<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos;

/**
 * Envelope v1 dos eventos de integração (ADR-005). `id` é a posição no feed de quem publicou.
 */
final class Evento
{
    public function __construct(
        public readonly int $id,
        public readonly string $eventId,
        public readonly string $tipo,
        public readonly int $versao,
        public readonly string $ocorridoEm,
        public readonly string $correlationId,
        public readonly string $aggregateId,
        public readonly int $sequencia,
        public readonly array $dados,
    ) {
    }

    public static function deArray(array $e): self
    {
        return new self(
            (int) $e['id'],
            (string) $e['event_id'],
            (string) $e['tipo'],
            (int) $e['versao'],
            (string) $e['ocorrido_em'],
            (string) $e['correlation_id'],
            (string) $e['aggregate_id'],
            (int) $e['sequencia'],
            is_array($e['dados']) ? $e['dados'] : (array) json_decode((string) $e['dados'], true),
        );
    }

    public function paraArray(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->eventId,
            'tipo' => $this->tipo,
            'versao' => $this->versao,
            'ocorrido_em' => $this->ocorridoEm,
            'correlation_id' => $this->correlationId,
            'aggregate_id' => $this->aggregateId,
            'sequencia' => $this->sequencia,
            'dados' => $this->dados,
        ];
    }
}
