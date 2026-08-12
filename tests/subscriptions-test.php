<?php

define( 'ABSPATH', __DIR__ );

function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ); }
function aspen_wallet_sanitize_bucket_slug( $value ) { return sanitize_key( $value ); }
function aspen_wallet_woo_get_resolved_item_grants( $item ) { return $item->grants; }
function wcs_get_subscription( $id ) { return $GLOBALS['subscriptions'][ $id ] ?? null; }
function wcs_get_users_subscriptions( $user_id ) { return $GLOBALS['subscriptions_by_user'][ $user_id ] ?? array(); }
function wallet_set_balance( $user_id, $bucket, $amount ) { $GLOBALS['balances'][ $user_id ][ $bucket ] = $amount; }

class WC_Order {
	public function get_id() { return 1; }
}

class Test_Item {
	public $grants;
	private $quantity;

	public function __construct( $quantity, $grants ) {
		$this->quantity = $quantity;
		$this->grants   = $grants;
	}

	public function get_quantity() { return $this->quantity; }
}

class WC_Subscription {
	private $user_id;
	private $items;
	private $status;
	private $meta = array();

	public function __construct( $user_id, $items, $status = 'active' ) {
		$this->user_id = $user_id;
		$this->items   = $items;
		$this->status  = $status;
	}

	public function get_user_id() { return $this->user_id; }
	public function get_items( $type ) { return $this->items; }
	public function has_status( $status ) { return $this->status === $status; }
	public function set_status( $status ) { $this->status = $status; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function save() {}
}

require dirname( __DIR__ ) . '/includes/subscriptions.php';

function reset_grant( $amount, $bucket = 'classes' ) {
	return array( 'type' => 'subscription_reset', 'bucket' => $bucket, 'amount' => $amount );
}

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$quantity_subscription = new WC_Subscription( 1, array( new Test_Item( 3, array( reset_grant( 5 ) ) ) ) );
assert_same( array( 'classes' => 15 ), aspen_wallet_get_subscription_reset_grants( $quantity_subscription ), 'quantity-adjusted grant' );

$multiple_items = new WC_Subscription( 1, array(
	new Test_Item( 2, array( reset_grant( 5 ) ) ),
	new Test_Item( 3, array( reset_grant( 4 ) ) ),
) );
assert_same( array( 'classes' => 22 ), aspen_wallet_get_subscription_reset_grants( $multiple_items ), 'multiple line items are additive' );

$subscriptions = array(
	new WC_Subscription( 7, array( new Test_Item( 1, array( reset_grant( 5 ) ) ) ) ),
	new WC_Subscription( 7, array( new Test_Item( 1, array( reset_grant( 5 ) ) ) ) ),
	new WC_Subscription( 7, array( new Test_Item( 1, array( reset_grant( 5 ) ) ) ) ),
);
$GLOBALS['subscriptions_by_user'][7] = $subscriptions;
assert_same( array( 'classes' => 15 ), aspen_wallet_get_user_active_subscription_reset_grants( 7 ), 'separate subscriptions are additive' );

aspen_wallet_handle_subscription_renewal_success( $subscriptions[0] );
assert_same( 15, $GLOBALS['balances'][7]['classes'], 'renewal applies combined entitlement' );

$subscriptions[0]->set_status( 'cancelled' );
aspen_wallet_handle_subscription_status( $subscriptions[0], 'cancelled', 'active' );
assert_same( 10, $GLOBALS['balances'][7]['classes'], 'cancellation preserves other active subscriptions' );

$subscriptions[1]->set_status( 'expired' );
aspen_wallet_handle_subscription_status( $subscriptions[1], 'expired', 'active' );
assert_same( 5, $GLOBALS['balances'][7]['classes'], 'expiration preserves other active subscriptions' );

echo "subscriptions tests passed\n";
