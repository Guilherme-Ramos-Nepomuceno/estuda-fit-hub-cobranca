<?php

declare(strict_types=1);

namespace EstudaFitHub\Controllers;

use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;
use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Models\Checkin;
use EstudaFitHub\Services\SituacaoFinanceiraLocal;
use EstudaFitHub\Support\HttpException;

/**
 * Chamado pela catraca. Precisa responder em menos de 300 ms no p95.
 */
final class CheckinController
{
    public function registrar(Request $request): Response
    {
        $request->exigir(['cpf', 'unidade_id']);

        $cpf = preg_replace('/\D/', '', (string) $request->input('cpf')) ?? '';
        $aluno = Aluno::where('cpf', $cpf)->first();
        if ($aluno === null) {
            throw new HttpException(404, 'Aluno não encontrado');
        }

        // Ponto B: decide pela cópia local da situação financeira, sem ler faturas nem chamar Cobrança.
        $inadimplente = SituacaoFinanceiraLocal::inadimplente((int) $aluno->id);

        $liberado = $aluno->situacao === 'ativo' && !$inadimplente;

        $motivo = null;
        if (!$liberado) {
            $motivo = $aluno->situacao !== 'ativo' ? 'situacao_' . $aluno->situacao : 'inadimplente';
        }

        $checkin = Checkin::create([
            'aluno_id' => $aluno->id,
            'unidade_id' => (int) $request->input('unidade_id'),
            'ocorrido_em' => date('Y-m-d H:i:s'),
            'liberado' => $liberado ? 1 : 0,
            'motivo_bloqueio' => $motivo,
        ]);

        return Response::json([
            'checkin_id' => $checkin->id,
            'liberado' => $liberado,
            'motivo_bloqueio' => $motivo,
            'aluno' => ['id' => $aluno->id, 'nome' => $aluno->nome],
        ]);
    }
}
