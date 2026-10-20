-- Raise three category seeds. A row that was already edited stays as it is.
-- Produce is unchanged.

UPDATE category_shelf_life
   SET fridge_days = 21, updated_at = (now() AT TIME ZONE 'UTC')
 WHERE category = 'Dairy' AND fridge_days = 7;

UPDATE category_shelf_life
   SET fridge_days = 30, updated_at = (now() AT TIME ZONE 'UTC')
 WHERE category = 'Other' AND fridge_days = 7;

UPDATE category_shelf_life
   SET pantry_days = 7, updated_at = (now() AT TIME ZONE 'UTC')
 WHERE category = 'Bakery' AND pantry_days = 5;
