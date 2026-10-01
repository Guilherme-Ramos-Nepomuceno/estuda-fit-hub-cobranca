<?php

namespace Tests\Feature;

use App\Eventos\Consumidor;
use App\Eventos\Evento;
use App\Eventos\EventSource;
use App\Logging\FormatoJson;
use App\Support\Correlacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

/** PRD etapa 5, critérios 1 a 3, 5, 9 e 11: logs estruturados em Cobrança. */
class LogsTest extends TestCase
{
    use RefreshDatabase;

    /** @var MessageLogged[] */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, fn (MessageLogged $m) => $this->logs[] = $m);
        config(['cobranca.eventos.token' => 'token-de-teste']);
    }

    private function log(string $evento): ?MessageLogged
    {
        return collect($this->logs)->first(fn (MessageLogged $m) => $m->message === $evento);
    }

    public function test_requisicao_devolve_o_correlation_id_recebido_e_registra_a_linha(): void
    {
        $this->withHeader('X-Correlation-Id', 'corr-recebido-123')
            ->getJson('/up')->assertOk()->assertHeader('X-Correlation-Id', 'corr-recebido-123');

        $linha = $this->log('http.requisicao');
        $this->assertSame('info', $linha->level);
        $this->assertSame(['method' => 'GET', 'path' => '/up', 'status' => 200], array_intersect_key($linha->context, array_flip(['method', 'path', 'status'])));
        $this->assertIsFloat($linha->context['duracao_ms']);
        $this->assertSame('corr-recebido-123', Correlacao::id());
    }

    public function test_consulta_ao_feed_so_vira_log_quando_falha(): void
    {
        $this->withToken('token-de-teste')->getJson('/eventos')->assertOk();
        $this->assertNull($this->log('http.requisicao'), 'Consulta bem-sucedida ao feed não gera linha');

        $this->withToken('errado')->getJson('/eventos')->assertStatus(401);
        $this->assertSame(401, $this->log('http.requisicao')->context['status']);
    }

    public function test_sem_header_gera_um_uuid(): void
    {
        $id = $this->getJson('/up')->headers->get('X-Correlation-Id');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $id);
    }

    public function test_formato_da_linha_e_mascaramento(): void
    {
        Correlacao::definir('corr-formato-1');
        $linha = json_decode((new FormatoJson)->format(new LogRecord(
            datetime: new \DateTimeImmutable('2026-10-20 09:00:00.123', new \DateTimeZone('America/Sao_Paulo')),
            channel: 'local',
            level: Level::Warning,
            message: 'teste.formato',
            context: ['cpf' => '12345678901', 'email' => 'maria.souza@exemplo.com', 'mensagem' => 'falhou para 987.654.321-00'],
        )), true);

        $this->assertSame('2026-10-20T12:00:00.123Z', $linha['timestamp']);
        $this->assertSame(['warning', 'cobranca', 'corr-formato-1', 'teste.formato'], [$linha['level'], $linha['service'], $linha['correlation_id'], $linha['event']]);
        $this->assertSame('***.***.***-01', $linha['cpf']);
        $this->assertSame('m***@exemplo.com', $linha['email']);
        $this->assertSame('falhou para ***.***.***-00', $linha['mensagem']);
    }

    public function test_erro_interno_responde_so_o_correlation_id_e_nao_loga_os_valores_da_consulta(): void
    {
        Route::get('/teste-erro', fn () => DB::select('SELECT * FROM tabela_que_nao_existe WHERE token = ?', ['segredo-do-cliente']));

        $resposta = $this->withHeader('X-Correlation-Id', 'corr-do-erro-1')->getJson('/teste-erro');

        $resposta->assertStatus(500)->assertExactJson(['erro' => 'Erro interno', 'correlation_id' => 'corr-do-erro-1']);
        $erro = $this->log('http.erro');
        $this->assertSame('error', $erro->level);
        $mensagem = $erro->context['exception']->getMessage();
        $this->assertStringContainsString('tabela_que_nao_existe', $mensagem, 'O log tem o que o suporte precisa');
        $this->assertStringNotContainsString('segredo-do-cliente', $mensagem, 'Mas não os valores da consulta');
    }

    public function test_erro_de_cliente_nao_vira_500(): void
    {
        Route::get('/teste-404', fn () => abort(404));

        $this->getJson('/teste-404')->assertStatus(404);
        $this->assertNull($this->log('http.erro'));
    }

    public function test_consumidor_registra_processado_e_ignorado(): void
    {
        $evento = new Evento(1, 'evt-log', 'TipoSemHandler', 1, now('UTC')->format('Y-m-d H:i:s.v'), 'corr-do-monolito', 'x:1', 1, []);
        $fonte = new class([$evento]) implements EventSource
        {
            public function __construct(private array $eventos)
            {
            }

            public function buscar(int $aposId, int $max): array
            {
                return array_values(array_filter($this->eventos, fn (Evento $e) => $e->id > $aposId));
            }
        };
        $consumidor = new Consumidor($fonte, 'monolito', []);

        $consumidor->processarLote();
        DB::table('consumidor_posicao')->update(['ultimo_id' => 0]);
        $consumidor->processarLote();

        $processado = $this->log('evento.processado');
        $this->assertSame(['evt-log', 'TipoSemHandler'], [$processado->context['event_id'], $processado->context['tipo']]);
        $this->assertGreaterThanOrEqual(0, $processado->context['lag_ms']);
        $this->assertSame('duplicado', $this->log('evento.ignorado')->context['motivo']);
        $this->assertSame('corr-do-monolito', Correlacao::id());
    }
}
