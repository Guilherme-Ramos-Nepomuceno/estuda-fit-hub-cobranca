# ADR-006: Migração das faturas históricas e corte do cron mensal

- **Data**: 2026-10-01
- **Status**: Aceita
- **Decisores**: Guilherme Ramos Nepomuceno
- **Tags**: dados, migração, cron, rollout

## Contexto e problema

As 14 milhões de faturas e seus pagamentos estão no banco do monólito e precisam terminar no banco de Cobrança ([ADR-005](005-fronteira-de-cobranca-e-eventos-via-outbox-e-feed.md)). Isso tem que acontecer **sem indisponibilidade** e com os dados mudando durante a cópia: 30 mil pagamentos por dia, mais vencimentos e cancelamentos.

Algumas condições complicam:
- só **um sistema pode gravar** cada fatura;
- os IDs são referenciados pelo gateway (`gateway_ref`) e por `pagamentos`;
- relatórios e rollback dependem de o monólito continuar com os dados atualizados;
- o cron do dia 1º gera 250 mil faturas e é o processo com mais incidentes;
- o desafio proíbe o serviço de acessar o banco do monólito, exceto na transição e com justificativa.

## Decisão

**Termos usados aqui:**
- **Unidade:** uma academia da rede. São 40, com cerca de 6 mil alunos cada.
- **Sistema responsável:** quem gera, cobra, baixa e vence as faturas dos alunos de uma unidade naquele momento. No início é o monólito para todas. Durante o canary, Cobrança assume as unidades migradas.
- **Troca de responsável:** ligar a flag de uma unidade ([ADR-004](004-rollout-canary-por-unidade.md)).

1. **Onde terminam:** no banco de Cobrança, **com os mesmos IDs**. As faturas novas criadas por Cobrança começam em 2.000.000.000 (`AUTO_INCREMENT`), para não colidir com os IDs do histórico migrado. As tabelas `faturas` e `pagamentos` do monólito deixam de ser fonte e viram **cópia de leitura alimentada por eventos**: a cópia de uma fatura criada por Cobrança tem id local e guarda o id de lá em `cobranca_id`. Gravar o id de Cobrança como id da cópia avançaria o `AUTO_INCREMENT` do monólito, e as faturas que ele ainda cria colidiriam com as de Cobrança. Com isso, o que mantém relatórios, a tela do aluno e o rollback funcionando. Elas são removidas quando os relatórios usarem o modelo de leitura próprio.
2. **Histórico e vivas são tratados separadamente:**
   - **Histórico** (pagas e canceladas, imutáveis): copiado antes do canary, em segundo plano, quantas vezes for preciso.
   - **Vivas** (abertas e vencidas): sincronizadas **por unidade, no momento da troca de responsável**. Primeiro a sincronização final da unidade, depois a flag é ligada.
3. **Como copiar:** um comando em Cobrança divide a faixa de IDs em jobs de 5 mil faturas (`Bus::batch`), processados por **4 workers**. Cada job lê por keyset, grava com upsert idempotente pela chave primária e guarda o último ID. Se cair, retoma de onde parou; se rodar duas vezes, não duplica. Os índices do destino ficam ativos durante a carga.
4. **Validação:** contagem, soma de `valor` e contagem por status, **por competência**, nos dois lados. A unidade só troca de responsável se tudo bater.
5. **Corte do cron mensal:**
   - **Por unidade:** no dia 1º, Cobrança gera as faturas das unidades que já são dela, e o monólito gera as das demais.
   - **Proteção contra duplicata:** a chave única `(matricula_id, competencia)` impede fatura duplicada mesmo se os dois rodarem por engano.
   - **Calendário:** **nenhuma unidade troca de responsável entre o dia 26 e o fim do cron do dia 1º**, já validado. Assim a geração mensal nunca pega uma unidade no meio da troca, e a troca anterior teve pelo menos as 24 h de observação da ADR-004, com margem para corrigir.
   - **Responsável fixo durante a execução:** cada execução do cron registra o sistema responsável de cada unidade no início e não muda durante aquela rodada.
6. **Webhook durante o canary:** o gateway continua com uma única URL, a do monólito. **Todo webhook**, de qualquer unidade, é gravado no outbox do monólito, com deduplicação pelo `event_id` do gateway, e a resposta é a mesma de hoje (`204`), sem chamar Cobrança.
   - Unidades migradas: Cobrança consome esses webhooks pelo feed ([ADR-005](005-fronteira-de-cobranca-e-eventos-via-outbox-e-feed.md)), como qualquer outro evento, e ignora as referências que não são dela.
   - Unidades não migradas: além do registro, o monólito processa localmente, como hoje.
   - Gravar todos, e não só os das unidades migradas, elimina a corrida em que o webhook chega antes de o monólito ter a cópia da fatura criada por Cobrança.
   - Efeitos: o worker do monólito nunca espera Cobrança, e se Cobrança cair nenhum webhook se perde.
   - No 100%, a URL do gateway passa para Cobrança.

**Medição no ambiente local** (1 milhão de faturas, MySQL com 4 vCPU; script e dados em [docs/benchmark](../benchmark/)):

| Estratégia | Tempo | Memória |
|---|---|---|
| Registro por registro, 1 processo | ~7,7 h (estimado) | — |
| **Lote de 5 mil, 4 processos** | **19,7 s** | 18 MB por processo, 72 MB no total |
| Lote de 5 mil, 8 processos | 17,4 s (+12% sobre 4 processos, com o dobro de memória) | 120 MB no total |
| Destino sem índices + recriação | 40 s, pior que manter os índices | — |
| Segunda execução, sobre destino já preenchido | 2,9 s, sem duplicar | — |

Projeção para 14 milhões: **de 5 a 15 min**, a confirmar no volume de produção local ([ADR-002](002-estrategia-de-testes-e-ambiente-docker-compose.md)). O lote rende 1.180 vezes mais que o registro por registro. Passar de 4 processos quase não ajuda, porque o gargalo é o banco, e o consumo de memória não depende do volume total.

### Exceções à regra de não acessar o banco do monólito

| Exceção | Justificativa | Quando acaba |
|---|---|---|
| Cobrança lê uma **réplica** do monólito, com usuário **somente leitura**, para copiar o histórico e as vivas | Os dados precisam vir de algum lugar, e a réplica não pesa no banco de produção | Depois da sincronização final da última unidade |
| O monólito recebe e registra no outbox todos os webhooks, e não grava em `faturas` os das unidades migradas | Mantém uma única URL no gateway e permite rollback por unidade | No 100% do canary |

## Consequências

**Positivas**
- O histórico, que é a maior parte do volume, migra sem risco e sem pressa, porque não muda.
- Rollback por unidade sem perda de dados: o monólito recebe os eventos e continua com tudo atualizado.
- A migração pode ser interrompida, retomada e repetida a qualquer momento.
- O custo de memória é baixo e previsível, cerca de 20 MB por processo.

**Negativas**
- Durante o canary os dados ficam duplicados: os dois bancos guardam as faturas, e o monólito mantém uma cópia de leitura.
- Até o 100%, o webhook continua passando pelo monólito, agora só como registro rápido. Isso soma 1 a 3 s até o pagamento ser processado nas unidades migradas.
- O calendário do cron limita quando cada unidade pode trocar de responsável.
- A projeção para 14 milhões vem de uma medição com 1 milhão e precisa ser confirmada no volume real.

## Fora de escopo

Modelo de leitura dos relatórios (ADR futura) e retenção do histórico depois da remoção das tabelas do monólito. Os detalhes de implementação ficam no PRD de implementação.
