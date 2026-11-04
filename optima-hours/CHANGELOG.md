# Changelog

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
