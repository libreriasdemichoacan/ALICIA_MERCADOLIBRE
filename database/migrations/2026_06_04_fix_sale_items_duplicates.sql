-- Corrige duplicados existentes en sale_items y evita que MySQL permita duplicar
-- registros con variation_id NULL al resincronizar ventas.
DELETE si
FROM sale_items si
JOIN sale_items keep
  ON keep.sale_id = si.sale_id
 AND keep.meli_item_id = si.meli_item_id
 AND COALESCE(keep.variation_id, 0) = COALESCE(si.variation_id, 0)
 AND keep.id < si.id;

ALTER TABLE sale_items
  DROP INDEX uniq_sale_item;

ALTER TABLE sale_items
  ADD COLUMN variation_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(variation_id, 0)) STORED AFTER variation_id,
  ADD UNIQUE KEY uniq_sale_item (sale_id, meli_item_id, variation_key);
