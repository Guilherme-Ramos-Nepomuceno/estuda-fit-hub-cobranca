#!/usr/bin/env bash
# Fora do Git Bash (Windows) não há cygpath: o caminho já serve como está.
command -v cygpath >/dev/null 2>&1 || cygpath() { echo "$2"; }
# Uso: run.sh <nome> <imagem> "<docker run args>" "<[--entrypoint x] imagem args do cron>"
# Mede: /health, /situacao (c=8 e c=64), webhook (c=64), memória, cron de 5000 faturas com gateway a 20 ms.
set -u
NOME=$1; IMG=$2; ARGS=$3; CRON=$4
B=$(dirname "$0"); R="$B/results/${OUT:-}$NOME.jsonl"; : > "$R"
export MSYS_NO_PATHCONV=1
reset() { docker exec -i bench-db mysql -uroot -proot cobranca < "$B/reset.sql" 2>/dev/null; }
lg() { docker run --rm --network bench --cpus 4 --entrypoint /loadgen bench-tools "$@"; }

docker rm -f bench-app >/dev/null 2>&1; reset
t0=$(date +%s%N)
docker run -d --name bench-app --network bench --cpus 4 $ARGS "$IMG" >/dev/null
until docker run --rm --network bench --entrypoint /loadgen bench-tools -url http://bench-app:8080/health -c 1 -d 200ms -warmup 0s 2>/dev/null | grep -q '"ok":[1-9]'; do sleep 0.2; done
echo "{\"cenario\":\"startup_ms\",\"valor\":$(( ($(date +%s%N) - t0) / 1000000 ))}" >> "$R"
sleep 2
echo "{\"cenario\":\"mem_idle\",\"valor\":\"$(docker stats --no-stream --format '{{.MemUsage}}' bench-app | cut -d/ -f1)\"}" >> "$R"

cen() { local c=$1; shift; echo "{\"cenario\":\"$c\",\"res\":$(lg "$@")}" >> "$R"; tail -1 "$R"; }
cen health      -url http://bench-app:8080/health -c 64 -d 10s
cen situacao_c8 -url 'http://bench-app:8080/alunos/{id}/situacao' -c 8 -d 15s
cen situacao_c64 -url 'http://bench-app:8080/alunos/{id}/situacao' -c 64 -d 20s
(sleep 10; docker stats --no-stream --format '{{.MemUsage}} {{.CPUPerc}}' bench-app > "$B/results/.mem") &
cen webhook_c64 -method POST -url 'http://bench-app:8080/webhooks/pagamento?reference=gw_{id}&event_id={evt}' -idmax 1000000 \
    -body '{"status":"approved","amount":89.9,"method":"pix"}' -c 64 -d 20s
wait
echo "{\"cenario\":\"mem_carga\",\"valor\":\"$(cut -d/ -f1 "$B/results/.mem")\",\"cpu\":\"$(awk '{print $NF}' "$B/results/.mem")\"}" >> "$R"
docker exec -i bench-db mysql -uroot -proot -N cobranca -e "SELECT COUNT(*) FROM webhook_eventos; SELECT COUNT(*) FROM pagamentos; SELECT COUNT(*) FROM outbox;" 2>/dev/null | paste -sd' ' | awk '{print "{\"cenario\":\"consistencia\",\"eventos\":"$1",\"pagamentos\":"$2",\"outbox\":"$3"}"}' >> "$R"

if [ -n "$CRON" ]; then
  reset
  docker rm -f bench-app >/dev/null; out=$(docker run --rm --network bench --cpus 4 $ARGS $CRON 2>&1 | tail -1)
  echo "{\"cenario\":\"cron_5000\",\"res\":$out}" >> "$R"; tail -1 "$R"
fi
docker rm -f bench-app >/dev/null
