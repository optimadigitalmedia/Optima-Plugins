# Site Sync: approved copy, new pages and forms from the theme

Status: **Optima Core 1.6.1.0 (2026-11-18).** Owner of the job `content-sync` (plugin-first rule 32). Code: `optima-core/includes/sync/` (`class-optima-sync.php` the engine, `-admin.php` the screen, `-cli.php` WP-CLI). Tests: `tools/tests/site-sync.php`.

Approved words reach WordPress one way: the theme release carries them, and **Optima → Content → Approved copy** (address `admin.php?page=optima&tab=sync`) applies them. Since 1.6.1.0 the same screen also creates the pages a site lacks and adds the theme's Gravity Forms, so a site owner never hand-imports a WordPress import file (WXR) or a form file again (site owner, 2026-11-18: "we should be uploading these things through the app some how").

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

## 4. The theme contract (`optima-sync.json`)

The file sits at the theme root and every path in it is relative to the theme folder (files outside the theme are never read). `sync_version` must be `1`.

| Key | Entry | Notes |
|---|---|---|
| `pages` | `slug`, `post_type` (default `page`), `title`, `source` (file) or `content` (text), `previous` (earlier shipped copies), `create` | `slug` may be a path (`services/injury`) for pages |
| `fields` | `slug`, `post_type`, one of `field` (Core page field), `seo` (`title` or `desc`), `meta` (+ optional `key` inside an array), `post_field` (`title`, `content`, `excerpt`); `value`, `source` or `image`; `previous` | |
| `settings` | `group`, `key`, `value` or `source`, `previous` | Core settings groups |
| `options` | `option`, optional dotted `key`, `value` or `source`, `previous` | Only `blogname`, `blogdescription`, and keys inside `optima_business` (themes add their own array options with the `optima_sync_option_keys` filter) |
| `users` | `email`, `field` (`description`), `value` or `source`, `previous` | |
| `forms` | `file` (Gravity Forms export JSON in the theme), `title` | 1.6.1.0 |

**`create` block** (1.6.1.0), all keys optional (`"create": true` means a draft with every default):

| Key | Default | Notes |
|---|---|---|
| `status` | `draft` | `publish` or `draft`; anything else is refused |
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
      "create": { "status": "draft" } }
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

- **Optima → Content → Approved copy** (operators only, rules 9, 10 and 53): counts first, then the updates ready to go, the changes made in WordPress, the pages and forms the theme can add (checkboxes, unticked; "Create the ticked items"; "Restore the ticked pages from the trash" when one is there), pages the theme names but cannot create, forms waiting on Gravity Forms, and the last sync with its undo. A screen with nothing to do says so in one line (rule 52).
- **Build → Updates → Approved copy** shows the counts and applies the safe updates; adding pages and forms happens on Approved copy.
- **Site health** and the dashboard notice count everything that needs a person, including missing pages and forms and forms waiting on Gravity Forms.
- **WP-CLI:** `wp optima sync status [--all]`, `wp optima sync run [--dry-run]` (safe rows only), `wp optima sync create [<item>...] [--all] [--restore] [--dry-run]`, `wp optima sync undo`.

## 6. Upstream

Rule 55 (upstream first): page creation and forms in Site Sync were built here first, at the site owner's direction (2026-11-18). This section is the upstream spec in the upstream suite's shape; it goes to the internal repo's upstream specs and is sent upstream with the next port, so the two suites keep one Site Sync.
