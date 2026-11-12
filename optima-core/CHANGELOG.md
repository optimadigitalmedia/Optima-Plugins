# Changelog

## 1.6.0.14 (2026-11-12)

- Schema: the business node no longer carries an `aggregateRating`, and testimonials no longer print as `Review` nodes, unless a site opts in with the `optima_schema_self_reviews` filter. Google does not show review stars for a business's reviews of itself (self-serving reviews), and on a law-firm site a review in the markup is a testimonial under the bar's advertising rules. Sites that printed them lose only those nodes; everything else in the graph is unchanged.

## 1.6.0.13 (2026-11-12)

- Site health, Page descriptions: a page whose address has a description in the theme's setup data (`optima-setup.json`, `seo.pages`) no longer counts as missing one. Core already prints that description; the old check told people to type one into the SEO box, which would have overridden the approved words. Found on a client staging site that listed 16 such pages.

## 1.6.0.12 (2026-11-05)

- No em dash in visible text: the empty choice in a select reads Select one.
- Optima admin kit 1.8.0.0 (dashboard release 3): grids use auto-fit, so three cards never leave an empty fourth column; Optima list screens wear the kit head; a screen that is part of another tab lights that tab; one 3px focus ring; green, gold, grey and red dots each mean one thing; tab rows on phones scroll with a fade on the edge that has more; a collapsed For developers box; facts typed on Details show read-only elsewhere (rule 45).
- Your business, Details (was Business info): grouped fields with where each shows, a business type picker, hours typed once (the box leaves Details when Optima Hours is on; a save never blanks a field it did not show), old values that differ listed for checking.
- Testimonials and FAQs list under Optima, Content. SEO opens with its checks and a media picker for the share image. Approved copy's empty state says what to do; applying from Build, Updates returns there. Spam protection shows the form guard's status. No SEO box on a person's profile.

## 1.6.0.11 (2026-11-05)

- Labels (rule 44): the Site Sync tab is named Approved copy, the name the plugin-first rules use. Its address does not change.
- Optima admin kit 1.7.0.0, one nav (plugin-first rule 49): the WordPress sidebar is the only group navigation. A screen is a slim top bar (the client's logo and name, a Find box that opens the WordPress Command Palette, the Optima mark), the title with its group as the eyebrow, the lede, and one row of tabs. The hero band and the row of groups are gone. The Optima menu has eight items; People sits after Overview and holds the profiles and Cards.

## 1.6.0.10 (2026-11-04)

- Shared updater: release channels. A site on the stable channel (the default) sees only normal releases, exactly as before. A site on the test channel (Optima Build, Build, Updates, Release channel, or `OPTIMA_HUB_CHANNEL` in wp-config) also sees test releases (GitHub pre-releases) and takes the newest version from either. Without Optima Build a site is always on stable. This release goes out as a normal release so every site gets the channel-aware updater; test releases start with a later release.

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
