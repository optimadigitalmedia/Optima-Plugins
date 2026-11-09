# Optima Plugins: rules for every session

The plugin suite behind every Optima client site. Before changing any plugin, read
`docs/plugin-first.md` section 0 (strict rules). The short version:

- **Plugins do the work.** Each job (SEO, schema, AI search, redirects, call bar, hours,
  team, results, reviews, cards, analytics, spam guard, chat, accessibility, content sync)
  belongs to one plugin. Themes only fall back when `optima_owns( $job )` is false.
- **Never build a feature twice.** New client-usable features start here, go in the job
  table and `catalog.json`, then get a theme fallback only if needed.
- **Plugins dress from the theme's `DESIGN.md`.** CSS uses only `--optima-*` variables;
  no hard-coded colors, fonts or radii; plugins never load fonts.
- **Same everywhere.** One Optima menu with eight items (Overview, People, Your business, Content,
  Search and AI, Site health, Inbox, Build), for operators only; each feature's settings on its tab
  inside one of them; the sidebar is the only group nav; blocks render through `optima_slot()` in fixed spots.
- **Prove it.** `tools/uniform-check.sh` passes, head-and-schema diff for every move,
  staging first, version bump and changelog line every release (`RELEASING.md`).
- **Load, update and build the same way.** Same `functions.php` skeleton and asset handles in
  every theme; plugins installed and updated only by Optima Build; every theme updates through
  Optima Build from its own GitHub repo; every release is a GitHub release built by the shared
  script; same plugin skeleton and admin kit.
- **One home, one path for content.** One repo per site (`optimadigitalmedia/<Client>`), the
  same folders in every repo, words only in `content/*.md`, delivered only through the theme
  release plus Optima → Build → Approved copy. Same SOP for every site, any size.
- Optima names only in code, comments and docs.

## Design system

`design-system/` holds the Optima design tokens (`tokens.css`, `tokens.json`) and rules (`CORE.md`, `BRAND.md`). Generated from the private master `sean-the-builder/GoogleWorkspace` › `design-system/unified/`; never edit them here. Plugins read the role tokens (`--surface`, `--text`, `--accent-fill`, `--on-accent`, `--r-card`) with fallbacks; the `--optima-*` contract maps onto them (`--optima-accent-ink` = `--on-accent`). See `design-system/CORE.md` §6.
