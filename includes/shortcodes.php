<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function aspen_wallet_register_shortcode_hooks() {
	add_shortcode( 'wallet_balance', 'aspen_wallet_shortcode_balance' );
	add_shortcode( 'wallet_if', 'aspen_wallet_shortcode_if' );
	add_shortcode( 'wallet_booking', 'aspen_wallet_shortcode_booking' );
}

/**
 * Sanitize shortcode fallback content while still allowing nested shortcodes.
 *
 * @param mixed $fallback Raw fallback content.
 * @return string
 */
function aspen_wallet_sanitize_shortcode_fallback( $fallback ) {
	if ( is_array( $fallback ) || is_object( $fallback ) ) {
		return '';
	}

	$fallback = wp_unslash( (string) $fallback );
	$fallback = wp_kses_post( $fallback );

	return trim( $fallback );
}

function aspen_wallet_shortcode_balance( $atts ) {
	$raw_atts = (array) $atts;
	$atts = shortcode_atts(
		array(
			'fund'      => '',
			// Deprecated alias retained for published shortcode compatibility.
			'bucket'    => '',
			'divide_by' => 1,
			'decimals'  => 0,
			'suffix'    => '',
		),
		$raw_atts,
		'wallet_balance'
	);

	$fund_input = '' !== (string) $atts['fund'] ? $atts['fund'] : $atts['bucket'];
	$fund       = aspen_wallet_sanitize_bucket_slug( $fund_input );
	if ( '' === $fund || ! aspen_wallet_get_bucket_by_slug( $fund ) ) {
		return '';
	}

	$user_id        = get_current_user_id();
	$wallet_user_id = aspen_wallet_get_effective_wallet_user_id( $user_id );
	$amount         = wallet_get_balance( $wallet_user_id, $fund );

	$divide_by = aspen_wallet_to_int( $atts['divide_by'] );
	$decimals  = min( 6, aspen_wallet_to_int( $atts['decimals'] ) );

	if ( $divide_by <= 1 ) {
		$output = (string) $amount;
	} else {
		$output = wallet_format_balance( $amount, $divide_by, $decimals );
	}

	$suffix = sanitize_text_field( wp_unslash( (string) $atts['suffix'] ) );
	if ( '' !== $suffix ) {
		$output .= ' ' . $suffix;
	}

	return esc_html( $output );
}

function aspen_wallet_shortcode_if( $atts, $content = '' ) {
	$raw_atts = (array) $atts;
	$atts = shortcode_atts(
		array(
			'fund'     => '',
			// Deprecated alias retained for published shortcode compatibility.
			'bucket'   => '',
			'min'      => null,
			'max'      => null,
			'equals'   => null,
			'fallback' => '',
		),
		$raw_atts,
		'wallet_if'
	);

	$user_id        = get_current_user_id();
	$wallet_user_id = aspen_wallet_get_effective_wallet_user_id( $user_id );

	$has_rule   = false;
	$conditions = array();

	if ( null !== $atts['min'] && '' !== $atts['min'] ) {
		$conditions['min'] = aspen_wallet_to_int( $atts['min'] );
		$has_rule          = true;
	}

	if ( null !== $atts['max'] && '' !== $atts['max'] ) {
		$conditions['max'] = aspen_wallet_to_int( $atts['max'] );
		$has_rule          = true;
	}

	if ( null !== $atts['equals'] && '' !== $atts['equals'] ) {
		$conditions['equals'] = aspen_wallet_to_int( $atts['equals'] );
		$has_rule             = true;
	}

	$fund_input = '' !== (string) $atts['fund'] ? $atts['fund'] : $atts['bucket'];
	$fund_input = sanitize_text_field( wp_unslash( (string) $fund_input ) );
	$fund_input = trim( $fund_input );

	if ( '' === $fund_input ) {
		$balance = 0;
		foreach ( aspen_wallet_get_buckets() as $bucket ) {
			if ( empty( $bucket['slug'] ) ) {
				continue;
			}
			$balance += wallet_get_balance( $wallet_user_id, $bucket['slug'] );
		}
	} else {
		$raw_buckets = explode( ',', $fund_input );
		$buckets     = aspen_wallet_normalize_bucket_list( $raw_buckets );

		if ( empty( $buckets ) ) {
			$fallback = aspen_wallet_sanitize_shortcode_fallback( $atts['fallback'] );
			return '' !== $fallback ? do_shortcode( $fallback ) : '';
		}

		$balance = 0;
		foreach ( $buckets as $bucket ) {
			if ( ! aspen_wallet_get_bucket_by_slug( $bucket ) ) {
				continue;
			}
			$balance += wallet_get_balance( $wallet_user_id, $bucket );
		}
	}

	if ( ! $has_rule ) {
		$match = $balance > 0;
	} else {
		$match = true;

		if ( isset( $conditions['min'] ) && $balance < $conditions['min'] ) {
			$match = false;
		}

		if ( isset( $conditions['max'] ) && $balance > $conditions['max'] ) {
			$match = false;
		}

		if ( isset( $conditions['equals'] ) && $balance !== $conditions['equals'] ) {
			$match = false;
		}
	}

	if ( $match ) {
		return do_shortcode( wp_kses_post( (string) $content ) );
	}

	$fallback = aspen_wallet_sanitize_shortcode_fallback( $atts['fallback'] );
	return '' !== $fallback ? do_shortcode( $fallback ) : '';
}

function aspen_wallet_shortcode_booking( $atts ) {
	$atts = shortcode_atts(
		array(
			'calendar_id' => 0,
			'event_id'    => 0,
			'fallback'    => '',
		),
		(array) $atts,
		'wallet_booking'
	);

	$calendar_id = aspen_wallet_to_int( $atts['calendar_id'] );
	$event_id    = aspen_wallet_to_int( $atts['event_id'] );
	$fallback    = aspen_wallet_sanitize_shortcode_fallback( $atts['fallback'] );

	if ( $calendar_id <= 0 || $event_id <= 0 ) {
		return '';
	}

	$check = aspen_wallet_fluent_booking_affordability( $event_id, get_current_user_id() );
	if ( empty( $check['allowed'] ) ) {
		$blocked = '' !== $fallback ? $fallback : ( isset( $check['reason'] ) ? aspen_wallet_sanitize_shortcode_fallback( $check['reason'] ) : '' );
		return '' !== $blocked ? do_shortcode( $blocked ) : '';
	}

	$booking_shortcode = sprintf(
		'[fluent_booking id="%d"]',
		$event_id
	);
	$output = do_shortcode( $booking_shortcode );

	return apply_filters( 'aspen_wallet_booking_shortcode_output', $output, $event_id, $fallback, get_current_user_id() );
}
