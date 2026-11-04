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
- **Same everywhere.** One Optima Build menu in a fixed order; each feature's settings at
  Optima Build → <feature>; blocks render through `optima_slot()` in fixed spots.
- **Prove it.** `tools/uniform-check.sh` passes, head-and-schema diff for every move,
  staging first, version bump and changelog line every release (`RELEASING.md`).
- Optima names only in code, comments and docs.
