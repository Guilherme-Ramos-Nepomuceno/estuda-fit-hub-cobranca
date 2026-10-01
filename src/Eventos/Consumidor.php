<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos;

use EstudaFitHub\Support\Correlacao;
use EstudaFitHub\Support\DB;

/**
 * Idempotent Consumer (ADR-005). Para cada evento, numa única transação: registra o event_id no
 * inbox, descarta o que estiver fora de ordem, aplica o handler e avança a posição do feed.
 * Evento repetido ou com sequência já aplicada não tem efeito.
 */
final class Consumidor
{
    /** @param array<string, callable(Evento): void> $handlers por tipo de evento */
    public function __construct(
        private readonly EventSource $fonte,
        private readonly string $feed,
        private readonly array $handlers,
    ) {
    }

    /** @return int quantidade de eventos lidos do feed */
    public function processarLote(int $max = 500): int
    {
        $posicao = (int) (DB::selectOne('SELECT ultimo_id FROM consumidor_posicao WHERE feed = ?', [$this->feed])['ultimo_id'] ?? 0);
        $eventos = $this->fonte->buscar($posicao, $max);

        foreach ($eventos as $evento) {
            DB::transaction(fn () => $this->aplicar($evento));
        }

        return count($eventos);
    }

    private function aplicar(Evento $evento): void
    {
        $novo = DB::execute('INSERT IGNORE INTO eventos_processados (event_id) VALUES (?)', [$evento->eventId]) === 1;

        if ($novo && $this->naOrdem($evento)) {
            Correlacao::definir($evento->correlationId);
            ($this->handlers[$evento->tipo] ?? static fn () => null)($evento);
        }

        DB::execute(
            'INSERT INTO consumidor_posicao (feed, ultimo_id) VALUES (?, ?) AS novo ON DUPLICATE KEY UPDATE ultimo_id = GREATEST(consumidor_posicao.ultimo_id, novo.ultimo_id)',
            [$this->feed, $evento->id],
        );
    }

    /** Registra a sequência do agregado; false se ela já foi aplicada (evento fora de ordem). */
    private function naOrdem(Evento $evento): bool
    {
        $ultima = DB::selectOne('SELECT sequencia FROM agregados_sequencia WHERE aggregate_id = ? FOR UPDATE', [$evento->aggregateId]);
        if ($ultima !== null && (int) $ultima['sequencia'] >= $evento->sequencia) {
            return false;
        }
        DB::execute(
            'INSERT INTO agregados_sequencia (aggregate_id, sequencia) VALUES (?, ?) AS novo ON DUPLICATE KEY UPDATE sequencia = novo.sequencia',
            [$evento->aggregateId, $evento->sequencia],
        );

        return true;
    }
}
