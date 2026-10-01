<?php

namespace App\Gateway;

use App\Models\Fatura;

/**
 * Simula o gateway de pagamento, com o mesmo formato de referência do monólito.
 * Registrar a mesma fatura de novo devolve a mesma referência.
 */
class GatewaySimulado implements Gateway
{
    public function __construct(private readonly int $latenciaMs = 0)
    {
    }

    public function registrarCobranca(Fatura $fatura): string
    {
        if ($this->latenciaMs > 0) {
            usleep($this->latenciaMs * 1000);
        }

        return sprintf('gw_%d_%s', $fatura->id, substr(md5((string) $fatura->id), 0, 8));
    }
}
