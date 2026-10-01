# Etapa 3: webhook de pagamento idempotente

**Contexto.** Hoje o webhook grava em `faturas`, `pagamentos` e `alunos.situacao` e envia SMS na mesma requisição (acoplamento C). Gateway lento trava os workers do monólito, e o check-in falha junto. O webhook também não é idempotente: repetido, gera 2 pagamentos.

**O que muda.** O monólito só grava o webhook no outbox e responde `204`. Cobrança baixa as faturas dela e publica `FaturaPaga` e `SituacaoFinanceiraAlterada`; o monólito aplica a cópia, reativa o aluno e envia o SMS. Nenhum caminho gera segundo pagamento.

## Critérios

### Pagamento de fatura de unidade migrada

1. Webhook `approved` de uma fatura de Cobrança → `204`; 1 `WebhookPagamentoRecebido` no outbox do monólito, com `event_id = "gw:" + event_id do gateway`; nenhuma escrita em `faturas` ou `pagamentos` e nenhum SMS nessa requisição.
2. Cobrança consome → fatura `paga` com `pago_em`; 1 pagamento com valor, método e o `event_id` do gateway; publica `FaturaPaga` e `SituacaoFinanceiraAlterada`.
3. O monólito consome `FaturaPaga` → cópia da fatura `paga`; cópia do pagamento com `cobranca_id`; 1 SMS `pagamento_confirmado`; o valor aparece em `GET /relatorios/receita`.
4. `SituacaoFinanceiraAlterada` com `bloqueado = false` → aluno `bloqueado` volta a `ativo`.

### Duplicado, fora de ordem e simultâneo

5. O mesmo webhook duas vezes → `204` nas duas, 1 evento e 1 pagamento. Vale também para as faturas do monólito: o teste que exigia 2 pagamentos passa a exigir 1.
6. Dois `approved` com `event_id` diferentes para a mesma fatura → 1 pagamento.
7. `refused` depois de `approved` → a fatura continua `paga`.
8. Dois `approved` processados ao mesmo tempo → exatamente 1 pagamento (único teste de concorrência da ADR-002).

### Faturas do monólito, referências desconhecidas e Cobrança fora do ar

9. Fatura **criada pelo monólito** (sem `cobranca_id`) → comportamento atual (paga, pagamento, SMS, reativação), e o evento fica no outbox, onde Cobrança o ignora. Fatura já `paga` não recebe segundo pagamento. A regra é por quem criou a fatura, e não pela unidade, porque as faturas anteriores à migração de uma unidade só vão para Cobrança com a sincronização da ADR-006.
10. Referência desconhecida → `204` (antes `404`). Cobrança marca como processado, sem efeito. Motivo: o monólito não sabe se é uma fatura de Cobrança que ainda não chegou.
11. Com Cobrança parada → `204`. Quando ela volta, a fatura é baixada sem intervenção.
12. `pending` → só o registro no outbox.
13. Sem `event_id` → `204`, gravado com `event_id = "gw-sem-id:" + UUID`, sem deduplicação.

## Fora do escopo

- Régua de inadimplência em Cobrança (acoplamento E).
- Troca da URL do webhook no gateway: acontece no 100% do canary.
- E-mail de pagamento recusado para as unidades migradas: exigiria um evento que nenhuma fonte define.

## Impacto no código existente

- `PagamentoWebhookController::receber` grava primeiro o outbox, com deduplicação, e só depois processa o legado.
- Dois testes ajustados com justificativa: webhook duplicado (2 → 1 pagamento) e referência desconhecida (`404` → `204`).

## Decisões difíceis de reverter

| Decisão | Formato | Alternativa descartada |
|---|---|---|
| Deduplicação na entrada | `event_id` = `"gw:" + event_id do gateway`, recusado pela chave única do outbox | Tabela própria de webhooks: duplica o que o outbox garante |
| `WebhookPagamentoRecebido` v1 | `reference`, `status`, `amount` (texto decimal), `method`, `gateway_event_id`, `recebido_em` | JSON bruto sem schema: prende o consumidor ao formato do gateway |
| `FaturaPaga` v1 | `fatura_id`, `aluno_id`, `pagamento_id`, `valor`, `metodo`, `pago_em` (UTC) | — |
| `SituacaoFinanceiraAlterada` v1 | agregado `aluno:{id}`; `aluno_id`, `vencida_desde` (data ou `null`), `bloqueado` | Só `inadimplente: bool`: a tolerância de 5 dias da catraca exige a data |
| Defesa em profundidade | `pagamentos.event_id` único em Cobrança | Confiar só na entrada: um reprocessamento manual duplicaria |

**Fontes:** [ADR-006](../adr/006-migracao-das-faturas-historicas-e-corte-do-cron.md) (item 6), [ADR-005](../adr/005-fronteira-de-cobranca-e-eventos-via-outbox-e-feed.md), [ADR-002](../adr/002-estrategia-de-testes-e-ambiente-docker-compose.md), [DESAFIO.md](../../DESAFIO.md) ("o webhook pode chegar duplicado ou fora de ordem").
