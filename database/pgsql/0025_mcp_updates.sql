-- Server-wide switch for MCP deploy_update. Absent or 0 means off.
-- An admin session on Settings → System is the only writer.
-- The root spool runner reads this row and refuses a token request when it is off.

CREATE TABLE server_flags (
    flag_key           VARCHAR(64)  PRIMARY KEY,
    enabled            SMALLINT     NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)),
    updated_at         TIMESTAMP(0) NOT NULL DEFAULT (now() AT TIME ZONE 'UTC'),
    updated_by_user_id BIGINT       REFERENCES users(id) ON DELETE SET NULL
);
