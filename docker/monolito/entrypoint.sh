#!/bin/sh
# Sobe o monólito: cria o schema apenas se o banco estiver vazio (ADR-002) e inicia o servidor.
set -e

php bin/console db:migrate --se-vazio

# -q: sem a linha de acesso do servidor embutido, que não é JSON; o log por requisição é da aplicação (ADR-003).
exec php -S 0.0.0.0:8080 -q -t public public/index.php
