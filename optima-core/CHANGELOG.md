# Changelog

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
