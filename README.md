# SilverStripe Newsletter

Compose MailChimp-style, drag-and-drop HTML newsletters in the CMS from Elemental
blocks, manage CMS-defined audiences, brand them from a central theme, send them in
batches over SMTP, and track opens/clicks/bounces using Silverstripe's native CMS
preview pane in the editor.

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
- [Preview](#preview)
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
- **Native CMS preview** with Silverstripe's built-in device switching, dirty block
  refreshes, and an unsaved-changes banner.
- **Sent issue snapshots** so delivered newsletters and view-online pages do not
  change after a live send starts.

---

## Installation

1. In the project root `composer.json`, add the path repository and require it, or via command line:

   ```composer require mspacemedia/silverstripe-newsletter```

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

Merge tags are resolved late. A live send stores the rendered issue HTML before
recipient-specific values are substituted, then each delivery or recipient-specific
view-online request resolves `*|EMAIL|*`, names, custom merge data, unsubscribe and
view-online links for that subscriber.

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

When a live send starts, the issue is locked:

- A `NewsletterIssue.SentHTML` snapshot is captured and used for delivery and
  view-online rendering.
- The issue and its Elemental blocks are no longer editable or deletable once it has
  moved beyond Draft.
- Bulk sends resolve merge tags and tracking links from the locked snapshot, so later
  CMS changes cannot alter already-sent newsletters.

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

## Preview

Newsletter issues use Silverstripe's native `CMSPreviewable` support inside
`NewsletterAdmin`, so the preview appears in the standard CMS preview pane rather than
a custom floating panel. The built-in Silverstripe preview controls, including device
switching, remain available.

Preview rendering is handled by two ModelAdmin actions:

- `cmsPreview/<issueID>` renders the current persisted issue state.
- `cmsPreviewUnsaved/<issueID>` accepts dirty Elemental block form data, applies it to
  the in-memory block instance only, and renders the iframe without writing the block
  or issue.

`client/dist/newsletter-preview.js` only coordinates the native preview:

- It watches Elemental block dirty/status changes, including editor text changes before
  the block is saved as draft or published.
- It posts the dirty block payload once per changed payload, preventing repeat
  `cmsPreview` loops after the first edit.
- It injects the returned HTML into the native preview iframe and shows the translated
  "Contains unsaved changes" banner when the iframe represents unsaved block data.
- Once the block is saved and the dirty state clears, it refreshes the normal native
  preview URL.

Sent issues render from the locked `SentHTML` snapshot when available, so the preview
and public view-online pages reflect the sent content rather than later edits.

---

## Routes

| Route | Purpose |
| --- | --- |
| `newsletter/view/<token>` | Public "view online" page for an issue; uses the sent snapshot when present. |
| `newsletter/viewrecord/<token>` | Recipient-specific "view online" page for a send record; resolves merge tags for that subscriber. |
| `newsletter/unsubscribe/<token>` | One-click unsubscribe (per-subscriber token). |
| `newsletter/open/<token>.png` | Open-tracking pixel. |
| `newsletter/click/<token>?u=<url>` | Click redirector. |
| `newsletter/preview/<issueID>` | Legacy/admin preview route; native CMS preview uses `NewsletterAdmin` `cmsPreview` actions. |

---

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
client/dist/   newsletter-preview.js
lang/          Translation strings
```

---

## Out of scope (v0.1)

- Bounce capture via IMAP/POP polling or ESP webhooks (only piped-DSN handling so far).
- A/B testing, scheduled-future sends beyond a queued start, full open/click drill-down
  dashboards.
- Per-recipient preview selection inside the CMS; recipient-specific output is rendered
  by `newsletter/viewrecord/<token>` after a send record exists.
