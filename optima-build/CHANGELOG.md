# Changelog

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
