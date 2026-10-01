.PHONY: up down logs migrate seed test smoke faturas vencidas regua shell shell-cobranca

up:
	docker compose up -d --build --wait --remove-orphans

down:
	docker compose down

logs:
	docker compose logs -f monolito cobranca

migrate:
	docker compose exec monolito php bin/console db:migrate

seed:
	docker compose exec monolito php bin/console db:seed

test:
	sh scripts/test.sh

smoke:
	docker compose exec monolito php scripts/smoke.php

faturas:
	docker compose exec monolito php bin/console faturas:gerar-mensais

vencidas:
	docker compose exec monolito php bin/console faturas:marcar-vencidas

regua:
	docker compose exec monolito php bin/console cobranca:regua --limite=50

shell:
	docker compose exec monolito sh

shell-cobranca:
	docker compose exec cobranca sh
