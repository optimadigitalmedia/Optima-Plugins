# Plugin First: plugins do the work, themes only fill gaps

Status: **Adopted Nov 4, 2026. These rules are binding for every Optima plugin, theme and client build from now on.** The build-out (sections 4 and 5) is not done yet; until a site is converted, do not add new theme code for any job in the table in section 2.

## 0. Strict rules (read before touching any plugin or theme)

Every rule is a MUST or a NEVER (36 rules). If a task would break one, stop and ask the site owner first.

**Who does the work**

1. A plugin MUST own every job listed in section 2. When that plugin is on, it does the whole job: output, settings, schema and styling.
2. A theme MUST NEVER do a job a plugin owns, except as a fallback that runs only when `optima_owns( $job )` is false.
3. NEVER build the same feature twice. Before writing theme code, check the job table. If a plugin owns it, the work goes in the plugin.
4. A new job (anything a client could also use) MUST be built as a plugin feature first, added to the job table and `catalog.json`, then given a theme fallback only if one is needed.
5. Theme fallback code MUST live in `inc/fallbacks/<job>.php`, one file per job, and MUST start with the `optima_owns()` check.

**How things look**

6. Every theme MUST ship `DESIGN.md` at its root with one `optima-design` JSON block (section 3).
7. Plugin CSS MUST use only the `--optima-*` variables Optima Build prints from `DESIGN.md`. NEVER hard-code a color, font or radius in a plugin.
8. Plugins MUST NEVER load fonts. The theme loads them; plugins name them.

**Same everywhere**

9. Every site MUST have one top-level dashboard menu, **Optima Build**, in the order in section 4a.
10. A feature's settings MUST live only at Optima Build → <feature name>. NEVER under Settings, Tools, Appearance or a theme screen.
11. A theme MUST NEVER register its own admin menu for features. Design-only settings go under Optima Build → Design.
12. Plugin blocks MUST render only through `optima_slot( $name )` in the fixed spots in section 4a, so each feature appears in the same place on every site.
13. Every theme MUST have the required files in section 4a (`DESIGN.md`, `optima-setup.json`, `inc/fallbacks/`, `inc/slots.php`). Every plugin MUST have the required plugin files.
14. Client facts (team, hours, phone, results data) MUST live in `optima-setup.json`. NEVER hard-code them in templates.

**Proving it**

15. `tools/uniform-check.sh` MUST pass before any theme or plugin release.
16. Moving a job from a theme to a plugin MUST be checked with the head-and-schema diff (section 4): every tag and schema node present before is present after.
17. Every move lands on staging first, one site at a time, in the order in section 5.
18. Every plugin and theme change bumps its version and adds a changelog line, as today.

**Load the same way**

20. Every theme's `functions.php` MUST follow the same skeleton, in this order: `inc/setup.php`, `inc/slots.php`, every `inc/fallbacks/*.php`, then theme-only files. Nothing else at the top level.
21. Every theme MUST enqueue the same handles the same way: `optima-tokens` (the CSS variables), then `optima-theme` (the theme stylesheet), versioned with the theme version so every release clears caches. No inline `<style>` or `<script>` blocks except the ones Optima Build prints.
22. Plugins MUST load only through Optima Build: the hub installs, activates and updates them. NEVER install an Optima plugin by hand on a client site.

**Update the same way**

23. Every theme MUST update itself through Optima Build's theme updater from its own GitHub repo with a read-only key (as all four sites do today). NEVER ship a theme with its own updater code.
24. Every theme and plugin release MUST be a GitHub release whose zip is built by the same script (`build-theme-zip.sh` for themes, `RELEASING.md` for plugins), named `<slug>.zip`, with a semantic version that matches the file header and a changelog line.
25. Updates move forward only. Rollback is Optima Build's "Go back to vX" button, never a manual upload of an older zip.

**Build the same way on the back end**

26. Every theme MUST be a classic PHP theme with the folder layout in section 4a, the same PHP version floor as Optima Build, `php -l` clean, and its text domain equal to its folder name.
27. Every theme repo MUST carry the same release workflow and check scripts from `client-kit/template/` (`build-theme-zip.sh`, `uniform-check.sh`), copied, not rewritten.
28. Every plugin MUST be built from the same skeleton: main file with the standard header, `includes/class-<slug>-*.php`, settings pages from Optima Build's admin kit, and the shared updater. NEVER a plugin-specific settings framework.

**Content: one home, one path**

29. Every site MUST have exactly one repo, `optimadigitalmedia/<Client>`, and that repo is the only home of its words, design and theme. Its theme MUST update from that same repo. (Optima's theme still updates from `optima-digital-theme`; that moves into the site repo when Optima is converted, last.)
30. Every site repo MUST use the same folders: `content/pages/`, `content/posts/`, `content/results/` (case results for firms, case studies for Optima), `content/internal/` (never ships), `docs/` (brief, decisions, facts), `site/` (static build), `wordpress/<theme>/` (with `DESIGN.md` and `optima-setup.json`).
31. Words MUST live only in `content/*.md`. NEVER type or paste copy into WordPress by hand.
32. Approved copy MUST reach WordPress one way: the theme release carries it, and Optima Build → Content updates (Site Sync, owned by optima-core) applies it with a revision and an undo. A WordPress import file is used only for the first import of new posts.
33. Every site's Optima Build screen names its repo on the theme card. That is where its content lives; nothing else needs remembering.
34. All content lands on staging first. Live gets it only through the launch go-live card.
35. The same SOP (`client-kit/SOP.md`) and the same repo layout apply to every site, small or large. A bigger site has more pages, never a different structure.

**Naming**

36. Plugin and theme code, comments, readmes and docs in Optima repos use Optima names only.



## 1. The rule

- **A plugin owns each job.** When its plugin is on, the plugin does the whole job: output, settings, schema, styling.
- **The theme is the fallback only.** Theme code for a job runs only when the plugin for that job is off or missing.
- **The theme owns the look and the page layouts**, and nothing else a plugin can do.
- **The theme gives the plugins two files:** `DESIGN.md` (how things look) and `optima-setup.json` (the client's facts). Plugins read both.

This reverses today's rule. Today `catalog.json` uses `not_with_themes` to make the plugin step aside when the Optima theme already does the job, which is why Optima's theme and plugins both carry SEO, AI search, cards and more.

## 2. The contract (how theme and plugins agree who does what)

**`optima_owns( $job )`**, added to Optima Build (the hub is on every site, so the function always exists). True when the plugin that owns `$job` is active.

| Job | Owner plugin |
|---|---|
| `seo` (titles, meta, canonical, robots, OG, sitemap rules) | optima-core |
| `schema` (Organization, WebSite, Person, Article, Breadcrumb) | optima-core |
| `content-sync` (approved copy into posts) | optima-core (Site Sync) |
| `ai-search` (llms.txt, AI crawler rules, Q&A blocks, FAQ schema) | optima-aeo |
| `redirects` | optima-redirects |
| `accessibility` (statement page, checks) | optima-accessibility |
| `team` (profile pages, team grid, Person/ProfilePage schema) | optima-team |
| `cards` (digital business cards, link page) | optima-cards |
| `call-bar` | optima-contact |
| `results` (case results with disclaimers) | optima-results |
| `hours` | optima-hours |
| `reviews` | optima-reviews |
| `analytics` (Tag Manager, consent, click events) | optima-analytics |
| `forms-guard` | optima-forms-guard |
| `chat` | optima-chat |

**Every theme wraps its own version of a job:**

```php
if ( function_exists( 'optima_owns' ) && optima_owns( 'seo' ) ) {
    return; // the plugin does it
}
// theme fallback below
```

`catalog.json` drops `not_with_themes` and gains `jobs` per feature, so the Features screen shows "Replaces the theme's version" instead of "Not used on this site".

## 3. DESIGN.md: plugins dress in the theme's brand

- Every theme ships `DESIGN.md` at its root: the human design rules **plus one machine block** the plugins read:

  ````md
  ```json optima-design
  { "color": { "ink": "#12163A", "surface": "#FFFFFF", "canvas": "#F4F7FB", "accent": "#1272C4", "accent-ink": "#FFFFFF", "highlight": "#FDC134", "night": "#0B0E26", "positive": "#2BA24B" },
    "font": { "display": "Montserrat", "body": "IBM Plex Sans", "mono": "IBM Plex Mono" },
    "radius": { "sm": 8, "md": 12, "lg": 24 },
    "button": { "shape": "pill", "weight": 700 } }
  ```
  ````

- **Optima Build reads it** (cached until the file changes), prints the values once as CSS variables (`--optima-ink`, `--optima-accent`, ...) on the site and in the dashboard, and shows a Design tab so we can see what every plugin will look like.
- **Every plugin styles itself only with those variables**, with design-system defaults when a theme has no `DESIGN.md`.
- Fonts come from the theme (the theme already loads them); plugins name them, never load their own.

## 4. Files to build

### Optima-Plugins

| File | What it does |
|---|---|
| `optima-build/includes/class-optima-hub-owns.php` | `optima_owns()` and the job map above |
| `optima-build/includes/class-optima-hub-design.php` | Reads `DESIGN.md`, prints the CSS variables, Design tab |
| `catalog.json` (both copies) | `not_with_themes` removed, `jobs` added |
| optima-core: `seo/`, `schema/` | Port what Optima's theme does better today so nothing is lost: title and description fitting, Person/Org/Article/Breadcrumb nodes, per-page OG image, sources |
| optima-core: `post-types.php` | Takes over the case-study post type from Optima Site Kit |
| optima-core: `sync/` | Its Content updates screen replaces the theme's `inc/content-updates.php` |
| optima-aeo | Port the theme's `inc/llms.php` (post list, guide) and FAQ node builder |
| optima-team | Port the theme's `/about/<slug>/` profile pages and ProfilePage schema |
| optima-redirects | Import the theme's hard-coded 301s as manual redirects |
| Every plugin's CSS (contact, results, reviews, team, cards, hours, chat, accessibility, cookie notice) | Switch to `--optima-*` variables |
| `tools/` | A head-and-schema diff script: saves each page's `<head>` and JSON-LD before and after a switch, so a move never loses a tag |

### Themes

| Theme | Changes |
|---|---|
| Optima (`optima-digital-media/`) | Wrap SEO head, schema, llms.txt, redirects, accessibility page, team profiles, cards and content updates in `optima_owns()`. Add `DESIGN.md`. Retire Optima Site Kit (analytics → optima-analytics, spam guard → optima-forms-guard, case studies → optima-core). Zip script allows `DESIGN.md` as the one shipped `.md` (it holds only design tokens, which are public in the CSS anyway). |
| A client theme with its own call bar or results | Call bar → optima-contact. Case results → optima-results. Add `DESIGN.md`. |
| A client theme with hard-coded hours | Hours → optima-hours. Add `DESIGN.md`. |
| A client theme with its own forms | Theme forms → Gravity Forms (so the spam guard covers them). Add `optima-setup.json` and `DESIGN.md`. |
| `client-kit/template/` | Ships `DESIGN.md`, `optima-setup.json` and the `optima_owns()` wrapper pattern, so every new build starts plugin-first. |

## 4a. Same everywhere


**In the dashboard, every site looks the same:**

- One top-level menu, **Optima Build**, on every site, in the same order: Features, Business info, then one item per feature that has settings (Call buttons, Hours, Reviews, Team, Results, Cards, Chat, Redirects, Accessibility, Analytics), then Site health, Request a change.
- **A feature's settings live in one place:** Optima Build → that feature's name. Never under Settings, Tools, Appearance, or a theme page.
- **Themes put no feature settings anywhere.** A theme's own screen (if any) holds design only, under Optima Build → Design.
- Every feature card on the Features screen has the same buttons in the same place: Settings, Turn off, version.

**On the site, every feature sits in the same spot:**

- Call bar: bottom of the screen on phones. Cookie notice: bottom left. Chat: bottom right. Hours: footer, beside the address. Reviews: their own block, placed by the theme through one hook (`optima_slot( 'reviews' )`). Accessibility statement: `/accessibility/`. Team profiles: `/team/<name>/`. Case results: `/results/`.
- Themes place plugin blocks only through `optima_slot( $name )` calls in fixed template spots (header, footer, after-hero, before-close), so a feature lands in the same place on every site.

**Every theme has the same files:**

```
<theme>/
  DESIGN.md            design rules + the optima-design token block
  optima-setup.json    the client's facts and which features it uses
  inc/fallbacks/       one file per job, used only when its plugin is off
  inc/slots.php        where plugin blocks render
  templates, assets    the look
```

**Every plugin has the same files:** `<slug>.php` (header and version), `includes/`, `assets/css/` (variables only), `readme.txt`, and the same settings-page layout from Optima Build's admin kit.

**Checked by a script:** `tools/uniform-check.sh` fails if a theme is missing a required file, a theme registers a menu outside Optima Build, or a plugin hard-codes a color.

## 5. Order (each step on staging, head-and-schema diff before and after)

1. Optima Build: `optima_owns()`, `optima_slot()`, the `DESIGN.md` reader, the uniform menu and `tools/uniform-check.sh`. Nothing changes on any site yet.
2. **The client site with the most features on goes first**, so it tests the most.
3. The other client sites, one at a time.
4. **Optima last.** Its SEO and schema scored 11,250 in the Oct 28 audit; the core port must match it tag for tag before the theme hands over.

## 6. What this fixes

- One place to fix each job, on every site.
- No more doubled tags or "Not used on this site" exceptions.
- Plugin output looks like each client's brand without per-client CSS.

## 7. Defaults (the site owner can change any of these)

1. **DESIGN.md format:** human rules plus one `optima-design` JSON block.
2. **Order:** client sites first, most features first; the Optima theme last.
3. **Optima Site Kit** retires once its three jobs live in the plugins.
