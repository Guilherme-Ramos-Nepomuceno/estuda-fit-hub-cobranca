<?php

declare(strict_types=1);

use EstudaFitHub\Controllers\CheckinController;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Models\Checkin;
use EstudaFitHub\Services\SituacaoFinanceiraLocal;
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

    /**
     * Ajustado (ponto B, PRD etapa 3): a catraca lê a cópia local da situação financeira. A fábrica
     * grava a fatura direto no banco, sem passar pelo código legado, então o teste recalcula a cópia
     * como o código legado faz na mesma transação.
     */
    public function testFaturaVencidaHaMaisDeCincoDiasBloqueiaCatraca(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(10)]);
        SituacaoFinanceiraLocal::recalcular((int) $aluno->id);

        $resposta = $this->checkin($aluno->cpf, (int) $aluno->unidade_id);

        $this->assertFalse($resposta['liberado']);
        $this->assertSame('inadimplente', $resposta['motivo_bloqueio']);
    }

    /** Ajustado como o anterior. */
    public function testFaturaVencidaRecenteAindaLibera(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(2)]);
        SituacaoFinanceiraLocal::recalcular((int) $aluno->id);

        $resposta = $this->checkin($aluno->cpf, (int) $aluno->unidade_id);

        $this->assertTrue($resposta['liberado'], 'Tolerância de 5 dias deve liberar');
    }

    /** Etapa 3, critério 1: a catraca não lê faturas */
    public function testDecideSoPelaCopiaLocalSemLerFaturas(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(30)]);
        SituacaoFinanceiraLocal::aplicar((int) $aluno->id, null, 1);

        $this->assertTrue($this->checkin($aluno->cpf, (int) $aluno->unidade_id)['liberado']);
    }

    /** Etapa 3, critérios 2 e 3: limite da tolerância */
    public function testToleranciaDeCincoDiasPelaCopiaLocal(): void
    {
        $seis = $this->criarAluno();
        $cinco = $this->criarAluno();
        SituacaoFinanceiraLocal::aplicar((int) $seis->id, $this->diasAtras(6), 1);
        SituacaoFinanceiraLocal::aplicar((int) $cinco->id, $this->diasAtras(5), 1);

        $this->assertSame('inadimplente', $this->checkin($seis->cpf, (int) $seis->unidade_id)['motivo_bloqueio']);
        $this->assertTrue($this->checkin($cinco->cpf, (int) $cinco->unidade_id)['liberado']);
    }

    /** Etapa 3, critério 4 */
    public function testSemLinhaNaCopiaLocalLibera(): void
    {
        $aluno = $this->criarAluno();

        $this->assertTrue($this->checkin($aluno->cpf, (int) $aluno->unidade_id)['liberado']);
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
