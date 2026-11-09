# Optima Digital: Brand Theme

Optima Digital is a legal digital-marketing agency for plaintiff's personal injury firms. Tone: confident, precise, premium, never flashy.

**Canon:** Optima Design System v2 (Oct 2026). The full guide, patterns, UI kits, logos and fonts live in the private `GoogleWorkspace` repo under `design-system/` (`readme.md` is the long guide). Tokens here are generated from `design-system/unified/tokens.json`.

## 1. Color

| Role | Light | Dark | Notes |
|---|---|---|---|
| Canvas `--bg` | `#F4F7FB` | `#12163A` | Cool blue-gray. Never cream or warm off-white. |
| Surface | `#FFFFFF` | `#1B2150` | |
| Text | navy `#1B2150` | `#FFFFFF` | |
| Text-2 | `#5D6779` | `#B7C0D6` | |
| Accent | azure `#1272C4` | `#1272C4` | Carries white text (5.0:1). |
| Accent as text | `#0D5490` | `#5AA7E0` | |
| Focus ring | blue-600 `#0F63AB` | gold `#FDC134` | 3px ring, 3px offset |

**Brand extras:** gold `#FDC134` (arrow accent: sparing, never a large fill), gold-600 `#EAA916` (rating stars on white), indigo `#2A2E7A`, navy `#1B2150`, night navy `#12163A`. Gradients only where defined: ring, arrow, hero (`--gradient-*`). Avoid random purple-to-blue gradients.

**Charts** (`--data-1..6`): blue `#1272C4`, gold `#FDC134`, green `#2BA24B`, indigo `#2A2E7A`, red `#E23B2E`, navy `#1B2150`.

**Recon channels** (`--recon-ch-*`, all 4.5:1 on white): Local Services `#1272C4`, paid search `#5B53C9`, Google Business `#0E7F8A`, Organic `#2A2E7A`, Referral `#B9532A`, AI search `#8A6206`, Direct `#5D6779`. Never invent a channel color; add a token first.

**Status:** positive `#2BA24B` (text `#0B6B3A`), negative `#E23B2E` (text `#B32419`), caution `#EAA916` (small text on gold tints `#8A5A00`). **EEAT grades:** A `#0B6B3A`, B `#2BA24B`, C `#EAA916`, D `#E23B2E`, F `#B32419`.

Shadows are navy-tinted, never pure black. Dark mode is navy, never near-black.

## 2. Type

| Role | Face | Use |
|---|---|---|
| Display | Montserrat 700/800 (brand: Gotham) | Headlines. 800 at 40px and up with `-0.045em` tracking. Never 900. |
| Body | IBM Plex Sans 400–600 (brand: Avenir) | Body, UI, and big numbers (600, tabular lining figures) |
| Mono | IBM Plex Mono 500/600 | Eyebrows (uppercase, `0.12em`), labels, sources, dates only |

Font files: `design-system/assets/fonts/` (self-hosted woff2). Scale: display-xl 68, display-l 52, display-m 40, h1 32, h2 24, h3 20, body 16, small 14, caption 12px.

## 3. Shape and layout

Radii: chip 8, control 12, card 16, xl 24, pill 999px. Container 1200px (dashboards 1160px), narrow 760px, gutter 24px, sections 96px. Spacing 4px grid: 4, 8, 12, 16, 24, 32, 48, 64, 96, 128.

## 4. Logo

- Always the official ring-and-arrow logo. Never redraw it, retype the name or build a stand-in.
- Files: `design-system/assets/` (PNG masters; `optima-logo-white-text.svg` on dark, `optima-logo-black-text.svg` on light).
- Motion: ring pieces appear in turn, arrow shoots in and turns gold to green (about 2s). Static under reduced motion. Exports in `assets/logo-motion/`.

## 5. Imagery and voice

- No photos of people for now (headshots included). Drawn product visuals carry the proof. Bylines use an initials monogram on the brand gradient.
- Abstract aerial texture in the brand tritone is allowed in at most three places.
- Voice: "we" and "you", confident and specific, no emoji, no hype. Every number has a date and a source. Never name a client on a results surface.

## 6. Channels

| Channel | Rules |
|---|---|
| Website / theme | Theme templates on v2; patterns in `design-system/patterns/` (Signal Path, Market Map, Record Tiles, Recon Hero, Sample Dashboard, Glossary, Team Profile). Interaction states table in `design-system/readme.md`. |
| WordPress plugins | Read roles from `tokens.css` with fallbacks (see `CORE.md` §6). The existing `--optima-*` plugin contract maps onto these roles; `--optima-accent-ink` means "text on accent" = `--on-accent`. |
| Dashboards / Recon | Light default, navy dark mode. Charts use `--data-*` or `--recon-ch-*`. Sample data is always labeled "Sample data", round values, fictional firm. |
| Decks and one-pagers | `design-system/templates/`. Navy or canvas grounds, Montserrat headlines, one gold moment per slide. |
| Email | `CORE.md` §6 Email. Button `#1272C4` with white text; body `#5D6779` on white. |
| Print | `CORE.md` §6 Print defaults. Gold never as a large fill. |
