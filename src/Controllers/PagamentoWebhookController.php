<?php

declare(strict_types=1);

namespace EstudaFitHub\Controllers;

use EstudaFitHub\Eventos\Outbox;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Pagamento;
use EstudaFitHub\Services\NotificacaoService;
use EstudaFitHub\Services\SituacaoFinanceiraLocal;
use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\Log;
use EstudaFitHub\Support\Uuid;

/**
 * Recebe as notificações do gateway de pagamento.
 * Payload esperado: { "reference": "gw_...", "status": "approved|refused|pending", "amount": 89.9, "method": "pix", "event_id": "evt_..." }
 *
 * Todo webhook é gravado no outbox (WebhookPagamentoRecebido) e deduplicado pelo event_id do
 * gateway (ADR-006). Faturas de Cobrança são processadas por ela, a partir do feed. Faturas
 * criadas pelo monólito continuam processadas aqui, como antes.
 */
final class PagamentoWebhookController
{
    public function receber(Request $request): Response
    {
        $request->exigir(['reference', 'status']);

        $referencia = (string) $request->input('reference');
        $status = (string) $request->input('status');
        $gatewayEventId = $request->input('event_id');
        if ($gatewayEventId === null || $gatewayEventId === '') {
            Log::warning('webhook.sem_event_id', ['reference' => $referencia, 'status' => $status]);
        }

        $novo = DB::transaction(fn (): bool => Outbox::registrar(
            'WebhookPagamentoRecebido',
            "webhook:{$referencia}",
            [
                'reference' => $referencia,
                'status' => $status,
                'amount' => $request->input('amount') === null ? null : (string) $request->input('amount'),
                'method' => $request->input('method'),
                'gateway_event_id' => $gatewayEventId,
                'recebido_em' => date(DATE_ATOM),
            ],
            $gatewayEventId === null || $gatewayEventId === '' ? 'gw-sem-id:' . Uuid::v4() : "gw:{$gatewayEventId}",
        ));

        if (!$novo) {
            return Response::vazio(); // webhook repetido: já registrado
        }

        $fatura = Fatura::where('gateway_ref', $referencia)->first();
        if ($fatura === null || $fatura->ehDeCobranca()) {
            return Response::vazio(); // fatura de Cobrança, ou que ainda vai chegar: Cobrança decide
        }

        if ($status === 'approved') {
            $this->baixarLocalmente($fatura, $request);
        } elseif ($status === 'refused') {
            $aluno = $fatura->aluno();
            if ($aluno !== null) {
                (new NotificacaoService())->enviarEmail($aluno, 'pagamento_recusado', ['fatura_id' => $fatura->id]);
            }
        }

        return Response::vazio();
    }

    /** Caminho legado, para faturas criadas pelo monólito. */
    private function baixarLocalmente(Fatura $fatura, Request $request): void
    {
        $pagou = DB::transaction(function () use ($fatura, $request): bool {
            $atual = DB::selectOne('SELECT status FROM faturas WHERE id = ? FOR UPDATE', [$fatura->id]);
            if (($atual['status'] ?? null) === 'paga') {
                return false;
            }

            $fatura->update(['status' => 'paga', 'pago_em' => date('Y-m-d H:i:s')]);
            Pagamento::create([
                'fatura_id' => $fatura->id,
                'valor' => $request->input('amount', $fatura->valor),
                'metodo' => (string) $request->input('method', 'desconhecido'),
                'gateway_payload' => json_encode($request->all(), JSON_UNESCAPED_UNICODE),
            ]);

            // Acoplamento: Cobrança escreve na tabela de Alunos.
            $aluno = $fatura->aluno();
            if ($aluno !== null && $aluno->situacao === 'bloqueado') {
                $aluno->update(['situacao' => 'ativo']);
            }
            SituacaoFinanceiraLocal::recalcular((int) $fatura->aluno_id);

            return true;
        });

        // Chamada HTTP externa síncrona (SMS) na thread do webhook.
        $aluno = $fatura->aluno();
        if ($pagou && $aluno !== null) {
            (new NotificacaoService())->enviarSms($aluno, 'pagamento_confirmado', ['fatura_id' => $fatura->id]);
        }
    }
}
