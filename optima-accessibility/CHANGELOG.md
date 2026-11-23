# Changelog

## 0.1.22.0 (2026-11-23)

Brought in from 0.1.12 to 0.1.22:
- Describe a featured image right on Weekly checks. When a featured image has no description, Show where now shows the image, a "Describe the image" box and Save. Saving writes the image's alt text in the Media Library and clears it from the results at once, with no new run. Images inside the page content still link to the editor. Only someone who can edit that image can save it.
- A classic theme can say a template prints no page title with the new optima_accessibility_template_prints_title filter (for example a private partner page whose pasted block carries the page's one H1). The weekly heading check then reads that page's own H1 as its title instead of flagging a second H1.
- The statement's contact buttons and "last reviewed" line read the theme's DESIGN.md accent, button corner and muted text (the --optima-* variables Optima Build prints) when the theme does not set --optima-a11y-*. Sites that set them, or set neither, look exactly the same.
- Shorter help on the at-launch color contrast and keyboard checks.
- Ships visitor-data.json, so Optima Core's privacy draft notes that the statement page and footer link collect nothing and set no cookies.

## 0.1.12.12 (2026-11-20)

- The Accessibility link is on by default and prints in the theme's footer spot through optima_slot( 'footer' ) (rule 12). A theme without that slot still gets it once at the very end of the page.
- The link stays out when a menu already links to the statement page or the page printed [optima_accessibility_link], so a page never shows two.
- The Accessibility screen's checkbox still turns it off. A site that saved settings before this release reads as on, because off was the old default rather than a choice; turn it off again there if it should stay off.
- Shared admin kit: the four Optima Law screens are filed in the menu (three under Content, form entries under Build).

## 0.1.12.11 (2026-11-12)

- Updates install only from the Optima Plugins release downloads (https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/). A release listed at any other address is skipped, and an update of an Optima plugin from any other address is refused with an error.
- Every download is checked against the sha256 published in the version list before WordPress unpacks it. A mismatch deletes the file, stops the install with an error and writes a line to the PHP error log.
- A release listed without a sha256 (one published before checksums were recorded) still installs as before, with a line in the PHP error log saying no checksum was checked.
- Automatic updates run only for a package from the Optima Plugins releases.
- The GitHub API fallback reads each asset's digest as its sha256, so the check works on that path too.

## 0.1.12.10 (2026-11-05)

- Optima admin kit 1.8.0.0 (dashboard release 3): grids use auto-fit, so three cards never leave an empty fourth column; Optima list screens wear the kit head; a screen that is part of another tab lights that tab; one 3px focus ring; green, gold, grey and red dots each mean one thing; tab rows on phones scroll with a fade on the edge that has more; a collapsed For developers box; facts typed on Details show read-only elsewhere (rule 45).
- The statement phone comes from Details (migration once, old value kept). A weekly check past its date shows Late with Run now; the tab hint says Not run yet.

## 0.1.12.9 (2026-11-05)

- Optima admin kit 1.7.0.0, one nav (plugin-first rule 49): the WordPress sidebar is the only group navigation. A screen is a slim top bar (the client's logo and name, a Find box that opens the WordPress Command Palette, the Optima mark), the title with its group as the eyebrow, the lede, and one row of tabs. The hero band and the row of groups are gone. The Optima menu has eight items; People sits after Overview and holds the profiles and Cards.

## 0.1.12.8 (2026-11-04)

- Shared updater: release channels. A site on the stable channel (the default) sees only normal releases, exactly as before. A site on the test channel (Optima Build, Build, Updates, Release channel, or `OPTIMA_HUB_CHANNEL` in wp-config) also sees test releases (GitHub pre-releases) and takes the newest version from either. Without Optima Build a site is always on stable. This release goes out as a normal release so every site gets the channel-aware updater; test releases start with a later release.

## 0.1.12.7 (2026-11-04)

- Optima menu, seven items (shared admin kit 1.6.0.0): Overview, Your business, Content, Search and AI, Site health, Inbox, Build. Each screen's group comes from its address, so a label change never moves it; every screen keeps its address. The eyebrow on each screen names its group, the word the sidebar shows. Screens opened from a button now have a page title (no PHP notice).

## 0.1.12.6 (2026-11-04)

- Optima admin footer: no personal email address. It reads "Built and looked after by Optima Digital" with a Request a change link (shared admin kit 1.5.1.5).

## 0.1.12.5 (2026-11-04)

- The Weekly checks section no longer shows its title twice ("Weekly" above "Weekly checks").

## 0.1.12.4 (2026-10-31)

- Optima release of upstream 0.1.12.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.1.10.3 (2026-10-28)

- Optima release of upstream 0.1.10.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 0.1.10.2 (2026-10-27)

- Optima release of upstream 0.1.10.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
