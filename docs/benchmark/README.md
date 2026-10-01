# Benchmark de stack e de migração

Evidência das decisões da [ADR-001](../adr/001-servico-cobranca-em-php-laravel-octane-frankenphp.md) (stack do serviço de Cobrança) e da [ADR-006](../adr/006-migracao-das-faturas-historicas-e-corte-do-cron.md) (migração das faturas).

## O que foi medido

O mesmo serviço de Cobrança foi implementado em 8 variantes, com a mesma lógica:

| Variante | Pasta |
|---|---|
| Go 1.26 (`net/http` + `go-sql-driver/mysql`) | `go/` |
| Bun 1.4 com `Bun.SQL` e com `mysql2`, 4 processos (`reusePort`) | `bun/` |
| PHP 8.5 puro: FPM, FPM com conexão persistente e FrankenPHP em worker mode | `php/` |
| Laravel 13 + Eloquent: FPM e Octane com FrankenPHP | `laravel/` |

Cada variante expõe três operações:
- **Webhook idempotente:** registra o `event_id`, trava a fatura com `FOR UPDATE`, grava o pagamento e o evento no outbox, tudo numa transação.
- **Consulta de situação financeira:** a usada pelo check-in.
- **Cron:** 5.000 faturas, com o gateway simulado a 20 ms e 50 chamadas simultâneas. O PHP-FPM agrupa as gravações em transação por lote.

**Condições:**
- **Banco:** MySQL 8.0 com 1 milhão de faturas (250 mil alunos × 4 competências).
- **Recursos:** 4 vCPU por aplicação.
- **Carga:** gerador próprio em Go (`gateway/loadgen`), com 8 e 64 conexões.
- **Repetições:** 3 rodadas intercaladas, com a ordem das stacks rotacionada a cada rodada. Os números são a mediana.
- **Validação:** nas 24 execuções, nenhuma requisição falhou, e o número de pagamentos bate com o de eventos no outbox.

## Resultados (mediana de 3 rodadas)

| Stack | Leitura, 64 conexões | Webhook, 64 conexões | Cron 5.000 | Memória sob carga |
|---|---|---|---|---|
| Bun + mysql2 | 11.013 req/s | 1.184 req/s | 9,8 s | 157 MB |
| Bun + Bun.SQL | 9.648 req/s | 1.166 req/s | 9,3 s | 66 MB |
| Go | 6.660 req/s | 1.288 req/s | 12,0 s | 10 MB |
| PHP puro, FrankenPHP worker | 6.186 req/s | 823 req/s | — | 81 MB |
| PHP puro, FPM persistente | 4.147 req/s | 906 req/s | — | 46 MB |
| **Laravel Octane** | **997 req/s** | **650 req/s** | **12,0 s** | **294 MB** |
| PHP puro, FPM | 447 req/s | 581 req/s | 8,7 s | 54 MB |
| Laravel FPM | 351 req/s | 289 req/s | 12,0 s | 127 MB |
| PHP em série (como o monólito hoje) | — | — | **219 s** | — |

A tabela completa, com p95, p99 e a variação entre rodadas, sai de `node aggregate.js`. Os dados brutos estão em `results/*.jsonl`.

## Migração das faturas (ADR-006)

Teste de cópia de 1 milhão de faturas entre dois bancos, em lotes por faixa de ID, com upsert idempotente (`migracao/`). Todas as execuções bateram com a origem em contagem, soma de valores e status.

| Estratégia | Tempo | Memória |
|---|---|---|
| Registro por registro, 1 processo | ~7,7 h (estimado pela taxa) | — |
| Lote de 5 mil, 1 processo | 23,7 s | 26 MB |
| **Lote de 5 mil, 4 processos** | **19,7 s** | 72 MB (18 MB por processo) |
| Lote de 5 mil, 8 processos | 17,4 s | 120 MB |
| Sem índices no destino + recriação | 40 s | — |
| Segunda execução sobre o destino já preenchido | 2,9 s, sem duplicar | 59 MB |

## Como reproduzir

Requer Docker e cerca de 4 GB livres. Leva por volta de 45 minutos.

```bash
cd docs/benchmark
./preparar.sh           # imagens, MySQL com 1 milhão de faturas e gateway simulado
./rounds.sh             # 3 rodadas intercaladas das 8 variantes (~40 min)
node aggregate.js       # medianas
./migracao/rodar.sh "1 2 4 8"   # migração em lotes com 1, 2, 4 e 8 processos
```

## Ressalvas

- **Números absolutos pessimistas:** a medição foi feita em Docker Desktop sobre WSL2, onde cada commit custa cerca de 13 ms. A comparação entre as stacks continua válida.
- **Variação entre rodadas:** em alguns cenários, a variação chegou a 2 vezes. Diferenças menores que cerca de 30% devem ser tratadas como empate.
- **Versão do MySQL:** o benchmark usou MySQL 8.0 por limitação de rede na época; o projeto usa MySQL 8.4.
- **Mesma instância:** na migração, origem e destino estavam no mesmo MySQL. Em produção são instâncias diferentes, e a rede entre elas também é amortizada pelo lote.
