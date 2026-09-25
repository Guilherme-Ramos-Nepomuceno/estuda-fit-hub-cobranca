SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notificacoes;
DROP TABLE IF EXISTS checkins;
DROP TABLE IF EXISTS pagamentos;
DROP TABLE IF EXISTS faturas;
DROP TABLE IF EXISTS matriculas;
DROP TABLE IF EXISTS planos;
DROP TABLE IF EXISTS alunos;
DROP TABLE IF EXISTS unidades;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE unidades (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(80)  NOT NULL,
    cidade      VARCHAR(80)  NOT NULL,
    criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE alunos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(120) NOT NULL,
    email       VARCHAR(160) NOT NULL,
    telefone    VARCHAR(20)  NOT NULL,
    cpf         CHAR(11)     NOT NULL,
    unidade_id  INT UNSIGNED NOT NULL,
    situacao    ENUM('ativo','inativo','bloqueado') NOT NULL DEFAULT 'ativo',
    criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_alunos_cpf (cpf),
    KEY idx_alunos_unidade (unidade_id),
    CONSTRAINT fk_alunos_unidade FOREIGN KEY (unidade_id) REFERENCES unidades (id)
) ENGINE=InnoDB;

CREATE TABLE planos (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome                 VARCHAR(80)   NOT NULL,
    valor_mensal         DECIMAL(10,2) NOT NULL,
    duracao_meses        TINYINT UNSIGNED NOT NULL,
    permite_multiunidade TINYINT(1)    NOT NULL DEFAULT 0,
    ativo                TINYINT(1)    NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE matriculas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    aluno_id        INT UNSIGNED NOT NULL,
    plano_id        INT UNSIGNED NOT NULL,
    inicio          DATE         NOT NULL,
    fim             DATE         NOT NULL,
    status          ENUM('ativa','trancada','cancelada') NOT NULL DEFAULT 'ativa',
    dia_vencimento  TINYINT UNSIGNED NOT NULL,
    criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_matriculas_aluno (aluno_id),
    KEY idx_matriculas_status (status),
    CONSTRAINT fk_matriculas_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id),
    CONSTRAINT fk_matriculas_plano FOREIGN KEY (plano_id) REFERENCES planos (id)
) ENGINE=InnoDB;

CREATE TABLE faturas (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    matricula_id  INT UNSIGNED  NOT NULL,
    aluno_id      INT UNSIGNED  NOT NULL,
    competencia   DATE          NOT NULL,
    valor         DECIMAL(10,2) NOT NULL,
    vencimento    DATE          NOT NULL,
    status        ENUM('aberta','paga','vencida','cancelada') NOT NULL DEFAULT 'aberta',
    pago_em       DATETIME      NULL,
    gateway_ref   VARCHAR(64)   NULL,
    criado_em     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_faturas_matricula (matricula_id),
    KEY idx_faturas_aluno_status (aluno_id, status),
    KEY idx_faturas_gateway_ref (gateway_ref),
    KEY idx_faturas_competencia (competencia),
    CONSTRAINT fk_faturas_matricula FOREIGN KEY (matricula_id) REFERENCES matriculas (id),
    CONSTRAINT fk_faturas_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id)
) ENGINE=InnoDB;

CREATE TABLE pagamentos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fatura_id       INT UNSIGNED  NOT NULL,
    valor           DECIMAL(10,2) NOT NULL,
    metodo          VARCHAR(30)   NOT NULL,
    gateway_payload JSON          NULL,
    criado_em       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pagamentos_fatura (fatura_id),
    CONSTRAINT fk_pagamentos_fatura FOREIGN KEY (fatura_id) REFERENCES faturas (id)
) ENGINE=InnoDB;

CREATE TABLE checkins (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    aluno_id        INT UNSIGNED NOT NULL,
    unidade_id      INT UNSIGNED NOT NULL,
    ocorrido_em     DATETIME     NOT NULL,
    liberado        TINYINT(1)   NOT NULL,
    motivo_bloqueio VARCHAR(40)  NULL,
    KEY idx_checkins_aluno (aluno_id),
    KEY idx_checkins_unidade_data (unidade_id, ocorrido_em),
    CONSTRAINT fk_checkins_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id),
    CONSTRAINT fk_checkins_unidade FOREIGN KEY (unidade_id) REFERENCES unidades (id)
) ENGINE=InnoDB;

CREATE TABLE notificacoes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    aluno_id    INT UNSIGNED NOT NULL,
    canal       ENUM('email','sms','push') NOT NULL,
    template    VARCHAR(60)  NOT NULL,
    payload     JSON         NULL,
    enviado_em  DATETIME     NULL,
    KEY idx_notificacoes_aluno (aluno_id),
    CONSTRAINT fk_notificacoes_aluno FOREIGN KEY (aluno_id) REFERENCES alunos (id)
) ENGINE=InnoDB;
