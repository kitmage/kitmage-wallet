<?php
/**
 * Plugin Name: KitMage Wallet
 * Description: A fund-based credit wallet for WooCommerce, WooCommerce Subscriptions, Fluent Booking, and FluentCRM.
 * Version: 0.1.2
 * Plugin URI: https://kitmage.com
 * Author: Mike@KitMage
 * Author URI: https://kitmage.com
 * Text Domain: kitmage-wallet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'KITMAGE_WALLET_FILE' ) ) {
	define( 'KITMAGE_WALLET_FILE', __FILE__ );
}

if ( ! defined( 'KITMAGE_WALLET_PATH' ) ) {
	define( 'KITMAGE_WALLET_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'KITMAGE_WALLET_VERSION' ) ) {
	define( 'KITMAGE_WALLET_VERSION', '0.1.2' );
}

/**
 * Activation safety checks.
 *
 * @return void
 */
function kitmage_wallet_activate() {
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		deactivate_plugins( plugin_basename( KITMAGE_WALLET_FILE ) );
		wp_die( esc_html__( 'KitMage Wallet requires PHP 7.4 or newer.', 'kitmage-wallet' ) );
	}

	if ( version_compare( get_bloginfo( 'version' ), '6.2', '<' ) ) {
		deactivate_plugins( plugin_basename( KITMAGE_WALLET_FILE ) );
		wp_die( esc_html__( 'KitMage Wallet requires WordPress 6.2 or newer.', 'kitmage-wallet' ) );
	}
}
register_activation_hook( KITMAGE_WALLET_FILE, 'kitmage_wallet_activate' );

/**
 * Load all module files.
 *
 * @return void
 */
function kitmage_wallet_load_modules() {
	require_once KITMAGE_WALLET_PATH . 'includes/balances.php';
	require_once KITMAGE_WALLET_PATH . 'includes/buckets.php';
	require_once KITMAGE_WALLET_PATH . 'includes/admin.php';
	require_once KITMAGE_WALLET_PATH . 'includes/admin-wallet-users.php';
	require_once KITMAGE_WALLET_PATH . 'includes/woo.php';
	require_once KITMAGE_WALLET_PATH . 'includes/subscriptions.php';
	require_once KITMAGE_WALLET_PATH . 'includes/fluent-booking.php';
	require_once KITMAGE_WALLET_PATH . 'includes/shortcodes.php';
}

/**
 * Register all plugin hooks in one place.
 *
 * @return void
 */
function kitmage_wallet_bootstrap() {
	kitmage_wallet_load_modules();

	kitmage_wallet_register_admin_hooks();
	kitmage_wallet_register_admin_wallet_users_hooks();
	kitmage_wallet_register_woo_hooks();
	kitmage_wallet_register_subscription_hooks();
	kitmage_wallet_register_fluent_booking_hooks();
	kitmage_wallet_register_shortcode_hooks();
}
add_action( 'plugins_loaded', 'kitmage_wallet_bootstrap' );
