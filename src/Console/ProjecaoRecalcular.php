<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Services\SituacaoFinanceiraLocal;

/**
 * Recalcula a cópia local da situação financeira de todos os alunos a partir das faturas locais
 * (preenchimento inicial e conferência). Idempotente.
 * Uso: php bin/console projecao:recalcular
 */
final class ProjecaoRecalcular implements Comando
{
    public function executar(array $args): int
    {
        SituacaoFinanceiraLocal::recalcularTodos();
        echo "Situação financeira recalculada para todos os alunos\n";

        return 0;
    }
}
