<?php

namespace App\Eventos;

use Illuminate\Support\Facades\Http;

/**
 * Lê o feed de eventos de outro sistema: GET {url}?apos={id}&max={n} com token Bearer.
 */
class HttpFeedSource implements EventSource
{
    public function __construct(private readonly string $url, private readonly string $token)
    {
    }

    public function buscar(int $aposId, int $max): array
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout(5)
            ->get($this->url, ['apos' => $aposId, 'max' => $max])
            ->throw()
            ->collect('eventos')
            ->map(Evento::deArray(...))
            ->all();
    }
}
