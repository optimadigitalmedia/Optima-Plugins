# Changelog

## 1.6.5.8 (2026-11-04)

- Optima release of upstream 1.6.5.
- **Site Sync creates the pages the bundle names and brings posts in as drafts** (Site Standard §6 and §12). A page entry with `"create": true` (or `"create_missing": true` at the top of the manifest) that the site lacks is created on the next run: pages publish, with their title, content, template, parent, menu order and front-page or posts-page setting; a new `posts` list works like `pages` with post_type `post` and arrives as drafts. The state reads "Will be created" and counts as a safe update, never as something to review. Undo trashes what the run created and puts the front-page settings back. A page without `create` is still reported as "Page not found", as before.
- **One manifest shape.** The entry keys the client themes grew on their own are read as aliases: `file` is `source`; `seo_title` and `description` on a page are its SEO fields; `identity` (`title`, `tagline`, `business.*`) sets the site title, tagline and Business info the way `options` does.
- Tests: `tools/tests/site-sync.php` gains the create, draft, alias and undo checks.
- **Hours live in one place.** With Optima Hours on, Business info no longer shows its free-text hours box; it points at Your business, Hours instead (Site Standard §7 v1.2: one fact, one place). The old text stays saved and untouched until the site's conversion copies it into Optima Hours.
- **Business info, SEO, Site Sync and Spam protection file by the new map.** Business info under Your business, Site Sync under Content, SEO and Spam protection under Search and AI, the Change log under Build. Shared admin kit 1.7.0.
- **`optima_design()`: every plugin reads the site's design from the theme's `DESIGN.md`** (Optima Site Standard §4, rule 3). The tokens on top of DESIGN.md (mode, colors, fonts, radius, logo, mark) are parsed with a small built-in reader (no YAML extension needed), the child theme over the parent, with gaps filled from the block theme's `theme.json` palette and fonts, then Optima's defaults. `optima_design( 'call buttons' )` lays a `## Feature: call buttons` section over the tokens. `optima_design_css()` prints a `:root` block of `--optima-*` variables, `optima_design_contrast()` and `optima_design_issues()` check the pairs §4 requires (button text on the accent fill, text on the page and on cards, 4.5:1), and the `optima_design` filter adjusts the result. Parsed once per request; forgotten on a theme switch or update.
- **`optima_core_owns( $job )`: a theme fallback runs only when no plugin does the job** (rule 6). `hours`, `call_bar`, `cards`, `reviews`, `chat`, `redirects`, `analytics`, `team`, `events`, `members`, `head`, `schema`, `sync`, `changelog` and the rest answer from the plugin classes that are loaded; `head` and `schema` say no while Optima Core still stands aside for a theme that prints its own. The `optima_core_owns` filter lets a plugin claim a job. The theme kit carries a stand-in that answers false when Optima Core is off.
- Test: `php tools/tests/design.php` (runs without WordPress; in the Lint workflow).
- **Updates group in the shared Optima nav, and every group always shows.** Site Sync and the Change log sit under Updates (Optima → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Optima Site Standard rules 11 and 12).
- The update check, the release feed and the plugin details now use `optimadigitalmedia/Optima-Plugins`. GitHub still forwards the old address, which is how this release reached the site.
- Optima layer revision 8: upstream b2d477b: release rings, Site log and Your site, seven-item menu, Site Sync creates pages; Booking, Newsletter and Writing not carried yet.
- Editable pages (Optima) carried forward unchanged.

## 1.6.0.9 (2026-11-04)

- Optima menu, seven items (shared admin kit 1.6.0.0): Overview, Your business, Content, Search and AI, Site health, Inbox, Build. Each screen's group comes from its address, so a label change never moves it; every screen keeps its address. The eyebrow on each screen names its group, the word the sidebar shows. Screens opened from a button now have a page title (no PHP notice).

## 1.6.0.8 (2026-11-04)

- Optima admin footer: no personal email address. It reads "Built and looked after by Optima Digital" with a Request a change link (shared admin kit 1.5.1.5).

## 1.6.0.7 (2026-11-04)

- Optima layer revision 7: **a theme's head can move to Core with no change in what search engines see.** New keys in the `seo` section of `optima-setup.json` (docs/plugin-first.md, section 3a), each optional; a site without the section prints what it printed before:
  - `site.production_url`: off the live host, robots gets `noindex, nofollow` and addresses written with the live origin move to the current host (JSON-LD, canonical, `og:url`, images). `site.robots_exact`: a noindex page drops `max-image-preview:large`.
  - `site.print` switches for tags a theme never printed (`og:locale`, `twitter:title`/`description`/`image`, `article:*_time`, the WebSite SearchAction, and `og:image` sizes as `auto`, `given` or off). `site.og_type` and `site.og_type_post`. `default_description` named as `""` or `false` means no description, never the tagline.
  - Special page keys `:search`, `:404`, `:posts`, `:category`, `:tag`, `:date`, `:author`, `:archive`. A search is no longer matched to the home page's entry.
  - Per URL: `canonical: false` (none), `og_url`, `schema_blocks` (print the nodes as separate script blocks or `@graph` groups, as the theme did), and tokens in titles, descriptions, addresses and schema (`%site%`, `%tagline%`, `%query%`, `%url%`, `%link%`, `%title%`, `%published%`, `%modified%`, `%page%`). Titles keep their spaces as written.
  - Schema: the organization's and website's own `@id` are kept and every reference follows them; a page node with the same `@id` as a site-wide one wins; a page that describes itself (WebPage, Article, `#page` ...) gets no extra WebPage or BlogPosting; `item` and `primaryImageOfPage` resolve like other addresses; a FAQPage follows the page's edited questions.
  - `schema.mode: "pages"`: each URL prints exactly the nodes it lists, `schema.print` names the site-wide nodes to add, a bare `{ "@id": ... }` stands for a site-wide node in place, and `schema.fallback` covers pages without their own. Business info adds nothing in this mode.
  - `schema.strip_inline_markers`: old injected JSON-LD blocks with those attributes are dropped from the head.
- Closing comments no longer removes a post's comment-feed `alternate` link from the head; WordPress prints it as it did before Core.
- Proved on three client sites against their theme baselines: every URL identical apart from author pages, which Optima Build closes to visitors.

## 1.6.0.6 (2026-11-04)

- Optima layer revision 6: **the theme's SEO facts drive the head.** When Optima Core prints the head (plugin first, jobs `seo` and `schema`), it now reads the `seo` section of the theme's `optima-setup.json`, which Optima Build 0.7.9.7 copies into the `optima_core_seo_setup` option. Per URL: title, meta description, canonical, robots, Open Graph title, description, type and image (with width, height and alt), Twitter card, extra meta tags and extra JSON-LD nodes; site-wide: a title format (`%page% | %site%`), default description and share image, site name, locale, robots and meta tags. Schema: the organization's type and fields are laid over the `#business` node, `sameAs` is merged, and Person and other nodes join the `@graph`. Theme paths (`assets/og/home.png`) resolve to the theme's address, `/about/` and `#id` to the site's. Order: the SEO box wins, then the theme's per-URL values, then Core's own fallbacks, then the theme's site defaults. Sites without the section print exactly what they printed before, plus `og:image:width`, `og:image:height` and `og:image:alt` for featured images. Spec: docs/plugin-first.md, section 3a.

## 1.6.0.5 (2026-11-04)

- Optima layer revision 5: visitor-facing styles now use the `--optima-*` design variables Optima Build 0.7.9.5 prints from the theme's DESIGN.md, so this feature dresses in each site's brand (plugin-first rule 7). Same look on sites without DESIGN.md.

## 1.6.0.4 (2026-10-31)

- Optima release of upstream 1.6.0.
- **Email addresses are hidden from spam bots.** Client sites printed their contact email in plain text (email links, visible text, the business details for search), and bots collected it, so clients got SEO spam. Optima Core now codes every address on the finished page before it is sent. Visitors see and click it as usual, and a page cache stores the coded copy. Inside the search data (JSON-LD) only the "@" is written as `\u0040`, so Google reads the same email. Scripts, styles, text boxes, form values and comments are left alone, and if anything goes wrong the page is sent unchanged. Filter `optima_core_email_guard` (true or false).
- **Comments and pingbacks are closed.** New comments and pingbacks on client sites mostly brought spam. With the switch on, pages and posts take no new comments or pingbacks, the `X-Pingback` header is gone, and the pingback XML-RPC methods are removed. Comments already saved stay in WordPress. Turn it off and the site works exactly as before. Filter `optima_core_close_comments` (true or false).
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Test: `php tools/tests/email-guard.php`.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.
- Editable pages (Optima) carried forward unchanged.

## 1.6.0.1 (2026-10-28)

- Editable pages (upstream 1.6.0 feature, Optima revision 1). A designed page's words and photos are edited on the page in the block editor; the layout, icons and theme parts stay locked with WordPress's content-only lock. Themes opt in with `add_theme_support( 'optima-editable-pages', array( 'content_dir' => ..., 'editor_css' => array( ... ) ) )` and call `optima_core_render_editable()` where the page body goes. Five blocks (`optima/box`, `optima/text`, `optima/image`, `optima/frozen`, `optima/part`), a Page photos panel, theme parts through the `optima_core_editable_part` filter, import that fills empty pages and refreshes untouched ones but never overwrites an edited page, and FAQ structured data rebuilt from the visible questions (`optima_schema_graph`, or `optima_core_editable_faq()` for themes that print their own schema).
- Administrators can rearrange a page with WordPress's "Edit pattern" button; clients and editors get the lock on the whole editor and no unlock button.
- Build tool for client repos: `tools/editable-pages/build_blocks.py` (not shipped in the plugin).
- Nothing changes for themes that do not opt in.

## 1.5.1.3 (2026-10-28)

- Optima release of upstream 1.5.1.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 1.5.1.2 (2026-10-27)

- Optima release of upstream 1.5.1.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
