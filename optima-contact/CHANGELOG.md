# Changelog

## 0.1.14.8 (2026-11-04)

- Optima release of upstream 0.1.14.
- **Files by the new Optima menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2).
- **Updates group in the shared Optima nav, and every group always shows.** Site Sync and the Change log sit under Updates (Optima → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Optima Site Standard rules 11 and 12).
- The update check, the release feed and the plugin details now use `optimadigitalmedia/Optima-Plugins`. GitHub still forwards the old address, which is how this release reached the site.
- Optima layer revision 8: upstream b2d477b: release rings, Site log and Your site, seven-item menu, Site Sync creates pages; Booking, Newsletter and Writing not carried yet.

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
