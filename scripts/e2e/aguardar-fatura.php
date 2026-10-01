<?php

declare(strict_types=1);

/**
 * E2E: espera a fatura gerada por Cobrança aparecer na tela do aluno no monólito
 * (MatriculaCriada → Cobrança → FaturaGerada → cópia no monólito).
 * Uso (dentro do container do monólito): php scripts/e2e/aguardar-fatura.php <aluno_id> [segundos]
 */

$aluno = (int) ($argv[1] ?? 0);
$limite = microtime(true) + (int) ($argv[2] ?? 30);

do {
    $dados = json_decode((string) @file_get_contents("http://localhost:8080/alunos/{$aluno}/faturas"), true);
    foreach ($dados['faturas'] ?? [] as $fatura) {
        if ((int) $fatura['id'] >= 2000000000 && preg_match('/^gw_\d+_[0-9a-f]{8}$/', (string) $fatura['gateway_ref'])) {
            printf("fatura %d com %s em %.1fs\n", $fatura['id'], $fatura['gateway_ref'], microtime(true) - ($limite - (int) ($argv[2] ?? 30)));
            exit(0);
        }
    }
    usleep(500_000);
} while (microtime(true) < $limite);

fwrite(STDERR, "FALHOU: nenhuma fatura de Cobrança para o aluno {$aluno} no prazo\n");
exit(1);
