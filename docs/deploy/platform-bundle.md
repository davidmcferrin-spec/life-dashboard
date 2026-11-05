# Platform bundle

Permissions, the agents fleet, the morning brief, the link graph, and health. Nothing in this bundle transfers money. `propose_envelope_assignment` still returns a proposal with `moves_money` false.

## Permissions

Sectors are money, home, mail, agents, and health. Each API token and each OAuth client starts at deny. Settings → Permissions sets deny, allow once, or allow always.

Allow once is spent after one successful act and returns to deny. Allow always does not ask again. Every allowed act, and every denial, is written to the audit log.

Money reads and changes, health reads and changes, and live Home Assistant reads fail closed with `needs-grant` until that key is granted. Agent status can be read without a grant. Sending, stopping, and OpenClaw ask cannot. Mail has no mailbox yet. `mail_status` says so.

Creating a token does not grant a sector. Health writes and money movement are never granted by another save.

## Agents

`/agents` is the fleet page for an admin session: Hermes status, sessions, send, poll, and stop; OpenCode status only; OpenClaw ask. Stop is shown only as far as the Hermes gateway supports cancel. If it answers 404, 405, or 501, the page says stop is not supported.

A connecting key needs an agents grant for those changes.

## Morning brief

`/brief` has Brief me, a short history, and an optional daily time. The job is:

```cron
*/15 * * * * php /path/to/life-dashboard/cron/morning_brief.php
```

It runs after the saved local time, once per day. The brief uses stored digests, payday pressure, Home Assistant when that connection exists, Spotify when a track is actually playing, and health readiness from stored vitals. A calendar is not connected on this server, and the brief says that. It does not invent a balance or a vital.

## Link graph

Pages, bills, envelopes, digests, health days, and briefs live in `graph_nodes`. Edges are wikilinks, mentions in briefs and digests, bill or envelope names found in a page, and manual links. `graph_query` and `graph_neighbors` read them. There is no Neo4j.

## Health

David's watches sync through [Open Wearables](https://github.com/the-momentum/open-wearables). Life-Dashboard does not perform Samsung OAuth. The phone SDK (Samsung Health and Health Connect) uploads to Open Wearables, and this app reads that API.

Install Open Wearables beside the dashboard. It is its own process:

```bash
git clone https://github.com/the-momentum/open-wearables.git
cd open-wearables
make init
docker compose up -d
```

Set `ADMIN_PASSWORD` before the first start. In the developer portal, create an API key and a user. Put Samsung Health and Health Connect above Garmin, WHOOP, and Oura in that portal's data priority. The schema here already accepts those later providers when a day arrives from more than one source. Samsung, then Health Connect, wins.

Settings → Open Wearables stores the base URL, the user UUID, and the API key. The key is encrypted in `system_settings`. It is not written to `.env`. Leave the key field blank to keep the saved key.

Health → Sync Open Wearables reads, for the last seven days:

- `GET /api/v1/users/{id}/summaries/sleep`
- `GET /api/v1/users/{id}/summaries/activity`
- `GET /api/v1/users/{id}/events/workouts`

Sleep minutes, HRV (RMSSD, or SDNN when RMSSD is absent), steps, and resting heart rate are stored only when Open Wearables sent a number. Readiness is the average of the inputs that exist. Sleep scores 100 between 7 and 9 hours. Steps score 100 at 8,000. HRV scores against the median of at least three earlier days, and is left out until that baseline exists. Confidence is how many of sleep, HRV, and steps were used, divided by three.

Manual workout, meal, and body logs stay on this server with authority `manual`. They do not call Samsung. The daily health line is stored and shown on Grok Digest and inside the morning brief.

MCP names: `health_status`, `health_readiness`, `health_sleep`, `health_workouts`, `health_metrics`, `health_sync`, `health_log_workout`, `health_log_meal`, `health_log_body`. Each needs a health grant on the calling key.
