<?php
/**
 * Post-purchase directions for WooCommerce orders that contain Wallet Credits.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'KITMAGE_WALLET_ORDER_ITEM_GRANTS_META_KEY' ) ) {
	define( 'KITMAGE_WALLET_ORDER_ITEM_GRANTS_META_KEY', '_kitmage_wallet_order_item_grants' );
}

/**
 * Keep purchase-time grant information separate from later product edits.
 *
 * This metadata is used only for customer guidance; it does not alter the
 * existing grant and subscription-processing logic.
 */
function kitmage_wallet_snapshot_order_item_grants( $item, $cart_item_key, $values, $order ) {
	if ( ! $item instanceof WC_Order_Item_Product ) {
		return;
	}

	$item->add_meta_data( KITMAGE_WALLET_ORDER_ITEM_GRANTS_META_KEY, kitmage_wallet_woo_get_resolved_item_grants( $item ), true );
}

function kitmage_wallet_register_order_guidance_hooks() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	add_action( 'woocommerce_checkout_create_order_line_item', 'kitmage_wallet_snapshot_order_item_grants', 10, 4 );
	add_action( 'woocommerce_before_thankyou', 'kitmage_wallet_order_guidance_confirmation', 5 );
	// Block checkout can fire the thankyou hook separately from classic checkout.
	add_action( 'woocommerce_thankyou', 'kitmage_wallet_order_guidance_confirmation', 5 );
	add_action( 'woocommerce_view_order', 'kitmage_wallet_order_guidance_view_order', 5 );
}

/**
 * Validate stored grant snapshots independently of current product settings.
 */
function kitmage_wallet_order_guidance_normalize_grants( $raw ) {
	$grants = array();
	if ( ! is_array( $raw ) ) {
		return $grants;
	}

	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$fund   = isset( $row['bucket'] ) ? kitmage_wallet_sanitize_bucket_slug( $row['bucket'] ) : '';
		$amount = isset( $row['amount'] ) ? absint( $row['amount'] ) : 0;
		$type   = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : '';

		if ( '' === $fund || 0 === $amount || ! in_array( $type, array( 'one_time_grant', 'subscription_reset' ), true ) ) {
			continue;
		}

		$grants[] = array( 'bucket' => $fund, 'amount' => $amount, 'type' => $type );
	}

	return $grants;
}

function kitmage_wallet_order_guidance_item_grants( $item ) {
	if ( ! $item instanceof WC_Order_Item_Product ) {
		return array();
	}

	$snapshot = $item->get_meta( KITMAGE_WALLET_ORDER_ITEM_GRANTS_META_KEY, true );
	if ( is_array( $snapshot ) ) {
		return kitmage_wallet_order_guidance_normalize_grants( $snapshot );
	}

	// Legacy orders predate the purchase-time snapshot.
	return kitmage_wallet_order_guidance_normalize_grants( kitmage_wallet_woo_get_resolved_item_grants( $item ) );
}

/**
 * Return one entry per Fund, even if multiple products grant the same Fund.
 * Applied details also allow historical orders to display guidance when a
 * product's Wallet configuration was subsequently removed.
 */
function kitmage_wallet_order_guidance_funds( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return array();
	}

	$funds = array();

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product || absint( $item->get_quantity() ) <= 0 ) {
			continue;
		}

		foreach ( kitmage_wallet_order_guidance_item_grants( $item ) as $grant ) {
			$slug = $grant['bucket'];
			if ( ! isset( $funds[ $slug ] ) ) {
				$funds[ $slug ] = array( 'one_time' => false, 'subscription' => false, 'applied' => false );
			}

			if ( 'one_time_grant' === $grant['type'] ) {
				$funds[ $slug ]['one_time'] = true;
			} elseif ( 'subscription_reset' === $grant['type'] ) {
				$funds[ $slug ]['subscription'] = true;
			}
		}
	}

	$applied = $order->get_meta( KITMAGE_WALLET_ORDER_APPLIED_DETAILS_META_KEY, true );
	if ( is_array( $applied ) ) {
		foreach ( $applied as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug   = isset( $row['bucket'] ) ? kitmage_wallet_sanitize_bucket_slug( $row['bucket'] ) : '';
			$amount = isset( $row['amount'] ) ? absint( $row['amount'] ) : 0;
			if ( '' === $slug || 0 === $amount ) {
				continue;
			}
			if ( ! isset( $funds[ $slug ] ) ) {
				$funds[ $slug ] = array( 'one_time' => false, 'subscription' => false, 'applied' => false );
			}
			$funds[ $slug ]['one_time'] = true;
			$funds[ $slug ]['applied']  = true;
		}
	}

	return $funds;
}

function kitmage_wallet_order_guidance_owns_order( $order ) {
	return get_current_user_id() > 0 && (int) $order->get_customer_id() === (int) get_current_user_id();
}

/**
 * Confirmation access matches WooCommerce's order-key URL or order owner.
 */
function kitmage_wallet_order_guidance_confirmation( $order_id ) {
	static $rendered = array();

	$order = wc_get_order( absint( $order_id ) );
	if ( ! $order instanceof WC_Order || isset( $rendered[ $order->get_id() ] ) ) {
		return;
	}

	$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
	if ( ! kitmage_wallet_order_guidance_owns_order( $order ) && ( '' === $key || ! $order->get_order_key() || ! hash_equals( $order->get_order_key(), $key ) ) ) {
		return;
	}

	if ( kitmage_wallet_order_guidance_render( $order ) ) {
		$rendered[ $order->get_id() ] = true;
	}
}

function kitmage_wallet_order_guidance_view_order( $order_id ) {
	$order = wc_get_order( absint( $order_id ) );
	if ( $order instanceof WC_Order && kitmage_wallet_order_guidance_owns_order( $order ) ) {
		kitmage_wallet_order_guidance_render( $order );
	}
}

/**
 * Render a single card with instructions and a button for each eligible Fund.
 * Never show redemption CTAs for unpaid, refunded, unmapped, or unconfirmed grants.
 */
function kitmage_wallet_order_guidance_render( $order ) {
	$funds = kitmage_wallet_order_guidance_funds( $order );
	if ( empty( $funds ) ) {
		return false;
	}

	$unavailable = $order->has_status( array( 'failed', 'cancelled', 'refunded' ) );
	$unpaid      = ! $order->is_paid();
	$unmapped    = kitmage_wallet_woo_resolve_order_user_id( $order ) <= 0;
	?>
	<section class="kitmage-wallet-next-steps" style="margin:1.5em 0;padding:1.5em;border:1px solid #ddd">
		<p><strong><?php esc_html_e( 'What’s next?', 'kitmage-wallet' ); ?></strong></p>
		<h2><?php esc_html_e( 'Using your Wallet Credits', 'kitmage-wallet' ); ?></h2>
		<?php if ( $unavailable ) : ?>
			<p><?php esc_html_e( 'This order has been cancelled, failed, or refunded. Credits from this order are not available to redeem.', 'kitmage-wallet' ); ?></p>
		<?php elseif ( $unpaid ) : ?>
			<p><?php esc_html_e( 'Your Wallet Credits will be available once payment has been confirmed.', 'kitmage-wallet' ); ?></p>
		<?php elseif ( $unmapped ) : ?>
			<p><?php esc_html_e( 'We could not link this purchase to a WordPress account. Please contact us with your order number for help accessing your Credits.', 'kitmage-wallet' ); ?></p>
		<?php else : ?>
			<?php foreach ( $funds as $slug => $grant ) : ?>
				<?php
				$fund         = kitmage_wallet_get_bucket_by_slug( $slug );
				$label        = $fund ? $fund['label'] : $slug;
				$url          = $fund && ! empty( $fund['redeem_url'] ) ? $fund['redeem_url'] : '';
				$instructions = $fund && ! empty( $fund['redeem_instructions'] ) ? $fund['redeem_instructions'] : __( 'Use these Credits for eligible bookings. Contact us if you need help locating the booking page.', 'kitmage-wallet' );
				$button       = $fund && ! empty( $fund['redeem_button_text'] ) ? $fund['redeem_button_text'] : __( 'Book with your Credits', 'kitmage-wallet' );
				$ready        = $grant['applied'] || $grant['subscription'];
				?>
				<div class="kitmage-wallet-next-steps-fund" style="margin-top:1em">
					<h3><?php echo esc_html( $label ); ?></h3>
					<?php if ( ! $ready ) : ?>
						<p><?php esc_html_e( 'Payment has been confirmed, but Credits for this Fund have not been confirmed in your Wallet yet. Please check back shortly.', 'kitmage-wallet' ); ?></p>
					<?php else : ?>
						<?php if ( $grant['subscription'] && ! $grant['applied'] ) : ?>
							<p><?php esc_html_e( 'Once your subscription Credits are available in your Wallet, you can use them for eligible bookings.', 'kitmage-wallet' ); ?></p>
						<?php endif; ?>
						<p><?php echo nl2br( esc_html( $instructions ) ); ?></p>
						<?php if ( '' !== $url ) : ?>
							<p><a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $button ); ?></a></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</section>
	<?php
	return true;
}
