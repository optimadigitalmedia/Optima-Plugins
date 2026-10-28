# Changelog

## 0.1.6.4 (2026-10-28)

- **Fix: a new redirect works the moment it is saved.** Saving a redirect checked the old address first, which cached "no redirect here", and the cache clear that followed did nothing on WP Engine's object cache. New and imported redirects returned 404 for up to an hour (found on staging after the 27-row import). Lookups now use a generation number that every change bumps. Same fix as upstream 0.1.8.

## 0.1.6.3 (2026-10-28)

- Optima release of upstream 0.1.6.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 0.1.6.2 (2026-10-27)

- Optima release of upstream 0.1.6.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
