<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos\Handlers;

use EstudaFitHub\Eventos\Evento;
use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Services\NotificacaoService;
use EstudaFitHub\Services\SituacaoFinanceiraLocal;

/**
 * SituacaoFinanceiraAlterada (Cobrança → monólito): atualiza a cópia local que a catraca lê.
 * O bloqueio por inadimplência é decidido por Cobrança (ADR-005); durante a transição o
 * monólito ainda o reflete em alunos.situacao, nos dois sentidos, e avisa o aluno por SMS
 * como a régua fazia. Aluno inativo não muda: a situação cadastral é do monólito.
 */
final class AplicarSituacaoFinanceira
{
    public function __invoke(Evento $evento): void
    {
        $aluno = Aluno::find((int) $evento->dados['aluno_id']);
        if ($aluno === null) {
            return;
        }

        SituacaoFinanceiraLocal::aplicar((int) $aluno->id, $evento->dados['vencida_desde'], $evento->sequencia);

        if ($evento->dados['bloqueado'] === true && $aluno->situacao === 'ativo') {
            $aluno->update(['situacao' => 'bloqueado']);
            (new NotificacaoService())->enviarSms($aluno, 'bloqueio_inadimplencia', ['vencida_desde' => $evento->dados['vencida_desde']]);
        } elseif ($evento->dados['bloqueado'] === false && $aluno->situacao === 'bloqueado') {
            $aluno->update(['situacao' => 'ativo']);
        }
    }
}
