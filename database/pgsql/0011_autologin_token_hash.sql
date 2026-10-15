-- =====================================================================
-- Auto-login links are secrets. Store SHA-256 only, the same way api_tokens
-- does. Existing rows are hashed in place, so a link the owner already has
-- (/auto/{64-hex}) still signs them in. The plaintext column is then dropped.
-- pgcrypto is a trusted extension; the database owner can create it.
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;

ALTER TABLE autologin_tokens ADD COLUMN token_hash CHAR(64);

UPDATE autologin_tokens
   SET token_hash = encode(digest(token, 'sha256'), 'hex')
 WHERE token_hash IS NULL;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM autologin_tokens
        WHERE token_hash IS NULL OR token_hash !~ '^[0-9a-f]{64}$'
    ) THEN
        RAISE EXCEPTION 'autologin token hash migration left a row unhashed';
    END IF;
END $$;

ALTER TABLE autologin_tokens ALTER COLUMN token_hash SET NOT NULL;
ALTER TABLE autologin_tokens ADD CONSTRAINT autologin_tokens_token_hash_key UNIQUE (token_hash);
ALTER TABLE autologin_tokens DROP COLUMN token;
