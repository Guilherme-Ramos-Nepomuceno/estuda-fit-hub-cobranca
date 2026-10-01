# PRD: extração do domínio de Cobrança

Plano de implementação a partir das [ADRs](../adr/) e do [DESAFIO.md](../../DESAFIO.md). Cada etapa tem critérios numerados, e os testes citam esses números.

## Etapas

| Etapa | Resultado observável | Status |
|---|---|---|
| Base | `docker compose up` sobe os serviços com bancos separados, e `make test` roda as suítes ([ADR-002](../adr/002-estrategia-de-testes-e-ambiente-docker-compose.md)) | ✅ concluída |
| [1](01-gerar-fatura-por-evento.md) | Matrícula numa unidade migrada gera a fatura em Cobrança, por evento, e o monólito recebe a cópia | ✅ concluída |
| [2](02-webhook-de-pagamento.md) | Pagamento de fatura de unidade migrada baixa a fatura em Cobrança e reativa o aluno, sem duplicar | ✅ concluída |
| [3](03-checkin-sem-faturas.md) | A catraca decide sem ler `faturas` e responde com Cobrança fora do ar; Cobrança marca as vencidas | ✅ concluída |
| [4](04-logs-estruturados.md) | Uma operação é rastreável nos dois sistemas por `correlation_id`, e nenhum erro de banco sai na resposta | ✅ concluída |
| [5](05-documentacao-e-evidencias.md) | Avaliador lê a decisão em 2 páginas, roda tudo e reproduz o benchmark | ✅ concluída |

A base de eventos (outbox, feed e inbox) entra na etapa 1, junto com o primeiro evento que a usa: sozinha, não tem nada que se veja funcionando. A ordem 1 → 2 → 3 é obrigatória, porque cada etapa usa os eventos da anterior. A 4 depende só da 1, e a 5 vem por último. Todas estão concluídas.

## Emendas feitas nas ADRs

Ao confrontar as ADRs com o código, alguns pontos estavam errados ou incompletos e foram corrigidos na fonte:

| ADR | Emenda |
|---|---|
| 002 | O monólito ganha migrations incrementais; o `--se-vazio` vale só para o schema inicial |
| 002 | O compose ganha os dois consumidores de eventos |
| 002 | `make seed-volume` com 14 milhões de faturas (entregue na etapa 5) |
| 005 / 006 | IDs de Cobrança a partir de 2.000.000.000; a cópia no monólito tem id local e o de Cobrança em `cobranca_id` (o id igual avançava o `AUTO_INCREMENT` do monólito, bug achado na etapa 2) |
| 006 | Todo webhook é gravado no outbox do monólito e respondido com `204`, o que elimina a corrida em que ele chega antes da cópia da fatura |
