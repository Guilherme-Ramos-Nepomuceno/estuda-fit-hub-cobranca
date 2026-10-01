TRUNCATE webhook_eventos; TRUNCATE pagamentos; TRUNCATE outbox;
DELETE FROM faturas WHERE competencia = '2026-10-01';
UPDATE faturas SET
  status = CASE WHEN CAST(SUBSTRING(gateway_ref, 4) AS UNSIGNED) <= 250000 THEN 'aberta'
                WHEN CAST(SUBSTRING(gateway_ref, 4) AS UNSIGNED) % 20 = 0 THEN 'vencida' ELSE 'paga' END,
  pago_em = CASE WHEN CAST(SUBSTRING(gateway_ref, 4) AS UNSIGNED) > 250000 AND CAST(SUBSTRING(gateway_ref, 4) AS UNSIGNED) % 20 <> 0 THEN '2026-01-01' ELSE NULL END,
  updated_at = NULL
WHERE updated_at IS NOT NULL;
