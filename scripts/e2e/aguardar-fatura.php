<?php

declare(strict_types=1);

/**
 * E2E: espera a cópia de uma fatura de Cobrança aparecer na tela do aluno no monólito com o
 * status esperado, e imprime o gateway_ref dela.
 * Uso (dentro do container do monólito): php scripts/e2e/aguardar-fatura.php <aluno_id> [segundos] [status]
 */

$aluno = (int) ($argv[1] ?? 0);
$segundos = (int) ($argv[2] ?? 30);
$status = $argv[3] ?? 'aberta';
$inicio = microtime(true);

do {
    $dados = json_decode((string) @file_get_contents("http://localhost:8080/alunos/{$aluno}/faturas"), true);
    foreach ($dados['faturas'] ?? [] as $fatura) {
        if ($fatura['cobranca_id'] !== null && $fatura['status'] === $status && preg_match('/^gw_\d+_[0-9a-f]{8}$/', (string) $fatura['gateway_ref'])) {
            fwrite(STDERR, sprintf("fatura %d de Cobrança %s em %.1fs\n", $fatura['cobranca_id'], $status, microtime(true) - $inicio));
            echo $fatura['gateway_ref'];
            exit(0);
        }
    }
    usleep(500_000);
} while (microtime(true) - $inicio < $segundos);

fwrite(STDERR, "FALHOU: nenhuma fatura de Cobrança com status {$status} para o aluno {$aluno} em {$segundos}s\n");
exit(1);
