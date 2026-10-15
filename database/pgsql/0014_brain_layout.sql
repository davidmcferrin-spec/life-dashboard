-- Saved places for vault pages on the brain map.
-- A page keeps its coordinates until its cluster changes, so the map
-- does not reshuffle when another note is added.

CREATE TABLE page_graph_nodes (
    page_id     BIGINT PRIMARY KEY REFERENCES pages(id) ON DELETE CASCADE,
    user_id     BIGINT       NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    cluster_key TEXT         NOT NULL,
    x           DOUBLE PRECISION NOT NULL,
    y           DOUBLE PRECISION NOT NULL,
    updated_at  TIMESTAMP(0) NOT NULL DEFAULT (now() AT TIME ZONE 'UTC')
);

CREATE INDEX idx_page_graph_nodes_user ON page_graph_nodes(user_id);
