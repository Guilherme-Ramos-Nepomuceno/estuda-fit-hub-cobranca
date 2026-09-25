<?php

declare(strict_types=1);

namespace EstudaFitHub\Services;

use EstudaFitHub\Models\Fatura;

/**
 * Simula o gateway de pagamento. Registrar uma cobrança devolve a referência
 * que o gateway usará depois no webhook (POST /webhooks/pagamento).
 */
final class GatewayPagamento
{
    public function registrarCobranca(Fatura $fatura): string
    {
        $latencia = (int) (getenv('GATEWAY_LATENCIA_MS') ?: 0);
        if ($latencia > 0) {
            usleep($latencia * 1000);
        }

        return sprintf('gw_%d_%s', $fatura->id, substr(md5((string) $fatura->id), 0, 8));
    }
}
