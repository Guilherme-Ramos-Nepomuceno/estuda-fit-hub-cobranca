#!/usr/bin/env bash
# Fora do Git Bash (Windows) não há cygpath: o caminho já serve como está.
command -v cygpath >/dev/null 2>&1 || cygpath() { echo "$2"; }
# Uso: rodar.sh "1 2 4 8" [lote] [manter] — mede tempo, memória e valida a cópia
export MSYS_NO_PATHCONV=1
D=$(cygpath -m "$(cd "$(dirname "$0")" && pwd)")
q() { docker exec bench-db mysql -uroot -proot -N -e "$1" 2>/dev/null; }
for n in $1; do
  [ "${3:-}" = manter ] || q "TRUNCATE migracao.faturas"
  docker rm -f bench-mig >/dev/null 2>&1
  docker run -d --name bench-mig --network bench --cpus 4 -v "$D":/m bench-laravel-base php /m/migrar.php $n ${2:-5000} >/dev/null
  pico=0
  while [ "$(docker inspect -f '{{.State.Running}}' bench-mig 2>/dev/null)" = true ]; do
    m=$(docker stats --no-stream --format '{{.MemUsage}}' bench-mig 2>/dev/null | awk '{v=$1; if (v ~ /GiB/) {sub(/GiB/,"",v); v*=1024} else {sub(/MiB/,"",v)}; print int(v)}')
    [ -n "$m" ] && [ "$m" -gt "$pico" ] && pico=$m
  done
  r=$(docker logs bench-mig 2>&1 | tail -1)
  ok=$(q "SELECT IF((SELECT CONCAT(COUNT(*),'-',SUM(valor),'-',SUM(status='paga')) FROM cobranca.faturas) = (SELECT CONCAT(COUNT(*),'-',SUM(valor),'-',SUM(status='paga')) FROM migracao.faturas), 'ok', 'DIVERGENTE')")
  echo "${r%\}},\"pico_container_mb\":$pico,\"validacao\":\"$ok\"}"
  docker rm -f bench-mig >/dev/null
done
