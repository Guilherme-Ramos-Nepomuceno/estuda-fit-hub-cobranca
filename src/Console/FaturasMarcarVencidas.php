<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Support\DB;

/**
 * Cron diário: faturas abertas com vencimento no passado viram vencidas.
 */
final class FaturasMarcarVencidas implements Comando
{
    public function executar(array $args): int
    {
        $afetadas = DB::execute(
            "UPDATE faturas SET status = 'vencida' WHERE status = 'aberta' AND vencimento < CURDATE()",
        );

        echo "{$afetadas} faturas marcadas como vencidas\n";

        return 0;
    }
}
