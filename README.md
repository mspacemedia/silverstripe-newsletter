# SilverStripe Newsletter

Compose MailChimp-style, drag-and-drop HTML newsletters in the CMS from Elemental
blocks, manage CMS-defined audiences, brand them from a central theme, send them in
batches over SMTP, and track opens/clicks/bounces — with a live preview pane in the
editor.

**Version:** 0.1 (first internal release)
**Requires:** SilverStripe 5.4 · PHP 8.1+

---

## Contents

- [Features](#features)
- [Installation](#installation)
- [Concepts](#concepts)
- [The block library](#the-block-library)
- [Branding / theme](#branding--theme)
- [Audiences, subscribers & CSV import/export](#audiences-subscribers--csv-importexport)
- [Dynamic audiences (source providers)](#dynamic-audiences-source-providers)
- [Sending](#sending)
- [Tracking (opens & clicks)](#tracking-opens--clicks)
- [Bounce handling](#bounce-handling)
- [Live preview](#live-preview)
- [Routes](#routes)
- [Developing the module](#developing-the-module)

---

## Features

- **Elemental block composer** with a full block set (header, headings, text, images,
  buttons, columns, social, video, code, footer, …) and inline editing.
- **Central brand/theme** (`NewsletterBrand`) — fonts, colours, button shape, logo;
  every block inherits it and can override per-block.
- **CMS-defined audiences** with CSV import/export, plus pluggable **source providers**
  to populate audiences from a host project's own models.
- **Instant test send** and **batched bulk send** (QueuedJobs) over SMTP, with retry +
  throttle (`MailHelper`) and a `List-Unsubscribe` header.
- **Open & click tracking**, per-issue engagement stats, and a CMS stats panel.
- **Bounce handling** by piping DSNs to a task (phase-1; webhook-free).
- **Live preview** docked in the editor with Desktop / Tablet / Mobile widths.

---

## Installation

The module is developed here as a Composer **path repository** (`_local-packages/`).

1. In the project root `composer.json`, add the path repository and require it:

   ```json
   "repositories": [
     { "type": "path", "url": "_local-packages/silverstripe-newsletter", "options": { "symlink": false } }
   ],
   "require": {
     "mspacemedia/silverstripe-newsletter": "1.0.x-dev"
   }
   ```

2. Install, build, expose client assets:

   ```bash
   composer install
   php vendor/bin/sake dev/build flush=all
   composer vendor-expose          # required for the live-preview JS/CSS
   ```

A default `NewsletterBrand` record is created automatically on `dev/build`.

> **Permissions:** everything is gated behind the `MANAGE_NEWSLETTERS` permission.
> Grant it to the groups that should manage newsletters (Security → Groups → Roles).

---

## Concepts

| Model | Role |
| --- | --- |
| `NewsletterIssue` | A single newsletter. Holds an Elemental area, targets audiences, carries send status + stats. |
| `NewsletterAudience` | A mailing list. Subscribers are attached manually, by CSV, or by a source provider. |
| `NewsletterSubscriber` | A recipient (unique by email). Belongs to many audiences. Status: Active / Unsubscribed / Bounced (global suppression). |
| `NewsletterBrand` | Global styling tokens (the "theme"). One record per install, editable in the CMS. |
| `NewsletterSendRecord` | Per-recipient delivery + engagement ledger (sent/failed/bounced, opens, clicks). |

All are managed under the **Newsletters** admin section.

---

## The block library

Issues are composed from Elemental blocks (edited inline). Available blocks:

`Header` (brand logo, overridable) · `Heading` · `Text` · `Boxed text` · `Columns`
(2–3) · `Image` · `Image + caption` · `Image group` · `Image card` · `Button` ·
`Video` (links out) · `Social follow` · `Logo` · `Custom HTML` · `Divider` · `Spacer`
· `Footer` (address + view-online/unsubscribe links).

Every block has an **Appearance** tab with per-block overrides — padding, alignment,
**font family, background colour, text colour, link colour** (blank = inherit the
brand), full-width **edge-to-edge** toggle (used by the Image block for full-bleed
images), and hide-on-mobile. Buttons additionally allow a per-block **corner radius**.

The blocks render to email-safe, table-based HTML; CSS is inlined at send time
(Emogrifier). These blocks are restricted to newsletters — they don't appear in normal
page editors.

---

## Branding / theme

`NewsletterBrand` holds the global look: font family, primary/link/heading/body
colours, page + content background, content width, button colour/text/radius/padding,
divider colour, footer colour, and a header logo.

- Edit it in **Newsletters → Brands / themes**.
- Blocks **inherit** these values; a block only overrides what you set on it.
- An issue can pick a specific brand (the **Brand / theme** dropdown), or use the default.
- The module ships neutral defaults. A host project can seed its own palette by
  overriding the config defaults, e.g.:

  ```yaml
  # app/_config/newsletter.yml
  MSpaceMedia\Newsletter\Model\NewsletterBrand:
    defaults:
      PrimaryColor: '#9c1d44'
      LinkColor: '#9c1d44'
      ButtonColor: '#9c1d44'
      BodyBackground: '#faf6ee'
  ```

  The seeded record remains fully editable in the CMS afterwards.

---

## Audiences, subscribers & CSV import/export

- Create audiences in **Newsletters → Audiences**.
- **Export**: each audience's subscriber grid has an Export button.
- **Import**: if `i-lateral/silverstripe-importexport` is installed, each audience's
  subscriber grid gains a CSV importer that adds rows **to that audience**, deduped by
  email (existing subscribers are reused, not duplicated).
- Suppression is **global per email** — an unsubscribed or bounced subscriber is
  skipped by every audience's sends and is never silently re-activated.

Subscribers support arbitrary **merge fields** (JSON), surfaced in templates as
MailChimp-style tags: `*|FNAME|*`, `*|LNAME|*`, `*|EMAIL|*`, `*|UNSUB|*`,
`*|VIEWONLINE|*`, plus any custom keys.

---

## Dynamic audiences (source providers)

A host project can feed an audience from its own data by implementing
`MSpaceMedia\Newsletter\Source\AudienceSourceProvider`:

```php
interface AudienceSourceProvider
{
    public function getKey(): string;     // matches NewsletterAudience.SourceKey
    public function getTitle(): string;   // used if the audience is auto-created
    public function getSubscribers(): iterable; // yields rows (see below)
}
```

Each yielded row:

```php
[
    'Email'     => 'person@example.com', // required
    'FirstName' => 'Jane',               // optional
    'Surname'   => 'Doe',                // optional
    'MergeData' => ['CITY' => 'Leeds'],  // optional, custom merge tags
    'Consent'   => true,                 // optional, default true; false = skip
]
```

Register providers in config and run the refresh task:

```yaml
MSpaceMedia\Newsletter\Task\NewsletterAudienceRefreshTask:
  providers:
    - App\Newsletter\MyOrderAudienceProvider
```

```bash
php vendor/bin/sake dev/tasks/NewsletterAudienceRefreshTask
```

The task upserts subscribers (deduped by email) into the matching audience, refreshes
name/merge data, and never re-activates unsubscribed/bounced records. Audiences with no
provider are simply manual/CSV-only.

> **Subscription API** (for project glue such as account pages / checkout):
> `MSpaceMedia\Newsletter\Service\NewsletterSubscriptionManager` provides
> `subscribe($email, $audienceKey, $data)`, `unsubscribe($email)`, `bounce($email)` and
> `isSubscribed($email)`. `unsubscribe()` fires an `onNewsletterUnsubscribe` extension
> hook so a project can reflect the change back onto its own models.

---

## Sending

Open an issue, compose it, choose its audience(s) and brand, then use the buttons in
the CMS:

- **Send test to me** — renders and sends a single `[TEST]` copy to the configured
  admin email **immediately** (synchronous; no queue runner needed).
- **Send to audiences** — queues a batched `NewsletterSendJob` (250 per batch, chained)
  that sends to every *active* subscriber in the targeted audiences, each via
  `MailHelper` (3× retry + throttle), records a `NewsletterSendRecord`, and stamps a
  `List-Unsubscribe` header. The issue moves Draft → Queued → Sending → Sent.

CLI equivalent:

```bash
php vendor/bin/sake dev/tasks/NewsletterSendTask?id=<IssueID>          # live send
php vendor/bin/sake dev/tasks/NewsletterSendTask?id=<IssueID>&test=true # test send
```

> SMTP transport is the project's `SS_MAILER_DSN`. In dev, use a catcher / the
> `send_all_emails_to` safety net so tests never reach real recipients.

---

## Tracking (opens & clicks)

- A 1×1 **open pixel** is embedded per recipient (`newsletter/open/<token>.png`).
- Outbound links are rewritten through a **click redirector**
  (`newsletter/click/<token>?u=…`); internal/unsubscribe/mailto links are left alone.
- Counts land on `NewsletterSendRecord` (opens, clicks, first/last opened).
- Each issue exposes aggregates (`getSentCount`, `getOpenedCount`, `getClickedCount`,
  `getBouncedCount`, `getOpenRate`, `getClickRate`) and a **Statistics** tab in the CMS.

Open tracking is best-effort (image-blocking inflates "not opened"); clicks are the
firmer signal.

---

## Bounce handling

Phase-1, webhook-free. Pipe bounce DSNs (from a `bounces@` forwarder) to the task:

```
… bounce mail … | php vendor/bin/sake dev/tasks/NewsletterBounceTask
# or, for testing:
php vendor/bin/sake dev/tasks/NewsletterBounceTask?file=/path/to/bounce.eml
```

Each send carries an `X-Newsletter-Token` header; the task correlates the bounce back
to its `NewsletterSendRecord` (falling back to the `Final-Recipient` address), marks the
record **Bounced** with the diagnostic reason, and **globally suppresses** the
subscriber. `processRaw(string $raw): bool` is public so a future IMAP/POP poller can
reuse the parsing.

> Requires a mail-forwarder rule and `proc_open` on the host.

---

## Live preview

While editing an issue a **live preview** is docked to the right of the editor:

- **Desktop / Tablet / Mobile** width toggles (scaled to fit the column).
- **Refresh** and **Hide** controls.
- Re-renders automatically as blocks are added, edited or reordered (after each block
  is saved — not per-keystroke), plus a manual refresh.

It is served by the admin-only `newsletter/preview/<issueID>` endpoint and powered by
`client/dist/newsletter-preview.js` (exposed via `composer vendor-expose`).

---

## Routes

| Route | Purpose |
| --- | --- |
| `newsletter/view/<token>` | Public "view online" page for an issue. |
| `newsletter/unsubscribe/<token>` | One-click unsubscribe (per-subscriber token). |
| `newsletter/open/<token>.png` | Open-tracking pixel. |
| `newsletter/click/<token>?u=<url>` | Click redirector. |
| `newsletter/preview/<issueID>` | Admin-only live preview (current/draft state). |

---

## Developing the module

Because the path repository uses `"symlink": false`, Composer **mirrors** the module
into `vendor/`. Edits in `_local-packages/silverstripe-newsletter/` are **not** picked
up by a plain `composer install`/`update`. To apply changes:

```bash
rm -rf vendor/mspacemedia/silverstripe-newsletter
composer install
php vendor/bin/sake dev/build flush=all
composer vendor-expose   # when client/dist assets changed
```

There is no automated test suite; validation is `dev/build flush=all` plus rendering an
issue (the render service is pure, so a throwaway BuildTask that renders an issue and
asserts on the HTML is the quickest check).

### Layout

```
src/
  Model/    NewsletterIssue, NewsletterAudience, NewsletterSubscriber,
            NewsletterBrand, NewsletterSendRecord, NewsletterPermissions
  Elements/ NewsletterBlockElemental (base) + concrete blocks, ScaledImageTrait
  Service/  NewsletterRenderService, NewsletterSubscriptionManager, NewsletterSender
  Job/      NewsletterSendJob
  Task/     NewsletterSendTask, NewsletterAudienceRefreshTask, NewsletterBounceTask
  Control/  NewsletterController
  Admin/    NewsletterAdmin
  Email/    MailHelper
  Source/   AudienceSourceProvider
templates/MSpaceMedia/Newsletter/Email/   Wrapper.ss + Blocks/*.ss
client/dist/   newsletter-preview.js / .css
```

---

## Out of scope (v0.1)

- Bounce capture via IMAP/POP polling or ESP webhooks (only piped-DSN handling so far).
- A/B testing, scheduled-future sends beyond a queued start, full open/click drill-down
  dashboards.
- Per-keystroke (pre-save) live preview.
