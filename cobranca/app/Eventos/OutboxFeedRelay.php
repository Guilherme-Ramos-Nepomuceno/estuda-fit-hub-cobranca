<?php

namespace App\Eventos;

use Illuminate\Support\Facades\DB;

class OutboxFeedRelay implements EventRelay
{
    public function pendentes(int $aposId, int $max): array
    {
        return DB::table('outbox')
            ->where('id', '>', $aposId)
            ->orderBy('id')
            ->limit(max(1, min($max, 500)))
            ->get()
            ->map(Evento::deArray(...))
            ->all();
    }
}
