# Site email through a sending service

How an Optima site sends its own email (form notices, chat and review alerts,
site checks, password resets) so it lands in the inbox. Optima Build's
**Email** screen does the sending; this page is the one-time setup around it.

## Why

- Managed hosts send from their own servers. Those servers are not in the
  domain's SPF record, so mail fails SPF and DMARC and lands in junk.
- Google with an app password works, but needs a personal mailbox's app
  password on every site.
- A **sending service** signs mail for the site's own domain (DKIM), so it
  passes DMARC with no mailbox and no app password. Each site gets its own
  key, limited to sending for its own domain.

## Pick a service

| Service | Good for | Notes |
|---|---|---|
| Resend | Simple setup, clear dashboard | SMTP user is `resend`, password is the API key |
| Postmark | Best inbox placement for transactional mail | The server token is both user name and password |
| Amazon SES | Lowest cost at volume | More setup: verify domain, leave the sandbox, make SMTP credentials |
| Brevo | Free tier, marketing tools too | SMTP login and key from its SMTP settings |

Check each service's current plan limits (domains per account, emails per day)
before choosing. One account can hold many verified domains.

## Setup (one site)

1. **Choose the sending domain.** Use a subdomain only for site mail, for
   example `send.example.com`. It keeps the domain's own email records (Google
   Workspace, Microsoft 365) untouched.
2. **Add the domain in the service** (Domains, Add domain). Turn open and click
   tracking **off**: transactional mail does not need it and links stay clean.
3. **Add the DNS records in Cloudflare.** Cloudflare, the domain, **DNS,
   Records, Add record**, one record each, exactly as the service lists them:
   - DKIM: usually a `TXT` (or `CNAME`) at a name like `resend._domainkey.send`.
   - SPF for the subdomain: a `TXT` at `send` such as `v=spf1 include:<service> ~all`
     (the service gives the exact value), plus the `MX` record it lists for
     bounces, if any.
   - Set every record to **DNS only** (grey cloud). A proxied record breaks mail.
   - Add only these records. Change or delete nothing else.
4. **Verify** in the service and wait until the domain shows verified.
5. **Make a key** with **sending access only**, limited to that domain. It is
   shown once: keep it in the password manager.
6. **Put the key on the site.** Best: in `wp-config.php`, so it never sits in the
   database:
   ```php
   define( 'OPTIMA_MAIL_SERVICE', 'resend' );        // google, resend, postmark, ses, brevo, custom
   define( 'OPTIMA_MAIL_PASSWORD', 'the-sending-key' );
   ```
   Or paste it on **Optima, Build, Email** (stored encrypted with the site's salts).
7. **On the Email screen:** pick the service, Save, enter a send-from address on
   the verified domain (for example `hello@send.example.com`) and a sender
   name, Save, then **Send test**. Check the inbox and the message headers:
   `spf=pass`, `dkim=pass`, `dmarc=pass`.

## DMARC for the main domain

Every domain should have one `TXT` record at `_dmarc`:

- Start with monitoring: `v=DMARC1; p=none; rua=mailto:<reports address>`.
- After two to four weeks of clean reports (every real sender passes), move to
  `p=quarantine`, then `p=reject`.
- Before tightening, make sure the main domain's SPF lists every real sender
  (for Google Workspace: `include:_spf.google.com`) and that the mailbox
  provider's DKIM is on (Google Admin, Apps, Gmail, Authenticate email).
- One SPF record per name only, with no more than 10 DNS lookups in total.

## Forms that send "as" someone else

The site always sends as the saved address. When a form asks to send as the
visitor or the business owner, that address becomes the **Reply-To**, so a
reply still reaches the right person.

## Staging

A staging copy should not email real people. Leave the Email screen off on
staging, or use a separate key for a test domain.

## Rolling back

**Turn off** on the Email screen (or remove the two `wp-config.php` lines)
sends through the host again. The DNS records can stay; they do nothing on
their own.
