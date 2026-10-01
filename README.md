# Estuda Fit Hub: extração do domínio de Cobrança

Solução do desafio descrito em [DESAFIO.md](DESAFIO.md): Cobrança sai do monólito para um serviço próprio, com o monólito em produção durante toda a transição.

| Entregável | Onde |
|---|---|
| Documento de decisão (2 páginas) | [docs/DECISAO.md](docs/DECISAO.md) |
| Decisões em detalhe (ADRs) | [docs/adr/](docs/adr/) |
| Plano de migração e corte | [docs/PLANO-MIGRACAO.md](docs/PLANO-MIGRACAO.md) |
| Código: serviço de Cobrança | [cobranca/](cobranca/) (PHP 8.5, Laravel 13, Octane, FrankenPHP) |
| Código: alteração no check-in (ponto B) | [src/Controllers/CheckinController.php](src/Controllers/CheckinController.php) e [src/Services/SituacaoFinanceiraLocal.php](src/Services/SituacaoFinanceiraLocal.php) |
| Plano de implementação, etapa por etapa | [docs/prd/](docs/prd/) |
| Benchmark que sustenta a escolha da stack | [docs/benchmark/](docs/benchmark/) |

O monólito continua em PHP 8.3 sem framework e sem `composer install`.

## Como rodar

Requisitos: Docker e Docker Compose v2.

No Windows, o PowerShell não tem `make` nem `sh`. O caminho mais simples é o terminal **Git Bash**, que vem com o Git, rodando os comandos `sh` abaixo. Para usar `make`, instale com `winget install ezwinports.make`; o Makefile já usa o `sh` do Git.

```bash
make up      # ou: sh scripts/subir.sh
make test    # ou: sh scripts/test.sh
```

O `make up` sobe tudo, cria os bancos, aplica as migrations e carrega os dados de exemplo (2 mil alunos) se o banco estiver vazio.

O `make test` roda tudo, para na primeira falha e termina com um resumo por etapa. Leva de 2,5 a 3,5 minutos depois do build:
1. o ambiente e os dados de exemplo (sobe, se ainda não estiver no ar);
2. a suíte do monólito (runner próprio);
3. a suíte de Cobrança (PHPUnit);
4. o fluxo do monólito: aluno, matrícula, check-in, pagamento e relatórios;
5. os fluxos por evento entre os dois sistemas, com os consumidores reais e com Cobrança fora do ar;
6. o rastreamento dos logs por `correlation_id`;
7. o desempenho da catraca: 60 s a 40 req/s com Cobrança parada, exigindo p95 abaixo de 300 ms.

| Serviço | Endereço | Papel |
|---|---|---|
| `monolito` | `http://localhost:8080` | O sistema atual |
| `cobranca` | `http://localhost:8081` | O serviço novo |
| `monolito-consumidor` / `cobranca-consumidor` | — | Cada um lê o feed de eventos do outro |
| `db` | `localhost:3316` | MySQL 8.4 com um banco por sistema (`estuda_fit_hub`, `cobranca` e as cópias `_test`). Usuário e senha são iguais ao nome do banco. O usuário de Cobrança não acessa o banco do monólito |

Outros comandos:

```bash
docker compose exec monolito php bin/console unidades:migrar 5     # canary: faturas novas da unidade 5 passam a ser de Cobrança
docker compose exec monolito php bin/console unidades:reverter 5   # volta, sem deploy
make perf                  # só o teste de desempenho da catraca
make seed-volume           # volume de produção: 250 mil alunos e ~14 milhões de faturas (~2,2 GB)
docker compose up --watch  # desenvolvimento: sincroniza o código dos dois sistemas (o código vai na imagem)
docker compose down -v     # derruba tudo e apaga os dados
```

> Se você já tinha subido uma versão anterior deste repositório, rode `docker compose down -v` uma vez, para que os bancos e usuários de Cobrança sejam criados.

## Decisões por falta de tempo

- **Comando de migração das faturas não implementado.** Ele foi desenhado e medido no benchmark (1 milhão de faturas em 19,7 s, com lotes e 4 processos), e o plano detalha as fases. Mas o comando não existe no serviço.
- **Só a primeira fatura é gerada por Cobrança.** O cron mensal, os lembretes da régua de cobrança e o cancelamento de matrícula (`MatriculaCancelada`) ainda não estão em Cobrança. O bloqueio já é: depois de 10 dias de atraso, Cobrança publica a situação, e o monólito bloqueia o aluno e envia o SMS. O cron mensal do monólito já ignora as unidades migradas, e o padrão de chamadas concorrentes ao gateway foi validado no benchmark.
- **Feed HTTP em vez de fila.** Não precisa de infraestrutura nova e respeita a regra do monólito sem Composer. A troca por fila é isolada nas interfaces `EventRelay` e `EventSource`.
- **Uma única instância MySQL em desenvolvimento**, com bancos e usuários separados. Em produção, cada sistema tem a própria.
- **Sem CI.** O contrato é o `make test`, que sobe tudo e testa tudo.
- **Matrícula sem os dados de pagamento na resposta** numa unidade migrada (`"cobranca": {"status": "em_processamento"}`). A resposta rápida com o Pix ou boleto ficou para uma ADR de experiência do usuário.
- **`php -S` com 4 workers no monólito.** É o servidor original do desafio, e cada worker atende uma requisição por vez. Com 4, uma requisição lenta (um relatório, uma notificação de 150 ms) não segura a catraca.
- **Segredos de desenvolvimento no `docker-compose.yml`:** tokens dos feeds e `APP_KEY`. Em produção, viriam de um gerenciador de segredos.

## O que faria a seguir

1. Implementar o comando de migração do histórico e das faturas vivas, com validação por competência, e ensaiá-lo no `make seed-volume`.
2. Levar para Cobrança o cron mensal (jobs em lote com chamadas concorrentes ao gateway), os lembretes da régua e o `MatriculaCancelada`.
3. Fazer o monólito adotar as faturas de Cobrança, para permitir rollback total de uma unidade.
4. Implementar o modo sombra no check-in: comparar a decisão pela cópia local com a consulta antiga e registrar `checkin.divergencia` antes do primeiro degrau do canary.
5. Criar um modelo de leitura próprio para os relatórios, alimentado por eventos, e remover a cópia de faturas do monólito.
6. Montar a CI rodando o `make test` e transformar os sinais de log da ADR-003 em alertas.
7. Avaliar a troca do feed por fila quando houver mais consumidores.

## Referência

### Logs

Os dois serviços escrevem uma linha JSON por evento ([ADR-003](docs/adr/003-logs-estruturados-com-correlation-id.md)), com `timestamp` (UTC), `level`, `service`, `correlation_id` e `event`. Mande o header `X-Correlation-Id` (ou leia o que volta na resposta) e siga a operação pelos dois lados:

```bash
docker compose logs --no-color | grep '"correlation_id":"<id>"'
```

Erros internos respondem só `{"erro": "Erro interno", "correlation_id": "..."}`. O detalhe fica no log, sem os valores das consultas. CPF, e-mail e telefone saem mascarados.

### Exemplos de requisição

[http/requests.http](http/requests.http) traz as rotas dos dois serviços prontas para disparar à mão (REST Client do VS Code ou cliente HTTP do PhpStorm): os feeds com token, o `X-Correlation-Id` e o fluxo completo de uma unidade migrada.

### Endpoints do monólito

| Método | Rota | Descrição |
|---|---|---|
| GET | `/health` | Estado da API e do banco |
| GET | `/eventos?apos=&max=` | Feed de eventos do monólito (token Bearer) |
| POST | `/alunos` | Cria aluno |
| GET | `/alunos/{id}` | Aluno, matrículas e resumo financeiro |
| GET | `/alunos/{id}/faturas` | Faturas do aluno (inclusive as cópias das faturas de Cobrança) |
| POST | `/matriculas` | Cria matrícula. Numa unidade migrada, a fatura é gerada por Cobrança |
| POST | `/matriculas/{id}/cancelar` | Cancela matrícula e faturas em aberto |
| POST | `/checkins` | Catraca: decide pela situação financeira local, sem chamar Cobrança |
| POST | `/webhooks/pagamento` | Recebe o gateway: registra no outbox e responde `204` |
| GET | `/relatorios/inadimplencia?unidade_id=` | Inadimplentes por unidade |
| GET | `/relatorios/receita?competencia=YYYY-MM` | Receita por unidade e plano |

### Endpoints de Cobrança

| Método | Rota | Descrição |
|---|---|---|
| GET | `/up` | Health check |
| GET | `/eventos?apos=&max=` | Feed de eventos de Cobrança (token Bearer) |

### Crons

| Onde | Comando | Quando |
|---|---|---|
| Monólito | `php bin/console faturas:gerar-mensais [YYYY-MM]` | Dia 1º (ignora unidades migradas) |
| Monólito | `php bin/console faturas:marcar-vencidas` | Diário (ignora as cópias de Cobrança) |
| Monólito | `php bin/console cobranca:regua --limite=50` | Diário |
| Cobrança | `php artisan faturas:marcar-vencidas` | Diário, 00:05 (agendado no `schedule` do Laravel) |

### Estrutura

```
bin/console              comandos de console do monólito
public/index.php         front controller (o ciclo HTTP fica em src/Http/Aplicacao.php)
src/                     monólito: Http, Support, Models, Services, Controllers, Console, Eventos
database/schema.sql      schema inicial do monólito
database/migrations/     migrations incrementais do monólito
database/init/, conf/    bancos e usuários por sistema; configuração espelhada da produção
tests/                   runner próprio do monólito (tests/run.php) e casos de teste
scripts/                 test.sh, smoke, E2E de eventos e logs, teste de carga (carga/)
cobranca/                serviço de Cobrança
docs/                    decisão, ADRs, PRD, plano de migração e benchmark
```

### Latências simuladas

As integrações externas (provedor de notificação e gateway) são simuladas com `usleep`. Ajuste no `docker-compose.yml`:

| Variável | Padrão | Efeito |
|---|---|---|
| `NOTIFICACAO_LATENCIA_MS` | 150 | Cada e-mail, SMS ou push |
| `GATEWAY_LATENCIA_MS` | 2 | Cada registro de cobrança no gateway (monólito e Cobrança) |

Os testes zeram as duas.
