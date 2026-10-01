<?php

namespace App\Eventos;

/**
 * Port de publicação (ADR-005): entrega a quem consome os eventos gravados no outbox.
 * Hoje o adaptador expõe o outbox pelo feed HTTP; com fila, troca-se só o adaptador.
 */
interface EventRelay
{
    /** @return Evento[] em ordem crescente de id */
    public function pendentes(int $aposId, int $max): array;
}
