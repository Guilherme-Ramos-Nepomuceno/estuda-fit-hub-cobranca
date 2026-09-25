<?php

declare(strict_types=1);

use EstudaFitHub\Console\FaturasGerarMensais;
use EstudaFitHub\Models\Fatura;

final class FaturasGerarMensaisTest extends TestCase
{
    private function rodar(string $competencia): void
    {
        ob_start();
        (new FaturasGerarMensais())->executar([$competencia]);
        ob_end_clean();
    }

    public function testGeraUmaFaturaPorMatriculaAtiva(): void
    {
        $plano = $this->criarPlano(['valor_mensal' => '89.90']);
        $ativa1 = $this->criarMatricula($this->criarAluno(), $plano, ['dia_vencimento' => 5]);
        $ativa2 = $this->criarMatricula($this->criarAluno(), $plano, ['dia_vencimento' => 20]);
        $this->criarMatricula($this->criarAluno(), $plano, ['status' => 'cancelada']);
        $this->criarMatricula($this->criarAluno(), $plano, ['status' => 'trancada']);

        $this->rodar('2026-03');

        $faturas = Fatura::where('competencia', '2026-03-01')->orderBy('id')->get();
        $this->assertCount(2, $faturas);
        $this->assertSame((int) $ativa1->id, (int) $faturas[0]->matricula_id);
        $this->assertSame('2026-03-05', $faturas[0]->vencimento);
        $this->assertSame((int) $ativa2->id, (int) $faturas[1]->matricula_id);
        $this->assertSame('2026-03-20', $faturas[1]->vencimento);
        $this->assertSame(89.90, (float) $faturas[0]->valor);
        $this->assertNotNull($faturas[0]->gateway_ref);
    }

    public function testNaoDuplicaFaturaNaMesmaCompetencia(): void
    {
        $this->criarMatricula($this->criarAluno());

        $this->rodar('2026-04');
        $this->rodar('2026-04');

        $this->assertSame(1, Fatura::where('competencia', '2026-04-01')->count());
    }
}
