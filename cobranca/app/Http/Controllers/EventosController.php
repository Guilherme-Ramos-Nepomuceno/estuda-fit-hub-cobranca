<?php

namespace App\Http\Controllers;

use App\Eventos\EventRelay;
use App\Eventos\Evento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feed de eventos de Cobrança (ADR-005): GET /eventos?apos={id}&max={n}, com token Bearer.
 */
class EventosController extends Controller
{
    public function __invoke(Request $request, EventRelay $relay): JsonResponse
    {
        $token = (string) config('cobranca.eventos.token');
        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['erro' => 'Não autorizado'], 401);
        }

        $eventos = $relay->pendentes(
            max(0, $request->integer('apos')),
            min(500, max(1, $request->integer('max', 500))),
        );

        return response()->json(['eventos' => array_map(fn (Evento $e) => $e->paraArray(), $eventos)]);
    }
}
