<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos;

use EstudaFitHub\Support\Correlacao;
use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\Uuid;

/**
 * Transactional outbox (ADR-005): o evento é gravado na mesma transação da mudança de negócio,
 * então ou os dois existem, ou nenhum. Quem chama deve estar dentro de DB::transaction.
 */
final class Outbox
{
    /**
     * @param string|null $eventId id determinístico, quando a origem já tem um (ex.: webhook do gateway).
     * @return bool false se o event_id já existia (evento repetido, nada gravado).
     */
    public static function registrar(string $tipo, string $aggregateId, array $dados, ?string $eventId = null): bool
    {
        $sequencia = (int) (DB::selectOne(
            'SELECT COALESCE(MAX(sequencia), 0) + 1 AS proxima FROM outbox WHERE aggregate_id = ?',
            [$aggregateId],
        )['proxima'] ?? 1);

        $inseridas = DB::execute(
            'INSERT IGNORE INTO outbox (event_id, tipo, versao, aggregate_id, sequencia, correlation_id, dados, ocorrido_em)
             VALUES (?, ?, 1, ?, ?, ?, ?, UTC_TIMESTAMP(3))',
            [$eventId ?? Uuid::v4(), $tipo, $aggregateId, $sequencia, Correlacao::id(), json_encode($dados, JSON_UNESCAPED_UNICODE)],
        );

        return $inseridas === 1;
    }
}
