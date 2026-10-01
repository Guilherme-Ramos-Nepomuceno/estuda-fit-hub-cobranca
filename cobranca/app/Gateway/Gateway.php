<?php

namespace App\Gateway;

use App\Models\Fatura;

interface Gateway
{
    /** Registra a cobrança e devolve a referência que o gateway usará no webhook. */
    public function registrarCobranca(Fatura $fatura): string;
}
