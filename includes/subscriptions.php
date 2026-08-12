<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ASPEN_WALLET_SUBSCRIPTION_RENEWAL_LOG_META_KEY' ) ) {
	define( 'ASPEN_WALLET_SUBSCRIPTION_RENEWAL_LOG_META_KEY', '_aspen_wallet_processed_renewal_orders' );
}

if ( ! defined( 'ASPEN_WALLET_SUBSCRIPTION_LAST_CLEARED_STATUS_META_KEY' ) ) {
	define( 'ASPEN_WALLET_SUBSCRIPTION_LAST_CLEARED_STATUS_META_KEY', '_aspen_wallet_last_cleared_status' );
}

function aspen_wallet_register_subscription_hooks() {
	if ( ! function_exists( 'wcs_get_subscription' ) ) {
		return;
	}

	add_action( 'woocommerce_subscription_renewal_payment_complete', 'aspen_wallet_handle_subscription_renewal_success', 10, 2 );
	add_action( 'woocommerce_subscription_payment_complete', 'aspen_wallet_handle_subscription_renewal_success', 10, 2 );
	add_action( 'woocommerce_subscription_status_updated', 'aspen_wallet_handle_subscription_status', 10, 3 );
}

function aspen_wallet_handle_subscription_renewal_success( $subscription, $last_order = null ) {
	$subscription = aspen_wallet_maybe_get_subscription( $subscription );
	if ( ! $subscription instanceof WC_Subscription ) {
		return;
	}

	$user_id = (int) $subscription->get_user_id();
	if ( $user_id <= 0 ) {
		return;
	}

	$renewal_order_id = aspen_wallet_get_renewal_order_id( $last_order );
	if ( $renewal_order_id > 0 && aspen_wallet_subscription_was_renewal_processed( $subscription, $renewal_order_id ) ) {
		return;
	}

	$grants = aspen_wallet_get_subscription_reset_grants( $subscription );
	if ( empty( $grants ) ) {
		if ( $renewal_order_id > 0 ) {
			aspen_wallet_mark_subscription_renewal_processed( $subscription, $renewal_order_id );
		}
		return;
	}

	$grants = aspen_wallet_get_user_active_subscription_reset_grants( $user_id );

	foreach ( $grants as $bucket => $amount ) {
		wallet_set_balance( $user_id, $bucket, $amount );
	}

	if ( $renewal_order_id > 0 ) {
		aspen_wallet_mark_subscription_renewal_processed( $subscription, $renewal_order_id );
	}

	$subscription->delete_meta_data( ASPEN_WALLET_SUBSCRIPTION_LAST_CLEARED_STATUS_META_KEY );
	$subscription->save();
}

function aspen_wallet_handle_subscription_status( $subscription, $new_status, $old_status ) {
	$subscription = aspen_wallet_maybe_get_subscription( $subscription );
	if ( ! $subscription instanceof WC_Subscription ) {
		return;
	}

	$new_status = sanitize_key( (string) $new_status );
	$old_status = sanitize_key( (string) $old_status );

	$terminal_statuses = array( 'cancelled', 'expired', 'failed' );
	if ( ! in_array( $new_status, $terminal_statuses, true ) ) {
		return;
	}

	$last_cleared_status = sanitize_key( (string) $subscription->get_meta( ASPEN_WALLET_SUBSCRIPTION_LAST_CLEARED_STATUS_META_KEY, true ) );
	if ( $last_cleared_status === $new_status || $old_status === $new_status ) {
		return;
	}

	$user_id = (int) $subscription->get_user_id();
	if ( $user_id <= 0 ) {
		return;
	}

	$grants = aspen_wallet_get_subscription_reset_grants( $subscription );
	if ( empty( $grants ) ) {
		$subscription->update_meta_data( ASPEN_WALLET_SUBSCRIPTION_LAST_CLEARED_STATUS_META_KEY, $new_status );
		$subscription->save();
		return;
	}

	$active_grants = aspen_wallet_get_user_active_subscription_reset_grants( $user_id );

	foreach ( array_keys( $grants ) as $bucket ) {
		$amount = isset( $active_grants[ $bucket ] ) ? $active_grants[ $bucket ] : 0;
		wallet_set_balance( $user_id, $bucket, $amount );
	}

	$subscription->update_meta_data( ASPEN_WALLET_SUBSCRIPTION_LAST_CLEARED_STATUS_META_KEY, $new_status );
	$subscription->save();
}

function aspen_wallet_maybe_get_subscription( $subscription ) {
	if ( $subscription instanceof WC_Subscription ) {
		return $subscription;
	}

	$subscription_id = absint( $subscription );
	if ( $subscription_id <= 0 ) {
		return null;
	}

	$loaded = wcs_get_subscription( $subscription_id );
	return ( $loaded instanceof WC_Subscription ) ? $loaded : null;
}

function aspen_wallet_get_subscription_reset_grants( $subscription ) {
	$grants_by_bucket = array();

	foreach ( $subscription->get_items( 'line_item' ) as $item ) {
		$quantity    = absint( $item->get_quantity() );
		$item_grants = aspen_wallet_woo_get_resolved_item_grants( $item );
		if ( empty( $item_grants ) ) {
			continue;
		}

		foreach ( $item_grants as $grant ) {
			$type   = isset( $grant['type'] ) ? sanitize_key( $grant['type'] ) : '';
			$bucket = isset( $grant['bucket'] ) ? aspen_wallet_sanitize_bucket_slug( $grant['bucket'] ) : '';
			$amount = isset( $grant['amount'] ) ? absint( $grant['amount'] ) : 0;

			if ( 'subscription_reset' !== $type || '' === $bucket ) {
				continue;
			}

			if ( ! isset( $grants_by_bucket[ $bucket ] ) ) {
				$grants_by_bucket[ $bucket ] = 0;
			}

			$grants_by_bucket[ $bucket ] += $amount * $quantity;
		}
	}

	return $grants_by_bucket;
}

/**
 * Get the combined reset entitlement from all of a user's active subscriptions.
 *
 * Each subscription and each line item represents an independently purchased
 * entitlement, so grants for the same Fund are additive.
 *
 * @param int $user_id WordPress user ID.
 * @return array<string, int>
 */
function aspen_wallet_get_user_active_subscription_reset_grants( $user_id ) {
	$grants_by_bucket = array();
	$user_id          = absint( $user_id );

	if ( $user_id <= 0 || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
		return $grants_by_bucket;
	}

	foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
		if ( ! $subscription instanceof WC_Subscription || ! $subscription->has_status( 'active' ) ) {
			continue;
		}

		foreach ( aspen_wallet_get_subscription_reset_grants( $subscription ) as $bucket => $amount ) {
			if ( ! isset( $grants_by_bucket[ $bucket ] ) ) {
				$grants_by_bucket[ $bucket ] = 0;
			}

			$grants_by_bucket[ $bucket ] += $amount;
		}
	}

	return $grants_by_bucket;
}

function aspen_wallet_get_renewal_order_id( $last_order ) {
	if ( $last_order instanceof WC_Order ) {
		return absint( $last_order->get_id() );
	}

	return absint( $last_order );
}

function aspen_wallet_subscription_was_renewal_processed( $subscription, $renewal_order_id ) {
	$renewal_order_id = absint( $renewal_order_id );
	if ( $renewal_order_id <= 0 ) {
		return false;
	}

	$processed_ids = $subscription->get_meta( ASPEN_WALLET_SUBSCRIPTION_RENEWAL_LOG_META_KEY, true );
	$processed_ids = is_array( $processed_ids ) ? $processed_ids : array();
	$processed_ids = array_map( 'absint', $processed_ids );

	return in_array( $renewal_order_id, $processed_ids, true );
}

function aspen_wallet_mark_subscription_renewal_processed( $subscription, $renewal_order_id ) {
	$renewal_order_id = absint( $renewal_order_id );
	if ( $renewal_order_id <= 0 ) {
		return;
	}

	$processed_ids   = $subscription->get_meta( ASPEN_WALLET_SUBSCRIPTION_RENEWAL_LOG_META_KEY, true );
	$processed_ids   = is_array( $processed_ids ) ? $processed_ids : array();
	$processed_ids[] = $renewal_order_id;
	$processed_ids   = array_values( array_unique( array_map( 'absint', $processed_ids ) ) );

	$max_history = 30;
	if ( count( $processed_ids ) > $max_history ) {
		$processed_ids = array_slice( $processed_ids, 0 - $max_history );
	}

	$subscription->update_meta_data( ASPEN_WALLET_SUBSCRIPTION_RENEWAL_LOG_META_KEY, $processed_ids );
}
