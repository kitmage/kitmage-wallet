<?php
/**
 * Regression tests for wallet_balance single- and multi-Fund rendering.
 *
 * Run: php tests/shortcodes-test.php
 */

define( 'ABSPATH', __DIR__ );

function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
	return array_merge( $defaults, $atts );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function wp_unslash( $value ) {
	return stripslashes( $value );
}
function kitmage_wallet_sanitize_bucket_slug( $slug ) {
	return trim( strtolower( preg_replace( '/[^a-z0-9-]+/', '-', (string) $slug ) ), '-' );
}
function kitmage_wallet_normalize_bucket_list( $buckets ) {
	$normalized = array();
	foreach ( $buckets as $bucket ) {
		$slug = kitmage_wallet_sanitize_bucket_slug( $bucket );
		if ( '' !== $slug && ! isset( $normalized[ $slug ] ) ) {
			$normalized[ $slug ] = $slug;
		}
	}
	return array_values( $normalized );
}
function kitmage_wallet_get_bucket_by_slug( $slug ) {
	return in_array( $slug, $GLOBALS['fund_registry'], true ) ? array( 'slug' => $slug ) : null;
}
function get_current_user_id() {
	return 41;
}
function kitmage_wallet_get_effective_wallet_user_id( $user_id ) {
	return 99;
}
function wallet_get_balance( $user_id, $fund ) {
	if ( 99 !== $user_id ) {
		throw new RuntimeException( 'Expected effective wallet owner to be used.' );
	}
	return isset( $GLOBALS['balances'][ $fund ] ) ? $GLOBALS['balances'][ $fund ] : 0;
}
function kitmage_wallet_to_int( $value ) {
	return max( 0, (int) $value );
}
function wallet_format_balance( $amount, $divide_by = 1, $decimals = 0 ) {
	return number_format( $amount / $divide_by, $decimals, '.', ',' );
}
function esc_html( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

$GLOBALS['fund_registry'] = array( 'prepaid', 'subscription', 'other' );
$GLOBALS['balances'] = array( 'prepaid' => 90, 'subscription' => 30, 'other' => 0 );

require dirname( __DIR__ ) . '/includes/shortcodes.php';

function assert_same( $expected, $actual, $description ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $description . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

assert_same( '90', kitmage_wallet_shortcode_balance( array( 'fund' => 'prepaid' ) ), 'single Fund' );
assert_same( '120', kitmage_wallet_shortcode_balance( array( 'fund' => 'prepaid,subscription' ) ), 'combined Funds' );
assert_same( '120', kitmage_wallet_shortcode_balance( array( 'fund' => 'prepaid, subscription' ) ), 'whitespace in list' );
assert_same( '120', kitmage_wallet_shortcode_balance( array( 'fund' => 'prepaid,,subscription' ) ), 'empty list item ignored' );
assert_same( '90', kitmage_wallet_shortcode_balance( array( 'fund' => 'prepaid,prepaid' ) ), 'duplicates counted once' );
assert_same( '90', kitmage_wallet_shortcode_balance( array( 'fund' => 'unknown,prepaid' ) ), 'unknown Fund ignored' );
assert_same( '', kitmage_wallet_shortcode_balance( array( 'fund' => 'unknown' ) ), 'unknown Fund returns blank' );
assert_same( '', kitmage_wallet_shortcode_balance( array( 'fund' => '' ) ), 'empty Fund returns blank' );
assert_same( '120', kitmage_wallet_shortcode_balance( array( 'bucket' => 'prepaid,subscription' ) ), 'legacy bucket alias' );
assert_same(
	'2.0 Hours',
	kitmage_wallet_shortcode_balance( array( 'fund' => 'prepaid,subscription', 'divide_by' => '60', 'decimals' => '1', 'suffix' => 'Hours' ) ),
	'divide, format and suffix combined balance'
);
assert_same(
	'120 Credits',
	kitmage_wallet_shortcode_balance( array( 'fund' => 'prepaid,subscription', 'suffix' => '<b>Credits</b>' ) ),
	'suffix remains sanitized'
);

echo "wallet_balance shortcode tests passed\n";
