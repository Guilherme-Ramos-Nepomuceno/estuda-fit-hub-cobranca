<?php

namespace Tests\Feature;

use App\Models\Fatura;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PRD etapa 3, critérios 11 e 12: Cobrança marca as faturas vencidas. */
class MarcarVencidasTest extends TestCase
{
    use RefreshDatabase;

    private function fatura(int $matricula, int $aluno, string $vencimento, string $status = 'aberta'): Fatura
    {
        return Fatura::create([
            'matricula_id' => $matricula, 'aluno_id' => $aluno, 'competencia' => substr($vencimento, 0, 8).'01',
            'valor' => '99.90', 'vencimento' => $vencimento, 'status' => $status, 'gateway_ref' => "gw_{$matricula}_{$vencimento}",
        ]);
    }

    public function test_marca_vencidas_e_publica_os_eventos_por_fatura_e_por_aluno(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $antiga = $this->fatura(1, 501, '2026-09-10');
        $recente = $this->fatura(2, 501, '2026-10-15');
        $futura = $this->fatura(3, 502, '2026-10-25');

        $this->artisan('faturas:marcar-vencidas')->expectsOutput('2 faturas marcadas como vencidas')->assertSuccessful();

        $this->assertSame('vencida', $antiga->refresh()->status);
        $this->assertSame('vencida', $recente->refresh()->status);
        $this->assertSame('aberta', $futura->refresh()->status);
        $this->assertSame(2, DB::table('outbox')->where('tipo', 'FaturaVencida')->count());

        $situacoes = DB::table('outbox')->where('tipo', 'SituacaoFinanceiraAlterada')->get();
        $this->assertCount(1, $situacoes, 'Um evento por aluno afetado');
        $dados = json_decode($situacoes[0]->dados, true);
        $this->assertSame(501, $dados['aluno_id']);
        $this->assertSame('2026-09-10', $dados['vencida_desde'], 'O menor vencimento entre as vencidas');
        $this->assertTrue($dados['bloqueado'], 'Mais de 10 dias de atraso');
    }

    public function test_rodar_de_novo_no_mesmo_dia_nao_muda_nada(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->fatura(1, 501, '2026-10-15');

        $this->artisan('faturas:marcar-vencidas')->assertSuccessful();
        $eventos = DB::table('outbox')->count();
        $this->artisan('faturas:marcar-vencidas')->expectsOutput('0 faturas marcadas como vencidas')->assertSuccessful();

        $this->assertSame($eventos, DB::table('outbox')->count());
    }

    public function test_agendado_todo_dia_as_00h05_no_fuso_do_negocio(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'faturas:marcar-vencidas'));

        $this->assertNotNull($evento);
        $this->assertSame('5 0 * * *', $evento->expression);
        $this->assertSame('America/Sao_Paulo', $evento->timezone);
    }
}
