# Etapa 4: logs estruturados com correlation_id

**Contexto.** Hoje o monólito registra só duas linhas de texto por requisição, sem nível, sem identificador e sem contexto. A resposta `500` e o `/health` expõem a mensagem de erro do banco. Com Cobrança separada, uma operação cruza dois processos e não há como ligar as pontas.

**O que muda.** Cada requisição e cada evento processado geram uma linha JSON com `correlation_id`, no mesmo formato nos dois serviços. Nenhuma resposta expõe erro de banco. O cron registra cada item que falha e segue com os outros.

## Critérios

### Toda requisição e todo evento deixam uma linha rastreável

1. Toda resposta gera 1 linha JSON com `timestamp` (UTC), `level`, `service`, `correlation_id`, `event = "http.requisicao"`, `method`, `path`, `status` e `duracao_ms`. Exceção: consulta bem-sucedida ao feed `GET /eventos`.
2. Com `X-Correlation-Id: C` → a resposta devolve `C`, e a linha tem `correlation_id = C`.
3. Sem o header → um UUID v4 é gerado, devolvido e registrado.
4. O evento gravado leva o `correlation_id` da requisição. Quem consome registra `evento.processado` com o mesmo id, `event_id`, `tipo` e `lag_ms`.
5. Evento repetido → `evento.ignorado` com `motivo` `duplicado` ou `fora_de_ordem`.
6. Duas requisições seguidas no mesmo worker do Octane (A e depois B) → a segunda tem `correlation_id = B`, sem nada da primeira.

### Nenhum erro de banco sai na resposta

7. Exceção não tratada no monólito → `500` com exatamente `{"erro": "Erro interno", "correlation_id": "<C>"}`; classe e mensagem só no log, como `error`.
8. Banco fora → `GET /health` responde `503` com `"banco": "indisponivel"`, sem a mensagem do driver.
9. Exceção não tratada em Cobrança → o mesmo corpo do critério 7, e o log não contém os valores das consultas (`DB_MASK_BINDINGS=true`).

### Cada item que falha no cron fica registrado

10. Gateway falha para uma matrícula em `faturas:gerar-mensais` → as outras são processadas; `cron.item_falhou` com `matricula_id` e `motivo`; ao final, `cron.fim` com `geradas`, `puladas`, `falhas` e `duracao_s`; código de saída `1` se houve falha. A fatura que ficou sem registro no gateway é registrada na execução seguinte.
11. Nenhum log contém CPF, e-mail ou telefone completos. O CPF aparece como `***.***.***-NN`.

## Fora do escopo

- Log de cada consulta bem-sucedida ao feed: seriam dezenas de milhares de linhas por dia sem valor. O que importa fica com quem consome (`lag_ms`).
- Tracing distribuído e plataforma de métricas. O `correlation_id` pode virar `trace_id` depois.
- Retenção dos logs e o sinal `checkin.divergencia`, que depende do modo sombra.

## Impacto no código existente

- Monólito: o ciclo HTTP sai do `public/index.php` para `Http\Aplicacao`, testável. Ganha um logger mínimo próprio, porque não tem Composer. O `php -S` sobe com `-q`.
- `StatusController::health` deixa de expor a mensagem do driver.
- `FaturasGerarMensais` isola cada item.
- Cobrança: formatador próprio (`App\Logging\FormatoJson`), middleware de correlação e `DB_MASK_BINDINGS=true`. O `correlation_id` fica no `Context` do Laravel, que o Octane limpa a cada requisição.

## Decisões difíceis de reverter

| Decisão | Formato | Alternativa descartada |
|---|---|---|
| Header de correlação | `X-Correlation-Id`, aceito e sempre devolvido, nos dois serviços | `X-Request-Id`: muda a cada salto |
| Corpo do erro 500 | `{"erro": "Erro interno", "correlation_id": "<uuid>"}`, nos dois serviços | Padrão do Laravel (`{"message": "Server Error"}`): sem o id, o suporte não acha o log |

**Fontes:** [ADR-003](../adr/003-logs-estruturados-com-correlation-id.md).
