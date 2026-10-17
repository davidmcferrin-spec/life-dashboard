-- A placement lock keeps a widget's column, row, width, and height.
-- Auto-fit and adding another widget leave a locked card where it is.
-- Fit clears the lock.

ALTER TABLE dashboard_widgets
    ADD COLUMN placement_locked SMALLINT NOT NULL DEFAULT 0
    CHECK (placement_locked IN (0, 1));
