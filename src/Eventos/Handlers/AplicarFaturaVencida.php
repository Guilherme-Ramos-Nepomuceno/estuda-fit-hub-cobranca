<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos\Handlers;

use EstudaFitHub\Eventos\Evento;
use EstudaFitHub\Support\DB;

/**
 * FaturaVencida (Cobrança → monólito): a cópia da fatura passa a vencida, para a tela do aluno e
 * os relatórios. A catraca é atualizada pelo SituacaoFinanceiraAlterada que vem junto.
 */
final class AplicarFaturaVencida
{
    public function __invoke(Evento $evento): void
    {
        DB::execute(
            "UPDATE faturas SET status = 'vencida' WHERE cobranca_id = ? AND status = 'aberta'",
            [$evento->dados['fatura_id']],
        );
    }
}
