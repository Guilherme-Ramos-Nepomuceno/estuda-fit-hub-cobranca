# ADR-004: Rollout em canary por unidade

- **Data**: 2026-10-01
- **Status**: Aceita
- **Decisores**: Guilherme Ramos Nepomuceno
- **Tags**: deploy, rollout, rollback, banco de dados

## Contexto e problema

O desafio exige colocar Cobrança em produção **sem indisponibilidade** e com um caminho claro para voltar atrás. Teste local, mesmo no volume de produção ([ADR-002](002-estrategia-de-testes-e-ambiente-docker-compose.md)), não prova o comportamento com dados e tráfego reais. Ligar o caminho novo para os 250 mil alunos de uma vez transforma qualquer erro em incidente na rede inteira.

## Decisão

O caminho novo (check-in pela projeção, webhook e cron no serviço) é ligado **por unidade (academia da rede)**, por meio de uma feature flag no monólito:

**1 unidade → 4 unidades (10%) → 40 unidades (100%)**

- **Avançar:** cada degrau fica no ar pelo menos 24 h e só avança se os sinais de log da [ADR-003](003-logs-estruturados-com-correlation-id.md), filtrados pelas unidades do canary, estiverem dentro do limite:
  - zero divergência no check-in;
  - taxa de erro do webhook igual à de antes;
  - atraso do outbox abaixo de 60 s;
  - nenhuma falha no cron sem reprocessamento.
  
  O `make test` também precisa passar.
- **Voltar atrás:** desligar a flag da unidade. Em segundos ela volta ao caminho antigo, sem deploy.
- **Compatibilidade:** como os dois caminhos convivem no mesmo schema, **toda mudança de banco é aditiva** (coluna nullable ou com default, tabela ou índice novos). Antes de ir para produção, ela é ensaiada no volume local de produção, medindo o tempo e o lock. Remover estruturas antigas só depois do 100%, num deploy separado. Assim, voltar atrás nunca exige desfazer migration.

## Consequências

**Positivas**
- Um erro atinge só os alunos das unidades do canary, não a rede inteira.
- Voltar atrás leva segundos e não depende de deploy nem de desfazer schema.
- A unidade é um corte natural do negócio: fácil de comunicar e de acompanhar nos relatórios.

**Negativas**
- O monólito convive com dois caminhos enquanto durar o canary, o que aumenta a complexidade temporariamente.
- Remover as estruturas antigas fica para depois e precisa ser acompanhado para não virar dívida.
- O canary não exercita a carga total. O pico de 40 check-ins por segundo só é validado no 100%, e por isso o teste de carga local continua obrigatório.

## Fora de escopo

Ferramenta de feature flag, estratégia de deploy do binário do serviço e backfill das 14 milhões de faturas (ADR de dados). Os detalhes de implementação ficam no PRD de implementação.
