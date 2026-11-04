# Changelog

## 0.3.1.8 (2026-11-04)

- Optima release of upstream 0.3.1.
- **Files by the new Optima menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2, Alex 2026-11-04).
- **Change requests from the chat join the site's request list.** When a visitor asks for a change in the chat and Optima Build is on the site, the ask is also saved as a change request under Optima, Request a change, with the person's name and how to reach them, so one list holds every request however it came in and the Site log records it. The chat inbox keeps its own copy as before. Sites without Optima Build change nothing.
- **Updates group in the shared Optima nav, and every group always shows.** Site Sync and the Change log sit under Updates (Optima → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Optima Site Standard rules 11 and 12).
- The update check, the release feed and the plugin details now use `optimadigitalmedia/Optima-Plugins`. GitHub still forwards the old address, which is how this release reached the site.
- Optima layer revision 8: upstream b2d477b: release rings, Site log and Your site, seven-item menu, Site Sync creates pages; Booking, Newsletter and Writing not carried yet.

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
