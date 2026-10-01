-- Base de eventos do monólito (ADR-005) e flag de unidade migrada (ADR-004).

-- Transactional outbox: eventos publicados pelo monólito, expostos em GET /eventos.
CREATE TABLE outbox (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id        VARCHAR(64)      NOT NULL,
    tipo            VARCHAR(60)      NOT NULL,
    versao          TINYINT UNSIGNED NOT NULL,
    aggregate_id    VARCHAR(64)      NOT NULL,
    sequencia       INT UNSIGNED     NOT NULL,
    correlation_id  VARCHAR(64)      NOT NULL,
    dados           JSON             NOT NULL,
    ocorrido_em     DATETIME(3)      NOT NULL,
    UNIQUE KEY uq_outbox_event_id (event_id),
    KEY idx_outbox_aggregate (aggregate_id, sequencia)
) ENGINE=InnoDB;

-- Inbox: eventos de Cobrança já aplicados (idempotência).
CREATE TABLE eventos_processados (
    event_id      VARCHAR(64) PRIMARY KEY,
    processado_em DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB;

-- Última sequência aplicada por agregado (descarta evento fora de ordem).
CREATE TABLE agregados_sequencia (
    aggregate_id VARCHAR(64)  PRIMARY KEY,
    sequencia    INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- Posição de leitura de cada feed consumido.
CREATE TABLE consumidor_posicao (
    feed       VARCHAR(40)     PRIMARY KEY,
    ultimo_id  BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Unidades cujas faturas são de Cobrança (canary por unidade).
CREATE TABLE cobranca_unidades_migradas (
    unidade_id  INT UNSIGNED PRIMARY KEY,
    migrada_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_unidades_migradas_unidade FOREIGN KEY (unidade_id) REFERENCES unidades (id)
) ENGINE=InnoDB;
