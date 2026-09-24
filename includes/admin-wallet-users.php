<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kitmage_wallet_register_admin_wallet_users_hooks() {
	add_action( 'admin_menu', 'kitmage_wallet_register_users_admin_menu', 5 );
	add_action( 'admin_post_kitmage_wallet_save_user_balances', 'kitmage_wallet_handle_save_user_balances' );
	add_action( 'show_user_profile', 'kitmage_wallet_render_profile_wallet_section' );
	add_action( 'edit_user_profile', 'kitmage_wallet_render_profile_wallet_section' );
	add_action( 'personal_options_update', 'kitmage_wallet_handle_profile_wallet_save' );
	add_action( 'edit_user_profile_update', 'kitmage_wallet_handle_profile_wallet_save' );
	add_action( 'admin_notices', 'kitmage_wallet_render_profile_wallet_notices' );
}

/**
 * Render wallet balances section on user profile screens.
 *
 * @param WP_User $user User object being edited.
 * @return void
 */
function kitmage_wallet_render_profile_wallet_section( $user ) {
	if ( ! ( $user instanceof WP_User ) || ! current_user_can( 'edit_user', $user->ID ) ) {
		return;
	}

	$buckets = kitmage_wallet_get_buckets();
	?>
	<h2><?php echo esc_html__( 'Wallet Funds', 'kitmage-wallet' ); ?></h2>
	<table class="form-table" role="presentation">
		<tbody>
			<?php if ( empty( $buckets ) ) : ?>
				<tr>
					<th><?php echo esc_html__( 'Wallet', 'kitmage-wallet' ); ?></th>
					<td><em><?php echo esc_html__( 'No funds configured yet.', 'kitmage-wallet' ); ?></em></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $buckets as $bucket ) : ?>
					<?php
					$slug    = $bucket['slug'];
					$balance = wallet_get_balance( $user->ID, $slug );
					?>
					<tr>
						<th><label for="wallet_bucket_<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $bucket['label'] ); ?></label></th>
						<td>
							<input id="wallet_bucket_<?php echo esc_attr( $slug ); ?>" type="number" name="wallet_bucket[<?php echo esc_attr( $slug ); ?>]" min="0" step="1" value="<?php echo esc_attr( $balance ); ?>" class="regular-text" />
							<?php echo esc_html__( 'Credits', 'kitmage-wallet' ); ?>
							<p class="description"><code><?php echo esc_html( $slug ); ?></code></p>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	<?php wp_nonce_field( 'kitmage_wallet_profile_wallet_save', 'kitmage_wallet_profile_wallet_nonce' ); ?>
	<?php
}

/**
 * Save wallet balances from user profile screens.
 *
 * @param int $user_id User ID being updated.
 * @return void
 */
function kitmage_wallet_handle_profile_wallet_save( $user_id ) {
	$user_id = (int) $user_id;

	if ( $user_id <= 0 || ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	$nonce = isset( $_POST['kitmage_wallet_profile_wallet_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['kitmage_wallet_profile_wallet_nonce'] ) ) : '';
	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'kitmage_wallet_profile_wallet_save' ) ) {
		kitmage_wallet_add_profile_wallet_notice( 'error', __( 'Wallet Credits were not saved: invalid wallet profile nonce.', 'kitmage-wallet' ) );
		return;
	}

	$raw_values = isset( $_POST['wallet_bucket'] ) && is_array( $_POST['wallet_bucket'] ) ? wp_unslash( $_POST['wallet_bucket'] ) : array();
	$buckets    = kitmage_wallet_get_buckets();
	$errors     = array();
	$updated    = 0;

	foreach ( $buckets as $bucket ) {
		$slug       = $bucket['slug'];
		$label      = $bucket['label'];
		$raw_amount = isset( $raw_values[ $slug ] ) ? sanitize_text_field( (string) $raw_values[ $slug ] ) : '0';

		$parsed = kitmage_wallet_parse_int_amount( $raw_amount, true );
		if ( is_wp_error( $parsed ) ) {
			$errors[] = sprintf( __( '%1$s must be a whole integer.', 'kitmage-wallet' ), $label );
			continue;
		}

		$amount = (int) $parsed;
		if ( $amount < 0 ) {
			$amount = 0;
		}

		if ( wallet_set_balance( $user_id, $slug, $amount ) ) {
			$updated++;
		}
	}

	foreach ( $errors as $error ) {
		kitmage_wallet_add_profile_wallet_notice( 'error', $error );
	}

	if ( empty( $errors ) ) {
		kitmage_wallet_add_profile_wallet_notice( 'success', __( 'Wallet Credits updated.', 'kitmage-wallet' ) );
	} elseif ( $updated > 0 ) {
		kitmage_wallet_add_profile_wallet_notice( 'success', __( 'Wallet Credits partially updated.', 'kitmage-wallet' ) );
	}
}

function kitmage_wallet_add_profile_wallet_notice( $type, $message ) {
	if ( ! is_user_logged_in() || '' === $message ) {
		return;
	}

	$user_id = get_current_user_id();
	$key     = 'kitmage_wallet_profile_notices';
	$notices = get_user_meta( $user_id, $key, true );

	if ( ! is_array( $notices ) ) {
		$notices = array();
	}

	$notices[] = array(
		'type'    => ( 'success' === $type ) ? 'success' : 'error',
		'message' => sanitize_text_field( $message ),
	);

	update_user_meta( $user_id, $key, $notices );
}

function kitmage_wallet_render_profile_wallet_notices() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
		return;
	}

	$user_id = get_current_user_id();
	$key     = 'kitmage_wallet_profile_notices';
	$notices = get_user_meta( $user_id, $key, true );

	if ( ! is_array( $notices ) || empty( $notices ) ) {
		return;
	}

	delete_user_meta( $user_id, $key );

	foreach ( $notices as $notice ) {
		$type    = ( isset( $notice['type'] ) && 'success' === $notice['type'] ) ? 'notice-success' : 'notice-error';
		$message = isset( $notice['message'] ) ? sanitize_text_field( $notice['message'] ) : '';

		if ( '' === $message ) {
			continue;
		}

		echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}

function kitmage_wallet_register_users_admin_menu() {
	add_menu_page(
		__( 'Wallet', 'kitmage-wallet' ),
		__( 'Wallet', 'kitmage-wallet' ),
		'edit_users',
		'kitmage-wallet-users',
		'kitmage_wallet_render_user_balances_page',
		'dashicons-money-alt',
		56
	);

	add_submenu_page(
		'kitmage-wallet-users',
		__( 'User Wallets', 'kitmage-wallet' ),
		__( 'User Wallets', 'kitmage-wallet' ),
		'edit_users',
		'kitmage-wallet-users',
		'kitmage_wallet_render_user_balances_page'
	);

	// Keep direct links created before the KitMage rebrand working. Registering
	// and then removing the submenu leaves the page routable without showing a
	// duplicate navigation item.
	add_submenu_page(
		'kitmage-wallet-users',
		__( 'User Wallets', 'kitmage-wallet' ),
		__( 'User Wallets', 'kitmage-wallet' ),
		'edit_users',
		'aspen-wallet-users',
		'kitmage_wallet_render_user_balances_page'
	);
	remove_submenu_page( 'kitmage-wallet-users', 'aspen-wallet-users' );
}

function kitmage_wallet_render_user_balances_page() {
	if ( ! current_user_can( 'edit_users' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'kitmage-wallet' ) );
	}

	$search_term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$user_id     = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
	$selected    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
	$buckets     = kitmage_wallet_get_buckets();
	$messages    = kitmage_wallet_parse_notice_messages( isset( $_GET['wallet_success'] ) ? wp_unslash( $_GET['wallet_success'] ) : '' );
	$errors      = kitmage_wallet_parse_notice_messages( isset( $_GET['wallet_errors'] ) ? wp_unslash( $_GET['wallet_errors'] ) : '' );

	$users = array();
	if ( '' !== $search_term ) {
		$users = get_users(
			array(
				'number'         => 20,
				'search'         => '*' . esc_attr( $search_term ) . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'orderby'        => 'display_name',
				'order'          => 'ASC',
			)
		);
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'User Wallets', 'kitmage-wallet' ); ?></h1>

		<?php foreach ( $errors as $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endforeach; ?>
		<?php foreach ( $messages as $message ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endforeach; ?>

		<form method="get">
			<input type="hidden" name="page" value="kitmage-wallet-users" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="kitmage_wallet_user_search"><?php echo esc_html__( 'Find User', 'kitmage-wallet' ); ?></label></th>
					<td>
						<input id="kitmage_wallet_user_search" type="text" name="s" class="regular-text" value="<?php echo esc_attr( $search_term ); ?>" placeholder="<?php echo esc_attr__( 'Search by email, login, or display name', 'kitmage-wallet' ); ?>" />
						<?php submit_button( __( 'Search', 'kitmage-wallet' ), 'secondary', '', false ); ?>
					</td>
				</tr>
			</table>
		</form>

		<?php if ( '' !== $search_term ) : ?>
			<h2><?php echo esc_html__( 'Search Results', 'kitmage-wallet' ); ?></h2>
			<?php if ( empty( $users ) ) : ?>
				<p><?php echo esc_html__( 'No users found.', 'kitmage-wallet' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $users as $user ) : ?>
						<li>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'kitmage-wallet-users', 's' => $search_term, 'user_id' => $user->ID ), admin_url( 'admin.php' ) ) ); ?>">
								<?php echo esc_html( sprintf( '%1$s (%2$s)', $user->display_name, $user->user_email ) ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $selected instanceof WP_User ) : ?>
			<h2>
				<?php echo esc_html( sprintf( __( 'Wallet for %1$s', 'kitmage-wallet' ), $selected->display_name ) ); ?>
				<small>&lt;<?php echo esc_html( $selected->user_email ); ?>&gt;</small>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'kitmage_wallet_save_user_balances' ); ?>
				<input type="hidden" name="action" value="kitmage_wallet_save_user_balances" />
				<input type="hidden" name="user_id" value="<?php echo esc_attr( $selected->ID ); ?>" />
				<input type="hidden" name="s" value="<?php echo esc_attr( $search_term ); ?>" />

				<table class="widefat striped">
					<thead><tr><th><?php echo esc_html__( 'Fund', 'kitmage-wallet' ); ?></th><th><?php echo esc_html__( 'Credits', 'kitmage-wallet' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $buckets ) ) : ?>
						<tr><td colspan="2"><?php echo esc_html__( 'No funds configured yet.', 'kitmage-wallet' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $buckets as $bucket ) : ?>
							<?php
							$slug    = $bucket['slug'];
							$balance = wallet_get_balance( $selected->ID, $slug );
							?>
							<tr>
								<td><strong><?php echo esc_html( $bucket['label'] ); ?></strong><br /><code><?php echo esc_html( $slug ); ?></code></td>
								<td><input type="number" min="0" step="1" name="balances[<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( $balance ); ?>" class="small-text" /></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Credits', 'kitmage-wallet' ) ); ?>
			</form>
		<?php elseif ( $user_id > 0 ) : ?>
			<p><?php echo esc_html__( 'Selected user was not found.', 'kitmage-wallet' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

function kitmage_wallet_handle_save_user_balances() {
	if ( ! current_user_can( 'edit_users' ) ) {
		wp_die( esc_html__( 'You do not have permission to edit user Wallets.', 'kitmage-wallet' ) );
	}

	check_admin_referer( 'kitmage_wallet_save_user_balances' );

	$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
	$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;

	if ( ! ( $user instanceof WP_User ) ) {
		kitmage_wallet_user_balances_redirect(
			array(
				'wallet_errors' => rawurlencode( __( 'User not found.', 'kitmage-wallet' ) ),
			)
		);
	}

	$values  = isset( $_POST['balances'] ) ? (array) wp_unslash( $_POST['balances'] ) : array();
	$buckets = kitmage_wallet_get_buckets();

	$errors = array();
	foreach ( $buckets as $bucket ) {
		$slug   = $bucket['slug'];
		$label  = isset( $bucket['label'] ) ? $bucket['label'] : $slug;
		$parsed = isset( $values[ $slug ] ) ? kitmage_wallet_parse_int_amount( $values[ $slug ] ) : 0;

		if ( is_wp_error( $parsed ) ) {
			$errors[] = sprintf( __( '%1$s must be a whole integer.', 'kitmage-wallet' ), $label );
			continue;
		}

		$amount = (int) $parsed;
		wallet_set_balance( $user_id, $slug, $amount );
	}

	if ( ! empty( $errors ) ) {
		kitmage_wallet_user_balances_redirect(
			array(
				'user_id'      => $user_id,
				's'            => isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '',
				'wallet_errors' => implode( '|', $errors ),
			)
		);
	}

	kitmage_wallet_user_balances_redirect(
		array(
			'user_id'        => $user_id,
			's'              => isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '',
			'wallet_success' => rawurlencode( __( 'Credits updated.', 'kitmage-wallet' ) ),
		)
	);
}

function kitmage_wallet_user_balances_redirect( $args ) {
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=kitmage-wallet-users' ) ) );
	exit;
}
