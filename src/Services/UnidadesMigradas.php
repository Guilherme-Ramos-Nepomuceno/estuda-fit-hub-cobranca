<?php

declare(strict_types=1);

namespace EstudaFitHub\Services;

use EstudaFitHub\Support\DB;

/**
 * Canary por unidade (ADR-004): unidades cujas faturas são de Cobrança.
 */
final class UnidadesMigradas
{
    public static function contem(int $unidadeId): bool
    {
        return DB::selectOne('SELECT 1 AS sim FROM cobranca_unidades_migradas WHERE unidade_id = ?', [$unidadeId]) !== null;
    }

    /** @return int[] */
    public static function ids(): array
    {
        return array_map('intval', array_column(DB::select('SELECT unidade_id FROM cobranca_unidades_migradas'), 'unidade_id'));
    }

    public static function migrar(int $unidadeId): void
    {
        DB::execute('INSERT IGNORE INTO cobranca_unidades_migradas (unidade_id) VALUES (?)', [$unidadeId]);
    }

    public static function reverter(int $unidadeId): void
    {
        DB::execute('DELETE FROM cobranca_unidades_migradas WHERE unidade_id = ?', [$unidadeId]);
    }
}
