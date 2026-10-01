#!/bin/sh
# E2E dos fluxos por evento entre monólito e Cobrança, com os consumidores reais (PRD etapas 2 e 3).
# Migra temporariamente a unidade 5 e a devolve ao monólito no final, mesmo se algo falhar.
set -e
cd "$(dirname "$0")/.."

UNIDADE=5
mono() { docker compose exec -T monolito "$@"; }
parar_cobranca() { docker compose stop cobranca cobranca-consumidor >/dev/null 2>&1; }
subir_cobranca() { docker compose up -d --wait cobranca cobranca-consumidor >/dev/null 2>&1; }

restaurar() {
    subir_cobranca || true
    mono php bin/console unidades:reverter "$UNIDADE" >/dev/null 2>&1 || true
}
trap restaurar EXIT

mono php bin/console unidades:migrar "$UNIDADE" >/dev/null

printf '\n-- Matrícula numa unidade migrada gera a fatura em Cobrança\n'
aluno=$(mono php scripts/e2e/matricular.php "$UNIDADE")
ref=$(mono php scripts/e2e/aguardar-fatura.php "$aluno" 30 aberta)

printf '\n-- Webhook (duplicado) baixa a fatura em Cobrança, e a cópia no monólito fica paga\n'
mono php scripts/e2e/pagar.php "$ref"
mono php scripts/e2e/aguardar-fatura.php "$aluno" 30 paga >/dev/null

printf '\n-- Com Cobrança parada, matrícula e webhook respondem, e tudo é processado quando ela volta\n'
aluno=$(mono php scripts/e2e/matricular.php "$UNIDADE")
ref=$(mono php scripts/e2e/aguardar-fatura.php "$aluno" 30 aberta)
parar_cobranca
mono php scripts/e2e/pagar.php "$ref"
aluno2=$(mono php scripts/e2e/matricular.php "$UNIDADE")
echo "matrícula e webhook aceitos com Cobrança fora do ar" >&2
subir_cobranca
mono php scripts/e2e/aguardar-fatura.php "$aluno" 60 paga >/dev/null
mono php scripts/e2e/aguardar-fatura.php "$aluno2" 60 aberta >/dev/null

pagamentos=$(docker compose exec -T db mysql -ucobranca -pcobranca -N -e "SELECT COUNT(*) - COUNT(DISTINCT fatura_id) FROM cobranca.pagamentos" 2>/dev/null)
[ "$pagamentos" = 0 ] || { echo "FALHOU: há faturas com mais de um pagamento em Cobrança" >&2; exit 1; }

printf '\nE2E de eventos passou.\n'
