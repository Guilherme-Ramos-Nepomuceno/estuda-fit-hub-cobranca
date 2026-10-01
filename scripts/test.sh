#!/bin/sh
# Um comando para testar tudo (ADR-002): sobe o ambiente se preciso e roda as suítes
# do monólito, do serviço de Cobrança e o fluxo ponta a ponta. Para na primeira falha.
set -e
cd "$(dirname "$0")/.."

etapa() { printf '\n== %s\n' "$1"; }

etapa "Subindo o ambiente"
docker compose up -d --build --wait --remove-orphans

etapa "Monólito (runner próprio)"
docker compose exec -T monolito php tests/run.php

etapa "Cobrança (PHPUnit)"
docker compose exec -T cobranca php artisan test

etapa "Ponta a ponta"
docker compose exec -T monolito php bin/console db:seed --se-vazio
docker compose exec -T monolito php scripts/smoke.php

etapa "Eventos entre monólito e Cobrança (ponta a ponta)"
sh scripts/e2e-eventos.sh

etapa "Catraca com Cobrança fora do ar: p95 < 300 ms a 40 req/s"
sh scripts/perf-checkin.sh

printf '\nTodas as suítes passaram.\n'
