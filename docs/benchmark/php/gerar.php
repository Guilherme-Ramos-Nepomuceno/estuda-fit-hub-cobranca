<?php

// Cron de geração. Modo "serie" = como o monólito faz hoje; modo "lote" = curl_multi com 50 chamadas simultâneas.
require __DIR__ . '/app.php';

[, $competencia, $modo] = $argv + [null, '2026-10', 'serie'];
$inicio = microtime(true);
$pdo = pdo();
$contratos = $pdo->query('SELECT matricula_id, aluno_id, valor_mensal, dia_vencimento FROM contratos WHERE ativo = 1')->fetchAll();
$ins = $pdo->prepare('INSERT IGNORE INTO faturas (matricula_id, aluno_id, competencia, valor, vencimento) VALUES (?, ?, ?, ?, ?)');
$upd = $pdo->prepare('UPDATE faturas SET gateway_ref = ? WHERE id = ?');
$gateway = getenv('GATEWAY_URL');
$geradas = 0;

$criar = static function (array $c) use ($ins, $pdo, $competencia): ?int {
    $ins->execute([$c['matricula_id'], $c['aluno_id'], "{$competencia}-01", $c['valor_mensal'], sprintf('%s-%02d', $competencia, $c['dia_vencimento'])]);

    return $ins->rowCount() === 0 ? null : (int) $pdo->lastInsertId();
};
$handle = static function (int $id, string $valor) use ($gateway) {
    $ch = curl_init($gateway);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['fatura_id' => $id, 'valor' => $valor]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);

    return $ch;
};

if ($modo === 'serie') {
    foreach ($contratos as $c) {
        $id = $criar($c);
        if ($id === null) {
            continue;
        }
        $ch = $handle($id, $c['valor_mensal']);
        $upd->execute([json_decode(curl_exec($ch), true)['ref'], $id]);
        $geradas++;
    }
} else {
    foreach (array_chunk($contratos, 50) as $lote) {
        $mh = curl_multi_init();
        $pendentes = [];
        $pdo->beginTransaction();
        foreach ($lote as $c) {
            $id = $criar($c);
            if ($id === null) {
                continue;
            }
            $ch = $handle($id, $c['valor_mensal']);
            curl_multi_add_handle($mh, $ch);
            $pendentes[$id] = $ch;
        }
        $pdo->commit();
        do {
            curl_multi_exec($mh, $ativos);
            curl_multi_select($mh);
        } while ($ativos > 0);
        $pdo->beginTransaction();
        foreach ($pendentes as $id => $ch) {
            $upd->execute([json_decode(curl_multi_getcontent($ch), true)['ref'], $id]);
            curl_multi_remove_handle($mh, $ch);
            $geradas++;
        }
        $pdo->commit();
        curl_multi_close($mh);
    }
}

echo json_encode(['geradas' => $geradas, 'segundos' => round(microtime(true) - $inicio, 2)]), "\n";
