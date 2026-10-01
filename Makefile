.PHONY: up down logs migrate seed seed-volume test perf smoke faturas vencidas regua shell shell-cobranca

# No Windows, fora do Git Bash não há sh no PATH: usa o que vem com o Git for Windows.
SH := sh
ifeq ($(OS),Windows_NT)
SH := "$(or $(ProgramW6432),$(ProgramFiles))\Git\bin\sh.exe"
endif

up:
	$(SH) scripts/subir.sh

down:
	docker compose down

logs:
	docker compose logs -f monolito cobranca

migrate:
	docker compose exec monolito php bin/console db:migrate

seed:
	docker compose exec monolito php bin/console db:seed

test:
	$(SH) scripts/test.sh

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

perf:
	$(SH) scripts/perf-checkin.sh

seed-volume:
	docker compose exec monolito php -d memory_limit=512M bin/console db:seed-volume
