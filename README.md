# Estuda Fit Hub, o monólito

Sistema fictício de gestão de uma rede de academias, usado como base para o desafio técnico descrito em [DESAFIO.md](DESAFIO.md). Leia o desafio primeiro.

PHP 8.3 sem framework, MySQL 8.4, Docker Compose. Sem `composer install`: tudo o que o projeto precisa está no repositório.

## Subir o ambiente

Requisitos: Docker e Docker Compose v2.

```bash
docker compose up -d --build
docker compose exec app php bin/console db:migrate
docker compose exec app php bin/console db:seed
```

A API fica em `http://localhost:8080`. O MySQL fica exposto em `localhost:3316` (usuário, senha e banco: `estuda_fit_hub`). Há um `Makefile` com atalhos para tudo isso (`make up`, `make migrate`, `make seed`, `make test`, `make smoke`).

## Rodar os testes

```bash
docker compose exec app php tests/run.php
```

Os testes usam o banco `estuda_fit_hub_test` e recriam o schema a cada execução. Aceita um filtro por nome: `php tests/run.php Checkin`.

## Fluxo ponta a ponta

```bash
docker compose exec app php scripts/smoke.php
```

Cria aluno, matrícula, faz check-in, recebe o webhook do gateway e consulta os relatórios, imprimindo o tempo de cada chamada. Os exemplos de requisição também estão em `http/requests.http`.

## Crons

```bash
docker compose exec app php bin/console faturas:gerar-mensais 2026-10
docker compose exec app php bin/console faturas:marcar-vencidas
docker compose exec app php bin/console cobranca:regua --limite=50
```

Em produção rodam por agendador: o primeiro no dia 1º, os outros dois diariamente.

## Endpoints

| Método | Rota | Descrição |
|---|---|---|
| GET | `/health` | Estado da API e do banco |
| POST | `/alunos` | Cria aluno |
| GET | `/alunos/{id}` | Aluno, matrículas e resumo financeiro |
| GET | `/alunos/{id}/faturas` | Faturas do aluno |
| POST | `/matriculas` | Cria matrícula e a primeira fatura |
| POST | `/matriculas/{id}/cancelar` | Cancela matrícula e faturas em aberto |
| POST | `/checkins` | Catraca: decide se libera o aluno |
| POST | `/webhooks/pagamento` | Recebe confirmação do gateway |
| GET | `/relatorios/inadimplencia?unidade_id=` | Inadimplentes por unidade |
| GET | `/relatorios/receita?competencia=YYYY-MM` | Receita por unidade e plano |

## Estrutura

```
bin/console            entrada dos comandos de console
public/index.php       front controller e rotas
src/Http               Request, Response, Router
src/Support            DB (PDO), Model (active record), Query, HttpException
src/Models             uma classe por tabela
src/Services           MatriculaService, NotificacaoService, GatewayPagamento
src/Controllers        um por recurso HTTP
src/Console            comandos de cron e de banco
database/schema.sql    schema completo
tests/                 runner próprio (tests/run.php) e casos de teste
scripts/smoke.php      fluxo ponta a ponta
```

## Latências simuladas

As integrações externas (provedor de notificação e gateway) são simuladas com `usleep`. Ajuste no `docker-compose.yml`:

| Variável | Padrão | Efeito |
|---|---|---|
| `NOTIFICACAO_LATENCIA_MS` | 150 | Cada e-mail, SMS ou push |
| `GATEWAY_LATENCIA_MS` | 2 | Cada registro de cobrança no gateway |

Suba os valores para sentir onde o acoplamento dói. Os testes zeram as duas.

## Derrubar

```bash
docker compose down
```

Use `docker compose down -v` para descartar também os dados do MySQL.
