<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

spl_autoload_register(static function (string $classe): void {
    $prefixo = 'EstudaFitHub\\';
    if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) {
        return;
    }
    $relativo = str_replace('\\', '/', substr($classe, strlen($prefixo)));
    $arquivo = __DIR__ . '/src/' . $relativo . '.php';
    if (is_file($arquivo)) {
        require $arquivo;
    }
});
