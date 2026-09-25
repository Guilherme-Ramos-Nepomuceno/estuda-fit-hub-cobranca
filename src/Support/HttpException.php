<?php

declare(strict_types=1);

namespace EstudaFitHub\Support;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $mensagem)
    {
        parent::__construct($mensagem);
    }
}
