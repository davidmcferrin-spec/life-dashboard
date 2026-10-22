# Cloudflare Tunnel and MCP OAuth

Two hostnames. `dash.<domain>` is the PHP app (pages, `/ws`, OAuth consent, token, and registration). `mcp.<domain>` is `lifedash-mcp` on `127.0.0.1:8765`, plus the OAuth discovery documents. Anything else on the MCP hostname is 404 at the edge.

`cloudflared` connects outbound. The router does not gain a new open port. The LAN UI on the server's own HTTPS name keeps working, including by IP.

Create a remotely managed tunnel in Cloudflare Zero Trust and copy its token. Signed in as an admin:

1. **Settings → Setup → Public URLs.** Dashboard URL `https://dash.<your-domain>`. MCP URL `https://mcp.<your-domain>/mcp`. **Save**, then **Restart MCP service**.
2. **Settings → Tunnel.** Paste the token. Dashboard hostname `dash.<your-domain>`. MCP hostname `mcp.<your-domain>`. **Save**. Copy these public hostname routes into Zero Trust:
   - `dash.<your-domain>` → `http://localhost:80`
   - `mcp.<your-domain>` → `http://localhost:8765`

   **Start**. Status reads Connected or Not connected.

The helper writes the token to `/etc/lifedash/cloudflared.token` (root, mode 0600) and starts `cloudflared.service` from `deploy/systemd/cloudflared.service`. A tunnel that already has `/etc/cloudflared/config.yml` still starts when no token is saved. That file is not committed.

Claude custom connector URL:

```text
https://mcp.<your-domain>/mcp
```

Claude discovers OAuth from that origin, registers a public client, and sends the browser to `https://dash.<your-domain>/oauth/authorize`. Sign in with the dashboard account and pick the scopes. Access tokens are short-lived. Refresh tokens rotate. Settings → Agents lists the client and can revoke it.

Do not put a Cloudflare Access service-token policy on `/mcp` for this connector. Claude's browser and token calls do not send those headers. A static bearer token on Settings → Agents still works for an existing bot and does not need OAuth.

Settings → Agents, **Allow plain HTTP MCP access (LAN IP)**, stores the switch in `server_flags` (`mcp_plain_http`). With no row saved, plain HTTP stays allowed. The page shows On or Off. Each token and OAuth client shows the last hop, HTTP or HTTPS, so you can see that a client has moved before you turn plain HTTP off. The checkbox is the switch. A bearer token cannot change it.

Off means a direct `http://<ip>:8765` JSON-RPC call gets HTTP 403 and the message names the MCP URL. The process keeps listening, so the client sees that URL. `/healthz` stays open for local checks. Tunnel traffic is loopback plus `X-Forwarded-Proto: https` and is still accepted. The change applies within a couple of seconds. It does not restart the service and it does not change the bind.

MCP bind defaults to all interfaces (`0.0.0.0`). `MCP_HOST` is ignored. Leave the bind on **All interfaces** until you also want the LAN port closed. Then Settings → Setup → MCP bind → **Loopback only**, **Save**, and **Restart MCP service**. The tunnel still dials `127.0.0.1:8765`. Writes from an OAuth client honor the same Allow MCP updates switch as a static token.
