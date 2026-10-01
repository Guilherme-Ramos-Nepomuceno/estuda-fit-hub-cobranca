<?php

declare(strict_types=1);

use EstudaFitHub\Console\EventosConsumir;
use EstudaFitHub\Console\FaturasGerarMensais;
use EstudaFitHub\Eventos\Evento;
use EstudaFitHub\Eventos\Outbox;
use EstudaFitHub\Http\Aplicacao;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;
use EstudaFitHub\Http\Router;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Support\Correlacao;
use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\Log;

require_once __DIR__ . '/EventosTest.php'; // FonteEmMemoria

/** Controller de teste que falha com uma exceção não tratada. */
final class ControllerQueQuebra
{
    public function acao(Request $request): Response
    {
        throw new RuntimeException('SQLSTATE[HY000]: senha do banco recusada para o usuário root');
    }
}

/**
 * PRD etapa 4: logs estruturados com correlation_id e sem vazamento de erro de banco.
 */
final class LogsTest extends TestCase
{
    public function setUp(): void
    {
        Log::capturar();
    }

    /** @return array<int, array> linhas capturadas com o evento informado */
    private function linhas(string $evento): array
    {
        return array_values(array_filter(Log::capturadas(), static fn (array $l): bool => $l['event'] === $evento));
    }

    /** Critérios 1 e 2 */
    public function testRequisicaoGeraLinhaJsonEDevolveOCorrelationIdRecebido(): void
    {
        $request = new Request('GET', '/health', [], [], [], ['x-correlation-id' => 'corr-recebido-123']);

        $response = (new Aplicacao())->tratar($request);

        $this->assertSame('corr-recebido-123', $response->header('X-Correlation-Id'));
        $linha = $this->linhas('http.requisicao')[0];
        $this->assertSame('info', $linha['level']);
        $this->assertSame('monolito', $linha['service']);
        $this->assertSame('corr-recebido-123', $linha['correlation_id']);
        $this->assertSame(['GET', '/health', 200], [$linha['method'], $linha['path'], $linha['status']]);
        $this->assertTrue(is_float($linha['duracao_ms']) || is_int($linha['duracao_ms']));
        $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $linha['timestamp']), 'ISO-8601 UTC');
    }

    /** Critério 3 */
    public function testSemHeaderGeraUuidV4(): void
    {
        $response = (new Aplicacao())->tratar(new Request('GET', '/health'));

        $id = $response->header('X-Correlation-Id');
        $this->assertSame(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $id));
        $this->assertSame($id, $this->linhas('http.requisicao')[0]['correlation_id']);
    }

    /** Critério 4: o evento gravado carrega o correlation_id da requisição */
    public function testEventoGravadoCarregaOCorrelationIdDaRequisicao(): void
    {
        Correlacao::definir('corr-da-matricula');
        DB::transaction(fn () => Outbox::registrar('Teste', 'teste:1', []));

        $this->assertSame('corr-da-matricula', DB::selectOne('SELECT correlation_id FROM outbox')['correlation_id']);
    }

    /** Critérios 4 e 5: o consumidor registra o processado e o ignorado, com o id de origem */
    public function testConsumidorRegistraProcessadoEIgnorado(): void
    {
        $aluno = $this->criarAluno();
        $evento = new Evento(1, 'evt-log', 'SituacaoFinanceiraAlterada', 1, gmdate('Y-m-d H:i:s') . '.000', 'corr-de-cobranca', "aluno:{$aluno->id}", 1, [
            'aluno_id' => (int) $aluno->id, 'vencida_desde' => null, 'bloqueado' => false,
        ]);
        $antigo = new Evento(2, 'evt-log-antigo', 'SituacaoFinanceiraAlterada', 1, $evento->ocorridoEm, 'corr-de-cobranca', "aluno:{$aluno->id}", 1, $evento->dados);

        $consumidor = EventosConsumir::consumidor(new FonteEmMemoria([$evento, $antigo]));
        $consumidor->processarLote();
        DB::execute("UPDATE consumidor_posicao SET ultimo_id = 0 WHERE feed = 'cobranca'");
        $consumidor->processarLote();

        $processado = $this->linhas('evento.processado')[0];
        $this->assertSame('corr-de-cobranca', $processado['correlation_id']);
        $this->assertSame(['evt-log', 'SituacaoFinanceiraAlterada'], [$processado['event_id'], $processado['tipo']]);
        $this->assertTrue($processado['lag_ms'] >= 0 && $processado['lag_ms'] < 60_000);
        $this->assertSame(['fora_de_ordem', 'duplicado', 'duplicado'], array_column($this->linhas('evento.ignorado'), 'motivo'));
    }

    /** Critério 7 */
    public function testErroInternoNaoVazaODetalheNaResposta(): void
    {
        $router = new Router();
        $router->add('GET', '/quebra', [ControllerQueQuebra::class, 'acao']);
        $request = new Request('GET', '/quebra', [], [], [], ['x-correlation-id' => 'corr-do-erro-1']);

        $response = (new Aplicacao($router))->tratar($request);

        $this->assertSame(500, $response->status());
        $this->assertSame(['erro' => 'Erro interno', 'correlation_id' => 'corr-do-erro-1'], $response->dados());
        $this->assertFalse(str_contains($response->corpo(), 'senha'), 'Nada da exceção na resposta');
        $erro = $this->linhas('http.erro')[0];
        $this->assertSame('error', $erro['level']);
        $this->assertSame(RuntimeException::class, $erro['exception']);
        $this->assertTrue(str_contains($erro['mensagem'], 'senha do banco recusada'));
    }

    /** Critério 8 */
    public function testHealthComBancoForaNaoExpoeAMensagemDoDriver(): void
    {
        $porta = getenv('DB_PORT');
        putenv('DB_PORT=1');
        DB::desconectar();
        try {
            $response = (new Aplicacao())->tratar(new Request('GET', '/health'));
        } finally {
            putenv('DB_PORT=' . $porta);
            DB::desconectar();
        }

        $this->assertSame(503, $response->status());
        $this->assertSame('indisponivel', $response->dados()['banco']);
        $this->assertFalse(str_contains($response->corpo(), 'SQLSTATE'));
        $this->assertSame('error', $this->linhas('health.banco_indisponivel')[0]['level']);
    }

    /** Critério 10 */
    public function testItemQueFalhaNoCronNaoParaOsOutros(): void
    {
        $plano = $this->criarPlano();
        $falha = $this->criarMatricula($this->criarAluno(), $plano);
        $ok = $this->criarMatricula($this->criarAluno(), $plano);
        $gateway = static function (Fatura $f) use ($falha): string {
            if ((int) $f->matricula_id === (int) $falha->id) {
                throw new RuntimeException('gateway recusou a conexão');
            }

            return "gw_{$f->id}_ok";
        };

        ob_start();
        $codigo = (new FaturasGerarMensais($gateway))->executar(['2026-11']);
        ob_end_clean();

        $this->assertSame(1, $codigo, 'Sai com 1 quando houve falha');
        $this->assertNotNull(Fatura::where('matricula_id', $ok->id)->first()->gateway_ref, 'A outra matrícula foi processada');
        $item = $this->linhas('cron.item_falhou')[0];
        $this->assertSame((int) $falha->id, $item['matricula_id']);
        $this->assertSame('gateway recusou a conexão', $item['motivo']);
        $fim = $this->linhas('cron.fim')[0];
        $this->assertSame([1, 0, 1], [$fim['geradas'], $fim['puladas'], $fim['falhas']]);
        $this->assertTrue(isset($fim['duracao_s']));
    }

    /** Critério 10: a fatura que ficou sem registro é registrada na execução seguinte */
    public function testFaturaSemRegistroNoGatewayEhRegistradaNaProximaExecucao(): void
    {
        $matricula = $this->criarMatricula($this->criarAluno());
        ob_start();
        (new FaturasGerarMensais(static fn () => throw new RuntimeException('fora do ar')))->executar(['2026-11']);
        $codigo = (new FaturasGerarMensais(static fn (Fatura $f): string => "gw_{$f->id}_retry"))->executar(['2026-11']);
        ob_end_clean();

        $this->assertSame(0, $codigo);
        $faturas = Fatura::where('matricula_id', $matricula->id)->where('competencia', '2026-11-01')->get();
        $this->assertCount(1, $faturas);
        $this->assertSame("gw_{$faturas[0]->id}_retry", $faturas[0]->gateway_ref);
    }

    /** Critério 11 */
    public function testDadosPessoaisSaemMascarados(): void
    {
        Log::info('teste.mascaramento', [
            'cpf' => '12345678901',
            'email' => 'maria.souza@exemplo.com',
            'telefone' => '(11) 99999-1234',
            'mensagem' => 'Duplicate entry 987.654.321-00 para joao@exemplo.com',
        ]);

        $linha = json_encode($this->linhas('teste.mascaramento')[0]);
        foreach (['12345678901', 'maria.souza', '99999', '987.654.321-00', 'joao@'] as $proibido) {
            $this->assertFalse(str_contains($linha, $proibido), "Vazou: {$proibido}");
        }
        $this->assertTrue(str_contains($linha, '***.***.***-01'));
        $this->assertTrue(str_contains($linha, 'm***@exemplo.com'));
    }
}
