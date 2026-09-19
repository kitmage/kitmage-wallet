<?php
/**
 * Shared sanitizers and balance helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert mixed value into safe integer >= 0.
 *
 * @param mixed $value Raw value.
 * @return int
 */
function kitmage_wallet_to_int( $value ) {
	if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) ) {
		return 0;
	}

	$value = is_string( $value ) ? wp_unslash( $value ) : $value;
	$value = is_string( $value ) ? sanitize_text_field( $value ) : $value;

	return max( 0, (int) $value );
}

/**
 * Parse a strict integer amount from raw input.
 *
 * Rejects floats, scientific notation, and ambiguous string values.
 *
 * @param mixed $raw            Raw input.
 * @param bool  $allow_negative Allow negative integers.
 * @return int|WP_Error
 */
function kitmage_wallet_parse_int_amount( $raw, $allow_negative = false ) {
	if ( is_bool( $raw ) || is_array( $raw ) || is_object( $raw ) || null === $raw ) {
		return new WP_Error( 'invalid_amount', __( 'Amount must be a whole integer.', 'kitmage-wallet' ) );
	}

	if ( is_int( $raw ) ) {
		$value = $raw;
	} else {
		$raw = is_string( $raw ) ? wp_unslash( $raw ) : $raw;
		$raw = is_string( $raw ) ? trim( sanitize_text_field( $raw ) ) : (string) $raw;

		if ( '' === $raw || ! preg_match( '/^-?\d+$/', $raw ) ) {
			return new WP_Error( 'invalid_amount', __( 'Amount must be a whole integer.', 'kitmage-wallet' ) );
		}

		$value = (int) $raw;
	}

	if ( ! $allow_negative && $value < 0 ) {
		return new WP_Error( 'invalid_amount', __( 'Amount cannot be negative.', 'kitmage-wallet' ) );
	}

	return $value;
}

/**
 * Sanitize bucket slug.
 *
 * @param string $slug Raw slug.
 * @return string
 */
function kitmage_wallet_sanitize_bucket_slug( $slug ) {
	return sanitize_title( wp_unslash( (string) $slug ) );
}

/**
 * Build user meta key for bucket.
 *
 * @param string $bucket Bucket slug.
 * @return string
 */
function kitmage_wallet_bucket_meta_key( $bucket ) {
	$bucket = kitmage_wallet_sanitize_bucket_slug( $bucket );
	return '_user_wallet_bucket_' . str_replace( '-', '_', $bucket );
}

/**
 * Normalize and de-duplicate a bucket list while preserving order.
 *
 * @param mixed $buckets Bucket list.
 * @return string[]
 */
function kitmage_wallet_normalize_bucket_list( $buckets ) {
	if ( ! is_array( $buckets ) ) {
		$buckets = array();
	}

	$normalized = array();

	foreach ( $buckets as $bucket ) {
		$slug = kitmage_wallet_sanitize_bucket_slug( $bucket );

		if ( '' === $slug || isset( $normalized[ $slug ] ) ) {
			continue;
		}

		$normalized[ $slug ] = $slug;
	}

	return array_values( $normalized );
}


/**
 * Get an integer property from a Teams for WooCommerce Memberships team object.
 *
 * @param mixed    $team    Team object or ID.
 * @param string[] $methods Candidate getter methods.
 * @return int
 */
function kitmage_wallet_get_team_int_property( $team, $methods ) {
	foreach ( $methods as $method ) {
		if ( is_object( $team ) && is_callable( array( $team, $method ) ) ) {
			$value = $team->{$method}();
			$value = is_object( $value ) && isset( $value->ID ) ? $value->ID : $value;
			$value = absint( $value );

			if ( $value > 0 ) {
				return $value;
			}
		}
	}

	return 0;
}

/**
 * Resolve a Teams for WooCommerce Memberships team ID from a team object.
 *
 * @param mixed $team Team object or ID.
 * @return int
 */
function kitmage_wallet_get_team_id( $team ) {
	if ( is_numeric( $team ) ) {
		return absint( $team );
	}

	$team_id = kitmage_wallet_get_team_int_property(
		$team,
		array( 'get_id', 'get_team_id', 'get_post_id' )
	);

	if ( $team_id > 0 ) {
		return $team_id;
	}

	if ( is_object( $team ) ) {
		foreach ( array( 'id', 'ID', 'team_id', 'post_id' ) as $property ) {
			if ( isset( $team->{$property} ) ) {
				$team_id = absint( $team->{$property} );

				if ( $team_id > 0 ) {
					return $team_id;
				}
			}
		}
	}

	return 0;
}

/**
 * Resolve the owning user ID for a Teams for WooCommerce Memberships team.
 *
 * @param mixed $team Team object or ID.
 * @return int
 */
function kitmage_wallet_get_team_owner_id( $team ) {
	$owner_id = kitmage_wallet_get_team_int_property(
		$team,
		array( 'get_owner_id', 'get_owner_user_id', 'get_user_id' )
	);

	if ( $owner_id > 0 ) {
		return $owner_id;
	}

	$team_id = kitmage_wallet_get_team_id( $team );
	if ( $team_id <= 0 ) {
		return 0;
	}

	return absint( get_post_field( 'post_author', $team_id ) );
}

/**
 * Normalize Teams for WooCommerce Memberships query results to a plain team list.
 *
 * @param mixed $teams Raw Teams API result.
 * @return array<int,mixed>
 */
function kitmage_wallet_normalize_teams_result( $teams ) {
	if ( empty( $teams ) ) {
		return array();
	}

	if ( is_object( $teams ) && is_callable( array( $teams, 'get_teams' ) ) ) {
		$teams = $teams->get_teams();
	}

	if ( is_object( $teams ) ) {
		$teams = array( $teams );
	}

	if ( is_array( $teams ) && isset( $teams['teams'] ) && is_array( $teams['teams'] ) ) {
		$teams = $teams['teams'];
	}

	if ( ! is_array( $teams ) ) {
		return array();
	}

	return array_values( $teams );
}

/**
 * Get Teams for WooCommerce Memberships teams associated with a user.
 *
 * @param int $user_id User ID.
 * @return array<int,mixed>
 */
function kitmage_wallet_get_user_teams( $user_id ) {
	$user_id = absint( $user_id );

	if ( $user_id <= 0 || ! function_exists( 'wc_memberships_for_teams_get_teams' ) ) {
		return array();
	}

	$teams = wc_memberships_for_teams_get_teams(
		$user_id,
		array(
			'role' => array( 'owner', 'manager', 'member' ),
		)
	);

	return kitmage_wallet_normalize_teams_result( $teams );
}

/**
 * Resolve the wallet owner that should be used for a user.
 *
 * Team members use their Teams for WooCommerce Memberships team owner's wallet.
 * Users without a team, team owners, and users on sites without Teams keep using
 * their own wallet. If a user belongs to multiple teams, the first team with a
 * resolvable owner is used by default and can be customized by filter.
 *
 * @param mixed $user_id User ID.
 * @return int
 */
function kitmage_wallet_get_effective_wallet_user_id( $user_id ) {
	$user_id = absint( $user_id );

	if ( $user_id <= 0 ) {
		return 0;
	}

	$teams          = kitmage_wallet_get_user_teams( $user_id );
	$wallet_user_id = $user_id;
	$selected_team  = null;

	foreach ( $teams as $team ) {
		$owner_id = kitmage_wallet_get_team_owner_id( $team );

		if ( $owner_id <= 0 ) {
			continue;
		}

		$wallet_user_id = $owner_id;
		$selected_team  = $team;
		break;
	}

	/**
	 * Filters the user ID whose wallet should be used for a given user.
	 *
	 * @param int        $wallet_user_id Resolved wallet owner user ID.
	 * @param int        $user_id        Original user ID.
	 * @param mixed|null $selected_team  Selected team object or ID.
	 * @param array      $teams          All team objects returned for the user.
	 */
	return absint( apply_filters( 'kitmage_wallet_effective_wallet_user_id', $wallet_user_id, $user_id, $selected_team, $teams ) );
}

/**
 * Get a bucket balance.
 *
 * @param mixed $user_id User ID.
 * @param mixed $bucket  Bucket slug.
 * @return int
 */
function wallet_get_balance( $user_id, $bucket ) {
	$user_id = (int) $user_id;
	$bucket  = kitmage_wallet_sanitize_bucket_slug( $bucket );

	if ( $user_id <= 0 || '' === $bucket ) {
		return 0;
	}

	$meta_key = kitmage_wallet_bucket_meta_key( $bucket );
	$balance  = get_user_meta( $user_id, $meta_key, true );

	return kitmage_wallet_to_int( $balance );
}

/**
 * Set a bucket balance.
 *
 * @param mixed $user_id User ID.
 * @param mixed $bucket  Bucket slug.
 * @param mixed $amount  Balance to set.
 * @return bool
 */
function wallet_set_balance( $user_id, $bucket, $amount ) {
	$user_id = (int) $user_id;
	$bucket  = kitmage_wallet_sanitize_bucket_slug( $bucket );

	if ( $user_id <= 0 || '' === $bucket ) {
		return false;
	}

	$meta_key = kitmage_wallet_bucket_meta_key( $bucket );
	$parsed   = kitmage_wallet_parse_int_amount( $amount );
	$amount   = is_wp_error( $parsed ) ? 0 : (int) $parsed;
	$old      = wallet_get_balance( $user_id, $bucket );
	$updated  = false !== update_user_meta( $user_id, $meta_key, $amount );

	if ( $updated && $old !== $amount ) {
		do_action( 'wallet_balance_updated', $user_id, $bucket, $old, $amount, 'set_balance' );
	}

	return $updated;
}

/**
 * Add credits to a bucket.
 *
 * @param mixed $user_id User ID.
 * @param mixed $bucket  Bucket slug.
 * @param mixed $amount  Amount to add.
 * @return bool
 */
function wallet_add_balance( $user_id, $bucket, $amount ) {
	$user_id = (int) $user_id;
	$amount  = kitmage_wallet_to_int( $amount );

	if ( $user_id <= 0 ) {
		return false;
	}

	if ( $amount <= 0 ) {
		return true;
	}

	$current = wallet_get_balance( $user_id, $bucket );
	return wallet_set_balance( $user_id, $bucket, $current + $amount );
}

/**
 * Determine if a user can afford a debit from a bucket list.
 *
 * @param mixed $user_id User ID.
 * @param mixed $buckets Ordered list of allowed bucket slugs.
 * @param mixed $amount  Required amount.
 * @return bool
 */
function wallet_can_afford( $user_id, $buckets, $amount ) {
	$user_id = (int) $user_id;
	$amount  = kitmage_wallet_to_int( $amount );
	$buckets = kitmage_wallet_normalize_bucket_list( $buckets );

	if ( $user_id <= 0 || empty( $buckets ) ) {
		return false;
	}

	if ( 0 === $amount ) {
		return true;
	}

	$total = 0;

	foreach ( $buckets as $bucket ) {
		$total += wallet_get_balance( $user_id, $bucket );

		if ( $total >= $amount ) {
			return true;
		}
	}

	return false;
}

/**
 * Debit credits from buckets in strict priority order.
 *
 * @param mixed $user_id User ID.
 * @param mixed $buckets Ordered bucket slugs.
 * @param mixed $amount  Debit amount.
 * @return array<string,mixed>
 */
function wallet_debit_balances( $user_id, $buckets, $amount ) {
	$user_id = (int) $user_id;
	$amount  = kitmage_wallet_to_int( $amount );
	$buckets = kitmage_wallet_normalize_bucket_list( $buckets );

	$result = array(
		'success' => false,
		'requested_amount' => $amount,
		'debited_amount' => 0,
		'remaining_amount' => $amount,
		'deltas' => array(),
	);

	if ( $user_id <= 0 || empty( $buckets ) ) {
		return $result;
	}

	if ( 0 === $amount ) {
		$result['success']          = true;
		$result['remaining_amount'] = 0;
		return $result;
	}

	if ( ! wallet_can_afford( $user_id, $buckets, $amount ) ) {
		return $result;
	}

	$remaining = $amount;

	foreach ( $buckets as $bucket ) {
		if ( $remaining <= 0 ) {
			break;
		}

		$current = wallet_get_balance( $user_id, $bucket );
		if ( $current <= 0 ) {
			continue;
		}

		$debit = min( $current, $remaining );
		$next  = $current - $debit;

		if ( ! wallet_set_balance( $user_id, $bucket, $next ) ) {
			return $result;
		}

		$result['deltas'][ $bucket ] = 0 - $debit;
		$remaining                  -= $debit;
	}

	$result['debited_amount']   = $amount - $remaining;
	$result['remaining_amount'] = $remaining;
	$result['success']          = ( 0 === $remaining );

	return $result;
}

/**
 * Format a raw integer balance for display.
 *
 * @param mixed $amount    Raw amount.
 * @param mixed $divide_by Divisor.
 * @param mixed $decimals  Decimal precision.
 * @return string
 */
function wallet_format_balance( $amount, $divide_by = 1, $decimals = 0 ) {
	$amount    = kitmage_wallet_to_int( $amount );
	$divide_by = kitmage_wallet_to_int( $divide_by );
	$decimals  = min( 6, kitmage_wallet_to_int( $decimals ) );

	if ( $divide_by <= 0 ) {
		$divide_by = 1;
	}

	$value = $amount / $divide_by;

	return number_format_i18n( $value, $decimals );
}

// Back-compat wrappers.
function kitmage_wallet_get_balance( $user_id, $bucket ) {
	return wallet_get_balance( $user_id, $bucket );
}

function kitmage_wallet_set_balance( $user_id, $bucket, $amount ) {
	return wallet_set_balance( $user_id, $bucket, $amount );
}

function kitmage_wallet_add_balance( $user_id, $bucket, $amount ) {
	return wallet_add_balance( $user_id, $bucket, $amount );
}
