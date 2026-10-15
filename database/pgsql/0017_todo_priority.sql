-- Priority on an existing task. Notes already live on user_todo_items.
-- 1 is the highest priority. Null means the task has no priority set.

ALTER TABLE user_todo_items
    ADD COLUMN priority SMALLINT;

ALTER TABLE user_todo_items
    ADD CONSTRAINT user_todo_items_priority_chk CHECK (priority IS NULL OR priority BETWEEN 1 AND 5);
