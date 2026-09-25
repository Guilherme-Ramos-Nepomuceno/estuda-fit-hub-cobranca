<?php

declare(strict_types=1);

namespace EstudaFitHub\Controllers;

use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Pagamento;
use EstudaFitHub\Services\NotificacaoService;
use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\HttpException;

/**
 * Recebe as notificações do gateway de pagamento.
 * Payload esperado: { "reference": "gw_...", "status": "approved|refused|pending", "amount": 89.9, "method": "pix", "event_id": "evt_..." }
 */
final class PagamentoWebhookController
{
    public function receber(Request $request): Response
    {
        $request->exigir(['reference', 'status']);

        $fatura = Fatura::where('gateway_ref', (string) $request->input('reference'))->first();
        if ($fatura === null) {
            throw new HttpException(404, 'Fatura não encontrada para a referência informada');
        }

        $status = (string) $request->input('status');

        if ($status === 'approved') {
            DB::transaction(function () use ($fatura, $request): void {
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
            });

            // Chamada HTTP externa síncrona (SMS) na thread do webhook.
            $aluno = $fatura->aluno();
            if ($aluno !== null) {
                (new NotificacaoService())->enviarSms($aluno, 'pagamento_confirmado', ['fatura_id' => $fatura->id]);
            }
        } elseif ($status === 'refused') {
            $aluno = $fatura->aluno();
            if ($aluno !== null) {
                (new NotificacaoService())->enviarEmail($aluno, 'pagamento_recusado', ['fatura_id' => $fatura->id]);
            }
        }

        return Response::vazio();
    }
}
