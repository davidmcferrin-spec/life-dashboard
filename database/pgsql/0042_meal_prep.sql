-- A prep lot's use-by comes from keeps_days. protein_g is optional grams per serving.

ALTER TABLE pantry_recipes
    ADD COLUMN servings SMALLINT CHECK (servings IS NULL OR (servings >= 1 AND servings <= 30)),
    ADD COLUMN keeps_days SMALLINT NOT NULL DEFAULT 3 CHECK (keeps_days >= 1 AND keeps_days <= 30),
    ADD COLUMN meal_prep SMALLINT NOT NULL DEFAULT 0 CHECK (meal_prep IN (0, 1)),
    ADD COLUMN protein_g NUMERIC(6,1) CHECK (protein_g IS NULL OR (protein_g >= 0 AND protein_g <= 999.9));
