# Changelog

## 0.7.9.4 (2026-10-31)

- Optima release of upstream 0.7.9.
- **"Test builds" no longer flags a client's own plugin.** The row now looks only at plugins that update from this repo.
- **Form emails in the site's own colors.** Gravity Forms notifications now arrive in the same card as the Email test: the site's color bar, its Site Icon, the site name, and the subject as the headline, with the form answers below. When the message has a phone number, it gets Call and Text buttons; when it has an email address, an Email back button that reads plain-text addresses too (the first one that is not the site's own). The headline leaves out the phone number, since it already has its Call button. Confirmations sent to the person who filled in the form get the card without those buttons. Light colors like Carolina blue get dark button text so it stays readable. Only HTML notifications, only while Optima Email is set up. Off switch: the `optima_mail_brand_forms` filter.
- **Other Optima plugins can use the site's brand color.** `Optima_Hub_Mail::brand_color()` returns the Email screen's pick or the detected color; Optima Analytics 0.1.15 colors the cookie notice's OK button with it.
- **Site health: "Hidden upload fields".** With Optima Forms Guard 0.3.0 or later, Site health lists any Gravity Forms File Upload field set to Hidden, with a link to fix it.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- **Site health: "Test builds".** Lists any active Optima plugin running a test build: a version with a label like `0.7.8-test.1`, or one newer than its newest release. Neither updates the normal way, and the row says which plugin and what release it waits for. It reads the release list the updater already keeps, so it adds no call to GitHub. `tools/test-build.sh` builds labeled test zips (see RELEASING.md).
- **Optima hears when a live site is hidden from Google.** When the live site has "Discourage search engines" ticked in Settings, Reading, Optima Build emails sean@optimadigitalmedia.com ("<Site name> is hidden from Google") as soon as the setting changes and from a daily check, at most once a week while it stays on, and shows administrators a notice. Staging addresses, non-production environments and local copies are left alone. `OPTIMA_HUB_REQUEST_EMAIL` or the `optima_build_alert_email` filter changes the address.
- **Site health: "Form emails go to".** Lists where each active Gravity Forms form sends its emails: the addresses (with {admin_email} filled in), "the address in field 3", or "routing rules". On a live site it asks for a look when a form sends no email or emails only Optima. The `optima_build_agency_domains` filter sets which domains count as Optima's.
- **Site health: "Review totals".** With Optima Reviews 0.5.5 or later, shows how long ago the review counts were last updated, and asks for a look after 30 days. Sites that have not saved the totals since updating read "Update the totals once to start tracking."
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.7.4.6 (2026-10-28)

- **Fix: Direct answers no longer counts password-protected pages.** A private partner page (WordPress page password) is read by no visitor and no AI tool, but it was checked as a main page and counted as one without answers. It is skipped like the legal pages. The count catches up at the next scan (within 12 hours).

## 0.7.4.5 (2026-10-28)

- Brings two fixes from a side branch into the main line, on top of revision 3 (staging ran 0.7.4.4 from that branch, so the main line now moves past it):
  - Site health, Search engines: a staging copy behind the host's password protection, or one the theme already marks noindex, reads "Kept out on staging" instead of asking for a password that is already on.
  - Features screen: recommended cards no longer show a stray "01" counter.
- Features screen: AI search is listed under "Not used on this site" with the Optima Digital theme, which already runs llms.txt, the AI crawler rules and the question-and-answer blocks. Running both replaced the theme's guide with a generic one.
- Revision 3 (already in 0.7.4.3): IBM Plex Sans on admin screens; the section nav shows on every list screen; settings tabs say "settings".

## 0.7.4.3 (2026-10-28)

- Optima release of upstream 0.7.4.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 0.7.4.2 (2026-10-27)

- Optima release of upstream 0.7.4.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
