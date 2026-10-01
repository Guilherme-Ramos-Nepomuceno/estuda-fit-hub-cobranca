<?php

namespace Tests\Feature;

use App\Cobranca\ProcessarPagamento;
use App\Eventos\Evento;
use App\Models\Fatura;
use App\Models\Pagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PRD etapa 3, critérios 2, 6, 7, 10 e 12: webhook de pagamento consumido por Cobrança. */
class ProcessarPagamentoTest extends TestCase
{
    use RefreshDatabase;

    public static function webhook(string $referencia, string $status = 'approved', string $gatewayEventId = 'evt_1'): Evento
    {
        return new Evento(1, "gw:{$gatewayEventId}", 'WebhookPagamentoRecebido', 1, '2026-10-20 12:00:00.000', 'corr-pg', "webhook:{$referencia}", 1, [
            'reference' => $referencia, 'status' => $status, 'amount' => '119.90', 'method' => 'pix',
            'gateway_event_id' => $gatewayEventId, 'recebido_em' => '2026-10-20T09:00:00-03:00',
        ]);
    }

    public static function fatura(array $extra = []): Fatura
    {
        return Fatura::create($extra + [
            'matricula_id' => 77, 'aluno_id' => 501, 'competencia' => '2026-10-01', 'valor' => '119.90',
            'vencimento' => '2026-10-18', 'status' => 'aberta', 'gateway_ref' => 'gw_cobranca_1',
        ]);
    }

    private function processar(Evento $evento): void
    {
        app(ProcessarPagamento::class)($evento);
    }

    public function test_aprovado_baixa_a_fatura_e_publica_os_eventos(): void
    {
        $fatura = self::fatura();

        $this->processar(self::webhook('gw_cobranca_1'));

        $fatura->refresh();
        $this->assertSame('paga', $fatura->status);
        $this->assertNotNull($fatura->pago_em);
        $pagamento = Pagamento::sole();
        $this->assertSame('119.90', $pagamento->valor);
        $this->assertSame('pix', $pagamento->metodo);
        $this->assertSame('evt_1', $pagamento->event_id);
        $this->assertGreaterThanOrEqual(2000000000, $pagamento->id);

        $paga = json_decode(DB::table('outbox')->where('tipo', 'FaturaPaga')->sole()->dados, true);
        $this->assertSame($fatura->id, $paga['fatura_id']);
        $this->assertSame($pagamento->id, $paga['pagamento_id']);
        $this->assertSame('119.90', $paga['valor']);

        $situacao = json_decode(DB::table('outbox')->where('tipo', 'SituacaoFinanceiraAlterada')->sole()->dados, true);
        $this->assertSame(['aluno_id' => 501, 'bloqueado' => false, 'vencida_desde' => null], $situacao);
    }

    public function test_outro_event_id_para_fatura_ja_paga_nao_gera_segundo_pagamento(): void
    {
        self::fatura();

        $this->processar(self::webhook('gw_cobranca_1', gatewayEventId: 'evt_1'));
        $this->processar(self::webhook('gw_cobranca_1', gatewayEventId: 'evt_2'));

        $this->assertSame(1, Pagamento::count());
        $this->assertSame(1, DB::table('outbox')->where('tipo', 'FaturaPaga')->count());
    }

    public function test_recusado_depois_de_aprovado_nao_reverte(): void
    {
        $fatura = self::fatura();

        $this->processar(self::webhook('gw_cobranca_1', 'approved', 'evt_1'));
        $this->processar(self::webhook('gw_cobranca_1', 'refused', 'evt_2'));

        $this->assertSame('paga', $fatura->refresh()->status);
    }

    public function test_referencia_que_nao_e_de_cobranca_e_ignorada(): void
    {
        $this->processar(self::webhook('gw_123_do_monolito'));

        $this->assertSame(0, Pagamento::count());
        $this->assertSame(0, DB::table('outbox')->count());
    }

    public function test_pending_nao_altera_nada(): void
    {
        $fatura = self::fatura();

        $this->processar(self::webhook('gw_cobranca_1', 'pending'));

        $this->assertSame('aberta', $fatura->refresh()->status);
        $this->assertSame(0, Pagamento::count());
    }

    public function test_pagamento_de_fatura_vencida_limpa_o_debito_do_aluno(): void
    {
        self::fatura(['status' => 'vencida', 'vencimento' => '2026-09-01']);

        $this->processar(self::webhook('gw_cobranca_1'));

        $situacao = json_decode(DB::table('outbox')->where('tipo', 'SituacaoFinanceiraAlterada')->sole()->dados, true);
        $this->assertNull($situacao['vencida_desde']);
        $this->assertFalse($situacao['bloqueado']);
    }
}
