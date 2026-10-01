<?php

namespace App\Http\Controllers;

use App\Models\Fatura;
use App\Models\Outbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CobrancaController extends Controller
{
    public function situacao(int $id): JsonResponse
    {
        $inadimplente = Fatura::where('aluno_id', $id)
            ->where('status', 'vencida')
            ->where('vencimento', '<', now()->subDays(5)->toDateString())
            ->exists();

        $abertas = Fatura::where('aluno_id', $id)->whereIn('status', ['aberta', 'vencida'])->count();

        return response()->json(['aluno_id' => $id, 'inadimplente' => $inadimplente, 'faturas_abertas' => $abertas]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'status' => 'required|string',
            'amount' => 'required|numeric',
            'method' => 'required|string',
        ]);
        $ref = (string) $request->query('reference');
        $evt = (string) $request->query('event_id');

        return DB::transaction(function () use ($dados, $ref, $evt) {
            if (DB::table('webhook_eventos')->insertOrIgnore(['event_id' => $evt, 'reference' => $ref]) === 0) {
                return response()->json(['ok' => true, 'duplicado' => true]);
            }

            $fatura = Fatura::where('gateway_ref', $ref)->lockForUpdate()->firstOrFail();

            if ($dados['status'] === 'approved' && $fatura->status !== 'paga') {
                $fatura->update(['status' => 'paga', 'pago_em' => now()]);
                $fatura->pagamentos()->create(['valor' => $dados['amount'], 'metodo' => $dados['method'], 'event_id' => $evt]);
                Outbox::create(['tipo' => 'FaturaPaga', 'payload' => ['fatura_id' => $fatura->id, 'valor' => $dados['amount'], 'event_id' => $evt]]);
            }

            return response()->json(['ok' => true, 'duplicado' => false]);
        });
    }
}
