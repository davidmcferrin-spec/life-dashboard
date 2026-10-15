-- Goal progress: number metrics already store config.goal. A habit may name
-- the metric that checks it off (goal_metric_id) and the day's last value
-- that counts (goal_threshold). No new columns.
--
-- Backfill the water pair when it is already on this database: water_ml gets
-- a goal of 3000 ml when it has none, and the Water habit (key water, or
-- id 13 when that row is a habit) links to water_ml (key water_ml, or id 17
-- when that row is a number metric). Same user only. Key match wins when
-- both rules hit one habit.

UPDATE metrics
   SET config = config || jsonb_build_object('goal', 3000),
       updated_at = (now() AT TIME ZONE 'UTC')
 WHERE kind = 'number'
   AND (config->>'goal') IS NULL
   AND (key = 'water_ml' OR id = 17);

UPDATE metrics
   SET unit = 'ml',
       updated_at = (now() AT TIME ZONE 'UTC')
 WHERE kind = 'number'
   AND key = 'water_ml'
   AND (unit IS NULL OR btrim(unit) = '');

UPDATE metrics AS habit
   SET config = habit.config || jsonb_build_object(
           'goal_metric_id', picked.id,
           'goal_threshold', COALESCE((picked.config->>'goal')::numeric, 3000)
       ),
       updated_at = (now() AT TIME ZONE 'UTC')
  FROM (
        SELECT DISTINCT ON (h.id)
               h.id AS habit_id,
               m.id,
               m.config
          FROM metrics h
          JOIN metrics m
            ON m.user_id = h.user_id
           AND m.kind = 'number'
         WHERE h.kind = 'habit'
           AND (
                (h.key = 'water' AND m.key = 'water_ml')
                OR (h.id = 13 AND m.id = 17)
           )
         ORDER BY h.id,
                  CASE WHEN h.key = 'water' AND m.key = 'water_ml' THEN 0 ELSE 1 END,
                  m.id
       ) AS picked
 WHERE habit.id = picked.habit_id;
