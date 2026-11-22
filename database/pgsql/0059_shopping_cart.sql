-- Open shopping rows can be told apart by a caller key, not only by name and unit.
-- source, needed_by, and order_wave are optional. A deferred wave stays out of the next cart.
-- Notes are copied into the new columns only when the whole note is a source line
-- and at most one hyphenated key. Anything else is left alone, and notes are never rewritten.

ALTER TABLE shopping_items
    ADD COLUMN source VARCHAR(64),
    ADD COLUMN dedupe_key VARCHAR(160),
    ADD COLUMN needed_by DATE,
    ADD COLUMN order_wave VARCHAR(80);

WITH trimmed AS (
    SELECT
        id,
        list_id,
        bought,
        regexp_replace(notes, '^[[:space:]]+|[[:space:]]+$', '', 'g') AS note
    FROM shopping_items
    WHERE notes IS NOT NULL
),
extracted AS (
    SELECT
        id,
        list_id,
        bought,
        CASE
            WHEN note ~* '^source[[:space:]]*:[[:space:]]*ai[[:space:]]*$'
              OR note ~* '^source[[:space:]]*:[[:space:]]*ai[[:space:],;|]+(?:(?:dedupe_key|dedupe|key)[[:space:]]*:[[:space:]]*)?[a-z0-9]+(?:-[a-z0-9]+)+[[:space:]]*$'
            THEN 'ai'
        END AS new_source,
        CASE
            WHEN note ~* '^source[[:space:]]*:[[:space:]]*ai[[:space:],;|]+(?:(?:dedupe_key|dedupe|key)[[:space:]]*:[[:space:]]*)?[a-z0-9]+(?:-[a-z0-9]+)+[[:space:]]*$'
              OR note ~* '^(?:(?:dedupe_key|dedupe|key)[[:space:]]*:[[:space:]]*)?[a-z0-9]+(?:-[a-z0-9]+)+[[:space:]]*$'
            THEN lower((regexp_match(note, '([a-z0-9]+(?:-[a-z0-9]+)+)', 'i'))[1])
        END AS new_key
    FROM trimmed
    WHERE note <> ''
),
open_dupes AS (
    SELECT list_id, new_key
    FROM extracted
    WHERE bought = 0 AND new_key IS NOT NULL
    GROUP BY list_id, new_key
    HAVING COUNT(*) > 1
)
UPDATE shopping_items AS s
SET
    source = COALESCE(s.source, e.new_source),
    dedupe_key = CASE
        WHEN s.dedupe_key IS NOT NULL THEN s.dedupe_key
        WHEN e.new_key IS NULL THEN s.dedupe_key
        WHEN s.bought = 0 AND EXISTS (
            SELECT 1 FROM open_dupes d WHERE d.list_id = s.list_id AND d.new_key = e.new_key
        ) THEN s.dedupe_key
        ELSE e.new_key
    END
FROM extracted e
WHERE s.id = e.id
  AND (
        (s.source IS NULL AND e.new_source IS NOT NULL)
        OR (
            s.dedupe_key IS NULL
            AND e.new_key IS NOT NULL
            AND NOT (
                s.bought = 0 AND EXISTS (
                    SELECT 1 FROM open_dupes d WHERE d.list_id = s.list_id AND d.new_key = e.new_key
                )
            )
        )
    );

CREATE UNIQUE INDEX uq_shopping_items_open_dedupe
    ON shopping_items (list_id, dedupe_key)
    WHERE bought = 0 AND dedupe_key IS NOT NULL;
