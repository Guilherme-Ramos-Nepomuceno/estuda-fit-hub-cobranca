<?php

declare(strict_types=1);

namespace EstudaFitHub\Support;

/**
 * Active Record enxuto. Cada modelo aponta para uma tabela e expõe
 * find / where / create / update, no estilo que o time está acostumado.
 */
abstract class Model
{
    protected static string $tabela = '';

    private array $atributos;

    final public function __construct(array $atributos = [])
    {
        $this->atributos = $atributos;
    }

    public function __get(string $nome): mixed
    {
        return $this->atributos[$nome] ?? null;
    }

    public function __isset(string $nome): bool
    {
        return isset($this->atributos[$nome]);
    }

    public function toArray(): array
    {
        return $this->atributos;
    }

    public static function tabela(): string
    {
        return static::$tabela;
    }

    public static function query(): Query
    {
        return new Query(static::class, static::$tabela);
    }

    public static function where(string $coluna, mixed $operador, mixed $valor = null): Query
    {
        if (func_num_args() === 2) {
            return static::query()->where($coluna, $operador);
        }

        return static::query()->where($coluna, $operador, $valor);
    }

    public static function find(int $id): ?static
    {
        /** @var ?static $modelo */
        $modelo = static::where('id', $id)->first();

        return $modelo;
    }

    public static function create(array $dados): static
    {
        $id = DB::insert(static::$tabela, $dados);
        $modelo = static::find($id);
        if ($modelo === null) {
            throw new \RuntimeException(sprintf('Falha ao carregar %s#%d após insert', static::class, $id));
        }

        return $modelo;
    }

    public function update(array $dados): void
    {
        if ($dados === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($dados)));
        DB::execute(
            sprintf('UPDATE `%s` SET %s WHERE `id` = ?', static::$tabela, $sets),
            array_merge(array_values($dados), [$this->atributos['id']]),
        );
        $this->atributos = array_merge($this->atributos, $dados);
    }

    public function refresh(): static
    {
        $novo = static::find((int) $this->atributos['id']);
        if ($novo !== null) {
            $this->atributos = $novo->toArray();
        }

        return $this;
    }
}
