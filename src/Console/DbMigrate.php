<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Support\DB;

/**
 * Sem argumentos: recria o banco do zero (apaga todas as tabelas, aplica database/schema.sql
 * e todas as migrations de database/migrations).
 * Com --se-vazio: num banco vazio faz o mesmo; num banco existente só aplica as migrations
 * pendentes. O schema.sql começa com DROP TABLE, então nunca roda sobre dados existentes.
 */
final class DbMigrate implements Comando
{
    private const RAIZ = __DIR__ . '/../../database';

    public function executar(array $args): int
    {
        $existente = (int) (DB::selectOne('SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE()')['total'] ?? 0) > 0;

        if (in_array('--se-vazio', $args, true) && $existente) {
            $aplicadas = $this->aplicarMigrations();
            echo $aplicadas === 0 ? "Schema atualizado, nada a aplicar\n" : "{$aplicadas} migration(s) aplicada(s)\n";

            return 0;
        }

        $this->apagarTudo();
        $comandos = $this->executarArquivo(self::RAIZ . '/schema.sql');
        $aplicadas = $this->aplicarMigrations();
        echo "Schema aplicado: {$comandos} comandos executados, {$aplicadas} migration(s)\n";

        return 0;
    }

    private function apagarTudo(): void
    {
        $tabelas = array_column(DB::select('SELECT table_name AS nome FROM information_schema.tables WHERE table_schema = DATABASE()'), 'nome');
        DB::raw('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tabelas as $tabela) {
            DB::raw("DROP TABLE IF EXISTS `{$tabela}`");
        }
        DB::raw('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function aplicarMigrations(): int
    {
        DB::raw('CREATE TABLE IF NOT EXISTS schema_migrations (versao VARCHAR(100) PRIMARY KEY, aplicada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $feitas = array_column(DB::select('SELECT versao FROM schema_migrations'), 'versao');

        $arquivos = glob(self::RAIZ . '/migrations/*.sql') ?: [];
        sort($arquivos);
        $aplicadas = 0;
        foreach ($arquivos as $arquivo) {
            $versao = basename($arquivo, '.sql');
            if (in_array($versao, $feitas, true)) {
                continue;
            }
            $this->executarArquivo($arquivo);
            DB::insert('schema_migrations', ['versao' => $versao]);
            $aplicadas++;
        }

        return $aplicadas;
    }

    private function executarArquivo(string $arquivo): int
    {
        $sql = file_get_contents($arquivo);
        if ($sql === false) {
            throw new \RuntimeException("Não foi possível ler {$arquivo}");
        }
        $comandos = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($comandos as $comando) {
            DB::raw($comando);
        }

        return count($comandos);
    }
}
