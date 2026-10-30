-- A season can be allowed outside its dates. Prompt allow-lists stay separate.

ALTER TABLE spotify_season_windows
    ADD COLUMN allow_outside SMALLINT NOT NULL DEFAULT 0 CHECK (allow_outside IN (0, 1));
