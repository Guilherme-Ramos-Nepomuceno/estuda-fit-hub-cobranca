<?php

declare(strict_types=1);

namespace EstudaFitHub\Http;

use EstudaFitHub\Support\HttpException;

final class Router
{
    /** @var array<int, array{0: string, 1: string, 2: array{0: class-string, 1: string}}> */
    private array $rotas = [];

    /** @param array{0: class-string, 1: string} $handler */
    public function add(string $metodo, string $padrao, array $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $padrao) . '$#';
        $this->rotas[] = [strtoupper($metodo), $regex, $handler];
    }

    public function despachar(Request $request): Response
    {
        $caminhoExiste = false;
        foreach ($this->rotas as [$metodo, $regex, $handler]) {
            if (!preg_match($regex, $request->caminho, $m)) {
                continue;
            }
            $caminhoExiste = true;
            if ($metodo !== $request->metodo) {
                continue;
            }
            $parametros = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            [$classe, $acao] = $handler;
            $controller = new $classe();

            return $controller->$acao($request->comParametros($parametros));
        }

        throw new HttpException($caminhoExiste ? 405 : 404, $caminhoExiste ? 'Método não permitido' : 'Rota não encontrada');
    }
}
