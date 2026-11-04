# Changelog

## 0.16.3.8 (2026-11-04)

- Optima release of upstream 0.16.3.
- **Files by the new Optima menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2).
- **Updates group in the shared Optima nav, and every group always shows.** Site Sync and the Change log sit under Updates (Optima → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Optima Site Standard rules 11 and 12).
- The update check, the release feed and the plugin details now use `optimadigitalmedia/Optima-Plugins`. GitHub still forwards the old address, which is how this release reached the site.
- **A members directory people can contact from.** `[optima_directory contact="yes"]` adds where each speaker or vendor works and how to reach them to their card: Website, Book, Email, phone, and their social and shop links named by site (Instagram, Facebook, Linktree, Etsy and so on). Search also finds them by city. Off by default, so a public directory shows what it showed before.
- **A list layout.** `[optima_directory layout="list"]` shows speakers or vendors the way people look for help: a search box, a Kind of work choice and a Where choice (plain dropdowns), everyone A to Z with a letter rail, and a name that opens to its sheet in place (contact in contact mode, topics, the link to their page). Works without JavaScript; filters as you type with it.
- **Public contact fields.** Speakers gain Where they work, Booking or services link, Business email and Business phone; vendors gain Business email, Business phone and social links. They show only in a `contact="yes"` directory, never on the public speaker page, and stay separate from the private presenter and vendor details. The CSV import takes `city`, `booking`, `email`, `phone` (speakers) and `email`, `phone`, `links` (vendors).
- Email links are encoded against address harvesters; each link carries the person's name for screen readers.
- **Paid tickets, end to end, up to the payment step.** "Create a ticket" on an event makes a simple, virtual WooCommerce product (name, price, how many, sales open and close, the name on receipts, what it includes) already ticked as the event's ticket. Checkout asks for each ticket holder's name. When the order is paid, each ticket becomes an attendee in the sign-ups table: the same QR ticket, door list, check-in and day-before reminder as free sign-ups, counted in places left. The buyer gets one email with every ticket and the calendar file; a holder with their own email gets a copy. Tested with WooCommerce's offline method renamed "Test payment"; a real payment service is a WooCommerce setting.
- **Refunds void tickets.** A full refund, a cancelled order or a partial refund turns the refunded tickets void ("This ticket was refunded"; they never check in). Partial refunds void tickets not yet checked in first.
- **Name on receipts.** A ticket can carry a plain name for the order, the WooCommerce emails and the payment service (such as "Workshop registration"), while the event page shows its own name.
- **Sales window and capacity for shop tickets.** Each ticket has its own sales open and close dates ("On sale December 1", "Sales closed"). The event's Capacity caps free and paid tickets together, in the quantity box, the cart and Sold out.
- **Agreements per person.** An event can ask each ticket holder to agree to the site's community agreements (one link under Settings) and a waiver the owner writes, confirm their age when the event has a minimum age, and choose yes or no for photos, then type their name to sign. Asked on the sign-up form and once per ticket at checkout. The ticket keeps the signature, the time and the version of the words; every version's words stay on the event. The door list and a scanned ticket show "Agreed" or "Has not agreed yet" and "No photos", and the ticket page asks anyone who still owes an answer. The spreadsheet download gains Ticket, Order, Agreed at, Signature, Agreement version, Age confirmed, Photos and Newsletter requested.
- **Newsletter opt-in.** An unticked "Send me the newsletter" box on sign-ups and checkout, shown when a newsletter connector listens. Consent is stored with its time, and `optima_newsletter_optin` ( $email, $first_name, $source, $tags, $context ) fires for the connector, with `$source` `event` (free sign-up) or `ticket` (paid checkout), no per-event tags, and the exact box wording, the event's address and its slug in `$context` (`wording`, `page`, `url`, `campaign`). Four-argument listeners keep working. Nothing is sent to a newsletter service from here.
- While the cart holds tickets, a block checkout page shows the classic checkout form, which has room for per-ticket fields (`optima_events_classic_checkout` to keep the block one). Paid attendees stay off on sites running FooEvents (`optima_events_paid_attendees`).
- The sign-ups table gains columns for the source, order, agreements, photo choice and newsletter time (it updates itself). events.csv takes `agree`, `waiver` and `photo_ask`. The Ready to share list counts shop tickets as the ticket link and price.
- Optima layer revision 8: upstream b2d477b: release rings, Site log and Your site, seven-item menu, Site Sync creates pages; Booking, Newsletter and Writing not carried yet.

## 0.14.3.4 (2026-10-31)

- Optima release of upstream 0.14.3.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Optima plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Optima plugins were already updating themselves; only the label was wrong.
- Optima layer revision 4: the port now carries the Optima-only work itself: editable pages in Optima Core, site-font widgets, full changelog history.

## 0.14.1.3 (2026-10-28)

- Optima release of upstream 0.14.1.
- Optima layer revision 3: the Optima admin screens use IBM Plex Sans for body text (Optima design system v2, October 2026), replacing Nunito Sans.

## 0.14.1.2 (2026-10-27)

- Optima release of upstream 0.14.1.
- Optima layer revision 2: the section nav styled on plain WordPress screens (the kit stylesheet gets its own handle, so the theme's admin stylesheet no longer replaces it), and icon chips on feature cards.
