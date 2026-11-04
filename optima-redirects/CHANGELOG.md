# Changelog

## 0.1.9.7 (2026-11-04)

- Shared updater: release channels. A site on the stable channel (the default) sees only normal releases, exactly as before. A site on the test channel (Optima Build, Build, Updates, Release channel, or `OPTIMA_HUB_CHANNEL` in wp-config) also sees test releases (GitHub pre-releases) and takes the newest version from either. Without Optima Build a site is always on stable. This release goes out as a normal release so every site gets the channel-aware updater; test releases start with a later release.

## 0.1.9.6 (2026-11-04)

- Optima menu, seven items (shared admin kit 1.6.0.0): Overview, Your business, Content, Search and AI, Site health, Inbox, Build. Each screen's group comes from its address, so a label change never moves it; every screen keeps its address. The eyebrow on each screen names its group, the word the sidebar shows. Screens opened from a button now have a page title (no PHP notice).

## 0.1.9.5 (2026-11-04)

- Optima admin footer: no personal email address. It reads "Built and looked after by Optima Digital" with a Request a change link (shared admin kit 1.5.1.5).

## 0.1.9.4 (2026-10-31)

- Optima release of upstream 0.1.9.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.1.6.4 (2026-10-28)

- **Fix: a new redirect works the moment it is saved.** Saving a redirect checked the old address first, which cached "no redirect here", and the cache clear that followed did nothing on WP Engine's object cache. New and imported redirects returned 404 for up to an hour (found on staging after the 27-row import). Lookups now use a generation number that every change bumps. Same fix as upstream 0.1.8.

## 0.1.6.3 (2026-10-28)

- Optima release of upstream 0.1.6.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 0.1.6.2 (2026-10-27)

- Optima release of upstream 0.1.6.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
