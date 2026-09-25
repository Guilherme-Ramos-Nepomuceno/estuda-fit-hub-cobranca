<?php

declare(strict_types=1);

namespace EstudaFitHub\Controllers;

use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;
use EstudaFitHub\Support\DB;
use Throwable;

final class StatusController
{
    public function health(Request $request): Response
    {
        try {
            DB::selectOne('SELECT 1 AS ok');
            $banco = 'ok';
        } catch (Throwable $e) {
            $banco = 'indisponivel: ' . $e->getMessage();
        }

        return Response::json([
            'status' => $banco === 'ok' ? 'ok' : 'degradado',
            'banco' => $banco,
            'php' => PHP_VERSION,
            'hora' => date(DATE_ATOM),
        ], $banco === 'ok' ? 200 : 503);
    }
}
