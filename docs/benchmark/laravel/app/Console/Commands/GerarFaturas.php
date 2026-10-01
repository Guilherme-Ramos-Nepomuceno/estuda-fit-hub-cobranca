<?php

namespace App\Console\Commands;

use App\Models\Contrato;
use App\Models\Fatura;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GerarFaturas extends Command
{
    protected $signature = 'cobranca:gerar {competencia}';

    public function handle(): void
    {
        $inicio = microtime(true);
        $competencia = $this->argument('competencia');
        $geradas = 0;

        Contrato::where('ativo', 1)->chunkById(50, function ($lote) use ($competencia, &$geradas) {
            $novas = DB::transaction(fn () => $lote
                ->map(fn (Contrato $c) => Fatura::createOrFirst(
                    ['matricula_id' => $c->matricula_id, 'competencia' => "{$competencia}-01"],
                    ['aluno_id' => $c->aluno_id, 'valor' => $c->valor_mensal, 'vencimento' => sprintf('%s-%02d', $competencia, $c->dia_vencimento)],
                ))
                ->filter(fn (Fatura $f) => $f->wasRecentlyCreated));

            $respostas = Http::pool(fn (Pool $pool) => $novas
                ->map(fn (Fatura $f) => $pool->as((string) $f->id)->post(config('services.gateway.url'), ['fatura_id' => $f->id, 'valor' => $f->valor]))
                ->all());

            DB::transaction(fn () => $novas->each(fn (Fatura $f) => $f->update(['gateway_ref' => $respostas[(string) $f->id]->json('ref')])));
            $geradas += $novas->count();
        }, 'matricula_id');

        $this->line(json_encode(['geradas' => $geradas, 'segundos' => round(microtime(true) - $inicio, 2)]));
    }
}
