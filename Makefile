.PHONY: up down logs migrate seed test smoke faturas vencidas regua shell

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f app

migrate:
	docker compose exec app php bin/console db:migrate

seed:
	docker compose exec app php bin/console db:seed

test:
	docker compose exec app php tests/run.php

smoke:
	docker compose exec app php scripts/smoke.php

faturas:
	docker compose exec app php bin/console faturas:gerar-mensais

vencidas:
	docker compose exec app php bin/console faturas:marcar-vencidas

regua:
	docker compose exec app php bin/console cobranca:regua --limite=50

shell:
	docker compose exec app sh
