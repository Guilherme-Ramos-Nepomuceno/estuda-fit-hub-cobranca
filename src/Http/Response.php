<?php

declare(strict_types=1);

namespace EstudaFitHub\Http;

final class Response
{
    public function __construct(
        private readonly int $status,
        private readonly string $corpo = '',
        private readonly array $headers = [],
    ) {
    }

    public static function json(mixed $dados, int $status = 200): self
    {
        return new self(
            $status,
            (string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function vazio(int $status = 204): self
    {
        return new self($status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function comHeader(string $nome, string $valor): self
    {
        return new self($this->status, $this->corpo, [$nome => $valor] + $this->headers);
    }

    public function header(string $nome): ?string
    {
        return $this->headers[$nome] ?? null;
    }

    public function corpo(): string
    {
        return $this->corpo;
    }

    public function dados(): array
    {
        $decodificado = json_decode($this->corpo, true);

        return is_array($decodificado) ? $decodificado : [];
    }

    public function enviar(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $nome => $valor) {
            header("{$nome}: {$valor}");
        }
        echo $this->corpo;
    }
}
