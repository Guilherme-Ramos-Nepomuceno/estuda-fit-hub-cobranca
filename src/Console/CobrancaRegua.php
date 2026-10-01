<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Services\NotificacaoService;
use EstudaFitHub\Support\DB;

/**
 * Cron diário da régua de inadimplência:
 *  - 1 a 10 dias de atraso: e-mail de lembrete
 *  - mais de 10 dias: bloqueia o aluno e envia SMS
 * Uso: php bin/console cobranca:regua [--limite=N]
 */
final class CobrancaRegua implements Comando
{
    public function executar(array $args): int
    {
        $limite = 0;
        foreach ($args as $arg) {
            if (preg_match('/^--limite=(\d+)$/', $arg, $m)) {
                $limite = (int) $m[1];
            }
        }

        $sql = "SELECT f.id, f.aluno_id, f.valor, f.vencimento, DATEDIFF(CURDATE(), f.vencimento) AS dias_atraso
                  FROM faturas f
                 WHERE f.status = 'vencida' AND f.cobranca_id IS NULL
                 ORDER BY f.vencimento ASC";
        if ($limite > 0) {
            $sql .= ' LIMIT ' . $limite;
        }

        $notificacoes = new NotificacaoService();
        $lembretes = 0;
        $bloqueios = 0;

        foreach (DB::select($sql) as $fatura) {
            $aluno = Aluno::find((int) $fatura['aluno_id']);
            if ($aluno === null || $aluno->situacao === 'inativo') {
                continue;
            }

            if ((int) $fatura['dias_atraso'] > 10) {
                if ($aluno->situacao !== 'bloqueado') {
                    // Cobrança decide a situação cadastral do aluno.
                    $aluno->update(['situacao' => 'bloqueado']);
                    $notificacoes->enviarSms($aluno, 'bloqueio_inadimplencia', ['fatura_id' => $fatura['id']]);
                    $bloqueios++;
                }
                continue;
            }

            $notificacoes->enviarEmail($aluno, 'lembrete_fatura_vencida', [
                'fatura_id' => $fatura['id'],
                'valor' => $fatura['valor'],
                'vencimento' => $fatura['vencimento'],
            ]);
            $lembretes++;
        }

        echo "Régua executada: {$lembretes} lembretes enviados, {$bloqueios} alunos bloqueados\n";

        return 0;
    }
}
