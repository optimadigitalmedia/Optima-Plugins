# Dashboard redesign: release plan

Agreed by the site owner on 2026-11-04: menu name "Optima"; Optima brand with the
client's logo; clients never see turn-on buttons; rules 9, 10, 33 and section 4a
amended, rules 44 to 46 added (`docs/plugin-first.md`); first staging site is the
client site with the most features on.

**The target:** one Optima menu with seven items, the same words in the sidebar and
on the screen, one home that answers three questions, one page where the operator
does every update. Every existing screen keeps its address; only the sidebar, the
home and the words change.

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

## Release 2: one fact, one tab

- **Your business → Details** becomes the only place for name, phone, address and
  hours; Call buttons, Hours, Email and the theme read them and show them read-only
  with a link (rule 45).
- Hours: the free-text hours box in Business info and the Hours plugin's structured
  hours become one; a one-time migration per site keeps the structured hours and
  shows the old text for checking.
- Client repos: `docs/11-update-paths.md` in each site repo updated to the new paths.

## Release 3: every site at a glance

One row per site on the agency's internal site (where it is, theme, Build, what is
waiting, health, requests, a link to that site's Build page). Needs one read-only
status endpoint in Optima Build (signed, operators only) and a row per site in the
private fleet list. Not in this public repo's data.

## Open questions

- Change-request recipient per site (CTO). The operator default is settled (agency-domain administrators); a site narrows it in wp-config.
- Who logs in most: if operators come first, Overview opens on the operator strip.
