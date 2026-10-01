<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos\Handlers;

use EstudaFitHub\Eventos\Evento;
use EstudaFitHub\Models\Aluno;

/**
 * SituacaoFinanceiraAlterada (Cobrança → monólito). O bloqueio por inadimplência é decidido por
 * Cobrança (ADR-005); durante a transição o monólito ainda o reflete em alunos.situacao.
 */
final class AplicarSituacaoFinanceira
{
    public function __invoke(Evento $evento): void
    {
        $aluno = Aluno::find((int) $evento->dados['aluno_id']);
        if ($aluno === null) {
            return;
        }

        if ($evento->dados['bloqueado'] === false && $aluno->situacao === 'bloqueado') {
            $aluno->update(['situacao' => 'ativo']);
        }
    }
}
