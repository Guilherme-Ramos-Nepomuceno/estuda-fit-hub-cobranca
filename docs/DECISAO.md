# Extração do domínio de Cobrança: documento de decisão

**Resumo.** Cobrança vira um serviço em **PHP 8.5 + Laravel 13 com Octane e FrankenPHP**, com banco próprio, conversa com o monólito por **eventos (outbox + feed HTTP)** e entra **unidade por unidade (canary)**. No benchmark, todas as stacks atenderam a carga; Laravel junta driver maduro, ferramentas prontas e o domínio do time ([ADR-001](adr/001-servico-cobranca-em-php-laravel-octane-frankenphp.md)).

## Fronteira

Cobrança é dona de quanto o aluno deve e se está em dia. O monólito fica com cadastro, matrícula, check-in, notificações e relatórios ([ADR-005](adr/005-fronteira-de-cobranca-e-eventos-via-outbox-e-feed.md)).

| Item | Dono | Por quê |
|---|---|---|
| `faturas`, `pagamentos` | Cobrança | É o núcleo do domínio. O monólito fica com uma cópia de leitura alimentada por eventos, com o id de Cobrança em `cobranca_id`, para relatórios, tela do aluno e rollback |
| `planos.valor_mensal` | Monólito | É o catálogo comercial. Cobrança guarda o **valor contratado**, recebido na matrícula, e uma mudança de preço não altera contratos em andamento |
| `matriculas.dia_vencimento` | Cobrança | É um termo de cobrança. Chega no evento da matrícula; a coluna do monólito fica só de leitura e sai depois do corte |
| `alunos.situacao` | Dividido | A situação cadastral (ativo ou inativo) é do monólito. O bloqueio por inadimplência é de Cobrança, publicado como evento. Durante a transição, o monólito ainda reflete o bloqueio nessa coluna |

## Contrato

- **Entre os sistemas, toda mudança de estado vai por evento.** O evento é gravado no **outbox**, na mesma transação da mudança, o que elimina a escrita dupla entre banco e mensagem. Cada lado expõe `GET /eventos?apos={id}` (com token), e o outro consome guardando a posição.
- **O consumo é idempotente:** por `event_id` e por sequência dentro de cada agregado. Evento repetido ou fora de ordem não tem efeito.
- **Trocar por fila é barato:** as pontas dependem só de `EventRelay` e `EventSource` (*ports & adapters*).

| Direção | Eventos |
|---|---|
| Monólito → Cobrança | `MatriculaCriada` (valor contratado e dia de vencimento), `WebhookPagamentoRecebido` |
| Cobrança → Monólito | `FaturaGerada`, `FaturaPaga`, `FaturaVencida`, `SituacaoFinanceiraAlterada` |

Envelope: `event_id`, `tipo`, `versao`, `ocorrido_em` (UTC), `correlation_id`, `aggregate_id`, `sequencia`, `dados`; dinheiro como texto decimal.

**O check-in nunca chama Cobrança:** decide por uma cópia local da situação financeira, alimentada pelos eventos. O **webhook** é gravado no outbox do monólito (deduplicado pelo `event_id` do gateway) e respondido com `204` na hora, então gateway lento não trava mais os workers.

## Dados

As 14 milhões de faturas terminam em Cobrança, **com os mesmos IDs**. As faturas novas de Cobrança começam em 2.000.000.000, para não colidir com o histórico migrado. Como elas chegam lá ([ADR-006](adr/006-migracao-das-faturas-historicas-e-corte-do-cron.md), [plano](PLANO-MIGRACAO.md)):

- **Histórico** (pagas e canceladas, imutáveis): copiado antes do canary, de uma **réplica** com usuário somente leitura, em lotes de 5 mil com 4 processos e upsert idempotente. Medido: 1 milhão em 19,7 s; 14 milhões em 5 a 15 min.
- **Vivas** (abertas e vencidas): sincronizadas por unidade, no momento em que ela troca de responsável.
- **Validação:** contagem, soma e status por competência, nos dois lados.

Exceções à regra de não acessar o banco do monólito: a réplica (até a última unidade) e os webhooks registrados no monólito (até a troca da URL no gateway).

## Rollout

**Canary por unidade:** 1 academia → 4 (10%) → 40, por uma flag no monólito ([ADR-004](adr/004-rollout-canary-por-unidade.md)).
- **Para avançar:** cada degrau fica no ar pelo menos 24 h e só avança se os **logs estruturados com `correlation_id`** ([ADR-003](adr/003-logs-estruturados-com-correlation-id.md)) estiverem dentro do limite: divergência no check-in, erros do webhook, atraso do outbox e falhas do cron. O `make test` também precisa passar ([ADR-002](adr/002-estrategia-de-testes-e-ambiente-docker-compose.md)).
- **Para voltar atrás:** desligar a flag da unidade, em segundos e sem deploy.
- **Banco:** schema só muda de forma aditiva; voltar nunca exige desfazer migration.
- **Cron:** nenhuma unidade troca de responsável entre o dia 26 e o fim do cron do dia 1º.

**Medido:** com Cobrança parada, a catraca atende 40 req/s com p95 de 25 a 30 ms (limite: 300 ms) e nenhuma falha.

## Riscos e o que não foi resolvido

**Riscos aceitos:**
- **Consistência eventual:** o desbloqueio vem 1 a 3 s depois do pagamento, e a matrícula numa unidade migrada responde sem a fatura, que chega por e-mail.
- **Dois caminhos convivendo** no monólito durante o canary, com as faturas nos dois bancos.
- **Overhead do framework:** o Octane é cerca de 6 vezes mais lento que PHP puro e usa 294 MB, mas atende 25 vezes o pico. O estado pode vazar entre requisições; o `correlation_id` está protegido e testado.
- **Feed HTTP:** com Cobrança fora, os eventos atrasam, não se perdem. Com mais consumidores, vale migrar para fila.
- **Operação:** definir a retenção dos logs. O Laravel 13 perde suporte de segurança em 03/2028, antes do PHP 8.5.

**O que não foi resolvido:**
- **Ainda não implementados:** o comando de migração das faturas (só desenhado e medido), o cron mensal e os lembretes da régua de cobrança em Cobrança, e o cancelamento de matrícula (`MatriculaCancelada`).
- **Rollback depois de faturas geradas por Cobrança:** as faturas que Cobrança criou continuam sendo dela depois do rollback. Um rollback total, com Cobrança desligada, exigiria o monólito adotá-las.
- **Ficam para depois:** o modo sombra no check-in (comparar a decisão nova com a antiga antes do corte), o modelo de leitura dos relatórios, a experiência do usuário (resposta rápida na matrícula) e a CI.
