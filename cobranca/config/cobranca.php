<?php

return [
    'eventos' => [
        // Token que o monólito usa para ler o feed de Cobrança.
        'token' => env('EVENTOS_TOKEN'),
        // Feed de eventos do monólito, consumido por eventos:consumir.
        'monolito_url' => env('MONOLITO_EVENTOS_URL'),
        'monolito_token' => env('MONOLITO_EVENTOS_TOKEN'),
    ],

    'gateway' => [
        // Latência simulada de cada registro de cobrança (ms), como no monólito.
        'latencia_ms' => (int) env('GATEWAY_LATENCIA_MS', 0),
    ],
];
