<?php

namespace Tests\Feature;

use App\Cobranca\ProcessarPagamento;
use App\Models\Pagamento;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * PRD etapa 2, critério 8 (o único teste de concorrência da ADR-002): dois webhooks aprovados,
 * com event_id diferentes, processados ao mesmo tempo para a mesma fatura geram 1 pagamento.
 *
 * Uma conexão separada trava a fatura; dois processos filhos tentam pagá-la e ficam esperando;
 * a trava é liberada. Sem o FOR UPDATE do caso de uso, os dois leriam a fatura como aberta
 * e gravariam 2 pagamentos.
 */
class PagamentoConcorrenteTest extends TestCase
{
    use DatabaseTruncation;

    public function test_webhooks_simultaneos_geram_um_unico_pagamento(): void
    {
        $fatura = ProcessarPagamentoTest::fatura();
        config(['database.connections.trava' => config('database.connections.mysql')]);
        $trava = DB::connection('trava');
        $trava->beginTransaction();
        $trava->select('SELECT id FROM faturas WHERE id = ? FOR UPDATE', [$fatura->id]);

        DB::disconnect(); // cada filho abre a própria conexão
        $filhos = [];
        foreach (['evt_a', 'evt_b'] as $gatewayEventId) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    app(ProcessarPagamento::class)(ProcessarPagamentoTest::webhook('gw_cobranca_1', 'approved', $gatewayEventId));
                    pcntl_exec('/bin/true');
                } catch (Throwable) {
                    pcntl_exec('/bin/false');
                }
            }
            $filhos[] = $pid;
        }

        usleep(500_000); // os dois filhos chegam ao lock antes de ele ser liberado
        $trava->commit();
        foreach ($filhos as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), 'Nenhum filho falhou');
        }

        DB::reconnect();
        $this->assertSame(1, Pagamento::where('fatura_id', $fatura->id)->count());
        $this->assertSame('paga', $fatura->refresh()->status);
    }

    /** Os filhos precisam de dados comitados; limpa ao final para não vazar para os outros testes. */
    protected function tearDown(): void
    {
        foreach (['pagamentos', 'faturas', 'outbox'] as $tabela) {
            DB::table($tabela)->delete();
        }
        parent::tearDown();
    }
}
