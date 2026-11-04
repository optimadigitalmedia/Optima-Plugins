# Changelog

## 0.4.3.8 (2026-11-04)

- Optima release of upstream 0.4.3.
- **Files by the new Optima menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2).
- **Updates group in the shared Optima nav, and every group always shows.** Site Sync and the Change log sit under Updates (Optima → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Optima Site Standard rules 11 and 12).
- The update check, the release feed and the plugin details now use `optimadigitalmedia/Optima-Plugins`. GitHub still forwards the old address, which is how this release reached the site.
- **Dues through the site's own checkout, when WooCommerce is active.** Without WooCommerce the plugin works as before.
- **Approve, then pay.** With a dues product, approving puts a member on the new status **Approved, dues to pay**. The welcome email and their membership page carry **Pay your dues**: a signed link that puts the dues in the cart, tied to that member, and opens the checkout, signed in or not. Members-only pages and events open the moment the order is paid. Settings can switch to **pay with the application** instead: the payment is held, the year starts the day they are approved, and anyone not accepted is listed for a refund.
- **A paid order makes the member active.** On a processing or completed dues order, `Optima_Members::paid()` runs for that member (matched by the order's own meta, then the buyer's account, then email), the renewal date moves forward one period, the order shows under the member's account, and both the order and the member's new history get a note. Orders of dues alone complete themselves when a gateway reports payment.
- **Refunds and cancellations are flagged, never revoked.** The new **Renewals** screen lists them (with Keep the membership or End the membership), a payment from someone not accepted, and any dues order that matched no member. They also show in Events, Needs you, and as a count on the menu.
- **Renewals without saved cards.** Reminder emails 30 days (yearly plans) and 7 days before the renewal date, once each, with **Renew now** and the amount. A **grace period** keeps access open after the renewal date; then it closes by itself until they pay. Renewals shows who is due, past due, lapsed, approved but unpaid, and who paid in the last 30 days.
- **Nothing changes on a site that does not opt in.** Reminders go by default only to members whose plan has a dues product (Settings can send them to every member, with the next-step note in place of a pay link, or turn them off), so a site without a checkout sends no new email. The grace period defaults to 14 days on a fresh install and to **0 on a site upgraded from an earlier version**, so access still ends the day after the renewal date until the owner sets one. A one-time upgrade step writes that 0, and saving Settings never resets it.
- **Comped members.** All members has a **Renewal price** per member: empty for the plan price, 0 to renew free (a $0 checkout to confirm), or any amount. The import marks people whose latest order was $0 (a full-value code) as "Joined on a $0 order", and keeps everyone's real end date as their renewal date.
- **Headings follow the page's H1.** `level="3"` on either shortcode, or the `optima_members_heading_level` filter, moves them. The text size is unchanged.
- New: `optima_members_paid` action, `Optima_Members::log()` and `history()`, the privacy export includes the history and the eraser removes it. Declares WooCommerce order storage (HPOS) compatibility.
- Optima layer revision 8: upstream b2d477b: release rings, Site log and Your site, seven-item menu, Site Sync creates pages; Booking, Newsletter and Writing not carried yet.

## 0.3.3.4 (2026-10-31)

- Optima release of upstream 0.3.3.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.3.1.3 (2026-10-28)

- Optima release of upstream 0.3.1.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 0.3.1.2 (2026-10-27)

- Optima release of upstream 0.3.1.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
