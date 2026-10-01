<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos;

use RuntimeException;

/**
 * Lê o feed de eventos de outro sistema: GET {url}?apos={id}&max={n} com token Bearer.
 */
final class HttpFeedSource implements EventSource
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $timeoutSegundos = 5,
    ) {
    }

    public function buscar(int $aposId, int $max): array
    {
        $ch = curl_init(sprintf('%s?apos=%d&max=%d', $this->url, $aposId, $max));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSegundos,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$this->token}", 'Accept: application/json'],
        ]);
        $corpo = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($ch);

        if ($corpo === false || $status !== 200) {
            throw new RuntimeException(sprintf('Feed %s indisponível (status %d%s)', $this->url, $status, $erro !== '' ? ", {$erro}" : ''));
        }

        $dados = json_decode((string) $corpo, true);

        return array_map(Evento::deArray(...), $dados['eventos'] ?? []);
    }
}
