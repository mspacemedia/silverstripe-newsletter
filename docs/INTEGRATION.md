# Newsletter integration — a worked retail example

A complete, real-code example of wiring the `mspacemedia/silverstripe-newsletter`
module into a site. The site here is a **retail shop that supports both guest
checkout and registered customer accounts** — a very common shape — and this
document shows how it handles the parts that actually take thought:

- **who ends up on the mailing list**, and through which doors (checkout, account
  pages, a scheduled rebuild);
- **how the two kinds of customer are reconciled** — a guest identified only by
  email vs. an account-holder with a login;
- **how per-person "merge data" is generated** so newsletters can be personalised
  and segmented even for guests;
- **how subscription opt-in/opt-out stays in sync** across every place it can
  change.

It's the concrete companion to `README.md` (the framework-agnostic explanation of
the same feature): read `README.md` for the *concepts*, read this for *how an
actual integration is put together* — with the real classes and config you can
copy and adapt to your own retail site.

> The module is a first-class Composer requirement — **never edit it in
> `vendor/`.** Everything below lives in `app/` (plus one controller), which is the
> proper place to wire your own data in.

Files:

| File | Role |
| --- | --- |
| `app/_config/newsletter-providers.yml` | Registers providers, sets the subscriber `anchor_class`, seeds the brand, and defines the merge-field allowlist. |
| `app/src/newsletter/RetailOrderAudienceProvider.php` | Feeds the **retail** audience from customer `Order`s. |
| `app/src/extensions/NewsletterMemberReflectionExtension.php` | Reflects unsubscribes back onto the `Member` account. |
| `app/src/extensions/MemberExtension.php` | `onAfterWrite` syncs `Member.MarketingNotifications` → subscriber. |

Refresh the audience (idempotent upsert, safe to re-run — see "Refreshing"):

```bash
php vendor/silverstripe/framework/cli-script.php dev/tasks/NewsletterAudienceRefreshTask
```

> `php vendor/bin/sake …` does **not** work on this Windows host — it just echoes
> the wrapper script. Use `cli-script.php` for every sake-style command.

---

## The identity model — read this before writing segments

A subscriber's identity in this system is its **email**. The retail provider
dedupes on `BillingAddress.Email`.

The module's computed merge fields and segment expressions traverse a subscriber's
**Anchor** — a polymorphic `has_one` we set to the customer's `Member`
(`anchor_class: SilverStripe\Security\Member`). The Member's order history is the
`mOrders` relation (`MemberExtension`: `'mOrders' => Order.Member`), i.e. orders
joined by `Order.MemberID`.

**Email and `MemberID` are different keys**, so anchoring alone has two blind spots:

1. **Guest customers have no Member**, so no anchor → `mOrders.Count` is `0`. A
   guest who ordered 20 times by email would never match `mOrders.Count >= 5`.
2. **Members are under-counted.** `mOrders` only sees orders tied to `MemberID`.
   Orders the same person placed as a guest (before registering, or logged out)
   have `MemberID = 0` and are invisible, so a member with 3 account + 4 guest
   orders reads as `Count = 3`.

We therefore use **two complementary mechanisms**:

### 1. The Anchor (live, member-only)

Set on each subscriber that has a `Member`. Good for *live* drill-down across the
member's account orders, e.g. in newsletter body copy:

```
{{ mOrders.Where(Status = 'COMPLETE').Sum(TotalDonation) | currency }}
{{#if mOrders.Count }}Welcome back!{{else}}Your first order awaits{{/if}}
```

Only `mOrders` / `mSubscriptionOrders` and the listed `Order` fields are
traversable — see the allowlist below (the engine is default-deny).

The provider attaches the anchor when (and only when) the customer has an account:

```php
// app/src/newsletter/RetailOrderAudienceProvider.php (excerpt)
$member = Member::get()->filter('Email', $data['Email'])->first();
if ($member && $member->exists()) {
    $row['Anchor'] = $member;   // live {{ mOrders.* }} resolves through this
}
// guests fall through with no 'Anchor' key → unanchored, which is fine.
```

### 2. Pre-computed lifetime aggregates (canonical, email-keyed)

`RetailOrderAudienceProvider` sums **every** order for an email — guest and account
alike — and stores the totals in the subscriber's `MergeData`. These are the
figures to segment on, because they match the email identity:

| Tag | Meaning |
| --- | --- |
| `ORDERCOUNT` | Count of placed (non-`CART`) `CUSTOMER` orders for the email. |
| `LIFETIMESPEND` | Sum of `Order.Total`. |
| `LIFETIMEDONATION` | Sum of `Order.TotalDonation`. |
| `LASTORDER` | Most recent `Order.Placed` (raw datetime). |

They resolve as bare tags from `MergeData` for **every** subscriber (member or
guest), so they are correct where the anchor is not. They are a **snapshot**,
recomputed on each refresh — not live.

It's a two-pass build: accumulate across **all** of an email's orders, then yield
one row per email carrying the totals (guards elided — see the file):

```php
// app/src/newsletter/RetailOrderAudienceProvider.php (excerpt)

// Pass 1 — walk every order, accumulating per lower-cased email. The first
// occurrence (orders sorted newest-first) seeds identity + most-recent opt-in.
foreach ($orders as $order) {
    $email = strtolower(trim((string) $order->BillingAddress()->Email));
    if (!isset($customers[$email])) {
        $customers[$email] = [
            'Email'  => $order->BillingAddress()->Email,
            'Offers' => (bool) $order->Offers,   // most-recent checkout opt-in
            'Count'  => 0, 'Spend' => 0.0, 'Donation' => 0.0,
            // …FirstName, Surname, LastOrder…
        ];
    }
    $customers[$email]['Count']++;
    $customers[$email]['Spend']    += (float) $order->Total;
    $customers[$email]['Donation'] += (float) $order->TotalDonation;
}

// Pass 2 — one row per email, totals carried as MergeData.
foreach ($customers as $data) {
    yield [
        'Email'     => $data['Email'],
        'Consent'   => $consent,                  // member pref overrides Offers
        'MergeData' => [
            'ORDERCOUNT'       => $data['Count'],
            'LIFETIMESPEND'    => round($data['Spend'], 2),
            'LIFETIMEDONATION' => round($data['Donation'], 2),
            'LASTORDER'        => $data['LastOrder'],
        ],
        // 'Anchor' => $member,  // added when an account exists (see above)
    ];
}
```

> A subscriber with no orders simply has no `ORDERCOUNT`, so an order-history
> segment won't match them — which is the intended result.

---

## Writing segments

Any audience becomes a segment by setting a **Segment expression** (Segment tab).
Prefer the email-keyed aggregates so members **and** guests are included:

```
ORDERCOUNT >= 5                       lifetime 5+ orders (guests included)
LIFETIMEDONATION >= 1000              £1,000+ lifetime donation
LIFETIMESPEND >= 250                  £250+ lifetime spend
```

Use the anchor relation only when you specifically mean *account-linked* orders:

```
mOrders.Count >= 5                    5+ orders tied to a Member account ONLY
mOrders.Where(Status = 'COMPLETE').Count > 0
```

### Performance note (why aggregates are row-by-row)

`NewsletterSegmentService` has a fast path (one grouped `HAVING` query) for the
exact shape `Relation.Aggregate <op> Number` on the anchor class — so
`mOrders.Count >= 5` is batched. A bare `ORDERCOUNT >= 5` is a single-token
expression, not a relation path, so it evaluates **row-by-row** over active
subscribers. That is fine at this list size; "Build / refresh members" on the
segment recomputes membership on demand.

---

## Consent sync (Member ↔ subscriber)

`NewsletterSubscriber.Status` (keyed by email) is the authoritative suppression
state — sends only go to `Active` subscribers. For account-holders, the canonical
opt-in flag is `Member.MarketingNotifications`, and the two are kept in sync:

- **Member → subscriber:** `MemberExtension::onAfterWrite()` calls the module's
  `subscribe()` / `unsubscribe()` whenever `MarketingNotifications` changes (account
  page + registration both write that field, so they route through here).
- **Subscriber → member (unsubscribe):** `NewsletterMemberReflectionExtension`
  listens for the module's `onNewsletterUnsubscribe` hook and flips
  `MarketingNotifications` back to false, so an email-link unsubscribe shows on the
  account page. Guests have no account, so this is a safe no-op for them.
- **Subscriber → member (re-opt-in at checkout):** `CheckoutController` reflects a
  ticked offers box onto a matching `Member` (`MarketingNotifications = true`). This
  closes an asymmetry: `subscribe()` reactivates the *subscriber*, but without this
  the account flag would stay stale-false after a prior unsubscribe — the account
  page would read "not subscribed" while the customer receives mail, and the
  refresh task's member-authoritative consent check would then read false and skip
  them. Guests (no account) just subscribe directly.

> The retail provider's consent is **member-authoritative**: when a Member exists,
> `Member.MarketingNotifications` overrides the per-order `Offers` flag. That only
> stays correct because the flag is kept in sync at every opt-in/opt-out point
> above — don't add a new opt-in path that writes the subscriber without also
> writing the Member field (or vice-versa).

That decision is the few lines that set `$consent` before each yield:

```php
// app/src/newsletter/RetailOrderAudienceProvider.php (excerpt)
$consent = $data['Offers'];                          // most-recent checkout opt-in
$member  = Member::get()->filter('Email', $data['Email'])->first();
if ($member && $member->exists()) {
    $consent = (bool) $member->MarketingNotifications;   // account preference wins
}
// Consent=false rows are skipped by the refresh task (never unsubscribed).
```

---

## Configuration reference

The whole integration is four config blocks in one file,
`app/_config/newsletter-providers.yml`:

```yaml
# 1. Register the site's audience source provider(s). NewsletterAudienceRefreshTask
#    pulls subscribers from each into the audience whose SourceKey matches getKey().
MSpaceMedia\Newsletter\Task\NewsletterAudienceRefreshTask:
  providers:
    - Madc\Newsletter\RetailOrderAudienceProvider

# 2. Set the anchor type and reflect unsubscribes back onto the account.
#    anchor_class: computed fields ({{ … }}) and segments traverse the subscriber's
#    polymorphic Anchor record. Member is the module default; declared explicitly
#    here so the integration's intent is self-contained.
MSpaceMedia\Newsletter\Model\NewsletterSubscriber:
  anchor_class: SilverStripe\Security\Member
  extensions:
    - Madc\Extensions\NewsletterMemberReflectionExtension

# 3. Merge-field expression allowlist (DEFAULT-DENY). The engine can only traverse
#    the relations/fields named here, re-checked at send time — the visual builder
#    is a convenience, never the security boundary. Member->mOrders is the
#    customer-order relation; Order money fields back aggregates like
#    {{ mOrders.Sum(Total) | currency }}.
MSpaceMedia\Newsletter\Service\MergeFieldService:
  currency_symbol: '£'
  allowlist:
    SilverStripe\Security\Member:
      relations: [mOrders, mSubscriptionOrders]
      fields: [FirstName, Surname, Email]
    Madc\Order\Order:
      fields: [Total, TotalDonation, DonationAfterFee, Status, Type, Placed]

# 4. Optional: seed the default NewsletterBrand (editable in the CMS afterwards).
#    The module ships neutral defaults; override with your own palette here.
MSpaceMedia\Newsletter\Model\NewsletterBrand:
  defaults:
    Title: 'Example Shop'
    PrimaryColor: '#1d4ed8'
    LinkColor: '#1d4ed8'
    ButtonColor: '#1d4ed8'
    ButtonTextColor: '#ffffff'
    BodyBackground: '#f5f5f5'
    ContentBackground: '#ffffff'
```

Adding a new traversable field/relation means adding it to block 3 **and** (for
aggregates) ensuring the relation is a real non-polymorphic `has_many` if you want
the grouped fast path. Registering another audience source is just one more line in
block 1 pointing at a new provider class.

---

## Adding a new source provider

`RetailOrderAudienceProvider` is the worked reference; the shape of any provider is:

```php
class MyAudienceProvider implements AudienceSourceProvider
{
    public function getKey(): string   { return 'my-key'; }     // == NewsletterAudience.SourceKey
    public function getTitle(): string { return 'My audience'; } // used if auto-created

    public function getSubscribers(): iterable
    {
        yield [
            'Email'     => $email,         // required
            'FirstName' => $firstName,     // optional
            'Surname'   => $surname,       // optional
            'Consent'   => true,           // false = skip (never unsubscribes)
            'MergeData' => ['MYTAG' => 42],// optional bare-tag facts
            'Anchor'    => $someRecord,    // optional DataObject for live {{ … }}
        ];
    }
}
```

1. Implement `MSpaceMedia\Newsletter\Source\AudienceSourceProvider` in
   `app/src/newsletter/`. `getKey()` must match the target audience's `SourceKey`.
2. `getSubscribers()` yields rows as above (`Email` required; the rest optional).
3. Register it under
   `MSpaceMedia\Newsletter\Task\NewsletterAudienceRefreshTask.providers` in
   `newsletter-providers.yml`.
4. Run the refresh task.

---

## Refreshing

`NewsletterAudienceRefreshTask` upserts subscribers deduped by email:

- Existing records are **updated in place** (name, `MergeData`, `Anchor`); no
  duplicates are created.
- Suppressed (`Unsubscribed` / `Bounced`) subscribers are **never reactivated** —
  status is left untouched. **Do not purge and re-import**: that would resurrect
  unsubscribes as `Active` (PECR/GDPR breach) and regenerate `UnsubscribeToken`s,
  breaking unsubscribe links already in inboxes.
- `MergeData` is **fully rewritten** by the retail provider on each run (recomputed
  in full, not merged), so retail subscribers' merge tags are provider-owned. If you
  add another provider that writes the same subscribers, be aware it must not also
  set `MergeData` unless it means to overwrite these aggregates.
- The aggregates and anchor are a snapshot as of the last run — schedule the task
  (or run it before a segmented send) to keep them current.

---

