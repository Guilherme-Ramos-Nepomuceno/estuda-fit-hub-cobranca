# ADR-002: Estratégia de testes e ambiente único em Docker Compose

- **Data**: 2026-10-01
- **Status**: Aceita
- **Decisores**: Guilherme Ramos Nepomuceno
- **Tags**: testes, ambiente, docker, migrations

## Contexto e problema

O desafio pede três coisas:
- testes automatizados do serviço de Cobrança (geração de fatura e webhook) e da mudança no check-in (ponto B);
- que os 17 testes atuais do monólito continuem passando, ou sejam ajustados com justificativa;
- que **tudo suba com `docker compose up`**.

Hoje os testes rodam num runner próprio, sem Composer, e são todos de integração. O banco do monólito é criado por um comando separado, cujo `schema.sql` apaga as tabelas antes de recriá-las (`DROP TABLE`). Não há E2E automatizado nem teste das restrições do desafio. Com um segundo serviço e um segundo banco, subir e testar o ambiente passaria a exigir vários passos manuais, o que é ruim para quem avalia e para quem desenvolve.

## Decisão

**Requisito técnico: um comando para subir tudo, um comando para testar tudo.**

### Ambiente

O `docker-compose.yml` passa a ter três serviços de aplicação e dois consumidores de eventos:

| Serviço | Papel | Porta |
|---|---|---|
| `db` | MySQL 8.4 com **bancos e usuários separados**: `estuda_fit_hub` e `cobranca`, mais as versões `_test` de cada um. Cada usuário só tem permissão no próprio banco | 3316 |
| `monolito` | O app atual em PHP | 8080 |
| `cobranca` | O serviço novo em Laravel Octane com FrankenPHP | 8081 |
| `monolito-consumidor` | Processo contínuo que lê o feed de eventos de Cobrança ([ADR-005](005-fronteira-de-cobranca-e-eventos-via-outbox-e-feed.md)) | — |
| `cobranca-consumidor` | Processo contínuo que lê o feed de eventos do monólito | — |

- **Subir:** `docker compose up` sobe tudo na ordem certa, porque os apps esperam o `healthcheck` do banco.
- **Migrations automáticas na subida:**
  - **Cobrança:** roda `php artisan migrate --force`, que é idempotente, antes de iniciar o Octane.
  - **Monólito:** aplica o `schema.sql` **só se o banco estiver vazio**, porque o script é destrutivo. As mudanças de schema seguintes vão em `database/migrations/*.sql`, aplicadas em ordem e registradas numa tabela de controle, para que um banco existente as receba sem ser recriado.
- **Seed:** continua manual (`make seed`), para não sobrescrever os dados de quem já está testando.
- **Espelhamento de produção em tabelas e volumetria:**
  - **Configuração:** o banco local reproduz a produção nas tabelas, índices, versão (MySQL 8.4), charset, `sql_mode`, usuários e permissões. Tudo fica versionado em `database/init/` e nas migrations.
  - **Volume:** `make seed-volume` gera a volumetria de produção (250 mil alunos e 14 milhões de faturas) com dados sintéticos, nunca copiados da produção (LGPD). Toda migration e consulta nova é ensaiada nesse volume antes de ir para produção, medindo o tempo de execução e o lock.
  - **Dia a dia:** `make seed` continua gerando um volume pequeno, para desenvolver rápido.
- **Isolamento:** a separação por usuário impede, já em dev, que o serviço leia o banco do monólito, como o desafio exige. Em produção, cada um tem a própria instância ([ADR-001](001-servico-cobranca-em-php-laravel-octane-frankenphp.md)). Uma única instância local é só uma simplificação de desenvolvimento.

### Testes

- **Subir:** `make up` sobe tudo e carrega os dados de exemplo se o banco estiver vazio.
- **Rodar:** `make test` sobe o ambiente se for preciso e roda, em sequência, as suítes do monólito e do serviço e o E2E, com um resumo por etapa. Termina com código diferente de zero se algo falhar.

| Onde | Ferramenta | Cobre |
|---|---|---|
| Cobrança | **PHPUnit 12** (já vem no Laravel 13), MySQL real, gateway simulado com `Http::fake()` | **Unitário:** estados da fatura, vencimento, valores em centavos. **Integração:** idempotência do webhook (duplicado, fora de ordem, referência inexistente), outbox na mesma transação, geração idempotente. **Contrato:** JSON Schema dos eventos. **Concorrência:** um único teste, webhooks simultâneos para a mesma fatura geram 1 pagamento |
| Monólito | Runner atual (sem Composer) | Os testes acoplados a `faturas` são reescritos ou movidos, com justificativa na PR. Exemplo: `testWebhookDuplicadoRegistraPagamentoDuasVezesComportamentoAtual` passa a exigir 1 pagamento, porque o desafio exige tolerar duplicatas |
| E2E | `scripts/smoke.php` estendido | Matrícula → fatura → webhook → check-in liberado. **Cobrança fora do ar → check-in com p95 abaixo de 300 ms** |

**Condição para cada etapa do rollout:** o `make test` precisa passar sem falhas.

## Consequências

**Positivas**
- O avaliador sobe e testa o projeto com dois comandos, sem passos manuais.
- As restrições do desafio viram verificações executáveis.
- O isolamento por usuário do banco impõe a fronteira de dados desde o desenvolvimento.

**Negativas**
- Sem CI, ninguém garante que o `make test` rodou antes de um merge; depende de disciplina. A CI fica nos próximos passos do README.
- Uma só instância MySQL local não reproduz falhas de rede entre os bancos de produção.
- Como o `schema.sql` é destrutivo, a subida do monólito precisa checar se o banco está vazio antes de aplicá-lo. É um ponto de atenção até ele virar migrations incrementais.
- Dois runners de teste convivem até o monólito poder usar Composer.

## Fora de escopo

CI, teste de carga contínuo e testes de concorrência além do webhook. Os detalhes de implementação ficam no PRD de implementação.
