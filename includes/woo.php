<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'KITMAGE_WALLET_PRODUCT_GRANTS_META_KEY' ) ) {
	define( 'KITMAGE_WALLET_PRODUCT_GRANTS_META_KEY', '_kitmage_wallet_grants' );
}

if ( ! defined( 'KITMAGE_WALLET_PRODUCT_GRANTS_NONCE' ) ) {
	define( 'KITMAGE_WALLET_PRODUCT_GRANTS_NONCE', 'kitmage_wallet_product_grants_nonce' );
}

if ( ! defined( 'KITMAGE_WALLET_ORDER_APPLIED_META_KEY' ) ) {
	define( 'KITMAGE_WALLET_ORDER_APPLIED_META_KEY', '_kitmage_wallet_one_time_grants_applied' );
}

if ( ! defined( 'KITMAGE_WALLET_ORDER_APPLIED_DETAILS_META_KEY' ) ) {
	define( 'KITMAGE_WALLET_ORDER_APPLIED_DETAILS_META_KEY', '_kitmage_wallet_one_time_grants_applied_details' );
}

function kitmage_wallet_register_woo_hooks() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	add_action( 'woocommerce_product_options_general_product_data', 'kitmage_wallet_woo_render_product_wallet_fields' );
	add_action( 'woocommerce_process_product_meta', 'kitmage_wallet_woo_save_product_wallet_meta', 10, 1 );

	add_action( 'woocommerce_variation_options', 'kitmage_wallet_woo_render_variation_wallet_fields', 10, 3 );
	add_action( 'woocommerce_save_product_variation', 'kitmage_wallet_woo_save_variation_wallet_meta', 10, 2 );

	add_action( 'woocommerce_order_status_completed', 'kitmage_wallet_woo_apply_order_one_time_grants', 10, 1 );
	add_action( 'woocommerce_payment_complete', 'kitmage_wallet_woo_apply_order_one_time_grants', 10, 1 );
}

function kitmage_wallet_get_grant_types() {
	return array(
		'one_time_grant'     => __( 'One-time grant', 'kitmage-wallet' ),
		'subscription_reset' => __( 'Subscription reset', 'kitmage-wallet' ),
	);
}

function kitmage_wallet_woo_get_bucket_options() {
	$options = array();

	foreach ( kitmage_wallet_get_buckets() as $bucket ) {
		$options[ $bucket['slug'] ] = $bucket['label'] . ' (' . $bucket['slug'] . ')';
	}

	return $options;
}

function kitmage_wallet_woo_default_grant_row() {
	return array(
		'bucket' => '',
		'amount' => 0,
		'type'   => 'one_time_grant',
	);
}

function kitmage_wallet_woo_get_product_grants( $product_id ) {
	$raw = get_post_meta( $product_id, KITMAGE_WALLET_PRODUCT_GRANTS_META_KEY, true );
	return kitmage_wallet_woo_normalize_grants( $raw );
}

function kitmage_wallet_woo_normalize_grants( $raw_grants ) {
	$raw_grants = is_array( $raw_grants ) ? $raw_grants : array();
	$valid      = array();
	$types      = kitmage_wallet_get_grant_types();
	$buckets    = kitmage_wallet_woo_get_bucket_options();

	foreach ( $raw_grants as $raw_grant ) {
		if ( ! is_array( $raw_grant ) ) {
			continue;
		}

		$bucket = isset( $raw_grant['bucket'] ) ? kitmage_wallet_sanitize_bucket_slug( $raw_grant['bucket'] ) : '';
		$type   = isset( $raw_grant['type'] ) ? sanitize_key( $raw_grant['type'] ) : '';
		$amount = isset( $raw_grant['amount'] ) ? absint( $raw_grant['amount'] ) : 0;

		if ( '' === $bucket || ! isset( $buckets[ $bucket ] ) ) {
			continue;
		}

		if ( ! isset( $types[ $type ] ) ) {
			continue;
		}

		$valid[] = array(
			'bucket' => $bucket,
			'amount' => $amount,
			'type'   => $type,
		);
	}

	return array_values( $valid );
}

function kitmage_wallet_woo_get_resolved_item_grants( $item ) {
	if ( ! $item instanceof WC_Order_Item_Product ) {
		return array();
	}

	$product_id   = $item->get_product_id();
	$variation_id = $item->get_variation_id();

	if ( $variation_id > 0 ) {
		$variation_grants = kitmage_wallet_woo_get_product_grants( $variation_id );
		if ( ! empty( $variation_grants ) ) {
			return $variation_grants;
		}
	}

	if ( $product_id > 0 ) {
		return kitmage_wallet_woo_get_product_grants( $product_id );
	}

	return array();
}

function kitmage_wallet_woo_resolve_order_user_id( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return 0;
	}

	$user_id = (int) $order->get_user_id();
	if ( $user_id > 0 ) {
		return $user_id;
	}

	$customer_id = (int) $order->get_customer_id();
	if ( $customer_id > 0 ) {
		return $customer_id;
	}

	$billing_email = sanitize_email( (string) $order->get_billing_email() );
	if ( '' !== $billing_email ) {
		$user = get_user_by( 'email', $billing_email );
		if ( $user instanceof WP_User ) {
			return (int) $user->ID;
		}
	}

	return 0;
}

function kitmage_wallet_woo_apply_order_one_time_grants( $order_id ) {
	$order_id = absint( $order_id );
	if ( $order_id <= 0 ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	if ( 'yes' === $order->get_meta( KITMAGE_WALLET_ORDER_APPLIED_META_KEY, true ) ) {
		return;
	}

	$user_id = kitmage_wallet_woo_resolve_order_user_id( $order );
	if ( $user_id <= 0 ) {
		$order->add_order_note( __( 'KitMage Wallet: skipped one-time grants (no mapped WordPress user).', 'kitmage-wallet' ) );
		$order->update_meta_data( KITMAGE_WALLET_ORDER_APPLIED_META_KEY, 'no_user' );
		$order->save();
		return;
	}

	$applied = array();

	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		$grants = kitmage_wallet_woo_get_resolved_item_grants( $item );
		if ( empty( $grants ) ) {
			continue;
		}

		$quantity = absint( $item->get_quantity() );
		if ( $quantity <= 0 ) {
			continue;
		}

		foreach ( $grants as $grant ) {
			$bucket = isset( $grant['bucket'] ) ? kitmage_wallet_sanitize_bucket_slug( $grant['bucket'] ) : '';
			$amount = isset( $grant['amount'] ) ? absint( $grant['amount'] ) : 0;
			$type   = isset( $grant['type'] ) ? sanitize_key( $grant['type'] ) : '';

			if ( 'one_time_grant' !== $type || '' === $bucket || $amount <= 0 ) {
				continue;
			}

			$amount *= $quantity;

			if ( wallet_add_balance( $user_id, $bucket, $amount ) ) {
				$applied[] = array(
					'item_id' => absint( $item_id ),
					'bucket'  => $bucket,
					'amount'  => $amount,
				);
			}
		}
	}

	if ( empty( $applied ) ) {
		$order->add_order_note( __( 'KitMage Wallet: no one-time grants found for this order.', 'kitmage-wallet' ) );
	} else {
		$summary_parts = array();
		foreach ( $applied as $entry ) {
			$summary_parts[] = sprintf( '%s +%d', $entry['bucket'], $entry['amount'] );
		}

		/* translators: 1: user ID, 2: comma-separated grant summary. */
		$order->add_order_note( sprintf( __( 'KitMage Wallet: applied one-time grants to user #%1$d (%2$s).', 'kitmage-wallet' ), $user_id, implode( ', ', $summary_parts ) ) );
	}

	$order->update_meta_data( KITMAGE_WALLET_ORDER_APPLIED_META_KEY, 'yes' );
	$order->update_meta_data( KITMAGE_WALLET_ORDER_APPLIED_DETAILS_META_KEY, $applied );
	$order->save();

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'KitMage Wallet order ' . $order_id . ' one-time grants applied. User=' . $user_id . ' Applied=' . wp_json_encode( $applied ) );
	}
}

function kitmage_wallet_woo_render_product_wallet_fields() {
	global $post;

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$product = wc_get_product( $post->ID );
	if ( ! $product ) {
		return;
	}

	if ( ! $product->is_type( array( 'simple', 'variable', 'subscription', 'variable-subscription' ) ) ) {
		return;
	}

	$grants = kitmage_wallet_woo_get_product_grants( $post->ID );
	if ( empty( $grants ) ) {
		$grants = array( kitmage_wallet_woo_default_grant_row() );
	}

	kitmage_wallet_woo_render_wallet_grants_panel( 'kitmage_wallet_grants', $grants );
}

function kitmage_wallet_woo_render_variation_wallet_fields( $loop, $variation_data, $variation ) {
	$grants = kitmage_wallet_woo_get_product_grants( $variation->ID );
	if ( empty( $grants ) ) {
		$grants = array( kitmage_wallet_woo_default_grant_row() );
	}

	echo '<div class="form-row form-row-full">';
	echo '<h4>' . esc_html__( 'Wallet Credits', 'kitmage-wallet' ) . '</h4>';
	kitmage_wallet_woo_render_wallet_grants_panel( 'kitmage_wallet_variation_grants[' . absint( $loop ) . ']', $grants, 'variable_' . absint( $loop ) );
	echo '</div>';
}

function kitmage_wallet_woo_render_wallet_grants_panel( $field_name, $grants, $uniq = 'product' ) {
	$bucket_options = kitmage_wallet_woo_get_bucket_options();
	$grant_types    = kitmage_wallet_get_grant_types();

	wp_nonce_field( 'kitmage_wallet_save_product_grants', KITMAGE_WALLET_PRODUCT_GRANTS_NONCE );

	echo '<p><strong>' . esc_html__( 'Wallet Credits', 'kitmage-wallet' ) . '</strong></p>';
	echo '<p class="description">' . esc_html__( 'Each row grants Credits to a Fund. Subscription reset rows set the Fund’s Credits on renewal; one-time grant rows add Credits when an order completes.', 'kitmage-wallet' ) . '</p>';
	echo '<table class="widefat striped" style="max-width:900px">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Fund', 'kitmage-wallet' ) . '</th>';
	echo '<th>' . esc_html__( 'Credits', 'kitmage-wallet' ) . '</th>';
	echo '<th>' . esc_html__( 'Type', 'kitmage-wallet' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $grants as $index => $grant ) {
		$bucket = isset( $grant['bucket'] ) ? kitmage_wallet_sanitize_bucket_slug( $grant['bucket'] ) : '';
		$amount = isset( $grant['amount'] ) ? absint( $grant['amount'] ) : 0;
		$type   = isset( $grant['type'] ) ? sanitize_key( $grant['type'] ) : 'one_time_grant';

		echo '<tr>';
		echo '<td><select name="' . esc_attr( $field_name ) . '[' . absint( $index ) . '][bucket]">';
		echo '<option value="">' . esc_html__( 'Select Fund', 'kitmage-wallet' ) . '</option>';
		foreach ( $bucket_options as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $bucket, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td>';

		echo '<td><input min="0" type="number" step="1" name="' . esc_attr( $field_name ) . '[' . absint( $index ) . '][amount]" value="' . esc_attr( (string) $amount ) . '" /></td>';

		echo '<td><select name="' . esc_attr( $field_name ) . '[' . absint( $index ) . '][type]">';
		foreach ( $grant_types as $grant_type => $grant_label ) {
			echo '<option value="' . esc_attr( $grant_type ) . '" ' . selected( $type, $grant_type, false ) . '>' . esc_html( $grant_label ) . '</option>';
		}
		echo '</select></td>';
		echo '</tr>';
	}

	$empty_row = kitmage_wallet_woo_default_grant_row();
	$index     = count( $grants );
	echo '<tr>';
	echo '<td><select name="' . esc_attr( $field_name ) . '[' . absint( $index ) . '][bucket]">';
	echo '<option value="">' . esc_html__( 'Select Fund', 'kitmage-wallet' ) . '</option>';
	foreach ( $bucket_options as $slug => $label ) {
		echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</option>';
	}
	echo '</select></td>';
	echo '<td><input min="0" type="number" step="1" name="' . esc_attr( $field_name ) . '[' . absint( $index ) . '][amount]" value="' . esc_attr( (string) $empty_row['amount'] ) . '" /></td>';
	echo '<td><select name="' . esc_attr( $field_name ) . '[' . absint( $index ) . '][type]">';
	foreach ( $grant_types as $grant_type => $grant_label ) {
		echo '<option value="' . esc_attr( $grant_type ) . '">' . esc_html( $grant_label ) . '</option>';
	}
	echo '</select></td>';
	echo '</tr>';

	echo '</tbody></table>';
}

function kitmage_wallet_woo_save_product_wallet_meta( $product_id ) {
	if ( ! current_user_can( 'edit_product', $product_id ) ) {
		return;
	}

	if ( ! isset( $_POST[ KITMAGE_WALLET_PRODUCT_GRANTS_NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ KITMAGE_WALLET_PRODUCT_GRANTS_NONCE ] ) ), 'kitmage_wallet_save_product_grants' ) ) {
		return;
	}

	$raw_grants = isset( $_POST['kitmage_wallet_grants'] ) ? (array) wp_unslash( $_POST['kitmage_wallet_grants'] ) : array();
	$grants     = kitmage_wallet_woo_normalize_grants( $raw_grants );

	if ( empty( $grants ) ) {
		delete_post_meta( $product_id, KITMAGE_WALLET_PRODUCT_GRANTS_META_KEY );
		return;
	}

	update_post_meta( $product_id, KITMAGE_WALLET_PRODUCT_GRANTS_META_KEY, $grants );
}

function kitmage_wallet_woo_save_variation_wallet_meta( $variation_id, $i ) {
	if ( ! current_user_can( 'edit_product', $variation_id ) ) {
		return;
	}

	if ( ! isset( $_POST[ KITMAGE_WALLET_PRODUCT_GRANTS_NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ KITMAGE_WALLET_PRODUCT_GRANTS_NONCE ] ) ), 'kitmage_wallet_save_product_grants' ) ) {
		return;
	}

	$raw_variation_grants = isset( $_POST['kitmage_wallet_variation_grants'] ) ? (array) wp_unslash( $_POST['kitmage_wallet_variation_grants'] ) : array();
	$raw_grants           = isset( $raw_variation_grants[ $i ] ) && is_array( $raw_variation_grants[ $i ] ) ? $raw_variation_grants[ $i ] : array();
	$grants               = kitmage_wallet_woo_normalize_grants( $raw_grants );

	if ( empty( $grants ) ) {
		delete_post_meta( $variation_id, KITMAGE_WALLET_PRODUCT_GRANTS_META_KEY );
		return;
	}

	update_post_meta( $variation_id, KITMAGE_WALLET_PRODUCT_GRANTS_META_KEY, $grants );
}
