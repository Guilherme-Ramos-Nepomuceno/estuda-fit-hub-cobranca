<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos;

/**
 * Port de publicação (ADR-005): entrega a quem consome os eventos gravados no outbox.
 * Hoje o adaptador expõe o outbox pelo feed HTTP. Para usar fila, basta outro adaptador
 * que leia o outbox e publique na fila; o outbox e quem grava nele não mudam.
 */
interface EventRelay
{
    /** @return Evento[] em ordem crescente de id */
    public function pendentes(int $aposId, int $max): array;
}
