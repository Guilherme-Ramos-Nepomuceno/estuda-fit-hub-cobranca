<?php

declare(strict_types=1);

use EstudaFitHub\Console\EventosConsumir;
use EstudaFitHub\Controllers\AlunoController;
use EstudaFitHub\Controllers\EventosController;
use EstudaFitHub\Controllers\RelatorioController;
use EstudaFitHub\Eventos\Evento;
use EstudaFitHub\Eventos\EventSource;
use EstudaFitHub\Eventos\Outbox;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Notificacao;
use EstudaFitHub\Support\DB;

/** Feed em memória no lugar do HTTP: o consumidor depende só da interface (ADR-005). */
final class FonteEmMemoria implements EventSource
{
    /** @param Evento[] $eventos */
    public function __construct(public array $eventos)
    {
    }

    public function buscar(int $aposId, int $max): array
    {
        return array_slice(array_values(array_filter($this->eventos, static fn (Evento $e): bool => $e->id > $aposId)), 0, $max);
    }
}

/**
 * PRD etapa 2: feed do monólito e consumo idempotente de FaturaGerada.
 */
final class EventosTest extends TestCase
{
    public function setUp(): void
    {
        putenv('EVENTOS_TOKEN=token-de-teste');
    }

    private function feed(?string $autorizacao, array $query = []): array
    {
        $headers = $autorizacao === null ? [] : ['authorization' => $autorizacao];
        $resposta = (new EventosController())->listar(new Request('GET', '/eventos', $query, [], [], $headers));

        return [$resposta->status(), $resposta->dados()];
    }

    private function faturaGerada(int $posicao, int $faturaId, int $matriculaId, int $alunoId, int $sequencia = 1, string $eventId = ''): Evento
    {
        return new Evento($posicao, $eventId ?: "evt-{$posicao}", 'FaturaGerada', 1, '2026-10-01 12:00:00.000', 'corr-1', "fatura:{$faturaId}", $sequencia, [
            'fatura_id' => $faturaId, 'matricula_id' => $matriculaId, 'aluno_id' => $alunoId,
            'competencia' => '2026-10-01', 'valor' => '119.90', 'vencimento' => '2026-10-04', 'gateway_ref' => "gw_{$faturaId}_abcdef12",
        ]);
    }

    /** Critério 7 */
    public function testFeedEntregaEmOrdemComLimite(): void
    {
        DB::transaction(function (): void {
            foreach (range(1, 3) as $i) {
                Outbox::registrar('Teste', "teste:{$i}", ['n' => $i]);
            }
        });

        [$status, $dados] = $this->feed('Bearer token-de-teste', ['apos' => '0', 'max' => '2']);

        $this->assertSame(200, $status);
        $this->assertCount(2, $dados['eventos']);
        $this->assertTrue($dados['eventos'][0]['id'] < $dados['eventos'][1]['id'], 'Ordem crescente de id');
        $this->assertSame(1, $dados['eventos'][0]['versao']);
        $this->assertSame(['n' => 1], $dados['eventos'][0]['dados']);

        [, $resto] = $this->feed('Bearer token-de-teste', ['apos' => (string) $dados['eventos'][1]['id']]);
        $this->assertCount(1, $resto['eventos']);
    }

    /** Critério 8 */
    public function testFeedSemTokenOuComTokenErradoRetorna401(): void
    {
        DB::transaction(fn () => Outbox::registrar('Teste', 'teste:1', []));

        foreach ([null, 'Bearer outro-token', 'token-de-teste'] as $autorizacao) {
            [$status, $dados] = $this->feed($autorizacao);
            $this->assertSame(401, $status);
            $this->assertSame(['erro' => 'Não autorizado'], $dados);
        }
    }

    /** Critério 4 */
    public function testFaturaGeradaCriaCopiaComMesmoIdEEnviaEmail(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fonte = new FonteEmMemoria([$this->faturaGerada(1, 2000000001, (int) $matricula->id, (int) $aluno->id)]);

        EventosConsumir::consumidor($fonte)->processarLote();

        $copia = Fatura::where('cobranca_id', 2000000001)->first();
        $this->assertNotNull($copia);
        $this->assertSame('gw_2000000001_abcdef12', $copia->gateway_ref);
        $this->assertSame('aberta', $copia->status);
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->where('template', 'fatura_gerada')->count());

        $tela = (new AlunoController())->faturas((new Request('GET', '/alunos/x/faturas'))->comParametros(['id' => (string) $aluno->id]))->dados();
        $this->assertSame(2000000001, (int) $tela['faturas'][0]['cobranca_id']);
    }

    /** Critério 9: reprocessar o mesmo evento não repete o efeito */
    public function testMesmoEventoDuasVezesTemEfeitoUnico(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fonte = new FonteEmMemoria([$this->faturaGerada(1, 2000000001, (int) $matricula->id, (int) $aluno->id)]);

        $consumidor = EventosConsumir::consumidor($fonte);
        $consumidor->processarLote();
        DB::execute("UPDATE consumidor_posicao SET ultimo_id = 0 WHERE feed = 'cobranca'");
        $consumidor->processarLote();

        $this->assertSame(1, Fatura::where('aluno_id', $aluno->id)->count());
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->count());
    }

    /** Etapa 3, critério 3 */
    public function testFaturaPagaAtualizaCopiaGravaPagamentoEEnviaSms(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno, null, []);
        $copia = $this->criarFatura($matricula, ['cobranca_id' => 2000000001, 'competencia' => '2026-10-01', 'gateway_ref' => 'gw_2000000001_abcdef12']);
        $paga = new Evento(1, 'evt-paga', 'FaturaPaga', 1, '2026-10-20 15:00:00.000', 'corr-pg', 'fatura:2000000001', 2, [
            'fatura_id' => 2000000001, 'aluno_id' => (int) $aluno->id, 'pagamento_id' => 2000000050,
            'valor' => '99.90', 'metodo' => 'pix', 'pago_em' => '2026-10-20 15:00:00',
        ]);

        EventosConsumir::consumidor(new FonteEmMemoria([$paga]))->processarLote();

        $fatura = Fatura::where('cobranca_id', 2000000001)->first();
        $this->assertSame('paga', $fatura->status);
        $this->assertSame('2026-10-20 12:00:00', $fatura->pago_em, 'UTC convertido para o fuso local');
        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) AS total FROM pagamentos WHERE cobranca_id = 2000000050 AND fatura_id = ?', [$copia->id])['total']);
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->where('template', 'pagamento_confirmado')->where('canal', 'sms')->count());

        $receita = (new RelatorioController())->receita(new Request('GET', '/relatorios/receita', ['competencia' => '2026-10']))->dados();
        $this->assertSame(99.90, (float) $receita['receita'][0]['receita']);
    }

    /** Etapa 3, critério 4 */
    public function testSituacaoSemBloqueioReativaAlunoBloqueado(): void
    {
        $aluno = $this->criarAluno(['situacao' => 'bloqueado']);
        $evento = new Evento(1, 'evt-sit', 'SituacaoFinanceiraAlterada', 1, '2026-10-20 15:00:00.000', 'corr-pg', "aluno:{$aluno->id}", 1, [
            'aluno_id' => (int) $aluno->id, 'vencida_desde' => null, 'bloqueado' => false,
        ]);

        EventosConsumir::consumidor(new FonteEmMemoria([$evento]))->processarLote();

        $this->assertSame('ativo', $aluno->refresh()->situacao);
    }

    /** Critério 10: sequência já aplicada é descartada */
    public function testEventoForaDeOrdemNaoAlteraDados(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $novo = $this->faturaGerada(1, 2000000001, (int) $matricula->id, (int) $aluno->id, 2, 'evt-novo');
        $antigo = $this->faturaGerada(2, 2000000001, (int) $matricula->id, (int) $aluno->id, 1, 'evt-antigo');
        $antigo = new Evento(2, 'evt-antigo', 'FaturaGerada', 1, $antigo->ocorridoEm, 'corr-1', $antigo->aggregateId, 1, ['gateway_ref' => 'gw_antigo'] + $antigo->dados);

        EventosConsumir::consumidor(new FonteEmMemoria([$novo, $antigo]))->processarLote();

        $this->assertSame('gw_2000000001_abcdef12', Fatura::where('cobranca_id', 2000000001)->first()->gateway_ref);
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->count());
        $this->assertSame(2, (int) DB::selectOne("SELECT ultimo_id FROM consumidor_posicao WHERE feed = 'cobranca'")['ultimo_id'], 'A posição avança mesmo descartando');
    }
}
