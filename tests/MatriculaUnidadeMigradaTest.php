<?php

declare(strict_types=1);

use EstudaFitHub\Console\UnidadesMigrar;
use EstudaFitHub\Controllers\MatriculaController;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Notificacao;
use EstudaFitHub\Services\MatriculaService;
use EstudaFitHub\Services\UnidadesMigradas;
use EstudaFitHub\Support\DB;

/**
 * PRD etapa 2: matrícula numa unidade migrada não cria fatura local, publica MatriculaCriada.
 */
final class MatriculaUnidadeMigradaTest extends TestCase
{
    private function matricular(int $alunoId, int $planoId, int $dia = 10): array
    {
        $request = new Request('POST', '/matriculas', [], ['aluno_id' => $alunoId, 'plano_id' => $planoId, 'dia_vencimento' => $dia]);
        $resposta = (new MatriculaController())->criar($request);

        return [$resposta->status(), $resposta->dados()];
    }

    /** Critério 1 */
    public function testUnidadeMigradaRespondeSemFaturaEPublicaMatriculaCriada(): void
    {
        $aluno = $this->criarAluno();
        $plano = $this->criarPlano(['valor_mensal' => '119.90', 'duracao_meses' => 12]);
        UnidadesMigradas::migrar((int) $aluno->unidade_id);

        [$status, $dados] = $this->matricular((int) $aluno->id, (int) $plano->id, 15);

        $this->assertSame(201, $status);
        $this->assertNull($dados['primeira_fatura']);
        $this->assertSame(['status' => 'em_processamento'], $dados['cobranca']);
        $this->assertSame('ativa', $dados['matricula']['status']);
        $this->assertSame(0, Fatura::where('aluno_id', $aluno->id)->count(), 'Nenhuma fatura local');
        $this->assertSame(0, Notificacao::where('aluno_id', $aluno->id)->count(), 'Nenhum e-mail síncrono');

        $eventos = DB::select("SELECT * FROM outbox WHERE tipo = 'MatriculaCriada'");
        $this->assertCount(1, $eventos);
        $this->assertSame("matricula:{$dados['matricula']['id']}", $eventos[0]['aggregate_id']);
        $evento = json_decode($eventos[0]['dados'], true);
        $this->assertSame('119.90', $evento['valor_mensal']);
        $this->assertSame(15, $evento['dia_vencimento']);
        $this->assertSame((int) $aluno->unidade_id, $evento['unidade_id']);
    }

    /** Critério 6 */
    public function testUnidadeNaoMigradaMantemComportamentoAtual(): void
    {
        $aluno = $this->criarAluno();
        $plano = $this->criarPlano();

        [$status, $dados] = $this->matricular((int) $aluno->id, (int) $plano->id);

        $this->assertSame(201, $status);
        $this->assertNotNull($dados['primeira_fatura']['gateway_ref'] ?? null);
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS total FROM outbox')['total']);
    }

    /** Critério 11: matrícula e evento na mesma transação */
    public function testRollbackDesfazMatriculaEEvento(): void
    {
        $aluno = $this->criarAluno();
        $plano = $this->criarPlano();
        UnidadesMigradas::migrar((int) $aluno->unidade_id);

        try {
            DB::transaction(function () use ($aluno, $plano): void {
                (new MatriculaService())->criar($aluno, $plano, 10);
                throw new RuntimeException('falha depois de gravar');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS total FROM matriculas WHERE aluno_id = ?', [$aluno->id])['total']);
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS total FROM outbox')['total']);
    }

    /** Critério 5 */
    public function testComandosLigamEDesligamAFlagDaUnidade(): void
    {
        $unidade = $this->criarUnidade();
        ob_start();
        $migrar = (new UnidadesMigrar())->executar([(string) $unidade->id]);
        $this->assertTrue(UnidadesMigradas::contem((int) $unidade->id));
        $reverter = (new UnidadesMigrar(reverter: true))->executar([(string) $unidade->id]);
        ob_end_clean();

        $this->assertSame(0, $migrar);
        $this->assertSame(0, $reverter);
        $this->assertFalse(UnidadesMigradas::contem((int) $unidade->id));
    }

    /** Critério 5: unidade inexistente */
    public function testComandoComUnidadeInexistenteSaiComCodigoUm(): void
    {
        $codigo = (new UnidadesMigrar())->executar(['999999']);

        $this->assertSame(1, $codigo);
        $this->assertSame([], UnidadesMigradas::ids());
    }
}
