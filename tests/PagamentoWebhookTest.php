<?php

declare(strict_types=1);

use EstudaFitHub\Controllers\PagamentoWebhookController;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Models\Notificacao;
use EstudaFitHub\Models\Pagamento;
use EstudaFitHub\Support\DB;

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
     * Antes: testWebhookDuplicadoRegistraPagamentoDuasVezesComportamentoAtual exigia 2 pagamentos.
     * Ajustado (ADR-002, PRD etapa 3, critério 5): o desafio exige tolerar webhook duplicado,
     * e o outbox deduplica pelo event_id do gateway.
     */
    public function testWebhookDuplicadoRegistraUmPagamento(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula);
        $payload = ['reference' => $fatura->gateway_ref, 'status' => 'approved', 'event_id' => 'evt_4'];

        $this->assertSame(204, $this->webhook($payload));
        $this->assertSame(204, $this->webhook($payload));

        $this->assertSame(1, Pagamento::where('fatura_id', $fatura->id)->count());
        $this->assertSame(1, $this->eventosNoOutbox());
    }

    /**
     * Antes: testReferenciaDesconhecidaRetorna404. Ajustado (PRD etapa 3, critério 10): o monólito
     * não tem como saber se a referência é de uma fatura de Cobrança que ainda não chegou,
     * então registra e responde 204; Cobrança decide.
     */
    public function testReferenciaDesconhecidaRetorna204ERegistraNoOutbox(): void
    {
        $this->assertSame(204, $this->webhook(['reference' => 'gw_nao_existe', 'status' => 'approved', 'event_id' => 'evt_5']));
        $this->assertSame(1, $this->eventosNoOutbox());
    }

    /** Critério 1 */
    public function testFaturaDeCobrancaSoRegistraNoOutbox(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula, ['cobranca_id' => 2000000007, 'gateway_ref' => 'gw_2000000007_abcdef12']);

        $status = $this->webhook(['reference' => 'gw_2000000007_abcdef12', 'status' => 'approved', 'amount' => 119.9, 'method' => 'pix', 'event_id' => 'evt_6']);

        $this->assertSame(204, $status);
        $evento = DB::selectOne("SELECT * FROM outbox WHERE tipo = 'WebhookPagamentoRecebido'");
        $this->assertSame('gw:evt_6', $evento['event_id']);
        $this->assertSame('gw_2000000007_abcdef12', json_decode($evento['dados'], true)['reference']);
        $this->assertSame('aberta', $fatura->refresh()->status, 'Nenhuma escrita em faturas');
        $this->assertSame(0, Pagamento::where('fatura_id', $fatura->id)->count());
        $this->assertSame(0, Notificacao::where('aluno_id', $aluno->id)->count(), 'Nenhum SMS na requisição');
    }

    /**
     * Regressão: gravar a cópia com o id de Cobrança como id avançava o AUTO_INCREMENT do
     * monólito, e as faturas criadas depois passavam a ser tratadas como de Cobrança.
     */
    public function testCopiaDeCobrancaNaoAfetaFaturasCriadasPeloMonolito(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->criarFatura($matricula, ['cobranca_id' => 2000000009, 'competencia' => '2026-09-01']);
        $legada = $this->criarFatura($matricula);

        $this->webhook(['reference' => $legada->gateway_ref, 'status' => 'approved', 'event_id' => 'evt_10']);

        $this->assertTrue((int) $legada->id < 2000000000, 'Id local continua na faixa do monólito');
        $this->assertSame('paga', $legada->refresh()->status, 'Processada localmente');
    }

    /** Critério 9: outro event_id para fatura legada já paga */
    public function testAprovadoComOutroEventIdNaoPagaDuasVezes(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula);

        $this->webhook(['reference' => $fatura->gateway_ref, 'status' => 'approved', 'event_id' => 'evt_7']);
        $this->webhook(['reference' => $fatura->gateway_ref, 'status' => 'approved', 'event_id' => 'evt_8']);

        $this->assertSame(1, Pagamento::where('fatura_id', $fatura->id)->count());
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->where('template', 'pagamento_confirmado')->count());
        $this->assertSame(2, $this->eventosNoOutbox());
    }

    /** Critério 12 */
    public function testPendingSoRegistra(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula);

        $this->assertSame(204, $this->webhook(['reference' => $fatura->gateway_ref, 'status' => 'pending', 'event_id' => 'evt_9']));

        $this->assertSame('aberta', $fatura->refresh()->status);
        $this->assertSame(1, $this->eventosNoOutbox());
    }

    /** Critério 13 */
    public function testSemEventIdEhAceitoSemDeduplicacao(): void
    {
        $payload = ['reference' => 'gw_qualquer', 'status' => 'approved'];

        $this->assertSame(204, $this->webhook($payload));
        $this->assertSame(204, $this->webhook($payload));

        $ids = array_column(DB::select('SELECT event_id FROM outbox ORDER BY id'), 'event_id');
        $this->assertCount(2, $ids);
        $this->assertTrue(str_starts_with($ids[0], 'gw-sem-id:') && $ids[0] !== $ids[1]);
    }

    private function eventosNoOutbox(): int
    {
        return (int) DB::selectOne("SELECT COUNT(*) AS total FROM outbox WHERE tipo = 'WebhookPagamentoRecebido'")['total'];
    }
}
