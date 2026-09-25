<?php

declare(strict_types=1);

use EstudaFitHub\Controllers\PagamentoWebhookController;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Models\Notificacao;
use EstudaFitHub\Models\Pagamento;
use EstudaFitHub\Support\HttpException;

final class PagamentoWebhookTest extends TestCase
{
    private function webhook(array $payload): int
    {
        $request = new Request('POST', '/webhooks/pagamento', [], $payload);

        return (new PagamentoWebhookController())->receber($request)->status();
    }

    public function testPagamentoAprovadoMarcaFaturaPagaERegistraPagamento(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(3)]);

        $status = $this->webhook([
            'reference' => $fatura->gateway_ref,
            'status' => 'approved',
            'amount' => 99.90,
            'method' => 'pix',
            'event_id' => 'evt_1',
        ]);

        $this->assertSame(204, $status);
        $this->assertSame('paga', $fatura->refresh()->status);
        $this->assertNotNull($fatura->pago_em);
        $this->assertSame(1, Pagamento::where('fatura_id', $fatura->id)->count());
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->where('template', 'pagamento_confirmado')->count());
    }

    public function testPagamentoAprovadoReativaAlunoBloqueado(): void
    {
        $aluno = $this->criarAluno(['situacao' => 'bloqueado']);
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(20)]);

        $this->webhook(['reference' => $fatura->gateway_ref, 'status' => 'approved', 'event_id' => 'evt_2']);

        $this->assertSame('ativo', $aluno->refresh()->situacao);
    }

    public function testPagamentoRecusadoNotificaPorEmailESemMudarFatura(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula);

        $this->webhook(['reference' => $fatura->gateway_ref, 'status' => 'refused', 'event_id' => 'evt_3']);

        $this->assertSame('aberta', $fatura->refresh()->status);
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->where('template', 'pagamento_recusado')->count());
    }

    /**
     * Comportamento ATUAL, documentado de propósito: o webhook não é idempotente.
     * O gateway reenvia eventos; hoje isso gera pagamento em dobro.
     */
    public function testWebhookDuplicadoRegistraPagamentoDuasVezesComportamentoAtual(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula);
        $payload = ['reference' => $fatura->gateway_ref, 'status' => 'approved', 'event_id' => 'evt_4'];

        $this->webhook($payload);
        $this->webhook($payload);

        $this->assertSame(2, Pagamento::where('fatura_id', $fatura->id)->count());
    }

    public function testReferenciaDesconhecidaRetorna404(): void
    {
        $this->assertLanca(HttpException::class, fn () => $this->webhook(['reference' => 'gw_nao_existe', 'status' => 'approved']), 404);
    }
}
