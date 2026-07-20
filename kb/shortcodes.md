# Aspen Wallet Shortcodes Guide

This knowledge base article explains every Aspen Wallet shortcode, all available attributes, and practical usage patterns.

---

## Quick reference

### `[wallet_balance]`
Display the current user’s balance for a specific wallet bucket.

### `[wallet_if]...[/wallet_if]`
Conditionally render content based on the current user’s bucket balance.

### `[wallet_booking]`
Render a Fluent Booking shortcode only when the current user can afford the configured wallet cost for the event.

---

## Important behavior to understand first

- All Aspen Wallet balances are stored as **integers**.
- Shortcodes evaluate against the **currently logged-in user**.
- If a bucket is missing or invalid, most shortcodes return empty output (or fallback output, if provided).
- Fallback attributes are sanitized, and nested shortcodes are allowed inside fallback content.

---

## 1) `[wallet_balance]`

### Purpose
Use this shortcode anywhere you want to show a user how many credits they currently have in a bucket.

### Syntax

```text
[wallet_balance bucket="" divide_by="1" decimals="0" suffix=""]
```

### Attributes

- `bucket` (required)
  - The bucket slug to read from (example: `general-credits`, `coaching`, `premium`).
  - If empty or invalid, output is blank.

- `divide_by` (optional, default: `1`)
  - Lets you display a transformed value while preserving integer storage.
  - If `divide_by` is `1` or less, raw integer balance is shown.
  - If greater than `1`, output is formatted with `wallet_format_balance()`.

- `decimals` (optional, default: `0`)
  - Number of decimals used when `divide_by` is greater than `1`.
  - Clamped to a maximum of `6`.

- `suffix` (optional)
  - Text appended after the number (for example: `credits`, `hrs`, `sessions`).

### Example usage

#### Basic

```text
[wallet_balance bucket="general-credits"]
```

#### Human-friendly units
If 100 stored credits = 1 hour:

```text
[wallet_balance bucket="coaching" divide_by="100" decimals="2" suffix="hours"]
```

#### Whole-number units with label

```text
[wallet_balance bucket="sessions" suffix="sessions remaining"]
```

### When to use
- Account/dashboard pages
- Sidebar membership summaries
- Booking intro sections before call-to-action buttons

---

## 2) `[wallet_if]...[/wallet_if]`

### Purpose
Conditionally show content based on one or more balance checks.

### Syntax

```text
[wallet_if bucket="" min="" max="" equals="" fallback=""]
  ...content shown when conditions match...
[/wallet_if]
```

### Attributes

- `bucket` (required)
  - Bucket slug to evaluate.

- `min` (optional)
  - Requires user balance to be **greater than or equal to** this value.

- `max` (optional)
  - Requires user balance to be **less than or equal to** this value.

- `equals` (optional)
  - Requires user balance to be exactly this value.

- `fallback` (optional)
  - Content to show when conditions fail (or bucket is invalid).
  - Nested shortcodes inside fallback are supported.

### Rule logic

- At least one of `min`, `max`, or `equals` must be provided.
- If multiple rules are provided, **all** must pass.
- If all pass: shortcode body content renders.
- If any fail: `fallback` renders (or blank if no fallback).

### Example usage

#### Show upgrade message when user has no credits

```text
[wallet_if bucket="general-credits" equals="0" fallback=""]
<p>You have no credits left. <a href="/plans">Buy more credits</a>.</p>
[/wallet_if]
```

#### Show booking CTA only if balance is sufficient

```text
[wallet_if bucket="coaching" min="1" fallback="<p>You need at least 1 coaching credit.</p>"]
<a class="button" href="#book-now">Book a coaching call</a>
[/wallet_if]
```

#### Show a tier-specific notice

```text
[wallet_if bucket="priority" min="5" max="10" fallback="<p>Priority benefits unlock at 5+ credits.</p>"]
<p>You are in the Priority access band.</p>
[/wallet_if]
```

### When to use
- Gating page sections by entitlement
- Personalized CTAs
- Inline upgrade prompts

---

## 3) `[wallet_booking]`

### Purpose
Safely embed booking UI that is automatically hidden/replaced when the user cannot afford the booking’s wallet credit cost.

### Syntax

```text
[wallet_booking calendar_id="0" event_id="0" fallback=""]
```

### Attributes

- `calendar_id` (required)
  - Fluent Booking calendar ID.
  - Must be a positive integer.

- `event_id` (required)
  - Fluent Booking event ID.
  - Must be a positive integer.

- `fallback` (optional)
  - Content to render when booking is blocked by wallet rules.
  - If omitted, Aspen Wallet may use a system-generated reason message.

### How it works

1. Validates `calendar_id` and `event_id`.
2. Runs wallet affordability check for current user and target event.
3. If blocked:
   - renders `fallback` (if provided), otherwise reason text (if available).
4. If allowed:
   - renders this Fluent Booking shortcode:

```text
[fluent_booking id="{event_id}"]
```

Fluent Booking identifies the event with its `id` attribute. The `calendar_id`
attribute on `[wallet_booking]` is validated by Aspen Wallet but is not forwarded
to Fluent Booking.

### Example usage

```text
[wallet_booking calendar_id="3" event_id="17" fallback="<p>You do not have enough credits for this booking. <a href='/credits'>Get credits</a>.</p>"]
```

### When to use
- Event landing pages
- Member-only booking funnels
- Sales pages where you want explicit fallback messaging

---

## Combining shortcodes for better UX

### Pattern: show balance + condition + booking block

```text
<p>Your coaching balance: [wallet_balance bucket="coaching" suffix="credits"]</p>

[wallet_if bucket="coaching" min="1" fallback="<p>You need at least 1 credit to book this event.</p>"]
  [wallet_booking calendar_id="3" event_id="17" fallback="<p>Booking is currently unavailable for your wallet balance.</p>"]
[/wallet_if]
```

This gives users clear state feedback before they attempt booking.

---

## Troubleshooting checklist

If shortcode output is blank or unexpected:

1. Confirm the bucket slug exists and is spelled correctly.
2. Confirm the user is logged in (these shortcodes read current user context).
3. Confirm attribute values are numeric where required.
4. Confirm `calendar_id` and `event_id` are valid Fluent Booking IDs.
5. Add temporary fallback text to identify whether condition checks are failing.

---

## Best practices

- Keep bucket slugs stable once used in published content.
- Prefer explicit `fallback` messages for clearer UX.
- Use `[wallet_if]` around expensive UI blocks to avoid showing inaccessible actions.
- Use `[wallet_balance]` near booking CTAs to reduce confusion.
