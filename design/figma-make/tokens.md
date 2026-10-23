# Design tokens — Figma Make "AI Dashboard Design"

Source: `design/figma-make/index.css` (David's own Figma Make file). Extracted 2026-10-23.
Target in life-dashboard: the theme blocks in `public/assets/css/dashboard.css`
(`:root`, `html[data-theme="light"]`, `html[data-theme="dark"]`, `html[data-theme="jarvis"]`),
consumed by `lifedash.css`. Theme switching stays on `data-theme` (jarvis | light | dark | system);
the design's `.theme-jarvis/.theme-light/.theme-dark` classes map 1:1 onto those attribute values.

## 1. Colour — per theme

| Design token        | JARVIS (design `:root`)        | Dark (`.theme-dark`)          | Light (`.theme-light`)        | Existing app var it replaces |
|---------------------|--------------------------------|-------------------------------|-------------------------------|------------------------------|
| `--bg`              | `#080b0c`                      | `#101314`                     | `#edf2f2`                     | `--bg` / `--page-bg` |
| `--surface`         | `#0e1315`                      | `#171b1d`                     | `#f8fafa`                     | `--bg-elev` / `--surface-bg` |
| `--surface-raised`  | `#141b1d`                      | `#1d2325`                     | `#ffffff`                     | new (`--surface-raised`) — modals, menus, player |
| `--surface-hover`   | `#192225`                      | `#242b2d`                     | `#e8eeee`                     | `--surface-hover` |
| `--border`          | `#253033`                      | `#333d40`                     | `#cbd6d7`                     | `--input-border` (controls) |
| `--border-soft`     | `rgba(151,184,184,.12)`        | `rgba(182,200,201,.12)`       | `rgba(45,75,77,.13)`          | `--line` / `--chrome-border` / `--surface-border` |
| `--text`            | `#e8efef`                      | `#e7ebeb`                     | `#152123`                     | `--text` / `--text-primary` |
| `--text-secondary`  | `#829092`                      | `#929c9e`                     | `#5e7073`                     | `--text-secondary` / `--text-dim` |
| `--text-tertiary`   | `#536164`                      | `#626e70`                     | `#89989a`                     | `--text-muted` / `--text-faint` / `--text-label` (see contrast note) |
| `--accent`          | `#44d7d0`                      | `#9ab4b5` (grey, not teal)    | `#168c88`                     | `--accent` (JARVIS accent stays per-user via `JarvisAccent`) |
| `--accent-soft`     | `rgba(68,215,208,.11)`         | `rgba(154,180,181,.11)`       | `rgba(22,140,136,.10)`        | `--accent-soft` / `--nav-active-bg` |
| `--success`         | `#62c99a`                      | (inherits `#62c99a`)          | `#23835d`                     | `--ok` |
| `--warning`         | `#e3b45f`                      | (inherits `#e3b45f`)          | `#9d6a16`                     | `--warn` |
| `--danger`          | `#e06e75`                      | (inherits `#e06e75`)          | `#bc4750`                     | `--danger` |
| focus ring          | `0 0 0 3px rgba(68,215,208,.22)` | same                        | same                          | `--focus-ring` |

Derived values the design uses inline (worth naming once in CSS):
- `--accent-border`: `color-mix(in srgb, var(--accent) 30%, transparent)` (hover borders, mode switch).
- Sidebar glass: JARVIS `rgba(9,13,14,.94)` + `backdrop-filter: blur(20px)`; light `rgba(245,248,248,.95)`.
- Topbar glass: JARVIS `rgba(8,11,12,.84)` + blur(18px); light `rgba(237,242,242,.88)`.
- Light-theme cards: `linear-gradient(145deg, rgba(255,255,255,.96), rgba(244,248,248,.94))` + `0 10px 28px rgba(42,67,70,.07)`.
- Page glow: JARVIS `radial-gradient(circle at 56% 24%, rgba(31,94,92,.11), transparent 30%)`; dark `rgba(125,150,151,.07)`; light `rgba(22,140,136,.08)`.
- Ambient grid: 64px lines `rgba(100,180,178,.06)`, opacity .14 (light .35), radial mask. (The app already has an optional starfield; treat the grid as an alternative background, not an addition.)
- Inactive nav text JARVIS `#728083`, hover `#b9c5c5`; active nav = accent text + accent-soft bg + 2px accent bar with `0 0 10px var(--accent)` glow.
- Modal backdrop light `rgba(35,50,53,.35)`.

Contrast note: JARVIS `--text-tertiary` `#536164` on `--bg` `#080b0c` is ≈3.0:1 — fine for decorative labels, too low for body text. Keep the app's current `--text-muted` (`#8b979e`) for anything that must be read.

## 2. Typography

| Role | Design | Recommendation for the port |
|------|--------|-----------------------------|
| UI sans | Inter 400/500/600/700 (Google Fonts `@import`) | Keep self-hosted IBM Plex Sans already in `public/assets/fonts` for PR 1; switching to Inter means vendoring OFL woff2 files → **NEEDS DAVID OK** |
| Mono labels / badges / numbers | DM Mono 400/500, uppercase, letter-spacing .05–.16em | Use existing `--font-mono` (IBM Plex Mono); same uppercase + tracking treatment |
| Weights used | 400, 500, 550, 600, 700 | 400 / 500 / 600 only (550 is not in the vendored Plex files) |

Font-size reality check: the Make CSS is dominated by 6–9px text (7px ×81, 8px ×48, 6px ×46, 9px ×32). That is unreadable at 1440×900 and conflicts with "clean UX". Port onto a readable scale instead:

| Token | Size | Use (design size it replaces) |
|-------|------|-------------------------------|
| `--fs-2xs` | 11px | mono eyebrow labels, badges, kbd (6–8px) |
| `--fs-xs`  | 12px | meta, table secondary, nav labels (8–9px) |
| `--fs-sm`  | 13px | body, nav items, buttons, inputs (10–11px) |
| `--fs-md`  | 14px | widget titles, brand (12–14px) |
| `--fs-lg`  | 16px | section titles (15–17px) |
| `--fs-xl`  | 20px | stat values (18–22px) |
| `--fs-2xl` | 24px | page values, settings step title (24–28px) |
| `--fs-3xl` | clamp(26px, 3vw, 32px) | page H1 / greeting (clamp 26–37px) |

## 3. Spacing

Design gaps are irregular (3,4,5,6,7,8,9,10,12,13,14px). David asked for a 4/8 system, so snap to:
`--sp-1: 4px; --sp-2: 8px; --sp-3: 12px; --sp-4: 16px; --sp-5: 20px; --sp-6: 24px; --sp-8: 32px`.
Fixed chrome sizes from the design: sidebar 224px (collapsed icon rail ≈ 64px), topbar 72px,
nav row 39px → 40px, control height 32–34px → 32px, content padding 30px → 32px, content grid gap 14px → 16px.

## 4. Radius (one family)

| Token | Design | Use |
|-------|--------|-----|
| `--r-sm` | 6px (raw 4/5/6/7px also used) | inputs, buttons, nav items, kbd |
| `--r-md` | 10px (raw 8/9px also used) | widgets, cards, menus |
| `--r-lg` | 14px | modals, Spotify player, settings panel |
| pill | 99px | chips, badges, segmented controls |
| round | 50% | avatar, brand mark, status dots |

The app today uses 8 / 10 / 12 / 999. Proposal: `--radius-sm: 6px; --radius-control: 6px; --radius: 10px; --radius-lg: 14px; --radius-full: 999px` and collapse every raw 7px/8px/9px value onto these.

## 5. Elevation

| Token | Value | Use |
|-------|-------|-----|
| `--shadow` | `0 18px 50px rgba(0,0,0,.24)` (light: `0 18px 45px rgba(35,55,58,.12)`) | raised cards, menus |
| `--shadow-modal` | `0 30px 80px rgba(0,0,0,.55)` | `.app-modal-panel` (app currently `--elev-dialog: 0 16px 40px rgba(0,0,0,.45)`) |
| `--shadow-player` | `0 20px 60px rgba(0,0,0,.5)` + faint accent glow | floating Spotify player |
| accent glow | `0 0 20px rgba(68,215,208,.18)` | brand mark, active nav bar, live dots |

## 6. Motion

- Default transition `.16s` on colour/background/border; layout/transform `.25s ease`; board/brain camera `transform .7s cubic-bezier(.2,.8,.2,1)`.
- Keyframes in the design: `spin`, `blink`, `wave`, `corePulse`, `nodePulse`, `cardWipe`, `shimmer` (loading skeleton), `modalIn`. Port only `spin`, `shimmer`, `modalIn`, `cardWipe` (rotating cards) in the early PRs; Brain keyframes go with the Brain PR.
- The design ships a `prefers-reduced-motion: reduce` block — keep it (the app should honour it everywhere).

## 7. Icons

Lucide, stroke icons, default 20px, `stroke-width: 1.7` (nav 18px, small controls 13–15px). Use `currentColor` by inlining the vendored SVG with `readfile()` as `app-shell-open.php` already does. Icon list per PR is in `BUILD-PLAN.md`.

## 8. Layout grids

- Content pages: 12-column grid, gap 14px (→16px).
- Dashboard board in the design: 7 columns, rows ≈144px, `--widget-w` 1–7, `--widget-h` 1–4.
  The app stores layouts as 12 canonical columns (18/24 on wide screens) with 32px rows in Postgres.
  See BUILD-PLAN "NEEDS DAVID OK" — do not change the stored column model without approval.
- Breakpoints in the design: 1100px, 850px, 620px. App grid breakpoints are 700 / 1600 / 3440 (keep those for the grid; use the design's for page layouts).
