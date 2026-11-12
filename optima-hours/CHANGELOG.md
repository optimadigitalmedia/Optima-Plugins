# Changelog

## 0.1.8.12 (2026-11-12)

- **Safer schema output.** The opening hours JSON-LD is encoded with `JSON_HEX_TAG`, so a `<` or `>` in any field can never close the script tag early. The schema itself is unchanged.
- Updates install only from the Optima Plugins release downloads (https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/). A release listed at any other address is skipped, and an update of an Optima plugin from any other address is refused with an error.
- Every download is checked against the sha256 published in the version list before WordPress unpacks it. A mismatch deletes the file, stops the install with an error and writes a line to the PHP error log.
- A release listed without a sha256 (one published before checksums were recorded) still installs as before, with a line in the PHP error log saying no checksum was checked.
- Automatic updates run only for a package from the Optima Plugins releases.
- The GitHub API fallback reads each asset's digest as its sha256, so the check works on that path too.

## 0.1.8.11 (2026-11-05)

- Optima admin kit 1.8.0.0 (dashboard release 3): grids use auto-fit, so three cards never leave an empty fourth column; Optima list screens wear the kit head; a screen that is part of another tab lights that tab; one 3px focus ring; green, gold, grey and red dots each mean one thing; tab rows on phones scroll with a fade on the edge that has more; a collapsed For developers box; facts typed on Details show read-only elsewhere (rule 45).
- The screen title is Hours. Hours typed in the old box on Details show once for checking. Where it updates shows a neutral dot until the hours are confirmed. Kit-size Save; shortcodes in For developers.

## 0.1.8.10 (2026-11-05)

- Optima admin kit 1.7.0.0, one nav (plugin-first rule 49): the WordPress sidebar is the only group navigation. A screen is a slim top bar (the client's logo and name, a Find box that opens the WordPress Command Palette, the Optima mark), the title with its group as the eyebrow, the lede, and one row of tabs. The hero band and the row of groups are gone. The Optima menu has eight items; People sits after Overview and holds the profiles and Cards.

## 0.1.8.9 (2026-11-04)

- Shared updater: release channels. A site on the stable channel (the default) sees only normal releases, exactly as before. A site on the test channel (Optima Build, Build, Updates, Release channel, or `OPTIMA_HUB_CHANNEL` in wp-config) also sees test releases (GitHub pre-releases) and takes the newest version from either. Without Optima Build a site is always on stable. This release goes out as a normal release so every site gets the channel-aware updater; test releases start with a later release.

## 0.1.8.8 (2026-11-04)

- Optima menu, seven items (shared admin kit 1.6.0.0): Overview, Your business, Content, Search and AI, Site health, Inbox, Build. Each screen's group comes from its address, so a label change never moves it; every screen keeps its address. The eyebrow on each screen names its group, the word the sidebar shows. Screens opened from a button now have a page title (no PHP notice).

## 0.1.8.7 (2026-11-04)

- Optima admin footer: no personal email address. It reads "Built and looked after by Optima Digital" with a Request a change link (shared admin kit 1.5.1.5).

## 0.1.8.6 (2026-11-04)

- Optima layer revision 6: **hours fill the footer beside the address.** When the hours are confirmed and at least one day is open, they print in the theme's `footer-contact` slot as one line ("Monday to Friday, 9 a.m. to 5 p.m."), so `optima_slot_has( 'footer-contact' )` is true only when there is something to show. Filter `optima_hours_footer_show` picks `summary` (default), `table`, `status` or `both`, or `''` for none; `optima_hours_summary` changes the words. Styles use the theme's type and `--optima-*` variables only.

## 0.1.8.5 (2026-11-04)

- Optima layer revision 5: visitor-facing styles now use the `--optima-*` design variables Optima Build 0.7.9.5 prints from the theme's DESIGN.md, so this feature dresses in each site's brand (plugin-first rule 7). Same look on sites without DESIGN.md.

## 0.1.8.4 (2026-10-31)

- Optima release of upstream 0.1.8.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.1.6.3 (2026-10-28)

- Optima release of upstream 0.1.6.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 0.1.6.2 (2026-10-27)

- Optima release of upstream 0.1.6.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
