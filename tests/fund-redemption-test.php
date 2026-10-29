<?php
/**
 * Regression checks for Fund redemption settings.
 *
 * Run: php tests/fund-redemption-test.php
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['options'] = array();
function __( $text, $domain = '' ) { return $text; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['options'] ) ? $GLOBALS['options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function sanitize_textarea_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function esc_url_raw( $url ) {
	$url = trim( (string) $url );
	return preg_match( '~^https?://~i', $url ) ? $url : '';
}
function kitmage_wallet_sanitize_bucket_slug( $slug ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $slug ) ), '-' ); }
class WP_Error {
	private $code;
	public function __construct( $code, $message ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
require dirname( __DIR__ ) . '/includes/buckets.php';

function assert_equals( $expected, $actual, $description ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $description . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

assert_equals(
	true,
	kitmage_wallet_upsert_bucket( array(
		'slug' => 'coaching',
		'label' => 'Coaching Minutes',
		'description' => 'For coaching sessions',
		'redeem_url' => 'https://example.org/coaching',
		'redeem_instructions' => 'Choose a session.' . "\n" . 'Log in first.',
		'redeem_button_text' => 'Book coaching'
	) ),
	'create Fund with directions'
);
$fund = kitmage_wallet_get_bucket_by_slug( 'coaching' );
assert_equals( 'https://example.org/coaching', $fund['redeem_url'], 'persist redemption URL' );
assert_equals( 'Choose a session.' . "\n" . 'Log in first.', $fund['redeem_instructions'], 'persist multi-line instructions' );
assert_equals( 'Book coaching', $fund['redeem_button_text'], 'persist button text' );

kitmage_wallet_upsert_bucket( array(
	'slug' => 'coaching',
	'label' => 'Updated Coaching',
	'redeem_url' => 'javascript:alert(1)',
	'redeem_instructions' => '<strong>Only text allowed</strong>',
	'redeem_button_text' => '<script>Bad</script>Redeem'
), 'coaching' );
$fund = kitmage_wallet_get_bucket_by_slug( 'coaching' );
assert_equals( '', $fund['redeem_url'], 'unsafe URL rejected' );
assert_equals( 'Only text allowed', $fund['redeem_instructions'], 'instructions sanitized' );
assert_equals( 'BadRedeem', $fund['redeem_button_text'], 'button sanitized' );

$GLOBALS['options'][ KITMAGE_WALLET_BUCKETS_OPTION_KEY ][] = array( 'slug' => 'legacy', 'label' => 'Legacy Fund', 'description' => '' );
$legacy = kitmage_wallet_get_bucket_by_slug( 'legacy' );
assert_equals( '', $legacy['redeem_url'], 'old Fund defaults to no URL' );
assert_equals( '', $legacy['redeem_instructions'], 'old Fund defaults to no instructions' );
assert_equals( '', $legacy['redeem_button_text'], 'old Fund defaults to no button label' );

echo "fund redemption tests passed\n";
