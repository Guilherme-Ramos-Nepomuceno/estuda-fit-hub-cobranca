<?php

namespace App\Eventos;

/**
 * Envelope v1 dos eventos de integração (ADR-005). `id` é a posição no feed de quem publicou.
 */
final readonly class Evento
{
    public function __construct(
        public int $id,
        public string $eventId,
        public string $tipo,
        public int $versao,
        public string $ocorridoEm,
        public string $correlationId,
        public string $aggregateId,
        public int $sequencia,
        public array $dados,
    ) {
    }

    public static function deArray(array|object $e): self
    {
        $e = (array) $e;

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
