# Changelog

## 0.11.1.8 (2026-11-04)

Optima now tracks upstream through a vendor branch (`upstream-port`, ports only) merged into Optima's own work, so a port never overwrites it again. What is Optima's own in this release:

- **The site lock stays on top of the new screens.** Every Build screen opens with the lock's view capability, so a client under the lock still sees Overview, Site health, Inbox and Your site. Overview says "Changes to this site go through Optima"; the lock card sits on Updates. Under the lock a client sees only the groups they can open (no "Not on" labels on features that are on) and no everyday jobs they could not do.
- **Build stays operator-only.** A client who opens Features, Updates, Design or Email by its address lands on Overview.
- **Old addresses keep working.** Overview and Inbox moved to tabs of the Build page; `page=optima-overview` and `page=optima-inbox` forward there.
- **Hours not confirmed yet** shows in the Inbox and on Overview (for a client under the lock it opens Request a change).
- **Design** (the DESIGN.md tokens) files under Build beside Features and Updates.
- **No personal address in the admin footer**: it links to Request a change.
- Retired: Optima's own menu, Overview and Inbox classes (`class-optima-hub-menu.php`, `-overview.php`, `-inbox.php`, last on main at 71ab4b1); the upstream screens replace them. Kept: `optima_owns()` and slots, the DESIGN.md reader, site lock, account privacy, Core's `seo` setup section.
- Ring 1 is optimadigitalmedia.com and staging copies; ring 2 is set by hand (`OPTIMA_HUB_RING` or the Updates screen).

Upstream notes:

- Optima release of upstream 0.11.1.
- **Release rings (Site Standard rule 19).** A Optima plugin release now installs itself on optimadigitalmedia.com and staging copies first (ring 1), on sites set to ring 2 by hand a day later, and on client sites two days after it is out (ring 3). Until a site's turn the automatic update waits; Build, Updates says on each plugin's row when it installs, and Update now still works. The ring comes from `OPTIMA_HUB_RING` in wp-config.php, the setting on the Updates screen, or the address. A release whose date the version list does not carry is never held, so nothing changes until the Release workflow publishes dates (this release adds them).
- **Pause switch.** Build, Updates, "Pause automatic updates for 7 days" stops automatic Optima updates on one site; "Turn automatic updates back on" ends it early. Operators only.
- **Hold a release everywhere.** A `hold` entry in catalog.json (`{"slug": "optima-build", "version": "0.10.0", "note": "Fix coming"}`, or `"version": "*"`) stops that release's automatic update on every site that reads the catalog (within 12 hours, or at once with Check for new versions). The Updates screen shows the hold and its note.
- The version list (`releases.json` on the feed release) now carries each release's `published_at`.
- Test: `php tools/tests/release-rings.php <wordpress>` (17 checks).
- **Your site: the client's view of the Site log.** Anyone who is not a Optima operator opens the same screen as **Optima → Your site**: only lines marked "Client sees", in their client wording, with no versions, kinds or internal detail. Four parts: **Waiting on you** (anything Optima needs their OK on, with an Approve button), **This month**, **Your requests** (open ones, with the Request a change button) and **Coming up** (planned lines), then **Earlier this year**. It files under Overview for them (Build stays hidden), and the Overview's "With Optima" box links to it. Operators get a "See it as the client" link on the Site log. Step 3 of the Optima Client Hub.
- **Approve.** A line an operator marks "Waiting on you" and shows to the client carries an Approve button there. The Inbox counts "Waiting on your OK" with the other queues. "Ask for a change first" opens the request form with the line filled in. This replaces screenshotting a text into a decisions log.
- **The Optima box on the Dashboard** says how many updates the site had this month and how many things wait on the client's OK, with a link to Your site.
- **Hub keys made on the site, no wp-config edit.** No, and WP Engine turns the file editors off, so the keys the Optima Client Hub needs no longer require SFTP. On Optima → Site log, operators press **Make keys**: the site makes a read key (for the Sheet) and a write key (for Claude), shows them once, and stores them encrypted the way the theme key is stored. A client administrator never sees them again. **Make new keys** replaces the pair; **Forget the keys** turns the endpoint off. `OPTIMA_HUB_READ_TOKEN` and `OPTIMA_HUB_WRITE_TOKEN` in wp-config.php still work and win over keys made on the screen. Making keys writes a setup line in the log.
- **The Optima menu is built around who is looking (Site Standard §7, v1.2).** Seven items, the same words in the sidebar and on the screen: **Overview**, **Your business**, **Content**, **Search and AI**, **Site health**, **Inbox**, **Build**. Every existing screen keeps its address; only where it is filed and what the first screen is change. Shared admin kit 1.7.0, with a test (`tools/tests/admin-kit-groups.php`) so the filing never drifts from the map.
- **Overview, the first screen for everyone.** One line that says whether the site is all right (from Site health), "Needs you" with the Inbox counts, "With Optima" (updates waiting, the theme, the last three Site log lines), the four things a client comes here to do (change a page, write a post, update hours, ask for a review), and one line to ask for more: "Want something added or changed? Ask Optima."
- **Inbox: everything waiting on a person, one count.** Change requests, unanswered chat questions and new reviews, each with its count and a button, plus Ask for reviews. The sidebar shows the total as a bubble. Other plugins add a queue with the `optima_hub_inbox` filter.
- **Build is for operators.** Features, Updates and the Site log file under Build, and the admin kit hides the group from everyone who is not an operator (`OPTIMA_HUB_OPERATORS`), so a client never sees a turn-on button. The screens stay reachable by address for admins who know them; every action on them still checks for an operator.
- **Your business holds every fact once.** Business info, Call buttons, Hours, Team, Case results, Reviews and Review settings, Cards & Links, Events, Members and Partners file together. Content holds Site Sync, Redirects, Image optimizer and Writing. Search and AI holds SEO, AI search, Analytics, Accessibility, Spam protection and Site checks. Email, Chat settings, Customer list, the Change log and a theme's own screen sit under Build.
- **Site health: "Design contrast".** With Optima Core 1.6.3 or newer and a theme that has a DESIGN.md, Site health shows the lowest contrast ratio among the pairs the Site Standard requires (button text on the accent fill, text on the page and on cards) and names any pair under 4.5:1 (rule 10). Muted text under 4.5:1 is a warning, not a failure.
- **Brand color from DESIGN.md.** The Email screen's detected color (which the cookie notice and form emails use) now reads the theme's `accent_fill` from DESIGN.md before Optima Cards, Optima Reviews or theme.json. Sites without a DESIGN.md are unchanged.
- **The Site log: one line for every change to the site, written by the plugins that make it.** New screen, Optima, Site log. A Optima plugin updating logs its version and the first line of its changelog; the theme updating logs its version and its changelog headline; a feature turned on, off or sent back logs who did it; Site Sync logs how many pages got new copy; the setup file logs what it filled in; a change request logs when it comes in and when it moves; the hidden-from-Google alert logs when it goes out and when the site is visible again. Each line carries a kind, a topic, a status (done, planned, waiting on you), the version, a measure (before and after) and whether the client sees it. Lines shown to the client carry their own plain wording; the screen flags the ones that have none yet. A done line never changes: only its client wording, measure and client flag can be edited. Operators add notes for work done by hand, filter by month, kind, topic and status, and export the view as CSV. This is step 1 of the Optima Client Hub (spec: `docs/site-log-spec.md`).
- **Import history.** A one-time import of a site's earlier change record from a JSON file. Optima sites have no history files yet, so it stays unused.
- **A read endpoint for the Optima Client Hub.** `optima-build/v1/log`, `/requests` and `/site` answer a Bearer key set in `wp-config.php` (`OPTIMA_HUB_READ_TOKEN`; `OPTIMA_HUB_WRITE_TOKEN` can also `POST /log` note lines, which is how Claude logs work as it ships). `/site` returns the plan, WordPress, PHP, theme and every Optima plugin's version and update state, the Site health rows, the AI-ready count, open requests, review totals and spam counts. No visitor data, form entries, attachments or keys. 60 calls a minute per key; a call with the wrong key is noted in the log once an hour, with no address. Off until a key is set, and off on white-label sites unless `OPTIMA_HUB_LOG_API` is true.
- **Requests can be created by other plugins.** `Optima_Hub_Requests::create()` saves a request and fires `optima_hub_request_opened`; Optima Chat 0.3.0 uses it so chat requests join the same list. New actions for the log: `optima_hub_feature_done`, `optima_hub_request_status`, `optima_hub_setup_done`, `optima_hub_watch_alert`, `optima_hub_watch_cleared`. The log also listens to Optima Core's `optima_sync_applied`.
- **Not the same as Optima Core's Change log.** Optima Core 1.7.0's Change log (under Updates) records what someone who is not Optima edits on the site, so the repo can catch up. The Site log records what Optima does for the client, and what the client asked for. A later release feeds the first into the second as a line the client can see.
- **Optima → Updates: every update in one place.** A new Updates screen holds everything that brings a new version or new content onto the site (Site Standard rule 12): the Theme box (version, read-only key, Update the theme now), a Plugins table (every installed Optima plugin with its version, Up to date or Update ready, Updates paused, and Update now), Check for new versions, Fill from theme, Site Sync, Import posts (XML), Site settings, the Change log and the Recent changes log. The Features screen keeps the feature cards, the plan and the counts; the Theme box, the version line and the two buttons moved off it. Check for new versions and Fill from theme again now return to Updates. Site Sync and the Change log open their Optima Core screens; the cards say plainly when a tool is not on the site yet (no Optima Core, or Optima Core older than 1.7.0), and Site settings waits for its export tool (`optima_hub_site_settings_url` filter).
- **One menu named Optima on every site** (rule 11). When a theme still registers its own Build menu (optimadigitalmedia.com's "Optima Build", Recon's "Recon Build"), the hub relabels it Optima (Site Build on partner sites) so every dashboard reads the same until that theme's conversion drops the menu.
- **Theme updates keep downloading after a repo moves or is renamed.** The download check compared the two, skipped the key, and WordPress fetched the private file without it: "Download failed. Not Found." The check now also trusts the exact file address the release lookup returned, whatever owner and name it carries. Each theme picks up its new Update URI at its next release.
- **The feature catalog and Site health read from the new organization** (`optimadigitalmedia/Optima-Plugins`).
- The update check, the release feed and the plugin details now use `optimadigitalmedia/Optima-Plugins`. GitHub still forwards the old address, which is how this release reached the site.
- **Theme updates download when the repo name's capitals differ.** The download check compared the two letter for letter, so it skipped the key and WordPress fetched the private file without it: "Download failed. Not Found." The check now ignores capitals, the way GitHub does.
- Optima layer revision 8: upstream b2d477b: release rings, Site log and Your site, seven-item menu, Site Sync creates pages; Booking, Newsletter and Writing not carried yet.

## 0.7.9.14 (2026-11-04)

- **Release channel** (Build, Updates, operators only): Test means this site gets each release first, before the client sites; Stable (the default) means it gets releases after they are checked on the test site. One button switches it and checks for new versions straight away. Stored in the `optima_hub_channel` option; `define( 'OPTIMA_HUB_CHANNEL', 'test' );` in wp-config fixes it and the card then only shows it. Plugin updates only: theme updates still follow the theme's own repo.
- On the test channel the update queue and the feature cards label a test release (a GitHub pre-release) "Test release", and turning a feature on or going back a version can use test releases too. Stable sites never see them.
- Site health: a test release installed on a test-channel site is expected and no longer listed under Test builds; on a stable site it is listed as before. Test-channel sites get a Release channel row, which warns when an Optima plugin with an older updater loaded first (that copy ignores test releases until it is updated).
- Shared updater: release channels. A site on the stable channel (the default) sees only normal releases, exactly as before. A site on the test channel (Optima Build, Build, Updates, Release channel, or `OPTIMA_HUB_CHANNEL` in wp-config) also sees test releases (GitHub pre-releases) and takes the newest version from either. Without Optima Build a site is always on stable. This release goes out as a normal release so every site gets the channel-aware updater; test releases start with a later release.

## 0.7.9.13 (2026-11-04)

- Fix: clicking Optima in the sidebar opened a 404 on sites without Optima Core (the Optima section is created by Build there). Overview was added before its parent menu existed, so WordPress gave it the wrong address. It is now added after the section, and the menu link opens Overview on every site.

## 0.7.9.12 (2026-11-04)

- Fix: the web team's own accounts are operators again. 0.7.9.10 limited the default operators to administrators on the agency domain, which left the web team's administrator accounts (on the agency's web-team partner domain) outside Build and inside the site lock. Administrators on that domain are operators by default again; the domain is stored only as a hash. A site's own `OPTIMA_HUB_OPERATORS` list still replaces the defaults.

## 0.7.9.11 (2026-11-04)

- **The site lock waits for an operator account.** It applies only when at least one user on the site passes the operator check, so a site can never be locked with nobody able to edit it. The answer is kept in a transient (keyed to the operator list) and cleared whenever a user is added, changed, removed or given a new role. While it waits, administrators see "Site lock is waiting: no Optima operator account on this site yet." on Overview and in the WordPress Dashboard box, and the Site lock card on Build reads On, Waiting for an operator account, or Off.

## 0.7.9.10 (2026-11-04)

Dashboard redesign, release 1 (`docs/dashboard-redesign-plan.md`).

- **The Optima menu has seven items:** Overview, Your business, Content, Search and AI, Site health, Inbox, Build, in that order on every site. The sidebar shows the group names and each screen's eyebrow repeats them (rule 44). Every screen keeps its address and its page title.
- **Overview** (new, `admin.php?page=optima-overview`) is what the Optima menu opens on: one status line from Site health, Needs you (chat questions, new reviews, review request notes, hours not confirmed), With Optima (open change requests and where each stands), four everyday jobs as links (edit a team bio, write a post, change hours, ask for a review; each only when its feature is on), one request box that uses the Request a change handler and comes back to Overview, and "Want something added? Ask Optima". Operators also see a strip with updates waiting and the last version check.
- **Inbox** (new, `admin.php?page=optima-inbox`) lists everything waiting on a person from the stores the features already keep: change requests not done, chat questions not answered, new reviews and notes from the review page. Each row links to the screen that handles it. Read only. One count on the Inbox link in the sidebar.
- **Build is for operators only.** Its first view is the update queue (Check for new versions, the theme, plugin updates with one Update now button each, Fill from theme again, Approved copy, Theme setup); every button comes back to Build, and the WordPress update screens get a "Back to Build" link. Features, Updates, Design, Email, Site checks, Customer list, Site setup and Content updates sit under Build. Anyone else who opens Updates, Features, Design or Email by its address lands on Overview, and never sees turn-on buttons, the plan selector or update buttons.
- **Operators by default are the administrators on the agency's domain** (`@optimadigitalmedia.com`): a user whose address ends with it and who can manage the site's settings. `OPTIMA_HUB_OPERATORS` in wp-config still narrows or changes it; an entry starting with `@` is a whole domain, an exact address is one person (who must be able to manage plugins). No personal address is in the code any more.
- **Site lock, on by default: only operators edit the site.** Anyone else keeps their account and role, but while the lock is on cannot edit, publish or delete posts, pages or any other post type, upload files, change Optima, theme or WordPress settings, manage users, import or export, or install, activate or update plugins and themes (held back by a `user_has_cap` filter; no role changes in the database). They still see Overview, Site health and Inbox (read only), send requests, and Overview tells them: "Changes to this site go through Optima. Send a request below." Never applies to WP-CLI, cron or an operator. Off switches: the Site lock card on Build (operators only) or `add_filter( 'optima_build_site_lock', '__return_false' )`. Overview, Site health, Inbox and Request a change open with a new capability, `optima_view_hub`, given to anyone whose role can edit posts, so the lock does not hide them and subscribers never see them.
- **The WordPress Dashboard box** shows Overview's status line, what is waiting in the Inbox and the review totals, with Open Overview and Site health buttons.
- **The toolbar's Optima menu** opens Overview; Build in it shows to operators only.
- Site Sync shows under Content (it was hidden behind Updates); Approved copy on Build still links to it.

## 0.7.9.9 (2026-11-04)

- Optima admin footer: no personal email address. It reads "Built and looked after by Optima Digital" with a Request a change link (shared admin kit 1.5.1.5).
- Business info sits under Business in the Optima menu, next to Call buttons, Hours and Team (it was under Search).

## 0.7.9.8 (2026-11-04)

- **The `seo` section is no longer autoloaded.** A big site's section runs to hundreds of KB; Core reads it only on the front end.
- **Fill from theme keeps the new `seo` keys** Optima Core 1.6.0.7 reads: `production_url`, `robots_exact`, `print`, `og_type_post`, `og_url`, `schema_blocks`, `false` for no canonical or description, and the special page keys (`:search`, `:404`, `:posts`, `:archive` ...). Titles keep their spaces exactly as the theme printed them.
- **Fix: Call buttons stayed off after Fill from theme.** A new option now starts from the plugin's defaults (switched on included). An option an editor already saved is never re-enabled.
- Site setup and Content updates sit behind Updates: gone from the sidebar and the tab row, and Updates links to both.
- The Design screen uses the Optima layout, and its swatches wrap.
- The Features screen stays first in the Optima menu (the shared nav names it "Home", so the name sort missed it).

## 0.7.9.7 (2026-11-04)

- **Fill from theme carries the SEO head.** A new `seo` section in `optima-setup.json` (site defaults, per-URL title, description, canonical, robots, share tags, and JSON-LD for the organization, people and other nodes) is copied whole into the `optima_core_seo_setup` option that Optima Core 1.6.0.6 reads. It refreshes when the file or the theme version changes and on "Fill from theme again", and is removed when the theme drops the section. Editors' SEO box values are never touched. Spec: docs/plugin-first.md, section 3a.

## 0.7.9.6 (2026-11-04)

- **Account names stay private on every site** (new job `account-privacy`). For visitors, author pages and `?author=N` go to the About page (or home), the public REST users list and the users sitemap are closed, and author links point to About. Signed-in people and the editor are unaffected; bylines and schema keep the display name, so nothing visible changes. Login names are often email addresses and can show who has access to a site. Filters: `optima_build_account_privacy`, `optima_build_author_target`.

## 0.7.9.5 (2026-11-04)

- Optima layer revision 5: the plugin-first foundation (`docs/plugin-first.md`).
- **`optima_owns( $job )`.** Tells a theme whether a plugin owns a job (SEO, schema, content sync, AI search, redirects, accessibility, team, cards, call bar, results, hours, reviews, analytics, spam guard, chat). Themes run their own version only when it returns false. Filter: `optima_owns`.
- **`optima_slot( $name )`.** The fixed spots a theme offers for plugin blocks (header, after-hero, before-close, footer, footer-contact, body-end), so a feature lands in the same place on every site. Plugins attach with `add_action( 'optima_slot_<name>', ... )`.
- **Design from the theme.** Optima Build reads the `optima-design` block in the theme's `DESIGN.md` (cached until the file changes) and prints it once as `--optima-*` CSS variables on the site and in the dashboard. Without the file, the Optima design system values apply. New screen: Optima Build → Design shows the colors, fonts and corners every feature uses.
- **One menu, the same on every site.** Optima Build tidies the Optima menu last: one fixed order (Features, Business info, the features with settings, then Design, Updates, Site health, Request a change) and plain names ("Team", "Chat", "Case results", "Site check" instead of "... settings"). No plugin changes needed.
- **Updates: every update tool on one screen.** New Optima Build → Updates holds Check for new versions, the theme (version and its read-only key), Fill from theme again, and the way to apply approved copy (Optima Core's Site Sync, or the theme's Content updates screen when Core is off). Site Sync and Content updates leave the sidebar but keep their addresses. The Features screen keeps the plan and a link to Updates.
- **The theme's setup file fills the call buttons.** A `contact` section in `optima-setup.json` (phone, text, book_url, label, title, subtitle, text_note, book_note) fills Optima Call buttons where a value is blank or still the default.

## 0.7.9.4 (2026-10-31)

- Optima release of upstream 0.7.9.
- **"Test builds" no longer flags a client's own plugin.** The row now looks only at plugins that update from this repo.
- **Form emails in the site's own colors.** Gravity Forms notifications now arrive in the same card as the Email test: the site's color bar, its Site Icon, the site name, and the subject as the headline, with the form answers below. When the message has a phone number, it gets Call and Text buttons; when it has an email address, an Email back button that reads plain-text addresses too (the first one that is not the site's own). The headline leaves out the phone number, since it already has its Call button. Confirmations sent to the person who filled in the form get the card without those buttons. Light colors like Carolina blue get dark button text so it stays readable. Only HTML notifications, only while Optima Email is set up. Off switch: the `optima_mail_brand_forms` filter.
- **Other Optima plugins can use the site's brand color.** `Optima_Hub_Mail::brand_color()` returns the Email screen's pick or the detected color; Optima Analytics 0.1.15 colors the cookie notice's OK button with it.
- **Site health: "Hidden upload fields".** With Optima Forms Guard 0.3.0 or later, Site health lists any Gravity Forms File Upload field set to Hidden, with a link to fix it.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- **Site health: "Test builds".** Lists any active Optima plugin running a test build: a version with a label like `0.7.8-test.1`, or one newer than its newest release. Neither updates the normal way, and the row says which plugin and what release it waits for. It reads the release list the updater already keeps, so it adds no call to GitHub. `tools/test-build.sh` builds labeled test zips (see RELEASING.md).
- **Optima hears when a live site is hidden from Google.** When the live site has "Discourage search engines" ticked in Settings, Reading, Optima Build emails sean@optimadigitalmedia.com ("<Site name> is hidden from Google") as soon as the setting changes and from a daily check, at most once a week while it stays on, and shows administrators a notice. Staging addresses, non-production environments and local copies are left alone. `OPTIMA_HUB_REQUEST_EMAIL` or the `optima_build_alert_email` filter changes the address.
- **Site health: "Form emails go to".** Lists where each active Gravity Forms form sends its emails: the addresses (with {admin_email} filled in), "the address in field 3", or "routing rules". On a live site it asks for a look when a form sends no email or emails only Optima. The `optima_build_agency_domains` filter sets which domains count as Optima's.
- **Site health: "Review totals".** With Optima Reviews 0.5.5 or later, shows how long ago the review counts were last updated, and asks for a look after 30 days. Sites that have not saved the totals since updating read "Update the totals once to start tracking."
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.7.4.6 (2026-10-28)

- **Fix: Direct answers no longer counts password-protected pages.** A private partner page (WordPress page password) is read by no visitor and no AI tool, but it was checked as a main page and counted as one without answers. It is skipped like the legal pages. The count catches up at the next scan (within 12 hours).

## 0.7.4.5 (2026-10-28)

- Brings two fixes from a side branch into the main line, on top of revision 3 (staging ran 0.7.4.4 from that branch, so the main line now moves past it):
  - Site health, Search engines: a staging copy behind the host's password protection, or one the theme already marks noindex, reads "Kept out on staging" instead of asking for a password that is already on.
  - Features screen: recommended cards no longer show a stray "01" counter.
- Features screen: AI search is listed under "Not used on this site" with the Optima Digital theme, which already runs llms.txt, the AI crawler rules and the question-and-answer blocks. Running both replaced the theme's guide with a generic one.
- Revision 3 (already in 0.7.4.3): IBM Plex Sans on admin screens; the section nav shows on every list screen; settings tabs say "settings".

## 0.7.4.3 (2026-10-28)

- Optima release of upstream 0.7.4.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 0.7.4.2 (2026-10-27)

- Optima release of upstream 0.7.4.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
