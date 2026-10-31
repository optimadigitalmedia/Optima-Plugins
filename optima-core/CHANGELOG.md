# Changelog

## 1.6.0.5 (2026-10-31)

- **Editable pages now take over pages that came from the old site.** A staging site copied from a live site keeps the old site's page text. The theme already shows its approved design on those pages, so that old text is never seen, but the import only filled empty pages, which left the old text in the editor. Editors changed text nobody could see (found on the GJNOLA staging site: 30 of 33 pages). The import now replaces any page text that is not editable-page blocks with the approved blocks. The old text is kept in the page's revisions and in the `_optima_editable_legacy` field. Pages already edited in the new format are never overwritten, as before.
- Takes effect on the next admin visit after the update.

## 1.6.0.4 (2026-10-31)

- Optima release of upstream 1.6.0.
- **Email addresses are hidden from spam bots.** Client sites printed their contact email in plain text (email links, visible text, the business details for search), and bots collected it, so clients got SEO spam. Optima Core now codes every address on the finished page before it is sent. Visitors see and click it as usual, and a page cache stores the coded copy. Inside the search data (JSON-LD) only the "@" is written as `\u0040`, so Google reads the same email. Scripts, styles, text boxes, form values and comments are left alone, and if anything goes wrong the page is sent unchanged. Filter `optima_core_email_guard` (true or false).
- **Comments and pingbacks are closed.** New comments and pingbacks on client sites mostly brought spam. With the switch on, pages and posts take no new comments or pingbacks, the `X-Pingback` header is gone, and the pingback XML-RPC methods are removed. Comments already saved stay in WordPress. Turn it off and the site works exactly as before. Filter `optima_core_close_comments` (true or false).
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Test: `php tools/tests/email-guard.php`.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.
- Editable pages (Optima) carried forward unchanged.

## 1.6.0.1 (2026-10-28)

- Editable pages (upstream 1.6.0 feature, Optima revision 1). A designed page's words and photos are edited on the page in the block editor; the layout, icons and theme parts stay locked with WordPress's content-only lock. Themes opt in with `add_theme_support( 'optima-editable-pages', array( 'content_dir' => ..., 'editor_css' => array( ... ) ) )` and call `optima_core_render_editable()` where the page body goes. Five blocks (`optima/box`, `optima/text`, `optima/image`, `optima/frozen`, `optima/part`), a Page photos panel, theme parts through the `optima_core_editable_part` filter, import that fills empty pages and refreshes untouched ones but never overwrites an edited page, and FAQ structured data rebuilt from the visible questions (`optima_schema_graph`, or `optima_core_editable_faq()` for themes that print their own schema).
- Administrators can rearrange a page with WordPress's "Edit pattern" button; clients and editors get the lock on the whole editor and no unlock button.
- Build tool for client repos: `tools/editable-pages/build_blocks.py` (not shipped in the plugin).
- Nothing changes for themes that do not opt in.

## 1.5.1.3 (2026-10-28)

- Optima release of upstream 1.5.1.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 1.5.1.2 (2026-10-27)

- Optima release of upstream 1.5.1.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
