<?php

declare(strict_types=1);

namespace EstudaFitHub\Support;

use InvalidArgumentException;

/**
 * Query builder mínimo. Só o suficiente para o monólito, sem joins:
 * quando o código precisa de JOIN ele escreve SQL na mão (veja os relatórios).
 */
final class Query
{
    private const OPERADORES = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE'];

    /** @var string[] */
    private array $wheres = [];
    private array $params = [];
    /** @var string[] */
    private array $ordens = [];
    private ?int $limite = null;

    public function __construct(
        private readonly string $modelo,
        private readonly string $tabela,
    ) {
    }

    public function where(string $coluna, mixed $operador, mixed $valor = null): self
    {
        if (func_num_args() === 2) {
            $valor = $operador;
            $operador = '=';
        }
        $operador = strtoupper((string) $operador);
        if (!in_array($operador, self::OPERADORES, true)) {
            throw new InvalidArgumentException("Operador inválido: {$operador}");
        }
        $this->wheres[] = "`{$coluna}` {$operador} ?";
        $this->params[] = $valor;

        return $this;
    }

    public function whereNull(string $coluna): self
    {
        $this->wheres[] = "`{$coluna}` IS NULL";

        return $this;
    }

    public function whereIn(string $coluna, array $valores): self
    {
        if ($valores === []) {
            $this->wheres[] = '1 = 0';

            return $this;
        }
        $this->wheres[] = sprintf('`%s` IN (%s)', $coluna, implode(', ', array_fill(0, count($valores), '?')));
        $this->params = array_merge($this->params, array_values($valores));

        return $this;
    }

    public function orderBy(string $coluna, string $direcao = 'ASC'): self
    {
        $direcao = strtoupper($direcao) === 'DESC' ? 'DESC' : 'ASC';
        $this->ordens[] = "`{$coluna}` {$direcao}";

        return $this;
    }

    public function limit(int $quantidade): self
    {
        $this->limite = max(1, $quantidade);

        return $this;
    }

    /** @return Model[] */
    public function get(): array
    {
        $linhas = DB::select($this->montar('*', true), $this->params);

        return array_map(fn (array $linha): Model => new ($this->modelo)($linha), $linhas);
    }

    public function first(): ?Model
    {
        $this->limit(1);
        $itens = $this->get();

        return $itens[0] ?? null;
    }

    public function exists(): bool
    {
        return DB::selectOne($this->montar('1', false) . ' LIMIT 1', $this->params) !== null;
    }

    public function count(): int
    {
        $linha = DB::selectOne($this->montar('COUNT(*) AS total', false), $this->params);

        return (int) ($linha['total'] ?? 0);
    }

    /**
     * Percorre o resultado em lotes ordenados por id (keyset pagination).
     * @param callable(Model[]): void $fn
     */
    public function chunk(int $tamanho, callable $fn): void
    {
        $tamanho = max(1, $tamanho);
        $ultimoId = 0;
        do {
            $wheres = array_merge($this->wheres, ['`id` > ?']);
            $sql = sprintf(
                'SELECT * FROM `%s` WHERE %s ORDER BY `id` ASC LIMIT %d',
                $this->tabela,
                implode(' AND ', $wheres),
                $tamanho,
            );
            $linhas = DB::select($sql, array_merge($this->params, [$ultimoId]));
            if ($linhas === []) {
                break;
            }
            $fn(array_map(fn (array $linha): Model => new ($this->modelo)($linha), $linhas));
            $ultimoId = (int) $linhas[array_key_last($linhas)]['id'];
        } while (count($linhas) === $tamanho);
    }

    public function update(array $dados): int
    {
        if ($dados === []) {
            return 0;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($dados)));
        $sql = sprintf('UPDATE `%s` SET %s', $this->tabela, $sets);
        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        return DB::execute($sql, array_merge(array_values($dados), $this->params));
    }

    public function toSql(): string
    {
        return $this->montar('*', true);
    }

    private function montar(string $select, bool $comOrdemELimite): string
    {
        $sql = sprintf('SELECT %s FROM `%s`', $select, $this->tabela);
        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        if ($comOrdemELimite && $this->ordens !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->ordens);
        }
        if ($comOrdemELimite && $this->limite !== null) {
            $sql .= ' LIMIT ' . $this->limite;
        }

        return $sql;
    }
}
