<?php

declare(strict_types=1);

namespace EstudaFitHub\Eventos;

/**
 * Port de consumo (ADR-005): de onde vêm os eventos do outro sistema.
 * Hoje é o feed HTTP; com fila, troca-se só o adaptador. O consumidor e os handlers não mudam.
 */
interface EventSource
{
    /** @return Evento[] em ordem crescente de id */
    public function buscar(int $aposId, int $max): array;
}
