# Desafio técnico: extrair o domínio de Cobrança do monólito

## 1. Contexto

A **Estuda Fit Hub** é uma rede fictícia de academias com 40 unidades e cerca de 250 mil alunos ativos. O sistema que sustenta a operação é este monólito em **PHP 8** com um único banco **MySQL**, construído ao longo de 6 anos. Ele concentra os seguintes módulos:

| Módulo | Responsabilidade | Onde está no código |
|---|---|---|
| Alunos | Cadastro, dados pessoais | `src/Controllers/AlunoController.php` |
| Planos e Matrículas | Catálogo de planos, matrícula, cancelamento | `src/Services/MatriculaService.php`, `src/Controllers/MatriculaController.php` |
| **Cobrança** | Faturas mensais, pagamentos, webhook do gateway, régua de inadimplência | `src/Console/FaturasGerarMensais.php`, `src/Console/FaturasMarcarVencidas.php`, `src/Console/CobrancaRegua.php`, `src/Controllers/PagamentoWebhookController.php` |
| Check-in | Liberação da catraca | `src/Controllers/CheckinController.php` |
| Notificações | E-mail, SMS e push | `src/Services/NotificacaoService.php` |
| Relatórios | Painéis gerenciais | `src/Controllers/RelatorioController.php` |

O código foi reduzido ao essencial para caber em um desafio, mas **os acoplamentos são reais** e representam o que existe em produção.

### O problema que motiva o trabalho

- **Cobrança** é o módulo com mais incidentes nos últimos 12 meses. Qualquer deploy do monólito (mesmo uma mudança em Relatórios) pode derrubar o cron que gera 250 mil faturas no dia 1º de cada mês.
- Quando o gateway de pagamento fica lento, o **webhook** de confirmação trava workers do monólito e o **check-in** das catracas começa a falhar por falta de capacidade.
- O time de Finanças quer evoluir regras de cobrança (parcelamento, multa, desconto por antecipação) com cadência própria, sem esperar a fila de deploy do monólito.
- A liderança decidiu **extrair Cobrança para um serviço independente**, mantendo o monólito em produção durante toda a transição, sem janela de indisponibilidade.

## 2. Mapa dos acoplamentos

Estude estes pontos antes de desenhar qualquer coisa. Eles precisam ser tratados na sua solução.

| # | Onde | O que acontece |
|---|---|---|
| A | `MatriculaService::criar` | A primeira fatura nasce dentro da transação da matrícula. Na mesma transação há chamada ao gateway e envio de e-mail síncrono. |
| B | `CheckinController::registrar` | A catraca consulta a tabela `faturas` para decidir se libera. Regra de Cobrança embutida no Check-in. |
| C | `PagamentoWebhookController::receber` | O webhook escreve em `faturas`, `pagamentos` e **em `alunos.situacao`**, e ainda dispara SMS síncrono. Não é idempotente (veja o teste `testWebhookDuplicadoRegistraPagamentoDuasVezesComportamentoAtual`). |
| D | `FaturasGerarMensais` | Cron do dia 1º. Lê `matriculas` e `planos`, cria faturas uma a uma e chama o gateway em série. |
| E | `CobrancaRegua` | Cron diário que bloqueia alunos (escreve em `alunos.situacao`) e envia notificações. |
| F | `RelatorioController` | Relatórios com JOIN entre `faturas`, `pagamentos`, `alunos`, `matriculas`, `planos` e `unidades`. |
| G | `MatriculaService::cancelar` | Cancelamento de matrícula cancela faturas direto na tabela. |
| H | `AlunoController::mostrar` | Tela do aluno conta faturas abertas e vencidas direto na tabela. |

### Esquema do banco

Está em `database/schema.sql`. Tabelas: `unidades`, `alunos`, `planos`, `matriculas`, `faturas`, `pagamentos`, `checkins`, `notificacoes`.

### Números para dimensionar (produção)

| Métrica | Valor |
|---|---|
| Alunos ativos | 250 mil |
| Faturas geradas no cron mensal | 250 mil em até 1 hora |
| Check-ins por dia | 180 mil, pico de 40 por segundo entre 18h e 20h |
| Webhooks do gateway por dia | 30 mil, com rajadas no dia do vencimento |
| Faturas históricas na tabela | 14 milhões |

O seed local cria 2 mil alunos. Use `--alunos=N` para simular volumes maiores.

## 3. Sua tarefa

Projetar e **iniciar** a extração do domínio de **Cobrança** para um serviço independente. Não esperamos a migração completa. Esperamos uma fatia vertical que prove a abordagem e um plano claro para o restante.

### Entregáveis obrigatórios

1. **Documento de decisão** (máximo 2 páginas, formato livre, ADR é bem-vindo) cobrindo:
   - Fronteira: o que passa a ser do serviço de Cobrança, o que fica no monólito e por quê. Trate explicitamente `faturas`, `pagamentos`, `planos.valor_mensal`, `matriculas.dia_vencimento` e `alunos.situacao`.
   - Contrato: como o monólito e o serviço conversam (API, eventos ou ambos). Inclua os contratos que você criaria.
   - Dados: onde vivem as 14 milhões de faturas ao final e como chegam lá.
   - Rollout: como colocar em produção sem indisponibilidade e como voltar atrás se der errado.
   - Riscos e o que você não resolveu.

2. **Código** (linguagem livre para o serviço novo; o monólito continua em PHP):
   - O serviço de Cobrança com pelo menos dois casos de uso funcionando: **gerar fatura para uma matrícula** e **receber o webhook de pagamento**.
   - A alteração no monólito no ponto de acoplamento **B** (check-in), de forma que ele deixe de consultar a tabela `faturas`.
   - Testes automatizados do que você construiu. Os testes existentes em `tests/` devem continuar passando ou ser ajustados com justificativa.
   - Tudo deve subir com `docker compose up`.

3. **Plano de migração e corte** para as faturas históricas e para o cron mensal.

4. **README** com: como rodar, decisões que você tomou por falta de tempo e o que faria a seguir.

### Restrições

- Zero indisponibilidade para check-in e para o webhook.
- Relatórios gerenciais não podem parar de funcionar em nenhuma etapa, mesmo que temporariamente fiquem com atraso de alguns minutos.
- Check-in precisa responder em menos de 300 ms no p95 mesmo com o serviço de Cobrança fora do ar.
- O webhook do gateway pode chegar duplicado ou fora de ordem.
- Não é permitido que o serviço novo leia ou escreva no banco do monólito em regime permanente. Durante a transição, justifique cada exceção.

### Formato e prazo

- Esforço esperado: até **6 horas**. Não premiamos quem gastar mais tempo.
- Prazo de entrega: **5 dias corridos** a partir do recebimento.
- Entrega: fork ou cópia deste repositório com o código, o documento e o README, em um repositório Git ao qual tenhamos acesso.
- Apresentação: **45 minutos** em chamada, sendo 15 de apresentação sua e 30 de conversa técnica.

### Uso de ferramentas de IA

Pode usar. Vamos avaliar o seu entendimento na apresentação, não a autoria de cada linha. Você precisa saber explicar e defender toda decisão que estiver no repositório.
