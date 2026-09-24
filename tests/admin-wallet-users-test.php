<?php

define( 'ABSPATH', __DIR__ );

$GLOBALS['admin_pages']      = array();
$GLOBALS['removed_submenus'] = array();

function __( $text, $domain = 'default' ) {
	return $text;
}

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
	$GLOBALS['admin_pages'][ $menu_slug ] = array(
		'parent'     => null,
		'capability' => $capability,
		'callback'   => $callback,
	);
}

function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
	$GLOBALS['admin_pages'][ $menu_slug ] = array(
		'parent'     => $parent_slug,
		'capability' => $capability,
		'callback'   => $callback,
	);
}

function remove_submenu_page( $menu_slug, $submenu_slug ) {
	$GLOBALS['removed_submenus'][] = array( $menu_slug, $submenu_slug );
}

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

require dirname( __DIR__ ) . '/includes/admin-wallet-users.php';

kitmage_wallet_register_users_admin_menu();

assert_same(
	array(
		'parent'     => 'kitmage-wallet-users',
		'capability' => 'edit_users',
		'callback'   => 'kitmage_wallet_render_user_balances_page',
	),
	$GLOBALS['admin_pages']['aspen-wallet-users'],
	'legacy Aspen admin URL registration'
);
assert_same(
	array( array( 'kitmage-wallet-users', 'aspen-wallet-users' ) ),
	$GLOBALS['removed_submenus'],
	'legacy Aspen admin URL hidden from navigation'
);

echo "admin wallet users tests passed\n";
