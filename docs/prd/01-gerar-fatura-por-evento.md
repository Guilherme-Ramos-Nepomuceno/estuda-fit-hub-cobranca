# Etapa 1: gerar fatura para matrícula, por evento

**Contexto.** Hoje a primeira fatura nasce dentro da transação da matrícula, com gateway e e-mail síncronos (acoplamento A). Gateway lento deixa a matrícula lenta; gateway fora derruba a matrícula.

**O que muda.** Numa unidade migrada, a matrícula grava só a matrícula e o evento `MatriculaCriada`. Cobrança gera a fatura, registra no gateway e publica `FaturaGerada`; o monólito grava a cópia e envia o e-mail. As demais unidades seguem como hoje. Esta etapa também traz a base de eventos (outbox, feed e inbox) nos dois lados.

## Critérios

### Matrícula numa unidade migrada gera a fatura em Cobrança

1. Unidade migrada, `POST /matriculas` válido → `201` com `"primeira_fatura": null` e `"cobranca": {"status": "em_processamento"}`; matrícula `ativa`; **nenhuma** fatura nova no monólito; 1 `MatriculaCriada` no outbox, com o `valor_mensal` do plano e o `dia_vencimento`.
2. Cobrança consome o `MatriculaCriada` → exatamente 1 fatura, com:
   - `competencia` no 1º dia do mês de `inicio` (data local; o `ocorrido_em` é UTC);
   - `vencimento` = `inicio` + 3 dias;
   - `valor` = `valor_mensal`;
   - `status` `aberta`;
   - `gateway_ref` no formato `gw_{id}_{8 hex}`.
3. Cobrança publica 1 `FaturaGerada` com `fatura_id` ≥ 2000000000 e o `gateway_ref`.
4. O monólito consome o `FaturaGerada` → cópia em `faturas` com `cobranca_id` = `fatura_id` (id local próprio) e o mesmo `gateway_ref`; ela aparece em `GET /alunos/{id}/faturas`; 1 e-mail `fatura_gerada`.
5. `unidades:migrar {id}` liga a flag da unidade e `unidades:reverter {id}` desliga, valendo já para a próxima matrícula, sem deploy. Unidade inexistente → código de saída `1`.
6. Unidade **não** migrada → comportamento atual (`201` com `primeira_fatura.gateway_ref`), e nenhum evento no outbox.

### O feed entrega cada evento uma vez, na ordem

7. `GET /eventos?apos=0&max=500` com token válido → `200`, no máximo 500 eventos, em ordem crescente de `id`.
8. Sem token ou com token errado → `401 {"erro": "Não autorizado"}`, nenhum evento.
9. O mesmo evento processado duas vezes → efeito único: 1 fatura, 1 cópia e 1 e-mail.
10. Evento com `sequencia` menor ou igual à já aplicada para o mesmo `aggregate_id` → marcado como processado, sem alterar dados.

### Falhas não perdem nem duplicam

11. Matrícula e `MatriculaCriada` na mesma transação: se ela é desfeita, nenhum dos dois existe.
12. Com Cobrança parada, a matrícula responde `201`. Quando Cobrança volta, a fatura é gerada sem intervenção.
13. Gateway falha → a fatura fica sem `gateway_ref` e nada é publicado. A próxima tentativa registra e publica uma vez, sem criar outra fatura.

## Fora do escopo

- Cancelamento de matrícula em Cobrança (`MatriculaCancelada`, acoplamento G) e cron mensal (acoplamento D): ficam no plano. Nesta etapa, o cron do monólito só passa a ignorar as unidades migradas.
- Dados de pagamento na resposta da matrícula: dependem da ADR de experiência do usuário.
- Fila de mensagens e limpeza do outbox.

## Impacto no código existente

- `MatriculaService::criar` e `MatriculaController` ganham o caminho da unidade migrada.
- `FaturasGerarMensais` (monólito) ignora as unidades migradas.
- `estuda_fit_hub.faturas` passa a guardar também as cópias das faturas de Cobrança.
- O monólito ganha migrations incrementais (`database/migrations/`). O compose ganha os dois consumidores.

## Decisões difíceis de reverter

| Decisão | Formato | Alternativa descartada |
|---|---|---|
| Outbox (dois lados) | `outbox`: `id`, `event_id` único, `tipo`, `versao`, `aggregate_id`, `sequencia`, `correlation_id`, `dados` JSON, `ocorrido_em` | Publicar direto no feed ou na fila: escrita dupla |
| Envelope | `id` (posição no feed), `event_id` (UUID), `tipo`, `versao`, `ocorrido_em`, `correlation_id`, `aggregate_id`, `sequencia`, `dados` | Sem `sequencia`, não dá para descartar evento fora de ordem |
| Feed | `GET /eventos?apos=&max=` (até 500), `Authorization: Bearer`, um token por lado | Feed aberto expõe dados financeiros |
| Inbox | `eventos_processados`, `consumidor_posicao`, `agregados_sequencia`. No monólito, tudo numa transação com o handler. Em Cobrança, o handler é idempotente e o evento é marcado depois dele, porque o gateway não pode ficar dentro de transação | Guardar só a posição: reprocessar duplicaria efeitos |
| `MatriculaCriada` v1 | `matricula_id`, `aluno_id`, `unidade_id`, `plano_id`, `valor_mensal` (texto decimal), `dia_vencimento`, `inicio`, `fim` | Valor como número: float perde centavos |
| `FaturaGerada` v1 | `fatura_id`, `matricula_id`, `aluno_id`, `competencia`, `valor`, `vencimento`, `gateway_ref` | Publicar antes do gateway: a cópia ficaria sem a referência que o webhook usa |
| IDs de Cobrança | `AUTO_INCREMENT = 2000000000` em `faturas` e `pagamentos` | UUID: o histórico migrado mantém ids `INT` |
| Cópia no monólito | `faturas.cobranca_id` e `pagamentos.cobranca_id`, únicos; id local | Id de Cobrança como id da cópia: avança o `AUTO_INCREMENT` do monólito e causa colisão (achado por teste) |
| Flag da unidade | tabela `cobranca_unidades_migradas` | Coluna em `unidades`: mistura cadastro com rollout |
| Migrations do monólito | `database/migrations/NNN_nome.sql` + `schema_migrations` | Editar o `schema.sql`, que só vale para banco vazio |

**Fontes:** [ADR-004](../adr/004-rollout-canary-por-unidade.md), [ADR-005](../adr/005-fronteira-de-cobranca-e-eventos-via-outbox-e-feed.md), [ADR-006](../adr/006-migracao-das-faturas-historicas-e-corte-do-cron.md), [DESAFIO.md](../../DESAFIO.md) (caso de uso obrigatório).
