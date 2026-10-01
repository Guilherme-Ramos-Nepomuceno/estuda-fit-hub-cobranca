<?php

namespace App\Cobranca;

use App\Eventos\Outbox;
use App\Models\Fatura;
use Illuminate\Support\Carbon;

/**
 * Publica SituacaoFinanceiraAlterada para um aluno (ADR-005), recalculada a partir das faturas:
 * desde quando há fatura vencida e se o atraso já passou do limite de bloqueio.
 * Quem chama deve estar dentro de DB::transaction.
 */
class SituacaoFinanceira
{
    public static function publicar(int $alunoId): void
    {
        $vencidaDesde = Fatura::where('aluno_id', $alunoId)->where('status', 'vencida')->min('vencimento');
        $limiteBloqueio = Carbon::today(config('cobranca.fuso'))->subDays(config('cobranca.dias_para_bloqueio'))->toDateString();

        Outbox::registrar('SituacaoFinanceiraAlterada', "aluno:{$alunoId}", [
            'aluno_id' => $alunoId,
            'vencida_desde' => $vencidaDesde,
            'bloqueado' => $vencidaDesde !== null && $vencidaDesde < $limiteBloqueio,
        ]);
    }
}
