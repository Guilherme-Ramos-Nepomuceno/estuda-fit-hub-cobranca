<?php

namespace App\Console\Commands;

use App\Cobranca\SituacaoFinanceira;
use App\Eventos\Outbox;
use App\Models\Fatura;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cron diário: faturas abertas com vencimento no passado viram vencidas. Publica FaturaVencida
 * por fatura e SituacaoFinanceiraAlterada por aluno afetado, para a catraca do monólito.
 * Rodar de novo no mesmo dia não muda nada nem publica eventos.
 */
class FaturasMarcarVencidas extends Command
{
    protected $signature = 'faturas:marcar-vencidas';

    protected $description = 'Marca como vencidas as faturas abertas com vencimento no passado';

    public function handle(): int
    {
        $hoje = Carbon::today(config('cobranca.fuso'))->toDateString();
        $total = 0;

        Fatura::where('status', 'aberta')->where('vencimento', '<', $hoje)
            ->chunkById(500, function ($faturas) use (&$total): void {
                DB::transaction(function () use ($faturas, &$total): void {
                    foreach ($faturas as $fatura) {
                        $fatura->update(['status' => 'vencida']);
                        Outbox::registrar('FaturaVencida', "fatura:{$fatura->id}", [
                            'fatura_id' => $fatura->id,
                            'aluno_id' => $fatura->aluno_id,
                            'vencimento' => $fatura->vencimento->toDateString(),
                        ]);
                    }
                    foreach ($faturas->pluck('aluno_id')->unique() as $alunoId) {
                        SituacaoFinanceira::publicar($alunoId);
                    }
                    $total += $faturas->count();
                });
            });

        $this->line("{$total} faturas marcadas como vencidas");

        return self::SUCCESS;
    }
}
