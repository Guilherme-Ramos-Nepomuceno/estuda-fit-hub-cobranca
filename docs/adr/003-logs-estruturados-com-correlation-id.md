# ADR-003: Logs estruturados em JSON com correlation_id

- **Data**: 2026-10-01
- **Status**: Aceita
- **Decisores**: Guilherme Ramos Nepomuceno
- **Tags**: observabilidade, logs, segurança, rollout

## Contexto e problema

Hoje o monólito registra só duas linhas de texto, em `public/index.php`: `[req] POST /webhooks/pagamento -> 204 (203 ms)` e `[erro] Classe: mensagem`. Faltam nível, ID de requisição e contexto de negócio. O webhook não registra o `event_id`, e os crons só imprimem um resumo no final, então quando um item falha não fica rastro. Para investigar um pagamento, é preciso consultar o banco de produção.

Há também um vazamento: a resposta 500 devolve `$e->getMessage()`, o que pode expor um erro de SQL a quem chamou a API.

Com Cobrança separada, uma operação passa a cruzar dois processos e um outbox. Sem um identificador comum, não dá para ligar as pontas, nem decidir com segurança se o rollout avança ou volta.

## Decisão

1. **Formato:** JSON, uma linha por evento, na saída de erro do processo (`stderr`), que o `docker compose logs` já coleta. Campos fixos: `timestamp` (UTC), `level`, `service`, `correlation_id`, `event`, além do contexto de negócio (`aluno_id`, `fatura_id`, `event_id`, `competencia`).
2. **`correlation_id` de ponta a ponta:** o header `X-Correlation-Id` é lido, ou gerado se não vier, na entrada do monólito e do serviço. Ele é gravado nos eventos do outbox e repassado por quem consome esses eventos. No Octane, o contexto é limpo a cada requisição para não vazar entre elas.
3. **Erro de banco nunca sai na resposta:** o 500 devolve `{"erro": "Erro interno", "correlation_id": "..."}`. A exceção completa vai só para o log.
4. **Cada item que falha gera um log:** no cron, no gateway e no outbox, cada falha é registrada com os IDs e o motivo, e o processamento segue. Ao final sai um resumo com `geradas`, `puladas`, `falhas` e `duracao_s`.
5. **Dados pessoais mascarados:** CPF, e-mail e telefone nunca aparecem completos.

**Por que estruturado:**
- **Busca direta por campo.** Qualquer agregador (Loki, CloudWatch, Datadog) filtra por `correlation_id`, `fatura_id` ou `event_id` sem expressão regular e sem consultar o banco de produção.
- **Contagem.** Dá para contar e agrupar, por exemplo falhas por lote ou webhooks duplicados por hora.
- **Estabilidade.** Mudar o texto de uma mensagem não quebra buscas nem alertas.

## Como apoia o rollout e o rollback

Os logs viram os sinais de decisão de cada etapa:

| Sinal | Evento no log | Avança se | Volta atrás se |
|---|---|---|---|
| Falhas no webhook | `http.requisicao` com `path` `/webhooks/pagamento` e `status` 5xx; o detalhe em `http.erro` | Taxa igual à de antes | Taxa acima da de antes |
| Atraso dos eventos entre os sistemas | `evento.processado`, campo `lag_ms` | p95 abaixo de 60 s | Acima de 5 min |
| Cron mensal | `cron.fim`, campo `falhas`, e um `cron.item_falhou` por item | 0 falhas sem reprocessamento | Lote com falhas sem reprocessamento |
| Divergência no check-in (modo sombra, **a implementar** antes do primeiro degrau) | `checkin.divergencia` | 0 divergências em 24 h | Qualquer divergência sem explicação |

## Consequências

**Positivas**
- Um pagamento é rastreado do gateway ao check-in por um único `correlation_id`.
- Investigar não exige acesso ao banco de produção.
- Some o vazamento de erros de SQL nas respostas da API.
- Avançar ou voltar no rollout passa a ser uma decisão baseada em sinais objetivos.

**Negativas**
- O volume de log cresce. Vai ser preciso definir retenção e, no futuro, amostragem dos eventos de sucesso.
- O monólito ganha um logger mínimo próprio, porque não tem Composer.
- O JSON é menos legível no terminal; para ler localmente, usar `jq` ou o `pail` do Laravel.

## Fora de escopo

Tracing distribuído (OpenTelemetry) e plataforma de métricas. O `correlation_id` foi escolhido para poder virar `trace_id` mais tarde. Os detalhes de implementação ficam no PRD de implementação.
