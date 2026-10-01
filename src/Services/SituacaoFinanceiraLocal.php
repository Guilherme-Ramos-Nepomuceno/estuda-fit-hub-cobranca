<?php

declare(strict_types=1);

namespace EstudaFitHub\Services;

use EstudaFitHub\Support\DB;

/**
 * Cópia local da situação financeira do aluno, lida pela catraca (ponto B). É alimentada pelos
 * eventos de Cobrança e recalculada pelo código legado, na mesma transação em que ele muda faturas.
 */
final class SituacaoFinanceiraLocal
{
    /** Tolerância de atraso antes de bloquear a catraca (mesma regra de antes). */
    private const DIAS_DE_TOLERANCIA = 5;

    public static function inadimplente(int $alunoId): bool
    {
        $vencidaDesde = DB::selectOne('SELECT vencida_desde FROM situacao_financeira WHERE aluno_id = ?', [$alunoId])['vencida_desde'] ?? null;

        return $vencidaDesde !== null && $vencidaDesde < date('Y-m-d', strtotime('-' . self::DIAS_DE_TOLERANCIA . ' days'));
    }

    /** Recalcula a partir das faturas locais. Chame dentro da transação que alterou as faturas. */
    public static function recalcular(int $alunoId): void
    {
        DB::execute(
            "INSERT INTO situacao_financeira (aluno_id, vencida_desde)
             SELECT * FROM (SELECT ? AS aluno_id, MIN(vencimento) AS vencida_desde FROM faturas WHERE aluno_id = ? AND status = 'vencida') AS novo
             ON DUPLICATE KEY UPDATE vencida_desde = novo.vencida_desde",
            [$alunoId, $alunoId],
        );
    }

    /** Recalcula todos os alunos de uma vez (preenchimento inicial e conferência). */
    public static function recalcularTodos(): int
    {
        return DB::execute(
            "INSERT INTO situacao_financeira (aluno_id, vencida_desde)
             SELECT * FROM (
                 SELECT a.id AS aluno_id, MIN(CASE WHEN f.status = 'vencida' THEN f.vencimento END) AS vencida_desde
                   FROM alunos a LEFT JOIN faturas f ON f.aluno_id = a.id
                  GROUP BY a.id
             ) AS novo
             ON DUPLICATE KEY UPDATE vencida_desde = novo.vencida_desde",
        );
    }

    /** Aplica a situação publicada por Cobrança (SituacaoFinanceiraAlterada). */
    public static function aplicar(int $alunoId, ?string $vencidaDesde, int $sequencia): void
    {
        DB::execute(
            'INSERT INTO situacao_financeira (aluno_id, vencida_desde, sequencia) VALUES (?, ?, ?) AS novo
             ON DUPLICATE KEY UPDATE vencida_desde = novo.vencida_desde, sequencia = novo.sequencia',
            [$alunoId, $vencidaDesde, $sequencia],
        );
    }
}
