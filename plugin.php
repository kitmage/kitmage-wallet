<?php
/**
 * Plugin Name: Aspen Wallet
 * Description: A fund-based credit wallet for WooCommerce, WooCommerce Subscriptions, Fluent Booking, and FluentCRM.
 * Version: 0.1.2
 * Author: Aspen Wallet
 * Text Domain: aspen-wallet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ASPEN_WALLET_FILE' ) ) {
	define( 'ASPEN_WALLET_FILE', __FILE__ );
}

if ( ! defined( 'ASPEN_WALLET_PATH' ) ) {
	define( 'ASPEN_WALLET_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ASPEN_WALLET_VERSION' ) ) {
	define( 'ASPEN_WALLET_VERSION', '0.1.2' );
}

/**
 * Activation safety checks.
 *
 * @return void
 */
function aspen_wallet_activate() {
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		deactivate_plugins( plugin_basename( ASPEN_WALLET_FILE ) );
		wp_die( esc_html__( 'Aspen Wallet requires PHP 7.4 or newer.', 'aspen-wallet' ) );
	}

	if ( version_compare( get_bloginfo( 'version' ), '6.2', '<' ) ) {
		deactivate_plugins( plugin_basename( ASPEN_WALLET_FILE ) );
		wp_die( esc_html__( 'Aspen Wallet requires WordPress 6.2 or newer.', 'aspen-wallet' ) );
	}
}
register_activation_hook( ASPEN_WALLET_FILE, 'aspen_wallet_activate' );

/**
 * Load all module files.
 *
 * @return void
 */
function aspen_wallet_load_modules() {
	require_once ASPEN_WALLET_PATH . 'includes/balances.php';
	require_once ASPEN_WALLET_PATH . 'includes/buckets.php';
	require_once ASPEN_WALLET_PATH . 'includes/admin.php';
	require_once ASPEN_WALLET_PATH . 'includes/admin-wallet-users.php';
	require_once ASPEN_WALLET_PATH . 'includes/woo.php';
	require_once ASPEN_WALLET_PATH . 'includes/subscriptions.php';
	require_once ASPEN_WALLET_PATH . 'includes/fluent-booking.php';
	require_once ASPEN_WALLET_PATH . 'includes/shortcodes.php';
}

/**
 * Register all plugin hooks in one place.
 *
 * @return void
 */
function aspen_wallet_bootstrap() {
	aspen_wallet_load_modules();

	aspen_wallet_register_admin_hooks();
	aspen_wallet_register_admin_wallet_users_hooks();
	aspen_wallet_register_woo_hooks();
	aspen_wallet_register_subscription_hooks();
	aspen_wallet_register_fluent_booking_hooks();
	aspen_wallet_register_shortcode_hooks();
}
add_action( 'plugins_loaded', 'aspen_wallet_bootstrap' );
