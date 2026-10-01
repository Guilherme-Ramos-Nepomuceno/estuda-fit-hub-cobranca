<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos;

use EstudaFitHub\Support\DB;

final class OutboxFeedRelay implements EventRelay
{
    public function pendentes(int $aposId, int $max): array
    {
        $linhas = DB::select(
            sprintf(
                'SELECT id, event_id, tipo, versao, ocorrido_em, correlation_id, aggregate_id, sequencia, dados
                   FROM outbox WHERE id > ? ORDER BY id LIMIT %d',
                max(1, min($max, 500)),
            ),
            [$aposId],
        );

        return array_map(Evento::deArray(...), $linhas);
    }
}
