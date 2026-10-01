# Estuda Fit Hub, o monólito

Sistema fictício de gestão de uma rede de academias, usado como base para o desafio técnico descrito em [DESAFIO.md](DESAFIO.md). Leia o desafio primeiro.

Monólito em PHP 8.3 sem framework (sem `composer install`: tudo o que ele precisa está no repositório), serviço de Cobrança em PHP 8.5 + Laravel 13 com Octane e FrankenPHP (`cobranca/`), MySQL 8.4 e Docker Compose. As decisões estão em [docs/adr/](docs/adr/).

## Subir o ambiente

Requisitos: Docker e Docker Compose v2.

```bash
docker compose up -d --build --wait
docker compose exec monolito php bin/console db:seed
```

A subida já cria os bancos e aplica as migrations. O schema do monólito só é aplicado se o banco estiver vazio, para não apagar dados.

| Serviço | Endereço | Banco (usuário/senha iguais ao nome do banco) |
|---|---|---|
| `monolito` | `http://localhost:8080` | `estuda_fit_hub` |
| `cobranca` | `http://localhost:8081` | `cobranca`. O usuário não tem acesso ao banco do monólito |
| `db` | `localhost:3316` | MySQL 8.4 com os dois bancos e as versões `_test` de cada um |
| `monolito-consumidor` | — | Lê o feed de eventos de Cobrança e aplica no monólito |
| `cobranca-consumidor` | — | Lê o feed de eventos do monólito e aplica em Cobrança |

Monólito e Cobrança conversam por eventos, com outbox e feed HTTP (`GET /eventos`, protegido por token), conforme a [ADR-005](docs/adr/005-fronteira-de-cobranca-e-eventos-via-outbox-e-feed.md). As faturas de uma unidade só passam a ser geradas por Cobrança depois que a unidade é migrada (canary, [ADR-004](docs/adr/004-rollout-canary-por-unidade.md)):

```bash
docker compose exec monolito php bin/console unidades:migrar 5     # faturas novas da unidade 5 passam a ser de Cobrança
docker compose exec monolito php bin/console unidades:reverter 5   # volta para o monólito, sem deploy
```

> Se você já tinha subido uma versão anterior deste repositório, recrie o volume do banco uma vez (`docker compose down -v`), para que os bancos e usuários de Cobrança sejam criados.

Há um `Makefile` com atalhos (`make up`, `make seed`, `make test`, `make smoke`, `make logs`).

Para desenvolver o serviço de Cobrança, use `docker compose up --watch`. Ele sincroniza o código e reinicia o Octane a cada alteração, e reconstrói a imagem quando o `composer.lock` muda.

O `cobranca/Dockerfile` tem dois alvos. O `dev`, usado pelo compose e pelos testes, inclui o Composer e as dependências de desenvolvimento. O `prod` (`docker build --target prod cobranca`) tem só as dependências de produção, `php.ini` de produção, OPcache sem checagem de arquivos, usuário sem privilégios e migrations fora da subida.

## Rodar os testes

```bash
make test        # ou: sh scripts/test.sh
```

Um comando sobe o ambiente, se preciso, e roda em sequência:
- os testes do monólito;
- os testes do serviço de Cobrança (PHPUnit, banco `cobranca_test`);
- o fluxo ponta a ponta do monólito;
- os fluxos por evento entre os dois sistemas, com os consumidores reais, inclusive com Cobrança fora do ar (`scripts/e2e-eventos.sh`).

Para na primeira falha. Para rodar só os do monólito: `docker compose exec monolito php tests/run.php [Filtro]`. Eles usam o banco `estuda_fit_hub_test` e recriam o schema a cada execução.

## Fluxo ponta a ponta

```bash
docker compose exec monolito php scripts/smoke.php
```

Cria aluno, matrícula, faz check-in, recebe o webhook do gateway e consulta os relatórios, imprimindo o tempo de cada chamada. Os exemplos de requisição também estão em `http/requests.http`.

## Crons

```bash
docker compose exec monolito php bin/console faturas:gerar-mensais 2026-10
docker compose exec monolito php bin/console faturas:marcar-vencidas
docker compose exec monolito php bin/console cobranca:regua --limite=50
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
scripts/test.sh        roda todas as suítes (make test)
database/init/         bancos e usuários de cada sistema (primeira subida do volume)
database/conf/         configuração do MySQL espelhada da produção
docker/monolito/       entrypoint do monólito
cobranca/              serviço de Cobrança (Laravel 13 + Octane + FrankenPHP)
docs/adr/              decisões de arquitetura
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
