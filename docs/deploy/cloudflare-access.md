# Cloudflare Tunnel and Access for MCP

The dashboard UI stays on the LAN (and UniFi Teleport). The public hostname is only for a cloud assistant that cannot join the home network.

`lifedash-tunnel-setup` publishes two paths and nothing else:

| Path | Where it goes |
| --- | --- |
| `/healthz` | Apache on port 80. Up or down only. |
| `/mcp` | `lifedash-mcp` on port 8765 (`0.0.0.0`; the tunnel still dials `127.0.0.1:8765`) |

Every other path returns 404 at Cloudflare's edge. The UI hostname is not in the tunnel ingress. `cloudflared` connects outbound, so the router does not gain a new open port. `lifedash-mcp` listens on `0.0.0.0`.

```bash
sudo lifedash-tunnel-setup mcp.<your-domain>
```

Treat `https://mcp.<your-domain>/mcp` as internet-reachable.

## Cloudflare Access service token

Access is configured in the Cloudflare dashboard. It is not a line in the ingress file. Put it in front of `/mcp` so a request is rejected before it reaches the app.

1. Zero Trust → Access → Applications → Add an application → self-hosted.
2. Application domain: the tunnel hostname. Path: `/mcp`.
3. Create a **Service token**. Copy the Client ID and Client Secret once.
4. Add a policy: Action Allow, Include Service Token, and select that token. Do not add an Allow-everyone policy on this application.
5. Leave `/healthz` without Access so an uptime check can call it. It returns status only.

The assistant sends two Access headers on every MCP request:

```text
CF-Access-Client-Id: <service token client id>
CF-Access-Client-Secret: <service token secret>
```

Those headers are checked by Cloudflare. They are a different secret from the app token.

## App token (second check)

On the dashboard, Settings → Agents → Access **Money only (90 days)**. That token has `money:read` and `money:write` and no other scope. It expires 90 days from creation unless you pick a date. The plaintext is shown once. Revoke it on that page if a laptop is lost.

The MCP process forwards the call to PHP with:

```text
Authorization: Bearer <app token>
X-Lifedash-Tool: <tool name>
```

A money-only token cannot read pages, habits, files, or investing. Writes are actor `ai`. Reads and writes of `/api/v1/money/` are written to `audit_events` with the tool name and the filter (from, to, year, month, category, account, limit). Filter values that look like a full account number are dropped. Request bodies are not stored. The creator-subscription name is never a stored value; the importer replaces it with `Creator subscription` before the row is written.

The MCP process also limits each bearer token to about 60 tool calls per minute. The 61st call is a JSON-RPC error and is not forwarded to PHP. The limit key is a hash of the token. The raw token is not logged.

## What not to give the assistant

- Do not put `DATABASE_URL` or a shell on the server in the assistant's config.
- Do not log MCP request bodies in Cloudflare. Access should see the path, not the JSON. In Zero Trust → Settings → App sessions / logs, keep body capture off for this application.
- Do not copy money rows to a hosted notebook, a second cloud database, or back to the Google Sheet on a schedule.
- Tailscale is for machines you own. Do not publish the MCP port with Tailscale Funnel.
