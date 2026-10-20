-- Pack size is how many pieces are in one package. A null pack size
-- leaves lot quantity as entered. empty is the last condiment level.

ALTER TABLE pantry_products
    ADD COLUMN units_per_package INTEGER
        CHECK (units_per_package IS NULL OR (units_per_package >= 1 AND units_per_package <= 10000)),
    ADD COLUMN piece_unit VARCHAR(16);

ALTER TABLE pantry_lots DROP CONSTRAINT pantry_lots_qty_level_check;
ALTER TABLE pantry_lots ADD CONSTRAINT pantry_lots_qty_level_check
    CHECK (qty_level IS NULL OR qty_level IN ('full', 'most', 'half', 'low', 'nearly_empty', 'empty'));
