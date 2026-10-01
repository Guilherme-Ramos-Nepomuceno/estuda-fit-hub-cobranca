<?php

return [
    // Fuso do negócio para regras de data (vencimento, bloqueio). O banco e os eventos usam UTC.
    'fuso' => env('COBRANCA_FUSO', 'America/Sao_Paulo'),

    // Dias de atraso a partir dos quais o aluno fica bloqueado (mesma regra da régua do monólito).
    'dias_para_bloqueio' => 10,

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
