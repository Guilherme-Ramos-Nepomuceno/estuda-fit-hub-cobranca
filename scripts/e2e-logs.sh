#!/bin/sh
# E2E dos logs (PRD etapa 4): uma matrícula é rastreável pelos dois serviços com um único
# correlation_id, o Octane não reaproveita o id de uma requisição na seguinte, e nenhum log
# tem CPF completo.
set -e
cd "$(dirname "$0")/.."

UNIDADE=5
C="e2e-log-$(date +%s)"
mono() { docker compose exec -T "$@"; }
logs() { docker compose logs --no-color --since "$INICIO" "$@" 2>/dev/null; }
falhar() { echo "FALHOU: $1" >&2; exit 1; }

trap 'docker compose exec -T monolito php bin/console unidades:reverter "$UNIDADE" >/dev/null 2>&1 || true' EXIT
INICIO=$(date -u +%Y-%m-%dT%H:%M:%SZ)
mono monolito php bin/console unidades:migrar "$UNIDADE" >/dev/null

printf '\n-- Uma matrícula seguida por um único correlation_id (%s)\n' "$C"
aluno=$(mono -e CORRELATION_ID="$C" monolito php scripts/e2e/matricular.php "$UNIDADE")
mono monolito php scripts/e2e/aguardar-fatura.php "$aluno" 30 aberta >/dev/null 2>&1
sleep 2
logs monolito | grep "\"correlation_id\":\"$C\"" | grep -q '"event":"http.requisicao".*"path":"/matriculas"' || falhar "monólito não registrou a matrícula com $C"
logs cobranca-consumidor | grep "\"correlation_id\":\"$C\"" | grep -q '"event":"evento.processado".*"tipo":"MatriculaCriada"' || falhar "Cobrança não registrou MatriculaCriada com $C"
logs monolito-consumidor | grep "\"correlation_id\":\"$C\"" | grep -q '"event":"evento.processado".*"tipo":"FaturaGerada"' || falhar "monólito não registrou FaturaGerada com $C"
echo "monólito → Cobrança → monólito com o mesmo correlation_id"

printf '\n-- O Octane não reaproveita o id da requisição anterior\n'
A="e2e-octane-$(date +%s)"
for _ in 1 2 3 4 5 6 7 8; do
    mono cobranca wget -q -O /dev/null --header="X-Correlation-Id: $A" http://127.0.0.1:8081/up
    novo=$(mono cobranca wget -q -S -O /dev/null http://127.0.0.1:8081/up 2>&1 | sed -n 's/.*X-Correlation-Id: //Ip' | tr -d '\r')
    [ -n "$novo" ] && [ "$novo" != "$A" ] || falhar "requisição sem header recebeu o id anterior ($novo)"
done
echo "8 pares de requisições nos 4 workers, sem vazamento"

printf '\n-- Nenhum log com CPF completo\n'
cpfs=$(docker compose exec -T db mysql -uestuda_fit_hub -pestuda_fit_hub -N -e "SELECT cpf FROM estuda_fit_hub.alunos ORDER BY id DESC LIMIT 5" 2>/dev/null)
for cpf in $cpfs; do
    logs | grep -q "$cpf" && falhar "CPF $cpf apareceu nos logs"
done
echo "nenhum dos CPFs usados aparece nos logs"

printf '\nE2E de logs passou.\n'
