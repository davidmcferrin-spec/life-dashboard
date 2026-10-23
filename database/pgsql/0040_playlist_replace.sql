-- replace was staged by the app but rejected by the op check, so the request 500'd.

ALTER TABLE spotify_playlist_changes DROP CONSTRAINT spotify_playlist_changes_op_check;

ALTER TABLE spotify_playlist_changes ADD CONSTRAINT spotify_playlist_changes_op_check
    CHECK (op IN ('add', 'remove', 'reorder', 'replace', 'rename', 'describe', 'create', 'delete'));
