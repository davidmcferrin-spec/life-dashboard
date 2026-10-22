-- Local model settings. One row for the machine. The service itself stays on loopback.

CREATE TABLE ollama_settings (
    id          SMALLINT     PRIMARY KEY DEFAULT 1,
    base_url    TEXT         NOT NULL DEFAULT 'http://127.0.0.1:11434',
    chat_model  VARCHAR(80)  NOT NULL DEFAULT '',
    embed_model VARCHAR(80)  NOT NULL DEFAULT 'nomic-embed-text',
    updated_at  TIMESTAMP(0) NOT NULL DEFAULT (now() AT TIME ZONE 'UTC'),
    CONSTRAINT ollama_settings_one CHECK (id = 1)
);

INSERT INTO ollama_settings (id) VALUES (1);
