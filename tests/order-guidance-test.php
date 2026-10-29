<?php
/**
 * Regression checks for the post-purchase redemption guidance.
 *
 * Run: php tests/order-guidance-test.php
 */
define( 'ABSPATH', __DIR__ );

function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ); }
function kitmage_wallet_sanitize_bucket_slug( $value ) { return sanitize_key( $value ); }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html_e( $text, $domain = '' ) { echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function wc_clean( $value ) { return trim( $value ); }
function get_current_user_id() { return $GLOBALS['current_user']; }
function kitmage_wallet_woo_resolve_order_user_id( $order ) { return $order->wallet_user; }
function kitmage_wallet_woo_get_resolved_item_grants( $item ) { return $item->live_grants; }
function kitmage_wallet_get_bucket_by_slug( $slug ) { return isset( $GLOBALS['funds'][ $slug ] ) ? $GLOBALS['funds'][ $slug ] : null; }
function wc_get_order( $order_id ) { return isset( $GLOBALS['orders'][ $order_id ] ) ? $GLOBALS['orders'][ $order_id ] : null; }

class WC_Order_Item_Product {
	public $live_grants;
	private $quantity;
	private $meta = array();

	public function __construct( $grants = array(), $quantity = 1 ) {
		$this->live_grants = $grants;
		$this->quantity = $quantity;
	}
	public function get_quantity() { return $this->quantity; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function add_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ] = $value; }
}

class WC_Order {
	private $id;
	private $items;
	private $meta;
	private $status;
	private $paid;
	private $customer_id;
	private $order_key;
	public $wallet_user;

	public function __construct( $id, $items, $applied = array(), $status = 'processing', $paid = true, $customer_id = 4, $wallet_user = 4 ) {
		$this->id = $id;
		$this->items = $items;
		$this->meta = array( '_kitmage_wallet_one_time_grants_applied_details' => $applied );
		$this->status = $status;
		$this->paid = $paid;
		$this->customer_id = $customer_id;
		$this->wallet_user = $wallet_user;
		$this->order_key = 'secret-' . $id;
	}
	public function get_id() { return $this->id; }
	public function get_items( $type = '' ) { return $this->items; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
	public function is_paid() { return $this->paid; }
	public function get_customer_id() { return $this->customer_id; }
	public function get_order_key() { return $this->order_key; }
}

require dirname( __DIR__ ) . '/includes/order-guidance.php';
$GLOBALS['current_user'] = 4;
$GLOBALS['funds'] = array(
	'coaching' => array( 'label' => 'Coaching', 'redeem_url' => 'https://example.org/coaching', 'redeem_instructions' => 'Schedule your coaching session.', 'redeem_button_text' => 'Book coaching' ),
	'premium'  => array( 'label' => 'Premium', 'redeem_url' => 'https://example.org/premium', 'redeem_instructions' => 'Choose a premium appointment.', 'redeem_button_text' => 'Book premium' ),
);

function assert_equals( $expected, $actual, $description ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $description . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}
function assert_has( $text, $html, $description ) {
	if ( false === strpos( $html, $text ) ) {
		fwrite( STDERR, 'Missing ' . $description . ': ' . $text . "\n" );
		exit( 1 );
	}
}
function assert_lacks( $text, $html, $description ) {
	if ( false !== strpos( $html, $text ) ) {
		fwrite( STDERR, 'Unexpected ' . $description . ': ' . $text . "\n" );
		exit( 1 );
	}
}
function render_order( $order ) {
	ob_start();
	$result = kitmage_wallet_order_guidance_render( $order );
	return array( $result, ob_get_clean() );
}

$one_time = array( array( 'bucket' => 'coaching', 'amount' => 30, 'type' => 'one_time_grant' ) );
$premium = array( array( 'bucket' => 'premium', 'amount' => 45, 'type' => 'one_time_grant' ) );
$subscription = array( array( 'bucket' => 'premium', 'amount' => 90, 'type' => 'subscription_reset' ) );
$item = new WC_Order_Item_Product( $one_time );
kitmage_wallet_snapshot_order_item_grants( $item, '', array(), null );
$item->live_grants = $premium;
assert_equals( $one_time, kitmage_wallet_order_guidance_item_grants( $item ), 'purchase-time snapshot survives product edits' );

$empty_item = new WC_Order_Item_Product( array() );
kitmage_wallet_snapshot_order_item_grants( $empty_item, '', array(), null );
$empty_item->live_grants = $premium;
assert_equals( array(), kitmage_wallet_order_guidance_item_grants( $empty_item ), 'no retroactive grant guidance' );

$order = new WC_Order(
	1,
	array( $item, new WC_Order_Item_Product( $one_time ), new WC_Order_Item_Product( $premium ) ),
	array( array( 'bucket' => 'coaching', 'amount' => 30 ), array( 'bucket' => 'premium', 'amount' => 45 ) )
);
$funds = kitmage_wallet_order_guidance_funds( $order );
assert_equals( array( 'coaching', 'premium' ), array_keys( $funds ), 'deduplicates Funds within mixed orders' );
assert_equals( true, $funds['coaching']['applied'], 'applied Fund recognized' );

list( $shown, $html ) = render_order( $order );
assert_equals( true, $shown, 'card rendered' );
assert_has( 'Schedule your coaching session.', $html, 'custom coaching instructions' );
assert_has( 'https://example.org/coaching', $html, 'coaching redemption URL' );
assert_has( 'https://example.org/premium', $html, 'premium redemption URL' );
assert_equals( 1, substr_count( $html, '<h3>Coaching</h3>' ), 'one heading for duplicate Fund' );

list( $shown, $html ) = render_order( new WC_Order( 2, array( new WC_Order_Item_Product( $one_time ) ), array(), 'pending', false ) );
assert_has( 'once payment has been confirmed', $html, 'pending purchase message' );
assert_lacks( 'href=', $html, 'no unpaid redemption CTA' );

list( $shown, $html ) = render_order( new WC_Order( 3, array( new WC_Order_Item_Product( $one_time ) ), array(), 'refunded' ) );
assert_has( 'not available to redeem', $html, 'refunded purchase message' );
assert_lacks( 'href=', $html, 'no refunded redemption CTA' );

list( $shown, $html ) = render_order( new WC_Order( 4, array( new WC_Order_Item_Product( $one_time ) ) ) );
assert_has( 'not been confirmed', $html, 'still-processing message' );
assert_lacks( 'href=', $html, 'no unconfirmed redemption CTA' );

list( $shown, $html ) = render_order( new WC_Order( 5, array( new WC_Order_Item_Product( $one_time ) ), array(), 'processing', true, 0, 0 ) );
assert_has( 'could not link this purchase', $html, 'unmapped account message' );
assert_lacks( 'href=', $html, 'no unmapped redemption CTA' );

list( $shown, $html ) = render_order( new WC_Order( 6, array( new WC_Order_Item_Product( $subscription ) ) ) );
assert_has( 'Once your subscription Credits are available', $html, 'subscription availability caveat' );
assert_has( 'https://example.org/premium', $html, 'subscription redemption URL' );

list( $shown, $html ) = render_order( new WC_Order( 7, array( new WC_Order_Item_Product() ) ) );
assert_equals( false, $shown, 'ordinary order has no card' );
assert_equals( '', $html, 'ordinary order emits no markup' );

$GLOBALS['orders'][1] = $order;
$GLOBALS['current_user'] = 9;
$_GET['key'] = 'incorrect';
ob_start();
kitmage_wallet_order_guidance_confirmation( 1 );
assert_equals( '', ob_get_clean(), 'wrong order key does not render card' );
$_GET['key'] = 'secret-1';
ob_start();
kitmage_wallet_order_guidance_confirmation( 1 );
kitmage_wallet_order_guidance_confirmation( 1 );
$html = ob_get_clean();
assert_equals( 1, substr_count( $html, 'class="kitmage-wallet-next-steps"' ), 'confirmation hooks render once per order' );

echo "order guidance tests passed\n";
