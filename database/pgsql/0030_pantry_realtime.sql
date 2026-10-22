-- Recipes and meal rows were not on lifedash_events, so a committed
-- write never reached an open pantry page. Lots, products, and shopping
-- lists already notify. NOTIFY is delivered when the transaction commits.

CREATE TRIGGER trg_pantry_recipes_notify
    AFTER INSERT OR UPDATE OR DELETE ON pantry_recipes
    FOR EACH ROW EXECUTE FUNCTION lifedash_notify();

CREATE TRIGGER trg_pantry_recipe_ingredients_notify
    AFTER INSERT OR UPDATE OR DELETE ON pantry_recipe_ingredients
    FOR EACH ROW EXECUTE FUNCTION lifedash_notify();

CREATE TRIGGER trg_meal_components_notify
    AFTER INSERT OR UPDATE OR DELETE ON meal_components
    FOR EACH ROW EXECUTE FUNCTION lifedash_notify();

CREATE TRIGGER trg_meal_pairings_notify
    AFTER INSERT OR UPDATE OR DELETE ON meal_pairings
    FOR EACH ROW EXECUTE FUNCTION lifedash_notify();
