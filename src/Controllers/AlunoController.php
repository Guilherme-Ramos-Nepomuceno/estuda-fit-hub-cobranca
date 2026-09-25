<?php

declare(strict_types=1);

namespace EstudaFitHub\Controllers;

use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;
use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Matricula;
use EstudaFitHub\Models\Unidade;
use EstudaFitHub\Support\HttpException;

final class AlunoController
{
    public function criar(Request $request): Response
    {
        $request->exigir(['nome', 'email', 'telefone', 'cpf', 'unidade_id']);

        $cpf = preg_replace('/\D/', '', (string) $request->input('cpf')) ?? '';
        if (strlen($cpf) !== 11) {
            throw new HttpException(422, 'CPF deve ter 11 dígitos');
        }
        if (Aluno::where('cpf', $cpf)->exists()) {
            throw new HttpException(422, 'CPF já cadastrado');
        }
        if (Unidade::find((int) $request->input('unidade_id')) === null) {
            throw new HttpException(422, 'Unidade inexistente');
        }

        $aluno = Aluno::create([
            'nome' => (string) $request->input('nome'),
            'email' => (string) $request->input('email'),
            'telefone' => (string) $request->input('telefone'),
            'cpf' => $cpf,
            'unidade_id' => (int) $request->input('unidade_id'),
            'situacao' => 'ativo',
        ]);

        return Response::json($aluno->toArray(), 201);
    }

    public function mostrar(Request $request): Response
    {
        $aluno = Aluno::find((int) $request->param('id'));
        if ($aluno === null) {
            throw new HttpException(404, 'Aluno não encontrado');
        }

        $matriculas = array_map(
            static fn (Matricula $m): array => $m->toArray(),
            Matricula::where('aluno_id', $aluno->id)->orderBy('id', 'DESC')->get(),
        );

        // Acoplamento: a tela do aluno resume a situação financeira lendo faturas direto.
        $resumoFinanceiro = [
            'faturas_abertas' => Fatura::where('aluno_id', $aluno->id)->where('status', 'aberta')->count(),
            'faturas_vencidas' => Fatura::where('aluno_id', $aluno->id)->where('status', 'vencida')->count(),
        ];

        return Response::json([
            'aluno' => $aluno->toArray(),
            'matriculas' => $matriculas,
            'financeiro' => $resumoFinanceiro,
        ]);
    }

    public function faturas(Request $request): Response
    {
        $aluno = Aluno::find((int) $request->param('id'));
        if ($aluno === null) {
            throw new HttpException(404, 'Aluno não encontrado');
        }

        $faturas = array_map(
            static fn (Fatura $f): array => $f->toArray(),
            Fatura::where('aluno_id', $aluno->id)->orderBy('competencia', 'DESC')->get(),
        );

        return Response::json(['aluno_id' => $aluno->id, 'faturas' => $faturas]);
    }
}
