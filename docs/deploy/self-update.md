# Update the server from MCP

`lifedash-mcp` listens on `0.0.0.0:8765`. A token that can call it must not be able to run a shell or pick an arbitrary git ref. The deploy tools only queue an update of `main`.

PHP and the MCP process stay unprivileged. They write a request file. `lifedash-deploy.path` starts `lifedash-deploy.service`, which runs `deploy/bin/lifedash-deploy-from-spool` as root. That script fetches `origin main`, refuses the job unless the requested commit is exactly `origin/main`, then runs `lifedash-deploy --confirm-sha`. The existing pre-migration `pg_dump` still runs before migrations.

The tool returns as soon as the file is queued. The deploy restarts `lifedash-mcp`, so the caller polls `deploy_status` and `deploy_log`.

## Tools

| Tool | Scope | What it does |
| --- | --- | --- |
| `deploy_status` | `system:read` | Checked-out commit, `origin/main`, commits behind (sha and title), pending migrations, last result |
| `deploy_log` | `system:read` | Last run's output, or one job id |
| `deploy_update` | `system:deploy` | Queue the update. `confirm_sha` is required and must equal the `origin/main` from `deploy_status`. `dry_run` does not queue anything |

`system:deploy` can also call the two read tools. `system:read` cannot update.

## Allow MCP updates

Settings → System has **Allow MCP updates**. It is off until an admin turns it on. The value is a row in `server_flags` (`flag_key = mcp_updates`). Changing it writes an audit event.

The three tools stay registered either way. While the switch is off:

- `deploy_status` and `deploy_log` still work. Status includes `mcp_updates: false`.
- `deploy_update`, including a dry run, returns `MCP updates are disabled in Settings`.
- The root spool runner reads the same row and rejects a request that carries a token id, so a file dropped past PHP does not deploy.

**Update now** on Settings → System is an admin session. It does not carry a token id, and it still queues when the switch is off.

`read`, `write`, and `*` do not grant either scope. `system:write` is not a scope. Settings → Agents shows the two checkboxes only for an admin, and the token API rejects them from anyone else. Existing tokens stay unchanged.

`confirm_sha` is 40 lowercase hex digits. The request accepts no ref, path, or command. A second check on the root side fetches again, so a commit that moved after the tool call is not deployed. The checkout must be on `main`.

One deploy can be queued at a time. A finished, failed, or running deploy blocks the next one for 5 minutes. A rejected confirm does not. `flock` stops two root runs from overlapping. Dry run does not count toward the limit.

Every status, log, update, and rejected update is an audit event. Bearer calls include the token id and agent name. The browser button is actor `user`.

Settings → System has **Dry run** and **Update now** for an admin session. Same confirm rule, same spool.

## One-time steps on the server

The copy of `lifedash-deploy` already on the server does not install this unit until it has been replaced. Run the update twice:

```bash
sudo lifedash-deploy
sudo lifedash-deploy --no-pull
```

The first command fast-forwards the checkout. The second command is the new script. It creates the spool, installs the units, refreshes the PHP-FPM `open_basedir` (so PHP can write the spool and run `/usr/bin/git`), enables `lifedash-deploy.path`, and reloads PHP-FPM.

Fresh installs get the same pieces from `setup.sh` / `provision.sh`.

Check:

```bash
systemctl status lifedash-deploy.path
ls -ld /var/lib/lifedash/deploy/incoming /var/lib/lifedash/deploy/state
```

`incoming` is `root:lifedash` mode `3770` (the app user can add a request, not rewrite another user's file). `state` is `root:lifedash` mode `0750` (the app user can read status and logs, not reset the rate limit).

The second command also applies migration `0025_mcp_updates`, which creates `server_flags`. **Allow MCP updates** stays off.

Then, as an admin, open Settings → System and turn **Allow MCP updates** on. Until that switch is on, `deploy_update` is refused and **Update now** on the same page still queues. Open Settings → Agents and create a token with `system:read`. Add `system:deploy` only for an agent that may queue an update. Call `deploy_status`, then `deploy_update` with `dry_run: true` and `confirm_sha` set to that `origin/main`. A dry run does not create a request file.

Root still needs read access to the GitHub repo, the same as `sudo lifedash-deploy` today.

## Paths

| Path | Role |
| --- | --- |
| `/var/lib/lifedash/deploy/incoming/<job>.json` | Request: job id, confirm sha, user id, token id, token name |
| `/var/lib/lifedash/deploy/state/jobs/<job>.log` | Output of that run |
| `/var/lib/lifedash/deploy/state/last.json` | Last result, including the rate-limit clock |
| `lifedash-deploy.path` | Starts the queue when a request file appears |
| `lifedash-deploy.service` | Drains the queue |
| `lifedash-deploy@.service` | One job: `systemctl start lifedash-deploy@<jobid>` |

Job ids are generated by PHP. A client cannot choose one.
