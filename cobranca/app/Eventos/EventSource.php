<?php

namespace App\Eventos;

/**
 * Port de consumo (ADR-005): de onde vêm os eventos do outro sistema.
 * Hoje é o feed HTTP; com fila, troca-se só o adaptador.
 */
interface EventSource
{
    /** @return Evento[] em ordem crescente de id */
    public function buscar(int $aposId, int $max): array;
}
