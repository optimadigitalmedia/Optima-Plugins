# Changelog

## 0.1.10.2 (2026-11-12)

- The firm's publishing threshold now holds on the site, not only in the dashboard: an approved result under the line no longer shows in the results grid or as a single result. A threshold of 0 or an empty box still means every approved result may show.
- A result whose amount has no figure in it (for example "Confidential settlement") shows once the firm approves it, since there is no number to compare. The dashboard now labels each result Yes, "No, not shown on the site" or "No amount to check", so the list matches what visitors see.
- A result limit (limit="6") now counts only results the site may show, newest result date first.
- The results stylesheet now loads in the page head on Elementor pages, Elementor templates and synced patterns that use the shortcode. Anywhere else it prints right beside the results with a few layout rules, so the grid never jumps when the page loads.
- Updates install only from the Optima Plugins release downloads (https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/). A release listed at any other address is skipped, and an update of an Optima plugin from any other address is refused with an error.
- Every download is checked against the sha256 published in the version list before WordPress unpacks it. A mismatch deletes the file, stops the install with an error and writes a line to the PHP error log.
- A release listed without a sha256 (one published before checksums were recorded) still installs as before, with a line in the PHP error log saying no checksum was checked.
- Automatic updates run only for a package from the Optima Plugins releases.
- The GitHub API fallback reads each asset's digest as its sha256, so the check works on that path too.

## 0.1.10.1 (2026-11-05)

- Optima admin kit 1.8.0.0 (dashboard release 3): grids use auto-fit, so three cards never leave an empty fourth column; Optima list screens wear the kit head; a screen that is part of another tab lights that tab; one 3px focus ring; green, gold, grey and red dots each mean one thing; tab rows on phones scroll with a fade on the edge that has more; a collapsed For developers box; facts typed on Details show read-only elsewhere (rule 45).
- One Case results tab with the results list on top (amount, type, year, attorney, above threshold, firm approval); Amount, Year and Above threshold columns on the WordPress list; a publishing threshold setting (default 1000000) that labels results and changes nothing on the site.

## 0.1.10.0 (2026-11-05)

- A result names its attorney from People (rule 47): a select on the editor, an Attorney column on the list, a filter by profile (?optima_attorney=<id>). The card prints "Attorney: <name>" linked to the profile page while it is published; a removed profile names nobody. Results without an attorney do not change.
- Labels (rule 44): the settings screen is named Case results, as the sidebar says.
- Optima admin kit 1.7.0.0, one nav (plugin-first rule 49): the WordPress sidebar is the only group navigation. A screen is a slim top bar (the client's logo and name, a Find box that opens the WordPress Command Palette, the Optima mark), the title with its group as the eyebrow, the lede, and one row of tabs. The hero band and the row of groups are gone. The Optima menu has eight items; People sits after Overview and holds the profiles and Cards.

## 0.1.9.8 (2026-11-04)

- Shared updater: release channels. A site on the stable channel (the default) sees only normal releases, exactly as before. A site on the test channel (Optima Build, Build, Updates, Release channel, or `OPTIMA_HUB_CHANNEL` in wp-config) also sees test releases (GitHub pre-releases) and takes the newest version from either. Without Optima Build a site is always on stable. This release goes out as a normal release so every site gets the channel-aware updater; test releases start with a later release.

## 0.1.9.7 (2026-11-04)

- Optima menu, seven items (shared admin kit 1.6.0.0): Overview, Your business, Content, Search and AI, Site health, Inbox, Build. Each screen's group comes from its address, so a label change never moves it; every screen keeps its address. The eyebrow on each screen names its group, the word the sidebar shows. Screens opened from a button now have a page title (no PHP notice).

## 0.1.9.6 (2026-11-04)

- Optima admin footer: no personal email address. It reads "Built and looked after by Optima Digital" with a Request a change link (shared admin kit 1.5.1.5).

## 0.1.9.5 (2026-11-04)

- Optima layer revision 5: visitor-facing styles now use the `--optima-*` design variables Optima Build 0.7.9.5 prints from the theme's DESIGN.md, so this feature dresses in each site's brand (plugin-first rule 7). Same look on sites without DESIGN.md.

## 0.1.9.4 (2026-10-31)

- Optima release of upstream 0.1.9.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.1.7.3 (2026-10-28)

- Optima release of upstream 0.1.7.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 0.1.7.2 (2026-10-27)

- Optima release of upstream 0.1.7.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
