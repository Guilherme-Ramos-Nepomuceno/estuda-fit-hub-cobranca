#!/bin/sh
# E2E dos fluxos por evento entre monólito e Cobrança, com os consumidores reais (PRD etapa 2).
# Migra temporariamente a unidade 5 e a devolve ao monólito no final, mesmo se algo falhar.
set -e
cd "$(dirname "$0")/.."

UNIDADE=5
mono() { docker compose exec -T monolito "$@"; }

restaurar() {
    docker compose up -d --wait cobranca cobranca-consumidor >/dev/null 2>&1 || true
    mono php bin/console unidades:reverter "$UNIDADE" >/dev/null 2>&1 || true
}
trap restaurar EXIT

mono php bin/console unidades:migrar "$UNIDADE" >/dev/null

printf '\n-- Matrícula numa unidade migrada gera a fatura em Cobrança\n'
aluno=$(mono php scripts/e2e/matricular.php "$UNIDADE")
mono php scripts/e2e/aguardar-fatura.php "$aluno" 30

printf '\n-- Com Cobrança parada, a matrícula responde e a fatura sai quando ela volta\n'
docker compose stop cobranca cobranca-consumidor >/dev/null 2>&1
aluno=$(mono php scripts/e2e/matricular.php "$UNIDADE")
echo "matrícula aceita com Cobrança fora do ar"
docker compose up -d --wait cobranca cobranca-consumidor >/dev/null 2>&1
mono php scripts/e2e/aguardar-fatura.php "$aluno" 60

printf '\nE2E de eventos passou.\n'
