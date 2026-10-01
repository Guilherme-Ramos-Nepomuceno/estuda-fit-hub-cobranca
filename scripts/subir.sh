#!/bin/sh
# Um comando para subir tudo (ADR-002): constrói as imagens, sobe banco, monólito, Cobrança e
# os consumidores, aplica as migrations e carrega os dados de exemplo se o banco estiver vazio.
set -e
cd "$(dirname "$0")/.."

saida=$(mktemp)
trap 'rm -f "$saida"' EXIT

printf '== Subindo banco, monólito, Cobrança e consumidores (na primeira vez, o build leva alguns minutos)\n'
if ! docker compose up -d --build --wait --remove-orphans >"$saida" 2>&1; then
    cat "$saida"
    printf '\nFALHOU: o ambiente não subiu. Veja a saída acima e docker compose logs.\n'
    exit 1
fi

printf '\n== Dados de exemplo\n'
docker compose exec -T monolito php bin/console db:seed --se-vazio

cat <<'FIM'

Ambiente no ar.
  Monólito   http://localhost:8080/health
  Cobrança   http://localhost:8081/up
  MySQL      localhost:3316 (usuário e senha: estuda_fit_hub ou cobranca)
  Exemplos   http/requests.http
FIM

# Chamado pelo test.sh com --testes, a dica do próximo passo sobra.
[ "$1" = "--testes" ] || printf '\nPróximo passo: make test (ou sh scripts/test.sh) roda os testes, as simulações e o ponta a ponta.\n'
