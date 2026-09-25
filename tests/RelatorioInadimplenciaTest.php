<?php

declare(strict_types=1);

use EstudaFitHub\Controllers\RelatorioController;
use EstudaFitHub\Http\Request;

final class RelatorioInadimplenciaTest extends TestCase
{
    public function testAgrupaFaturasVencidasPorAlunoDaUnidade(): void
    {
        $unidade = $this->criarUnidade();
        $outraUnidade = $this->criarUnidade(['nome' => 'Outra']);
        $plano = $this->criarPlano();

        $devedor = $this->criarAluno(['unidade_id' => $unidade->id]);
        $matriculaDevedor = $this->criarMatricula($devedor, $plano);
        $this->criarFatura($matriculaDevedor, ['status' => 'vencida', 'valor' => '100.00', 'vencimento' => $this->diasAtras(40)]);
        $this->criarFatura($matriculaDevedor, ['status' => 'vencida', 'valor' => '50.00', 'vencimento' => $this->diasAtras(10)]);
        $this->criarFatura($matriculaDevedor, ['status' => 'paga', 'valor' => '100.00']);

        $emDia = $this->criarAluno(['unidade_id' => $unidade->id]);
        $this->criarFatura($this->criarMatricula($emDia, $plano), ['status' => 'aberta']);

        $devedorDeOutraUnidade = $this->criarAluno(['unidade_id' => $outraUnidade->id]);
        $this->criarFatura($this->criarMatricula($devedorDeOutraUnidade, $plano), ['status' => 'vencida', 'vencimento' => $this->diasAtras(30)]);

        $request = new Request('GET', '/relatorios/inadimplencia', ['unidade_id' => (string) $unidade->id]);
        $dados = (new RelatorioController())->inadimplencia($request)->dados();

        $this->assertCount(1, $dados['inadimplentes']);
        $linha = $dados['inadimplentes'][0];
        $this->assertSame((int) $devedor->id, (int) $linha['aluno_id']);
        $this->assertSame(2, (int) $linha['faturas_vencidas']);
        $this->assertSame(150.0, (float) $linha['total_devido']);
        $this->assertSame($this->diasAtras(40), $linha['vencida_desde']);
    }
}
