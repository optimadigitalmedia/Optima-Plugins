# Changelog

## 0.4.3.0 (2026-11-23)

Brought in from 0.1.11 to 0.4.3:
- Sources on credentials. Every bar admission, education, award and board certification line carries a source: where someone can check it (the Knowledge Hub's source column, through the setup file). The profile page, the bio blocks and the Person schema show only lines with a source. Lines saved before this release, and lines from a setup file that carries no source yet, read "Added before sources were tracked" and keep showing, so no live page changes. A line whose source is sent empty is saved and stays off the site. The Credentials box on the profile shows each line's source. Memberships stay plain lines for now (the setup file fill hands them over as text).
- Shows on. Four switches per person: their own profile page, the team page, a business card and a byline. Existing profiles keep what they had: profile page, team page and byline on, card off. With the profile page off, visitors get "page not found", the page leaves the sitemap, the /team/ list and the site's own search, the team grid lists the person without a link, the schema drops the address and bylines keep WordPress's own author link. Editors can still open it, marked noindex. The team grid lists live people with Team page on.
- The card switch lives here only. Optima Cards reads it, so a person's card is switched on or off in one place.
- Business card box on the profile: intro, why talk to them, tags, card phone and card email. Card only: never on the profile page, the team page, the schema or the REST API. Optima Cards reads them through Optima_Team::card() and Optima_Team::card_ids().
- Needs a look. A person needs a look when details they sent wait for an OK, there is no photo, the photo has no alt text, a credential line has no source, or they are not live yet. The People screen lists them first, says why on each row, and has a Needs a look filter beside a filter for each team group (Attorneys first).
- The People screen shows where each person shows, and a Send them a link action on each row.
- A person's photo with no alt text set reads as their name wherever a theme prints it.
- Send them a link. On the People screen, the profile and at the end of Add a person (Save as a draft and send them a link). It makes a one-time link the operator copies and sends; nothing is emailed. The person fills in their own name, title, short bio and photo, and their card words when A business card is on. Credentials and profile links are never typed there (rule 47): the page lists them read-only and says to tell the firm about a mistake. The link ends after 14 days, works once, allows a few tries an hour, and only a keyed hash of it is stored. The page has a nonce and a hidden spam field, takes JPG, PNG or WebP photos up to 5 MB, is never listed by search engines or cached, sends no referrer and keeps the analytics tag off.
- What they send waits for an OK. It is kept apart from the live profile, shows on the profile as a now and as sent table, and is approved or discarded on the People screen, at the top. On a law site whose team bios need the firm's approval (Optima Law), Approve asks for the attorney who approved the words and a tick that the firm approved them; without both nothing changes, and with both the approval goes on Optima Law's record with the exact version.
- Privacy tools. WordPress's export and erase tools cover details waiting for an OK, found by the profile's email, its card email or its login.
- Bylines with more than one person. The Byline box now picks one or more people from People, in order, with Move up, Move down and Remove. A post saved before this release reads the same. People whose byline is off can be picked, with a note that the post leaves them out until it is on. The author name becomes "Ana Ruiz, Ben Cole & Dee Fox", the author link goes to the first person's bio (none when their page is off), the_author_posts_link() links each name, and the Article schema names every Person.
- Offboarding keeps a post someone wrote with others: their name comes off its byline and the post stays. Posts only they wrote are handled as before.
- Optima_Team::byline_people() gives who a post's byline credits, for other plugins.
- The REST API hides what it never should have shown. For anyone who cannot edit posts, the people list and the REST search leave out drafts and people whose own page is off, asking for one by ID answers "not found", and card only data, details waiting for an OK and link data are never sent. Editors and the block editor see everyone, as before.
- Reviewed by on plain pages. The reviewedBy and lastReviewed stamp now asks Optima Core for the page's WebPage node first when the graph has none, and stamps every node that describes a page. A plain page carries the reviewer without help from Optima Law; Optima Law's own step stays harmless. A theme's exact page graph is left as it lists it.
- Deleting the plugin now also removes every link and any details still waiting; with a full wipe it removes bylines that point at the deleted profiles too.

## 0.1.11.5 (2026-11-20)

- Shared admin kit: the four Optima Law screens are filed in the menu (three under Content, form entries under Build).

## 0.1.11.4 (2026-11-13)

- Board certification on each profile: a Certifications list (what the person is certified in, the certifying body, the year and a link to check it), read from the setup file like the other credentials. It shows on the bio as "Board certified in X by Y" and goes into the Person schema as hasCredential with credentialCategory certification and recognizedBy the body.
- Awards carry three more facts from the setup file: the wording (named to, included in, selected for), the issuer's "How this is awarded" page, printed as a link beside the award, and whether a fee was paid. On a law site an award with a fee paid is never printed, on the page or in the schema, and the profile's Credentials box says why. Awards saved before this release print as before.
- The at-a-glance card for a bio, through the theme's bio-glance slot: the name as on the bar roll, role, office, where licensed, board certification by its body, the headline honor with its year, languages, a Call button (call_click) and a Free consultation button when the firm offers one (People, Settings, Free consultation page). Variants card and split (with the photo). The profile below the card leaves out what the card already shows.
- The practice-attorneys slot: the attorneys whose practice areas include the practice page's slug, or who work at an office, each linking to the person's one bio. Variants portrait and horizontal.
- A Byline box on posts picks the author from People without a WordPress login. The post's Article schema names that Person (its id and bio address), the author name and link the theme prints point at the bio, and People counts the post under the person's bylines and offboarding includes it.
- A team group on each profile (attorney, intake, case management, paralegal, client care) and the offices the person works at. Once any profile has a group, the team grid lists attorneys first and then everyone else under their group heading; [optima_team group="no"] keeps one list. Sites with no groups set see no change.
- Add a person brings the credentials, the offices and the group the setup file carries.

## 0.1.11.3 (2026-11-13)

- Practice areas on each profile: tick case types from the shared practice-area list (car-accidents, truck-accidents, brain-injury and the rest) or add others, one per line. They show on the profile page and go into the Person schema as knowsAbout, each linked to the practice page when the site has a published page at that slug. Add a person brings them in from the setup file's practice_areas.
- The ProfilePage schema now carries dateCreated (the date the profile was published) beside dateModified.
- One page and one Person per attorney. The cause of the duplicate: every profile is its own public page under /team/, so on a site whose theme already has the bio (for example on /about/) the same person had a second page, a second sitemap entry and a second Person id, and a theme with no profile template showed it under an "Archives" heading. A profile can now name its bio page (Bio page address) and the Person id the theme's schema already uses. With a bio page set, the profile page forwards there (301), leaves the sitemap, and every link, byline, reviewed-by line and schema reference points there; on that page the profile's facts join the theme's Person node with the same id instead of adding a second one. Profiles without a bio page are unchanged.
- The /team/ list (the profile archive) is now noindex, follow. It still works for links; the team grid shortcode does not use it.
- Reviewed by: the line and the reviewedBy and lastReviewed schema print only when the record is complete: the reviewer, the review date, and the version of the page the review covers (a fingerprint of its words, taken when the review is entered). If the words change after that, nothing prints, the page's box says so, and People lists it under Reviews to fix as "Review out of date" until someone ticks that the reviewer read the page as it is now. A review saved before this release counts while the page has not been changed after its review date. Picking a reviewer no longer fills in today's date: a review with no date stays incomplete and prints nothing.

## 0.1.11.2 (2026-11-12)

- Updates install only from the Optima Plugins release downloads (https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/). A release listed at any other address is skipped, and an update of an Optima plugin from any other address is refused with an error.
- Every download is checked against the sha256 published in the version list before WordPress unpacks it. A mismatch deletes the file, stops the install with an error and writes a line to the PHP error log.
- A release listed without a sha256 (one published before checksums were recorded) still installs as before, with a line in the PHP error log saying no checksum was checked.
- Automatic updates run only for a package from the Optima Plugins releases.
- The GitHub API fallback reads each asset's digest as its sha256, so the check works on that path too.

## 0.1.11.1 (2026-11-05)

- No em dash in visible text: a missing title on Add a person reads Not set.
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
