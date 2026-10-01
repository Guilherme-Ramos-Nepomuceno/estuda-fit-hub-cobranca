<?php

namespace App\Eventos;

use App\Support\Correlacao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent Consumer (ADR-005). Os handlers de Cobrança chamam o gateway, que não pode ficar
 * dentro de transação. Por isso o handler é idempotente e o evento só é marcado como processado
 * depois dele. Se o handler falha, o evento é tentado de novo no próximo lote.
 */
class Consumidor
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
        $posicao = (int) DB::table('consumidor_posicao')->where('feed', $this->feed)->value('ultimo_id');
        $eventos = $this->fonte->buscar($posicao, $max);

        foreach ($eventos as $evento) {
            Correlacao::definir($evento->correlationId);
            $motivo = $this->motivoParaIgnorar($evento);
            if ($motivo === null) {
                ($this->handlers[$evento->tipo] ?? static fn () => null)($evento);
            }
            $this->concluir($evento);
            $this->registrar($evento, $motivo);
        }

        return count($eventos);
    }

    /** @return 'duplicado'|'fora_de_ordem'|null */
    private function motivoParaIgnorar(Evento $evento): ?string
    {
        if (DB::table('eventos_processados')->where('event_id', $evento->eventId)->exists()) {
            return 'duplicado';
        }
        $ultima = DB::table('agregados_sequencia')->where('aggregate_id', $evento->aggregateId)->value('sequencia');

        return $ultima !== null && $evento->sequencia <= (int) $ultima ? 'fora_de_ordem' : null;
    }

    private function concluir(Evento $evento): void
    {
        DB::transaction(function () use ($evento): void {
            DB::table('eventos_processados')->insertOrIgnore(['event_id' => $evento->eventId]);
            DB::statement(
                'INSERT INTO agregados_sequencia (aggregate_id, sequencia) VALUES (?, ?) AS novo
                 ON DUPLICATE KEY UPDATE sequencia = GREATEST(agregados_sequencia.sequencia, novo.sequencia)',
                [$evento->aggregateId, $evento->sequencia],
            );
            DB::statement(
                'INSERT INTO consumidor_posicao (feed, ultimo_id) VALUES (?, ?) AS novo
                 ON DUPLICATE KEY UPDATE ultimo_id = GREATEST(consumidor_posicao.ultimo_id, novo.ultimo_id)',
                [$this->feed, $evento->id],
            );
        });
    }

    private function registrar(Evento $evento, ?string $motivo): void
    {
        $contexto = ['event_id' => $evento->eventId, 'tipo' => $evento->tipo, 'feed' => $this->feed];
        if ($motivo !== null) {
            Log::info('evento.ignorado', $contexto + ['motivo' => $motivo]);

            return;
        }
        $lagMs = (int) round(Carbon::parse($evento->ocorridoEm, 'UTC')->diffInMilliseconds(now(), absolute: false));
        Log::info('evento.processado', $contexto + ['lag_ms' => $lagMs]);
    }
}
