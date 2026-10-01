<?php

namespace App\Logging;

use App\Support\Correlacao;
use DateTimeZone;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Throwable;

/**
 * Uma linha JSON por evento (ADR-003), no mesmo formato do monólito: timestamp UTC, nível,
 * serviço, correlation_id e o nome do evento, mais o contexto. CPF, e-mail e telefone nunca
 * saem completos. Ativado por LOG_STDERR_FORMATTER.
 */
class FormatoJson implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $contexto = $record->context;
        if (($contexto['exception'] ?? null) instanceof Throwable) {
            $e = $contexto['exception'];
            $contexto['exception'] = $e::class;
            $contexto['mensagem'] ??= $e->getMessage();
            $contexto['arquivo'] ??= $e->getFile().':'.$e->getLine();
        }

        $linha = [
            'timestamp' => $record->datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => strtolower($record->level->getName()),
            'service' => 'cobranca',
            'correlation_id' => Correlacao::id(),
            'event' => $record->message,
        ] + self::mascarar($contexto);

        return json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
    }

    public function formatBatch(array $records): string
    {
        return implode('', array_map($this->format(...), $records));
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
                    'telefone' => '*******'.substr(preg_replace('/\D/', '', $valor) ?? '', -4),
                    default => self::mascararTexto($valor),
                };
            }
        }

        return $dados;
    }

    private static function mascararTexto(string $texto): string
    {
        $texto = (string) preg_replace_callback('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', fn (array $m) => self::cpf($m[0]), $texto);

        return (string) preg_replace('/\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', '$1***$2', $texto);
    }

    private static function cpf(string $cpf): string
    {
        return '***.***.***-'.substr(preg_replace('/\D/', '', $cpf) ?? '', -2);
    }
}
