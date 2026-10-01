#!/bin/sh
# Um comando para testar tudo (ADR-002): sobe o ambiente se preciso e roda as suítes do monólito
# e de Cobrança, as simulações e o ponta a ponta. Para na primeira falha e termina com um resumo.
cd "$(dirname "$0")/.."

resumo=""
total=7
n=0
inicio_geral=$(date +%s)

rodar() {
    nome=$1
    shift
    n=$((n + 1))
    printf '\n== %d/%d %s\n' "$n" "$total" "$nome"
    inicio=$(date +%s)
    if "$@"; then
        resumo="${resumo}  ok      $n. $nome ($(($(date +%s) - inicio)) s)
"
    else
        printf '\n== Resumo\n%s  FALHOU  %d. %s\n' "$resumo" "$n" "$nome"
        exit 1
    fi
}

rodar "Ambiente e dados de exemplo" sh scripts/subir.sh --testes
rodar "Testes do monólito (runner próprio)" docker compose exec -T monolito php tests/run.php
rodar "Testes de Cobrança (PHPUnit)" docker compose exec -T cobranca php artisan test
rodar "Fluxo do monólito: aluno, matrícula, check-in, pagamento e relatórios" docker compose exec -T monolito php scripts/smoke.php
rodar "Eventos entre monólito e Cobrança, inclusive com Cobrança fora do ar" sh scripts/e2e-eventos.sh
rodar "Logs: um correlation_id de ponta a ponta, sem vazamento e sem CPF" sh scripts/e2e-logs.sh
rodar "Catraca com Cobrança fora do ar: 40 req/s por 60 s, p95 abaixo de 300 ms" sh scripts/perf-checkin.sh

printf '\n== Resumo\n%s\nTodas as etapas passaram em %d s.\n' "$resumo" "$(($(date +%s) - inicio_geral))"
