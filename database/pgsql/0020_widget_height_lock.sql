-- A user resize keeps the widget's height. Unlocked widgets size to their content.
ALTER TABLE dashboard_widgets
    ADD COLUMN height_locked SMALLINT NOT NULL DEFAULT 0
    CHECK (height_locked IN (0, 1));
