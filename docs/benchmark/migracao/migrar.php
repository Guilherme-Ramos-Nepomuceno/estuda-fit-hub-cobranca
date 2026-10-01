<?php

declare(strict_types=1);

// Migração paralela de faturas: o coordenador divide a faixa de IDs em N partições
// e cria N processos (pcntl_fork). Cada processo tem as próprias conexões e copia
// a sua partição em lotes por keyset, com upsert idempotente.
// Uso: php migrar.php <processos> <lote>

[, $processos, $lote] = $argv + [null, 4, 5000];
$processos = (int) $processos;
$lote = (int) $lote;

const COLUNAS = ['id', 'matricula_id', 'aluno_id', 'competencia', 'valor', 'vencimento', 'status', 'pago_em', 'gateway_ref', 'criado_em'];

function conectar(string $banco): PDO
{
    return new PDO("mysql:host=bench-db;dbname={$banco};charset=utf8mb4", 'bench', 'bench', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function migrarParticao(int $k, int $de, int $ate, int $lote): array
{
    $origem = conectar('cobranca');
    $destino = conectar('migracao');
    $colunas = implode(', ', COLUNAS);
    $select = $origem->prepare("SELECT {$colunas} FROM faturas WHERE id > ? AND id <= ? ORDER BY id LIMIT {$lote}");
    $marcadores = '(' . implode(', ', array_fill(0, count(COLUNAS), '?')) . ')';
    $insert = [];

    $ultimo = $de - 1;
    $linhas = 0;
    $lotes = 0;
    $inicio = microtime(true);
    while (true) {
        $select->execute([$ultimo, $ate]);
        $rows = $select->fetchAll(PDO::FETCH_NUM);
        if ($rows === []) {
            break;
        }
        $n = count($rows);
        $insert[$n] ??= $destino->prepare(
            "INSERT INTO faturas ({$colunas}) VALUES " . implode(', ', array_fill(0, $n, $marcadores))
            . ' AS novo ON DUPLICATE KEY UPDATE status = novo.status, pago_em = novo.pago_em, gateway_ref = novo.gateway_ref',
        );
        $destino->beginTransaction();
        $insert[$n]->execute(array_merge(...$rows));
        $destino->commit();

        $linhas += $n;
        $lotes++;
        $ultimo = (int) $rows[$n - 1][0];
        unset($rows);
    }

    return [
        'processo' => $k,
        'linhas' => $linhas,
        'lotes' => $lotes,
        'segundos' => round(microtime(true) - $inicio, 2),
        'pico_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    ];
}

$coord = conectar('cobranca');
[$min, $max] = array_map('intval', $coord->query('SELECT MIN(id), MAX(id) FROM faturas')->fetch(PDO::FETCH_NUM));
$coord = null; // conexões não podem ser compartilhadas entre processos após o fork

$inicio = microtime(true);
$tamanho = intdiv($max - $min + $processos, $processos);
$filhos = [];
for ($k = 0; $k < $processos; $k++) {
    $de = $min + $k * $tamanho;
    $ate = min($max, $de + $tamanho - 1);
    $pid = pcntl_fork();
    if ($pid === 0) {
        file_put_contents("/tmp/particao-{$k}.json", json_encode(migrarParticao($k, $de, $ate, $lote)));
        exit(0);
    }
    $filhos[] = $pid;
}
$falhas = 0;
foreach ($filhos as $pid) {
    pcntl_waitpid($pid, $status);
    $falhas += pcntl_wexitstatus($status) !== 0 ? 1 : 0;
}

$particoes = array_map(static fn (int $k) => json_decode((string) file_get_contents("/tmp/particao-{$k}.json"), true), range(0, $processos - 1));
$total = array_sum(array_column($particoes, 'linhas'));
$segundos = microtime(true) - $inicio;
echo json_encode([
    'processos' => $processos,
    'lote' => $lote,
    'linhas' => $total,
    'segundos' => round($segundos, 1),
    'linhas_por_s' => (int) ($total / $segundos),
    'pico_mb_por_processo' => max(array_column($particoes, 'pico_mb')),
    'pico_mb_coordenador' => round(memory_get_peak_usage(true) / 1048576, 1),
    'falhas' => $falhas,
]), "\n";
