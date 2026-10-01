<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos\Handlers;

use EstudaFitHub\Eventos\Evento;
use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Services\NotificacaoService;
use EstudaFitHub\Support\DB;

/**
 * FaturaGerada (Cobrança → monólito): grava a cópia de leitura da fatura, identificada pelo
 * id de Cobrança em cobranca_id (ADR-006), e envia o e-mail que antes saía dentro da
 * transação da matrícula.
 */
final class AplicarFaturaGerada
{
    public function __invoke(Evento $evento): void
    {
        $f = $evento->dados;
        DB::execute(
            "INSERT INTO faturas (cobranca_id, matricula_id, aluno_id, competencia, valor, vencimento, status, gateway_ref)
             VALUES (?, ?, ?, ?, ?, ?, 'aberta', ?) AS novo
             ON DUPLICATE KEY UPDATE gateway_ref = novo.gateway_ref",
            [$f['fatura_id'], $f['matricula_id'], $f['aluno_id'], $f['competencia'], $f['valor'], $f['vencimento'], $f['gateway_ref']],
        );

        $aluno = Aluno::find((int) $f['aluno_id']);
        if ($aluno !== null) {
            (new NotificacaoService())->enviarEmail($aluno, 'fatura_gerada', ['fatura' => $f]);
        }
    }
}
