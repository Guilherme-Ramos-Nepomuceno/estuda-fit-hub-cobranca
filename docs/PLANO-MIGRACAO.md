# Plano de migração e corte

Como as 14 milhões de faturas históricas e o cron mensal passam do monólito para o serviço de Cobrança **sem indisponibilidade**, com validação e caminho de volta em cada fase. As decisões e o porquê de cada uma estão na [ADR-006](adr/006-migracao-das-faturas-historicas-e-corte-do-cron.md). O rollout por unidade está na [ADR-004](adr/004-rollout-canary-por-unidade.md).

## Princípios

- **Um único sistema responsável por cada fatura.** Uma *unidade* (academia) troca de responsável inteira, ligando uma flag (`unidades:migrar`). O monólito nunca grava fatura de Cobrança, e vice-versa.
- **O histórico não muda.** Faturas pagas e canceladas são imutáveis e podem ser copiadas antes, sem pressa. Só as vivas (abertas e vencidas) precisam ser sincronizadas no momento da troca.
- **O monólito continua com tudo.** As tabelas `faturas` e `pagamentos` dele passam a ser uma cópia de leitura, alimentada pelos eventos de Cobrança (`cobranca_id`). Assim, relatórios, tela do aluno e rollback continuam funcionando.
- **Toda cópia é idempotente e retomável.** Pode ser interrompida e repetida sem duplicar.

## Fases

| Fase | O que acontece | Validação para avançar | Como voltar atrás |
|---|---|---|---|
| **0. Preparação** | Cobrança no ar sem nenhuma unidade migrada. Réplica de leitura do monólito com usuário somente leitura. Ensaio completo no volume de produção local (`make seed-volume`) | Ensaio dentro do tempo esperado (abaixo) e sem divergência | Nada a desfazer: nenhuma unidade foi tocada |
| **1. Histórico** | Comando em Cobrança copia as faturas pagas e canceladas da réplica, em jobs de 5 mil por faixa de ID e 4 workers, com upsert pela chave primária e os mesmos IDs | Contagem, soma de `valor` e contagem por status, **por competência**, iguais nos dois lados | Apagar a cópia em Cobrança. O monólito não foi alterado |
| **2. Vivas** | O mesmo comando, filtrado por status `aberta`/`vencida`, repetido até a diferença entre as execuções ficar pequena | Mesma validação, filtrada pelas vivas | Igual à fase 1 |
| **3. Canary** | 1 unidade → 4 (10%) → 40. Para cada unidade: sincronização final das vivas dela → validação → `unidades:migrar` | Pelo menos 24 h por degrau, com os sinais de log da [ADR-003](adr/003-logs-estruturados-com-correlation-id.md): zero divergência no check-in, erro do webhook igual ao de antes, atraso do outbox abaixo de 60 s e nenhuma falha no cron sem reprocessamento | `unidades:reverter`, em segundos e sem deploy. A cópia no monólito continua atualizada pelos eventos |
| **4. 100%** | A URL de webhook do gateway passa para Cobrança. Revoga-se o acesso à réplica | Um cron mensal completo rodando só em Cobrança | Devolver a URL do webhook ao monólito, que continua registrando e repassando |
| **5. Limpeza** | As tabelas de faturas do monólito saem quando os relatórios passarem a ler um modelo próprio | Relatórios validados contra o modelo novo | — |

## Corte do cron mensal

- **Por unidade:** no dia 1º, Cobrança gera as faturas das unidades migradas, e o monólito as das demais. O monólito já ignora as unidades migradas (`faturas:gerar-mensais`).
- **Contra duplicata:** a chave única `(matricula_id, competencia)` em Cobrança impede fatura duplicada, mesmo se os dois rodarem por engano.
- **Calendário:** **nenhuma unidade troca de responsável entre o dia 26 e o fim do cron do dia 1º, já validado.** Assim, a troca anterior teve pelo menos 24 h de observação, e o cron nunca pega uma unidade no meio da troca.
- **Responsável fixo durante a execução:** o responsável de cada unidade é lido no início e não muda durante aquela rodada.
- **Concorrência ao gateway:** em série, 250 mil faturas levam horas. O monólito, em série, levou 219 s para 5 mil; com 50 chamadas simultâneas, levou 9 a 12 s. Em Cobrança, o cron mensal usa jobs em lote com chamadas concorrentes (a implementar, ver Lacunas).
- **Sinal de saúde:** cada execução registra `cron.item_falhou` por item e `cron.fim` com geradas, puladas, falhas e duração, e sai com código `1` se houve falha.

## Números de referência

**Migração** (1 milhão de faturas, MySQL com 4 vCPU; detalhes em [docs/benchmark](benchmark/)):
- **Lote de 5 mil com 4 processos:** 19,7 s e 18 MB por processo.
- **Registro por registro:** cerca de 7,7 h.
- **Mais processos que CPUs do banco não ajuda:** com 8 processos, só 12% a mais.
- **Manter os índices do destino:** removê-los e recriá-los custou mais (40 s) do que mantê-los.
- **Repetir a cópia** sobre o destino preenchido levou 2,9 s, sem duplicar.
- **Projeção para 14 milhões:** de 5 a 15 min, porque o índice cresce além da memória do banco.

**Volume de ensaio:** `make seed-volume` gera 250 mil alunos e pouco mais de 14 milhões de faturas sintéticas (cerca de 2,2 GB). Tempo medido: de 5,5 a 8,3 min (14.145.459 faturas), conforme a carga da máquina. Nesse volume, a catraca com Cobrança parada manteve p95 de 23 a 55 ms a 40 req/s, sem falhas.

## Exceções à regra de não acessar o banco do monólito

| Exceção | Por quê | Termina |
|---|---|---|
| Cobrança lê uma réplica do monólito com usuário somente leitura (fases 1 a 3) | Os dados precisam vir de algum lugar, e a réplica não pesa no banco de produção | Na sincronização final da última unidade |
| O monólito recebe e registra no outbox todos os webhooks (fases 3 e 4) | Uma única URL no gateway e rollback por unidade | Na troca da URL do webhook |

## Lacunas conhecidas

- **Comando de migração das faturas:** desenhado e medido no benchmark (`docs/benchmark/migracao`), mas não implementado no serviço.
- **Cron mensal em Cobrança:** não implementado. Nesta entrega, Cobrança gera só a primeira fatura de cada matrícula. O padrão de chamadas concorrentes ao gateway foi validado no benchmark.
- **Rollback de uma unidade depois de faturas geradas por Cobrança:** o monólito volta a gerar as faturas novas, mas as que Cobrança criou para essa unidade continuam marcadas como dela (`cobranca_id`), e os webhooks dessas faturas continuam indo para Cobrança. Um rollback total, com Cobrança desligada, exigiria que o monólito adotasse essas cópias, o que não está implementado.
- **Cancelamento de matrícula (`MatriculaCancelada`):** fica no plano. Até lá, cancelar uma matrícula de unidade migrada não cancela as faturas em Cobrança.
