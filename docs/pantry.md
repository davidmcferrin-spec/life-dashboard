# Pantry

On-hand food and household lots, with use-by dates. The phone page is `/pantry`. Category shelf lives are edited at `/settings/pantry`. The dashboard widget type is `pantry`.

Shelf-life numbers are reasonable defaults in the style of USDA FoodKeeper; verify and edit. They are not a copy of that database.

## Data

- `pantry_products` — catalog. `upc` is unique per user and null for produce. A null shelf-life column inherits `category_shelf_life` for that aisle. `units_per_package` and `piece_unit` are optional. Bread can be 11 slices, a soda pack 6 bottles. A null pack size leaves the lot quantity as entered.
- `pantry_lots` — a quantity in one place (`pantry`, `fridge`, `freezer`). `use_by_overridden` is 1 when the date was typed in.
- `pantry_events` — append-only `add`, `consume`, `split`, `move`, `open`, `toss`, `adjust`. `by_name` is the signed-in user or the agent token name.
- `pantry_recipes` and `pantry_recipe_ingredients` — an ingredient is one product or one category.
- `pantry_lookup_cache` — shared UPC lookup cache. It has no `user_id` and does not push a realtime event.

The first pantry page or pantry tool call seeds category defaults, common produce (no UPC), and any UPC table on the vault page titled `David — Shopping Preferences` (page 20 is used only when that title is absent). The seed reads the page; it does not ship a guessed UPC list. It runs again when that page's `updated_at` changes, and it does not overwrite a UPC already in the catalog.

## Use-by

- A new lot uses one use-by calculation. Freezer life starts the day the lot is placed. Fridge and pantry life starts on the purchase date. The product's own days win; otherwise the category days are used. A null life leaves `use_by` empty. Zero days on a product means the use-by date is the start date. A typed date sets `use_by_overridden`.
- Moving the whole lot keeps an overridden date. `reset_use_by` clears it and recomputes. A freezer move counts freezer days from the move date. A fridge or pantry move counts that location's days from the purchase date. When the lot is already open and an opened life is known (the product's `opened_life_days`, otherwise the category opened default), that date is `opened_at` plus the opened life. If both the opened date and the location date exist, the earlier one wins. A location with no shelf life, and no opened life, keeps the current date and clears the override.
- A split piece that stays in the same place copies `use_by`, the override, and `opened_at`. A piece that changes place uses the same calculation, keeps `opened_at`, and does not keep the override.
- Opening sets `opened_at` and always recomputes `use_by` with that same rule. A second open is rejected.

Consume matches one product by exact name, a single fuzzy name, UPC, or `lot_id`. It takes on-hand lots soonest `use_by` first and can span lots. Asking for more than is on hand changes nothing. Omitting quantity takes one unit. Quantity may be a fraction. The lot stays `on_hand` while any quantity remains, and quantity at zero marks it `used`. `adjust_lot` with a quantity above zero puts a `used` lot back to `on_hand`. Tossed and expired lots stay closed. A lot with `qty_level` set steps one level per use: full or most to half, half to low, low or nearly empty to empty. Empty sets the level, zeroes the quantity, and marks the lot used. A level-tracked lot still takes a whole number of uses.

`add_pantry_items` and `import_order` expand a packed product when the line omits a unit or uses a package unit (`pkg`, `box`, `bag`, `each`): quantity becomes packages times `units_per_package`, stored in `piece_unit`. A line that already uses the piece unit, such as 2 slices, is stored as that quantity.

Split and move use that piece quantity. A lot of 5 slices can be consumed by 1, then split in one call so 2 stay in the fridge and 2 go to the freezer. The pieces keep the product, unit, purchase date, and opened time. The freezer use-by starts on the move date. The fridge use-by is the purchase date plus the fridge life. An opened piece uses the earlier of that date and the opened life. The pantry page asks for a count in each place, and those counts must add up to the lot.

Units live in `PantryUnits`. `slice`/`slices`, `piece`/`pieces`, and `serving`/`servings` are accepted, along with the older names (`each`, `ct`, `head`, `bunch`, `lb`, `oz`, `g`, `kg`, `gal`, `qt`, `pt`, `ml`, `l`, `pkg`, `bag`, `box`, `can`, `bottle`, `dozen`). `GET /api/v1/pantry/units` and `list_pantry_units` return that list.

The inventory page defaults to on hand. Status `any` is every lot except `tossed`. Omitting status on the API still means `on_hand`. Purge marks the selected on-hand lots `tossed` and writes one `toss` event each. Low stock is on-hand quantity below `min_on_hand`, and also an on-hand lot at low, nearly empty, or empty, and a product whose last lot was used up at empty while nothing is on hand. Pushing that list onto shopping skips a name that already has an unchecked row; it does not add to that row's quantity. The default list is id 1 when it belongs to the user, otherwise the list named Groceries.

`import_order` matches an exact UPC, then an exact unique name. A new name creates a product (`source = order`). Lines that are UPC-only and unknown, ambiguous names, or a name that already has a different UPC come back as `unmatched` and are not guessed.

`what_can_i_make` ranks by how many ingredients the stock covers, then by the soonest `use_by` among the lots that counted. A quantity counts only when the units match.

## Meal builder

The Meals tab on `/pantry` plans from components. It does not replace recipes. Filter categories are protein, base, sauce, and side. Aromatic, vegetable, and spice are suggestions for the current picks. A pick list is optional, and each filter category takes at most two. Pairings are stored once, with the lower component id first. The page reads that suggestion and the component list. Items that do not pair with every pick stay at the bottom of the column.

A component is on hand when its linked product has an active lot with quantity left, or a level other than empty. Not-on-hand items are italic. Only what I have is remembered per user. Save as recipe writes a normal pantry recipe. Add missing to Groceries sends the picks that are not on hand to the Groceries list. There is no meal log. Made before links to a recipe whose protein, base, sauce, and side ingredients are exactly the picks, matched by product or by name. Aromatic, vegetable, spice, and unmapped ingredients are listed under the link as also uses, and they do not decide the match. A recipe with none of the four filter categories is not a match.

`0029_meal_builder.sql` creates `meal_components` and `meal_pairings`. It does not insert food. Claire adds components through MCP. Token writes need Allow MCP updates and `pantry:write`. A signed-in session can still save a recipe and add groceries while that toggle is off. Reads use `pantry:read`.

## Live updates

A committed pantry write publishes on `lifedash_events`. `pantry_products`, `pantry_lots`, `shopping_lists`, and `shopping_items` already had `lifedash_notify()` triggers. `0030_pantry_realtime.sql` adds the same trigger to `pantry_recipes`, `pantry_recipe_ingredients`, `meal_components`, and `meal_pairings`. PostgreSQL delivers the notify when the transaction commits, so a rolled-back write publishes nothing. MCP tools call these same PHP services, so a token write publishes the same way a session write does. The payload is the existing `{table, op, id, user_id}` event. No new API field.

`lifedash-push` fans that event out to the signed-in user's sockets. `/pantry` subscribes and refetches only the open section: lots and products refresh inventory, expiring, products, recipes, and meals; a recipe refresh covers recipes and meals; a component, pairing, or Groceries change refreshes meals. The refetch is debounced. Search text, meal picks, scroll position, open dialogs, and in-progress form fields stay. The scan camera is left alone. If the socket drops, the pantry tab polls every 30 seconds while it is visible and reconnects. The page is not reloaded.

The dashboard pantry card refetches on those same tables, and a Groceries change also refreshes the shopping card. `lifedash-deploy` restarts the realtime push process and applies `0030_pantry_realtime.sql`.

## UPC lookup

Scan uses the browser `BarcodeDetector` API (`upc_a`, `upc_e`, `ean_13`, `ean_8`) when the page is a secure context and the browser has that API. If the API is missing, the page loads the vendored `@zxing/library@0.21.3` UMD build at `public/assets/vendor/zxing/zxing-0.21.3.min.js` and reads the camera. On plain HTTP the browser does not expose the camera, so Scan takes a photo and ZXing reads the barcode from that image. The script integrity is `sha384-BzBxP10ZE72aitqj5UMmUsbKFliP/DZqA8Wq+BNNhlIJDGoEd1tpkMYXOg9+n6sB`. The library is Apache-2.0; its license is `public/assets/vendor/zxing/LICENSE`. It is loaded from this origin because the app content security policy allows scripts from `'self'` only. The same code is ignored for 2.5 seconds. Manual entry is on the same page.

Server lookup order: local products (12-digit and leading-zero 13-digit forms), then Open Food Facts `https://world.openfoodfacts.org/api/v2/product/{upc}.json`, then UPCitemdb trial `https://api.upcitemdb.com/prod/trial/lookup?upc=`. Each call times out at 4 seconds. A hit is cached for 30 days and a definitive miss for 1 day. A transport error is not cached. Name, brand, size, and an https image are copied only from fields the response actually has. A miss leaves the form blank. External lookup does not save a product; the scan sheet does that after confirmation. `get_product_by_upc` stays local unless `external` is true.

## MCP

Category shelf lives are per user. New accounts seed Dairy fridge 21 days, Other fridge 30 days, and Bakery pantry 7 days. `0028_pantry_shelf_defaults.sql` raises existing rows only where those three values are still the old seeds (7, 7, and 5). Produce is left alone. `POST /api/v1/pantry/categories` and `update_pantry_category` edit pantry, fridge, freezer, and opened days. Each value is a whole number from 1 to 3650, or null. `POST /api/v1/pantry/products/{id}` and `update_pantry_product` set the same kind of override on one product so produce can differ from the aisle. Both writes use `pantry:write`, the same scope as the other pantry writes.

`POST /api/v1/pantry/recipes/{id}` and `update_recipe` replace a recipe's name, notes, and ingredients. The recipes page edits that in a dialog.

Scopes `pantry:read` and `pantry:write` appear with the other token areas. Writes are audited as `pantry_lot`, `product`, `recipe`, `pantry_category`, or `meal_component`.

Tools: `list_pantry`, `list_pantry_units`, `get_product_by_upc`, `upsert_product`, `update_pantry_product`, `list_products`, `add_pantry_items`, `consume_pantry_item`, `split_or_move_lot`, `open_lot`, `toss_lot`, `adjust_lot`, `expiring_items`, `low_stock`, `pantry_stats`, `import_order`, `list_recipes`, `upsert_recipe`, `update_recipe`, `update_pantry_category`, `what_can_i_make`, `list_meal_components`, `add_meal_component`, `update_meal_component`, `remove_meal_component`, `add_meal_pairing`, `remove_meal_pairing`, `list_meal_pairings`, `suggest_meal`.

No one-time server step. `lifedash-deploy` applies `database/pgsql/0026_pantry.sql` through `database/pgsql/0030_pantry_realtime.sql`. The catalog fills in on first use. Meal components are not seeded.
