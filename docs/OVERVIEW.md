# Personalising newsletters with live data — a worked example

This is a step-by-step walkthrough of how to wire real customer data into your
newsletters so each person can be greeted by name, told things that are true about
*them*, and grouped into targeted lists automatically.

It's written to be followed by anyone — you don't need to be a programmer. The code
snippets are there to *show the shape* of each step; the words around them explain
what's happening and why.

---

## The story we'll use

In our solution there are three kinds of record, and it helps to picture them as
three separate things:

- **Orders** — every purchase someone makes. An order always has an email address
  on it (you can't check out without one).
- **Customer accounts** — an optional login. Some people register an account; many
  don't and simply check out as a guest.
- **Subscribers** — the mailing list itself. One entry per email address, holding
  whether that person is currently subscribed.

The important thing to notice up front: **a person is identified by their email
address**, but **not everyone has an account**. Someone can place ten orders as a
guest and never register. That single fact shapes everything below.

Our goal: send a newsletter that can say *"Hi Jane, you've placed 7 orders with
us"* — and automatically build a list of *"customers who've ordered 5+ times"* —
and have all of that work whether Jane has an account or not.

---

## Step 1 — Decide what each subscriber should "point at"

A subscriber, on its own, only knows an email address and a name. To say anything
richer ("how many orders", "how much they've spent") it needs to be **connected to
a record that holds that information**.

We call that connection the **anchor**. Think of it as a luggage tag tied to the
subscriber that says *"for the full story about this person, look over here."* In
our solution, the natural anchor is the person's **customer account**, because an
account can lead to that customer's orders.

You tell the system, once, what kind of record the anchor usually is:

```
Subscriber:
  anchor type: Customer Account
```

That's it for configuration. From now on, when the newsletter wants extra detail
about a subscriber, it follows the tag to their account.

> A subscriber with no anchor (our guests, who have no account) is perfectly
> fine — anything that relies on the anchor simply comes back blank, and we handle
> that gracefully later.

---

## Step 2 — Attach the anchor when people join the list

People arrive on the list through two different doors, and we attach the anchor at
each one:

**Door A — someone with an account opts in.** We hand the subscription over with
the account attached as the anchor:

```
subscribe(email, list: "retail", {
    firstName: "Jane",
    anchor:    $customerAccountDataObject,   // the luggage tag
})
```

**Door B — the scheduled audience task.** Periodically a background task rebuilds
each list from our records. As it does, it attaches the same anchor for anyone who
has an account:

```
for each customer found in orders:
    yield {
        email:  customer.email,
        anchor: customer.account   // only if they actually have one
    }
```

Guests have no account, so they simply arrive with **no anchor** — that's expected,
and Step 5 is where we make sure they're not left out.

---

## Step 3 — Tell the system what it's allowed to read

Before the newsletter can read anything from the anchor, you have to **explicitly
list what's allowed**. Nothing is readable by default.

This is deliberate, and it's a good thing: it stops anyone composing a newsletter
from accidentally pulling out sensitive details (passwords, internal flags), and it
keeps the list of "things a newsletter can mention" short and intentional.

```
Allowed to read:
  Customer Account:
    related lists: [ their Orders ]
    fields:        [ First name, Surname, Email ]
  Order:
    fields:        [ Total, Status, Date ]
```

Read this as a permission slip: *"a newsletter may look at an account's orders, and
on each order it may see the total, status and date — and nothing else."*

---

## Step 4 — Let the newsletter ask questions

Now you can drop **questions** straight into newsletter content using double
braces. The system answers them per person, at the moment the email is sent:

```
Hi {{ FirstName }},

You've placed {{ Orders.Count }} orders with us,
totalling {{ Orders.Sum(Total) | currency }}.
```

For Jane this might arrive as *"You've placed 7 orders with us, totalling £320.00."*

You can also show or hide whole chunks depending on the answer:

```
{{#if Orders.Count}}
  Thanks for being a regular!
{{else}}
  Welcome — we hope you'll order again soon.
{{/if}}
```

A few building blocks you can mix together:

- **Count** things: `Orders.Count`
- **Add up** a field: `Orders.Sum(Total)` (also average, smallest, largest)
- **Filter first**: `Orders.Where(Status = 'Paid').Count`
- **Tidy the output**: `… | currency`, `… | date('d/m/Y')`, `… | default('none')`

If a question can't be answered (or isn't allowed), it quietly comes back blank
rather than breaking the email.

---

## Step 5 — Mind the gap: guests vs account holders

Here's the catch the whole design hinges on.

When you ask `Orders.Count`, the system follows the anchor to the **account**, and
counts the orders **attached to that account**. That's fine for a logged-in
regular. But it quietly misses two groups:

1. **Guests.** No account means no anchor, so `Orders.Count` is zero — even if they
   ordered twenty times. The newsletter would treat a loyal guest as a stranger.
2. **"Mixed" customers.** Someone who ordered a few times as a guest *before* they
   registered. Those early orders may not have been attached to the account yet, so they don't get
   counted either.

The root cause is simple to state: **orders belong together by email address, but
the anchor counts them by account.** Those aren't the same thing.

So whenever the *true* answer depends on the person's identity (their email) rather
than whether they happen to have an account, don't rely on the anchor alone.

---

## Step 6 — Pre-compute the facts where you already know them

The fix is the nice part. The background audience task from Step 2 already walks
through **every order, grouped by email address** — so it's the one place that can
see a guest's full history. While it's there, we have it **tally the totals and
write them onto the subscriber** as a few plain facts ("merge data"):

```
for each email address (across ALL its orders, guest and account alike):
    yield {
        email:  "jane@example.com",
        anchor: account,            // still attached, if they have one
        mergeData: {
            ORDER_COUNT:    7,
            LIFETIME_SPEND: 320.00,
            LAST_ORDER:     "2026-06-20",
        },
    }
```

Now every subscriber — guest or account holder — carries their own honest totals.
These behave just like the built-in name fields, so you can use them anywhere:

```
You've ordered {{ ORDER_COUNT }} times and spent {{ LIFETIME_SPEND | currency }}.
```

Two things worth understanding about these pre-computed facts:

- They're a **snapshot**, refreshed each time the audience task runs — not a
  live-to-the-second figure. For newsletters that's exactly right.
- We **keep the anchor too**. The anchor is still the best tool for *live, detailed*
  questions about an account ("orders still marked unpaid", say). The pre-computed
  facts are for the identity-level totals the anchor can't see. They work together.

---

## Step 7 — Build targeted lists automatically (segments)

A **segment** is a self-maintaining sub-list defined by a simple true/false rule.
Anyone the rule is true for is included; everyone else isn't.

Because we did Step 6, you can write rules on the honest, email-wide totals — so
they catch guests *and* account holders:

```
ORDER_COUNT >= 5            our 5-times-or-more customers (guests included)
LIFETIME_SPEND >= 250       customers who've spent £250 or more
```

You'd only fall back to the anchor's live relation when you specifically mean
"orders tied to an account":

```
Orders.Count >= 5           account-linked orders only
```

Press **refresh** on a segment and it recomputes who belongs, on the spot. From
then on it's just an ordinary list you can send to.

---

## Step 8 — Keep everything in sync (the quiet part that matters most)

A subscription has an on/off state in two places: the **subscriber** (the real
mailing state) and the **customer account's preference** (what the person sees on
their account page). If those two ever disagree, you get the worst outcome —
someone receiving mail while their account says "unsubscribed," or vice versa.

So we keep them mirrored at **every** point where a preference can change. There are
three, and it's worth seeing all three together:

1. **They change it on their account page.** Updating the account preference flows
   straight to the subscriber — turning it on adds them, turning it off removes
   them.

2. **They click "unsubscribe" in an email.** The subscriber is switched off *and*
   that change is reflected **back onto their account**, so the account page tells
   the truth next time they look. (Guests have no account, so there's simply nothing
   to mirror — and they still get unsubscribed correctly.)

3. **They opt back in at checkout.** This is the easy one to forget. Ticking the
   "keep me updated" box switches the subscriber on — but we also reflect it **back
   onto the account**. Without that, someone who once unsubscribed and later
   re-opts-in at the till would be quietly receiving mail while their account still
   said "unsubscribed."

The rule that ties this together: **the customer account's preference is treated as
the authority** for people who have an account. That's only safe because we keep it
in step at all three points above. The practical takeaway for anyone extending this:

> If you ever add a *new* way for someone to opt in or out, make sure it updates
> **both** the subscriber and the account preference — never just one.

---

## Putting it all together

Here's the whole journey for two people, side by side:

**Jane, a guest** who has ordered 7 times and never registered:

- She has no account, so **no anchor**.
- The audience task counts all 7 of her orders by email and writes
  `ORDER_COUNT: 7` onto her subscriber.
- A newsletter can greet her with her name and her real order count.
- The `ORDER_COUNT >= 5` segment includes her — correctly.
- If she unsubscribes, she's switched off; there's no account to mirror to.

**Sam, an account holder** who ordered twice as a guest, then registered and
ordered 3 more times:

- His subscriber is **anchored** to his account.
- The anchor's live count would say `3` (only the account-linked orders) — but the
  audience task's `ORDER_COUNT` says `5`, counting his guest orders too.
- Segments on `ORDER_COUNT` see all 5; live anchor questions still work for
  account-specific detail.
- When he unsubscribes from an email, his account page updates to match; when he
  re-opts-in at checkout, it updates back.

---

## Setup checklist

1. **Pick the anchor** — what most subscribers point at (e.g. a customer account).
2. **Attach it** wherever people join the list (sign-up, checkout, the audience
   task).
3. **Grant read access** to the specific relations and fields a newsletter may use.
4. **Pre-compute identity-level facts** (totals keyed by email) in the audience task
   so guests and account holders are both covered.
5. **Write content** with `{{ … }}` questions, and **segments** with simple rules.
6. **Mirror every opt-in/opt-out** between the subscriber and the account
   preference — at all of them, not just some.
