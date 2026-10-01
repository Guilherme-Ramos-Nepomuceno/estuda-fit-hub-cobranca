<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AmbienteTest extends TestCase
{
    public function test_health_check_responde(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_testes_rodam_no_banco_de_teste(): void
    {
        $this->assertSame('cobranca_test', DB::selectOne('SELECT DATABASE() AS banco')->banco);
    }

    public function test_servico_nao_acessa_o_banco_do_monolito(): void
    {
        // ADR-002 e ADR-005: a fronteira de dados é imposta pelas permissões do banco.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/denied/i');

        DB::select('SELECT 1 FROM estuda_fit_hub.faturas LIMIT 1');
    }
}
