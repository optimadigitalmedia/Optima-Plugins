# Changelog

## 0.2.4.11 (2026-11-12)

- The chat opens on a disclosure line, shown before the visitor types and saved with every conversation. The wording is a setting; the starting wording is marked for the firm to approve.
- On a law-firm site the chat stays off the site until an operator saves the disclosure once, and the Chat screen opens on that to-do.
- Saved conversations are deleted after 90 days by a daily job (a site can keep them only with a written reason), are reachable from WordPress's Export and Erase Personal Data tools by email, and are deleted with the plugin.
- Events now follow rule 50: chat_start on the first open in a visit, booking_click for the booking link, form_submit when a visitor sends their details. Older names go out only with old event names turned on.
- Updates install only from the Optima Plugins release downloads (https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/). A release listed at any other address is skipped, and an update of an Optima plugin from any other address is refused with an error.
- Every download is checked against the sha256 published in the version list before WordPress unpacks it. A mismatch deletes the file, stops the install with an error and writes a line to the PHP error log.
- A release listed without a sha256 (one published before checksums were recorded) still installs as before, with a line in the PHP error log saying no checksum was checked.
- Automatic updates run only for a package from the Optima Plugins releases.
- The GitHub API fallback reads each asset's digest as its sha256, so the check works on that path too.

## 0.2.4.10 (2026-11-05)

- Optima admin kit 1.8.0.0 (dashboard release 3): grids use auto-fit, so three cards never leave an empty fourth column; Optima list screens wear the kit head; a screen that is part of another tab lights that tab; one 3px focus ring; green, gold, grey and red dots each mean one thing; tab rows on phones scroll with a fade on the edge that has more; a collapsed For developers box; facts typed on Details show read-only elsewhere (rule 45).
- AI connection shows Connected or Not connected; the key belongs in wp-config.php (a saved key keeps working until removed). Chat questions show the day asked, No contact left, and Question for older entries. Labels and 24px switch targets.

## 0.2.4.9 (2026-11-05)

- Labels (rule 44): the settings screen is named Chat, as the sidebar says.
- Optima admin kit 1.7.0.0, one nav (plugin-first rule 49): the WordPress sidebar is the only group navigation. A screen is a slim top bar (the client's logo and name, a Find box that opens the WordPress Command Palette, the Optima mark), the title with its group as the eyebrow, the lede, and one row of tabs. The hero band and the row of groups are gone. The Optima menu has eight items; People sits after Overview and holds the profiles and Cards.

## 0.2.4.8 (2026-11-04)

- Shared updater: release channels. A site on the stable channel (the default) sees only normal releases, exactly as before. A site on the test channel (Optima Build, Build, Updates, Release channel, or `OPTIMA_HUB_CHANNEL` in wp-config) also sees test releases (GitHub pre-releases) and takes the newest version from either. Without Optima Build a site is always on stable. This release goes out as a normal release so every site gets the channel-aware updater; test releases start with a later release.

## 0.2.4.7 (2026-11-04)

- Optima menu, seven items (shared admin kit 1.6.0.0): Overview, Your business, Content, Search and AI, Site health, Inbox, Build. Each screen's group comes from its address, so a label change never moves it; every screen keeps its address. The eyebrow on each screen names its group, the word the sidebar shows. Screens opened from a button now have a page title (no PHP notice).

## 0.2.4.6 (2026-11-04)

- Optima admin footer: no personal email address. It reads "Built and looked after by Optima Digital" with a Request a change link (shared admin kit 1.5.1.5).

## 0.2.4.5 (2026-11-04)

- Optima layer revision 5: visitor-facing styles now use the `--optima-*` design variables Optima Build 0.7.9.5 prints from the theme's DESIGN.md, so this feature dresses in each site's brand (plugin-first rule 7). Same look on sites without DESIGN.md.

## 0.2.4.4 (2026-10-31)

- Optima release of upstream 0.2.4.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.2.2.3 (2026-10-28)

- Optima release of upstream 0.2.2.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.
- The chat widget takes its font from the site (`font-family: inherit`) instead of naming Nunito Sans.

## 0.2.2.2 (2026-10-27)

- Optima release of upstream 0.2.2.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
