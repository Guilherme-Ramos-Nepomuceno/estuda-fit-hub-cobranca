<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Models\Unidade;
use EstudaFitHub\Services\UnidadesMigradas;

/**
 * Liga ou desliga o canary de uma unidade (ADR-004).
 * Uso: php bin/console unidades:migrar <id>   |   php bin/console unidades:reverter <id>
 */
final class UnidadesMigrar implements Comando
{
    public function __construct(private readonly bool $reverter = false)
    {
    }

    public function executar(array $args): int
    {
        $id = (int) ($args[0] ?? 0);
        $unidade = $id > 0 ? Unidade::find($id) : null;
        if ($unidade === null) {
            fwrite(STDERR, "Unidade inexistente: " . ($args[0] ?? '(vazio)') . "\n");

            return 1;
        }

        if ($this->reverter) {
            UnidadesMigradas::reverter($id);
            echo "Unidade {$id} ({$unidade->nome}) voltou para o monólito\n";
        } else {
            UnidadesMigradas::migrar($id);
            echo "Unidade {$id} ({$unidade->nome}) migrada: faturas novas são geradas por Cobrança\n";
        }

        return 0;
    }
}
