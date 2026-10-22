-- Playlist origin, ownership, and the last per-playlist sync error.
-- origin stays put on later syncs: synced rows came from Spotify,
-- created and ai_created were made here.

ALTER TABLE spotify_playlists
    ADD COLUMN origin VARCHAR(16) NOT NULL DEFAULT 'synced' CHECK (origin IN ('synced', 'created', 'ai_created')),
    ADD COLUMN is_owned SMALLINT NOT NULL DEFAULT 0 CHECK (is_owned IN (0, 1)),
    ADD COLUMN sync_error TEXT;

UPDATE spotify_playlists
   SET origin = 'ai_created', is_owned = 1
 WHERE managed_by_ai = 1;

UPDATE spotify_playlists
   SET origin = 'created', is_owned = 1
 WHERE managed_by_ai = 0
   AND spotify_id IS NULL;

UPDATE spotify_playlists AS p
   SET is_owned = 1
  FROM spotify_account AS a
 WHERE a.user_id = p.user_id
   AND p.owner_id IS NOT NULL
   AND p.owner_id = a.spotify_user_id;
