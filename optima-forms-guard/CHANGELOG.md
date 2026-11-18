# Changelog

## 0.3.2.8 (2026-11-12)

- Updates install only from the Optima Plugins release downloads (https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/). A release listed at any other address is skipped, and an update of an Optima plugin from any other address is refused with an error.
- Every download is checked against the sha256 published in the version list before WordPress unpacks it. A mismatch deletes the file, stops the install with an error and writes a line to the PHP error log.
- A release listed without a sha256 (one published before checksums were recorded) still installs as before, with a line in the PHP error log saying no checksum was checked.
- Automatic updates run only for a package from the Optima Plugins releases.
- The GitHub API fallback reads each asset's digest as its sha256, so the check works on that path too.

## 0.3.2.7 (2026-11-12)

- **Refuses List answers that carry hidden code.** Gravity Forms 3.1.2 and earlier has a reported critical flaw (CVE-2026-103039, rated 9.8): a List field headed for a Post Custom Field is handed to PHP's unserialize, which can run code on the site if another plugin or theme supplies a way to use it. Forms Guard now checks every List field before Gravity Forms saves anything, and refuses a submission whose answer is serialized PHP (an object, a class or an array in PHP's storage format). Nobody types that into a List field, so real visitors lose nothing. A refusal adds to the monthly blocked count. The real fix is to update Gravity Forms (3.2.2 is out), and this keeps a site safe until it has. Turn it off with the `optima_forms_guard_block_serialized` filter.
- Security release ahead of the next full plugin round: only this check changes. Carried from upstream 0.3.7; the next round brings the rest of 0.3.3 to 0.3.7.

## 0.3.2.6 (2026-11-05)

- Now carries the Optima admin kit 1.8.0.0. Search and AI, Spam protection on a site without Optima Core shows the guard's status and what it blocked; with Core the address opens Core's Spam protection tab. Listed as Spam protection for forms in the catalog.

## 0.3.2.5 (2026-11-04)

- Shared updater: release channels. A site on the stable channel (the default) sees only normal releases, exactly as before. A site on the test channel (Optima Build, Build, Updates, Release channel, or `OPTIMA_HUB_CHANNEL` in wp-config) also sees test releases (GitHub pre-releases) and takes the newest version from either. Without Optima Build a site is always on stable. This release goes out as a normal release so every site gets the channel-aware updater; test releases start with a later release.

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
