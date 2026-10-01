#!/bin/sh
# Sobe o monólito: cria o schema apenas se o banco estiver vazio (ADR-002) e inicia o servidor.
set -e

php bin/console db:migrate --se-vazio

exec php -S 0.0.0.0:8080 -t public public/index.php
