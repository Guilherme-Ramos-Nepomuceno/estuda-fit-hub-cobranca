<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * correlation_id da operação em curso (ADR-003). Fica no Context do Laravel, que o Octane limpa
 * a cada requisição, para não vazar de uma para outra no mesmo worker. O consumidor de eventos
 * assume o do evento que está processando.
 */
class Correlacao
{
    public static function id(): string
    {
        if (! Context::has('correlation_id')) {
            Context::add('correlation_id', (string) Str::uuid());
        }

        return Context::get('correlation_id');
    }

    public static function definir(string $id): void
    {
        Context::add('correlation_id', $id);
    }
}
