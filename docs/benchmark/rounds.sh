#!/usr/bin/env bash
# Fora do Git Bash (Windows) não há cygpath: o caminho já serve como está.
command -v cygpath >/dev/null 2>&1 || cygpath() { echo "$2"; }
# 3 rodadas intercaladas de todas as stacks, ordem rotacionada a cada rodada.
B=$(cd "$(dirname "$0")" && pwd)
LENV="--env-file $(cygpath -m "$B/laravel.env")"
PENV="-e DB_DSN=mysql:host=bench-db;dbname=cobranca -e GATEWAY_URL=http://bench-gw:9000/cobrancas"
BENV="-e PROCS=4 -e POOL=12 -e DB_URL=mysql://bench:bench@bench-db:3306/cobranca -e GATEWAY_URL=http://bench-gw:9000/cobrancas"
STACKS=(
  "go|bench-go|-e DSN=bench:bench@tcp(bench-db:3306)/cobranca?interpolateParams=true -e GATEWAY_URL=http://bench-gw:9000/cobrancas|bench-go gerar 2026-10"
  "bun-bunsql|bench-bun|-e ENTRY=bunsql.ts $BENV|-e POOL=50 bench-bun bun run bunsql.ts gerar 2026-10"
  "bun-mysql2|bench-bun|-e ENTRY=mysql2.ts $BENV|-e POOL=50 bench-bun bun run mysql2.ts gerar 2026-10"
  "php-fpm|bench-php-fpm|$PENV|bench-php-fpm php /app/gerar.php 2026-10 lote"
  "php-franken|bench-php-franken|$PENV|"
  "laravel-fpm|bench-laravel-fpm|$LENV|bench-laravel-fpm php artisan cobranca:gerar 2026-10"
  "laravel-octane|bench-laravel-octane|$LENV|"
  "php-fpm-persist|bench-php-fpm|$PENV -e DB_PERSISTENT=1|"
)
n=${#STACKS[@]}
for r in ${ROUNDS:-1 2 3}; do
  for k in $(seq 0 $((n-1))); do
    IFS='|' read -r nome img args cron <<< "${STACKS[$(( (k + (r-1)*2) % n ))]}"
    [[ "$nome" =~ ${ONLY:-.} ]] || continue
    echo "INICIO r$r $nome $(date +%H:%M:%S)"
    "$B/run.sh" "$nome-r$r" "$img" "$args" "$cron" >/dev/null 2>&1
    echo "FIM r$r $nome $(grep -c res "$B/results/$nome-r$r.jsonl") cenarios, falhas: $(grep -o '"falhas":[0-9]*' "$B/results/$nome-r$r.jsonl" | cut -d: -f2 | paste -sd+ | bc 2>/dev/null || grep -o '"falhas":[0-9]*' "$B/results/$nome-r$r.jsonl" | cut -d: -f2 | tr '\n' ' ')"
  done
done
echo "TUDO CONCLUIDO $(date +%H:%M:%S)"
