# Build plan — porting the Figma Make "AI Dashboard Design" into life-dashboard

Source: David's own Figma Make file "AI Dashboard Design" (React 19 + Vite + Tailwind 4), staged under
`design/figma-make/` on branch `design/figma-make-ai-dashboard`. It is a **visual reference only**.
Nothing from `App.tsx` or `index.css` is copied into the app; every PR re-implements the look in
PHP templates + vanilla JS + `lifedash.css` / `dashboard.css`.

Repo baseline: `main` @ `bd031da` ("Use the shared modal for stocks settings dialogs. (#87)").
Asset cache-bust today: `$assetVer = '20261023ai'` in `templates/partials/head.php`. Every PR that
touches CSS/JS bumps it once (next suffixes: `20261023aj`, `ak`, … or the date of the PR).

---

## 0. Rules every PR follows (copy into each PR checklist)

1. No React, no Tailwind, no build step. Templates in `templates/`, scripts in `public/assets/js/`, styles in
   `public/assets/css/lifedash.css` (tokens in `dashboard.css` theme blocks).
2. Dialogs use `.app-modal` + `app-modal.js`; yes/no prompts use `AppModal.confirm`. Notices use `toast.js`.
   Empty states use `partials/empty-panel.php` (server) or `DashboardEmpty.html()` (client).
3. Icons only from `public/assets/vendor/lucide/*.svg` (Lucide **v1.61.0**, ISC, file header kept), inlined with
   `readfile()` so the stroke stays `currentColor`. Each PR vendors only the icons it uses (list below).
   No emoji, no hand-drawn glyphs (replace today's `⋮⋮`, `⚙`, `✕`, `×` as the PR touches them).
4. Dashboard home: only the search box (Local / Google / DDG / Bing / Grok / Claude engine buttons) is fixed and
   centred. Everything else — vitals, agenda, quick actions, today, etc. — is a normal movable/removable widget.
   No gap between the search row and the first widget row.
5. Settings opens from the cog at the bottom of the sidebar, above the user card, and keeps the steps-rail layout
   (`templates/settings/_shell-top.php` / `_shell-bottom.php` / `_nav.php`).
6. No `.env`. Every setting is a dashboard form field. Secrets are encrypted at rest, rendered masked
   (`SecretMask`) with Show / Replace and an "Encrypted" hint. Never echo a stored secret back into HTML.
7. Account numbers: last 4 only (`AccountNumberGuard`). Show as `•••• 4812`. Never a full number in markup, JS, logs.
8. Keep the label **"Creator subscription"** exactly (`AccountNumberGuard::CREATOR`).
9. No finance logic changes. Money/Investing/Stocks PRs are presentation-only on existing endpoints.
10. Real data only. Every widget/page reads an existing endpoint; when there is no data, show the empty / needs-setup
    state that links to the matching Settings step. No demo arrays, no "Alex Sterling", no fake balances.
11. Comments: sparse, explain *why* only.
12. No screenshots (or the `design/` folder) in PRs. Branch each PR from `main`, not from the design branch.
13. PR body must include: *"Design ported from David's own Figma Make file (AI Dashboard Design). No outside code was copied."*
14. Nav label is **Vault** (the design's "Valut" is a typo).

---

## 1. Design → app map

| Design page / component | App file(s) today | Visual change | Behaviour change | Real data backing |
|---|---|---|---|---|
| App shell: sidebar (brand, icon + label nav, badge, collapse to icon rail), topbar (breadcrumb, ⌘K search, theme switch, bell, clock) | `partials/app-shell-open.php`, `app-shell-close.php`, `app-shell.js`, `appearance-nav.php`, `theme-switcher.php`, `theme.js`, `lifedash.css` (`.app-sidebar*`, `.app-header*`) | Glass sidebar 224px, Lucide icon per item, accent bar on active, mono group labels, user card with initials avatar; 72px topbar with breadcrumb + clock | Collapse to icon rail (persist in `localStorage`, like theme), ⌘K focuses global search, theme cycle jarvis→light→dark (system stays in Settings) | Nav groups are the existing `$shellGroups`; user card = real `$user['username']`; ⌘K → existing `GET /api/v1/search`; clock = existing `server-time.js` |
| Settings cog above user card | `app-settings-pin` already in `app-shell-open.php` | Restyle to design (`settings` icon, label, active state) | Already correct position — keep | — |
| Dashboard: fixed centred search + engine buttons | `templates/dashboard.php` (`.ld-search-row`), `dashboard.js` | Design "assistant" composer look (terminal/command icon, send button, engine segmented pills) | Unchanged: fixed, centred, not a widget. Remove page-heading eyebrow/"at a glance" lines to kill wasted space (prompt 191) | `PreferenceService::searchEnginesForUi()` |
| Dashboard board (grid, Edit layout, Add widget catalog, widget menu, lock/fit/remove, states) | `dashboard.php`, `lifedash-grid.js`, `widgets.js`, `lifedash-renderers.js`, `partials/lifedash-modals.php`, `src/Widgets/WidgetTypeRegistry.php` | Widget chrome (title row with icon, ⋯ menu, lock-keyhole badge), catalog modal grouped by category with search, skeleton/empty/error/needs-setup states | Replace the edit bar (`⋮⋮ Lock Fit ⚙ ✕`) with a ⋯ menu: Settings / Lock height / Lock place / Fit to content / Remove (Remove via `AppModal.confirm`). Catalog = the existing registry grouped by category. Pack upward with no gaps (gridstack `float:false` + compact) | `GET /api/v1/widget-types`, `/api/v1/widgets*`, `POST /api/v1/layout`, revisions/rollback |
| Widgets: habits, mood, goals, streak, stat, chart, gauge, entries | `lifedash-renderers.js`, `lifedash-trackers.js` | Restyle to design cards (habit pips, gauge ring, sparkline) | none | `/api/v1/widgets/{id}/data`, metrics/habits APIs |
| Widgets: today (`life`), vitals, agenda, quick actions, calls, shopping, pantry, digest, weather, todos, bookmarks, flights, packages, system status, clipboards, news | renderers + `partials/widgets/*.php` (legacy.*) | Restyle | none | existing per-widget endpoints |
| Widgets: spending, net worth, baby step, debt, food week, budget, funds, bills, retirement, credit, portfolio, stocks | renderers + `partials/widgets/stocks.php` | Restyle only; last-4 masks | none | `/api/v1/money/dashboard`, `/money/summary`, `/invest/scorecard`, `/api/stocks` |
| Widgets: spotify, youtube, reddit, reddit reader, X, custom | renderers, `spotify.js` | Restyle; rotating card wipe/push animation | none | existing media feeds |
| Music (Overview, Playlists + detail, Mixtape Builder, Track Library, Seasons, Activity) | `templates/spotify.php` (nav "Playlists"), `spotify.js`, `src/Spotify/*`, `/api/v1/spotify/*` | Tabbed page per design, energy-arc chart, track table, builder steps, season cards | Tabs as `?tab=` links; builder uses `POST /spotify/mixtape/plan` then `POST /spotify/playlists`; drag reorder → `tracks/reorder`; inline meta edit → `tracks/meta`; states: not connected / rate-limited / no device | All exist (see API list in §6). Activity "Undo" → NEEDS DAVID OK |
| Floating Spotify player (drag, expand, up next, devices) | `spotify-player.js`, `/settings/spotify/player`, `/api/v1/spotify/player|devices|queue` | Restyle to design (rounded 14px, glow) | Expand panel with queue + device picker | existing |
| Money / Investing / Stocks (`FinancePage`) | `templates/settings/money.php`, `investing.php`, `stocks.php`, `settings-money.js`, `settings-investing.js`, `stocks-manage.js` | Stat cards, chart, accounts list (`•••• 4812`), transactions / holdings table | Presentation only. Adding/editing accounts keeps today's modals | `/api/v1/money/accounts|transactions|summary|dashboard|budget|debts|sinking-funds`, `/invest/scorecard`, `/api/stocks`. Standalone `/money` pages → NEEDS DAVID OK |
| Pantry / Meal Prep | `templates/pantry.php` (+ `?view=meals`), `pantry.js` | Inventory table, expiry chips, meal week grid, shared modal | none | `/api/v1/pantry/*` incl. `/pantry/meals*` |
| Vault (tree / note Preview default + Edit toggle / backlinks, tags, outline / mobile drawer) | `templates/brain.php` (route `/brain`, nav "Vault"), `brain.js`, `brain-markdown.js` | Three-pane layout, Preview\|Edit segmented control (already exists as `data-brain-tab`), context pane | Mobile: tree becomes drawer | `/api/v1/pages*`, `/pages/graph` (backlinks), revisions |
| Brain (3D clouds, free-roam/attach camera, read/write glow, node panel "Open in Vault") | `templates/brain-graph.php` (route `/brain/graph`), `brain-graph.js`, `brain-camera.js`, `brain-forces.js` | Cloud clusters, glow pulses, node panel restyle | Attach / free-roam toggle, read vs write packet colours | `/api/v1/pages/graph`, `BrainActivity` via `/api/v1/audit/presence` |
| Grok Digest (compact list, date, collapse, menu for history/compare/sources) | `templates/digest.php`, `digest.js` | Compact rows same width as dashboard | History / compare / source editing move into a ⋯ menu | `/api/v1/digest/items|versions|compare|sources` |
| Flights (cards + details modal) | `templates/flights.php`, `flights-manage.js`, `partials/widgets/flights.php` | Flight cards, status chip, details modal | Details in `.app-modal` | `/api/flights*` |
| Clipboard, CloudPad, Clock, Feeds | `settings/clipboards.php` (Boards/Files/CloudPad tabs), `cloudpad/canvas.php`, `clock.php`, `news.php` | **Design has placeholders only** — apply shell + tokens, no new layout | none | existing |
| Settings (wizard look: numbered rail, form, preview/progress, footer Back / Saved automatically / Save) | `settings/_shell-top.php`, `_shell-bottom.php`, `_nav.php`, `settings-shell.js`, every `settings/*.php` | Numbered steps rail with teal current step, footer bar | Footer status "Saved automatically" only where the page already autosaves; secret fields → shared masked component | existing settings routes. Design's Identity / Voice / Routines / Privacy steps → NEEDS DAVID OK |
| Design-system page (Foundations / Components tabs inside Settings) | — | **Do not ship.** Reference only | — | — |

---

## 2. PR list (in merge order)

Each PR is independent of later ones and can be reviewed alone. "Owns" = the only files that PR may edit; if a later PR
needs one of them it waits for the earlier PR to merge.

### PR 1 — Design tokens (no visual layout change)
- **Owns:** `public/assets/css/dashboard.css` (`:root` + three `html[data-theme]` blocks only), `templates/partials/head.php` (`$assetVer`).
- **Change:** Map the design palette per `tokens.md` onto the existing variable names (keep the names so nothing else breaks);
  add `--surface-raised`, `--border-soft`, `--accent-border`, `--shadow`, `--shadow-modal`, `--shadow-player`,
  `--fs-*`, `--sp-*`, `--radius-lg`; radius family 6/10/14/999. Keep IBM Plex fonts. JARVIS accent stays per-user
  (`JarvisAccent` overrides `--accent`). Dark theme accent: keep teal unless David approves the grey (`#9ab4b5`).
- **Icons:** none.
- **Accept:** all three themes render every existing page with no broken colours; light theme passes WCAG AA for body text;
  `JarvisAccent` amber still recolours; no layout shifts beyond radius/colour.

### PR 2 — App shell: sidebar + topbar
- **Owns:** `templates/partials/app-shell-open.php`, `app-shell-close.php`, `appearance-nav.php`, `public/assets/js/app-shell.js`,
  `lifedash.css` (`.app-sidebar*`, `.app-nav*`, `.app-header*`, `.app-user*`, `.app-settings-pin*` sections only),
  new `public/assets/vendor/lucide/*.svg` listed below.
- **Change:** Icon per nav item, accent active bar, mono group labels, collapse to icon rail (`localStorage`), user card with
  initials avatar + real username + Sign out, cog stays above user card. Topbar: breadcrumb (group › page), ⌘K search
  field (focus + `/api/v1/search` results dropdown), theme cycle button, live clock. Rename nothing except keep "Vault".
  Per-page header tools (Map / alarm / Sources / Manage lists / Subreddits / CloudPad / Player) stay, restyled.
- **Icons:** `layout-dashboard, book, brain, bot, newspaper, message-square (Reddit), sparkles (Agents), music-2,
  list-checks (Todos), clock-3, shopping-basket, cooking-pot, plane, package, wallet-cards, trending-up, chart-column,
  search, command, sun, moon, monitor, bell*, panel-left-close, panel-left-open, log-out, chevron-down, menu, x`.
  (*bell only if David approves notifications — see NEEDS DAVID OK; otherwise omit the bell.)
- **Accept:** every page renders inside the new shell at 1440, 1100, 850, 390px; collapsed rail shows tooltips + keeps
  active state; keyboard: Tab order sane, Esc closes mobile drawer, ⌘K/Ctrl+K focuses search; Settings cog highlights on
  `/settings*` except Agents/Packages (existing `$shellClaimedByGroup` rule).

### PR 3 — Shared components: buttons, fields, segmented control, chips, modal, toast, empty panel, secret field
- **Owns:** `lifedash.css` (`.btn*`, `.input`, `.seg-*`, `.chip*`, `.app-modal*`, `.toast*`, `.empty-panel*`, new `.secret-field*`),
  `public/assets/js/app-modal.js` (visual hooks only, no API change), `toast.js` (icon + tone classes), `empty-panel.js`,
  `templates/partials/empty-panel.php`, new `templates/partials/secret-field.php` + `public/assets/js/secret-field.js`.
- **Change:** Restyle to design; modal enter animation `modalIn` (respect reduced motion); replace `×` close glyphs with
  `x.svg`. Secret field partial: masked value from `SecretMask`, Show (reveals only a value the user just typed, never the
  stored one), Replace, "Encrypted" hint with `lock-keyhole`.
- **Icons:** `x, check, circle-check, circle-alert, triangle-alert, info, eye, eye-off, lock-keyhole, loader-circle, refresh-cw`.
- **Accept:** every existing modal opens/closes/escapes/focus-traps exactly as before; `AppModal.confirm` unchanged API;
  toast tones ok/warn/danger visible in all themes; secret field never outputs stored plaintext (check HTML source).

### PR 4 — Dashboard search row + board chrome
- **Owns:** `templates/dashboard.php`, `public/assets/js/dashboard.js`, `lifedash.css` (`.ld-search*`, `.ld-board`, `.ld-toolbar`, `.ld-w*`, `.ld-edit*`),
  `templates/partials/page-heading.php` (only to allow hiding the heading on `/`).
- **Change:** Search composer restyle (command icon, send button, engine pills), centred and fixed above the grid with no
  gap below. Remove the "Personal intelligence system / Dashboard" heading and timezone bar from the top of the page —
  the 4-zone clocks become a normal widget only if David approves (see NEEDS DAVID OK; until then keep the timezone bar
  as-is). Edit bar → ⋯ widget menu (Settings / Lock height / Lock place / Fit to content / Remove→`AppModal.confirm`).
  Lock badge `lock-keyhole`. Drag handle `grip-vertical`.
- **Icons:** `terminal (already), arrow-right (already), ellipsis, grip-vertical, lock-keyhole, lock-keyhole-open, maximize-2 (fit),
  sliders-horizontal (settings), trash-2, plus, pencil (Edit layout)`.
- **Accept:** search behaves exactly as today for all six engines incl. Local results; no widget can be placed above the search
  row; first widget row starts directly under it; menu actions hit the same endpoints as today's buttons; Remove asks via
  `AppModal.confirm`; layout save/restore and live WebSocket updates unchanged.

### PR 5 — Add-widget catalog modal + widget states
- **Owns:** `templates/partials/lifedash-modals.php`, `public/assets/js/widgets.js`, `lifedash-grid.js` (catalog + state rendering only),
  `lifedash.css` (`.ld-catalog*`, `.ld-state*`, `.ld-skeleton`).
- **Change:** Catalog grouped by category (Tracking / Data / Life / Money / Knowledge / Media / Custom) with search, icon,
  description, default size. Categories come from the registry; if the registry has no category field, group client-side by
  a map in `widgets.js` (no PHP change). Standard states for every widget: loading (shimmer skeleton), empty, error (Retry),
  needs setup (link to Settings step).
- **Icons:** `search, activity, chart-line, gauge, heart-pulse, calendar-days, cloud-sun, inbox, plane, package, server,
  shopping-cart, phone, utensils, wallet-cards, target, piggy-bank, credit-card, landmark, trending-up, chart-column,
  book-open, clipboard-list, newspaper, music-2, play-square (YouTube — Lucide brand icons are deprecated), smile,
  trophy, flame, square-pen, code-xml`.
- **Accept:** every registry type is listed exactly once; adding a widget places it in the first free slot and packs
  upward with no gaps; each state reachable (block the endpoint in devtools → error state with Retry).

### PR 6 — Widget visuals, batch A (Tracking + Data + Life)
- **Owns:** `lifedash-renderers.js` (renderers for habits, mood, goal, streak, stat, chart, gauge, table, life, vitals, agenda,
  quick_actions, calls, shopping, pantry, digest), `lifedash-trackers.js`, `partials/widgets/{weather,todos,bookmarks,flights,packages,pings}.php`
  and their JS, `lifedash.css` widget sections.
- **Change:** Restyle to the design cards. Rotating cards: `cardWipe` transition (reduced-motion: instant).
- **Icons:** `cloud, cloud-rain, cloud-sun, sun, wind, droplets, check, flame, smile, frown, meh, calendar-days, clock-3, phone,
  shopping-cart, utensils, bot, plane, package, wifi, wifi-off, bookmark, folder (already), folder-open`.
- **Accept:** each widget type renders real data, empty and error in all three themes at 2, 3, 4, 6, 12 columns; no text under 11px.

### PR 7 — Widget visuals, batch B (Money / Investing / Stocks widgets)
- **Owns:** renderers for spending, networth, baby_step, debt_snowball, food_week, budget_actual, sinking_funds, bills_due,
  retirement, credit, portfolio; `partials/widgets/stocks.php`; matching `lifedash.css` section.
- **Change:** Presentation only. Positive/negative colours from tokens; account rows show `•••• last4` only.
- **Icons:** `wallet-cards, piggy-bank, landmark, credit-card, receipt, trending-up, trending-down, arrow-up-right, arrow-down-right, target`.
- **Accept:** numbers identical to `main` for the same account (compare before/after); grep the rendered HTML/JS payload for
  any 5+ digit run in account fields — none; "Creator subscription" label unchanged; no change under `src/Money`, `src/Invest`, `src/Stock`.

### PR 8 — Widget visuals, batch C (Media + Knowledge + Custom)
- **Owns:** renderers for spotify, spotify_player, youtube, reddit, reddit_feed, x, pages, custom; `partials/widgets/{news,clipboards}.php`.
- **Icons:** `music-2, play, pause, skip-back, skip-forward, newspaper, message-square, file-text (already), book-open, code-xml, external-link`.
- **Accept:** as PR 6.

### PR 9 — Settings shell (wizard look)
- **Owns:** `templates/settings/_shell-top.php`, `_shell-bottom.php`, `_nav.php`, `public/assets/js/settings-shell.js`, `lifedash.css` (`.settings-*`).
- **Change:** Numbered steps rail (teal current, check on visited), content panel, optional right preview/progress column for
  pages that set `$settingsPreview`, sticky footer: Back / status text / primary. Status shows "Saved automatically" only on
  pages that autosave today; otherwise "Unsaved changes" → Save. Mobile: rail becomes the existing "Sections" drawer.
- **Icons:** `check, chevron-left, chevron-right (already), circle-dot`.
- **Accept:** every `/settings/*` page renders in the new shell with its form working unchanged; Back/Next follow
  `settings_nav_trail()`; no setting moved to `.env`; no feature on/off toggle added to Settings.

### PR 10 — Settings pages adopt secret field + form layout
- **Owns:** the `templates/settings/*.php` that hold secrets (spotify, weather, stocks, flight-provider, packages, ollama, tunnel,
  cloud-storage, agents/tokens display) — markup only.
- **Change:** Swap ad-hoc password inputs for `partials/secret-field.php`; consistent field rows.
- **Accept:** saving a blank secret field keeps the stored secret; Replace stores a new one; HTML never contains the stored value;
  existing `SecretMask` output unchanged. *If any page turns out to store a secret unencrypted today, stop and flag — that fix is NEEDS DAVID OK.*

### PR 11 — Money / Investing / Stocks pages (inside Settings)
- **Owns:** `templates/settings/{money,investing,stocks}.php`, `settings-money.js`, `settings-investing.js`, `stocks-manage.js`, `lifedash.css` (`.fin-*`).
- **Change:** Design FinancePage look: stat cards row, chart, accounts list (`•••• 4812`), transactions / holdings table.
  No "Add position" / "Buy" / trade buttons (agents cannot trade; design shows them).
- **Icons:** `wallet-cards, trending-up, trending-down, chart-column, upload, list-filter, arrow-up-right, arrow-down-right, refresh-cw`.
- **Accept:** same as PR 7 plus CSV import and weekly review still work; Polymarket refresh still works.

### PR 12 — Music page
- **Owns:** `templates/spotify.php`, `public/assets/js/spotify.js`, new `public/assets/js/spotify-mixtape.js` (builder UI only), `lifedash.css` (`.music-*`).
- **Change:** Tabs (Overview / Playlists / Mixtape Builder / Track Library / Seasons / Activity) as `?tab=`. Playlist detail with
  energy-arc chart and drag-reorder track table. Builder steps Theme / Prompt & arc / Seeds / Rules / Review; Generate plan →
  `POST /api/v1/spotify/mixtape/plan`; Create in Spotify → `POST /api/v1/spotify/playlists` + `/tracks`. Season guard shown
  from `/spotify/seasons/active`. Track Library with drawer editing `/spotify/tracks/meta`. Activity tab read-only from
  `/spotify/playlists/{id}/changes` + `/spotify/history`. States: not connected (link `/settings/spotify`), rate-limited, no device.
- **Icons:** `music-2, list-music, disc-3, wand-sparkles, sliders-horizontal, sprout (seasons)…, history, play, pause, shuffle, repeat,
  grip-vertical, lock-keyhole, lock-keyhole-open, arrow-left-right (swap), circle-alert, speaker, smartphone, monitor`.
- **Accept:** works with a disconnected account (every tab shows the needs-setup state); with a connected account the builder
  creates a real playlist; reorder persists; no new endpoints.

### PR 13 — Floating Spotify player
- **Owns:** `public/assets/js/spotify-player.js`, `lifedash.css` (`.sp-player*`).
- **Icons:** `play, pause, skip-back, skip-forward, volume-2, speaker, smartphone, monitor, chevron-up, chevron-down, list-music`.
- **Accept:** drag position persists; expand shows queue + device picker from existing endpoints; no device state handled.

### PR 14 — Vault
- **Owns:** `templates/brain.php`, `brain.js`, `brain-markdown.js`, `lifedash.css` (`.brain-*` vault sections).
- **Change:** Tree pane / note pane (Preview default, Edit toggle) / context pane (backlinks, tags, outline); mobile tree drawer.
- **Icons:** `folder (already), folder-open, file-text (already), book-open, link, tag, list-tree, pencil, eye, search, panel-left`.
- **Accept:** create/move/archive/restore pages still work; backlinks match `/api/v1/pages/graph`; Preview is default on load.

### PR 15 — Brain
- **Owns:** `templates/brain-graph.php`, `brain-graph.js`, `brain-camera.js`, `brain-forces.js`, `lifedash.css` (`.brain-graph*`).
- **Change:** Cloud clusters per folder/domain, free-roam / attach toggle, read (accent) vs write (warning) pulse from
  `/api/v1/audit/presence`, node panel with "Open in Vault" → `/brain?page={id}`. Reduced motion: no pulses, no auto-orbit.
  Stays on the existing canvas renderer (no CSS-3D / no new library).
- **Icons:** `brain, orbit, crosshair, zoom-in, zoom-out, rotate-ccw, external-link`.
- **Accept:** 60fps on 500 nodes on David's machine; keyboard pan/zoom still works.

### PR 16 — Grok Digest
- **Owns:** `templates/digest.php`, `digest.js`, `lifedash.css` (`.ld-digest*`).
- **Change:** Compact list same max width as the dashboard grid; date header; collapse; ⋯ menu for History / Compare / Sources.
- **Icons:** `bot, ellipsis, history, git-compare, rss, chevron-down`.
- **Accept:** read/unread, retention, source enable/disable unchanged.

### PR 17 — Flights
- **Owns:** `templates/flights.php`, `flights-manage.js`, `lifedash.css` (`.flight-*`).
- **Icons:** `plane, plane-takeoff, plane-landing, clock-3, map-pin, ellipsis`.
- **Accept:** create/update/delete watches unchanged; details open in `.app-modal`.

### PR 18 — Pantry / Meal Prep
- **Owns:** `templates/pantry.php`, `pantry.js` (and its meals module), `lifedash.css` (`.ld-pantry*`, `.ld-meal*`).
- **Icons:** `shopping-basket, cooking-pot, scan-barcode, calendar-days, triangle-alert, plus, minus`.
- **Accept:** scan, consume, toss, split, meal prep plan and grocery push unchanged; phone layout usable at 390px.

### PR 19 — Remaining pages adopt shell + tokens (Clipboard / CloudPad / Clock / News / Reddit / Todos)
- **Owns:** `templates/{clock,news,reddit,todos}.php`, `templates/settings/clipboards.php`, `templates/cloudpad/canvas.php`, their CSS sections.
- **Change:** Tokens, headings, buttons, empty panels only — the design has no layouts for these.
- **Accept:** no behaviour change.

---

## 3. Lucide icons to vendor (v1.61.0 names)

Already vendored: `arrow-right, chevron-right, file-text, folder, settings, terminal`.
All 121 names below were checked against the `lucide-static@1.61.0` npm package on 2026-10-23 — every file exists.

New, deduplicated (each PR vendors its own subset):
`activity, arrow-down-right, arrow-left-right, arrow-up-right, bell*, book, book-open, bookmark, bot, brain, calendar-days,
chart-column, chart-line, check, chevron-down, chevron-left, chevron-up, circle-alert, circle-check, circle-dot, clipboard-list,
clock-3, cloud, cloud-rain, cloud-sun, code-xml, command, cooking-pot, credit-card, crosshair, disc-3, droplets, ellipsis, external-link,
eye, eye-off, flame, folder-open, frown, gauge, git-compare, grip-vertical, heart-pulse, history, inbox, info, landmark, layout-dashboard,
link, list-checks, list-filter, list-music, list-tree, loader-circle, lock-keyhole, lock-keyhole-open, log-out, map-pin, maximize-2, meh,
menu, message-square, minus, monitor, moon, music-2, newspaper, orbit, package, panel-left, panel-left-close, panel-left-open, pause,
pencil, phone, piggy-bank, plane, plane-landing, plane-takeoff, play, play-square, plus, receipt, refresh-cw, repeat, rotate-ccw, rss,
scan-barcode, search, server, shopping-basket, shopping-cart, shuffle, skip-back, skip-forward, sliders-horizontal, smartphone, smile,
sparkles, speaker, sprout, square-pen, sun, tag, target, trash-2, trending-down, trending-up, triangle-alert, trophy, upload, utensils,
volume-2, wallet-cards, wand-sparkles, wifi, wifi-off, wind, x, zoom-in, zoom-out`.

Name changes from the design's lucide-react imports (Lucide 1.x renamed these): `BarChart3 → chart-column`, `CheckCircle2 → circle-check`,
`MoreHorizontal → ellipsis`, `Home → house`, `Filter → funnel`, `Code2 → code-xml`, `Globe2 → earth`, `Loader2 → loader-circle`,
`Edit3 / PenSquare → square-pen`. Verify each file exists in the v1.61.0 static package before committing; fetch from the
official `lucide-static@1.61.0` tarball only, keep the license header line.

---

## 4. NEEDS DAVID OK (not planned in — each needs a yes before anyone builds it)

1. **Dashboard grid: 7 columns vs 12.** The design (prompt 168) snaps to 7 columns with ~144px rows. The app stores 12 canonical
   columns (18/24 wide) with 32px rows in `dashboard_widgets`. Switching means a layout migration of stored rows → DB change.
   Default plan keeps 12 columns and only restyles.
2. **Assistant as a widget.** The design makes the assistant/search a 7-wide widget (and prompt 146 liked that). The hard rule says
   search stays fixed and centred. Plan follows the hard rule; confirm.
3. **Timezone bar / page heading on the dashboard.** Today they sit above the search. Making the 4-zone clock a widget needs a new
   widget type in `WidgetTypeRegistry` (backend). Plan only removes the heading lines; the timezone bar stays until approved.
4. **Standalone `/money`, `/investing`, `/stocks` pages + nav items.** The design gives them top-level nav; the app keeps them under
   Settings. New routes in `public/index.php` = backend change. Plan restyles the Settings pages instead.
5. **Music top-level nav name.** Design says "Music"; app nav says "Playlists" (`/spotify`). Label-only change, but it's a nav rename.
6. **Music Activity "Undo" / "View".** No undo endpoint exists (`/spotify/playlists/{id}/changes` is read-only). Undo = new backend.
7. **Mixtape "lock/swap a row and regenerate unlocked".** `Mixtape::plan()` has no locked-track input. Needs backend.
8. **Design's Settings steps Identity / Voice / Routines / Privacy / Initialize.** No backing services (voice, routines, privacy
   permissions). New backend + DB. Plan keeps the existing settings trail.
9. **Notifications bell** in the topbar. No notifications backend. Omit until approved.
10. **Voice / mic button** on the assistant. No speech backend. Omit.
11. **Fonts Inter + DM Mono.** Need vendoring OFL font files (third-party assets). Plan keeps IBM Plex.
12. **Dark theme accent grey `#9ab4b5`.** Design's "dark" theme drops teal; David asked for one teal accent. Confirm.
13. **Investing "Add position" / trade-like buttons** in the design. Agents cannot trade; any position entry beyond CSV import is
    finance logic. Omitted.
14. **Brain CSS-3D renderer.** Design uses CSS-3D transforms; app uses a canvas force graph. Rewriting the renderer is a big change
    (performance); plan restyles the canvas version.
15. **Widget categories in the registry.** If grouping needs a `category` field on `WidgetTypeRegistry`, that's a PHP change (small,
    no DB). Plan does it client-side to avoid it.
16. **Any secret found stored unencrypted** while doing PR 10 → security fix, flag before changing.
17. **Feature on/off controls on the dashboard** (prompt: "feature toggles live on the dashboard, not Settings"). Needs a place to
    persist per-feature enabled flags → PreferenceService/DB. Not planned.

---

## 5. Gaps and surprises found in the design

- Clipboard, CloudPad, Clock and Feeds are `GenericPage` placeholders in the design — no layout to port. The app already has full
  features for all four (PR 19 only re-skins them).
- The Design System (Foundations / Components) lives as tabs inside the design's Settings page — reference only, don't ship.
- Demo data everywhere (user "Alex Sterling / Pro workspace", balances, flights, tracks). None of it may reach the app.
- Font sizes are 6–9px across most of the CSS — unreadable; tokens.md proposes a readable scale.
- Light theme bugs in the design: Today widget tiles render grey, day pips render black.
- Dashboard board leaves an empty cell right of the Today widget (no dense packing) — contradicts "pack upward, no gaps".
- `.theme-dark` accent is grey, not teal.
- Google Fonts are loaded from the network via `@import` (the app self-hosts fonts; keep it that way).
- Design nav typo "Valut" → "Vault".
- The design has no Agents, Reddit, News, Todos or Packages page designs though the app nav has them.

## 6. Existing endpoints the port relies on (no new ones)

Widgets/layout: `/api/v1/widget-types`, `/api/v1/widgets`, `/api/v1/widgets/{id}/data|update|delete|revisions|rollback|goal-log`, `/api/v1/layout`, `/api/v1/realtime/token`.
Search: `/api/v1/search`. Life: `/api/v1/life/today`. Money: `/api/v1/money/accounts|transactions|summary|dashboard|budget|debts|sinking-funds|weekly-review|import`.
Investing: `/api/v1/invest/scorecard|import|wallet|polymarket/refresh`; Stocks: `/api/stocks*`.
Spotify: `/api/v1/spotify/status|playlists|playlists/{id}|changes|tracks/meta|seasons|seasons/active|search|history|saved|top|player|devices|queue|taste|prompts|mixtape/plan|playlists/{id}/tracks/reorder`.
Vault/Brain: `/api/v1/pages*`, `/api/v1/pages/graph`, `/api/v1/audit/presence`. Digest: `/api/v1/digest/*`.
Pantry: `/api/v1/pantry/*`. Flights: `/api/flights*`. Packages, pings, bookmarks, todos, clipboards, cloudpad: existing `/api/*`.

## 7. PR body template

```
Ports the <area> look from David's own Figma Make file (AI Dashboard Design) into the PHP templates, vanilla JS and lifedash.css.
No outside code was copied.

- What changed (visual):
- What changed (behaviour):
- Data: existing endpoints only (<list>)
- Icons vendored (Lucide v1.61.0): <list>
- Asset version: <new $assetVer>
- Tested: jarvis / light / dark at 1440, 1100, 850, 390px; empty + error states; reduced motion.
```
