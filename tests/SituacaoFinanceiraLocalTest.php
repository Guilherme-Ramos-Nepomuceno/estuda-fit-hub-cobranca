<?php

declare(strict_types=1);

use EstudaFitHub\Console\EventosConsumir;
use EstudaFitHub\Console\FaturasMarcarVencidas;
use EstudaFitHub\Console\ProjecaoRecalcular;
use EstudaFitHub\Controllers\PagamentoWebhookController;
use EstudaFitHub\Eventos\Evento;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Services\MatriculaService;
use EstudaFitHub\Support\DB;

require_once __DIR__ . '/EventosTest.php'; // FonteEmMemoria

/**
 * PRD etapa 4: a cópia local da situação financeira, que a catraca lê, fica atualizada.
 */
final class SituacaoFinanceiraLocalTest extends TestCase
{
    private function vencidaDesde(int $alunoId): ?string
    {
        return DB::selectOne('SELECT vencida_desde FROM situacao_financeira WHERE aluno_id = ?', [$alunoId])['vencida_desde'] ?? null;
    }

    private function semSaida(callable $fn): void
    {
        ob_start();
        $fn();
        ob_end_clean();
    }

    /** Critério 6 */
    public function testEventoDeCobrancaAtualizaECorrigeACopia(): void
    {
        $aluno = $this->criarAluno();
        $evento = fn (int $pos, int $seq, ?string $desde) => new Evento($pos, "evt-sit-{$pos}", 'SituacaoFinanceiraAlterada', 1, '2026-10-20 03:05:00.000', 'corr', "aluno:{$aluno->id}", $seq, [
            'aluno_id' => (int) $aluno->id, 'vencida_desde' => $desde, 'bloqueado' => false,
        ]);

        EventosConsumir::consumidor(new FonteEmMemoria([$evento(1, 1, '2026-09-10')]))->processarLote();
        $this->assertSame('2026-09-10', $this->vencidaDesde((int) $aluno->id));

        EventosConsumir::consumidor(new FonteEmMemoria([$evento(2, 2, null)]))->processarLote();
        $this->assertNull($this->vencidaDesde((int) $aluno->id), 'null limpa o débito');
    }

    /** Critério 13 */
    public function testFaturaVencidaDeCobrancaAtualizaACopiaDaFatura(): void
    {
        $aluno = $this->criarAluno();
        $copia = $this->criarFatura($this->criarMatricula($aluno), ['cobranca_id' => 2000000020]);
        $evento = new Evento(1, 'evt-venc', 'FaturaVencida', 1, '2026-10-20 03:05:00.000', 'corr', 'fatura:2000000020', 2, [
            'fatura_id' => 2000000020, 'aluno_id' => (int) $aluno->id, 'vencimento' => '2026-10-18',
        ]);

        EventosConsumir::consumidor(new FonteEmMemoria([$evento]))->processarLote();

        $this->assertSame('vencida', $copia->refresh()->status);
    }

    /** Critério 7: marcar vencidas do monólito */
    public function testMarcarVencidasDoMonolitoRecalculaENaoMexeNasCopias(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $legada = $this->criarFatura($matricula, ['vencimento' => $this->diasAtras(8)]);
        $copia = $this->criarFatura($matricula, ['cobranca_id' => 2000000021, 'competencia' => '2026-01-01', 'vencimento' => $this->diasAtras(40)]);

        $this->semSaida(fn () => (new FaturasMarcarVencidas())->executar([]));

        $this->assertSame('vencida', $legada->refresh()->status);
        $this->assertSame('aberta', $copia->refresh()->status, 'A cópia de Cobrança só muda por evento');
        $this->assertSame($this->diasAtras(8), $this->vencidaDesde((int) $aluno->id));
    }

    /** Critério 7: webhook legado e cancelamento */
    public function testPagamentoLegadoECancelamentoRecalculam(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(9)]);
        $outra = $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(20), 'competencia' => '2026-01-01']);
        $this->semSaida(fn () => (new ProjecaoRecalcular())->executar([]));
        $this->assertSame($this->diasAtras(20), $this->vencidaDesde((int) $aluno->id));

        (new PagamentoWebhookController())->receber(new Request('POST', '/webhooks/pagamento', [], ['reference' => $outra->gateway_ref, 'status' => 'approved', 'event_id' => 'evt_legado']));
        $this->assertSame($this->diasAtras(9), $this->vencidaDesde((int) $aluno->id), 'Sobra a outra vencida');

        (new MatriculaService())->cancelar($matricula);
        $this->assertNull($this->vencidaDesde((int) $aluno->id), 'Cancelamento limpa o débito');
        $this->assertSame('cancelada', $fatura->refresh()->status);
    }

    /** Critério 8 */
    public function testRecalcularTodosEhIdempotente(): void
    {
        $devedor = $this->criarAluno();
        $emDia = $this->criarAluno();
        $this->criarFatura($this->criarMatricula($devedor), ['status' => 'vencida', 'vencimento' => '2026-08-10']);

        $this->semSaida(fn () => (new ProjecaoRecalcular())->executar([]));
        $this->semSaida(fn () => (new ProjecaoRecalcular())->executar([]));

        $this->assertSame('2026-08-10', $this->vencidaDesde((int) $devedor->id));
        $this->assertNull($this->vencidaDesde((int) $emDia->id));
        $this->assertSame(2, (int) DB::selectOne('SELECT COUNT(*) AS total FROM situacao_financeira')['total']);
    }
}
