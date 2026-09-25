<?php

declare(strict_types=1);

use EstudaFitHub\Controllers\CheckinController;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Models\Checkin;
use EstudaFitHub\Support\HttpException;

final class CheckinTest extends TestCase
{
    private function checkin(string $cpf, int $unidadeId): array
    {
        $request = new Request('POST', '/checkins', [], ['cpf' => $cpf, 'unidade_id' => $unidadeId]);

        return (new CheckinController())->registrar($request)->dados();
    }

    public function testAlunoEmDiaEhLiberado(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->criarFatura($matricula, ['status' => 'aberta']);

        $resposta = $this->checkin($aluno->cpf, (int) $aluno->unidade_id);

        $this->assertTrue($resposta['liberado']);
        $this->assertNull($resposta['motivo_bloqueio']);
        $this->assertSame(1, Checkin::where('aluno_id', $aluno->id)->where('liberado', 1)->count());
    }

    public function testFaturaVencidaHaMaisDeCincoDiasBloqueiaCatraca(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(10)]);

        $resposta = $this->checkin($aluno->cpf, (int) $aluno->unidade_id);

        $this->assertFalse($resposta['liberado']);
        $this->assertSame('inadimplente', $resposta['motivo_bloqueio']);
    }

    public function testFaturaVencidaRecenteAindaLibera(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(2)]);

        $resposta = $this->checkin($aluno->cpf, (int) $aluno->unidade_id);

        $this->assertTrue($resposta['liberado'], 'Tolerância de 5 dias deve liberar');
    }

    public function testAlunoBloqueadoNaoEntraMesmoSemFaturaVencida(): void
    {
        $aluno = $this->criarAluno(['situacao' => 'bloqueado']);
        $this->criarMatricula($aluno);

        $resposta = $this->checkin($aluno->cpf, (int) $aluno->unidade_id);

        $this->assertFalse($resposta['liberado']);
        $this->assertSame('situacao_bloqueado', $resposta['motivo_bloqueio']);
    }

    public function testCpfDesconhecidoRetorna404(): void
    {
        $this->assertLanca(HttpException::class, fn () => $this->checkin('00000000000', 1), 404);
    }
}
