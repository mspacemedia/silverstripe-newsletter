# Expression language reference

The complete syntax understood by the `{{ … }}` merge-field engine
(`MSpaceMedia\Newsletter\Service\MergeFieldService` + `…\MergeExpression\Parser` /
`Evaluator`). Used by **computed merge fields**, inline `{{ … }}` tags in block content, and
**segment** expressions. For the "why" and worked examples see the README's
[Computed merge fields](../README.md#computed-merge-fields) and
[Audience segments](../README.md#audience-segments) sections.

Everything is **default-deny**: relations/fields are only reachable if named in the
`MergeFieldService.allowlist` (see README). Built-ins and a subscriber's own MergeData need no
allowlisting.

---

## Where expressions appear

| Form | Meaning |
| --- | --- |
| `{{ expression }}` | Output the value of `expression`. |
| `{{#if expression}}…{{else}}…{{/if}}` | Show a block of content conditionally. `{{else}}` is optional; blocks nest. |
| Segment expression (Segment tab) | A boolean expression; truthy ⇒ the subscriber is included. |

Markup the editor injects inside a marker (`<span>`, `&nbsp;`, `&gt;`) is stripped/decoded
automatically, so a tag typed in TinyMCE still parses.

---

## Values (literals)

| Literal | Examples |
| --- | --- |
| Number | `5`, `42`, `3.14`, `0.5` |
| String | `'Paid'`, `"hello"` (single or double quotes; escapes `\'` `\"` `\\` `\n` `\t`) |
| Negative | `-5`, `-Orders.Count` |

## Resolving a bare name

A bare identifier (e.g. `FirstName`, `ORDERCOUNT`) is resolved **in this order**:

1. a **defined merge field** whose Tag matches (case-insensitive; `First Name` → `FIRSTNAME`);
2. a **built-in** (below), including the subscriber's MergeData keys;
3. a **field or relation on the anchor** record.

If a defined field yields nothing (null/empty) or errors, resolution falls through to the
built-in/anchor — a misfiring computed field never blanks a real built-in.

### Built-in tags

Case-insensitive. Names fall back to the anchor record when the subscriber's own field is blank.

| Tag(s) | Value |
| --- | --- |
| `FirstName`, `FName` | Subscriber first name |
| `Surname`, `LastName`, `LName` | Subscriber surname |
| `Email` | Subscriber email |
| `Name` | `FirstName` + `Surname` (falls back to the display name) |
| *any MergeData key* | e.g. `ORDERCOUNT`, `LIFETIMEDONATION` — whatever the source provider stored |

> In the **segment/merge-field builder preview**, the sample is a real subscriber, so MergeData
> tags resolve. Anchor expressions only resolve for subscribers linked to a record.

## Paths — traversing relations

Starting from the anchor record:

| Step | Example | Result |
| --- | --- | --- |
| Field | `Member.Email` | a value (terminal) |
| Relation | `Orders` | a list (has_many / many_many) or a record (has_one) |
| `.Count` | `Orders.Count` | number of related records |
| `.Sum(field)` | `Orders.Sum(Amount)` | sum of a field |
| `.Avg(field)` / `.Min(field)` / `.Max(field)` | `Orders.Avg(Amount)` | aggregate of a field |
| `.First` | `Orders.First.Reference` | first record; chain a field after it |
| `.Where(field op value)` | `Orders.Where(Status = 'Paid').Count` | filter a list, then aggregate |

`Where` operators: `=`, `==`, `!=`, `>`, `<`, `>=`, `<=`. The value is a number or quoted string.
`Where` is chainable: `Orders.Where(Status = 'Paid').Where(Amount > 0).Count`.

An expression must end in a **value** — a path that stops on a record or list (e.g. bare `Orders`)
is an error. Add a field or aggregate.

## Operators

| Kind | Operators |
| --- | --- |
| Arithmetic | `+` `-` `*` `/`, parentheses `( )`, unary `-` |
| Comparison (→ boolean) | `=` / `==`, `!=`, `>`, `<`, `>=`, `<=` |
| Logical (→ boolean) | `&&` (and), `\|\|` (or) — `&&` binds tighter than `\|\|`; use `( )` to group |

Division by zero, or arithmetic involving a missing value, yields nothing (null). Logical operators
short-circuit (the right side is only evaluated if needed), so put cheaper / more selective tests
first in a segment.

## Functions

| Function | Meaning |
| --- | --- |
| `Concat(a, b, …)` | Join the parts as text |
| `Select(cond, a, b)` | `a` if `cond` is truthy, else `b` (inline picker; **`If` is a legacy alias**) |
| `Coalesce(a, b, …)` | First argument that isn't null/empty |
| `Round(x, places = 0)` | Round a number |
| `Upper(s)` / `Lower(s)` | Change case |

## Filters (pipe)

Applied after a value with `|`, left to right: `expr | filter | filter(arg)`.

| Filter | Meaning |
| --- | --- |
| `currency` / `currency(decimals)` | Prepend the configured symbol, format (default 2 dp) |
| `number(decimals = 0)` | Thousands-separated number |
| `round(places = 0)` | Round |
| `default(value)` | Use `value` when the input is empty/null |
| `upper` / `lower` | Change case |
| `date(format = 'd/m/Y')` | Format a date/timestamp with a PHP date format |

The currency symbol is `MergeFieldService.currency_symbol` (default `£`).

## Conditionals

```
{{#if expression}} shown when truthy {{else}} shown otherwise {{/if}}
```

- `{{else}}` is optional; blocks **nest**.
- **Truthiness:** `null`, `false`, `0`, empty string and the string `'0'` are false; everything
  else is true. So `{{#if Orders.Count}}…{{/if}}` shows when there is at least one order.
- For an inline choice between two *values* (not blocks), prefer `Select(cond, a, b)`.

## Behaviour & limits

- **Resolves per recipient** at send time (and in the builder preview against a sample subscriber).
  Tags stay literal in the locked sent snapshot and personalise per delivery.
- **Errors render empty.** An unknown tag, a disallowed field, or a malformed expression produces
  an empty string in delivered HTML rather than leaking an error.
- **Security:** aggregates and `Where()` run as parameterised ORM queries — no `eval`, no raw SQL —
  and every relation/field is re-checked against the allowlist at evaluation time.
- **Defined fields** may reference other defined fields; direct or indirect cycles resolve to empty.

## Examples

```text
Hi {{ FirstName }},
You placed {{ ORDERCOUNT }} {{ Select(ORDERCOUNT >= 2, 'orders', 'order') }} with us.
Lifetime giving: {{ LIFETIMEDONATION | currency }}
Average order: {{ Orders.Sum(Amount) / Orders.Count | currency }}
Paid orders: {{ Orders.Where(Status = 'Paid').Count }}
Last order: {{ LASTORDER | date('j M Y') }}

{{#if Orders.Count}}Thanks for your {{ Orders.Count }} orders!{{else}}Come and say hello.{{/if}}
```

Segment expressions (Segment tab):

```text
LIFETIMEDONATION >= 100
ORDERCOUNT >= 5
LIFETIMEDONATION > 5 && ORDERCOUNT >= 2
Orders.Where(Status = 'Paid').Count > 0 || LIFETIMESPEND >= 500
```
