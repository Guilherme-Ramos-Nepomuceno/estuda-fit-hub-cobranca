<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use EstudaFitHub\Controllers\AlunoController;
use EstudaFitHub\Controllers\CheckinController;
use EstudaFitHub\Controllers\MatriculaController;
use EstudaFitHub\Controllers\PagamentoWebhookController;
use EstudaFitHub\Controllers\RelatorioController;
use EstudaFitHub\Controllers\StatusController;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;
use EstudaFitHub\Http\Router;
use EstudaFitHub\Support\HttpException;

$router = new Router();

$router->add('GET', '/health', [StatusController::class, 'health']);

$router->add('POST', '/alunos', [AlunoController::class, 'criar']);
$router->add('GET', '/alunos/{id}', [AlunoController::class, 'mostrar']);
$router->add('GET', '/alunos/{id}/faturas', [AlunoController::class, 'faturas']);

$router->add('POST', '/matriculas', [MatriculaController::class, 'criar']);
$router->add('POST', '/matriculas/{id}/cancelar', [MatriculaController::class, 'cancelar']);

$router->add('POST', '/checkins', [CheckinController::class, 'registrar']);

$router->add('POST', '/webhooks/pagamento', [PagamentoWebhookController::class, 'receber']);

$router->add('GET', '/relatorios/inadimplencia', [RelatorioController::class, 'inadimplencia']);
$router->add('GET', '/relatorios/receita', [RelatorioController::class, 'receita']);

$inicio = microtime(true);
try {
    $request = Request::daGlobal();
    $response = $router->despachar($request);
} catch (HttpException $e) {
    $response = Response::json(['erro' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log(sprintf('[erro] %s: %s em %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    $response = Response::json(['erro' => 'Erro interno', 'detalhe' => $e->getMessage()], 500);
}

$response->enviar();

error_log(sprintf(
    '[req] %s %s -> %d (%.0f ms)',
    $_SERVER['REQUEST_METHOD'] ?? '-',
    $_SERVER['REQUEST_URI'] ?? '-',
    $response->status(),
    (microtime(true) - $inicio) * 1000,
));
