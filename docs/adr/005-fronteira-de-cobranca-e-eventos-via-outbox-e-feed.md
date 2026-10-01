# ADR-005: Fronteira de Cobrança e eventos via outbox e feed HTTP

- **Data**: 2026-10-01
- **Status**: Aceita
- **Decisores**: Guilherme Ramos Nepomuceno
- **Tags**: fronteira, contrato, eventos, consistência

## Contexto e problema

Cobrança sai do monólito, mas os dois continuam dependendo dos dados um do outro. O check-in precisa saber se o aluno está inadimplente. Cobrança precisa saber quando uma matrícula nasce ou é cancelada. Os relatórios cruzam faturas com alunos e planos. O desafio proíbe o serviço de acessar o banco do monólito e exige que o check-in funcione com Cobrança fora do ar.

O risco central é a **escrita dupla**. Se o serviço grava o pagamento no banco e, em seguida, avisa o monólito (por fila ou HTTP), uma queda entre os dois passos deixa os lados divergentes. Num sentido, a fatura fica paga e o aluno continua bloqueado. No outro, o aviso chega e o pagamento não existe.

## Decisão

### Fronteira

| Item | Dono | Decisão |
|---|---|---|
| `faturas`, `pagamentos` | Cobrança | Donos exclusivos. As faturas e os pagamentos criados por Cobrança têm IDs a partir de 2.000.000.000, para não colidir com os IDs das faturas históricas migradas. No monólito, a cópia de leitura tem id próprio e guarda o de Cobrança em `cobranca_id` ([ADR-006](006-migracao-das-faturas-historicas-e-corte-do-cron.md)) |
| `planos.valor_mensal` | Monólito | Cobrança guarda o **valor contratado**, recebido em `MatriculaCriada` |
| `matriculas.dia_vencimento` | Cobrança | É termo de cobrança. A coluna do monólito vira só leitura e sai depois do 100% do canary ([ADR-004](004-rollout-canary-por-unidade.md)) |
| `alunos.situacao` | Dividida | Situação cadastral fica no monólito. O bloqueio por inadimplência é de Cobrança, publicado em `SituacaoFinanceiraAlterada` |

Ficam no monólito: alunos, unidades, catálogo de planos, matrícula, check-in, notificações e relatórios. Os três últimos passam a ser alimentados por eventos.

### Comunicação

- **Mudança de estado entre os lados:** só por evento.
  - Monólito → Cobrança: `MatriculaCriada`, `MatriculaCancelada`.
  - Cobrança → Monólito: `FaturaGerada`, `FaturaPaga`, `FaturaVencida`, `FaturaCancelada`, `SituacaoFinanceiraAlterada`.
- **APIs síncronas, só onde a resposta imediata é necessária:** o webhook do gateway, a lista de faturas da tela do aluno e a administração. **O check-in nunca chama Cobrança**: lê uma cópia local da situação financeira.
- **Envelope de todo evento:** `event_id`, `tipo`, `versao`, `ocorrido_em`, `correlation_id` ([ADR-003](003-logs-estruturados-com-correlation-id.md)), `aggregate_id` e `sequencia`. Os JSON Schemas ficam versionados em `docs/contratos/`.

### Padrões de projeto

1. **Transactional Outbox, para resolver a escrita dupla.** O evento é gravado na tabela `outbox` **na mesma transação** da mudança de negócio. Ou os dois existem, ou nenhum. Entregar o evento é um passo separado, que pode ser repetido sem risco.
2. **Feed HTTP como transporte.** Cada lado expõe `GET /eventos?apos={id}&max=500`, autenticado. Quem consome busca a cada 1–2 s e guarda o último ID processado. Sem infraestrutura nova e sem Composer no monólito, com ordem garantida pelo ID e reprocessamento simples (basta voltar o ID).
3. **Idempotent Consumer (inbox).** Quem consome registra o `event_id` e atualiza seus dados **na mesma transação**. Evento repetido é descartado. Evento com `sequencia` menor que a já aplicada é ignorado.
4. **Ports & Adapters, para trocar o transporte depois.** Os dois lados dependem apenas de duas interfaces: `EventRelay` (publicar) e `EventSource` (consumir). Hoje elas são implementadas por `HttpFeedRelay` e `HttpFeedSource`. Para usar fila, basta criar `QueueRelay` e `QueueSource`. **O outbox, os handlers de evento e as regras de negócio não mudam.**

```
[transação de negócio + INSERT outbox] → EventRelay ──(feed HTTP hoje | fila amanhã)──► EventSource → inbox + handler
```

## Consequências

**Positivas**
- Nenhum cenário de queda deixa banco e evento divergentes.
- Check-in, relatórios e notificações ficam desacoplados da disponibilidade de Cobrança.
- Trocar para fila, se o feed virar gargalo ou surgirem mais consumidores, muda só os adaptadores.
- Reprocessar eventos depois de corrigir um bug é voltar o ID guardado.

**Negativas**
- Consistência eventual: o desbloqueio acontece 1–3 s depois do pagamento, não na mesma requisição.
- `POST /matriculas` deixa de garantir a fatura na resposta (ver ADR de experiência do usuário).
- Quem consome precisa que quem produz esteja no ar para buscar os eventos. Os eventos não se perdem, só atrasam.
- O outbox cresce: é preciso definir retenção depois do consumo.

## Fora de escopo

Tratamento de experiência do usuário (resposta rápida e avisos por e-mail), estratégia do banco de leitura dos relatórios e migração das 14 milhões de faturas, cada um em ADR própria. Os detalhes de implementação ficam no PRD de implementação.
