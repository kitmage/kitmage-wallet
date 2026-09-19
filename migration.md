# Aspen Wallet to KitMage Wallet database migration

KitMage Wallet uses the `kitmage_wallet_*` option, metadata, and payment-setting
keys. Run this migration once when upgrading an existing Aspen Wallet
installation so its existing configuration, grants, and processing history are
available under the new names.

## Before you begin

1. Back up the WordPress database.
2. Put the site in maintenance mode and stop background jobs (including Action
   Scheduler) so wallet data cannot change during the migration.
3. Replace every `wp_` table prefix below with the site's actual WordPress table
   prefix. On multisite, run the site-table statements for every relevant site.
4. Run the statements in the order shown. They are safe to run more than once:
   rows already using a KitMage key are left unchanged, and an old row is only
   renamed when the matching new key does not exist for the same object.

## Core WordPress tables

```sql
START TRANSACTION;

-- Fund registry option.
UPDATE wp_options AS old_row
LEFT JOIN wp_options AS new_row
  ON new_row.option_name = 'kitmage_wallet_buckets'
SET old_row.option_name = 'kitmage_wallet_buckets'
WHERE old_row.option_name = 'aspen_wallet_buckets'
  AND new_row.option_id IS NULL;

-- If both names already exist, retain the KitMage value and remove the stale
-- Aspen duplicate.
DELETE FROM wp_options
WHERE option_name = 'aspen_wallet_buckets';

-- Product grants, order processing markers, subscription state, and Fluent
-- Booking event settings. The left join avoids creating duplicate new keys.
UPDATE wp_postmeta AS old_row
JOIN (
  SELECT '_aspen_wallet_grants' AS old_key, '_kitmage_wallet_grants' AS new_key
  UNION ALL SELECT '_aspen_wallet_one_time_grants_applied', '_kitmage_wallet_one_time_grants_applied'
  UNION ALL SELECT '_aspen_wallet_one_time_grants_applied_details', '_kitmage_wallet_one_time_grants_applied_details'
  UNION ALL SELECT '_aspen_wallet_processed_renewal_orders', '_kitmage_wallet_processed_renewal_orders'
  UNION ALL SELECT '_aspen_wallet_last_cleared_status', '_kitmage_wallet_last_cleared_status'
  UNION ALL SELECT '_aspen_wallet_enabled', '_kitmage_wallet_enabled'
  UNION ALL SELECT '_aspen_wallet_credit_cost', '_kitmage_wallet_credit_cost'
  UNION ALL SELECT '_aspen_wallet_allowed_buckets', '_kitmage_wallet_allowed_buckets'
  UNION ALL SELECT '_aspen_wallet_use_credits_in_payment_settings', '_kitmage_wallet_use_credits_in_payment_settings'
) AS key_map ON key_map.old_key = old_row.meta_key
LEFT JOIN wp_postmeta AS new_row
  ON new_row.post_id = old_row.post_id
 AND new_row.meta_key = key_map.new_key
SET old_row.meta_key = key_map.new_key
WHERE new_row.meta_id IS NULL;

-- Remove old-key rows left behind only because the object already had the
-- corresponding KitMage key.
DELETE old_row
FROM wp_postmeta AS old_row
JOIN (
  SELECT '_aspen_wallet_grants' AS old_key, '_kitmage_wallet_grants' AS new_key
  UNION ALL SELECT '_aspen_wallet_one_time_grants_applied', '_kitmage_wallet_one_time_grants_applied'
  UNION ALL SELECT '_aspen_wallet_one_time_grants_applied_details', '_kitmage_wallet_one_time_grants_applied_details'
  UNION ALL SELECT '_aspen_wallet_processed_renewal_orders', '_kitmage_wallet_processed_renewal_orders'
  UNION ALL SELECT '_aspen_wallet_last_cleared_status', '_kitmage_wallet_last_cleared_status'
  UNION ALL SELECT '_aspen_wallet_enabled', '_kitmage_wallet_enabled'
  UNION ALL SELECT '_aspen_wallet_credit_cost', '_kitmage_wallet_credit_cost'
  UNION ALL SELECT '_aspen_wallet_allowed_buckets', '_kitmage_wallet_allowed_buckets'
  UNION ALL SELECT '_aspen_wallet_use_credits_in_payment_settings', '_kitmage_wallet_use_credits_in_payment_settings'
) AS key_map ON key_map.old_key = old_row.meta_key
JOIN wp_postmeta AS new_row
  ON new_row.post_id = old_row.post_id
 AND new_row.meta_key = key_map.new_key;

-- Fluent Booking stores one branded key inside a serialized payment_settings
-- value. Updating both the key and PHP string length keeps serialization valid.
UPDATE wp_postmeta
SET meta_value = REPLACE(
  meta_value,
  's:20:"aspen_wallet_enabled"',
  's:22:"kitmage_wallet_enabled"'
)
WHERE meta_key = 'payment_settings'
  AND meta_value LIKE '%s:20:"aspen_wallet_enabled"%';

COMMIT;
```

User balances do **not** need to be renamed: their historical
`_user_wallet_bucket_{fund}` keys are intentionally brand-neutral and remain in
use.

## WooCommerce HPOS (if enabled)

WooCommerce High-Performance Order Storage keeps order and subscription metadata
in `wp_wc_orders_meta`. Run this block only when that table exists:

```sql
START TRANSACTION;

UPDATE wp_wc_orders_meta AS old_row
JOIN (
  SELECT '_aspen_wallet_one_time_grants_applied' AS old_key, '_kitmage_wallet_one_time_grants_applied' AS new_key
  UNION ALL SELECT '_aspen_wallet_one_time_grants_applied_details', '_kitmage_wallet_one_time_grants_applied_details'
  UNION ALL SELECT '_aspen_wallet_processed_renewal_orders', '_kitmage_wallet_processed_renewal_orders'
  UNION ALL SELECT '_aspen_wallet_last_cleared_status', '_kitmage_wallet_last_cleared_status'
) AS key_map ON key_map.old_key = old_row.meta_key
LEFT JOIN wp_wc_orders_meta AS new_row
  ON new_row.order_id = old_row.order_id
 AND new_row.meta_key = key_map.new_key
SET old_row.meta_key = key_map.new_key
WHERE new_row.id IS NULL;

DELETE old_row
FROM wp_wc_orders_meta AS old_row
JOIN (
  SELECT '_aspen_wallet_one_time_grants_applied' AS old_key, '_kitmage_wallet_one_time_grants_applied' AS new_key
  UNION ALL SELECT '_aspen_wallet_one_time_grants_applied_details', '_kitmage_wallet_one_time_grants_applied_details'
  UNION ALL SELECT '_aspen_wallet_processed_renewal_orders', '_kitmage_wallet_processed_renewal_orders'
  UNION ALL SELECT '_aspen_wallet_last_cleared_status', '_kitmage_wallet_last_cleared_status'
) AS key_map ON key_map.old_key = old_row.meta_key
JOIN wp_wc_orders_meta AS new_row
  ON new_row.order_id = old_row.order_id
 AND new_row.meta_key = key_map.new_key;

COMMIT;
```

## Verification

All counts returned by these queries should be zero after migrating every
applicable table:

```sql
SELECT COUNT(*) AS old_options
FROM wp_options
WHERE option_name LIKE 'aspen_wallet%';

SELECT COUNT(*) AS old_postmeta
FROM wp_postmeta
WHERE meta_key LIKE '_aspen_wallet%'
   OR (meta_key = 'payment_settings' AND meta_value LIKE '%aspen_wallet%');

-- Run only when HPOS is enabled and the table exists.
SELECT COUNT(*) AS old_hpos_meta
FROM wp_wc_orders_meta
WHERE meta_key LIKE '_aspen_wallet%';
```

After verification, clear the WordPress object cache and reactivate **KitMage
Wallet**. The SQL intentionally does not rename WordPress hooks, shortcodes, or
PHP symbols; those are application code rather than database records.
