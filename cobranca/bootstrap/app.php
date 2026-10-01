<?php

use App\Http\Middleware\CorrelacaoELog;
use App\Support\Correlacao;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$erroDeCliente = fn (Throwable $e) => $e instanceof HttpExceptionInterface
    || $e instanceof ValidationException
    || $e instanceof AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(CorrelacaoELog::class);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($erroDeCliente): void {
        // Serviço só de API: erros sempre em JSON.
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // O detalhe do erro vai só para o log (ADR-003). Com DB_MASK_BINDINGS, nem os valores das consultas.
        $exceptions->report(function (Throwable $e) use ($erroDeCliente) {
            if ($erroDeCliente($e)) {
                return;
            }
            Log::error('http.erro', ['exception' => $e]);

            return false;
        });

        // Quem chamou recebe só o id, para o suporte achar a linha no log.
        $exceptions->render(fn (Throwable $e) => $erroDeCliente($e) ? null : response()->json([
            'erro' => 'Erro interno',
            'correlation_id' => Correlacao::id(),
        ], 500));
    })->create();
