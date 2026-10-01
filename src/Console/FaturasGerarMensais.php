<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Matricula;
use EstudaFitHub\Services\GatewayPagamento;
use EstudaFitHub\Services\UnidadesMigradas;
use EstudaFitHub\Support\DB;

/**
 * Cron do dia 1º: gera a fatura da competência para toda matrícula ativa.
 * Uso: php bin/console faturas:gerar-mensais [YYYY-MM]
 */
final class FaturasGerarMensais implements Comando
{
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

        $gateway = new GatewayPagamento();
        $geradas = 0;
        $puladas = 0;

        // Unidades migradas têm as faturas geradas por Cobrança (ADR-006).
        $migradas = UnidadesMigradas::ids();

        Matricula::where('status', 'ativa')->chunk(1000, function (array $matriculas) use ($competencia, $gateway, $migradas, &$geradas, &$puladas): void {
            $alunosDeMigradas = $this->alunosDeUnidades(array_map(static fn (Matricula $m): int => (int) $m->aluno_id, $matriculas), $migradas);

            /** @var Matricula $m */
            foreach ($matriculas as $m) {
                if (isset($alunosDeMigradas[(int) $m->aluno_id])) {
                    continue;
                }
                if (Fatura::where('matricula_id', $m->id)->where('competencia', $competencia)->exists()) {
                    $puladas++;
                    continue;
                }

                $plano = $m->plano();
                if ($plano === null) {
                    continue;
                }

                $fatura = Fatura::create([
                    'matricula_id' => $m->id,
                    'aluno_id' => $m->aluno_id,
                    'competencia' => $competencia,
                    'valor' => $plano->valor_mensal,
                    'vencimento' => sprintf('%s-%02d', substr($competencia, 0, 7), (int) $m->dia_vencimento),
                    'status' => 'aberta',
                ]);

                // Uma chamada ao gateway por fatura, em série.
                $fatura->update(['gateway_ref' => $gateway->registrarCobranca($fatura)]);
                $geradas++;
            }
        });

        echo "Competência {$competencia}: {$geradas} faturas geradas, {$puladas} já existiam\n";

        return 0;
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
