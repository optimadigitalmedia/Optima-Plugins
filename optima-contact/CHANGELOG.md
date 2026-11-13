# Changelog

## 0.1.11.12 (2026-11-13)

- When the call bar shows, the page gets scroll-padding-bottom equal to the bar's height plus the phone's safe-area inset, so a field or link reached with the keyboard never sits under the bar (WCAG 2.2, 2.4.11 Focus Not Obscured). The body padding that keeps the last content clear of the bar stays as it was.
- The Text button starts off on a new site (no Call buttons settings saved yet). Saved settings keep their value, and a site that saved its settings before the Text switch existed keeps Text as it was.
- The texting setting now asks "Is this number able to receive texts and monitored?". The Text button shows only when that is answered yes.
- Checked the event names against rule 50: the bar and the Let's talk sheet send call_click, text_click and booking_click. No change needed.

## 0.1.11.11 (2026-11-12)

- The call bar and the Let's talk sheet now send call_click, text_click and booking_click (rule 50), with location call_bar or lets_talk_sheet.
- The old contact_call, contact_text, contact_book and contact_open names go out only on sites that turn on old event names in Optima Analytics.
- Phone and text taps no longer send the number to analytics.
- Updates install only from the Optima Plugins release downloads (https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/). A release listed at any other address is skipped, and an update of an Optima plugin from any other address is refused with an error.
- Every download is checked against the sha256 published in the version list before WordPress unpacks it. A mismatch deletes the file, stops the install with an error and writes a line to the PHP error log.
- A release listed without a sha256 (one published before checksums were recorded) still installs as before, with a line in the PHP error log saying no checksum was checked.
- Automatic updates run only for a package from the Optima Plugins releases.
- The GitHub API fallback reads each asset's digest as its sha256, so the check works on that path too.

## 0.1.11.10 (2026-11-05)

- Optima admin kit 1.8.0.0 (dashboard release 3): grids use auto-fit, so three cards never leave an empty fourth column; Optima list screens wear the kit head; a screen that is part of another tab lights that tab; one 3px focus ring; green, gold, grey and red dots each mean one thing; tab rows on phones scroll with a fade on the edge that has more; a collapsed For developers box; facts typed on Details show read-only elsewhere (rule 45).
- The phone comes from Details and shows read-only (rule 45). Migration, once: a phone typed here and missing from Details moves there; the old value stays in optima_contact and is used only while Details has none. Off states grey; taps link to Analytics.

## 0.1.11.9 (2026-11-05)

- Optima admin kit 1.7.0.0, one nav (plugin-first rule 49): the WordPress sidebar is the only group navigation. A screen is a slim top bar (the client's logo and name, a Find box that opens the WordPress Command Palette, the Optima mark), the title with its group as the eyebrow, the lede, and one row of tabs. The hero band and the row of groups are gone. The Optima menu has eight items; People sits after Overview and holds the profiles and Cards.

## 0.1.11.8 (2026-11-04)

- Shared updater: release channels. A site on the stable channel (the default) sees only normal releases, exactly as before. A site on the test channel (Optima Build, Build, Updates, Release channel, or `OPTIMA_HUB_CHANNEL` in wp-config) also sees test releases (GitHub pre-releases) and takes the newest version from either. Without Optima Build a site is always on stable. This release goes out as a normal release so every site gets the channel-aware updater; test releases start with a later release.

## 0.1.11.7 (2026-11-04)

- Optima menu, seven items (shared admin kit 1.6.0.0): Overview, Your business, Content, Search and AI, Site health, Inbox, Build. Each screen's group comes from its address, so a label change never moves it; every screen keeps its address. The eyebrow on each screen names its group, the word the sidebar shows. Screens opened from a button now have a page title (no PHP notice).

## 0.1.11.6 (2026-11-04)

- Optima admin footer: no personal email address. It reads "Built and looked after by Optima Digital" with a Request a change link (shared admin kit 1.5.1.5).

## 0.1.11.5 (2026-11-04)

- Optima layer revision 5: visitor-facing styles now use the `--optima-*` design variables Optima Build 0.7.9.5 prints from the theme's DESIGN.md, so this feature dresses in each site's brand (plugin-first rule 7). Same look on sites without DESIGN.md.
- The bar renders in the theme's `body-end` slot (plugin-first rule 12); themes without slots still get it from the footer, once per page.

## 0.1.11.4 (2026-10-31)

- Optima release of upstream 0.1.11.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.1.9.3 (2026-10-28)

- Optima release of upstream 0.1.9.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.
- The call buttons take their font from the site (`font-family: inherit`) instead of naming Nunito Sans.

## 0.1.9.2 (2026-10-27)

- Optima release of upstream 0.1.9.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
