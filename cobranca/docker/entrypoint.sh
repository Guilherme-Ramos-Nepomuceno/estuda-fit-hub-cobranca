#!/bin/sh
# Sobe o serviço de Cobrança e inicia o Octane.
# Em dev as migrations rodam na subida (ADR-002). Em produção (MIGRAR_NA_SUBIDA=0)
# elas rodam como etapa separada do deploy, para várias réplicas não migrarem ao mesmo tempo.
set -e

if [ "${MIGRAR_NA_SUBIDA:-1}" = 1 ]; then
    php artisan migrate --force --no-interaction
fi

exec php artisan octane:frankenphp \
    --host=0.0.0.0 \
    --port=8081 \
    --workers="${OCTANE_WORKERS:-4}" \
    --max-requests="${OCTANE_MAX_REQUESTS:-1000}"
