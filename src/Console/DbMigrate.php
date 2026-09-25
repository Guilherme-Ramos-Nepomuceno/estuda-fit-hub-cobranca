<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Support\DB;

/**
 * Recria o schema do zero a partir de database/schema.sql.
 */
final class DbMigrate implements Comando
{
    public function executar(array $args): int
    {
        $arquivo = dirname(__DIR__, 2) . '/database/schema.sql';
        $sql = file_get_contents($arquivo);
        if ($sql === false) {
            fwrite(STDERR, "Não foi possível ler {$arquivo}\n");

            return 1;
        }

        $comandos = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($comandos as $comando) {
            DB::raw($comando);
        }

        echo 'Schema aplicado: ' . count($comandos) . " comandos executados\n";

        return 0;
    }
}
