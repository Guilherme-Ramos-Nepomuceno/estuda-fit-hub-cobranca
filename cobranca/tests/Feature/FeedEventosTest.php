<?php

namespace Tests\Feature;

use App\Eventos\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PRD etapa 1, critérios 7 e 8: feed de eventos de Cobrança. */
class FeedEventosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cobranca.eventos.token' => 'token-de-teste']);
        DB::transaction(function (): void {
            foreach (range(1, 3) as $i) {
                Outbox::registrar('Teste', "teste:{$i}", ['n' => $i]);
            }
        });
    }

    public function test_entrega_em_ordem_respeitando_o_limite(): void
    {
        $resposta = $this->withToken('token-de-teste')->getJson('/eventos?apos=0&max=2')->assertOk();

        $eventos = $resposta->json('eventos');
        $this->assertCount(2, $eventos);
        $this->assertLessThan($eventos[1]['id'], $eventos[0]['id']);
        $this->assertSame(['n' => 1], $eventos[0]['dados']);
        $this->assertSame(1, $eventos[0]['versao']);

        $this->withToken('token-de-teste')->getJson('/eventos?apos='.$eventos[1]['id'])->assertOk()->assertJsonCount(1, 'eventos');
    }

    public function test_sem_token_ou_com_token_errado_retorna_401(): void
    {
        $this->getJson('/eventos')->assertStatus(401)->assertExactJson(['erro' => 'Não autorizado']);
        $this->withToken('outro')->getJson('/eventos')->assertStatus(401)->assertExactJson(['erro' => 'Não autorizado']);
    }
}
