# Site email through Cloudflare

How an Optima site sends its own email (form notices, chat and review alerts,
site checks, password resets) so it lands in the inbox and comes from the
site's own domain. Optima Build's **Email** screen (Optima, Build, Email) does
the sending; this page is the one-time setup around it.

## Why

- Managed hosts send from their own servers. Those servers are not in the
  domain's SPF record, so mail fails SPF and DMARC and lands in junk.
- **Cloudflare Email Service** signs mail for the site's own domain (DKIM) and
  adds its sending records itself, so mail passes DMARC with no mailbox and no
  Google app password. This is the default route for every new setup.
- Google with an app password still works and stays available as the second
  choice. A site set up before routes existed keeps sending through Google.

## Routes on the Email screen

| Route | Server | Port | User name | Password |
|---|---|---|---|---|
| Cloudflare Email Service (recommended) | `smtp.mx.cloudflare.net` | 465, implicit TLS only (587 STARTTLS is not supported) | `api_token`, exactly | A Cloudflare API token with **Email Sending: Edit** |
| Google (Gmail or Workspace) | `smtp.gmail.com` | 587, STARTTLS | The Gmail or Workspace address | A Google app password |
| Other mail service (SMTP) | Typed on the screen | 587, 465 or 2525 | Typed on the screen | The service's SMTP password or key |

Changing the route clears the saved token or password and the last test
result: a Google app password is not a Cloudflare token.

## Before you start

- Cloudflare email sending needs the **Workers Paid** plan on the account that
  sends, and it is still in **beta**. Check the daily quota while it is.
- The domain must be a zone in the **same Cloudflare account** that sends.
- Email Routing (forwarding incoming mail) is a different product and does not
  send.

## Setup (one site)

1. **The domain's DNS is on Cloudflare**, in the account that will send.
2. **Check DMARC first.** Look for a `TXT` record at `_dmarc`. The Email
   screen shows what it finds. If there is none, Cloudflare adds one with
   `p=reject`, which could junk the firm's own staff mail if that mail is not
   DKIM-signed yet. When unsure, add `v=DMARC1; p=none` yourself first.
3. **Add the domain in Cloudflare:** Email, Email Sending, add the domain.
   Cloudflare adds its records on the `cf-bounce` subdomain, so the domain's
   own MX and SPF (Google Workspace, Microsoft 365) stay untouched:
   - `MX` at `cf-bounce` to Cloudflare's mail servers
   - `TXT` at `cf-bounce`: `v=spf1 include:_spf.mx.cloudflare.net ~all`
   - DKIM at `cf-bounce._domainkey`
   - `_dmarc` only if the domain has no DMARC record yet
4. **Create an API token** with the **Email Sending: Edit** permission. It is
   shown once: keep it in the password manager.
5. **Put the token on the site.** Best: in `wp-config.php`, so it never sits in
   the database:
   ```php
   define( 'OPTIMA_MAIL_SERVICE', 'cloudflare' );   // cloudflare, google or other
   define( 'OPTIMA_MAIL_PASSWORD', 'the-api-token' );
   ```
   Or paste it on the Email screen (stored encrypted with the site's salts,
   never shown again).
6. **On the Email screen:** Send through Cloudflare, a send-from address on the
   site's domain (for example `forms@example.com`), a sender name, Save, then
   **Send test**.
7. **Check the test:** open it in the inbox, show the original message, and
   look for `spf=pass`, `dkim=pass` and `dmarc=pass`.

## When the test fails

| Cloudflare says | What it means | Fix |
|---|---|---|
| `535` authentication failed | The token is wrong or lacks Email Sending: Edit | Make a new token with that permission and paste it in |
| `550 5.7.1` Sender denied | The send-from domain is not added in Email Sending | Add the domain in Cloudflare, or use an address on a domain that is |
| Could not connect | Outbound port 465 is closed | Ask the host to open outbound port 465 |

The screen warns when the send-from address is not on the site's own domain.
That warning is normal on a staging copy whose address is a host subdomain.

## Forms that send "as" someone else

The site always sends as the saved address. When a form asks to send as the
visitor or the business owner, that address becomes the **Reply-To**, so a
reply still reaches the right person.

## DMARC for the main domain

- Start with monitoring: `v=DMARC1; p=none; rua=mailto:<reports address>`.
- After two to four weeks of clean reports (every real sender passes), move to
  `p=quarantine`, then `p=reject`.
- Before tightening, make sure the main domain's SPF lists every real sender
  (for Google Workspace: `include:_spf.google.com`) and that the mailbox
  provider's DKIM is on.
- One SPF record per name only, with no more than 10 DNS lookups in total.

## Staging

A staging copy should not email real people. Leave the Email screen off on
staging, or use a separate token for a test domain.

## Rolling back

**Turn off** on the Email screen (or remove the `wp-config.php` lines) sends
through the host again. The Cloudflare records on `cf-bounce` can stay; they do
nothing on their own.
