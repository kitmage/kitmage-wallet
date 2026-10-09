<?php
/**
 * Wallet-aware rendering for Fluent Booking's Team Gutenberg block.
 *
 * The block stores selected calendar hosts and event IDs in calendarHosts.
 * Fluent Booking renders these event lists without using the individual-event
 * calendar HTML filter, so restrict the IDs before its render callback runs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Team block's frontend-only event filter.
 *
 * @return void
 */
function kitmage_wallet_register_fluent_booking_team_block_hooks() {
	add_filter( 'render_block_data', 'kitmage_wallet_filter_fluent_booking_team_block_data', 20, 1 );
}

/**
 * Limit a Team block's configured events to the current user's affordable ones.
 *
 * This runs after WordPress parses block attributes, but before Fluent Booking
 * builds host/event HTML and localizes event data for JavaScript. It never
 * updates the block attributes saved in post content.
 *
 * @param array $parsed_block Parsed block data from render_block_data.
 * @return array Filtered block data.
 */
function kitmage_wallet_filter_fluent_booking_team_block_data( $parsed_block ) {
	if ( ! is_array( $parsed_block ) || 'fluent-booking/team-management' !== ( $parsed_block['blockName'] ?? '' ) ) {
		return $parsed_block;
	}

	// Keep the Gutenberg editing interface unchanged.
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $parsed_block;
	}

	if ( ! function_exists( 'kitmage_wallet_fluent_booking_affordability' ) || ! class_exists( '\FluentBooking\App\Models\CalendarSlot' ) ) {
		return $parsed_block;
	}

	$hosts = $parsed_block['attrs']['calendarHosts'] ?? null;
	if ( ! is_array( $hosts ) || empty( $hosts ) ) {
		return $parsed_block;
	}

	$filtered_hosts = array();
	$user_id        = get_current_user_id();

	foreach ( $hosts as $host ) {
		if ( ! is_array( $host ) || empty( $host['id'] ) || empty( $host['events'] ) || ! is_array( $host['events'] ) ) {
			continue;
		}

		$calendar_id = absint( $host['id'] );
		if ( $calendar_id <= 0 ) {
			continue;
		}

		$selected_events = $host['events'];
		$all_events      = in_array( 'all', $selected_events, true );

		// Resolve "all" to explicit event IDs before checking per-event Credits.
		$events_query = \FluentBooking\App\Models\CalendarSlot::where( 'calendar_id', $calendar_id )
			->where( 'status', 'active' );

		if ( ! $all_events ) {
			$selected_ids = array_values( array_unique( array_filter( array_map( 'absint', $selected_events ) ) ) );
			if ( empty( $selected_ids ) ) {
				continue;
			}

			$events_query->whereIn( 'id', $selected_ids );
		}

		$allowed_ids = array();
		foreach ( $events_query->get() as $event ) {
			$event_id = absint( $event->id ?? 0 );
			if ( $event_id <= 0 ) {
				continue;
			}

			// The same check used by booking validation includes team-wallet resolution.
			$affordability = kitmage_wallet_fluent_booking_affordability( $event_id, $user_id );
			if ( ! empty( $affordability['allowed'] ) ) {
				$allowed_ids[] = $event_id;
			}
		}

		if ( empty( $allowed_ids ) ) {
			// The host has no bookable events for this user.
			continue;
		}

		$host['events']   = array_values( array_unique( $allowed_ids ) );
		$filtered_hosts[] = $host;
	}

	// An empty list makes Fluent Booking's Team render callback return ''.
	$parsed_block['attrs']['calendarHosts'] = $filtered_hosts;

	return $parsed_block;
}
