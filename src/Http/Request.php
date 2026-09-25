<?php

declare(strict_types=1);

namespace EstudaFitHub\Http;

use EstudaFitHub\Support\HttpException;

final class Request
{
    public function __construct(
        public readonly string $metodo,
        public readonly string $caminho,
        private readonly array $query = [],
        private readonly array $corpo = [],
        private array $parametros = [],
    ) {
    }

    public static function daGlobal(): self
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $caminho = rtrim($caminho, '/') ?: '/';

        $corpo = [];
        $bruto = (string) file_get_contents('php://input');
        $tipo = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if ($bruto !== '' && str_contains($tipo, 'application/json')) {
            $corpo = json_decode($bruto, true);
            if (!is_array($corpo)) {
                throw new HttpException(400, 'JSON inválido');
            }
        } elseif ($_POST !== []) {
            $corpo = $_POST;
        }

        return new self($metodo, $caminho, $_GET, $corpo);
    }

    public function input(string $chave, mixed $padrao = null): mixed
    {
        return $this->corpo[$chave] ?? $padrao;
    }

    public function all(): array
    {
        return $this->corpo;
    }

    public function query(string $chave, mixed $padrao = null): mixed
    {
        return $this->query[$chave] ?? $padrao;
    }

    public function param(string $chave): string
    {
        return (string) ($this->parametros[$chave] ?? '');
    }

    public function comParametros(array $parametros): self
    {
        $clone = clone $this;
        $clone->parametros = $parametros;

        return $clone;
    }

    /** @param string[] $campos */
    public function exigir(array $campos): void
    {
        foreach ($campos as $campo) {
            $valor = $this->input($campo);
            if ($valor === null || $valor === '') {
                throw new HttpException(422, "Campo obrigatório: {$campo}");
            }
        }
    }
}
