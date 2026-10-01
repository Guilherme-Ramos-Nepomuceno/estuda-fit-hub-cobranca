<?php

namespace Tests\Feature;

use App\Cobranca\GerarPrimeiraFatura;
use App\Eventos\Consumidor;
use App\Eventos\Evento;
use App\Eventos\EventSource;
use App\Gateway\Gateway;
use App\Gateway\GatewaySimulado;
use App\Models\Fatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** PRD etapa 2, critérios 2, 3, 9, 10 e 13: MatriculaCriada → fatura → FaturaGerada. */
class GerarPrimeiraFaturaTest extends TestCase
{
    use RefreshDatabase;

    private function matriculaCriada(int $posicao = 1, int $sequencia = 1, string $eventId = 'evt-1', string $valor = '119.90'): Evento
    {
        return new Evento($posicao, $eventId, 'MatriculaCriada', 1, '2026-10-15 23:30:00.000', 'corr-1', 'matricula:77', $sequencia, [
            'matricula_id' => 77, 'aluno_id' => 501, 'unidade_id' => 2, 'plano_id' => 3,
            'valor_mensal' => $valor, 'dia_vencimento' => 15, 'inicio' => '2026-10-15', 'fim' => '2027-10-15',
        ]);
    }

    /** @param Evento[] $eventos */
    private function consumir(array $eventos, ?Gateway $gateway = null): void
    {
        $fonte = new class($eventos) implements EventSource
        {
            public function __construct(private array $eventos)
            {
            }

            public function buscar(int $aposId, int $max): array
            {
                return array_values(array_filter($this->eventos, fn (Evento $e) => $e->id > $aposId));
            }
        };

        (new Consumidor($fonte, 'monolito', ['MatriculaCriada' => new GerarPrimeiraFatura($gateway ?? new GatewaySimulado())]))->processarLote();
    }

    public function test_gera_a_fatura_com_os_termos_da_matricula(): void
    {
        $this->consumir([$this->matriculaCriada()]);

        $fatura = Fatura::sole();
        $this->assertSame(77, $fatura->matricula_id);
        $this->assertSame(501, $fatura->aluno_id);
        $this->assertSame('2026-10-01', $fatura->competencia->toDateString());
        $this->assertSame('119.90', $fatura->valor);
        $this->assertSame('2026-10-18', $fatura->vencimento->toDateString(), 'Data local da matrícula + 3 dias');
        $this->assertSame('aberta', $fatura->status);
        $this->assertMatchesRegularExpression('/^gw_\d+_[0-9a-f]{8}$/', $fatura->gateway_ref);
        $this->assertGreaterThanOrEqual(2000000000, $fatura->id);
    }

    public function test_publica_fatura_gerada_com_a_referencia_do_gateway(): void
    {
        $this->consumir([$this->matriculaCriada()]);

        $evento = DB::table('outbox')->where('tipo', 'FaturaGerada')->sole();
        $dados = json_decode($evento->dados, true);
        $fatura = Fatura::sole();
        $this->assertSame("fatura:{$fatura->id}", $evento->aggregate_id);
        $this->assertSame('corr-1', $evento->correlation_id, 'Propaga o correlation_id da matrícula');
        $esperado = [
            'fatura_id' => $fatura->id, 'matricula_id' => 77, 'aluno_id' => 501, 'competencia' => '2026-10-01',
            'valor' => '119.90', 'vencimento' => '2026-10-18', 'gateway_ref' => $fatura->gateway_ref,
        ];
        // O MySQL normaliza a ordem das chaves em colunas JSON; tipos e valores continuam estritos.
        ksort($esperado);
        ksort($dados);
        $this->assertSame($esperado, $dados);
    }

    public function test_mesmo_evento_reprocessado_tem_efeito_unico(): void
    {
        $this->consumir([$this->matriculaCriada()]);
        DB::table('consumidor_posicao')->update(['ultimo_id' => 0]);
        $this->consumir([$this->matriculaCriada()]);

        $this->assertSame(1, Fatura::count());
        $this->assertSame(1, DB::table('outbox')->where('tipo', 'FaturaGerada')->count());
    }

    public function test_evento_com_sequencia_ja_aplicada_e_descartado(): void
    {
        $this->consumir([
            $this->matriculaCriada(posicao: 1, sequencia: 2, eventId: 'evt-novo'),
            $this->matriculaCriada(posicao: 2, sequencia: 1, eventId: 'evt-antigo', valor: '1.00'),
        ]);

        $this->assertSame('119.90', Fatura::sole()->valor);
        $this->assertSame(2, (int) DB::table('consumidor_posicao')->where('feed', 'monolito')->value('ultimo_id'));
    }

    public function test_falha_no_gateway_nao_publica_e_a_proxima_tentativa_completa(): void
    {
        $falha = new class implements Gateway
        {
            public function registrarCobranca(Fatura $fatura): string
            {
                throw new RuntimeException('gateway fora do ar');
            }
        };

        try {
            $this->consumir([$this->matriculaCriada()], $falha);
            $this->fail('A falha do gateway deveria interromper o lote');
        } catch (RuntimeException) {
        }

        $this->assertNull(Fatura::sole()->gateway_ref);
        $this->assertSame(0, DB::table('outbox')->where('tipo', 'FaturaGerada')->count());
        $this->assertSame(0, DB::table('eventos_processados')->count(), 'O evento continua pendente');

        $this->consumir([$this->matriculaCriada()]);

        $this->assertSame(1, Fatura::count());
        $this->assertNotNull(Fatura::sole()->gateway_ref);
        $this->assertSame(1, DB::table('outbox')->where('tipo', 'FaturaGerada')->count());
    }
}
