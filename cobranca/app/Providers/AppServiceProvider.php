<?php

namespace App\Providers;

use App\Eventos\EventRelay;
use App\Eventos\EventSource;
use App\Eventos\HttpFeedSource;
use App\Eventos\OutboxFeedRelay;
use App\Gateway\Gateway;
use App\Gateway\GatewaySimulado;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Ports & Adapters (ADR-005): trocar feed HTTP por fila é trocar estes dois bindings.
        $this->app->bind(EventRelay::class, OutboxFeedRelay::class);
        $this->app->bind(EventSource::class, fn () => new HttpFeedSource(
            (string) config('cobranca.eventos.monolito_url'),
            (string) config('cobranca.eventos.monolito_token'),
        ));

        $this->app->bind(Gateway::class, fn () => new GatewaySimulado(config('cobranca.gateway.latencia_ms')));
    }
}
