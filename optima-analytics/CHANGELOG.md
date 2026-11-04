# Changelog

## 0.1.18.8 (2026-11-04)

- Optima release of upstream 0.1.18.
- **Files by the new Optima menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2).
- **Updates group in the shared Optima nav, and every group always shows.** Site Sync and the Change log sit under Updates (Optima → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Optima Site Standard rules 11 and 12).
- The update check, the release feed and the plugin details now use `optimadigitalmedia/Optima-Plugins`. GitHub still forwards the old address, which is how this release reached the site.
- Optima layer revision 8: upstream b2d477b: release rings, Site log and Your site, seven-item menu, Site Sync creates pages; Booking, Newsletter and Writing not carried yet.

## 0.1.15.4 (2026-10-31)

- Optima release of upstream 0.1.15.
- **The cookie notice is readable on every site.** The notice now carries its own colors: light by default, dark when the page sets `data-theme="dark"`, and the OK button in the site's brand color from Optima Build 0.7.8, with dark text when that color is too light for white.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.1.13.3 (2026-10-28)

- Optima release of upstream 0.1.13.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.
- The cookie notice takes its font from the site (`font-family: inherit`) instead of naming Nunito Sans, so it matches whatever theme it sits on.

## 0.1.13.2 (2026-10-27)

- Optima release of upstream 0.1.13.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
