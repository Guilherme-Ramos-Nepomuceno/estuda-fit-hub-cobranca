<?php

declare(strict_types=1);

namespace EstudaFitHub\Controllers;

use EstudaFitHub\Eventos\EventRelay;
use EstudaFitHub\Eventos\Evento;
use EstudaFitHub\Eventos\OutboxFeedRelay;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;

/**
 * Feed de eventos do monólito (ADR-005): GET /eventos?apos={id}&max={n}, com token Bearer.
 */
final class EventosController
{
    public function __construct(private readonly EventRelay $relay = new OutboxFeedRelay())
    {
    }

    public function listar(Request $request): Response
    {
        $token = (string) getenv('EVENTOS_TOKEN');
        if ($token === '' || !hash_equals("Bearer {$token}", (string) $request->header('Authorization'))) {
            return Response::json(['erro' => 'Não autorizado'], 401);
        }

        $eventos = $this->relay->pendentes(
            max(0, (int) $request->query('apos', 0)),
            min(500, max(1, (int) $request->query('max', 500))),
        );

        return Response::json(['eventos' => array_map(static fn (Evento $e): array => $e->paraArray(), $eventos)]);
    }
}
