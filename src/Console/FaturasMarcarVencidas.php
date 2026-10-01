<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Services\SituacaoFinanceiraLocal;
use EstudaFitHub\Support\DB;

/**
 * Cron diário: faturas abertas com vencimento no passado viram vencidas, e a situação
 * financeira dos alunos afetados é recalculada na mesma transação.
 * Só as faturas criadas pelo monólito: as cópias de Cobrança (cobranca_id) mudam por evento.
 */
final class FaturasMarcarVencidas implements Comando
{
    public function executar(array $args): int
    {
        $afetadas = DB::transaction(function (): int {
            $alunos = array_column(DB::select(
                "SELECT DISTINCT aluno_id FROM faturas
                  WHERE status = 'aberta' AND vencimento < CURDATE() AND cobranca_id IS NULL
                  FOR UPDATE",
            ), 'aluno_id');

            $afetadas = DB::execute(
                "UPDATE faturas SET status = 'vencida'
                  WHERE status = 'aberta' AND vencimento < CURDATE() AND cobranca_id IS NULL",
            );

            foreach ($alunos as $alunoId) {
                SituacaoFinanceiraLocal::recalcular((int) $alunoId);
            }

            return $afetadas;
        });

        echo "{$afetadas} faturas marcadas como vencidas\n";

        return 0;
    }
}
