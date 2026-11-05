# Dashboard redesign: release plan

Agreed by the site owner on 2026-11-04: menu name "Optima"; Optima brand with the
client's logo; clients never see turn-on buttons; rules 9, 10, 33 and section 4a
amended, rules 44 to 46 added (`docs/plugin-first.md`); first staging site is the
client site with the most features on.

Agreed by the site owner on 2026-11-05 (Stage 0 audit of release 1, with the
design on the operator's canvas): the dashboard is for operators only, since
clients never sign in and send every change to the agency; Overview becomes a
glance to leave from; the sidebar is the only group nav; one profile per person
feeds everything that names them; leaving is a four-step, email-confirmed
offboarding. Rules 9, 10, 46 and section 4a amended, rules 47 to 49 added.

**The target:** one Optima menu with eight items, the same words in the sidebar and
on the screen, one home an operator reads in a glance, one page where the operator
does every update, one profile per person. Every existing screen keeps its address;
only the sidebar, the home and the words change.

## Release 1: the menu, Overview, Inbox, Build (Optima Build + shared admin kit)

**Release 1: built** (2026-11-04, Optima Build 0.7.9.10, shared admin kit 1.6.0.0, every plugin
that carries the kit one layer revision up). Tested on a local WordPress with the busiest client
site's plugin set; screenshots go to the site owner before merge. Where the build differs from the
table below: Chat settings sit under Your business with the other feature settings; "review
requests" in the Inbox are the notes people send from the review page (the Ask for reviews screen
itself holds nothing waiting, so it is a tab under Inbox); Email also sends a non-operator to Overview.

| File | Change |
|---|---|
| `tools/admin-kit/class-optima-admin-ui.php` (+ 18 copies) | `GROUPS` becomes the seven items (`overview`, `business`, `content`, `search`, `health`, `inbox`, `build`). `group()` sorts by slug lists, not word matches: Business info, Call buttons, Hours, Team, Results, Reviews settings, Cards → `business`; Site Sync, Redirects, Image optimizer → `content`; SEO, AI search, Analytics, Accessibility, Spam protection → `search`; Site health, Search health → `health`; Request a change, Chat questions, Review inbox, Review requests → `inbox`; Features, Updates, Design, Email, Site checks, Customer list, Site setup, Content updates → `build`. The `optima_admin_nav_group` filter stays. Kit version bump. |
| `optima-build/includes/class-optima-hub-menu.php` | Sidebar order follows the seven groups (one entry per group, its first screen), replacing the name list. `Build` entries are removed from the sidebar and tab row for anyone who is not an operator (after the access check, as `drop_behind()` does today). |
| `optima-build/includes/class-optima-hub-overview.php` (new) | The landing screen for everyone: status line (Site health score), **Needs you** (Inbox items for the client, unconfirmed facts), **With Optima** (open requests and their state), the four everyday jobs as links (edit a bio, write a post, change hours, ask for a review), one request box. Operators also see a strip: updates waiting, last check. |
| `optima-build/includes/class-optima-hub-inbox.php` (new) | One screen listing everything waiting on a person, read from the existing stores (change requests, chat questions, new reviews, review requests). One count in the sidebar. Each row links to the screen that handles it. |
| `optima-build/includes/class-optima-hub-admin.php` | Features, Updates and Design move under **Build** (operators only). Build's first view is the update queue: theme, plugins, Fill from theme, Approved copy, Theme setup, each with one button that returns to Build. Client feature cards and the plan selector leave every client view; clients see "Want something added? Ask Optima" on Overview. |
| `optima-build/includes/class-optima-hub-dashboard.php` | The WordPress Dashboard box shows Overview's status line and links to it. |
| `tools/uniform-check.sh` | New check: every Optima screen's group is one of the seven, so word-match drift cannot return. |
| `docs/plugin-first.md`, `README.md`, changelogs | Updated in the same release. |

**Stays byte for byte:** every page slug and address (bookmarks and links keep
working), every settings form and option name, the front end of every site, all
client themes, Optima Core's head and schema.

**Site lock (added to release 1 by the site owner, 2026-11-04): only operators edit the site.**
On by default. A non-operator keeps their role in the database, but while the lock is on cannot
edit, publish or delete content, upload files, change settings, manage users, or install, activate
or update plugins and themes; they see Overview, Site health and Inbox and send requests. Never
applies to WP-CLI, cron or an operator. Off: the Site lock card on Build, or the
`optima_build_site_lock` filter. The lock waits until the site has at least one operator account
(Optima Build 0.7.9.11): with none, nobody is locked, administrators see "Site lock is waiting: no
Optima operator account on this site yet." on Overview and the Dashboard box, and it starts by
itself once an operator account exists.

**Who sees what:** the default operator rule is "administrators on the agency domain": any
user whose address ends with `@optimadigitalmedia.com` and who can manage the site's settings
(decided by the site owner, 2026-11-04). A site can narrow or change it with
`OPTIMA_HUB_OPERATORS` in wp-config (exact addresses, or `@domain` entries). No personal
address lives in this repo.

**Tests:** local WordPress (SQLite) with the plugin set of the busiest client site:
every old address still opens (HTTP 200, same title), every screen lands in its
group, a non-operator never sees Build or a turn-on button, Inbox counts match the
stores, no PHP notices. Screenshots of Overview, Inbox and Build for review before
merge. Then staging on the first site, then every site.

**Rollback:** Optima → Build → Features → Optima Build → "Go back to v0.7.9.x".
Nothing is migrated in release 1, so going back loses nothing.

## Release 2: operators only, the glance, one nav, People, offboarding

**Status: planned** (Stage 1, 2026-11-05). Nothing below is built. Order of work: the
admin kit and Overview first (one release, kit 1.7.0.0), then People (optima-team,
with optima-cards and optima-results reading from it), then offboarding (Optima
Build). Each is its own test release on the agency's staging site before Promote
(`RELEASING.md`). The one-fact-one-tab items from the old release 2 fold into the
Your business work below.

**What release 1 got wrong (the audit):** three navigations on every screen (the
sidebar, the group pill row inside the hero band, the tab row); a hero band that
takes the top third of each page; one Overview shape for everyone with a few items
hidden by role; a client view and client "everyday jobs" on sites no client opens.

| File | Change |
|---|---|
| `tools/admin-kit/class-optima-admin-ui.php` (+ copies) | `open()` prints a slim top bar (client logo and name, a search box wired to the Command Palette, the Optima mark), the title, the lede and one tab row for the open group; it prints no group row and no hero (rule 49). `GROUPS` gains `people` after `overview`. `nav()` keeps only the open group's tabs. Kit version 1.7.0.0. |
| `tools/admin-kit/optima-admin.css` (+ copies) | The hero rules go; `.sh-nav-groups` goes; the top bar, tab row and the glance tiles come in. Google Fonts `@import` stays until the fonts ship with the kit (open question below). |
| `optima-build/includes/class-optima-hub-overview.php` | The glance (rule 46): status line; dashboards row (Recon, GA4, Search Console, Business Profile, host, repo: each a link from the site's setup data, with a cached figure where the feature already keeps one); the queue as three lists (Updates with "Apply all on staging", Inbox, Requests); the site facts row. The client answers, everyday jobs and request box leave this screen (the request form stays under Inbox → Request a change). |
| `optima-build/includes/class-optima-hub-menu.php` | Eight groups, `people` second. `drop_build()` stays. |
| `optima-build/includes/class-optima-hub-dashboard.php` | The WordPress Dashboard box shows the status line and opens Overview. Operators are sent from the Dashboard to Overview on login (`login_redirect`, operators only). |
| `optima-team` (People, rule 47) | The team post type becomes the profile: kind (attorney, staff), title, address on the site, team-page order, headshot, card switch, linked user (optional), reviewed-by assignments. Facts (name, bar number, admissions, education, awards, memberships, profile links) are read from `optima-setup.json` and shown read-only with their Hub tab and status; words (short bio, long bio) from `content/` through Approved copy. Screens: People (list with status, bio page, card, results, posts, updated), the profile (tabs Profile, Bio page, Case results, Posts, Reviewed pages, Digital card, Schema). `optima-cards` reads the card from the profile; `optima-results` picks the attorney from People; posts get a "Byline" box that picks a profile; pages get a "Reviewed by" box (profile and date) that prints the reviewed-by line and `reviewedBy` in the page schema. |
| `optima-build/includes/class-optima-hub-offboarding.php` (new, rule 48) | Four steps on one screen with a stepper: Review (the inventory of everything that names the person and what happens to each), Archive (writes the former-people folder into the site repo through the existing release path, moves the Hub row to Former, asks the site repo's workflow to copy photos and the review PDF to Drive; the plugin holds no Drive credential), Confirm (one link to the operator address through the hub's mail class, 24 hours, operator sign-in required, a second operator where the site has two), Remove (deletes, adds a 301 per old address through optima-redirects, flags reviewed pages on the Inbox, writes the removed-log and changelog lines, re-runs Site health, clears cache). Staging first; live at the go-live card. |
| `optima-core` (one fact, one tab) | Your business → Details is the only place for name, phone, address; Call buttons, Hours, Email and the theme show them read-only with a link (rule 45). Hours: the free-text box and Optima Hours' structured hours become one; a one-time migration keeps the structured hours and shows the old text for checking. Each Your business tab shows where its fact came from (setup file, Hub tab, date). |
| `tools/uniform-check.sh` | Eight groups. New check: no `.sh-hero` or `.sh-nav-groups` markup in any screen. |
| `docs/plugin-first.md`, `README.md`, `CLAUDE.md`, changelogs | Updated in the same releases. Client repos: `docs/11-update-paths.md` updated to the new paths. |

**Stays byte for byte:** every page slug and address, every settings form and
option name, the front end of every site, all client themes, Optima Core's head
and schema. Team members, cards and results keep their data; People reads the
same posts.

**Tests:** local WordPress with the busiest client site's plugin set: every old
address opens, no screen prints a group row or hero, Overview fits one screen at
1360 px wide with no scroll, a profile change shows on the team page, bio page,
author box, results and card at once, offboarding stops when the archive step is
made to fail, the confirm link refuses a non-operator, every removed address 301s.
Screenshots of Overview, People, one profile and the offboarding screen for review
before merge.

**Rollback:** "Go back to vX" on each plugin. Release 2 adds fields to team posts
and never removes any, so going back loses nothing.

## Release 3: every site at a glance

One item, **Every site**, on the agency's own site: one row per site (status,
updates waiting, Inbox, requests, signed cases from the site's cached Recon
summary where connected, theme and Build versions, last release) with links to
that site's Overview, Recon and repo. Filters: all, needs attention, updates
waiting, requests open. Needs one read-only status endpoint in Optima Build
(signed, operators only, cached for an hour) and the private fleet list as the row
source. Not in this public repo's data.

## Answered 2026-11-05

- Who logs in most: operators. Overview is the operator glance; there is no client view.
- Change-request recipient per site: the site's operator address, set in wp-config with the operators.
- Recon in the dashboard: no. Recon stays the client's own product; the dashboard links out to it.

## Open questions

- Admin fonts: the kit still loads Montserrat and the Plex faces from Google Fonts inside wp-admin. Ship them with the kit, or accept the remote load.
- Offboarding confirmer on a one-operator site: the same person starts and confirms. Name a standing second confirmer (CTO) or accept it.
- Posts that rank: the review step logs them and 301s them to the blog index. A "keep under the firm's byline" choice is one checkbox if ever wanted.
