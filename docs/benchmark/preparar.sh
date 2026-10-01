#!/usr/bin/env bash
# Prepara o ambiente do benchmark da ADR-001: baixa o FrankenPHP, cria o projeto Laravel com o
# código de Cobrança usado no teste, constrói as imagens das 8 variantes e sobe o MySQL com
# 1 milhão de faturas e o gateway simulado (20 ms por chamada).
set -euo pipefail
cd "$(dirname "$0")"
command -v cygpath >/dev/null 2>&1 || cygpath() { echo "$2"; }
W() { cygpath -w "$1"; }
export MSYS_NO_PATHCONV=1

FRANKENPHP=v1.12.7
if [ ! -f php/frankenphp ]; then
    echo "== Baixando FrankenPHP ${FRANKENPHP} (binário estático, ~170 MB)"
    curl -fL -o php/frankenphp "https://github.com/php/frankenphp/releases/download/${FRANKENPHP}/frankenphp-linux-x86_64"
fi

if [ ! -d laravel-build ]; then
    echo "== Criando o projeto Laravel 13 + Octane com o código do benchmark"
    docker run --rm -v "$(cygpath -m "$PWD")":/out -w /out composer:2 sh -c '
        composer create-project -q --no-interaction --prefer-dist laravel/laravel laravel-build "^13.0" &&
        cd laravel-build && composer require -q --no-interaction laravel/octane'
    cp -r laravel/. laravel-build/
    cp php/frankenphp laravel-build/frankenphp
    printf '.git\nnode_modules\ntests\nfrankenphp\n' > laravel-build/Dockerfile.base.dockerignore
    printf '.git\nnode_modules\ntests\nfrankenphp\n' > laravel-build/Dockerfile.fpm.dockerignore
    # Configuração do gateway usada pelo comando cobranca:gerar
    sed -i "s/^];$/    'gateway' => ['url' => env('GATEWAY_URL')],\n];/" laravel-build/config/services.php
fi

echo "== Construindo as imagens"
docker build -q -t bench-go "$(W go)" >/dev/null
docker build -q -t bench-tools "$(W gateway)" >/dev/null
docker build -q -t bench-bun "$(W bun)" >/dev/null
docker build -q -t bench-php-fpm -f "$(W php/Dockerfile.fpm)" "$(W php)" >/dev/null
docker build -q -t bench-php-franken -f "$(W php/Dockerfile.franken)" "$(W php)" >/dev/null
docker build -q -t bench-laravel-base -f "$(W laravel-build/Dockerfile.base)" "$(W laravel-build)" >/dev/null
docker build -q -t bench-laravel-fpm -f "$(W laravel-build/Dockerfile.fpm)" "$(W laravel-build)" >/dev/null
docker build -q -t bench-laravel-octane -f "$(W laravel-build/Dockerfile.octane)" "$(W laravel-build)" >/dev/null

echo "== Subindo MySQL (seed de 1 milhão de faturas) e gateway simulado"
docker network create bench >/dev/null 2>&1 || true
docker rm -f bench-db bench-gw >/dev/null 2>&1 || true
docker run -d --name bench-db --network bench --cpus 4 -e MYSQL_ROOT_PASSWORD=root \
    -v "$(W "$PWD/db")":/docker-entrypoint-initdb.d:ro mysql:8.0 \
    --innodb-buffer-pool-size=1G --max-connections=1000 --default-authentication-plugin=mysql_native_password >/dev/null
docker run -d --name bench-gw --network bench --cpus 2 -e LATENCIA_MS=20 --entrypoint /gateway bench-tools >/dev/null
until docker exec bench-db mysql -uroot -proot -N -e "SELECT COUNT(*) FROM cobranca.faturas" 2>/dev/null | grep -q 1000000; do sleep 5; done
echo "Pronto. Rode: ./rounds.sh && node aggregate.js"
