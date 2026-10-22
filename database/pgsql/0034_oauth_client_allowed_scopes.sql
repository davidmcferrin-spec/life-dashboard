-- Permission cap set from the Agents page.
-- NULL means the client has not been edited and consent still follows registration.

ALTER TABLE oauth_clients
    ADD COLUMN allowed_scopes JSONB;
