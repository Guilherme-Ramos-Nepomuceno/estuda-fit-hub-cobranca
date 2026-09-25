<?php

declare(strict_types=1);

namespace EstudaFitHub\Services;

use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Matricula;
use EstudaFitHub\Models\Plano;
use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\HttpException;

final class MatriculaService
{
    public function criar(Aluno $aluno, Plano $plano, int $diaVencimento): Matricula
    {
        if ($aluno->situacao === 'bloqueado') {
            throw new HttpException(422, 'Aluno bloqueado por inadimplência não pode se matricular');
        }
        if ($diaVencimento < 1 || $diaVencimento > 28) {
            throw new HttpException(422, 'dia_vencimento deve estar entre 1 e 28');
        }
        if (Matricula::where('aluno_id', $aluno->id)->where('status', 'ativa')->exists()) {
            throw new HttpException(422, 'Aluno já possui matrícula ativa');
        }

        return DB::transaction(function () use ($aluno, $plano, $diaVencimento): Matricula {
            $matricula = Matricula::create([
                'aluno_id' => $aluno->id,
                'plano_id' => $plano->id,
                'inicio' => date('Y-m-d'),
                'fim' => date('Y-m-d', strtotime("+{$plano->duracao_meses} months")),
                'status' => 'ativa',
                'dia_vencimento' => $diaVencimento,
            ]);

            // Cobrança acoplada: a primeira fatura nasce aqui, dentro da mesma transação.
            $fatura = Fatura::create([
                'matricula_id' => $matricula->id,
                'aluno_id' => $aluno->id,
                'competencia' => date('Y-m-01'),
                'valor' => $plano->valor_mensal,
                'vencimento' => date('Y-m-d', strtotime('+3 days')),
                'status' => 'aberta',
            ]);

            // Chamada externa (gateway) dentro da transação.
            $referencia = (new GatewayPagamento())->registrarCobranca($fatura);
            $fatura->update(['gateway_ref' => $referencia]);

            if ($aluno->situacao !== 'ativo') {
                $aluno->update(['situacao' => 'ativo']);
            }

            // Notificação síncrona dentro da transação.
            (new NotificacaoService())->enviarEmail($aluno, 'fatura_gerada', ['fatura' => $fatura->toArray()]);

            return $matricula;
        });
    }

    public function cancelar(Matricula $matricula): void
    {
        if ($matricula->status === 'cancelada') {
            throw new HttpException(422, 'Matrícula já cancelada');
        }

        DB::transaction(function () use ($matricula): void {
            $matricula->update(['status' => 'cancelada', 'fim' => date('Y-m-d')]);

            // Cobrança acoplada: cancelamento mexe direto nas faturas.
            Fatura::where('matricula_id', $matricula->id)
                ->whereIn('status', ['aberta', 'vencida'])
                ->update(['status' => 'cancelada']);

            $aluno = $matricula->aluno();
            if ($aluno !== null) {
                $aluno->update(['situacao' => 'inativo']);
                (new NotificacaoService())->enviarEmail($aluno, 'matricula_cancelada', ['matricula_id' => $matricula->id]);
            }
        });
    }
}
