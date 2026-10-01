<?php

declare(strict_types=1);

namespace EstudaFitHub\Support;

use PDO;
use Throwable;

/**
 * Conexão única com o MySQL. Todo o monólito passa por aqui.
 */
final class DB
{
    private static ?PDO $pdo = null;

    /** Fecha a conexão; a próxima chamada reconecta com as variáveis de ambiente atuais. */
    public static function desconectar(): void
    {
        self::$pdo = null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $porta = getenv('DB_PORT') ?: '3306';
            $nome = getenv('DB_NAME') ?: 'estuda_fit_hub';
            $usuario = getenv('DB_USER') ?: 'estuda_fit_hub';
            $senha = getenv('DB_PASS') ?: 'estuda_fit_hub';

            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $porta, $nome);

            self::$pdo = new PDO($dsn, $usuario, $senha, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$pdo;
    }

    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function selectOne(string $sql, array $params = []): ?array
    {
        $linhas = self::select($sql, $params);

        return $linhas[0] ?? null;
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public static function insert(string $tabela, array $dados): int
    {
        $colunas = array_keys($dados);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $tabela,
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $colunas)),
            implode(', ', array_fill(0, count($colunas), '?')),
        );
        self::execute($sql, array_values($dados));

        return (int) self::pdo()->lastInsertId();
    }

    public static function raw(string $sql): void
    {
        self::pdo()->exec($sql);
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }

        $pdo->beginTransaction();
        try {
            $resultado = $fn();
            $pdo->commit();

            return $resultado;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
