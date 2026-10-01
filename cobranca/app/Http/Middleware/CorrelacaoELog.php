<?php

namespace App\Http\Middleware;

use App\Support\Correlacao;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * correlation_id de ponta a ponta (ADR-003): aceita o recebido em X-Correlation-Id, ou gera um,
 * devolve na resposta e registra uma linha por requisição.
 */
class CorrelacaoELog
{
    public const HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $inicio = microtime(true);
        $recebido = (string) $request->header(self::HEADER);
        Correlacao::definir(preg_match('/^[A-Za-z0-9._-]{8,64}$/', $recebido) === 1 ? $recebido : (string) Str::uuid());

        $response = $next($request);
        $response->headers->set(self::HEADER, Correlacao::id());

        // As consultas do consumidor ao feed acontecem a cada 1-2 s; só erro delas vira log.
        // O que importa do feed fica do lado de quem consome (evento.processado, com lag_ms).
        $path = '/'.ltrim($request->path(), '/');
        if ($path !== '/eventos' || $response->getStatusCode() !== 200) {
            Log::info('http.requisicao', [
                'method' => $request->method(),
                'path' => $path,
                'status' => $response->getStatusCode(),
                'duracao_ms' => round((microtime(true) - $inicio) * 1000, 1),
            ]);
        }

        return $response;
    }
}
