-- Goal widgets name one to four metrics. Rewrite a saved single metric_id
-- (and its quick-add amounts) into metric_ids + logs so the card can render
-- without a config edit. New writes already store the list.

UPDATE dashboard_widgets AS w
SET config = jsonb_strip_nulls(jsonb_build_object(
    'metric_ids', jsonb_build_array((w.config->>'metric_id')::bigint),
    'view', COALESCE(NULLIF(w.config->>'view', ''), 'day'),
    'logs', CASE
        WHEN COALESCE(w.config->>'increments', '') <> '' OR COALESCE(w.config->>'dedupe_prefix', '') <> ''
        THEN jsonb_build_object(
            w.config->>'metric_id',
            jsonb_strip_nulls(jsonb_build_object(
                'increments', NULLIF(w.config->>'increments', ''),
                'dedupe_prefix', NULLIF(w.config->>'dedupe_prefix', '')
            ))
        )
        ELSE COALESCE(w.config->'logs', '{}'::jsonb)
    END
))
WHERE w.type = 'goal'
  AND w.config ? 'metric_id'
  AND NOT (w.config ? 'metric_ids')
  AND (w.config->>'metric_id') ~ '^[0-9]+$';
