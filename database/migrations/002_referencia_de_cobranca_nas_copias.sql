-- Cópias de leitura de faturas e pagamentos de Cobrança (ADR-006).
-- A cópia tem id local e guarda o id de Cobrança em cobranca_id. Gravar o id de Cobrança
-- (a partir de 2.000.000.000) como id avançaria o AUTO_INCREMENT do monólito, e as faturas
-- criadas aqui colidiriam com as de Cobrança.

ALTER TABLE faturas
    ADD COLUMN cobranca_id INT UNSIGNED NULL AFTER id,
    ADD UNIQUE KEY uq_faturas_cobranca_id (cobranca_id);

ALTER TABLE pagamentos
    ADD COLUMN cobranca_id INT UNSIGNED NULL AFTER id,
    ADD UNIQUE KEY uq_pagamentos_cobranca_id (cobranca_id);
