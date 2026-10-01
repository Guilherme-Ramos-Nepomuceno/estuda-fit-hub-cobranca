-- Cópia local da situação financeira do aluno, lida pela catraca (ponto B, PRD etapa 4).
-- vencida_desde: menor vencimento entre as faturas vencidas; NULL quando não há débito.

CREATE TABLE situacao_financeira (
    aluno_id       INT UNSIGNED PRIMARY KEY,
    vencida_desde  DATE         NULL,
    sequencia      INT UNSIGNED NOT NULL DEFAULT 0,
    atualizado_em  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_situacao_financeira_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id)
) ENGINE=InnoDB;

-- Preenchimento inicial: sem ele, a catraca liberaria todos os alunos.
INSERT INTO situacao_financeira (aluno_id, vencida_desde)
SELECT a.id, MIN(CASE WHEN f.status = 'vencida' THEN f.vencimento END)
  FROM alunos a
  LEFT JOIN faturas f ON f.aluno_id = a.id
 GROUP BY a.id;
