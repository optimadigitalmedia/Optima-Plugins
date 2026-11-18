# Optima Analytics

Google Tag Manager (or a GA4 tag) on the live site, with Google consent mode
set up first, a small cookie notice, and one way for every Optima feature to
report clicks. Settings: Optima, Search and AI, Analytics.

## Event names (plugin-first rule 50)

Every site sends the same seven names, so Recon reads every site the same way.
Make one Custom Event trigger per name in Tag Manager.

| Event | Sent when | Parameters |
|---|---|---|
| `call_click` | A phone link is tapped (call bar, the Let's talk sheet, a team card) | `location`, `method` (`tel`), `link_label` |
| `text_click` | A text link is tapped (call bar, the Let's talk sheet) | `location`, `method` (`sms`), `link_label` |
| `email_click` | An email link is tapped (a team card) | `location`, `method` (`mailto`), `link_label` |
| `booking_click` | A booking link is tapped (the Let's talk sheet, the chat) | `location`, `method` (`link`), `link_label`, `link_url` |
| `directions_click` | A directions link is tapped (events) | `method` (`link`), `link_label`, `link_url` |
| `form_submit` | A Gravity Forms confirmation shows, or a chat lead is sent | `form_id` (the form number, or `chat`), `location` (`form` or `chat`), `method` (`gravity_forms` or `chat`) |
| `chat_start` | The chat panel opens for the first time in a visit | `location` (`chat`), `method` (`ai` or `collect`) |

`location` is where on the page it happened: `call_bar`, `lets_talk_sheet`,
`team_card`, `chat` or `form`.

**No personal data reaches the dataLayer.** Phone, text and email links send
their `method`, never the number or address. A form sends its ID only, never
what the visitor typed. `link_url` is sent for web links only.

### Gravity Forms

`form_submit` fires once per submission:

- AJAX forms: on Gravity Forms' own `gform_confirmation_loaded` event.
- Forms that reload the page with a message: a hidden marker in the
  confirmation (`data-optima-form-submit="<form ID>"`), read when the page loads.
- Forms that redirect: a first-party cookie, `optima_form_submit`, holding the
  form ID for five minutes. The next page reads it, sends the event and clears it.

## Old event names (opt in)

Before rule 50 the plugins sent other names. A site whose Tag Manager triggers
still listen for them turns them back on, so both names go out until the
container is updated:

```php
// wp-config.php
define( 'OPTIMA_ANALYTICS_LEGACY_EVENTS', true );
```

or `add_filter( 'optima_analytics_legacy_events', '__return_true' );`. Off by
default. The Analytics screen (For developers) shows whether it is on.

| Old name | New name | `location` |
|---|---|---|
| `contact_call` | `call_click` | `call_bar` or `lets_talk_sheet` |
| `contact_text` | `text_click` | `call_bar` or `lets_talk_sheet` |
| `contact_book` | `booking_click` | `lets_talk_sheet` |
| `team_call` | `call_click` | `team_card` |
| `team_email` | `email_click` | `team_card` |
| `chat_open` | `chat_start` (first open in a visit only) | `chat` |
| `chat_book` | `booking_click` | `chat` |
| `lead_submit` | `form_submit` (`form_id` `chat`) | `chat` |
| `contact_open`, `chip_click`, `handoff`, `chat_work`, `chat_link` | none: not standard events, sent only with old names on | |

With old names on, `chat_open` still goes out on every open, as before.

## For scripts

```js
window.optimaTrack( 'call_click', { location: 'call_bar', method: 'tel' }, 'contact_call' );
```

The third argument is the old name, sent only when old names are on. Pass `''`
as the first argument to send only an old name. Links can carry
`data-optima-track`, `data-optima-location`, `data-optima-label` and
`data-optima-legacy` instead.
