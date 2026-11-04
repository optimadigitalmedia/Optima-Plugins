# Plugin First: plugins do the work, themes only fill gaps

Status: **Adopted Nov 4, 2026. These rules are binding for every Optima plugin, theme and client build from now on.** The build-out (sections 4 and 5) is not done yet; until a site is converted, do not add new theme code for any job in the table in section 2.

## 0. Strict rules (read before touching any plugin or theme)

Every rule is a MUST or a NEVER (43 rules). If a task would break one, stop and ask the site owner first.

**Who does the work**

1. A plugin MUST own every job listed in section 2. When that plugin is on, it does the whole job: output, settings, schema and styling.
2. A theme MUST NEVER do a job a plugin owns, except as a fallback that runs only when `optima_owns( $job )` is false.
3. NEVER build the same feature twice. Before writing theme code, check the job table. If a plugin owns it, the work goes in the plugin.
4. A new job (anything a client could also use) MUST be built as a plugin feature first, added to the job table and `catalog.json`, then given a theme fallback only if one is needed.
5. Theme fallback code MUST live in `inc/fallbacks/<job>.php`, one file per job, and MUST start with the `optima_owns()` check.

**How things look**

6. Every theme MUST ship `DESIGN.md` at its root with one `optima-design` JSON block (section 3).
7. Plugin CSS MUST use only the `--optima-*` variables Optima Build prints from `DESIGN.md`. NEVER hard-code a color, font or radius in a plugin.
8. Plugins MUST NEVER load fonts. The theme loads them; plugins name them. Only exception: a page a plugin renders without the theme (for example a standalone review page) loads the fonts named in DESIGN.md, and `tools/uniform-check.sh` lists each such file by name.

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
30. Every site repo MUST use the same folders: `content/pages/`, `content/blog/`, `content/results/` (case results for firms, case studies for Optima), `content/legal/`, `content/internal/` (never ships), `docs/` (brief, decisions, facts), `site/` (static build), `wordpress/<theme>/` (with `DESIGN.md` and `optima-setup.json`).
31. Words MUST live only in `content/*.md`. NEVER type or paste copy into WordPress by hand.
32. Approved copy MUST reach WordPress one way: the theme release carries it, and Optima Build → Content updates (Site Sync, owned by optima-core) applies it with a revision and an undo. A WordPress import file is used only for the first import of new posts.
33. Every site's Optima Build screen names its repo on the theme card. That is where its content lives; nothing else needs remembering.
34. All content lands on staging first. Live gets it only through the launch go-live card.
35. The same SOP (`client-kit/SOP.md`) and the same repo layout apply to every site, small or large. A bigger site has more pages, never a different structure.

**Running the fleet**

36. Every site MUST have a row in the agency's private fleet list (site, staging, live domain, host install, repo, plan, versions, access), updated the same day anything changes. Never in this public repo.
37. Every site MUST decide its update paths at kickoff and record them in its repo (`docs/11-update-paths.md`): theme template, editable field, plugin, or bulk import, chosen by how often the content changes.
38. A site that differs from this standard MUST record what and why in its decisions log, or be fixed.
39. Existing sites converge when touched: the next work session brings the site up to the standard one job at a time, never in one big rewrite.
40. Client repos MUST NEVER carry platform code. Themes and client-specific pieces yes; the shared plugins come only from this repo's releases.
41. Skills and check scripts MUST come from one source (the client kit) and be refreshed from it, never edited in a client repo. Each repo stamps the kit version it was refreshed from.
42. Every removal (feature, template, file, schema node, copy) MUST get a removed-log line in the same commit: what, why, where the job lives now, last commit with it. Versions only go up; changelogs are append-only.

**Naming**

43. Plugin and theme code, comments, readmes and docs in Optima repos use Optima names only.



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
| `account-privacy` | optima-build (on every site: author pages, the public users list and the users sitemap closed to visitors) |

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

## 3a. optima-setup.json: the `seo` section (the theme's head as data)

When a theme hands its SEO head and JSON-LD to Optima Core (jobs `seo` and `schema`), its old head code moves to `inc/fallbacks/seo.php` behind `optima_owns( 'seo' )`, and every value that code used moves into an `seo` section of `optima-setup.json`. Optima Core then prints the same tags from that data. Every key is optional.

```json
"seo": {
  "site": {
    "title_format": "%page% | %site%",
    "site_name": "Example Firm",
    "default_description": "One sentence for pages without their own.",
    "default_image": "assets/og/default.png",
    "image_width": 1200, "image_height": 630, "image_alt": "Example Firm",
    "twitter": "summary_large_image",
    "robots": "max-snippet:-1, max-video-preview:-1",
    "locale": "en_US",
    "meta": [ { "name": "theme-color", "content": "#12163A" }, { "name": "twitter:site", "content": "@example" } ]
  },
  "pages": {
    "/": { "title": "Example Firm | Injury Lawyers in Example City", "description": "...", "image": "assets/og/home.png", "og_type": "website" },
    "/about/": { "title": "...", "description": "...", "canonical": "/about/", "og_title": "...", "og_description": "...",
                 "schema": [ { "@type": "AboutPage", "@id": "/about/#webpage", "url": "/about/", "about": { "@id": "#business" } } ] },
    "/thank-you/": { "robots": "noindex, follow" },
    "/blog/*": { "og_type": "article" }
  },
  "schema": {
    "organization": { "@type": ["LegalService", "Attorney"], "name": "Example Firm", "telephone": "+1-555-555-0100",
                      "image": "assets/img/office.webp", "areaServed": "Example City", "priceRange": "Free consultation" },
    "website": { "name": "Example Firm" },
    "same_as": [ "https://www.facebook.com/example" ],
    "people": [ { "name": "Jane Example", "jobTitle": "Attorney", "url": "/about/jane-example/", "image": "assets/img/jane.webp" } ],
    "nodes": [ ]
  }
}
```

**Keys.**

| Where | Key | What it sets |
|---|---|---|
| `site` | `title_format` | Titles WordPress builds itself (no per-URL title): `%page%`, `%site%`, `%tagline%`. Not used on the home page. |
| `site` | `site_name` | `og:site_name` and `%site%` (default: the site title). |
| `site` | `default_description` | Last fallback for pages without their own. Named at all (even `""` or `false`), it replaces the tagline: `""` means no description. |
| `site` | `production_url` | The live origin (`https://example.com`). On any other host (staging, a local copy) robots gets `noindex, nofollow` and every address written with this origin (JSON-LD, canonical, `og:url`, images) moves to the current `home_url`. On the live host and its subdomains nothing changes. |
| `site` | `robots_exact` | `true`: a noindex page (a page's `robots`, search, 404, the SEO box, off production) drops the `max-image-preview:large` WordPress adds, as a theme that set robots by hand did. |
| `site` | `og_type`, `og_type_post` | Default `og:type` for pages and for posts (Core's defaults: `website`, `article`). |
| `site` | `print` | Switches for tags a theme never printed, each on when not named: `og_locale`, `twitter_title`, `twitter_description`, `twitter_image`, `article_times` (`article:published_time`/`modified_time`), `search_action` (the WebSite SearchAction), and `og_image_size`: `"auto"` (read a theme file's size), `"given"` (only sizes the data or the media library give), `false` (never). |
| `site`, `pages` | `image`/`default_image`, `image_width`, `image_height`, `image_alt` | `og:image`, `og:image:width`/`height`/`alt`, `twitter:image`. A theme image's size is read from the file when not given. |
| `site`, `pages` | `twitter` | `twitter:card`. |
| `site`, `pages` | `robots` | Added to WordPress's one robots tag, site-wide first, then the URL's (`noindex, follow`, `max-snippet:-1`). |
| `site` | `locale` | `og:locale`. |
| `site`, `pages` | `meta` | Any other `<meta>` the theme printed: `{ "name" or "property", "content" }`. |
| `pages` | `title` | The full `<title>`, used as written, spaces included (no format). With `%page%` in it, it is the format for that URL. Also `og:title` and `twitter:title` unless `og_title` is set. |
| `pages` | `description`, `canonical`, `og_title`, `og_description`, `og_type` | The matching tags. `canonical: false` prints no canonical (and no `og:url` unless `og_url` is given); `description: false` or `""` prints none. |
| `pages` | `og_url` | `og:url` when it is not the canonical (`"/"` for a theme that pointed archives at the home page); `false` for none. |
| `pages` | `schema` | JSON-LD nodes for that URL only. A node with the same `@id` as a site-wide one replaces it. A page node (WebPage or any subtype, Article, BlogPosting, FAQPage, or an `@id` ending in `#page`, `#webpage`, `#home`) stops Core adding its own WebPage and BlogPosting. A FAQPage takes its questions from the page's editable content (`optima_core_editable_faq()`) when that has the same number. |
| `pages` | `schema_blocks` | How the URL's nodes are printed: `[0, 0, 2]` is a node in its own script with its own `@context`, another, then one `@graph` of the next two; nodes left over go in a last block. Without it, graph mode prints one `@graph`; pages mode prints one node alone and several as one `@graph`. |
| `schema` | `organization` | Laid over Core's `#business` node key by key (the theme's facts win, Business info fills the rest). A given `@id` (`/#firm`, `https://example.com/#firm`) is kept, and every reference to `#business` (WebSite publisher, people, companions) follows it. `@type` may be a list. |
| `schema` | `website` | Laid over the `#website` node (its `name` defaults to `site.site_name` when set); a given `@id` is kept the same way. |
| `schema` | `same_as` | Added to the business `sameAs` list (duplicates dropped). |
| `schema` | `people` | Person nodes on every page. Defaults: `@type` Person, `@id` `/#person-<name>`, `worksFor` `/#business`. |
| `schema` | `nodes` | Any other site-wide nodes. |
| `schema` | `mode` | `"graph"` (default): Core's graph with the theme's facts merged in, as above. `"pages"`: exact. Each URL prints only the nodes its `schema` lists, in that order; nothing site-wide unless `print` says so; Business info adds nothing (no extra `sameAs`, no review nodes); Core adds no WebPage, BlogPosting or FAQ node. A bare `{ "@id": "/#firm" }` in a page's list stands for the site-wide node with that `@id`, printed whole in that place. |
| `schema` | `print` | Pages mode only: site-wide nodes printed before each page's own, in the order named. `{ "organization": true, "people": true, "website": ["/"] }`: `true` on every URL, a list of page keys for those only. |
| `schema` | `fallback` | Pages mode only: a node (or list) for singular pages with no `schema` of their own, with tokens: `{ "@type": "WebPage", "@id": "%link%#webpage", "url": "%link%", "name": "%title%", "about": { "@id": "/#firm" }, "dateModified": "%modified%" }`. Without it, such pages print nothing. |
| `schema` | `strip_inline_markers` | Attribute strings (`["data-no-minify=\"1\"", "data-cfasync=\"false\""]`): any `ld+json` script in the head whose tag carries all of them is dropped (an old injector's blocks), so only Core describes the site. |

**Page keys** are paths below the home URL with a trailing slash (`/`, `/about/`). A key ending in `*` (`/blog/*`) matches every deeper URL; the exact path wins, then the longest pattern.

**Special keys** cover requests with no path of their own: `:search` and `:404` (never matched by path, so a search is not read as the home page), `:posts` (the posts page), and `:category`, `:tag`, `:date`, `:author`, then `:archive` for any archive. For the posts page and archives the special key is laid over the path entry, if any (`:archive` first, the specific key last). Example: `":search": { "title": "Search: %query% | %site%", "canonical": "%url%", "robots": "noindex, follow" }`, `":404": { "canonical": false }`.

**Tokens** in titles, descriptions, `canonical`, `og_url` and every schema string: `%site%` (site name), `%tagline%`, `%query%` (search terms), `%url%` (this request's address, query included), `%link%` (the canonical address), `%title%` (the document title as printed), `%published%` and `%modified%` (the post's dates, ISO 8601), and `%page%` in a title (WordPress's page part). Use them for anything per request, so a `dateModified` never goes stale.

**Addresses.** `https://...` is used as is (moved to this host off production, see `production_url`). `/about/` and `#business` are on the site (`home_url`). A file name (`assets/og/home.png`) is a file in the theme, child theme first. Plain words stay plain. In schema this applies to `@id`, `url`, `image`, `logo`, `sameAs`, `contentUrl`, `thumbnailUrl`, `embedUrl`, `mainEntityOfPage`, `hasMap`, `item` (breadcrumbs), `primaryImageOfPage`, `significantLink`, `relatedLink`, `downloadUrl` and `urlTemplate`.

**Which value wins.** The Optima SEO box on the post (title, description, canonical, noindex) wins over everything. Then the theme's per-URL value. Then Core's own fallbacks (featured image, excerpt, term description, the editor's defaults on Optima Build → SEO; on the home page the editor's homepage description also beats `pages["/"].description`). Then the theme's `site` defaults.

**How it gets there.** Optima Build's setup filler copies the section whole (cleaned) into the option `optima_core_seo_setup` (not autoloaded: a big site's section runs to hundreds of KB), which Optima Core reads. Unlike the other sections it is a mirror of the theme, not blanks to fill: it is rewritten when the section or the theme version changes and on "Fill from theme again", and removed when the theme drops the section. It never touches the SEO box or Optima Build → SEO. The data applies only while Core prints the head (the theme does not declare `optima-seo-head` or define `optima_head`).

**Not expressible yet.** `<link>` tags other than canonical (preloads, `hreflang`; WordPress's own feed `alternate` links stay as they were), anything outside `<head>`, values computed per request beyond the tokens, automatic breadcrumbs, posts described from the post itself (fitted title and description, share card then category card), headers on text files (`X-Robots-Tag` on llms.txt is AI Search's), and per-post-type rules other than path patterns. Those stay in the theme (preloads are the theme's look anyway) or become a Core feature first.

**Proving a hand-over.** Snapshot every URL with the theme printing its head (Core off), switch Core on, fill from theme, snapshot again. Allowed differences: attribute quotes, ` />`, whitespace, the order of tags in the head and of keys in a JSON object. Everything else, including the JSON-LD blocks and the order of nodes, must match.

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

- Call bar: bottom of the screen on phones. Cookie notice: bottom left. Chat: bottom right. Hours: footer, beside the address (Optima Hours fills `optima_slot( 'footer-contact' )` with a one-line summary once the hours are confirmed; the slot stays empty, and `optima_slot_has( 'footer-contact' )` false, until then). Reviews: their own block, placed by the theme through one hook (`optima_slot( 'reviews' )`). Accessibility statement: `/accessibility/`. Team profiles: `/team/<name>/`. Case results: `/results/`.
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
