# Plugin First: plugins do the work, themes only fill gaps

Status: **proposed, waiting on Joy's four decisions (section 7).** Nothing below is built yet.

Joy, Nov 4 2026: "i want all the plugins to take over all theme edits... the theme should not do what the plugin also does... why would we build both? i want the plugin to pull design.md from the theme... i want plugin to do the lifting of all of it's responsibilities and then the theme only takes over if the plugin can't do it."

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

- Every theme ships `DESIGN.md` at its root: the human design rules (like Scout's `DESIGN.md`) **plus one machine block** the plugins read:

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
| RMN | Call bar → optima-contact. Case results → optima-results. Add `DESIGN.md`. |
| GJNOLA | Footer hours → optima-hours. Add `DESIGN.md`. |
| Collier | Theme forms → Gravity Forms (so the spam guard covers them). Add `optima-setup.json` and `DESIGN.md`. |
| `client-kit/template/` | Ships `DESIGN.md`, `optima-setup.json` and the `optima_owns()` wrapper pattern, so every new build starts plugin-first. |

## 5. Order (each step on staging, head-and-schema diff before and after)

1. Optima Build: `optima_owns()` and the `DESIGN.md` reader. Nothing changes on any site yet.
2. **RMN first** (every feature on, so it tests the most).
3. GJNOLA, then Collier.
4. **Optima last.** Its SEO and schema scored 11,250 in the Oct 28 audit; the core port must match it tag for tag before the theme hands over.

## 6. What this fixes

- One place to fix each job, on every site.
- No more doubled tags or "Not used on this site" exceptions.
- Plugin output looks like each client's brand without per-client CSS.

## 7. Decisions for Joy

1. **DESIGN.md format:** human rules plus one `optima-design` JSON block (above). OK?
2. **Order:** RMN first, Optima last (above), or Optima first?
3. **Retire Optima Site Kit** once its three jobs move into the plugins?
4. **Scout side:** port the same contract to Scout-Plugins and Scout themes (Scout's own rule already says features ship as plugins)?
