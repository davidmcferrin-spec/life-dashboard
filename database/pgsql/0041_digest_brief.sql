-- The parsed note stays in summary. brief is the one- or two-sentence line, written once.

ALTER TABLE digest_entries
    ADD COLUMN brief TEXT NOT NULL DEFAULT '',
    ADD COLUMN brief_kind VARCHAR(8) NOT NULL DEFAULT ''
        CHECK (brief_kind IN ('', 'ollama', 'excerpt'));
