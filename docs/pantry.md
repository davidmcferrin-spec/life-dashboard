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

- A new lot uses the purchase date plus the shelf life for its location. The product's own days win; otherwise the category days are used. A null life leaves `use_by` empty. Zero days means the use-by date is the start date. A typed date sets `use_by_overridden`.
- Moving the whole lot keeps an overridden date. `reset_use_by` clears it. A freezer move then counts freezer days from the move date. A fridge or pantry move counts that location's days from the purchase date. A location with no shelf life keeps the current date and clears the override.
- A split piece that stays in the same place copies `use_by`, the override, and `opened_at`. A piece that changes place uses the move rule above and does not keep the override, so a fridge pack can stay due soon while the frozen piece is dated from the day it went into the freezer.
- Opening sets `opened_at`. `use_by` becomes the earlier of the current date and the open date plus opened life. A date that does not move keeps its override. A second open is rejected.

Consume matches one product by exact name, a single fuzzy name, UPC, or `lot_id`. It takes on-hand lots soonest `use_by` first and can span lots. Asking for more than is on hand changes nothing. Omitting quantity takes one unit. Quantity zero marks the lot `used`. A lot with `qty_level` set steps one level per use: full or most to half, half to low, low or nearly empty to empty. Empty sets the level, zeroes the quantity, and marks the lot used.

`add_pantry_items` and `import_order` expand a packed product when the line omits a unit or uses a package unit (`pkg`, `box`, `bag`, `each`): quantity becomes packages times `units_per_package`, stored in `piece_unit`. A line that already uses the piece unit, such as 2 slices, is stored as that quantity.

Split and move use that piece quantity. A lot of 5 slices can be consumed by 1, then split in one call so 2 stay in the fridge and 2 go to the freezer. The pieces keep the product, unit, and purchase date. The freezer use-by starts on the move date. The fridge use-by is the purchase date plus the fridge life. The pantry page asks for a count in each place, and those counts must add up to the lot.

Units live in `PantryUnits`. `slice`/`slices`, `piece`/`pieces`, and `serving`/`servings` are accepted, along with the older names (`each`, `ct`, `head`, `bunch`, `lb`, `oz`, `g`, `kg`, `gal`, `qt`, `pt`, `ml`, `l`, `pkg`, `bag`, `box`, `can`, `bottle`, `dozen`). `GET /api/v1/pantry/units` and `list_pantry_units` return that list.

The inventory page defaults to on hand. Status `any` is every lot except `tossed`. Omitting status on the API still means `on_hand`. Purge marks the selected on-hand lots `tossed` and writes one `toss` event each. Low stock is on-hand quantity below `min_on_hand`, and also an on-hand lot at low, nearly empty, or empty, and a product whose last lot was used up at empty while nothing is on hand. Pushing that list onto shopping skips a name that already has an unchecked row; it does not add to that row's quantity. The default list is id 1 when it belongs to the user, otherwise the list named Groceries.

`import_order` matches an exact UPC, then an exact unique name. A new name creates a product (`source = order`). Lines that are UPC-only and unknown, ambiguous names, or a name that already has a different UPC come back as `unmatched` and are not guessed.

`what_can_i_make` ranks by how many ingredients the stock covers, then by the soonest `use_by` among the lots that counted. A quantity counts only when the units match.

## UPC lookup

Scan uses the browser `BarcodeDetector` API (`upc_a`, `upc_e`, `ean_13`, `ean_8`) when the page is a secure context and the browser has that API. If the API is missing, the page loads the vendored `@zxing/library@0.21.3` UMD build at `public/assets/vendor/zxing/zxing-0.21.3.min.js` and reads the camera. On plain HTTP the browser does not expose the camera, so Scan takes a photo and ZXing reads the barcode from that image. The script integrity is `sha384-BzBxP10ZE72aitqj5UMmUsbKFliP/DZqA8Wq+BNNhlIJDGoEd1tpkMYXOg9+n6sB`. The library is Apache-2.0; its license is `public/assets/vendor/zxing/LICENSE`. It is loaded from this origin because the app content security policy allows scripts from `'self'` only. The same code is ignored for 2.5 seconds. Manual entry is on the same page.

Server lookup order: local products (12-digit and leading-zero 13-digit forms), then Open Food Facts `https://world.openfoodfacts.org/api/v2/product/{upc}.json`, then UPCitemdb trial `https://api.upcitemdb.com/prod/trial/lookup?upc=`. Each call times out at 4 seconds. A hit is cached for 30 days and a definitive miss for 1 day. A transport error is not cached. Name, brand, size, and an https image are copied only from fields the response actually has. A miss leaves the form blank. External lookup does not save a product; the scan sheet does that after confirmation. `get_product_by_upc` stays local unless `external` is true.

## MCP

Scopes `pantry:read` and `pantry:write` appear with the other token areas. Writes are audited as `pantry_lot`, `product`, `recipe`, or `pantry_category`.

Tools: `list_pantry`, `list_pantry_units`, `get_product_by_upc`, `upsert_product`, `list_products`, `add_pantry_items`, `consume_pantry_item`, `split_or_move_lot`, `open_lot`, `toss_lot`, `adjust_lot`, `expiring_items`, `low_stock`, `pantry_stats`, `import_order`, `list_recipes`, `upsert_recipe`, `what_can_i_make`.

No one-time server step. `lifedash-deploy` applies `database/pgsql/0026_pantry.sql` and `database/pgsql/0027_pantry_pack.sql`. The catalog fills in on first use.
