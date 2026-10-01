<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos\Handlers;

use DateTimeImmutable;
use DateTimeZone;
use EstudaFitHub\Eventos\Evento;
use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Services\NotificacaoService;
use EstudaFitHub\Support\DB;

/**
 * FaturaPaga (Cobrança → monólito): atualiza a cópia da fatura, grava a cópia do pagamento
 * (identificada por cobranca_id, para os relatórios) e envia o SMS que antes saía na thread
 * do webhook.
 */
final class AplicarFaturaPaga
{
    public function __invoke(Evento $evento): void
    {
        $p = $evento->dados;
        // Datas dos eventos vêm em UTC; o monólito grava no fuso local (bootstrap.php).
        $pagoEm = (new DateTimeImmutable($p['pago_em'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
        DB::execute("UPDATE faturas SET status = 'paga', pago_em = ? WHERE cobranca_id = ?", [$pagoEm, $p['fatura_id']]);
        DB::execute(
            'INSERT IGNORE INTO pagamentos (cobranca_id, fatura_id, valor, metodo, gateway_payload)
             SELECT ?, id, ?, ?, NULL FROM faturas WHERE cobranca_id = ?',
            [$p['pagamento_id'], $p['valor'], $p['metodo'], $p['fatura_id']],
        );

        $aluno = Aluno::find((int) $p['aluno_id']);
        if ($aluno !== null) {
            (new NotificacaoService())->enviarSms($aluno, 'pagamento_confirmado', ['fatura_id' => $p['fatura_id']]);
        }
    }
}
