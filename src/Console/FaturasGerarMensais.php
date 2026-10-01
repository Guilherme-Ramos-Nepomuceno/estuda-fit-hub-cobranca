<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use Closure;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Matricula;
use EstudaFitHub\Services\GatewayPagamento;
use EstudaFitHub\Services\UnidadesMigradas;
use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\Log;
use RuntimeException;
use Throwable;

/**
 * Cron do dia 1º: gera a fatura da competência para toda matrícula ativa.
 * Uso: php bin/console faturas:gerar-mensais [YYYY-MM]
 *
 * Cada item que falha é registrado e o processamento segue (ADR-003). Uma fatura que ficou sem
 * registro no gateway numa execução anterior é registrada na seguinte. Sai com código 1 se
 * houve falhas, para o agendador alertar.
 */
final class FaturasGerarMensais implements Comando
{
    /** @var Closure(Fatura): string */
    private readonly Closure $registrarCobranca;

    /** @param (Closure(Fatura): string)|null $registrarCobranca chamada ao gateway */
    public function __construct(?Closure $registrarCobranca = null)
    {
        $gateway = new GatewayPagamento();
        $this->registrarCobranca = $registrarCobranca ?? static fn (Fatura $f): string => $gateway->registrarCobranca($f);
    }

    public function executar(array $args): int
    {
        $competencia = date('Y-m-01');
        if (isset($args[0])) {
            if (!preg_match('/^\d{4}-\d{2}$/', $args[0])) {
                fwrite(STDERR, "Competência inválida, use YYYY-MM\n");

                return 1;
            }
            $competencia = $args[0] . '-01';
        }

        $inicio = microtime(true);
        $contagem = ['geradas' => 0, 'puladas' => 0, 'falhas' => 0];
        Log::info('cron.inicio', ['cron' => 'faturas:gerar-mensais', 'competencia' => $competencia]);

        // Unidades migradas têm as faturas geradas por Cobrança (ADR-006).
        $migradas = UnidadesMigradas::ids();

        Matricula::where('status', 'ativa')->chunk(1000, function (array $matriculas) use ($competencia, $migradas, &$contagem): void {
            $alunosDeMigradas = $this->alunosDeUnidades(array_map(static fn (Matricula $m): int => (int) $m->aluno_id, $matriculas), $migradas);

            /** @var Matricula $m */
            foreach ($matriculas as $m) {
                if (isset($alunosDeMigradas[(int) $m->aluno_id])) {
                    continue;
                }
                try {
                    $contagem[$this->gerar($m, $competencia)]++;
                } catch (Throwable $e) {
                    $contagem['falhas']++;
                    Log::warning('cron.item_falhou', [
                        'cron' => 'faturas:gerar-mensais', 'competencia' => $competencia,
                        'matricula_id' => (int) $m->id, 'motivo' => $e->getMessage(),
                    ]);
                }
            }
            Log::info('cron.lote', ['cron' => 'faturas:gerar-mensais', 'competencia' => $competencia] + $contagem);
        });

        Log::info('cron.fim', ['cron' => 'faturas:gerar-mensais', 'competencia' => $competencia] + $contagem + [
            'duracao_s' => round(microtime(true) - $inicio, 2),
        ]);
        echo "Competência {$competencia}: {$contagem['geradas']} faturas geradas, {$contagem['puladas']} já existiam, {$contagem['falhas']} falhas\n";

        return $contagem['falhas'] > 0 ? 1 : 0;
    }

    /** @return 'geradas'|'puladas' */
    private function gerar(Matricula $m, string $competencia): string
    {
        /** @var Fatura|null $fatura */
        $fatura = Fatura::where('matricula_id', $m->id)->where('competencia', $competencia)->first();
        if ($fatura !== null && $fatura->gateway_ref !== null) {
            return 'puladas';
        }

        if ($fatura === null) {
            $plano = $m->plano();
            if ($plano === null) {
                throw new RuntimeException("Plano {$m->plano_id} não encontrado");
            }
            $fatura = Fatura::create([
                'matricula_id' => $m->id,
                'aluno_id' => $m->aluno_id,
                'competencia' => $competencia,
                'valor' => $plano->valor_mensal,
                'vencimento' => sprintf('%s-%02d', substr($competencia, 0, 7), (int) $m->dia_vencimento),
                'status' => 'aberta',
            ]);
        }

        // Uma chamada ao gateway por fatura, em série.
        $fatura->update(['gateway_ref' => ($this->registrarCobranca)($fatura)]);

        return 'geradas';
    }

    /**
     * @param int[] $alunoIds
     * @param int[] $unidades
     * @return array<int, true> alunos (do lote) que pertencem às unidades informadas
     */
    private function alunosDeUnidades(array $alunoIds, array $unidades): array
    {
        if ($alunoIds === [] || $unidades === []) {
            return [];
        }
        $linhas = DB::select(
            sprintf(
                'SELECT id FROM alunos WHERE id IN (%s) AND unidade_id IN (%s)',
                implode(', ', array_fill(0, count($alunoIds), '?')),
                implode(', ', array_fill(0, count($unidades), '?')),
            ),
            array_merge($alunoIds, $unidades),
        );

        return array_fill_keys(array_map('intval', array_column($linhas, 'id')), true);
    }
}
