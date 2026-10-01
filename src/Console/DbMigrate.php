<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Support\DB;

/**
 * Recria o schema do zero a partir de database/schema.sql.
 * Com --se-vazio só aplica quando o banco não tem tabelas: o schema.sql começa com
 * DROP TABLE, então rodá-lo a cada subida do container apagaria os dados.
 */
final class DbMigrate implements Comando
{
    public function executar(array $args): int
    {
        if (in_array('--se-vazio', $args, true)) {
            $tabelas = DB::selectOne('SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE()');
            if ((int) ($tabelas['total'] ?? 0) > 0) {
                echo "Schema já existe, nada a aplicar\n";

                return 0;
            }
        }

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
