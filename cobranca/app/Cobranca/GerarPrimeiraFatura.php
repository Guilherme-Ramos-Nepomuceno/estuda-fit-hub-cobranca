<?php

namespace App\Cobranca;

use App\Eventos\Evento;
use App\Eventos\Outbox;
use App\Gateway\Gateway;
use App\Models\Contrato;
use App\Models\Fatura;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso "gerar fatura para uma matrícula" (MatriculaCriada → FaturaGerada).
 *
 * Idempotente: a fatura é única por matrícula e competência, e só é publicada depois de
 * registrada no gateway. Se o gateway falha, a fatura fica sem referência e a próxima
 * tentativa registra e publica, sem criar outra.
 */
class GerarPrimeiraFatura
{
    public function __construct(private readonly Gateway $gateway)
    {
    }

    public function __invoke(Evento $evento): void
    {
        $m = $evento->dados;
        // A data local da matrícula, e não o ocorrido_em (UTC), define competência e vencimento.
        $inicio = Carbon::parse($m['inicio']);

        $fatura = DB::transaction(function () use ($m, $inicio): Fatura {
            Contrato::updateOrCreate(['matricula_id' => $m['matricula_id']], [
                'aluno_id' => $m['aluno_id'],
                'unidade_id' => $m['unidade_id'],
                'plano_id' => $m['plano_id'],
                'valor_mensal' => $m['valor_mensal'],
                'dia_vencimento' => $m['dia_vencimento'],
                'inicio' => $m['inicio'],
                'fim' => $m['fim'],
            ]);

            return Fatura::firstOrCreate(
                ['matricula_id' => $m['matricula_id'], 'competencia' => $inicio->copy()->startOfMonth()->toDateString()],
                ['aluno_id' => $m['aluno_id'], 'valor' => $m['valor_mensal'], 'vencimento' => $inicio->copy()->addDays(3)->toDateString()],
            );
        });

        if ($fatura->gateway_ref !== null) {
            return;
        }

        // Fora de transação: o gateway pode ser lento ou falhar (ADR-005).
        $referencia = $this->gateway->registrarCobranca($fatura);

        DB::transaction(function () use ($fatura, $referencia): void {
            $fatura->update(['gateway_ref' => $referencia]);
            Outbox::registrar('FaturaGerada', "fatura:{$fatura->id}", [
                'fatura_id' => $fatura->id,
                'matricula_id' => $fatura->matricula_id,
                'aluno_id' => $fatura->aluno_id,
                'competencia' => $fatura->competencia->toDateString(),
                'valor' => (string) $fatura->valor,
                'vencimento' => $fatura->vencimento->toDateString(),
                'gateway_ref' => $referencia,
            ]);
        });
    }
}
