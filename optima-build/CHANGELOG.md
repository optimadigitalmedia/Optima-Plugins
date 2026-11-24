# Changelog

## 0.7.11.9 (2026-11-24)

- Site health, Visitor address: when Cloudflare's header is not trusted, the note now says which addresses this visit came through (the server's, and the one that reached the host, and whether each is Cloudflare's), so the report shows what sits in between. Needs Optima Core 1.15.0.4.

## 0.7.11.8 (2026-11-23)

Brought in from upstream Build 0.8 to 0.17:
- Site health has a Security group (rule 19): Failed-login limit (moved here from Basics), XML-RPC, File editing, Admins, Two-step login, Backups and Security headers. The rows read Optima Core's Spam protection switches (on, forced_off, xmlrpc_needed, editor_locked) and say when Optima Core is missing or wp-config.php keeps a switch off. Each row is ok or look, never a fix. Only people who can manage the site see the group, because it names admins (never their email). The copied report gets a SECURITY block.
- Site health has an Outside plugins row (rule 51): every active plugin that is not an Optima plugin or Gravity Forms reads "outside the standard" (a look, never red) unless the theme's optima-setup.json lists its folder under approved_plugins.
- Saved secrets survive a change of the site's security keys. The theme key and the Email token or password keep an 8-character fingerprint of the keys they were saved with (never the keys or the secret). When the keys change they read "can't be read, paste again" instead of silently empty: Site health shows a red Site email row or a red Theme updates row, and Updates says to paste the theme key again. Keys saved earlier get their fingerprint the first time they are read. The Email screen itself is unchanged.
- A nightly form test, off until an operator turns it on in Build, Features: each ticked Gravity Forms form is sent around 2 am with made-up answers, its emails go out with [Test] in the subject, the test entry is deleted and add-on feeds are skipped. One miss is not an alarm; two failed nights in a row turn the Site health row red, list the form in the Inbox and email the operators, then once a week while it keeps failing. It waits on staging copies.
- Site health has a Form emails sent row: how many Gravity Forms notifications went out or failed in the last 7 days, naming the last failure with email addresses taken out.
- WordPress's own Site Health checks now run once a day with Build's daily check and are listed by name in a WordPress checks row, with items only the host can change set apart. WordPress's own badge stays in step.
- Direct answers counts FAQs added the same day: a page's check stops counting as soon as the page is saved, its Optima AEO answers change, or the theme or Optima AEO is updated, and it is checked again within minutes.
- Fill from theme takes a business section into Optima Core's Business info (blank fields only; same_as takes https links only).
- Fill from theme hands reviews totals, items and google_place_id to Optima Reviews when it can take them (Optima_Reviews_Theme, Optima_Reviews_Places). The API key never comes from the theme.
- The Review totals row says when the daily Google check has failed for three days, with the reason and a link to Review settings, and says "Google is checked every day" when it works.
- New tests: tools/tests/build-secret-health.php, build-security.php, build-outside-plugins.php, build-form-mail.php and build-form-test.php.
- Site health, Security: new row "Headers sent twice". Once a day the site loads its live home page and names any of the four browser protections (Strict-Transport-Security, X-Content-Type-Options, X-Frame-Options, Referrer-Policy) that arrive twice, and says to pick one owner: the host/CDN or Optima Core's switch. Never red.
- The daily header check now uses WordPress's safe request, runs once a day, and is skipped on a copy on someone's own computer.
- Site health, Security: new row "Visitor address" that says where visitor addresses come from (Cloudflare's header, a named proxy, or the server), and warns when a Cloudflare header arrives from outside Cloudflare's ranges.
- Site health: new row "Environment type" on WP Engine installs that do not set it: "Set the environment type on this install (production or staging) in WP Engine, so staging copies are always treated as staging." A look, never red.
- Inbox: new "Sent their details" list from Optima Team, for people who sent their details by link and wait for an OK. Each row opens the profile and says when it was sent.

## 0.7.11.7 (2026-11-23)

- The admin menu icon is now Optima's tilted arrow (the logo's arrow), replacing a lightning bolt that was never Optima's mark.

## 0.7.11.6 (2026-11-23)

- Email sends through Cloudflare Email Service first: smtp.mx.cloudflare.net, port 465 with implicit TLS, user name api_token, and a Cloudflare API token with Email Sending: Edit, stored encrypted like the app password or set as OPTIMA_MAIL_PASSWORD in wp-config.php. New setups start on Cloudflare.
- Google stays as the second route. A site saved before this release (no route saved) keeps sending through Google with its app password. A third route, Other mail service, takes any SMTP server, port and user name.
- Changing the route clears the saved token or password and the last test, since a Google app password is not a Cloudflare token. OPTIMA_MAIL_SERVICE in wp-config.php fixes the route.
- The Email screen opens on what needs doing: the Cloudflare checklist (DNS on Cloudflare, check DMARC, add the domain in Email Sending, create the token, paste and test, confirm spf, dkim and dmarc pass), with the domain's DMARC record and Cloudflare's cf-bounce records looked up live. Then Send test, then Nothing to do.
- The screen and the save notice warn when the send-from address is not on the site's own domain.
- Plain fixes for Cloudflare errors: 535 means the token is wrong or lacks Email Sending: Edit; 550 5.7.1 Sender denied means the domain is not added in Cloudflare Email Sending.
- Build, Updates and Site health say "Not set up: form emails go out from the host and often land in junk. Connect Cloudflare email so they come from your domain." until it is set up, then name the route, for example "Sending through Cloudflare as forms@example.com."
- New guide docs/email-sending.md and test tools/tests/mail-services.php.

## 0.7.11.5 (2026-11-20)

# optima-build: changelog bullets (INV-2611-02)
- Site health shows a new row, Failed-login limit, saying whether Optima Core's limit on failed logins is on. When it is off, the row links to Spam protection; when Optima Core is too old to have it, the row says to update Optima Core.
- Optima Law joins Build: its waiting items show in the Inbox and it owns the `law` job.
- Catalog: refreshed, read from the release channel, Optima Law added, the Optima theme no longer blocks Core, AI Search or Cards.
- Shared admin kit: the four Optima Law screens are filed in the menu (three under Content, form entries under Build).

## 0.7.11.4 (2026-11-20)

- Change requests and the hidden-site alert go to the site's operator administrators (or the site's admin email when it has none) instead of an address written into the plugin. `OPTIMA_HUB_REQUEST_EMAIL` and the two filters still set a different recipient.

## 0.7.11.3 (2026-11-13)

- Ten new template slots for the law-site page layer: breadcrumb, state-law, practice-results, practice-reviews, practice-attorneys, related-guides, next-steps, office-card, bio-glance and bio-results. Each prints only what its plugin attaches. When a theme passes no variant, optima_slot() fills it from the layout recipe's blocks.
- New optima_layout( $key, $default ), next to optima_slot(): reads the layout recipe (hero, home_order, practice_order, blocks, concept) with dotted keys such as blocks.results.
- Fill from theme now carries the new setup file sections. offices[] fills Optima Core's office list (optima_core_offices) by office id, blanks only, never removing an office. law fills Optima Results' state preset and publishing threshold only while they are unset or still the default, so an approved disclaimer is never moved. languages and layout are mirrored whole as optima_languages and optima_layout_recipe, and cleared when the theme drops them.
- Fill from theme keeps seo.alternate_name (a string or a list), so Optima Core can print WebSite alternateName.
- Team rows now fill practice_areas, bio_url and schema_id into blank fields of existing profiles. The team section runs once more so sites that already filled it get these.
- The Call buttons section also carries the What happens next steps, reply time and free consultation flag.
- Site health has a Page tree row: pages under a trashed parent, a second published page titled Home that is not the front page, and published content whose address ends in -copy or -2.

## 0.7.11.2 (2026-11-12)

- The feature catalog now comes from the version-list branch, not from main: catalog-test.json on the test channel, catalog.json (sent out by Promote) on stable. A catalog change reaches the test site first, like a release. The bundled catalog.json is still the fallback.
- Turn on and Go back install only a zip from the Optima Plugins releases, and check it against the sha256 in the version list before installing, whichever plugin's updater copy loaded first. A mismatch installs nothing and shows an error.
- Go back says plainly when the earlier release is no longer listed, instead of failing inside the installer.
- Site health's checks of the site's own pages now verify the HTTPS certificate on live sites and host staging addresses. Only a copy on someone's own computer (localhost, .local, .test, a private IP, or the environment set to local or development) skips the check. Core's https_local_ssl_verify filter can still change it for one site.
- Deleting the plugin also clears the test channel's cached catalog.
- Updates install only from the Optima Plugins release downloads (https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/). A release listed at any other address is skipped, and an update of an Optima plugin from any other address is refused with an error.
- Every download is checked against the sha256 published in the version list before WordPress unpacks it. A mismatch deletes the file, stops the install with an error and writes a line to the PHP error log.
- A release listed without a sha256 (one published before checksums were recorded) still installs as before, with a line in the PHP error log saying no checksum was checked.
- Automatic updates run only for a package from the Optima Plugins releases.
- The GitHub API fallback reads each asset's digest as its sha256, so the check works on that path too.

## 0.7.11.1 (2026-11-05)

- Updates polish: Site setup and Content updates leave the Build tab row (they are blocks on Updates); their tab shows only while that screen is open.
- Fill from theme again counts only setup sections whose plugin is active, so a section for a plugin the site does not run no longer shows Ready.
- Last update logs Optima plugin updates, including WordPress's automatic ones ("WordPress (automatic) updated Chat assistant to v0.2.4.10").

## 0.7.11.0 (2026-11-05)

- Optima admin kit 1.8.0.0 (dashboard release 3): grids use auto-fit, so three cards never leave an empty fourth column; Optima list screens wear the kit head; a screen that is part of another tab lights that tab; one 3px focus ring; green, gold, grey and red dots each mean one thing; tab rows on phones scroll with a fade on the edge that has more; a collapsed For developers box; facts typed on Details show read-only elsewhere (rule 45).
- One Update Screen: Build, Updates holds ten blocks in update order (site status with Copy for Claude, plugin updates, theme, fill from theme again, approved copy, release channel, email, what Site health flags, site lock, last update), each Needs you, Ready or Done. Update now updates a plugin or the theme in place through Optima_Hub_Features::handle() and returns to Updates; WordPress's bare update screen is no longer used.
- The release list asks again when it is older than an hour or WordPress's update list is gone; Overview, Updates and Features say when it was last checked.
- One count: change requests leave the Inbox count and have their own list; Inbox lists exactly what it counts and hides empty groups; review counts read the Review inbox's list.
- Overview: body-size status line with a state pill, the six dashboard tiles always (Not linked yet), plain last change, no link a viewer cannot open. Everyone who can view lands on Overview after sign-in.
- Site health: score line and one score per group, Copy for Claude beside it, HTTPS, test builds in one line, Real authors links to People. Features: one sentence, same card footer, Go back behind a menu and a confirm. Request a change: operators get the list with a status select that saves on change and Log a request.
- New screens: Your business, Details on a site without Optima Core (same option and address as Core's), and Content, Pages and posts. Details lists values typed on other screens before that differ. The WordPress Dashboard drops the Welcome panel and its own site-health box. site-build and site-build-features open the same tab on every site.

## 0.7.10.0 (2026-11-05)

- Overview is the operator glance (rule 46): one status line (health, updates waiting, Inbox count, last version check), the dashboards row (Recon, Analytics, Search Console, Business Profile and the host from a new optional "dashboards" section of the theme's setup file, plus the site's repo and the site itself), the queue (Updates, Inbox, Requests, each a short list with one action), and the site facts row (theme, Optima Build and channel, features on, last change, site lock). The client answers, everyday-job links and request box left this screen; the request form stays under Inbox, Request a change. An operator who signs in with no deep link lands on Overview.
- Inbox: a new kind, "Pages waiting for a reviewer", for pages whose reviewer was offboarded (Optima Team 0.1.11.0).
- Sidebar order follows the eight groups; the hub's rename map is empty now that every plugin names its screen the way the sidebar does (rule 44).
- Optima admin kit 1.7.0.0, one nav (plugin-first rule 49): the WordPress sidebar is the only group navigation. A screen is a slim top bar (the client's logo and name, a Find box that opens the WordPress Command Palette, the Optima mark), the title with its group as the eyebrow, the lede, and one row of tabs. The hero band and the row of groups are gone. The Optima menu has eight items; People sits after Overview and holds the profiles and Cards.

## 0.7.9.15 (2026-11-05)

- Build → Updates: every section (Features and Optima Build, Release channel, Approved copy, Theme setup, Site lock) is a padded box with space between, like the Theme box. They used the feature-card style, which drew them dashed and touching.

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
