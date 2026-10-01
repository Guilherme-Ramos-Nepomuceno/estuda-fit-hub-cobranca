<?php

declare(strict_types=1);

namespace EstudaFitHub\Support;

/**
 * correlation_id da operação em curso (ADR-003). Cada requisição HTTP começa um novo;
 * o consumidor de eventos assume o do evento que está processando.
 */
final class Correlacao
{
    private static ?string $id = null;

    public static function id(): string
    {
        return self::$id ??= Uuid::v4();
    }

    public static function definir(string $id): void
    {
        self::$id = $id;
    }
}
