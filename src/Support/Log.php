<?php

declare(strict_types=1);

namespace EstudaFitHub\Support;

/**
 * Log estruturado (ADR-003): uma linha JSON por evento, na saída de erro do processo, com
 * timestamp UTC, nível, serviço e correlation_id. CPF, e-mail e telefone nunca saem completos.
 * O monólito não tem Composer, então este é um logger mínimo próprio.
 */
final class Log
{
    /** @var array<int, array>|null linhas capturadas em vez de escritas (testes) */
    private static ?array $captura = null;

    public static function info(string $evento, array $contexto = []): void
    {
        self::escrever('info', $evento, $contexto);
    }

    public static function warning(string $evento, array $contexto = []): void
    {
        self::escrever('warning', $evento, $contexto);
    }

    public static function error(string $evento, array $contexto = []): void
    {
        self::escrever('error', $evento, $contexto);
    }

    /** Passa a guardar as linhas em memória; usado pelos testes. */
    public static function capturar(): void
    {
        self::$captura = [];
    }

    /** @return array<int, array> */
    public static function capturadas(): array
    {
        return self::$captura ?? [];
    }

    private static function escrever(string $nivel, string $evento, array $contexto): void
    {
        $linha = [
            'timestamp' => gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) (fmod(microtime(true), 1) * 1000)),
            'level' => $nivel,
            'service' => 'monolito',
            'correlation_id' => Correlacao::id(),
            'event' => $evento,
        ] + self::mascarar($contexto);

        if (self::$captura !== null) {
            self::$captura[] = $linha;

            return;
        }
        file_put_contents('php://stderr', json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** Mascara dados pessoais pelo nome do campo e, em qualquer texto, CPFs e e-mails soltos. */
    public static function mascarar(array $dados): array
    {
        foreach ($dados as $chave => $valor) {
            if (is_array($valor)) {
                $dados[$chave] = self::mascarar($valor);
            } elseif (is_string($valor)) {
                $dados[$chave] = match (strtolower((string) $chave)) {
                    'cpf' => self::cpf($valor),
                    'email' => (string) preg_replace('/^(.).*(@.*)$/', '$1***$2', $valor),
                    'telefone' => '*******' . substr(preg_replace('/\D/', '', $valor) ?? '', -4),
                    default => self::mascararTexto($valor),
                };
            }
        }

        return $dados;
    }

    private static function mascararTexto(string $texto): string
    {
        $texto = (string) preg_replace_callback('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', static fn (array $m): string => self::cpf($m[0]), $texto);

        return (string) preg_replace('/\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', '$1***$2', $texto);
    }

    private static function cpf(string $cpf): string
    {
        return '***.***.***-' . substr(preg_replace('/\D/', '', $cpf) ?? '', -2);
    }
}
