<?php

declare(strict_types=1);

use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Notificacao;
use EstudaFitHub\Services\MatriculaService;
use EstudaFitHub\Support\HttpException;

final class MatriculaServiceTest extends TestCase
{
    public function testCriarMatriculaGeraPrimeiraFaturaENotificacao(): void
    {
        $aluno = $this->criarAluno();
        $plano = $this->criarPlano(['valor_mensal' => '119.90', 'duracao_meses' => 12]);

        $matricula = (new MatriculaService())->criar($aluno, $plano, 15);

        $this->assertSame('ativa', $matricula->status);
        $this->assertSame(15, (int) $matricula->dia_vencimento);

        $faturas = Fatura::where('matricula_id', $matricula->id)->get();
        $this->assertCount(1, $faturas);
        $this->assertSame('aberta', $faturas[0]->status);
        $this->assertSame(119.90, (float) $faturas[0]->valor);
        $this->assertNotNull($faturas[0]->gateway_ref, 'Fatura deve ter referência do gateway');

        $notificacoes = Notificacao::where('aluno_id', $aluno->id)->get();
        $this->assertCount(1, $notificacoes);
        $this->assertSame('fatura_gerada', $notificacoes[0]->template);
        $this->assertSame('email', $notificacoes[0]->canal);
    }

    public function testNaoPermiteSegundaMatriculaAtiva(): void
    {
        $aluno = $this->criarAluno();
        $plano = $this->criarPlano();
        $this->criarMatricula($aluno, $plano);

        $this->assertLanca(HttpException::class, fn () => (new MatriculaService())->criar($aluno, $plano, 10), 422);
    }

    public function testDiaDeVencimentoForaDaFaixaEhRejeitado(): void
    {
        $aluno = $this->criarAluno();
        $plano = $this->criarPlano();

        $this->assertLanca(HttpException::class, fn () => (new MatriculaService())->criar($aluno, $plano, 31), 422);
    }

    public function testCancelarMatriculaCancelaFaturasEmAbertoEInativaAluno(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->criarFatura($matricula, ['status' => 'paga', 'competencia' => date('Y-m-01', strtotime('-1 month'))]);
        $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(3)]);
        $this->criarFatura($matricula, ['status' => 'aberta']);

        (new MatriculaService())->cancelar($matricula);

        $this->assertSame('cancelada', $matricula->refresh()->status);
        $this->assertSame('inativo', $aluno->refresh()->situacao);
        $this->assertSame(1, Fatura::where('matricula_id', $matricula->id)->where('status', 'paga')->count());
        $this->assertSame(2, Fatura::where('matricula_id', $matricula->id)->where('status', 'cancelada')->count());
    }
}
