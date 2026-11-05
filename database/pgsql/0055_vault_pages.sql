-- Nested vault pages, favorites, and last-opened.
-- Existing pages stay at the root (parent_id null), unfavorited, and unopened.
-- Saved versions are not rewritten here.
-- Wikilinks stay in the link table the graph already reads.

ALTER TABLE pages
    ADD COLUMN parent_id BIGINT REFERENCES pages(id) ON DELETE SET NULL,
    ADD COLUMN favorited_at TIMESTAMP(0),
    ADD COLUMN last_opened_at TIMESTAMP(0);

ALTER TABLE pages
    ADD CONSTRAINT pages_parent_not_self CHECK (parent_id IS NULL OR parent_id <> id);

CREATE INDEX idx_pages_parent
    ON pages (user_id, parent_id)
    WHERE archived_at IS NULL;

CREATE INDEX idx_pages_favorite
    ON pages (user_id, favorited_at DESC)
    WHERE favorited_at IS NOT NULL AND archived_at IS NULL;

CREATE INDEX idx_pages_opened
    ON pages (user_id, last_opened_at DESC)
    WHERE last_opened_at IS NOT NULL AND archived_at IS NULL;
