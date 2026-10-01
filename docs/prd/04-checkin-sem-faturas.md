# Etapa 4: check-in sem consultar `faturas` (ponto B)

**Contexto.** Hoje a catraca consulta `faturas` para decidir se libera (acoplamento B). Com as faturas indo para Cobrança, o check-in cairia junto com ela. O desafio exige p95 abaixo de 300 ms **com Cobrança fora do ar**, no pico de 40 por segundo.

**O que muda.** A catraca decide por uma cópia local da situação financeira (`situacao_financeira`), em todas as unidades. A cópia é alimentada pelos eventos de Cobrança e recalculada pelo código legado na mesma transação em que ele muda faturas. Cobrança ganha o próprio "marcar vencidas", para que aluno de unidade migrada também possa ficar inadimplente.

## Critérios

### A catraca decide pela cópia local

1. A catraca não lê `faturas`: com fatura vencida há 30 dias em `faturas` e sem débito na cópia, o aluno é liberado.
2. `vencida_desde` há 6 dias → `"liberado": false`, `"motivo_bloqueio": "inadimplente"`.
3. `vencida_desde` há 5 dias ou menos → liberado (a mesma tolerância de hoje).
4. Aluno sem linha na cópia → liberado.
5. `alunos.situacao = 'bloqueado'` → `"motivo_bloqueio": "situacao_bloqueado"` (como hoje).

### A cópia local fica atualizada

6. `SituacaoFinanceiraAlterada` com `vencida_desde = D` → a cópia passa a `D`; com `null`, o débito é limpo.
7. O código legado que altera faturas criadas pelo monólito (marcar vencidas, webhook, cancelamento, matrícula) recalcula a cópia na mesma transação: menor vencimento entre as vencidas, ou `null`.
8. `projecao:recalcular` recalcula todos os alunos. Rodar duas vezes dá o mesmo resultado.

### Disponível com Cobrança fora do ar

9. Com `cobranca` e `cobranca-consumidor` parados, `POST /checkins` responde `200`.
10. Nessas condições, `make perf` (60 s a 40 req/s, gerador em `scripts/carga/`) reporta p95 abaixo de 300 ms e nenhuma falha.

### Cobrança marca as faturas vencidas

11. `php artisan faturas:marcar-vencidas` → toda fatura `aberta` com vencimento passado vira `vencida`. Publica 1 `FaturaVencida` por fatura e 1 `SituacaoFinanceiraAlterada` por aluno afetado.
12. Rodar de novo no mesmo dia → nada muda e nenhum evento é publicado.
13. O monólito consome `FaturaVencida` → a cópia da fatura fica `vencida`.

## Fora do escopo

- Régua de bloqueio e lembretes para as unidades migradas (acoplamento E).
- Agendador rodando no compose: o comando fica no `schedule` do Laravel, às 00:05, e em dev roda manualmente.
- Modo sombra (`checkin.divergencia`): fica no plano.

## Impacto no código existente

- `CheckinController` deixa de usar `Fatura`.
- `FaturasMarcarVencidas`, `PagamentoWebhookController`, `MatriculaService::criar` e `cancelar` recalculam a cópia.
- Os crons legados e o cancelamento ignoram as cópias de Cobrança (`cobranca_id`), que só mudam por evento.
- A migration preenche a cópia, e o `db:seed` a recalcula. Sem isso, todos seriam liberados.
- O monólito sobe com `PHP_CLI_SERVER_WORKERS=4`. Medido a 40 req/s com Cobrança parada: 1 worker deu p95 de 843 ms; 4 workers, 67 ms.
- Dois testes do `CheckinTest` ajustados com justificativa: a fábrica grava a fatura direto no banco, então o teste recalcula a cópia.

## Decisões difíceis de reverter

| Decisão | Formato | Alternativa descartada |
|---|---|---|
| Cópia da situação financeira | `situacao_financeira (aluno_id PK, vencida_desde DATE NULL, sequencia, atualizado_em)`; o legado grava `sequencia = 0` | `inadimplente BOOL`: a tolerância é relativa ao dia da consulta, e o booleano ficaria errado no dia seguinte |
| `FaturaVencida` v1 | `fatura_id`, `aluno_id`, `vencimento` | Só a situação do aluno: a cópia da fatura ficaria `aberta` para sempre |

**Fontes:** [DESAFIO.md](../../DESAFIO.md) (ponto B obrigatório e p95), [ADR-005](../adr/005-fronteira-de-cobranca-e-eventos-via-outbox-e-feed.md), [ADR-002](../adr/002-estrategia-de-testes-e-ambiente-docker-compose.md).
