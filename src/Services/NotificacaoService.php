<?php

declare(strict_types=1);

namespace EstudaFitHub\Services;

use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Models\Notificacao;

/**
 * Simula o provedor externo de e-mail / SMS / push.
 * A latência é configurável por NOTIFICACAO_LATENCIA_MS para tornar visível
 * o custo de chamar isto de forma síncrona dentro das transações.
 */
final class NotificacaoService
{
    public function enviarEmail(Aluno $aluno, string $template, array $payload = []): Notificacao
    {
        return $this->enviar($aluno, 'email', $template, $payload);
    }

    public function enviarSms(Aluno $aluno, string $template, array $payload = []): Notificacao
    {
        return $this->enviar($aluno, 'sms', $template, $payload);
    }

    public function enviarPush(Aluno $aluno, string $template, array $payload = []): Notificacao
    {
        return $this->enviar($aluno, 'push', $template, $payload);
    }

    private function enviar(Aluno $aluno, string $canal, string $template, array $payload): Notificacao
    {
        // Chamada HTTP ao provedor externo (simulada).
        $latencia = (int) (getenv('NOTIFICACAO_LATENCIA_MS') ?: 0);
        if ($latencia > 0) {
            usleep($latencia * 1000);
        }

        return Notificacao::create([
            'aluno_id' => $aluno->id,
            'canal' => $canal,
            'template' => $template,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'enviado_em' => date('Y-m-d H:i:s'),
        ]);
    }
}
