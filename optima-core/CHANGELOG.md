# Changelog

## 1.15.0.4 (2026-11-24)

- Fixed: the real visitor address behind a host's own proxy. On WP Engine (and most managed hosts) the request reaches WordPress from an internal address, so Cloudflare's header was ignored and every visitor counted as one address. Now, when the server's address is internal (private or loopback) and the address that reached the host, the right-most public entry in X-Forwarded-For, is one of Cloudflare's, CF-Connecting-IP is trusted. A visitor who reaches the host directly still cannot fake it. Found on Collier staging, 2026-11-24.
- New: `Optima_Core_Client_IP::seen()` (the server's address and the address that reached the host) for Site health.

## 1.15.0.3 (2026-11-23)

- New: the real visitor address behind Cloudflare. `optima_client_ip()` trusts Cloudflare's CF-Connecting-IP header only when the request came from Cloudflare's published address ranges (read 2026-11-23). Anything else uses the server's address, so a faked header is ignored.
- New: a site behind another proxy (a host load balancer) can name its ranges with the `optima_core_proxy_ranges` filter. The address is then taken from X-Forwarded-For, right to left, skipping trusted proxies. The `optima_core_client_ip` filter has the last word.
- Failed-login limit: now counts by `optima_client_ip()`, so a site behind Cloudflare locks out the real guesser, not Cloudflare. The `optima_core_login_limit_proxy_header` filter still works.
- Cache purge after a theme or Optima plugin update now calls every WP Engine purge method the install has (object cache, CDN, page cache, and the full page cache purge where present).
- New, optional: Cloudflare purge by URL. Set `OPTIMA_CF_ZONE_ID` and `OPTIMA_CF_PURGE_TOKEN` in wp-config.php (never stored, never shown). After an update Core drops the home page and the theme's style and script files from Cloudflare; after Optima Optimize rewrites an image, or a file is replaced in place with Enable Media Replace, it drops that image's addresses. 30 addresses per call, own domain only, never on staging, a failure logged once an hour.
- Any plugin can ask for a Cloudflare purge with `do_action( 'optima_core_purge_urls', $urls, $why )`.
- Spam protection tab: shows whether the optional Cloudflare Turnstile check is on.
- Fixed two comments that said host and Cloudflare security headers were seen and kept. They are added after WordPress and cannot be seen; Site health now checks the live page for doubles.

## 1.15.0.2 (2026-11-23)

- The admin menu icon is now Optima's tilted arrow (the logo's arrow), replacing a lightning bolt that was never Optima's mark.

## 1.15.0.1 (2026-11-20)

# optima-core: changelog bullets (INV-2611-01, 02, 07, 10)
- Staging guard (INV-2611-01): a staging copy now also refuses calls that would create real records. That covers intake and CRM systems (Lawmatics, Clio, HubSpot, LeadConnector), automation hooks (n8n cloud, Zapier, Make, Slack), call tracking, the attribution receiver on any Cloud Functions or Cloud Run address, and every Gravity Forms webhook feed, whatever address it points at. A test entry on staging never becomes a real lead.
- Staging guard: the new `optima_core_staging_allow_hosts` filter lets a site re-open a host it needs on staging. In both host lists, an entry covers its subdomains and "*" stands for one or more parts of the address ("hook.*.make.com", "*.run.app").
- Staging guard: each refused address is written to the PHP error log at most once an hour, and the staging notice now names intake, automation and webhook services.
- Failed-login limit (INV-2611-02, rule 19): after 5 failed logins in 15 minutes, that address or that username waits 15 minutes, even with the right password. A good login clears the count. The login screen shows one plain sentence for every failed login, so it never says whether a username exists. Counts are stored under a salted hash, never the address or the name.
- The failed-login limit is the seventh switch on Search and AI, Spam protection, on by default. Turn it off there or with `login_limit` in OPTIMA_CORE_PROTECTION_OFF. Filters change the number of tries, the time window and the lockout, and a site behind Cloudflare can choose to trust its visitor address header.
- Offices (INV-2611-07): while Optima Hours owns the hours job, each office's schema takes the Optima Hours schedule (opening and holiday hours) instead of the office's own walk-in rows, so the hours have one owner. Without Optima Hours, offices print their own hours as before.
- Change log (INV-2611-10): deactivating Optima Core now clears the weekly email's scheduled event.
- Shared admin kit: the four Optima Law screens are filed in the menu (three under Content, form entries under Build).

## 1.15.0.0 (2026-11-19)

Optima release of upstream 1.15.0 (from 1.6.0), ported selectively. Every Optima revision from 1.6.0.1 to 1.6.0.16 is kept: the law-site blueprint, offices, breadcrumbs, primary practice, the content-changed date, no self-review schema, the sitemap and noindex rules, the setup-description health fix, JSON_HEX_TAG, editable pages, private pages and the email guard. Where upstream and Optima changed the same code, Optima's later rule wins.

**Security (plugin-first rule 19)**

- Four new switches on Optima, Search and AI, Spam protection, all on by default, so they turn on with this update: login names hidden from visitors (the REST users list), XML-RPC off (unless Jetpack needs it), basic browser protections (`nosniff`, `SAMEORIGIN` framing, a referrer policy, HSTS on HTTPS, WordPress version hidden; a header the host or Cloudflare already sends is kept), and the code editor off. The code editor switch also takes away the edit-code permissions, so it works on WP Engine, whose wp-config.php sets DISALLOW_FILE_EDIT to false. The screen opens with any switch that is off.
- A site that needs one off records why in its decisions log, then turns it off on the screen, or in wp-config.php with `define( 'OPTIMA_CORE_PROTECTION_OFF', 'xmlrpc_off' );` (comma list), or with the `optima_core_protection_on` filter.
- **Staging never reaches real people.** On a staging copy (WordPress set to staging, development or local, or a host staging address), email goes only to operators (the addresses Optima Build's operator check recognizes, with the web-team partner domain kept as a hash) and the site's admin email; everyone else is dropped from To, Cc and Bcc. Calls to newsletter, email-marketing, texting, payment and Zapier services are refused. A notice on every dashboard screen says so. `OPTIMA_STAGING` in wp-config.php overrides the detection either way; filters `optima_core_staging_guard`, `optima_core_staging_mail_to`, `optima_core_staging_blocked_hosts`.

**Fixes**

- Drop-down (select) fields no longer save blank: the meta check now gets each field's choices. Values match ignoring case and space and are stored as the declared key. Values already saved blank stay blank until set again.
- Fields reach the REST API and the block editor: every type with fields supports custom fields (WordPress's raw Custom Fields box is removed again).
- Schema names are plain text: an apostrophe or `&` in a page title, FAQ question, post headline, excerpt, the site name or the business name no longer reaches Google as `&#8217;` or `&amp;`. Breadcrumb names in the schema too.
- Page 2 and on of a list names itself as canonical (`/page/2/`), not page 1. Not-found and search pages print no canonical and no `og:url` instead of the home page. The SEO box and the theme's `seo.pages` canonical still win.
- The blog list reads the posts page's SEO title (with ", page 2" past the first page), description, excerpt or text, instead of the tagline.
- Saving the SEO screen no longer wipes keys it does not show (`purge_on_uninstall`).

**Speed**

- The map (new, below) loads Google only on a tap.
- After a theme or Optima plugin update, Core purges WP Engine's caches (and WP Rocket's where it runs). Cloudflare still needs a hand purge. Filter `optima_core_purge_after_update`; action `optima_core_caches_purged`.

**New**

- **Change log** (Optima, Build, Change log; operators only): who changed what and when, for every edit by someone who is not an operator (pages, posts, templates, SEO fields, menus, photos, key settings, plugins, themes, users and roles, never passwords, the Customizer, Gravity Forms), so the edit can be folded into the site repo. Copy the report, then mark entries as in the repo; marked entries move to a kept archive. Operators, cron, WP-CLI, auto-updates and Approved copy are not logged. The weekly email is **off until an operator turns it on**, and goes to the address set there, else the site's admin email. Stored in its own table, `{prefix}optima_change_log`.
- **Approved copy carries lists and noindex.** A `meta` or `field` entry can hold a list (for example `_optima_aeo_faqs` rows of `{q, a}` for AI search), compared as canonical JSON and shown one line per entry. A page entry's `"noindex": true` ticks Hide from search; `false` clears a tick the sync set. Prerequisite carried with them: upstream's page creation (`"create": true`, posts arrive as drafts), the `file`, `seo_title`, `description` and `identity` aliases, and undo of what a run created.
- **Search Console and Bing verification** fields on Optima, Search and AI, SEO; paste the tag or the code. Printed on the front page.
- **Thank-you pages stay out of search:** Gravity Forms confirmation pages (a page, or a redirect on this site) and pages at thank-you or thanks get noindex and leave the sitemap. The front page and posts page never do. Filter `optima_core_thanks_pages`.
- **Language versions (hreflang)** in the SEO box: set a page's language and link the same page in another language. Links save both ways; the head gets `hreflang` and `x-default` tags; a page in another language gets that `<html lang>`. Drafts, noindexed, password-protected and thank-you pages are left out. Stands down for WPML, Polylang, TranslatePress and Weglot. Nothing changes until a page is linked.
- **Privacy and terms** (Optima, Content, Privacy and terms): a privacy policy and terms of service drafted from Details and what each Optima plugin that is on lists in its `visitor-data.json`, plus Gravity Forms. A plugin that is on with no list gets a [Confirm] line. Copy the email for the firm, mark it approved (who and when), then Make the draft page: always a draft, marked for the firm's approval, never published from here, never overwriting a page. The screen says when the site changed since approval.
- **`[optima_map]`**: a "Show the map" button over whatever the theme wraps (or a plain panel); the tap loads Google's map in place. The place is the address on Details, or an office with `office="<id>"`. Styled from `--optima-*` variables; the tap sends `directions_click` (location `map`).

**Skipped from upstream:** the design-token reader and its scales (Optima's DESIGN.md block is printed by Optima Build), upstream's menu filing and admin kit relabels (1.6.2 to 1.6.6, 1.9.0, 1.13.1, 1.13.3), its job-owner check (Optima keeps `optima_owns()` in Build), the release-address move, plans and branding, the updater and admin kit copies, upstream's breadcrumbs (Optima's own breadcrumbs from 1.6.0.16 stay the only ones), the heading word ties (a design rule, off by default upstream), the list of searches that found nothing (Optima has no such list, so there was nothing to keep from loading), the notices address and Business info screen changes (Details is Optima's own).

Tests: `tools/tests/core-staging-guard.php`, `core-security-headers.php`, `core-schema-plain-text.php`, `core-select-meta.php`, `core-seo-gaps.php`, `core-hreflang.php`, `core-map.php`, `core-policies.php`, `sync-array-meta-unit.php` (no WordPress needed).

## 1.6.0.16 (2026-11-13)

- **Offices (Your business, Offices).** A list of the firm's offices, typed once here: name, page address, street and suite, city, state, ZIP, phone, Google Place ID, latitude and longitude, Business Profile address, walk-in hours, phone hours, time zone, photo, parking, finding the door, nearby courthouses and practice slugs. Optima Build fills it from `offices` in the theme's optima-setup.json (by office id, blanks only, never removing one). Stored in `optima_core_offices`. A site with no offices is unchanged.
- **One schema entry per office, on its own page.** Each office page gets a node of the business type (a law firm prints LegalService) with `@id` set to the office page address plus `#office`, its address, telephone, geo (only when both coordinates have 5 or more decimals), `hasMap` (Google Maps with the Place ID), `openingHoursSpecification` from the walk-in hours, `parentOrganization` pointing at the firm, and an intake ContactPoint. The ContactPoint's `hoursAvailable` comes only from phone hours (the office's own, else Optima Hours, Phones answered) and `availableLanguage` only from `languages` in the setup file. The firm node lists every office that has a page with `subOrganization`, the schema.org inverse of `parentOrganization`. The Offices tab lists what to check: an office with no page, no Place ID, or coordinates with too few decimals.
- **Office cards in the `office-card` slot.** Name, address, a phone link (`call_click`), office hours and phones answered with the time zone ("Monday to Friday, 8:30 a.m. to 5:30 p.m. ET"), and Get directions to Google Maps with the Place ID (`directions_click`). The map variant shows a static map picture only when a key is set (Offices, or `OPTIMA_MAPS_KEY` in wp-config), else a link styled as a map; never an embedded map. With no offices the slot shows the one address on Details. Styles use only `--optima-*` variables.
- **Breadcrumbs.** A BreadcrumbList on every page but the home page, and a visible breadcrumb in the `breadcrumb` slot, both from the same trail: Home, parent pages, the page (posts under the posts page, other types under their archive). The page's WebPage entry points to it. It steps aside when the theme's schema already has one or lists every node itself. A private page's crumb reads Private page.
- **Primary practice.** Posts and pages get a Primary practice box in the editor sidebar with the 19 shared practice slugs, saved as `_optima_primary_practice` and readable over the REST API, so a practice page can list its related guides.
- **One "content last changed" date.** It moves only when a post's title or content actually changes, not on a save or a Site Sync apply that changes nothing. It is the schema's `dateModified`, the `article:modified_time` tag, the `%modified%` token and the sitemap's `lastmod` (new), and themes print it with `optima_content_changed( $post_id )`. Posts saved before this keep WordPress's modified date until their words next change.
- **Private pages in the REST API.** A password-protected page's title reads Private page in `/wp/v2/pages` (and posts and search) for anyone who cannot edit it.

## 1.6.0.15 (2026-11-13)

- **Law firms are LegalService, never Attorney.** Your business, Details no longer offers Attorney as a business type, because schema.org deprecates it for a firm. A site that saved Attorney keeps its saved value (it shows as its own choice with a note to pick Law firm), and the schema prints it as LegalService. The same goes for a business type of Attorney in the theme's setup data. Nothing saved changes.
- **WebSite schema: no search box, an optional other name.** The WebSite entry no longer carries a SearchAction. Google retired the sitelinks search box it fed. It can now carry `alternateName` (the name a business trades under, or a short name people type), printed only when set: typed on Search and AI, SEO (new optional field, one name per line), else `seo.alternate_name` in the theme's optima-setup.json. A theme's `seo.schema.website.alternateName` still works and wins.
- **Sitemap: testimonials and FAQs stay out.** They are quotes and answers shown inside pages, so their post types (Core's, or the same types from another plugin) no longer get a sitemap, and a single testimonial or FAQ page is marked noindex to match. The list can be changed with the `optima_core_sitemap_skip_types` filter. Noindexed archives, terms, author archives and pages (1.6.0.13) and empty taxonomies (WordPress leaves those out) already stayed out; a thank-you page stays out once it is noindexed in the SEO box or the theme's `seo.pages`.
- **Embeds name the organization.** When another site embeds or previews a page (oEmbed), the author is the business name and the site's address, never the WordPress user who made the page or their author archive.
- **Private pages stay private.** A password-protected page shows a generic title in public: its `<title>`, share title and Twitter title read "Private page" with the site name, it prints no description or image alt text, its schema WebPage is named "Private page", and it is marked noindex. Its oEmbed answer is a plain link titled "Private page" with no embed code or picture, its embed view shows the same title, and it prints no oEmbed discovery links. This holds even when the theme prints its own head. The words can be changed with the `optima_core_private_title` filter.

## 1.6.0.14 (2026-11-12)

- **Site health, Page descriptions:** a page whose address has a description in the theme's setup data (`optima-setup.json`, `seo.pages`) no longer counts as missing one. Core already prints that description; the old check told people to type one into the SEO box, which would have overridden the approved words. (From PR #26.)
- **No star rating built from testimonials.** The business entry in the schema no longer carries a star rating or Review entries made from the site's own testimonials. Hand-picked quotes are not a platform's full count, Google does not show self-serving review markup, and on a law-firm site they read as a rating the firm gave itself. Testimonials and how they show on pages do not change.
- A plugin that holds a review platform's real totals can still add a rating through the `optima_schema_aggregate_rating` filter, which now starts empty. The `optima_schema_review_limit` filter is gone with the Review entries. The old rating cache is still cleared on save, so a leftover copy goes away.
- **Safer schema output.** The JSON-LD block is encoded with `JSON_HEX_TAG`, so a `<` or `>` in any field (a page title, an excerpt, a business name) can never close the script tag early.
- Updates install only from the Optima Plugins release downloads (https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/). A release listed at any other address is skipped, and an update of an Optima plugin from any other address is refused with an error.
- Every download is checked against the sha256 published in the version list before WordPress unpacks it. A mismatch deletes the file, stops the install with an error and writes a line to the PHP error log.
- A release listed without a sha256 (one published before checksums were recorded) still installs as before, with a line in the PHP error log saying no checksum was checked.
- Automatic updates run only for a package from the Optima Plugins releases.
- The GitHub API fallback reads each asset's digest as its sha256, so the check works on that path too.

## 1.6.0.13 (2026-11-12)

- Sitemap agrees with noindex: when Core prints the head, whatever the theme's `seo.pages` robots rules noindex leaves WordPress's sitemap too. `:category`, `:tag` or `:archive` drops that taxonomy's sitemap, `:author` or `:archive` the author sitemap, a term's path drops that term, and a page's path, pattern or `:posts` drops that page (the SEO box's noindex already did). Before, a theme that handed its head to Core kept its noindexed category archive in wp-sitemap.xml.
- A `canonical` of `"%link%"` no longer crashes the page (it named itself, so working it out never ended); it now means the default canonical. A token whose value names itself (`%title%` in a title) gives nothing instead of looping, and a per-URL title naming `%title%` is ignored, so WordPress builds the title.

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
