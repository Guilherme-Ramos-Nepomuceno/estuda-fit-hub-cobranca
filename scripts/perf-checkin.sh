#!/bin/sh
# Desempenho da catraca com Cobrança fora do ar (PRD etapa 3, critérios 9 e 10):
# 60 s a 40 req/s em POST /checkins, exigindo p95 abaixo de 300 ms e nenhuma falha.
set -e
cd "$(dirname "$0")/.."

trap 'docker compose up -d --wait cobranca cobranca-consumidor >/dev/null 2>&1 || true' EXIT

docker compose stop cobranca cobranca-consumidor >/dev/null 2>&1
alunos=$(docker compose exec -T db mysql -uestuda_fit_hub -pestuda_fit_hub -N -e "SELECT COUNT(*) FROM estuda_fit_hub.alunos WHERE cpf LIKE '1000%'" 2>/dev/null)
echo "Cobrança parada; carga em POST /checkins sobre ${alunos} alunos do seed"

docker compose build -q carga
docker compose run --rm carga \
    -method POST -url http://monolito:8080/checkins \
    -body '{"cpf":"{cpf}","unidade_id":1}' -idmin 1 -idmax "${alunos}" -cpfbase 10000000000 \
    -rps 40 -d "${DURACAO:-60s}" -max-p95-ms 300
