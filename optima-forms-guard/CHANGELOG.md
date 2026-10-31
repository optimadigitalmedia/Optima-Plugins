# Changelog

## 0.3.2.4 (2026-10-31)

- Optima release of upstream 0.3.2.
- **Catches "add your site to Google" spam.** Forms Guard now reads the message too. An entry that links to another website (email addresses are fine) or uses a known pitch ("search index", "backlink" and a few more) is marked as spam: the visitor sees the normal confirmation, no notification goes out, and the entry waits in the form's Spam folder in case a real person was caught. It counts toward the monthly blocked total. Turn it off with `optima_forms_guard_content_check`; change the phrases with `optima_forms_guard_spam_phrases`.
- **Refuses files sent to hidden upload fields.** A reported critical Gravity Forms flaw (CVE-2026-84434, reported 9.8) lets a stranger upload a file that runs code through any File Upload field whose Visibility is Hidden. Gravity Forms has no fix yet. Optima Forms Guard now drops any file sent to a File Upload field set to Hidden or Administrative, on a normal submit and on drag-and-drop uploads (those get a 403). A visitor never sees those fields, so real visitors lose nothing. Logged-in staff who can edit entries are let through. Each refusal adds to the monthly blocked count. Turn it off with the `optima_forms_guard_block_hidden_uploads` filter.
- **Names the fields to fix.** Site admins see a red notice listing each form and field, with a link to the form and what to change (set Visibility to Visible, or delete the field). `Optima_Forms_Guard::upload_check()` gives Optima Build the same answer as a Site health row; `Optima_Forms_Guard::hidden_uploads()` returns the list.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.2.0.2 (2026-10-27)

- Optima release of upstream 0.2.0.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
