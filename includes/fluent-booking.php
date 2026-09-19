<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KITMAGE_WALLET_FB_META_ENABLED         = '_kitmage_wallet_enabled';
const KITMAGE_WALLET_FB_META_COST            = '_kitmage_wallet_credit_cost';
const KITMAGE_WALLET_FB_META_ALLOWED_BUCKETS = '_kitmage_wallet_allowed_buckets';
const KITMAGE_WALLET_FB_META_USE_CREDITS_PAYMENT_SETTINGS = '_kitmage_wallet_use_credits_in_payment_settings';


function kitmage_wallet_fb_debug_log( $message, $context = array() ) {
	if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG || ! defined( 'KITMAGE_WALLET_DEBUG' ) || ! KITMAGE_WALLET_DEBUG ) {
		return;
	}

	$line = '[KITMAGE_WALLET_FB] ' . $message;
	if ( ! empty( $context ) ) {
		$encoded = wp_json_encode( $context );
		if ( false !== $encoded ) {
			$line .= ' ' . $encoded;
		}
	}

	error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

function kitmage_wallet_fb_to_array( $value ) {
	if ( is_array( $value ) ) {
		return $value;
	}

	if ( is_object( $value ) ) {
		return get_object_vars( $value );
	}

	return array();
}

function kitmage_wallet_register_fluent_booking_hooks() {
	$fluent_booking_loaded = defined( 'FLUENT_BOOKING' ) || defined( 'FLUENT_BOOKING_VERSION' ) || defined( 'FLUENT_BOOKING_LITE' ) || class_exists( '\\FluentBooking\\App\\App' );

	if ( ! $fluent_booking_loaded ) {
		kitmage_wallet_fb_debug_log( 'Hook registration skipped because Fluent Booking was not detected.', array(
			'FLUENT_BOOKING'         => defined( 'FLUENT_BOOKING' ),
			'FLUENT_BOOKING_VERSION' => defined( 'FLUENT_BOOKING_VERSION' ),
			'FLUENT_BOOKING_LITE'    => defined( 'FLUENT_BOOKING_LITE' ),
			'app_class'              => class_exists( '\\FluentBooking\\App\\App' ),
		) );
		return;
	}

	add_action( 'fluent_booking_after_event_settings_fields', 'kitmage_wallet_render_fluent_booking_event_wallet_settings', 20, 1 );
	add_action( 'fluent_booking_save_event_settings', 'kitmage_wallet_save_fluent_booking_event_wallet_settings', 20, 2 );

	add_filter( 'fluent_booking/event_payment_settings_defaults', 'kitmage_wallet_add_payment_settings_defaults', 20, 2 );
	add_filter( 'fluent_booking/get_event_payment_settings', 'kitmage_wallet_add_payment_settings_fields', 20, 2 );
	add_filter( 'fluent_booking/payment/get_payment_settings', 'kitmage_wallet_add_payment_settings_panel_checkbox', 20, 2 );
	add_filter( 'fluent_booking/payment/payment_settings_before_update_native', 'kitmage_wallet_save_payment_settings_panel_checkbox', 20, 1 );
	add_filter( 'fluent_booking/payment/payment_settings_before_update_woo', 'kitmage_wallet_save_payment_settings_panel_checkbox', 20, 1 );

	add_filter( 'fluent_booking_event_calendar_html', 'kitmage_wallet_filter_fluent_booking_calendar_html', 20, 3 );
	add_filter( 'kitmage_wallet_booking_shortcode_output', 'kitmage_wallet_maybe_block_booking_shortcode_output', 20, 4 );

	add_filter( 'fluent_booking/booking_data', 'kitmage_wallet_validate_fluent_booking_booking_data', 20, 4 );
	add_action( 'fluent_booking/after_booking_scheduled', 'kitmage_wallet_debit_after_fluent_booking_created', 20, 3 );
	add_action( 'fluent_booking/after_booking_pending', 'kitmage_wallet_debit_after_fluent_booking_created', 20, 3 );

	kitmage_wallet_fb_debug_log( 'Registered Fluent Booking wallet hooks.', array(
		'validation_hook' => 'fluent_booking/booking_data',
		'debit_hooks'     => array( 'fluent_booking/after_booking_scheduled', 'fluent_booking/after_booking_pending' ),
	) );
}


function kitmage_wallet_get_bucket_registry_slugs() {
	$slugs = array();

	foreach ( kitmage_wallet_get_buckets() as $bucket ) {
		if ( ! empty( $bucket['slug'] ) ) {
			$slugs[] = $bucket['slug'];
		}
	}

	return array_values( array_unique( $slugs ) );
}

function kitmage_wallet_sanitize_allowed_buckets( $raw_buckets ) {
	if ( is_string( $raw_buckets ) ) {
		$raw_buckets = explode( ',', $raw_buckets );
	}

	$buckets        = kitmage_wallet_normalize_bucket_list( $raw_buckets );
	$registry_slugs = kitmage_wallet_get_bucket_registry_slugs();

	if ( empty( $registry_slugs ) ) {
		return array();
	}

	return array_values( array_filter( $buckets, static function ( $bucket ) use ( $registry_slugs ) {
		return in_array( $bucket, $registry_slugs, true );
	} ) );
}

function kitmage_wallet_get_fluent_booking_event_wallet_settings( $event_id ) {
	$event_id = (int) $event_id;

	$settings = array(
		'enabled'         => false,
		'credit_cost'     => 0,
		'allowed_buckets' => array(),
	);

	if ( $event_id <= 0 ) {
		return $settings;
	}

	$legacy_enabled = (bool) get_post_meta( $event_id, KITMAGE_WALLET_FB_META_ENABLED, true );
	$payment_settings = get_post_meta( $event_id, 'payment_settings', true );
	$payment_toggle_exists = is_array( $payment_settings ) && array_key_exists( 'enabled', $payment_settings );

	// Migration behavior: once Fluent Booking's payment-settings toggle is available for an event,
	// it becomes the source of truth so the wallet gate follows the same enable/disable switch as booking payments.
	if ( $payment_toggle_exists ) {
		$settings['enabled'] = in_array( strtolower( (string) $payment_settings['enabled'] ), array( '1', 'true', 'yes', 'on' ), true );
	} else {
		// Backward compatibility: older events may only have the legacy KitMage flag, so we fall back until
		// the new payment_settings[enabled] value is saved at least once.
		$settings['enabled'] = $legacy_enabled;
	}

	$settings['credit_cost'] = kitmage_wallet_to_int( get_post_meta( $event_id, KITMAGE_WALLET_FB_META_COST, true ) );
	$settings['allowed_buckets'] = kitmage_wallet_sanitize_allowed_buckets( get_post_meta( $event_id, KITMAGE_WALLET_FB_META_ALLOWED_BUCKETS, true ) );

	// Hard safety requirements: wallet enforcement is only effective when both a positive cost and
	// at least one allowed bucket are configured, regardless of which enable toggle was used above.
	if ( $settings['credit_cost'] <= 0 || empty( $settings['allowed_buckets'] ) ) {
		$settings['enabled'] = false;
	}

	return $settings;
}



function kitmage_wallet_add_payment_settings_defaults( $defaults, $calendar_slot ) {
	$defaults['kitmage_wallet_enabled'] = 'no';

	return $defaults;
}

function kitmage_wallet_add_payment_settings_fields( $settings, $calendar_slot ) {
	$event_id = is_object( $calendar_slot ) && isset( $calendar_slot->id ) ? (int) $calendar_slot->id : 0;
	$legacy_enabled = (bool) get_post_meta( $event_id, KITMAGE_WALLET_FB_META_ENABLED, true );
	$settings['kitmage_wallet_enabled'] = $legacy_enabled ? 'yes' : ( isset( $settings['kitmage_wallet_enabled'] ) ? $settings['kitmage_wallet_enabled'] : 'no' );

	return $settings;
}

function kitmage_wallet_add_payment_settings_panel_checkbox( $data, $calendar_event ) {
	$event_id = is_object( $calendar_event ) && isset( $calendar_event->id ) ? (int) $calendar_event->id : 0;
	$enabled  = (bool) get_post_meta( $event_id, KITMAGE_WALLET_FB_META_USE_CREDITS_PAYMENT_SETTINGS, true );

	if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
		$data['settings'] = array();
	}

	$data['settings']['kitmage_wallet_use_credits_in_payment_settings'] = $enabled ? 'yes' : 'no';
	$data['settings']['kitmage_wallet_use_credits_in_payment_settings_label'] = 'Enable KitMage Credits for this event';
	$data['settings']['kitmage_wallet_use_credits_in_payment_settings_nonce'] = wp_create_nonce( 'kitmage_wallet_payment_settings_' . $event_id );

	return $data;
}

function kitmage_wallet_save_payment_settings_panel_checkbox( $settings ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		kitmage_wallet_fb_debug_log( 'Capability check failed for payment-settings save callback.', array( 'capability' => 'manage_options' ) );
		return $settings;
	}

	$event_id = isset( $_REQUEST['event_id'] ) ? (int) $_REQUEST['event_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $event_id <= 0 ) {
		return $settings;
	}

	$nonce = isset( $settings['kitmage_wallet_use_credits_in_payment_settings_nonce'] )
		? sanitize_text_field( wp_unslash( $settings['kitmage_wallet_use_credits_in_payment_settings_nonce'] ) )
		: '';

	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'kitmage_wallet_payment_settings_' . $event_id ) ) {
		kitmage_wallet_fb_debug_log( 'Nonce verification failed for payment-settings save callback.', array( 'event_id' => $event_id ) );
		return $settings;
	}

	$raw_value = isset( $settings['kitmage_wallet_use_credits_in_payment_settings'] ) ? $settings['kitmage_wallet_use_credits_in_payment_settings'] : 'no';
	$normalized = ( 'yes' === $raw_value || '1' === (string) $raw_value || 1 === $raw_value ) ? 1 : 0;

	kitmage_wallet_fb_debug_log( 'Payment-settings save callback fired with sanitized values.', array(
		'event_id'                                            => $event_id,
		'kitmage_wallet_use_credits_in_payment_settings'        => $normalized,
	) );

	update_post_meta( $event_id, KITMAGE_WALLET_FB_META_USE_CREDITS_PAYMENT_SETTINGS, $normalized );
	update_post_meta( $event_id, KITMAGE_WALLET_FB_META_ENABLED, $normalized );

	return $settings;
}


function kitmage_wallet_render_fluent_booking_event_wallet_settings( $event ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$event_id  = is_object( $event ) && isset( $event->id ) ? (int) $event->id : (int) $event;
	$settings  = kitmage_wallet_get_fluent_booking_event_wallet_settings( $event_id );
	$buckets   = kitmage_wallet_get_buckets();
	?>
	<div class="kitmage-wallet-event-settings">
		<h3><?php esc_html_e( 'Wallet Restriction', 'kitmage-wallet' ); ?></h3>
		<p>
			<label>
				<input type="checkbox" name="kitmage_wallet_enabled" value="1" <?php checked( $settings['enabled'] ); ?> />
				<?php esc_html_e( 'Enable KitMage Credits for this event', 'kitmage-wallet' ); ?>
			</label>
		</p>
		<p>
			<label for="kitmage-wallet-credit-cost"><?php esc_html_e( 'Credit Cost (int)', 'kitmage-wallet' ); ?></label>
			<input id="kitmage-wallet-credit-cost" type="number" min="0" step="1" name="kitmage_wallet_credit_cost" value="<?php echo esc_attr( $settings['credit_cost'] ); ?>" />
		</p>
		<?php wp_nonce_field( 'kitmage_wallet_save_fluent_booking_event_settings', 'kitmage_wallet_fb_nonce' ); ?>
		<p>
			<label for="kitmage-wallet-allowed-buckets"><?php esc_html_e( 'Allowed Funds (spending order)', 'kitmage-wallet' ); ?></label>
			<select id="kitmage-wallet-allowed-buckets" name="kitmage_wallet_allowed_buckets[]" multiple="multiple">
				<?php foreach ( $buckets as $bucket ) : ?>
					<option value="<?php echo esc_attr( $bucket['slug'] ); ?>" <?php selected( in_array( $bucket['slug'], $settings['allowed_buckets'], true ) ); ?>><?php echo esc_html( $bucket['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
	</div>
	<?php
}

function kitmage_wallet_save_fluent_booking_event_wallet_settings( $event_id, $payload ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$event_id = (int) $event_id;
	if ( $event_id <= 0 ) {
		return;
	}

	$nonce = isset( $payload['kitmage_wallet_fb_nonce'] ) ? sanitize_text_field( wp_unslash( $payload['kitmage_wallet_fb_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'kitmage_wallet_save_fluent_booking_event_settings' ) ) {
		return;
	}

	$enabled_input = isset( $payload['kitmage_wallet_enabled'] ) ? $payload['kitmage_wallet_enabled'] : 0;
	$cost_input    = isset( $payload['kitmage_wallet_credit_cost'] ) ? $payload['kitmage_wallet_credit_cost'] : 0;
	$buckets_input = isset( $payload['kitmage_wallet_allowed_buckets'] ) ? $payload['kitmage_wallet_allowed_buckets'] : array();

	$enabled = ! empty( $enabled_input );
	$cost    = kitmage_wallet_to_int( $cost_input );
	$buckets = kitmage_wallet_sanitize_allowed_buckets( $buckets_input );

	update_post_meta( $event_id, KITMAGE_WALLET_FB_META_ENABLED, $enabled ? 1 : 0 );
	update_post_meta( $event_id, KITMAGE_WALLET_FB_META_COST, $cost );
	update_post_meta( $event_id, KITMAGE_WALLET_FB_META_ALLOWED_BUCKETS, $buckets );
}


function kitmage_wallet_fb_extract_int_field( $value, $keys ) {
	if ( ! is_array( $keys ) || empty( $keys ) ) {
		return 0;
	}

	foreach ( $keys as $key ) {
		if ( is_object( $value ) && isset( $value->{$key} ) ) {
			return (int) $value->{$key};
		}

		if ( is_array( $value ) && isset( $value[ $key ] ) ) {
			return (int) $value[ $key ];
		}
	}

	return 0;
}


function kitmage_wallet_fb_get_booking_payload( $booking ) {
	if ( is_object( $booking ) || is_array( $booking ) ) {
		return $booking;
	}

	$booking_id = (int) $booking;
	if ( $booking_id <= 0 ) {
		return array();
	}

	global $wpdb;

	$tables = array(
		$wpdb->prefix . 'fcal_bookings',
		$wpdb->prefix . 'fluent_booking_bookings',
	);

	foreach ( $tables as $table ) {
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			continue;
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( is_array( $row ) ) {
			return $row;
		}
	}

	return array();
}

function kitmage_wallet_fb_resolve_booking_event_id( $booking, $event ) {
	$payload = kitmage_wallet_fb_get_booking_payload( $booking );
	$event_id = kitmage_wallet_fb_extract_int_field( $event, array( 'id', 'event_id', 'slot_id', 'calendar_slot_id' ) );
	if ( $event_id > 0 ) {
		return $event_id;
	}

	$event_id = kitmage_wallet_fb_extract_int_field( $payload, array( 'event_id', 'slot_id', 'calendar_slot_id', 'calendar_event_id' ) );
	kitmage_wallet_fb_debug_log( 'Resolved booking event id from payload fallback.', array(
		'event_id'       => $event_id,
		'event_payload'  => kitmage_wallet_fb_to_array( $event ),
		'booking_payload'=> kitmage_wallet_fb_to_array( $payload ),
	) );

	return $event_id;
}

function kitmage_wallet_fb_resolve_booking_user_id( $booking ) {
	$payload = kitmage_wallet_fb_get_booking_payload( $booking );
	$user_id = kitmage_wallet_fb_extract_int_field( $payload, array( 'user_id', 'wp_user_id', 'attendee_user_id', 'booked_by_user_id' ) );
	if ( $user_id > 0 ) {
		return $user_id;
	}
	kitmage_wallet_fb_debug_log( 'Could not resolve booking user from known user-id fields. Attempting email lookup.', array(
		'booking_payload' => kitmage_wallet_fb_to_array( $payload ),
	) );
	$email = '';
	if ( is_object( $payload ) && isset( $payload->email ) ) {
		$email = sanitize_email( (string) $payload->email );
	} elseif ( is_array( $payload ) && isset( $payload['email'] ) ) {
		$email = sanitize_email( (string) $payload['email'] );
	}

	if ( '' !== $email ) {
		$matched_user = get_user_by( 'email', $email );
		if ( $matched_user instanceof WP_User ) {
			kitmage_wallet_fb_debug_log( 'Resolved booking user by email fallback.', array( 'email' => $email, 'user_id' => (int) $matched_user->ID ) );
			return (int) $matched_user->ID;
		}
	}

	kitmage_wallet_fb_debug_log( 'Failed to resolve booking user id.', array( 'email' => $email, 'booking_payload' => kitmage_wallet_fb_to_array( $payload ) ) );

	return 0;
}

function kitmage_wallet_fluent_booking_affordability( $event_id, $user_id ) {
	$settings = kitmage_wallet_get_fluent_booking_event_wallet_settings( $event_id );
	$user_id  = (int) $user_id;

	if ( ! $settings['enabled'] ) {
		kitmage_wallet_fb_debug_log( 'Affordability check bypassed because wallet rule is disabled.', array( 'event_id' => $event_id, 'user_id' => $user_id, 'settings' => $settings ) );
		return array( 'allowed' => true, 'reason' => '' );
	}

	if ( $user_id <= 0 ) {
		kitmage_wallet_fb_debug_log( 'Affordability check failed because no WP user could be determined.', array( 'event_id' => $event_id, 'settings' => $settings ) );
		return array( 'allowed' => false, 'reason' => __( 'You must be logged in to book this event.', 'kitmage-wallet' ) );
	}

	$wallet_user_id = kitmage_wallet_get_effective_wallet_user_id( $user_id );

	if ( $wallet_user_id <= 0 || ! wallet_can_afford( $wallet_user_id, $settings['allowed_buckets'], $settings['credit_cost'] ) ) {
		kitmage_wallet_fb_debug_log( 'Affordability check failed due to insufficient balance.', array( 'event_id' => $event_id, 'booking_user_id' => $user_id, 'wallet_user_id' => $wallet_user_id, 'settings' => $settings ) );
		return array( 'allowed' => false, 'reason' => __( 'Insufficient wallet credits for this booking.', 'kitmage-wallet' ) );
	}

	return array( 'allowed' => true, 'reason' => '', 'wallet_user_id' => $wallet_user_id );
}

function kitmage_wallet_filter_fluent_booking_calendar_html( $html, $event, $context ) {
	$event_id = is_object( $event ) && isset( $event->id ) ? (int) $event->id : (int) $event;
	$check    = kitmage_wallet_fluent_booking_affordability( $event_id, get_current_user_id() );

	if ( $check['allowed'] ) {
		return $html;
	}

	$fallback = is_array( $context ) && isset( $context['fallback'] ) ? (string) $context['fallback'] : $check['reason'];
	return do_shortcode( $fallback );
}

function kitmage_wallet_maybe_block_booking_shortcode_output( $output, $event_id, $fallback, $user_id ) {
	$check = kitmage_wallet_fluent_booking_affordability( $event_id, $user_id );
	if ( $check['allowed'] ) {
		return $output;
	}

	$fallback = '' !== (string) $fallback ? (string) $fallback : $check['reason'];
	return do_shortcode( $fallback );
}

function kitmage_wallet_validate_fluent_booking_booking_data( $booking_data, $calendar_slot, $custom_fields_data, $raw_data ) {
	$event_id = is_object( $calendar_slot ) && isset( $calendar_slot->id ) ? (int) $calendar_slot->id : kitmage_wallet_fb_extract_int_field( $booking_data, array( 'event_id' ) );
	$user_id  = kitmage_wallet_fb_extract_int_field( $booking_data, array( 'person_user_id', 'user_id', 'wp_user_id' ) );

	if ( $user_id <= 0 && is_array( $booking_data ) && ! empty( $booking_data['email'] ) ) {
		$matched_user = get_user_by( 'email', sanitize_email( (string) $booking_data['email'] ) );
		if ( $matched_user instanceof WP_User ) {
			$user_id = (int) $matched_user->ID;
		}
	}

	$check = kitmage_wallet_fluent_booking_affordability( $event_id, $user_id );
	kitmage_wallet_fb_debug_log( 'Booking-data wallet validation result.', array(
		'event_id'      => $event_id,
		'user_id'       => $user_id,
		'allowed'       => ! empty( $check['allowed'] ),
		'reason'        => isset( $check['reason'] ) ? $check['reason'] : '',
		'booking_data'  => is_array( $booking_data ) ? $booking_data : array(),
		'raw_data'      => is_array( $raw_data ) ? $raw_data : array(),
	) );
	if ( $check['allowed'] ) {
		return $booking_data;
	}

	return new WP_Error( 'wallet_insufficient_credits', $check['reason'] );
}

function kitmage_wallet_debit_after_fluent_booking_created( $booking, $event ) {
	$event_id = kitmage_wallet_fb_resolve_booking_event_id( $booking, $event );
	$user_id  = kitmage_wallet_fb_resolve_booking_user_id( $booking );

	$payload        = kitmage_wallet_fb_get_booking_payload( $booking );
	$settings       = kitmage_wallet_get_fluent_booking_event_wallet_settings( $event_id );
	$wallet_user_id = $user_id > 0 ? kitmage_wallet_get_effective_wallet_user_id( $user_id ) : 0;
	if ( ! $settings['enabled'] || $user_id <= 0 || $wallet_user_id <= 0 ) {
		kitmage_wallet_fb_debug_log( 'Skipping wallet debit after booking create.', array(
			'event_id'        => $event_id,
			'booking_user_id' => $user_id,
			'wallet_user_id'  => $wallet_user_id,
			'wallet_enabled'  => ! empty( $settings['enabled'] ),
			'booking_payload' => is_object( $payload ) ? get_object_vars( $payload ) : ( is_array( $payload ) ? $payload : array() ),
		) );
		return;
	}

	kitmage_wallet_fb_debug_log( 'Attempting wallet debit after booking creation.', array(
		'event_id'        => $event_id,
		'booking_user_id' => $user_id,
		'wallet_user_id'  => $wallet_user_id,
		'credit_cost'     => $settings['credit_cost'],
		'allowed_buckets' => $settings['allowed_buckets'],
		'booking_payload' => kitmage_wallet_fb_to_array( $payload ),
	) );

	$debit = wallet_debit_balances( $wallet_user_id, $settings['allowed_buckets'], $settings['credit_cost'] );
	kitmage_wallet_fb_debug_log( 'Wallet debit result after booking creation.', array(
		'event_id'    => $event_id,
		'booking_user_id' => $user_id,
		'wallet_user_id'  => $wallet_user_id,
		'debit_result'    => is_array( $debit ) ? $debit : array( 'raw' => $debit ),
	) );
	if ( ! empty( $debit['success'] ) ) {
		return;
	}

	do_action(
		'kitmage_wallet_booking_debit_failed',
		array(
			'booking_id'     => kitmage_wallet_fb_extract_int_field( $payload, array( 'id' ) ),
			'event_id'       => $event_id,
			'user_id'        => $user_id,
			'wallet_user_id' => $wallet_user_id,
			'reason'         => __( 'Wallet debit failed after booking creation.', 'kitmage-wallet' ),
		)
	);
}

function kitmage_wallet_get_fluent_booking_events_for_admin() {
	global $wpdb;

	$slot_tables = array(
		$wpdb->prefix . 'fcal_calendar_events',
		$wpdb->prefix . 'fluent_booking_calendar_slots',
	);
	$cal_tables = array(
		$wpdb->prefix . 'fcal_calendars',
		$wpdb->prefix . 'fluent_booking_calendars',
	);

	$table = '';
	foreach ( $slot_tables as $slot_table ) {
		if ( $slot_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $slot_table ) ) ) {
			$table = $slot_table;
			break;
		}
	}

	$cal_table = '';
	foreach ( $cal_tables as $candidate_cal_table ) {
		if ( $candidate_cal_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $candidate_cal_table ) ) ) {
			$cal_table = $candidate_cal_table;
			break;
		}
	}

	if ( '' === $table ) {
		return array();
	}

	if ( '' !== $cal_table ) {
		$query = "SELECT s.id, s.title, s.calendar_id, c.title AS calendar_title FROM {$table} s LEFT JOIN {$cal_table} c ON c.id = s.calendar_id ORDER BY s.id DESC LIMIT 500";
	} else {
		$query = "SELECT id, title, calendar_id FROM {$table} ORDER BY id DESC LIMIT 500";
	}

	$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return is_array( $rows ) ? $rows : array();
}

function kitmage_wallet_render_booking_event_rules_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'kitmage-wallet' ) );
	}

	$events  = kitmage_wallet_get_fluent_booking_events_for_admin();
	$buckets = kitmage_wallet_get_buckets();
	$errors  = kitmage_wallet_parse_notice_messages( isset( $_GET['wallet_errors'] ) ? wp_unslash( $_GET['wallet_errors'] ) : '' );
	$success = kitmage_wallet_parse_notice_messages( isset( $_GET['wallet_success'] ) ? wp_unslash( $_GET['wallet_success'] ) : '' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Booking Event Rules', 'kitmage-wallet' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Associate each Fluent Booking event with KitMage wallet credit rules.', 'kitmage-wallet' ); ?></p>
		<?php foreach ( $errors as $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endforeach; ?>
		<?php foreach ( $success as $message ) : ?><div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div><?php endforeach; ?>

		<?php if ( empty( $events ) ) : ?>
			<p><?php esc_html_e( 'No Fluent Booking events were found. Confirm Fluent Booking is active and events exist.', 'kitmage-wallet' ); ?></p>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'kitmage_wallet_save_booking_event_rules' ); ?>
			<input type="hidden" name="action" value="kitmage_wallet_save_booking_event_rules" />
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Event', 'kitmage-wallet' ); ?></th><th><?php esc_html_e( 'Enable Credits', 'kitmage-wallet' ); ?></th><th><?php esc_html_e( 'Credit Cost', 'kitmage-wallet' ); ?></th><th><?php esc_html_e( 'Allowed Funds (comma-separated slugs)', 'kitmage-wallet' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $events as $event ) :
					$event_id = isset( $event['id'] ) ? (int) $event['id'] : 0;
					$settings = kitmage_wallet_get_fluent_booking_event_wallet_settings( $event_id );
					$calendar_id = isset( $event['calendar_id'] ) ? (int) $event['calendar_id'] : 0;
					$link = admin_url( 'admin.php?page=fluent-booking#/calendars/' . $calendar_id . '/slot-settings/' . $event_id . '/event-details' );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( isset( $event['title'] ) ? $event['title'] : '' ); ?></strong>
						<div><code><?php echo esc_html( sprintf( 'Event #%d / Calendar #%d', $event_id, $calendar_id ) ); ?></code></div>
						<div><a href="<?php echo esc_url( $link ); ?>" target="_blank"><?php esc_html_e( 'Open in Fluent Booking', 'kitmage-wallet' ); ?></a></div>
					</td>
					<td><label><input type="checkbox" name="rules[<?php echo esc_attr( $event_id ); ?>][enabled]" value="1" <?php checked( $settings['enabled'] ); ?> /> <?php esc_html_e( 'Enabled', 'kitmage-wallet' ); ?></label></td>
					<td><input type="number" min="0" step="1" name="rules[<?php echo esc_attr( $event_id ); ?>][credit_cost]" value="<?php echo esc_attr( $settings['credit_cost'] ); ?>" /></td>
					<td><input type="text" class="regular-text" name="rules[<?php echo esc_attr( $event_id ); ?>][allowed_buckets]" value="<?php echo esc_attr( implode( ',', $settings['allowed_buckets'] ) ); ?>" placeholder="nexus-consulting-prepaid,nexus-consulting-subscription" /></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Save Booking Event Rules', 'kitmage-wallet' ) ); ?>
			<p class="description"><?php echo esc_html( sprintf( __( 'Available Fund slugs: %s', 'kitmage-wallet' ), implode( ', ', array_map( static function( $bucket ) { return $bucket['slug']; }, $buckets ) ) ) ); ?></p>
		</form>
		<?php endif; ?>
	</div>
	<?php
}

function kitmage_wallet_handle_save_booking_event_rules() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to save booking event rules.', 'kitmage-wallet' ) );
	}

	check_admin_referer( 'kitmage_wallet_save_booking_event_rules' );

	$rules = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array();
	foreach ( $rules as $event_id => $rule ) {
		$event_id = (int) $event_id;
		if ( $event_id <= 0 || ! is_array( $rule ) ) {
			continue;
		}

		$enabled = ! empty( $rule['enabled'] ) ? 1 : 0;
		$cost    = kitmage_wallet_to_int( isset( $rule['credit_cost'] ) ? $rule['credit_cost'] : 0 );
		$buckets = kitmage_wallet_sanitize_allowed_buckets( isset( $rule['allowed_buckets'] ) ? $rule['allowed_buckets'] : '' );

		update_post_meta( $event_id, KITMAGE_WALLET_FB_META_ENABLED, $enabled );
		update_post_meta( $event_id, KITMAGE_WALLET_FB_META_COST, $cost );
		update_post_meta( $event_id, KITMAGE_WALLET_FB_META_ALLOWED_BUCKETS, $buckets );
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'kitmage-wallet-booking-rules', 'wallet_success' => rawurlencode( __( 'Booking event rules updated.', 'kitmage-wallet' ) ) ), admin_url( 'admin.php' ) ) );
	exit;
}
