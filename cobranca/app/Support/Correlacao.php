<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * correlation_id da operação em curso (ADR-003). O consumidor de eventos assume o do evento
 * que está processando. A limpeza por requisição no Octane vem com os logs (etapa 5).
 */
class Correlacao
{
    private static ?string $id = null;

    public static function id(): string
    {
        return self::$id ??= (string) Str::uuid();
    }

    public static function definir(?string $id): void
    {
        self::$id = $id;
    }
}
