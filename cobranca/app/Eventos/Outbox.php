<?php

namespace App\Eventos;

use App\Support\Correlacao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactional outbox (ADR-005): o evento é gravado na mesma transação da mudança de negócio.
 * Quem chama deve estar dentro de DB::transaction.
 */
class Outbox
{
    /** @return bool false se o event_id já existia (nada gravado) */
    public static function registrar(string $tipo, string $aggregateId, array $dados, ?string $eventId = null): bool
    {
        $sequencia = (int) DB::table('outbox')->where('aggregate_id', $aggregateId)->max('sequencia') + 1;

        return DB::table('outbox')->insertOrIgnore([
            'event_id' => $eventId ?? (string) Str::uuid(),
            'tipo' => $tipo,
            'versao' => 1,
            'aggregate_id' => $aggregateId,
            'sequencia' => $sequencia,
            'correlation_id' => Correlacao::id(),
            'dados' => json_encode($dados, JSON_UNESCAPED_UNICODE),
            'ocorrido_em' => DB::raw('UTC_TIMESTAMP(3)'),
        ]) === 1;
    }
}
