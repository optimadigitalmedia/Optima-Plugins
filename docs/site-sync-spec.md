# Site Sync: approved copy, new pages, forms, page status and retired pages from the theme

Status: **Optima Core 1.6.1.0 (2026-11-18; status keeping and the retire list added 2026-11-19).** Owner of the job `content-sync` (plugin-first rule 32). Code: `optima-core/includes/sync/` (`class-optima-sync.php` the engine, `-admin.php` the screen, `-cli.php` WP-CLI). Tests: `tools/tests/site-sync.php`.

Approved words reach WordPress one way: the theme release carries them, and **Optima → Content → Approved copy** (address `admin.php?page=optima&tab=sync`) applies them. Since 1.6.1.0 the same screen also creates the pages a site lacks and adds the theme's Gravity Forms, so a site owner never hand-imports a WordPress import file (WXR) or a form file again (site owner, 2026-11-18: "we should be uploading these things through the app some how"). The same release keeps each page's status the theme names and lists the junk pages the theme retires, so a theme never carries one-off cleanup code (site owner, 2026-11-19, after a theme was asked to set pages to draft and trash junk: "but we should include this as a [plugin] no?").

## 1. How it decides

The theme ships `optima-sync.json` at its root. Each entry becomes one or more **rows**. Every row is compared three ways: the copy in the theme, what WordPress holds, and the base (two hashes) recorded at the last sync.

| State | Screen label | Meaning | What happens |
|---|---|---|---|
| `current` | Up to date | WordPress matches the theme, or holds a copy someone chose to keep | Nothing |
| `update` | Ready to update | The theme changed, WordPress untouched since the last sync | Applied by the automatic run after a theme update, "Sync these now", Build → Updates "Apply", and `wp optima sync run` |
| `edited` | Edited in WordPress | WordPress changed, the theme did not | Waits for a person: Keep the WordPress copy, or Use the theme copy |
| `conflict` | Needs a decision | Both changed, or never synced and different | Same as edited. Never written on its own |
| `missing` | Not on the site yet | The page, post, user or form the row names is not there | A page with a `create` block, or a form, can be added when a person ticks it (section 3); anything else is listed |
| `unavailable` | Waiting on Gravity Forms | A form row while Gravity Forms is not active | Shown, never acted on |
| `published_early` | Published early | The theme says `draft`, the page is published | Waits for a person: Set back to draft, or Keep it as it is (section 3c) |
| `ready_to_publish` | Ready to publish | The theme says `publish`, the page is a draft or pending review | Waits for a person: Publish, or Keep it as it is (section 3c) |
| `retire` | Pages the theme retires | A page on the theme's `retire` list is on the site (not in the trash) | Waits for a person: Move to the trash, or Keep (section 3d). Some are refused and say why |

Never synced: an empty WordPress value, or one listed in the entry's `previous`, is safe (`update`); anything else is a `conflict`.

## 2. How it writes

Every write, whatever started it:

1. **One at a time.** A lock (`optima_sync_lock`, 15 minutes) stops two syncs running together.
2. **Reviewed.** A person's run carries the hash of the plan they looked at; if anything changed since, the run is refused ("Review it again").
3. **Journaled first.** The undo record (`optima_sync_journal`) is saved before the first write. An unfinished run must be undone before the next.
4. **Compared again** right before each write. A row that changed underneath stops the run.
5. **Undone in one step.** "Undo the last sync" (or `wp optima sync undo`) puts back each row the last run wrote, unless it changed since (then it is left alone and named).
6. **Base recorded** after each write, so the next theme change on an untouched row is a safe update.

## 3. Creating what the site lacks (1.6.1.0)

### 3a. New pages

A page entry may carry a `create` block. Without one, a missing page is only listed ("Pages the theme names but cannot create").

- **Never automatic.** The automatic run, "Sync these now", Build → Updates and `wp optima sync run` never create a page. Approved copy lists creatable pages under "Pages and forms the theme can add", **unticked**; "Create the ticked items" adds the ones a person ticks. (Safest default: a new page is a visible change, so it always needs a person.)
- **Parent first.** Ticked pages are created shallowest first. A child needs its parent on the site or ticked in the same run; otherwise the run is refused and nothing changes.
- **What the page gets.** The entry's `title` (else `create.title`, else the slug in words), its copy (`source` file or `content`), the `status` (default `draft`; drafts stay drafts), the parent, the page template, `menu_order` and the author. In the same step, the page's other rows (SEO title and description, page fields, images) fill from the theme, all in the same undo record.
- **Author.** `create.author` is matched by email, then by login. If no such user exists, the page is credited to the person running it (on the command line, the first administrator) and the screen says so.
- **Blocked, with the reason.** A parent that is not on the site and that the theme cannot create, a page template the theme does not have, a post type the site does not have, or a parent on a non-hierarchical type. The row shows why and cannot be ticked.
- **Trash.** If a page with the same slug is in the trash, Site Sync never creates a second one. The row says "In the trash" and offers **Restore the ticked pages from the trash**, which brings it back with the status it had (WordPress would otherwise restore it as a draft) and its old address.
- **Checked again right before.** If someone created the page, or trashed one with that slug, between the review and the write, the run stops.
- **Undo.** A page the last run created goes to the trash (never deleted) if its title and copy are still what Site Sync wrote; if someone edited it since, it is left whole, fields included, and named. A page the last run restored goes back to the trash if nobody changed it since.
- **Interrupted runs.** A created page carries the meta `_optima_sync_created` (the run's time), so undo finds it even if the run stopped before recording its id.

### 3b. Gravity Forms

A manifest `forms` list names Gravity Forms export files in the theme.

| State | When | What happens |
|---|---|---|
| `missing` | No active or inactive form (not trashed) has that title | Added when a person ticks it ("Create the ticked items", `wp optima sync create`), through `GFAPI::add_form`, titled exactly as the manifest says |
| `current` | The form matches the base, or (never synced) every value the export sets matches the form | Nothing. A form once imported by hand from the same file is adopted |
| `update` | The theme's export changed; the form is untouched since the last sync | `GFAPI::update_form` with the same form id, so its entries stay attached. Applied like any safe update |
| `edited` / `conflict` | Someone changed the form in WordPress (or both changed) | Never overwritten on its own. Keep the WordPress form, or Use the theme copy, as for page copy |
| `unavailable` | Gravity Forms is not active | "Gravity Forms is not active": listed, nothing done |

- **Matching.** The form id recorded at the last sync is tried first (so a form renamed in WordPress reads as edited, not missing), then the exact title. Two forms with the same title block the row until one is renamed or trashed.
- **Comparing.** A form is compared as JSON with its keys sorted, scalars as text, and the keys that change on every save left out (`id`, `date_created`, `date_updated`, `is_active`, `is_trash`, `version`, `nextFieldId`, each field's `formId`).
- **Never automatic for new forms**, the same rule as pages. Updates to untouched forms are automatic, as for copy.
- **Undo.** A form the last run added is switched off and moved to the forms trash (its entries, if any, stay). A form it updated gets back the full form it had before (saved in the undo record). Either only if the form is still what Site Sync wrote.
- **Entries.** An update keeps the form id. A theme release that renumbers field ids would detach old entries from those fields: keep field ids stable in the repo's export.

### 3c. Page status (status keeping)

A page entry may carry `"status": "draft"` or `"status": "publish"`: the status the site repo says the page should have.

| The theme says | The page on the site | State | Action (unticked) |
|---|---|---|---|
| `draft` | Published | `published_early`, "Published early" | **Set the ticked pages back to draft** |
| `publish` | Draft or pending review | `ready_to_publish`, "Ready to publish" | **Publish the ticked pages** |
| either | Private or scheduled | `current` | None: set that way on purpose |
| either | The same | `current` | None |
| either | Not on the site | Left to the page's `create` block, which takes this status when it names none | |

- **Never automatic.** The automatic run after a theme update, "Sync these now", Build → Updates "Apply" and `wp optima sync run` never change a status. Only a person's tick (or `wp optima sync set-status` with item ids or `--all`) does. (Safest default: publishing or unpublishing is a visible change.)
- **Checked again right before** each write: if the status changed since the review, the run stops.
- **Undo** puts back the status each page had, unless it changed since (then it is left alone and named).
- **Keep it as it is** records the person's choice; the row reads current until the theme or the page's status moves again. The site repo should then be corrected so the next theme agrees.
- A `create` block's `status` and the entry's `status` must agree; when the block names none, it takes the entry's.

### 3d. Pages the theme retires (retire list)

A manifest top-level `retire` list names pages to take off the site: duplicates, leftovers, pages moved to a new address. Each entry: `path` (the page address, `parent/child` for a child page; required), `reason` (shown on the screen), `redirect` (optional new address), `post_type` (default `page`).

- **Matching.** A page whose address is `path` and that is not in the trash is a match (`retire`). No match is a miss: the row reads current and nothing happens. The path is matched as WordPress stores it, so a page left under a trashed parent is matched as `old-parent__trashed/child`.
- **Never automatic, never deleted.** Approved copy lists matches under "Pages the theme retires", **unticked**; **Move the ticked pages to the trash** trashes the ticked ones (`wp_trash_post`). Nothing is ever permanently deleted.
- **Refused, with the reason** (the box cannot be ticked): the front page, the posts page and the privacy policy page; any page the manifest itself lists as live (a `path` equal to a `pages` slug is a manifest error; a page reached through another entry is refused on the screen); and a page with pages under it that the theme does not retire too, because trashing a parent renames it and moves every page under it to a new address (that is how `__trashed` paths are born).
- **Parents with their pages.** When a parent and its pages are all on the list, they must be ticked together; they go deepest first. Ticking the parent alone is refused and nothing changes.
- **Checked again right before** each trash: the same page, unchanged since the review, still not protected.
- **The 301.** When `redirect` is set and Optima Redirects is active, the row offers **Add the 301 to ...** (unticked). It adds a 301 from the page's address to `redirect` (origin `sync`) right after the page goes to the trash. If a redirect from that address already exists it is left as it is and the screen says so. Without Optima Redirects the row says the 301 is not added here: the theme's redirects file carries it, or add it on the Redirects screen once the plugin is on.
- **Undo** brings each page back from the trash with the status and address it had, and removes the 301 Site Sync added if it still points where it did. A page someone already restored is left alone; a page emptied from the trash since is named.
- **Keep the ticked pages** records the choice; the row reads current until the page or the entry changes. Correct the site repo so the next theme drops it.

## 4. The theme contract (`optima-sync.json`)

The file sits at the theme root and every path in it is relative to the theme folder (files outside the theme are never read). `sync_version` must be `1`.

| Key | Entry | Notes |
|---|---|---|
| `pages` | `slug`, `post_type` (default `page`), `title`, `source` (file) or `content` (text), `previous` (earlier shipped copies), `create`, `status` | `slug` may be a path (`services/injury`) for pages. `status` (`draft` or `publish`) is section 3c |
| `fields` | `slug`, `post_type`, one of `field` (Core page field), `seo` (`title` or `desc`), `meta` (+ optional `key` inside an array), `post_field` (`title`, `content`, `excerpt`); `value`, `source` or `image`; `previous` | |
| `settings` | `group`, `key`, `value` or `source`, `previous` | Core settings groups |
| `options` | `option`, optional dotted `key`, `value` or `source`, `previous` | Only `blogname`, `blogdescription`, and keys inside `optima_business` (themes add their own array options with the `optima_sync_option_keys` filter) |
| `users` | `email`, `field` (`description`), `value` or `source`, `previous` | |
| `forms` | `file` (Gravity Forms export JSON in the theme), `title` | 1.6.1.0 |
| `retire` | `path`, `reason`, `redirect`, `post_type` (default `page`) | 1.6.1.0, section 3d |

**`create` block** (1.6.1.0), all keys optional (`"create": true` means a draft with every default):

| Key | Default | Notes |
|---|---|---|
| `status` | the entry's `status`, else `draft` | `publish` or `draft`; anything else is refused. Must agree with the entry's `status` |
| `title` | the entry's `title`, else the slug in words | The entry's `title` wins when both are set |
| `parent` | from the slug path | A parent slug or path (`services`). If the slug is a path, they must agree |
| `template` | none | A page template file the theme has (`page-templates/practice.php`), or `default` |
| `menu_order` | `0` | |
| `author` | the person running it | Email or login |

The page's copy comes from the entry's `source` or `content`. Its other rows (in `fields`) fill in the same step.

**Forms files** are Gravity Forms exports (Forms → Import/Export → Export Forms) committed to the theme, one form per file is simplest. A file holding several forms is read by title. The manifest `title` is the title the form gets on the site, exactly.

Example:

```json
{
  "sync_version": 1,
  "pages": [
    { "slug": "workers-compensation", "title": "Workers' compensation",
      "source": "sync/pages/workers-compensation.html",
      "create": { "status": "publish", "template": "page-templates/practice.php", "menu_order": 2, "author": "author@example.com" } },
    { "slug": "workers-compensation/back-injuries", "title": "Back injuries at work",
      "source": "sync/pages/back-injuries.html",
      "create": { "status": "draft" } },
    { "slug": "spanish/compensacion", "status": "draft" },
    { "slug": "contact", "status": "publish" }
  ],
  "retire": [
    { "path": "industries-x__trashed/truck-drivers",
      "reason": "Duplicate of /industries/truck-drivers/",
      "redirect": "/industries/truck-drivers/" },
    { "path": "sample-page", "reason": "WordPress starter page" }
  ],
  "fields": [
    { "slug": "workers-compensation", "seo": "desc", "value": "One sentence for search results." }
  ],
  "forms": [
    { "file": "sync/forms/free-case-review.json", "title": "Free case review" }
  ]
}
```

The site repo's build script (`scripts/build-sync.py` or the kit's equivalent) writes this file and the `sync/` folder from `content/*.md` and the form exports; theme changes ship through the theme release as always. A WordPress import file stays only for the first import of posts (rule 32).

## 5. Screens and commands

- **Optima → Content → Approved copy** (operators only, rules 9, 10 and 53): counts first, then the updates ready to go, the changes made in WordPress, the pages and forms the theme can add (checkboxes, unticked; "Create the ticked items"; "Restore the ticked pages from the trash" when one is there), pages the theme names but cannot create, forms waiting on Gravity Forms, pages published early ("Set the ticked pages back to draft", "Keep it as it is"), pages ready to publish ("Publish the ticked pages", "Keep it as it is"), pages the theme retires ("Move the ticked pages to the trash" with an unticked "Add the 301" per page, "Keep the ticked pages"), and the last sync with its undo. Every box starts unticked. A screen with nothing to do says so in one line (rule 52).
- **Build → Updates → Approved copy** shows the counts and applies the safe updates; adding pages and forms happens on Approved copy.
- **Site health** and the dashboard notice count everything that needs a person, including missing pages and forms, forms waiting on Gravity Forms, pages published early or ready to publish, and pages the theme retires.
- **WP-CLI:** `wp optima sync status [--all]` (lists every row, including published early, ready to publish and retire, with the command for each), `wp optima sync run [--dry-run]` (safe rows only; never a status or a trash), `wp optima sync create [<item>...] [--all] [--restore] [--dry-run]`, `wp optima sync set-status [<item>...] [--all] [--dry-run]`, `wp optima sync retire [<item>...] [--all] [--redirect] [--dry-run]`, `wp optima sync undo`. (`status` already names the list, so the status action is `set-status`.) Each command is one run with one undo record: `undo` reverses the last command only.

## 6. Upstream

Rule 55 (upstream first): page creation and forms (2026-11-18), and status keeping and the retire list (2026-11-19), were built here first, at the site owner's direction. Sections 3a to 3d and the `status`, `create`, `forms` and `retire` keys in section 4 are the upstream spec in the upstream suite's shape, with no Optima names in the behavior: the manifest file and screen names map one to one (`optima-sync.json`, the Approved copy screen, the `wp optima sync` commands, the Redirects plugin). They go to the internal repo's upstream specs and are sent upstream with the next port, so the two suites keep one Site Sync.

Upstream summary of the 2026-11-19 additions, for the port:

1. **Status keeping.** Page entry key `status` (`draft` or `publish`). New states `published_early` and `ready_to_publish`; a person-only action per direction plus "Keep it as it is" (a kept base of the two status hashes). Private and scheduled pages read current. Never in the automatic run. Journaled; undo restores the earlier status if unchanged since.
2. **Retire list.** Top-level `retire` entries (`path`, `reason`, `redirect`, `post_type`). New state `retire`; refused for the front, posts and privacy pages, any page the manifest lists as live, and a parent with live pages under it that are not retired too. A person-only "Move to the trash" (never delete), deepest first; an optional per-page 301 through the redirects plugin when it is active. Journaled; undo untrashes with the earlier status and removes the 301 if it still points where it did.
3. **CLI.** `sync set-status` and `sync retire [--redirect]`, both with item ids or `--all` and `--dry-run`.
