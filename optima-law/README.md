# Optima Law Firm Tools

The pieces a law firm's website needs to stay careful. Core for every law
firm (catalog tier `core`, `law: true`, job `law`). Screens file inside the
Optima menu: **Content** (Disclaimers, Firm approvals, Attorney sign-offs) and
**Build** (Form entries, operators only).

## Bar rules differ by state, and the firm signs off

Every state bar has its own advertising rules, and every firm has its own way
it wants to be seen. This plugin gives starting wording for North Carolina,
Virginia, Florida and Washington, DC. Louisiana and South Carolina have no
state wording here: their lines start from the general wording marked
`[VERIFY: counsel]` and can't be approved until counsel's words replace the
placeholder. The plugin never decides whether any wording complies. **The
firm's attorneys review and approve the final wording**, and the approver's
name, the date and the exact words are kept as the record.

## Disclaimers (Optima → Content → Disclaimers)

| Line | Shows by default | What it says |
|---|---|---|
| Not legal advice | Footer | The website is general information, and contacting the firm does not create an attorney-client relationship. |
| Before someone sends a form | Under forms | Don't send confidential details through the website. |
| Attorney advertising | Footer | A short label. The firm decides whether it shows. |
| Prior results | Next to case results | Prior results do not guarantee a similar outcome, in the state's starting wording. Replaces the wording on Case results while this plugin is on (filter `optima_results_disclaimer`). The results threshold stays in Optima Case results. |
| Reviews and testimonials | Under reviews | Reviews reflect individual clients' experiences. Replaces the Reviews disclaimer on the site while this plugin is on. |

- A line always prints something: a blank box uses the starting wording.
- An approval belongs to one exact wording. Edited words read "Changed since
  approval" until the firm approves again. Extra spaces don't count as a change.
- Every approval is kept in `optima_law_approval_log`, which stays when the
  plugin is deleted.

### For themes

The footer lines render in `optima_slot( 'footer' )`. A theme without slots gets
them at the end of the page. A theme that places them itself:

```php
add_theme_support( 'optima-law-footer' );
optima_law_disclaimers( 'footer' );
```

Shortcodes: `[optima_disclaimers place="footer"]` and `[optima_disclaimer line="relationship"]`.

| Filter or action | What it changes |
|---|---|
| `optima_law_starting_wording` | The starting wording for a line and state. |
| `optima_law_disclaimer_text` | The wording a line prints (an empty return falls back to the starting wording). |
| `optima_law_disclaimer_approved` (action) | Runs when the firm's approval is recorded. |

## Firm approvals (Optima → Content → Firm approvals)

- Blog posts and People profiles start on; Pages is off until the site is
  built. Case results and reviews keep their own approval.
- Publishing without an approval saves as **Pending review** (block editor,
  classic editor, quick edit and code all go through the same check).
- The **Firm approval** panel or box takes the attorney's name and a tick. The
  name, date and exact version (a revision) are kept in `optima_law_content_log`,
  which stays when the plugin is deleted.
- Filter `optima_law_approval_types`; action `optima_law_content_approved`.

## Attorney sign-offs (Optima → Content → Attorney sign-offs)

"Reviewed by" belongs to Optima Team (People, plugin-first rule 47): the
page's Reviewed by box, the line at `optima_slot( 'before-close' )`, and
`reviewedBy` / `lastReviewed` in the schema. This plugin adds only the
schedule: pages not reviewed in the review period (6 months by default; 3 or
12), never reviewed, or changed since their review are listed here and on the
Inbox. The privacy page is left out. Without Optima Team the screen asks to turn
it on. Filter `optima_law_review_types`.

## Form entries (Optima → Build → Form entries, operators only)

**Off by default** until the decision is made. When on: each night, Gravity
Forms entries older than 90 days (30, 60, 180 or 365) are deleted with their
uploaded files, in every status. The first delete waits 14 days after it is
turned on. Starred entries, forms that email no one, forms with their own
Gravity Forms retention setting and forms chosen to keep are always kept. At
most 500 entries per form a night. `optima_law_entry_days()` returns the days
kept (0 when off) for Optima Core's privacy policy draft.

## Inbox

`Optima_Law_Inbox::kinds()` returns the rows for Optima → Inbox, in the shape
`Optima_Hub_Inbox::kinds()` uses: disclaimers to approve, waiting for the
firm's approval, edited since approval, pages due for an attorney's review,
and the form-entry start date during the 14-day notice.

## What this feature needs from the theme

- **DESIGN.md:** nothing of its own. The lines take the theme's font and color.
- **Slots:** `footer` for the footer lines (fallback: end of page). The
  Reviewed by line is Optima Team's, at `before-close`.
- **Class names:** `.optima-law-disclaimers`, `.optima-law-disclaimer`.
- **optima-setup.json:** nothing. The wording lives in the plugin and the firm
  approves it.
