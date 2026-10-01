# ADR-001: Serviço de Cobrança em PHP 8.5 com Laravel 13, Octane e FrankenPHP

- **Data**: 2026-09-30
- **Status**: Aceita
- **Decisores**: Guilherme Ramos Nepomuceno
- **Tags**: linguagem, runtime, performance, time

## Contexto e problema

Cobrança vai sair do monólito para um serviço próprio. O desafio deixa a linguagem livre, mas o monólito continua em PHP. A carga exigida é baixa: o pico do check-in é de 40 req/s, chegam 30 mil webhooks por dia e o cron precisa gerar 250 mil faturas em até 1 hora. O risco real está na correção, porque o serviço mexe com dinheiro: o webhook tem que ser idempotente, o outbox transacional e o cron tem que chamar o gateway de forma concorrente. Também pesa o time: são 6 anos de PHP neste monólito.

O banco também poderia ser trocado ou atualizado. Como esta decisão é sobre a stack de aplicação, ele fica como está: **MySQL 8.4, a mesma engine do monólito, em instância própria** (o serviço não lê nem escreve no banco do monólito).

## Critérios de decisão

- Atender a carga com folga e sem falhas sob concorrência.
- Driver MySQL maduro, já que é um serviço de dinheiro.
- Domínio do time e revisão cruzada com o monólito.
- Versões estáveis com janela de suporte longa.
- Pouco código repetitivo e convenções que a IA conhece bem.

## Opções consideradas

Go 1.26, Bun 1.4 + TypeScript, PHP 8.5 puro (FPM e FrankenPHP), Laravel 13 com FPM e **Laravel 13 com Octane sobre FrankenPHP**.

## Decisão

Escolhemos **Laravel 13 + Octane 2 sobre FrankenPHP 1.12, com PHP 8.5**.

Todas as stacks atendem a carga. O Laravel com Octane é a opção que junta folga suficiente, driver PDO maduro, ferramentas prontas (validação, filas, scheduler, migrations, cliente HTTP concorrente) e a linguagem que o time já domina. Com isso, o time revisa, opera e dá plantão nos dois lados sem troca de contexto.

**Evidências.** O mesmo serviço (webhook idempotente com outbox, consulta de situação e cron) foi implementado em cada stack e medido contra 1 milhão de faturas, com 4 vCPU por app e 64 conexões. Os valores são a mediana de 3 rodadas, e nenhuma das 24 execuções teve falha.

| Stack | Leitura do check-in | Webhook | Cron (5.000 faturas) | Memória sob carga |
|---|---|---|---|---|
| Bun + mysql2 | 11.013 req/s | 1.184 req/s | 9,8 s | 157 MB |
| Go | 6.660 req/s | 1.288 req/s | 12,0 s | 10 MB |
| PHP puro (FrankenPHP worker) | 6.186 req/s | 823 req/s | 8,7 s | 81 MB |
| **Laravel Octane ✅** | **997 req/s** | **650 req/s** | **12,0 s** | **294 MB** |
| Laravel FPM | 351 req/s | 289 req/s | 12,0 s | 127 MB |
| *Requisito do desafio* | *40 req/s no pico* | *30 mil/dia* | *250 mil em 1 h* | — |

Em resumo:

- **Atende com folga.** O Octane lê **25× o pico** do check-in, e as 250 mil faturas saem em cerca de **10 min**, ou seja, cabem 6 vezes na janela de 1 hora. O cron em série, como o monólito faz hoje, levou 219 s para 5.000 faturas, **18× mais lento**.
- **O Octane é obrigatório.** O mesmo Laravel rodando em FPM é **2,8× mais lento na leitura** e **2,3× no webhook**.
- **Go e Bun são mais rápidos, mas não precisamos disso.** Leem de 7 a 11× mais rápido. No webhook, que é limitado pelo commit no MySQL, a diferença cai para cerca de 2×. O Go exigiria formar o time. O driver MySQL do Bun (Bun.SQL) tem bugs em aberto, e uma rodada do cron gerou só 2.576 das 5.000 faturas, resultado que não se repetiu.
- **O custo é memória.** O Octane usa 294 MB, contra 10 MB do Go.

**Estabilidade e suporte:**
- **PHP 8.5:** lançado em 20/11/2025, já está no patch 8.5.11. Suporte ativo até 31/12/2027 e de segurança até **31/12/2029**.
- **Laravel 13:** lançado em 17/03/2026, já está na versão 13.34. Correção de bugs até o 3º trimestre de 2027 e de segurança até **17/03/2028**.
- **FrankenPHP:** mantido na organização oficial `php/` do GitHub.

## Consequências

**Positivas**
- Uma linguagem só no repositório: o mesmo time cuida do monólito e do serviço.
- Validação, filas (Horizon) e scheduler já vêm prontos para as regras de Finanças (parcelamento, multa, desconto).
- O Laravel 13 já traz `AGENTS.md` com diretrizes para agentes de IA, e o código segue convenções que os modelos conhecem.
- No Octane, o app e a conexão com o banco ficam vivos entre requisições, sem recarregar o framework a cada chamada como no FPM.

**Negativas**
- O framework custa caro por requisição: a leitura é 6× mais lenta que o PHP puro em worker mode. Nas consultas mais acessadas, usar Query Builder ou SQL direto em vez de Eloquent.
- O consumo de memória é o maior entre as opções (294 MB, contra 10 MB do Go).
- No Octane, o estado pode vazar entre requisições (singletons e propriedades estáticas). Mitigação: testes e `--max-requests`.
- São 97 pacotes no `vendor`, então rodar `composer audit` na CI.
- O Laravel 13 perde suporte de segurança antes do PHP 8.5, em 03/2028. Planejar a migração para o Laravel 14, previsto para o 1º trimestre de 2027.

## Notas do benchmark

Feito com Docker Desktop sobre WSL2 e MySQL 8.0, por limitação do ambiente. Cada commit custa cerca de 13 ms ali, então os números absolutos são pessimistas; a comparação entre as stacks continua válida. Código das 8 variantes, dados brutos e como reproduzir: [docs/benchmark](../benchmark/).
