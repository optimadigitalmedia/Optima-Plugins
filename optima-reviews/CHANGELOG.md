# Changelog

## 0.8.1.0 (2026-11-23)

Brought in from 0.5.5 to 0.8.1:
- Review totals from the theme. The theme's optima-setup.json can carry each platform's rating, review count and profile link with the date they were checked (reviews.totals). The newest check wins: the theme's totals apply only when their date is a real date, not in the future, and newer than the last check of that platform. A save on the Reviews screen counts as a check, so a newer typed number stays. Totals apply whatever the rating, so a low rating is never held back.
- Each platform remembers when it was checked. A save on the Reviews screen, a Google sync, the daily Google check and the theme each stamp the rows they write, so a fresh Google number no longer makes an old Yelp number look fresh. The "as of" date beside the Google rating uses that platform's own check date. Rows saved before this release use the old single date until they are next saved.
- Reviews from the theme (reviews.items) are added once each, matched on source, reviewer and date. Reviews already on the site, hidden ones and ones in the trash are never edited, removed or brought back. While Google is connected and syncing, Google's own numbers and reviews win.
- On a law site a review from the theme is never published by code: it goes in as a draft and waits in the Review inbox for a person to approve it, whatever its stars, and the North Carolina money check applies there as for every other review.
- A reviewer name with "&" is no longer added twice: WordPress can store it as "&amp;", so names are compared as plain text.
- Check Google every day (off until a key is saved). The Reviews screen has a new box with a Google place ID and a Google API key (Places API, New). With both set, the site asks Google once a day for the rating and review count and puts them in the Google row under Platforms. Check Google now runs one check on demand, and saving the box runs the first one. No sign-in is needed and no reviews are brought in. Without a key nothing is scheduled and no request is made; the key is set per site.
- The key is kept safe: stored encrypted with the site's own security keys, shown only as "Saved" with a Replace button, and it can be set as OPTIMA_REVIEWS_PLACES_KEY in wp-config.php instead. It never goes in optima-setup.json, logs or REST output. If the site's security keys change, the box says the key can't be read and asks for it again.
- When a check fails, nothing changes. The last reason is kept in plain words (never the key, never Google's raw reply) and shown in the box. While the Business Profile sign-in is connected, its sync keeps the Google numbers and the daily check stands down. Deleting the plugin removes the key, the place ID and the daily check.
- Private notes go to "Send private notes to" when it is filled in, else to the site's shared notice address (the site admin email until Optima Core has its own notice address). An address already saved keeps getting the notes.
- Review cards read the theme's DESIGN.md colors, line, muted text, label font and corner (the --optima-* variables Optima Build prints) when the theme's own --surface, --line and the rest are not set. Sites that set them look exactly the same.
- The review note limit now count each visitor by their real address behind Cloudflare (Optima Core's `optima_client_ip()`), instead of Cloudflare's server address. Without Optima Core it works as before.

## 0.5.5.14 (2026-11-20)

- Ships visitor-data.json, so Optima Core's privacy draft lists private notes, the hashed note limit, review page visit totals, copied Google and hand-added reviews, and what Google sees when reviewer photos and review page fonts load.
- Shared admin kit: the four Optima Law screens are filed in the menu (three under Content, form entries under Build).

## 0.5.5.13 (2026-11-13)

- The practice-reviews slot for practice pages: approved reviews whose topic belongs to the page's practice area, each with "Read about our {practice} work" linking the practice page. A topic belongs to the practice page with its own address unless Display, Topics and practice pages names another. With no practice (home, office pages) it shows the featured reviews. The "Selected client reviews" label, the Google link and the law-site disclaimer from 0.5.5.12 come with it.
- A quote layout (one large quote per row) beside grid and list, for the slot and as [optima_reviews layout="quote"].
- A theme that replaced card.php keeps its own markup; copy the plugin's card.php to get the practice link.

## 0.5.5.12 (2026-11-13)

- Law-site rules for reviews. A site counts as a law firm's the way the other Optima plugins tell: the business type on Your business, Details, Optima Team's law mode, or a law rule set (the new one here, or the one saved under Case results).
- On a law site every review shown needs a person's approval. The "New 5-star reviews" auto-publish box is hidden and ignored (its saved value is kept for the day the site stops being a law site), and a review the old option already put on the site stays hidden until someone opens it and presses Update. The Reviews screen counts any such reviews.
- On a law site the star filter is always off (min_rating is read as 0 everywhere, the block's setting included), so no review is hidden for its rating.
- Any hand-picked or capped list (featured, a topic, or a count limit) prints "Selected client reviews" with a "Read all reviews on Google" link to the Google profile the plugin already stores. The Google rating in the summary shows "as of" the last good sync, or the day the totals were last saved when Google is not connected.
- The testimonial disclaimer is required on a law site and prints before the reviews, at body size in the text's own color. With no wording saved, the site prints a placeholder marked [VERIFY: counsel]; the Display section reads "Not approved" until counsel's wording is saved with a name in the new "Wording approved by" box. Sites that are not law firms keep the optional disclaimer under the reviews, as before.
- New "Law firm rule set" under Display, with the same eight choices as Case results (North Carolina, Virginia, Florida, Louisiana, District of Columbia, Maryland, South Carolina, another state). Left on "Same as Case results" it follows the Case results setting.
- North Carolina: the Review inbox flags any review with a "$" or a number near "settle", "verdict" or "award", and a flagged review cannot be approved (the inbox, Add all and the editor's Publish button all keep it a draft and say why). The disclaimer also prints before and after any review that talks about an outcome. Other law sites see the flag as a reminder only.
- A theme that replaced the section or card template keeps its own markup; copy the plugin's new section.php and card.php to get the label, the link and the disclaimer placement.

## 0.5.5.11 (2026-11-12)

- The reviews stylesheet now loads in the page head on Elementor pages, Elementor templates and synced patterns that use the shortcode or the block, so reviews no longer jump when the page loads.
- Reviews placed somewhere the head check cannot see (a theme slot, a widget, a cached Elementor widget) now carry their stylesheet and a few layout rules right beside them, printed once per page, instead of waiting for the footer.
- Pages without reviews still load no reviews styles, and turning the stylesheet off under Reviews settings still turns it off everywhere.
- Updates install only from the Optima Plugins release downloads (https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/). A release listed at any other address is skipped, and an update of an Optima plugin from any other address is refused with an error.
- Every download is checked against the sha256 published in the version list before WordPress unpacks it. A mismatch deletes the file, stops the install with an error and writes a line to the PHP error log.
- A release listed without a sha256 (one published before checksums were recorded) still installs as before, with a line in the PHP error log saying no checksum was checked.
- Automatic updates run only for a package from the Optima Plugins releases.
- The GitHub API fallback reads each asset's digest as its sha256, so the check works on that path too.

## 0.5.5.10 (2026-11-05)

- Optima admin kit 1.8.0.0 (dashboard release 3): grids use auto-fit, so three cards never leave an empty fourth column; Optima list screens wear the kit head; a screen that is part of another tab lights that tab; one 3px focus ring; green, gold, grey and red dots each mean one thing; tab rows on phones scroll with a fade on the edge that has more; a collapsed For developers box; facts typed on Details show read-only elsewhere (rule 45).
- One count (Optima_Reviews_Inbox::counts()) for the notice, the Review inbox and Optima Build. One Reviews tab (reviews, platforms, Ask for reviews, display). Review inbox and Ask for reviews use the kit with titles equal to their tabs. Platforms follow the kind of business; every input labeled. Ask for reviews reads the business name from Details and colors and fonts from the design tokens; saved values are kept.

## 0.5.5.9 (2026-11-05)

- Optima admin kit 1.7.0.0, one nav (plugin-first rule 49): the WordPress sidebar is the only group navigation. A screen is a slim top bar (the client's logo and name, a Find box that opens the WordPress Command Palette, the Optima mark), the title with its group as the eyebrow, the lede, and one row of tabs. The hero band and the row of groups are gone. The Optima menu has eight items; People sits after Overview and holds the profiles and Cards.

## 0.5.5.8 (2026-11-04)

- Shared updater: release channels. A site on the stable channel (the default) sees only normal releases, exactly as before. A site on the test channel (Optima Build, Build, Updates, Release channel, or `OPTIMA_HUB_CHANNEL` in wp-config) also sees test releases (GitHub pre-releases) and takes the newest version from either. Without Optima Build a site is always on stable. This release goes out as a normal release so every site gets the channel-aware updater; test releases start with a later release.

## 0.5.5.7 (2026-11-04)

- Optima menu, seven items (shared admin kit 1.6.0.0): Overview, Your business, Content, Search and AI, Site health, Inbox, Build. Each screen's group comes from its address, so a label change never moves it; every screen keeps its address. The eyebrow on each screen names its group, the word the sidebar shows. Screens opened from a button now have a page title (no PHP notice).

## 0.5.5.6 (2026-11-04)

- Optima admin footer: no personal email address. It reads "Built and looked after by Optima Digital" with a Request a change link (shared admin kit 1.5.1.5).

## 0.5.5.5 (2026-11-04)

- Optima layer revision 5: visitor-facing styles now use the `--optima-*` design variables Optima Build 0.7.9.5 prints from the theme's DESIGN.md, so this feature dresses in each site's brand (plugin-first rule 7). Same look on sites without DESIGN.md.
- The Ask for reviews screen no longer loads IBM Plex itself; the shared Optima admin kit already does. The standalone review page prints the design variables in its own head.

## 0.5.5.4 (2026-10-31)

- Optima release of upstream 0.5.5.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- **The site notes when the review totals were last updated.** Saving Review settings, or a Google sync, records the time. Optima Build 0.7.8 shows it in Site health as "Review totals" and asks for a look after 30 days, so the counts on the site keep up with the platforms.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.5.3.3 (2026-10-28)

- Optima release of upstream 0.5.3.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.
- Ask for reviews and Inbox screens load and use IBM Plex Sans in place of Nunito Sans. The review page's default heading and body font is now IBM Plex Sans (added to the font list). Saved font choices, including Nunito Sans, are kept.

## 0.5.3.2 (2026-10-27)

- Optima release of upstream 0.5.3.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
