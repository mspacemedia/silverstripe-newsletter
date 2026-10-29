# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## What this is

A SilverStripe 5.4 **vendor module** (`mspacemedia/silverstripe-newsletter`, PHP 8.1+) for composing
MailChimp-style drag-and-drop HTML newsletters from Elemental blocks, managing audiences, and sending
in batches over SMTP with open/click tracking. PSR-4 namespace `MSpaceMedia\Newsletter\` → `src/`,
tests `MSpaceMedia\Newsletter\Tests\` → `tests/`.

This repo is **not runnable on its own** — it is installed into a host SilverStripe project. There is
no `phpunit.xml`, build script, or standalone app here. All commands below run from the **host project
root** with this module under `vendor/mspacemedia/silverstripe-newsletter`.

## Commands

```bash
# Rebuild schema after any $db / $has_many / new DataObject change (seeds a default NewsletterBrand)
vendor/bin/sake dev/build flush=1

# Run this module's tests (from host project root)
vendor/bin/phpunit vendor/mspacemedia/silverstripe-newsletter/tests
# Single test class / method
vendor/bin/phpunit vendor/mspacemedia/silverstripe-newsletter/tests/RenderServiceTest.php
vendor/bin/phpunit --filter testMethodName vendor/mspacemedia/silverstripe-newsletter/tests/RenderServiceTest.php

# Sending (also driven from CMS buttons on an issue)
vendor/bin/sake dev/tasks/NewsletterSendTask?id=<IssueID>            # live (queued) send
vendor/bin/sake dev/tasks/NewsletterSendTask?id=<IssueID>&test=true  # immediate test send
vendor/bin/sake dev/tasks/NewsletterAudienceRefreshTask              # repopulate provider-backed audiences
... bounce.eml | vendor/bin/sake dev/tasks/NewsletterBounceTask      # pipe a bounce DSN
```

Tests extend `SapphireTest` and need a database; they cannot run without a bootstrapped host project.

## Architecture

### Rendering pipeline — one render, many outputs (`NewsletterRenderService`)
The email *is* the canonical output: the same block templates power the email, the public "view online"
page, and the CMS preview — there are no parallel web/email template sets. Flow:

1. `renderSnapshot()` renders each `NewsletterBlockElemental` in the issue's ElementalArea with its
   `Email\Blocks\<ShortClassName>.ss` template, wraps them in `Email\Wrapper.ss`, then **inlines all
   CSS with Emogrifier** (Gmail/Outlook strip `<style>`). Merge tags and personalised links are left as
   literal `*|TAG|*` / `*|VIEWONLINE|*` / `*|UNSUB|*` placeholders at this stage.
2. `finaliseSnapshot()` is applied per recipient: resolves merge tags, and (when a tracking token is
   given) injects the open pixel and rewrites outbound `http(s)` links through the click redirector.

This two-phase split is the core design: **content/layout is frozen once; recipient-specific data is
resolved late.** When editing rendering, keep the snapshot pre-merge and tag resolution per-recipient.

### Send-time locking via `SentHTML` snapshot (`NewsletterIssue`)
`SendStatus` enum: `Draft → Queued → Sending → Sent` (+ `Cancelled`). On the first live send the issue
captures `SentHTML = renderSnapshot()`. After that:
- `hasSentSnapshot()` is true, so all rendering (email, view-online, preview) reads `SentHTML` instead
  of re-rendering blocks — **later CMS edits cannot alter an already-sent newsletter.**
- The issue is editable/deletable only while `SendStatus === 'Draft'` (`isEditable()`).
- `NewsletterIssue` is `Versioned` and implements `CMSPreviewable`.

### Blocks (`src/Elements/`)
`NewsletterBlockElemental` is the **concrete** base (SilverStripe's schema builder instantiates every
data class, so it cannot be abstract); it hides itself from the add-block menu via `canCreateElement()`
returning false only for `self::class`. It holds the shared **Appearance** tab fields (padding,
alignment, font, colours, full-width, hide-on-mobile) — blank colour/font = inherit the brand. Each
concrete block has a matching `templates/MSpaceMedia/Newsletter/Email/Blocks/<Name>.ss`.

Key conventions:
- `setRenderBrand()` injects the effective brand transiently so `Effective*()` style methods resolve
  brand inheritance without template arguments.
- `safeColor()` validates every stored colour before it reaches a `style=""` attribute — always route
  colour output through it.
- Rich-text blocks override `usesBlockAlignment()` to false so TinyMCE controls alignment (cell-level
  `text-align` would conflict). The `newsletter` TinyMCE config is defined in `_config.php`.
- All newsletter blocks are added to `Page.disallowed_elements` in `_config/newsletter.yml` so they
  never appear in normal page editors — **register any new block there too.**
- `Surface` (none/accent/primary) bands a block in brand colours. On `primary`, `isInverse()` flips
  text, headings, links and buttons to `InverseTextColor`; colour helpers (`Effective*Color()`,
  `SurfaceColor()`) already account for it, so templates should use them rather than `$Brand.*`.
- Buttons go through `renderButton()` (Outlook-safe table + `mso-padding-alt`); don't hand-roll `<a>`
  buttons in templates.
- Rich-text styling hooks: `richTextColor()`, `richTextLinkColor()`, `richTextTagStyles()` push inline
  styles that beat the inlined brand typography (see `FooterBlock`).
- Emogrifier's pruner drops anything with inline `display:none`; give elements that must survive
  (e.g. the preheader) the `-emogrifier-keep` class. `inlineCss()` logs and falls back to un-inlined
  HTML on failure, so a broken inliner shows up in the log, not as a send failure.

### Starter templates (`Service\NewsletterStarterService`)
YAML `starters` (in `_config/newsletter-starters.yml`, host-extendable) plus issues flagged
`IsTemplate` feed the "Start from" dropdown on a new `NewsletterIssue`. The choice is a transient
`StartFrom` value applied in `onAfterWrite()` (cleared first, as applying writes the issue again). A
layout is never applied to an issue that already has blocks. Starter `image` keys import a placeholder
from `client/dist/starters/` once into `assets/newsletter/starters/`. Template issues can be
test-sent, but `doSendNewsletter()` and `NewsletterSendJob` refuse a live send.

### Brand inheritance (`NewsletterBrand`)
Single global theme record (font stacks, colours, widths, button styling, logo). Blocks inherit from it
and override only what they set. Host projects seed a palette via YAML `defaults` (still CMS-editable
after seeding) — see README "Branding / theme".

### Sending — two paths, one transport
- **Test send**: `NewsletterSender::sendTest()` is synchronous (no queue runner needed).
- **Bulk send**: `NewsletterSendJob` (QueuedJobs) processes audiences in batches of 250, chaining a
  new job per offset, recording a `NewsletterSendRecord` per recipient and stamping a `List-Unsubscribe`
  header.
- Both deliver through `MailHelper::send()` (3× retry + throttle + logging) over the project's
  `SS_MAILER_DSN`. Suppression (unsubscribed/bounced) is **global per email address**.

### Tracking & bounces
Opens via `newsletter/open/<token>.png` pixel; clicks via `newsletter/click/<token>?u=…` redirector
(internal/unsubscribe/mailto links untouched). Counts land on `NewsletterSendRecord`. Bounces are
phase-1 / webhook-free: `NewsletterBounceTask` parses a piped DSN, correlates via the
`X-Newsletter-Token` header (fallback `Final-Recipient`), marks the record Bounced and suppresses the
subscriber.

### Extension points for host projects
- `Source\AudienceSourceProvider` interface + `NewsletterAudienceRefreshTask.providers` YAML list:
  feed an audience from the host project's own models (upsert deduped by email, never re-activates
  unsubscribed/bounced).
- `Service\NewsletterSubscriptionManager`: `subscribe/unsubscribe/bounce/isSubscribed`; `unsubscribe()`
  fires the `onNewsletterUnsubscribe` extension hook.

### Admin & preview
`Admin\NewsletterAdmin` (ModelAdmin) manages all models and serves preview via `cmsPreview/<id>`
(persisted state) and `cmsPreviewUnsaved/<id>` (applies dirty Elemental block form data in-memory only,
without writing). `client/dist/newsletter-preview.js` only coordinates SilverStripe's **native** CMS
preview pane (dirty-block detection, refresh, unsaved-changes banner) — it is not a custom preview UI.
Everything is gated behind the `MANAGE_NEWSLETTERS` permission (`Model\NewsletterPermissions`).

### Computed merge fields — the `{{ … }}` engine
A second, newer merge syntax sits alongside the legacy `*|FNAME|*` resolver (which is left
untouched). Editors define `NewsletterMergeField` records (Tag + Expression) and reference them in
content as `{{ TAG }}`, or write inline expressions / `{{#if}}…{{else}}…{{/if}}` blocks. Pipeline:

- `Service\MergeExpression\Parser` — pure lexer + recursive-descent parser → array AST (no ORM; cached
  and unit-tested). Grammar: relation paths with aggregates (`Order.Sum(Amount)`, `.Count`), `Where()`
  filters, arithmetic, comparisons, functions (`Concat/Round/If/Coalesce/Upper/Lower`), pipe filters
  (`| currency`, `| default(0)`).
- `Service\MergeExpression\Evaluator` — walks the AST against a subscriber's **anchor** record
  (`NewsletterSubscriber.Anchor`, a polymorphic has_one; `anchor_class` config drives the builder).
  **Security: default-deny allowlist** (`MergeFieldService.allowlist`, class ⇒ relations/fields) gates
  every traversal; aggregates/`Where()` run as parameterised ORM queries — no `eval`, no raw SQL.
- `Service\MergeFieldService` — resolves `{{#if}}` blocks then `{{ }}` outputs over the snapshot HTML,
  **per recipient inside `NewsletterRenderService::finaliseSnapshot`** (so the locked `SentHTML` stays
  generic and personalises at delivery). Resolves a bare name as: defined field → built-in
  (FirstName/Email/subscriber merge data) → anchor field/relation. Cycle-guarded; broken tags render
  empty rather than leaking.
- Builder UI: `Forms\MergeFieldBuilderField` + `client/dist/newsletter-mergefield.js` (vanilla, no
  build step) with relation/field pickers and a **live preview** that calls the CMS-gated
  `NewsletterAdmin::mergePreview`/`mergeSchema` actions — evaluation is always server-side.

Host projects must opt in via the allowlist (see commented example in `_config/newsletter.yml`); the
feature is inert until they do.

### Audience segments
`NewsletterAudience.SegmentExpression` (+ optional `BaseAudience`) reuses the expression engine as a
**boolean** filter: `Service\NewsletterSegmentService` materialises the active subscribers whose
expression is truthy into the audience's `Subscribers()`, so the send path is unchanged. Triggered by
the **"Build / refresh members"** `CustomAction` button on the audience (not a task). `matchingIDs()`
uses a **grouped-query fast path** for `Relation.Count|Sum|… <op> number` (ops `>`/`>=`/`=` only, where
zero/no-anchor can't match) and falls back to row-by-row (`MergeFieldService::matches`) for everything
else — both must agree (asserted in `SegmentServiceTest`). The builder's "Estimate matches" button
hits `NewsletterAdmin::segmentEstimate`.
