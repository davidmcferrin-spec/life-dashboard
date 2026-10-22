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

`MCP_BIND` defaults to `all` (`0.0.0.0`). `MCP_HOST` is ignored. Leave the bind on `all` until every bot that uses `http://<lan-ip>:8765/mcp` has moved to the tunnel, then:

```bash
sudo lifedash-env set MCP_BIND loopback
sudo systemctl restart lifedash-mcp
```

The tunnel still dials `127.0.0.1:8765`. Writes from an OAuth client honor the same Allow MCP updates switch as a static token.
