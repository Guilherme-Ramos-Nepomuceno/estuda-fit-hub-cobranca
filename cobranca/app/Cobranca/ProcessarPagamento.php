<?php

namespace App\Cobranca;

use App\Eventos\Evento;
use App\Eventos\Outbox;
use App\Models\Fatura;
use App\Models\Pagamento;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso "receber o webhook de pagamento" (WebhookPagamentoRecebido → FaturaPaga).
 *
 * Duplicados, fora de ordem e simultâneos não geram segundo pagamento: a fatura é travada
 * (FOR UPDATE) e só uma fatura aberta ou vencida pode ser paga. Referências que não são
 * de Cobrança são ignoradas, porque o monólito grava no outbox todos os webhooks (ADR-006).
 */
class ProcessarPagamento
{
    public function __invoke(Evento $evento): void
    {
        $w = $evento->dados;
        if ($w['status'] !== 'approved') {
            return; // refused e pending não alteram a fatura
        }

        DB::transaction(function () use ($w, $evento): void {
            $fatura = Fatura::where('gateway_ref', $w['reference'])->lockForUpdate()->first();
            if ($fatura === null || ! in_array($fatura->status, ['aberta', 'vencida'], true)) {
                return;
            }

            $fatura->update(['status' => 'paga', 'pago_em' => now()]);
            $pagamento = Pagamento::create([
                'fatura_id' => $fatura->id,
                'valor' => $w['amount'] ?? $fatura->valor,
                'metodo' => $w['method'] ?? 'desconhecido',
                'event_id' => $w['gateway_event_id'] ?? $evento->eventId,
            ]);

            Outbox::registrar('FaturaPaga', "fatura:{$fatura->id}", [
                'fatura_id' => $fatura->id,
                'aluno_id' => $fatura->aluno_id,
                'pagamento_id' => $pagamento->id,
                'valor' => (string) $pagamento->valor,
                'metodo' => $pagamento->metodo,
                'pago_em' => $fatura->pago_em->utc()->format('Y-m-d H:i:s'),
            ]);
            SituacaoFinanceira::publicar($fatura->aluno_id);
        });
    }
}
