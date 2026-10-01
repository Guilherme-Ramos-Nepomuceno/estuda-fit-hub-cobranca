-- Banco próprio do serviço de Cobrança (benchmark).
CREATE DATABASE IF NOT EXISTS cobranca CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'bench'@'%' IDENTIFIED WITH mysql_native_password BY 'bench';
GRANT ALL PRIVILEGES ON cobranca.* TO 'bench'@'%';
FLUSH PRIVILEGES;
USE cobranca;

CREATE TABLE contratos (
    matricula_id   INT UNSIGNED PRIMARY KEY,
    aluno_id       INT UNSIGNED NOT NULL,
    valor_mensal   DECIMAL(10,2) NOT NULL,
    dia_vencimento TINYINT UNSIGNED NOT NULL,
    ativo          TINYINT(1) NOT NULL DEFAULT 1
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
    updated_at    DATETIME      NULL,
    UNIQUE KEY uq_faturas_gateway_ref (gateway_ref),
    UNIQUE KEY uq_faturas_matricula_competencia (matricula_id, competencia),
    KEY idx_faturas_aluno_status (aluno_id, status, vencimento)
) ENGINE=InnoDB;

CREATE TABLE pagamentos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fatura_id       INT UNSIGNED  NOT NULL,
    valor           DECIMAL(10,2) NOT NULL,
    metodo          VARCHAR(30)   NOT NULL,
    event_id        VARCHAR(64)   NOT NULL,
    criado_em       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pagamentos_fatura (fatura_id)
) ENGINE=InnoDB;

-- Idempotência do webhook: o event_id do gateway é a chave.
CREATE TABLE webhook_eventos (
    event_id     VARCHAR(64) PRIMARY KEY,
    reference    VARCHAR(64) NOT NULL,
    recebido_em  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Transactional outbox: eventos para o monólito (FaturaPaga etc.).
CREATE TABLE outbox (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo          VARCHAR(60) NOT NULL,
    payload       JSON        NOT NULL,
    criado_em     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    publicado_em  DATETIME(3) NULL
) ENGINE=InnoDB;

-- Seed: 1 milhão de faturas (250 mil alunos x 4 competências).
CREATE TABLE digitos (d TINYINT UNSIGNED PRIMARY KEY);
INSERT INTO digitos VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9);

INSERT INTO faturas (matricula_id, aluno_id, competencia, valor, vencimento, status, pago_em, gateway_ref)
SELECT
    ((n - 1) % 250000) + 1,
    ((n - 1) % 250000) + 1,
    DATE_SUB('2026-09-01', INTERVAL FLOOR((n - 1) / 250000) MONTH),
    89.90,
    DATE_ADD(DATE_SUB('2026-09-01', INTERVAL FLOOR((n - 1) / 250000) MONTH), INTERVAL 9 DAY),
    CASE
        WHEN n <= 250000 THEN 'aberta'
        WHEN n % 20 = 0 THEN 'vencida'
        ELSE 'paga'
    END,
    CASE WHEN n > 250000 AND n % 20 <> 0 THEN '2026-01-01 00:00:00' ELSE NULL END,
    CONCAT('gw_', n)
FROM (
    SELECT a.d + b.d * 10 + c.d * 100 + e.d * 1000 + f.d * 10000 + g.d * 100000 + 1 AS n
    FROM digitos a, digitos b, digitos c, digitos e, digitos f, digitos g
) nums;

INSERT INTO contratos (matricula_id, aluno_id, valor_mensal, dia_vencimento)
SELECT matricula_id, aluno_id, 89.90, 10 FROM faturas WHERE competencia = '2026-09-01' AND matricula_id <= 5000;
