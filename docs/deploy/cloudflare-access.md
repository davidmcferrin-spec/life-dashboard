# Cloudflare Tunnel and MCP OAuth

Two hostnames. `dash.<domain>` is the PHP app (pages, `/ws`, OAuth consent, token, and registration). `mcp.<domain>` is `lifedash-mcp` on `127.0.0.1:8765`, plus the OAuth discovery documents. Anything else on the MCP hostname is 404 at the edge.

`cloudflared` connects outbound. The router does not gain a new open port. The LAN UI on the server's own HTTPS name keeps working, including by IP.

```bash
cloudflared tunnel login
cloudflared tunnel create lifedash
cloudflared tunnel route dns lifedash dash.<your-domain>
cloudflared tunnel route dns lifedash mcp.<your-domain>
sudo lifedash-tunnel-setup dash.<your-domain> mcp.<your-domain>
sudo lifedash-env set PUBLIC_BASE_URL https://dash.<your-domain>
sudo lifedash-env set MCP_PUBLIC_URL https://mcp.<your-domain>/mcp
sudo systemctl restart lifedash-mcp
```

The script installs `cloudflared.service` from `deploy/systemd/cloudflared.service` and writes `/etc/cloudflared/config.yml`. The credentials file path is a line in that config (`credentials-file`). The file itself stays in `/etc/cloudflared/` and is not committed.

Claude custom connector URL:

```text
https://mcp.<your-domain>/mcp
```

Claude discovers OAuth from that origin, registers a public client, and sends the browser to `https://dash.<your-domain>/oauth/authorize`. Sign in with the dashboard account and pick the scopes. Access tokens are short-lived. Refresh tokens rotate. Settings → Agents lists the client and can revoke it.

Do not put a Cloudflare Access service-token policy on `/mcp` for this connector. Claude's browser and token calls do not send those headers. A static bearer token on Settings → Agents still works for an existing bot and does not need OAuth.

`MCP_ALLOW_PLAIN_HTTP` defaults to true. Settings → Agents, **Allow plain HTTP MCP access (LAN IP)**, stores an override in `server_flags` (`mcp_plain_http`). A missing row keeps the config value. The page shows the effective state and whether the config file or that row set it. Each token and OAuth client shows the last hop, HTTP or HTTPS, so you can see that a client has moved before you turn plain HTTP off. The switch is a dashboard form. A bearer token cannot change it.

Off means a direct `http://<ip>:8765` JSON-RPC call gets HTTP 403 and the message names `MCP_PUBLIC_URL`. The process keeps listening, so the client sees that URL. `/healthz` stays open for local checks. Tunnel traffic is loopback plus `X-Forwarded-Proto: https` and is still accepted. The change applies within a couple of seconds. It does not restart the service and it does not change the bind.

`MCP_BIND` defaults to `all` (`0.0.0.0`). `MCP_HOST` is ignored. Leave the bind on `all` until you also want the LAN port closed, then:

```bash
sudo lifedash-env set MCP_BIND loopback
sudo systemctl restart lifedash-mcp
```

The tunnel still dials `127.0.0.1:8765`. Writes from an OAuth client honor the same Allow MCP updates switch as a static token.
