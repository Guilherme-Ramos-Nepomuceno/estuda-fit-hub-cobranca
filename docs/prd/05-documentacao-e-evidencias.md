# Etapa 5: documentação de entrega e evidências

**Contexto.** O desafio pede um documento de decisão de **no máximo 2 páginas**, um plano de migração e corte, e um README com como rodar, as decisões tomadas por falta de tempo e o que faria a seguir. A decisão está espalhada em 6 ADRs, e o benchmark que as sustenta está fora do repositório.

**O que muda.** O avaliador lê a decisão em 2 páginas, segue os links quando quiser o porquê, sobe e testa tudo com dois comandos e reproduz o benchmark.

## Critérios

1. `docs/DECISAO.md`: no máximo 900 palavras, com Fronteira (tratando `faturas`, `pagamentos`, `planos.valor_mensal`, `matriculas.dia_vencimento` e `alunos.situacao`), Contrato, Dados, Rollout, e Riscos e o que não foi resolvido. Cada seção aponta para a ADR correspondente.
2. A seção de riscos traz as consequências negativas das ADRs e o que ficou fora do escopo.
3. `docs/PLANO-MIGRACAO.md`: fases do histórico e do cron mensal, com validação e caminho de volta em cada uma, números medidos e link para a ADR-006.
4. `README.md` com "Como rodar", "Decisões por falta de tempo" e "O que faria a seguir". Os comandos funcionam num clone novo.
5. `docs/benchmark/` com o código das 8 variantes, os resultados brutos e como reproduzir. As ADRs 001 e 006 apontam para essa pasta.
6. `make seed-volume` gera 250 mil alunos e pelo menos 14 milhões de faturas sintéticas e termina com código `0`.
7. As emendas listadas no [índice do PRD](README.md) estão aplicadas nas ADRs.

## Fora do escopo

- Slides da apresentação.
- ADRs de experiência do usuário e de relatórios: ficaram para depois.
- Comando de migração das faturas: o desafio pede o plano, que traz os números medidos no benchmark.

**Fontes:** [DESAFIO.md](../../DESAFIO.md), seção 3.
