<?php

declare(strict_types=1);

namespace EstudaFitHub\Http;

use EstudaFitHub\Controllers\AlunoController;
use EstudaFitHub\Controllers\CheckinController;
use EstudaFitHub\Controllers\EventosController;
use EstudaFitHub\Controllers\MatriculaController;
use EstudaFitHub\Controllers\PagamentoWebhookController;
use EstudaFitHub\Controllers\RelatorioController;
use EstudaFitHub\Controllers\StatusController;
use EstudaFitHub\Support\Correlacao;
use EstudaFitHub\Support\HttpException;
use EstudaFitHub\Support\Log;
use EstudaFitHub\Support\Uuid;
use Throwable;

/**
 * Ciclo de uma requisição HTTP do monólito: correlation_id (ADR-003), roteamento, erros sem
 * vazar detalhes internos e uma linha de log por requisição.
 */
final class Aplicacao
{
    public const HEADER_CORRELACAO = 'X-Correlation-Id';

    private readonly Router $router;

    public function __construct(?Router $router = null)
    {
        $this->router = $router ?? self::rotas();
    }

    public static function rotas(): Router
    {
        $router = new Router();
        $router->add('GET', '/health', [StatusController::class, 'health']);
        $router->add('GET', '/eventos', [EventosController::class, 'listar']);

        $router->add('POST', '/alunos', [AlunoController::class, 'criar']);
        $router->add('GET', '/alunos/{id}', [AlunoController::class, 'mostrar']);
        $router->add('GET', '/alunos/{id}/faturas', [AlunoController::class, 'faturas']);

        $router->add('POST', '/matriculas', [MatriculaController::class, 'criar']);
        $router->add('POST', '/matriculas/{id}/cancelar', [MatriculaController::class, 'cancelar']);

        $router->add('POST', '/checkins', [CheckinController::class, 'registrar']);

        $router->add('POST', '/webhooks/pagamento', [PagamentoWebhookController::class, 'receber']);

        $router->add('GET', '/relatorios/inadimplencia', [RelatorioController::class, 'inadimplencia']);
        $router->add('GET', '/relatorios/receita', [RelatorioController::class, 'receita']);

        return $router;
    }

    /** @param Request|null $request sem request, lê da requisição corrente (php -S) */
    public function tratar(?Request $request = null): Response
    {
        $inicio = microtime(true);
        $correlacao = self::correlacaoValida($request?->header(self::HEADER_CORRELACAO) ?? ($_SERVER['HTTP_X_CORRELATION_ID'] ?? null)) ?? Uuid::v4();
        Correlacao::definir($correlacao);

        $metodo = $request->metodo ?? strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $caminho = $request->caminho ?? (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

        try {
            $response = $this->router->despachar($request ?? Request::daGlobal());
        } catch (HttpException $e) {
            $response = Response::json(['erro' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            // O detalhe vai só para o log; quem chamou recebe o id para o suporte achar a linha.
            Log::error('http.erro', ['exception' => $e::class, 'mensagem' => $e->getMessage(), 'arquivo' => "{$e->getFile()}:{$e->getLine()}"]);
            $response = Response::json(['erro' => 'Erro interno', 'correlation_id' => $correlacao], 500);
        }

        // As consultas do consumidor ao feed acontecem a cada 1-2 s; só erro delas vira log.
        // O que importa do feed fica do lado de quem consome (evento.processado, com lag_ms).
        if ($caminho !== '/eventos' || $response->status() !== 200) {
            Log::info('http.requisicao', [
                'method' => $metodo,
                'path' => $caminho,
                'status' => $response->status(),
                'duracao_ms' => round((microtime(true) - $inicio) * 1000, 1),
            ]);
        }

        return $response->comHeader(self::HEADER_CORRELACAO, $correlacao);
    }

    /** Aceita o id recebido só se tiver formato seguro para log e header. */
    private static function correlacaoValida(?string $id): ?string
    {
        return $id !== null && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $id) === 1 ? $id : null;
    }
}
