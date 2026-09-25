<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

interface Comando
{
    /** @param string[] $args */
    public function executar(array $args): int;
}
