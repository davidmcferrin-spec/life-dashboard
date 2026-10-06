-- =====================================================================
-- Indexes PostgreSQL does not create automatically for foreign keys,
-- plus the hot paths used by the dashboard widgets and cron pollers.
-- (SQLite never had these; at personal scale they mostly matter for
-- ON DELETE CASCADE performance and feed_cache, which grows daily.)
-- =====================================================================

CREATE INDEX idx_autologin_tokens_user_id        ON autologin_tokens(user_id);
CREATE INDEX idx_bookmark_folders_user_id        ON bookmark_folders(user_id);
CREATE INDEX idx_bookmarks_user_id               ON bookmarks(user_id);
CREATE INDEX idx_bookmarks_folder_id             ON bookmarks(folder_id);
CREATE INDEX idx_user_feeds_source_id            ON user_feeds(source_id);
CREATE INDEX idx_feed_cache_source_recent        ON feed_cache(source_id, (COALESCE(published_at, fetched_at)) DESC);
CREATE INDEX idx_user_flight_watches_user_id     ON user_flight_watches(user_id);
CREATE INDEX idx_user_ping_targets_user_id       ON user_ping_targets(user_id);
CREATE INDEX idx_clipboard_share_links_clip_id   ON clipboard_share_links(clipboard_id);
CREATE INDEX idx_clipboard_user_shares_with      ON clipboard_user_shares(shared_with_user_id);
CREATE INDEX idx_user_package_watches_user_id    ON user_package_watches(user_id);
CREATE INDEX idx_user_files_user_id              ON user_files(user_id);
CREATE INDEX idx_file_share_links_file_id        ON file_share_links(file_id);
CREATE INDEX idx_file_user_shares_with           ON file_user_shares(shared_with_user_id);
CREATE INDEX idx_user_storage_nodes_user_id      ON user_storage_nodes(user_id);
CREATE INDEX idx_storage_node_share_links_node   ON storage_node_share_links(storage_node_id);
CREATE INDEX idx_storage_node_user_shares_with   ON storage_node_user_shares(shared_with_user_id);
CREATE INDEX idx_user_todo_items_list_status     ON user_todo_items(list_id, status);
CREATE INDEX idx_user_todo_items_assigned_to     ON user_todo_items(assigned_to_user_id);
CREATE INDEX idx_todo_list_user_shares_with      ON todo_list_user_shares(shared_with_user_id);
CREATE INDEX idx_cloudpad_share_links_canvas     ON cloudpad_share_links(canvas_id);
CREATE INDEX idx_cloudpad_user_shares_with       ON cloudpad_user_shares(shared_with_user_id);
