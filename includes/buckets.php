<?php
/**
 * Bucket config helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KITMAGE_WALLET_BUCKETS_OPTION_KEY = 'kitmage_wallet_buckets';

function kitmage_wallet_get_buckets() {
	$buckets = get_option( KITMAGE_WALLET_BUCKETS_OPTION_KEY, null );
	if ( null === $buckets ) {
		$buckets = get_option( 'wallet_buckets', array() );
	}
	if ( ! is_array( $buckets ) ) {
		return array();
	}

	$clean = array();
	foreach ( $buckets as $bucket ) {
		if ( ! is_array( $bucket ) ) {
			continue;
		}

		$slug = isset( $bucket['slug'] ) ? kitmage_wallet_sanitize_bucket_slug( $bucket['slug'] ) : '';
		if ( '' === $slug ) {
			continue;
		}

		$clean[] = array(
			'label'       => isset( $bucket['label'] ) ? sanitize_text_field( $bucket['label'] ) : $slug,
			'slug'        => $slug,
			'description' => isset( $bucket['description'] ) ? sanitize_textarea_field( $bucket['description'] ) : '',
		);
	}

	return $clean;
}

function kitmage_wallet_get_bucket_by_slug( $slug ) {
	$slug = kitmage_wallet_sanitize_bucket_slug( $slug );
	if ( '' === $slug ) {
		return null;
	}

	foreach ( kitmage_wallet_get_buckets() as $bucket ) {
		if ( $slug === $bucket['slug'] ) {
			return $bucket;
		}
	}

	return null;
}

function kitmage_wallet_upsert_bucket( $bucket, $original_slug = '' ) {
	$bucket = is_array( $bucket ) ? $bucket : array();

	$label       = isset( $bucket['label'] ) ? sanitize_text_field( $bucket['label'] ) : '';
	$description = isset( $bucket['description'] ) ? sanitize_textarea_field( $bucket['description'] ) : '';
	$slug        = isset( $bucket['slug'] ) ? kitmage_wallet_sanitize_bucket_slug( $bucket['slug'] ) : '';
	$original_slug = kitmage_wallet_sanitize_bucket_slug( $original_slug );

	if ( '' === $slug ) {
		return new WP_Error( 'invalid_slug', __( 'Fund slug is required.', 'kitmage-wallet' ) );
	}

	if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
		return new WP_Error( 'invalid_slug_format', __( 'Fund slug must be lowercase kebab-case (letters, numbers, and hyphens only).', 'kitmage-wallet' ) );
	}

	if ( '' === $label ) {
		$label = $slug;
	}

	$buckets    = kitmage_wallet_get_buckets();
	$next       = array();
	$did_update = false;

	foreach ( $buckets as $existing ) {
		if ( $existing['slug'] === $original_slug && '' !== $original_slug ) {
			$next[]     = array(
				'label'       => $label,
				'slug'        => $slug,
				'description' => $description,
			);
			$did_update = true;
			continue;
		}

		if ( $existing['slug'] === $slug && $existing['slug'] !== $original_slug ) {
			return new WP_Error( 'duplicate_slug', __( 'Fund slug already exists.', 'kitmage-wallet' ) );
		}

		$next[] = $existing;
	}

	if ( ! $did_update ) {
		$next[] = array(
			'label'       => $label,
			'slug'        => $slug,
			'description' => $description,
		);
	}

	update_option( KITMAGE_WALLET_BUCKETS_OPTION_KEY, array_values( $next ), false );
	return true;
}

function kitmage_wallet_delete_bucket( $slug ) {
	$slug = kitmage_wallet_sanitize_bucket_slug( $slug );
	if ( '' === $slug ) {
		return new WP_Error( 'invalid_slug', __( 'Fund slug is required.', 'kitmage-wallet' ) );
	}

	$references = kitmage_wallet_get_bucket_references( $slug );
	if ( ! empty( $references['product_grants'] ) || ! empty( $references['event_rules'] ) ) {
		return new WP_Error( 'bucket_in_use', __( 'Fund is referenced by wallet product grants or booking event rules.', 'kitmage-wallet' ) );
	}

	$buckets = kitmage_wallet_get_buckets();
	$next    = array();
	$found   = false;

	foreach ( $buckets as $bucket ) {
		if ( $bucket['slug'] === $slug ) {
			$found = true;
			continue;
		}
		$next[] = $bucket;
	}

	if ( ! $found ) {
		return new WP_Error( 'not_found', __( 'Fund not found.', 'kitmage-wallet' ) );
	}

	update_option( KITMAGE_WALLET_BUCKETS_OPTION_KEY, array_values( $next ), false );
	return true;
}

function kitmage_wallet_get_bucket_references( $slug ) {
	global $wpdb;

	$slug = kitmage_wallet_sanitize_bucket_slug( $slug );
	if ( '' === $slug ) {
		return array();
	}

	$references = array(
		'product_grants' => array(),
		'event_rules'    => array(),
	);

	$product_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			KITMAGE_WALLET_PRODUCT_GRANTS_META_KEY
		),
		ARRAY_A
	);

	foreach ( $product_rows as $row ) {
		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		if ( $post_id <= 0 ) {
			continue;
		}

		$grants = maybe_unserialize( $row['meta_value'] );
		$grants = kitmage_wallet_woo_normalize_grants( $grants );

		foreach ( $grants as $grant ) {
			$grant_bucket = isset( $grant['bucket'] ) ? kitmage_wallet_sanitize_bucket_slug( $grant['bucket'] ) : '';
			if ( $grant_bucket === $slug ) {
				$references['product_grants'][] = $post_id;
				break;
			}
		}
	}

	$event_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			KITMAGE_WALLET_FB_META_ALLOWED_BUCKETS
		),
		ARRAY_A
	);

	foreach ( $event_rows as $row ) {
		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		if ( $post_id <= 0 ) {
			continue;
		}

		$allowed_buckets = maybe_unserialize( $row['meta_value'] );
		$allowed_buckets = kitmage_wallet_normalize_bucket_list( is_array( $allowed_buckets ) ? $allowed_buckets : explode( ',', (string) $allowed_buckets ) );

		if ( in_array( $slug, $allowed_buckets, true ) ) {
			$references['event_rules'][] = $post_id;
		}
	}

	$references['product_grants'] = array_values( array_unique( array_map( 'intval', $references['product_grants'] ) ) );
	$references['event_rules']    = array_values( array_unique( array_map( 'intval', $references['event_rules'] ) ) );

	return $references;
}
