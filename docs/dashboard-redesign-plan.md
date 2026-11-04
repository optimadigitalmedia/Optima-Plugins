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

**Who sees what:** operators come from `OPTIMA_HUB_OPERATORS` (wp-config). Until each
site sets its list, Build shows only to the default operator. The operator list
per site is an open question for the CTO.

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

- Operator list and change-request recipient per site (CTO).
- Who logs in most: if operators come first, Overview opens on the operator strip.
