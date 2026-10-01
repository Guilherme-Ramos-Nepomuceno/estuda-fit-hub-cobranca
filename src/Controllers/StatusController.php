<?php

declare(strict_types=1);

namespace EstudaFitHub\Controllers;

use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;
use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\Log;
use Throwable;

final class StatusController
{
    public function health(Request $request): Response
    {
        try {
            DB::selectOne('SELECT 1 AS ok');
            $banco = 'ok';
        } catch (Throwable $e) {
            // A mensagem do driver pode expor host e usuário: vai só para o log (ADR-003).
            Log::error('health.banco_indisponivel', ['exception' => $e::class, 'mensagem' => $e->getMessage()]);
            $banco = 'indisponivel';
        }

        return Response::json([
            'status' => $banco === 'ok' ? 'ok' : 'degradado',
            'banco' => $banco,
            'php' => PHP_VERSION,
            'hora' => date(DATE_ATOM),
        ], $banco === 'ok' ? 200 : 503);
    }
}
