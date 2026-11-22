-- Local source and dedupe key for playlists created here.
-- A repeated dedupe_key updates that user's playlist instead of inserting another.
-- Synced and followed playlists leave both columns null.

ALTER TABLE spotify_playlists
    ADD COLUMN source VARCHAR(64),
    ADD COLUMN dedupe_key VARCHAR(160);

CREATE UNIQUE INDEX idx_spotify_playlists_dedupe
    ON spotify_playlists (user_id, dedupe_key)
    WHERE dedupe_key IS NOT NULL;
