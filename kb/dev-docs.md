# KitMage Wallet Developer Docs

## Purpose & Scope
KitMage Wallet gives each user a **Wallet containing Funds**, with each Fund holding an integer number of Credits. It applies and restricts those Credits across connected systems (`plugin.php` / `kitmage_wallet_bootstrap()`).

Supported integrations in current code paths:
- WooCommerce grants (`includes/woo.php` / `kitmage_wallet_register_woo_hooks()`).
- WooCommerce Subscriptions renewal/reset behavior (`includes/subscriptions.php` / `kitmage_wallet_register_subscription_hooks()`).
- Fluent Booking restrictions + debit flow (`includes/fluent-booking.php` / `kitmage_wallet_register_fluent_booking_hooks()`).

Runtime assumptions:
- Runs in WordPress and exits when `ABSPATH` is absent (guard in every module file).
- Requires minimum PHP 7.4 and WordPress 6.2 on activation (`plugin.php` / `kitmage_wallet_activate()`).
- Integration modules are dependency-gated and no-op if dependency symbols are missing (`class_exists( 'WooCommerce' )`, `function_exists( 'wcs_get_subscription' )`, `defined( 'FLUENT_BOOKING' )`).

## Bootstrap & Module Loading
`plugin.php` defines:
- `KITMAGE_WALLET_FILE`: absolute plugin entry file path.
- `KITMAGE_WALLET_PATH`: plugin directory path.
- `KITMAGE_WALLET_VERSION`: plugin version string.

Activation checks (`plugin.php` / `kitmage_wallet_activate()`):
1. Compare `PHP_VERSION` against `7.4`.
2. Compare `get_bloginfo( 'version' )` against `6.2`.
3. Deactivate + `wp_die()` with localized message on failure.

Bootstrap sequence (`plugin.php` / `kitmage_wallet_bootstrap()`):
1. `kitmage_wallet_load_modules()` requires all includes.
2. Registers hooks via:
   - `kitmage_wallet_register_admin_hooks()`
   - `kitmage_wallet_register_admin_wallet_users_hooks()`
   - `kitmage_wallet_register_woo_hooks()`
   - `kitmage_wallet_register_subscription_hooks()`
   - `kitmage_wallet_register_fluent_booking_hooks()`
   - `kitmage_wallet_register_shortcode_hooks()`

## Data Model
- Fund registry option key: `KITMAGE_WALLET_BUCKETS_OPTION_KEY` = `kitmage_wallet_buckets`, with legacy fallback to `wallet_buckets` (`includes/buckets.php` / `kitmage_wallet_get_buckets()`). Internal `bucket` identifiers are retained for backward compatibility (`includes/buckets.php` / `kitmage_wallet_get_buckets()`).
- User-meta balances: `_user_wallet_bucket_{slug}` via `kitmage_wallet_bucket_meta_key()`; slug hyphens are converted to underscores (`includes/balances.php`).
- Fluent Booking event meta keys (`includes/fluent-booking.php`):
  - `_kitmage_wallet_enabled`
  - `_kitmage_wallet_credit_cost`
  - `_kitmage_wallet_allowed_buckets`
- Subscription renewal log key: `KITMAGE_WALLET_SUBSCRIPTION_RENEWAL_LOG_META_KEY` = `_kitmage_wallet_processed_renewal_orders` (`includes/subscriptions.php`).

## Core Balance Engine (`includes/balances.php`)
Integer parsing/sanitization:
- `kitmage_wallet_to_int()`: rejects bool/array/object as `0`; unslashes + sanitizes strings; clamps to `>= 0`.
- `kitmage_wallet_parse_int_amount( $raw, $allow_negative )`: strict integer parser using `/^-?\d+$/`; rejects empty/floats/scientific notation/ambiguous values; returns `WP_Error` for invalid values or disallowed negatives.

Balance API contracts:
- `wallet_get_balance( $user_id, $bucket ) -> int`: returns `0` for invalid user/bucket.
- `wallet_set_balance( $user_id, $bucket, $amount ) -> bool`: writes parsed int (invalid parses become `0`). Emits `wallet_balance_updated` only when write succeeds and value changed.
- `wallet_add_balance( $user_id, $bucket, $amount ) -> bool`: no-op success for non-positive amounts; otherwise read + set.
- `wallet_can_afford( $user_id, $buckets, $amount ) -> bool`: checks cumulative balance over normalized bucket priority order.
- `kitmage_wallet_get_effective_wallet_user_id( $user_id ) -> int`: resolves the actual wallet owner for front-end wallet usage; with Teams for WooCommerce Memberships active, team members resolve to the selected team's owner, and users without a team resolve to themselves.
- `wallet_debit_balances( $user_id, $buckets, $amount ) -> array` with keys:
  - `success`
  - `requested_amount`
  - `debited_amount`
  - `remaining_amount`
  - `deltas` (bucket => negative delta)

Teams for WooCommerce Memberships compatibility:
- `kitmage_wallet_get_user_teams()` uses `wc_memberships_for_teams_get_teams()` when available and requests `owner`, `manager`, and `member` roles.
- Team owner resolution prefers team object getter methods, then falls back to the team post `post_author`.
- Users in multiple teams use the first team with a resolvable owner by default. Customize via `kitmage_wallet_effective_wallet_user_id`.

`wallet_balance_updated` action semantics:
- Fired from `wallet_set_balance()`.
- Signature: `(int $user_id, string $bucket, int $old, int $new, string $context)` where context is currently `'set_balance'`.

## Fund Management & Validation (`includes/buckets.php`, `includes/admin.php`)
- Slugs are normalized with `kitmage_wallet_sanitize_bucket_slug()` (`sanitize_title`) and must pass kebab-case regex `^[a-z0-9]+(?:-[a-z0-9]+)*$` in `kitmage_wallet_upsert_bucket()`.
- Upsert behavior (`kitmage_wallet_upsert_bucket()`):
  - If `original_slug` matches an existing row, updates that row (supports rename).
  - If target slug already exists on another row, returns `WP_Error( 'duplicate_slug' )`.
  - Otherwise appends a new Fund.
- Delete guard (`kitmage_wallet_delete_bucket()`):
  - Calls `kitmage_wallet_get_bucket_references()`.
  - Blocks deletion when a Fund is referenced by Woo grants (`product_grants`) or Fluent Booking event rules (`event_rules`).
- Admin notices/redirect encoding:
  - Redirect args `wallet_errors` / `wallet_success` are pipe-delimited for multiple messages.
  - Parsing uses `rawurldecode` + `explode('|', ...)` + `sanitize_text_field` in `kitmage_wallet_parse_notice_messages()`.

## Admin UX & Permissions Matrix
Capabilities:
- Fund config page: `manage_options` (`includes/admin.php`).
- Wallet user menu/pages: `edit_users` (`includes/admin-wallet-users.php`).
- Profile editing wallet section/save: `edit_user` per target user (`includes/admin-wallet-users.php`).

Nonces used:
- `kitmage_wallet_save_buckets`
- `kitmage_wallet_delete_bucket`
- `kitmage_wallet_save_user_balances`
- `kitmage_wallet_profile_wallet_save`

| Page / Form | Hook | Handler |
|---|---|---|
| Bucket save form | `admin_post_kitmage_wallet_save_buckets` | `kitmage_wallet_handle_save_buckets()` |
| Bucket delete form | `admin_post_kitmage_wallet_delete_bucket` | `kitmage_wallet_handle_delete_bucket()` |
| User balances page save form | `admin_post_kitmage_wallet_save_user_balances` | `kitmage_wallet_handle_save_user_balances()` |
| User profile wallet save (self) | `personal_options_update` | `kitmage_wallet_handle_profile_wallet_save()` |
| User profile wallet save (admin editing user) | `edit_user_profile_update` | `kitmage_wallet_handle_profile_wallet_save()` |

## WooCommerce Granting Model (`includes/woo.php`)
Per-product grant metadata:
- Stored in post meta key `KITMAGE_WALLET_PRODUCT_GRANTS_META_KEY` (`_kitmage_wallet_grants`).
- Written by `kitmage_wallet_woo_save_product_wallet_meta()` and `kitmage_wallet_woo_save_variation_wallet_meta()`.
- Read by `kitmage_wallet_woo_get_product_grants()`.

Grant types + resolution:
- Grant types from `kitmage_wallet_get_grant_types()`:
  - `one_time_grant`
  - `subscription_reset`
- Line-item resolution (`kitmage_wallet_woo_get_resolved_item_grants()`): prefer variation grants; fallback to parent product grants.
- Subscription reset grants are surfaced for reuse by subscriptions via `kitmage_wallet_get_subscription_reset_grants()` (`includes/subscriptions.php`).

Apply timing + idempotency:
- One-time grants apply on `woocommerce_order_status_completed` and `woocommerce_payment_complete`.
- Each one-time grant amount is multiplied by its order line-item quantity before being added to the wallet.
- `kitmage_wallet_woo_apply_order_one_time_grants()` exits early if order meta `_kitmage_wallet_one_time_grants_applied` is `yes`.
- After processing, stores applied marker/details and adds order note.

## Subscriptions Renewal Flow (`includes/subscriptions.php`)
Renewal detection + order ID extraction:
- Hooks: `woocommerce_subscription_renewal_payment_complete` and `woocommerce_subscription_payment_complete`.
- Subscription normalization by `kitmage_wallet_maybe_get_subscription()`.
- Renewal order extraction by `kitmage_wallet_get_renewal_order_id( $last_order )`.

Processed renewal tracking:
- Duplicate detection via `kitmage_wallet_subscription_was_renewal_processed()` against `_kitmage_wallet_processed_renewal_orders`.
- Marking via `kitmage_wallet_mark_subscription_renewal_processed()`.
- Max history retained: 30 order IDs.

Reset grants aggregation + apply timing:
- Aggregation by Fund in `kitmage_wallet_get_subscription_reset_grants()` sums each grant multiplied by its line-item quantity.
- `kitmage_wallet_get_user_active_subscription_reset_grants()` sums those entitlements across all active subscriptions owned by the user.
- Renewal and terminal-status handlers set affected Fund balances to the user's combined active entitlement, so cancelling one subscription preserves grants from the others.

Sequence:
1. Renewal event fires.
2. Resolve subscription + renewal order ID.
3. Aggregate `subscription_reset` grants by Fund.
4. Check duplicate renewal ID.
5. Apply Fund resets.
6. Mark renewal as processed.

## Fluent Booking Restriction Flow (`includes/fluent-booking.php`)
Event settings UI + storage:
- UI fields rendered in `kitmage_wallet_render_fluent_booking_event_wallet_settings()`.
- Saved in `kitmage_wallet_save_fluent_booking_event_wallet_settings()` with nonce `kitmage_wallet_save_fluent_booking_event_settings`.
- Stores enabled, cost, and allowed Funds in event post meta keys (whose internal names retain `buckets` for compatibility).

Affordability checks + fallback behavior:
- Central check: `kitmage_wallet_fluent_booking_affordability()`.
- Affordability resolves the booking user through `kitmage_wallet_get_effective_wallet_user_id()` before checking balances, so team members spend from the team owner's wallet.
- For calendar HTML (`fluent_booking_event_calendar_html`) and `[wallet_booking]`, blocked users see fallback shortcode/content (or reason message).

Pre-create + post-create paths:
- Pre-create validation filter `fluent_booking_before_create_booking` returns `WP_Error( 'wallet_insufficient_credits', ... )` when blocked.
- Post-create action `fluent_booking_booking_created` debits via `wallet_debit_balances()` against the effective wallet user ID.

Failure action payload:
- On debit failure, emits `kitmage_wallet_booking_debit_failed` with:
  - `booking_id` (int)
  - `event_id` (int)
  - `user_id` (int; booking user for backward compatibility)
  - `wallet_user_id` (int; debited wallet owner)
  - `reason` (string)

## Shortcodes & Front-End Behavior (`includes/shortcodes.php`)
Registered shortcodes (`kitmage_wallet_register_shortcode_hooks()`):
- `[wallet_balance fund="" divide_by="1" decimals="0" suffix=""]`
  - Reads the Credits in the effective Wallet user's Fund; returns an escaped string (raw int or formatted). The deprecated `bucket` attribute remains an alias for backward compatibility.
- `[wallet_if fund="" min="" max="" equals="" fallback=""]...[/wallet_if]`
  - Evaluates integer conditions on the effective wallet user's balance; renders enclosed content or fallback.
- `[wallet_booking calendar_id="0" event_id="0" fallback=""]`
  - Runs wallet affordability check before rendering `[fluent_booking id="{event_id}"]`; filterable via `kitmage_wallet_booking_shortcode_output`.

Restriction impact:
- Booking/calendar output can be replaced by fallback in:
  - `kitmage_wallet_filter_fluent_booking_calendar_html()`
  - `kitmage_wallet_maybe_block_booking_shortcode_output()`
  - `kitmage_wallet_shortcode_booking()` pre-check.

## Hooks Reference
### Actions emitted
| Action | Arguments | Source | Purpose |
|---|---|---|---|
| `wallet_balance_updated` | `$user_id, $bucket, $old, $new, 'set_balance'` | `wallet_set_balance()` | Notify on successful changed balance writes. |
| `kitmage_wallet_booking_debit_failed` | `array{booking_id,event_id,user_id,wallet_user_id,reason}` | `kitmage_wallet_debit_after_fluent_booking_created()` | Emit when post-booking debit fails. |

### Filters consumed/applied
| Filter | Arguments | Source | Purpose |
|---|---|---|---|
| `fluent_booking_event_calendar_html` | `$html, $event, $context` | `kitmage_wallet_filter_fluent_booking_calendar_html()` | Replace calendar output when user cannot afford booking. |
| `fluent_booking_before_create_booking` | `$validation, $event, $booking_data` | `kitmage_wallet_validate_fluent_booking_before_create()` | Block booking creation when credits insufficient. |
| `kitmage_wallet_booking_shortcode_output` | `$output, $event_id, $fallback, $user_id` | `kitmage_wallet_shortcode_booking()` + `kitmage_wallet_maybe_block_booking_shortcode_output()` | Post-process booking shortcode output and enforce wallet fallback. |
| `kitmage_wallet_effective_wallet_user_id` | `$wallet_user_id, $user_id, $selected_team, $teams` | `kitmage_wallet_get_effective_wallet_user_id()` | Override which user wallet backs a logged-in user, especially for multi-team sites. |

## Operational Runbook
- **Add a new Fund safely**
  1. Create via Wallet admin form (`includes/admin.php` / `kitmage_wallet_handle_save_buckets()`).
  2. Confirm slug passes kebab-case validation (`includes/buckets.php` / `kitmage_wallet_upsert_bucket()`).
  3. Confirm the Fund appears in Woo grant selectors and Fluent Booking allowed Funds lists (`includes/woo.php`, `includes/fluent-booking.php`).

- **Migrate/rename Fund slugs**
  1. Use edit flow with `original_slug` (`includes/admin.php` form + handler).
  2. Understand balance meta key changes with slug (`includes/balances.php` / `kitmage_wallet_bucket_meta_key()`).
  3. Check references using `kitmage_wallet_get_bucket_references()` before deleting old slug entry.

- **Investigate mismatched balances**
  1. Trace reads/writes through `wallet_get_balance()`, `wallet_set_balance()`, `wallet_add_balance()`, `wallet_debit_balances()`.
  2. Inspect order grant markers `_kitmage_wallet_one_time_grants_applied` and `_kitmage_wallet_one_time_grants_applied_details` (`includes/woo.php`).
  3. Inspect subscription renewal log `_kitmage_wallet_processed_renewal_orders` (`includes/subscriptions.php`).

- **Diagnose booking debit failures**
  1. Re-check event settings via `kitmage_wallet_get_fluent_booking_event_wallet_settings()`.
  2. Reproduce affordability outcome via `kitmage_wallet_fluent_booking_affordability()`.
  3. Inspect `wallet_debit_balances()` result contract.
  4. Hook/log `kitmage_wallet_booking_debit_failed` payload from `kitmage_wallet_debit_after_fluent_booking_created()`.

## Risk & Edge Cases
- Integer-only credit model: all core parse/write paths normalize to integers (no decimal support).
- Non-negative enforcement: `kitmage_wallet_to_int()` clamps to `>=0`; `wallet_set_balance()` defaults invalid input to `0`; profile save clamps negatives to `0`.
- Concurrency caveat: debit/add flows are read-modify-write over user meta and are not transactional (`wallet_add_balance()`, `wallet_debit_balances()`, `wallet_set_balance()`).
- Dependency-off behavior: Woo/Subscriptions/Fluent Booking hooks are not registered unless dependency checks pass.

## Contributor Checklist
- Follow existing sanitization/escaping patterns (`sanitize_text_field`, `sanitize_textarea_field`, `sanitize_key`, `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, `wp_unslash`).
- Every new admin form must enforce capability checks + nonce validation (mirror patterns in `includes/admin.php` and `includes/admin-wallet-users.php`).
- Preserve backward compatibility for option/meta keys (notably buckets option fallback and existing order/subscription/event meta keys).
- Update this doc when adding/changing hooks, meta keys, or Credit/Fund semantics.
