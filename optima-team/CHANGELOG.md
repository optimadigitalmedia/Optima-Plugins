# Changelog

## 0.1.11.1 (2026-11-05)

- Optima admin kit 1.8.0.0 (dashboard release 3): grids use auto-fit, so three cards never leave an empty fourth column; Optima list screens wear the kit head; a screen that is part of another tab lights that tab; one 3px focus ring; green, gold, grey and red dots each mean one thing; tab rows on phones scroll with a fade on the edge that has more; a collapsed For developers box; facts typed on Details show read-only elsewhere (rule 45).
- Profiles is the list's name everywhere; one way to add a person (Add New lands on Add a person); credentials read-only on the profile (rule 47) and kept on save; law-firm tile says Needs you when the business type is missing; the team table scrolls in its own box; Back to People links; flat steps not reached.

## 0.1.11.0 (2026-11-05)

- People (rule 47): the Team screen is named People and its list shows what each profile feeds (posts under the byline, case results naming them, pages they reviewed), with a "This profile feeds" box on the profile editor. Optima Case Results picks the attorney from here.
- Reviewed by: a box on every public post type names the reviewer from People and the date. The page prints one line at the theme's before-close slot and carries reviewedBy and lastReviewed in its schema with the reviewer's Person node. ?optima_reviewed_by=<id> filters a post list.
- Offboarding (rule 48), from "Start offboarding" on a profile: Review (everything on the site that names the person and what happens to each), Archive (a bundle under wp-content/optima-archive, closed to visitors, with a zip to put in the site repo and the firm's Drive; a failed write stops the flow), Confirm (one link emailed to every operator, a day's life, opened signed in by an operator other than the starter where the site has two or more), Remove (profile, photo, posts under the byline, results naming them and the card are deleted; every old address is forwarded through Optima Redirects; reviewed pages keep the date, lose the name and go on the Inbox). A former-people record with the archive link sits on the People screen. Stopping before the last step removes nothing.
- Optima admin kit 1.7.0.0, one nav (plugin-first rule 49): the WordPress sidebar is the only group navigation. A screen is a slim top bar (the client's logo and name, a Find box that opens the WordPress Command Palette, the Optima mark), the title with its group as the eyebrow, the lede, and one row of tabs. The hero band and the row of groups are gone. The Optima menu has eight items; People sits after Overview and holds the profiles and Cards.

## 0.1.10.0 (2026-11-05)

- Add a person: a three-step walkthrough on the Team screen (Optima, Your business, Team, Add a person). Step 1 picks someone the theme's setup file names who is not on the site yet, with the facts the file holds (or starts from a name and a title for someone the file does not have). Step 2 sets the photo (media library, or the file's photo brought in on creation), the byline user (nobody by default: a profile is a record, not an account), the attorney flag on a law site, and the place on the team page. Step 3 shows the trust checklist on what was gathered and creates the profile as a draft or published, then opens it. A half-finished walkthrough waits a day per operator; leaving early creates nothing. The old "Add a team member" link stays as "Blank profile". Test: `php tools/tests/team-add.php`.

## 0.1.9.8 (2026-11-04)

- Shared updater: release channels. A site on the stable channel (the default) sees only normal releases, exactly as before. A site on the test channel (Optima Build, Build, Updates, Release channel, or `OPTIMA_HUB_CHANNEL` in wp-config) also sees test releases (GitHub pre-releases) and takes the newest version from either. Without Optima Build a site is always on stable. This release goes out as a normal release so every site gets the channel-aware updater; test releases start with a later release.

## 0.1.9.7 (2026-11-04)

- Optima menu, seven items (shared admin kit 1.6.0.0): Overview, Your business, Content, Search and AI, Site health, Inbox, Build. Each screen's group comes from its address, so a label change never moves it; every screen keeps its address. The eyebrow on each screen names its group, the word the sidebar shows. Screens opened from a button now have a page title (no PHP notice).

## 0.1.9.6 (2026-11-04)

- Optima admin footer: no personal email address. It reads "Built and looked after by Optima Digital" with a Request a change link (shared admin kit 1.5.1.5).

## 0.1.9.5 (2026-11-04)

- Optima layer revision 5: visitor-facing styles now use the `--optima-*` design variables Optima Build 0.7.9.5 prints from the theme's DESIGN.md, so this feature dresses in each site's brand (plugin-first rule 7). Same look on sites without DESIGN.md.

## 0.1.9.4 (2026-10-31)

- Optima release of upstream 0.1.9.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.1.7.3 (2026-10-28)

- Optima release of upstream 0.1.7.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 0.1.7.2 (2026-10-27)

- Optima release of upstream 0.1.7.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
