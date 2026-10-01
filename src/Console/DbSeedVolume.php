<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Services\SituacaoFinanceiraLocal;
use EstudaFitHub\Support\DB;

/**
 * Volumetria de produção para ensaiar migrations, consultas e a migração de faturas (ADR-002):
 * 250 mil alunos, cada um com uma matrícula e 57 meses de faturas (pouco mais de 14 milhões).
 * Parte do seed comum (unidades, planos e 2 mil alunos) e gera o resto direto em SQL, em lotes,
 * porque montar esse volume em memória no PHP não cabe. Os dados são sintéticos (LGPD).
 * Uso: php bin/console db:seed-volume [--alunos=250000] [--meses=57]
 */
final class DbSeedVolume implements Comando
{
    private const LOTE = 10_000;

    public function executar(array $args): int
    {
        $alunos = 250_000;
        $meses = 57;
        foreach ($args as $arg) {
            if (preg_match('/^--(alunos|meses)=(\d+)$/', $arg, $m)) {
                ${$m[1]} = max(1, (int) $m[2]);
            }
        }

        $inicio = microtime(true);
        (new DbSeed())->executar([]);
        $base = (int) DB::selectOne('SELECT MAX(id) AS maior FROM alunos')['maior'];

        DB::raw('SET SESSION foreign_key_checks = 0, unique_checks = 0');
        $this->criarNumeros('numeros', $alunos);
        $this->criarNumeros('meses', $meses);

        for ($de = $base + 1; $de <= $alunos; $de += self::LOTE) {
            $ate = min($alunos, $de + self::LOTE - 1);
            $this->gerarLote($de, $ate, $meses);
            printf("alunos %d a %d: ok (%.0fs)\n", $de, $ate, microtime(true) - $inicio);
        }

        DB::raw('DROP TEMPORARY TABLE IF EXISTS numeros, meses');
        DB::raw('SET SESSION foreign_key_checks = 1, unique_checks = 1');
        SituacaoFinanceiraLocal::recalcularTodos();

        foreach (['alunos', 'matriculas', 'faturas'] as $tabela) {
            printf("%-12s %10d\n", $tabela, DB::selectOne("SELECT COUNT(*) AS total FROM {$tabela}")['total']);
        }

        return 0;
    }

    /** Tabela temporária com 1..$ate. São duas (alunos e meses): o MySQL não reabre uma temporária na mesma consulta. */
    private function criarNumeros(string $tabela, int $ate): void
    {
        DB::raw("DROP TEMPORARY TABLE IF EXISTS {$tabela}");
        DB::raw("CREATE TEMPORARY TABLE {$tabela} (n INT UNSIGNED PRIMARY KEY)");
        DB::raw('SET SESSION cte_max_recursion_depth = ' . ($ate + 1));
        DB::raw("INSERT INTO {$tabela} (n) WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < {$ate}) SELECT n FROM seq");
    }

    /** Alunos [de, ate], uma matrícula ativa cada, e as faturas mensais dos últimos $meses meses. */
    private function gerarLote(int $de, int $ate, int $meses): void
    {
        $atual = date('Y-m-01');
        $planos = (int) DB::selectOne('SELECT COUNT(*) AS total FROM planos')['total'];
        $unidades = (int) DB::selectOne('SELECT COUNT(*) AS total FROM unidades')['total'];

        DB::transaction(function () use ($de, $ate, $meses, $atual, $planos, $unidades): void {
            DB::execute(
                "INSERT INTO alunos (id, nome, email, telefone, cpf, unidade_id, situacao)
                 SELECT n, CONCAT('Aluno Volume ', n), CONCAT('volume', n, '@exemplo.com'),
                        CONCAT('119', LPAD(n, 8, '0')), LPAD(10000000000 + n, 11, '0'), 1 + MOD(n, ?), 'ativo'
                   FROM numeros WHERE n BETWEEN ? AND ?",
                [$unidades, $de, $ate],
            );
            DB::execute(
                "INSERT INTO matriculas (id, aluno_id, plano_id, inicio, fim, status, dia_vencimento)
                 SELECT n, n, 1 + MOD(n, ?), DATE_SUB(?, INTERVAL ? MONTH), DATE_ADD(?, INTERVAL 12 MONTH), 'ativa', 1 + MOD(n, 28)
                   FROM numeros WHERE n BETWEEN ? AND ?",
                [$planos, $atual, $meses - 1, $atual, $de, $ate],
            );
            // Mês atual em aberto; no anterior, 1 em cada 20 alunos com fatura vencida; o resto pago.
            DB::execute(
                "INSERT INTO faturas (matricula_id, aluno_id, competencia, valor, vencimento, status, pago_em, gateway_ref)
                 SELECT a.n, a.n, c.competencia, p.valor_mensal,
                        DATE_ADD(c.competencia, INTERVAL MOD(a.n, 28) DAY),
                        CASE WHEN c.atras = 0 THEN 'aberta' WHEN c.atras = 1 AND MOD(a.n, 20) = 0 THEN 'vencida' ELSE 'paga' END,
                        CASE WHEN c.atras = 0 OR (c.atras = 1 AND MOD(a.n, 20) = 0) THEN NULL
                             ELSE DATE_ADD(c.competencia, INTERVAL MOD(a.n, 28) DAY) END,
                        CONCAT('gw_v_', a.n, '_', DATE_FORMAT(c.competencia, '%Y%m'))
                   FROM numeros a
                   JOIN (SELECT n - 1 AS atras, DATE_SUB(?, INTERVAL n - 1 MONTH) AS competencia FROM meses) c
                   JOIN planos p ON p.id = 1 + MOD(a.n, ?)
                  WHERE a.n BETWEEN ? AND ?",
                [$atual, $planos, $de, $ate],
            );
        });
    }
}
