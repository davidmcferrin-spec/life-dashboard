-- Last MCP transport seen for a static token or an OAuth client.
-- NULL until lifedash-mcp forwards a loopback call with X-Lifedash-Transport.
-- The plain-HTTP switch is a server_flags row (mcp_plain_http), not a column.
-- A missing row leaves MCP_ALLOW_PLAIN_HTTP in charge.

ALTER TABLE api_tokens
    ADD COLUMN last_transport VARCHAR(8);

ALTER TABLE api_tokens
    ADD CONSTRAINT api_tokens_last_transport_check
    CHECK (last_transport IS NULL OR last_transport IN ('http', 'https'));

ALTER TABLE oauth_clients
    ADD COLUMN last_transport VARCHAR(8);

ALTER TABLE oauth_clients
    ADD CONSTRAINT oauth_clients_last_transport_check
    CHECK (last_transport IS NULL OR last_transport IN ('http', 'https'));
