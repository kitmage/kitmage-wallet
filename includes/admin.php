<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kitmage_wallet_register_admin_hooks() {
	add_action( 'admin_menu', 'kitmage_wallet_register_admin_menu' );
	add_action( 'admin_post_kitmage_wallet_save_buckets', 'kitmage_wallet_handle_save_buckets' );
	add_action( 'admin_post_kitmage_wallet_delete_bucket', 'kitmage_wallet_handle_delete_bucket' );
	add_action( 'admin_post_kitmage_wallet_save_booking_event_rules', 'kitmage_wallet_handle_save_booking_event_rules' );
}

function kitmage_wallet_register_admin_menu() {
	add_submenu_page(
		'kitmage-wallet-users',
		__( 'Fund Types', 'kitmage-wallet' ),
		__( 'Fund Types', 'kitmage-wallet' ),
		'manage_options',
		'kitmage-wallet',
		'kitmage_wallet_render_admin_page'
	);

	add_submenu_page(
		'kitmage-wallet-users',
		__( 'Booking Event Rules', 'kitmage-wallet' ),
		__( 'Booking Event Rules', 'kitmage-wallet' ),
		'manage_options',
		'kitmage-wallet-booking-rules',
		'kitmage_wallet_render_booking_event_rules_page'
	);
}

function kitmage_wallet_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'kitmage-wallet' ) );
	}

	$errors  = kitmage_wallet_parse_notice_messages( isset( $_GET['wallet_errors'] ) ? wp_unslash( $_GET['wallet_errors'] ) : '' );
	$success = kitmage_wallet_parse_notice_messages( isset( $_GET['wallet_success'] ) ? wp_unslash( $_GET['wallet_success'] ) : '' );
	$editing = isset( $_GET['edit_slug'] ) ? kitmage_wallet_sanitize_bucket_slug( wp_unslash( $_GET['edit_slug'] ) ) : '';
	$delete_check_slug = isset( $_GET['delete_slug'] ) ? kitmage_wallet_sanitize_bucket_slug( wp_unslash( $_GET['delete_slug'] ) ) : '';
	$delete_references = '' !== $delete_check_slug ? kitmage_wallet_get_bucket_references( $delete_check_slug ) : array();

	$bucket         = array(
		'label'       => '',
		'slug'        => '',
		'description' => '',
	);
	$is_editing     = false;
	$editing_bucket = kitmage_wallet_get_bucket_by_slug( $editing );

	if ( $editing_bucket ) {
		$bucket     = $editing_bucket;
		$is_editing = true;
	}

	$buckets = kitmage_wallet_get_buckets();
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Fund Types', 'kitmage-wallet' ); ?></h1>
		<p class="description"><?php echo esc_html__( 'Each fund holds a separate credit balance in a user’s Wallet.', 'kitmage-wallet' ); ?></p>
		<?php foreach ( $errors as $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endforeach; ?>
		<?php foreach ( $success as $message ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endforeach; ?>

		<?php if ( '' !== $delete_check_slug && ( ! empty( $delete_references['product_grants'] ) || ! empty( $delete_references['event_rules'] ) ) ) : ?>
			<div class="notice notice-warning">
				<p><strong><?php echo esc_html__( 'This fund is in use and cannot be deleted yet.', 'kitmage-wallet' ); ?></strong></p>
				<ul style="list-style: disc; margin-left: 20px;">
					<?php if ( ! empty( $delete_references['product_grants'] ) ) : ?>
						<li><?php echo esc_html( sprintf( __( 'Used in WooCommerce product grants on product IDs: %s', 'kitmage-wallet' ), implode( ', ', array_map( 'intval', $delete_references['product_grants'] ) ) ) ); ?></li>
					<?php endif; ?>
					<?php if ( ! empty( $delete_references['event_rules'] ) ) : ?>
						<li><?php echo esc_html( sprintf( __( 'Used in Fluent Booking event rules on event IDs: %s', 'kitmage-wallet' ), implode( ', ', array_map( 'intval', $delete_references['event_rules'] ) ) ) ); ?></li>
					<?php endif; ?>
				</ul>
				<p><?php echo esc_html__( 'Remove these references first, then delete the fund.', 'kitmage-wallet' ); ?></p>
			</div>
		<?php endif; ?>

		<h2><?php echo esc_html__( 'Existing Funds', 'kitmage-wallet' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Label', 'kitmage-wallet' ); ?></th>
					<th><?php echo esc_html__( 'Slug', 'kitmage-wallet' ); ?></th>
					<th><?php echo esc_html__( 'Description', 'kitmage-wallet' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'kitmage-wallet' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $buckets ) ) : ?>
					<tr><td colspan="4"><?php echo esc_html__( 'No funds configured yet.', 'kitmage-wallet' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $buckets as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<td><code><?php echo esc_html( $row['slug'] ); ?></code></td>
							<td><?php echo esc_html( $row['description'] ); ?></td>
							<td>
								<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'kitmage-wallet', 'edit_slug' => $row['slug'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Edit', 'kitmage-wallet' ); ?></a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
									<?php wp_nonce_field( 'kitmage_wallet_delete_bucket' ); ?>
									<input type="hidden" name="action" value="kitmage_wallet_delete_bucket" />
									<input type="hidden" name="slug" value="<?php echo esc_attr( $row['slug'] ); ?>" />
									<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this fund? Deletion is blocked if references still exist.', 'kitmage-wallet' ) ); ?>');"><?php echo esc_html__( 'Delete', 'kitmage-wallet' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<h2><?php echo esc_html( $is_editing ? __( 'Edit Fund', 'kitmage-wallet' ) : __( 'Add Fund', 'kitmage-wallet' ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'kitmage_wallet_save_buckets' ); ?>
			<input type="hidden" name="action" value="kitmage_wallet_save_buckets" />
			<input type="hidden" name="original_slug" value="<?php echo esc_attr( $is_editing ? $bucket['slug'] : '' ); ?>" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="kitmage_wallet_label"><?php echo esc_html__( 'Label', 'kitmage-wallet' ); ?></label></th>
					<td><input id="kitmage_wallet_label" name="bucket[label]" type="text" class="regular-text" required value="<?php echo esc_attr( $bucket['label'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="kitmage_wallet_slug"><?php echo esc_html__( 'Slug', 'kitmage-wallet' ); ?></label></th>
					<td><input id="kitmage_wallet_slug" name="bucket[slug]" type="text" class="regular-text" required value="<?php echo esc_attr( $bucket['slug'] ); ?>" />
					<p class="description"><?php echo esc_html__( 'Lowercase kebab-case only.', 'kitmage-wallet' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="kitmage_wallet_description"><?php echo esc_html__( 'Description', 'kitmage-wallet' ); ?></label></th>
					<td><textarea id="kitmage_wallet_description" name="bucket[description]" class="large-text" rows="3"><?php echo esc_textarea( $bucket['description'] ); ?></textarea></td>
				</tr>
			</table>
			<?php submit_button( $is_editing ? __( 'Update Fund', 'kitmage-wallet' ) : __( 'Add Fund', 'kitmage-wallet' ) ); ?>
		</form>
	</div>
	<?php
}

function kitmage_wallet_handle_save_buckets() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to save wallet settings.', 'kitmage-wallet' ) );
	}

	check_admin_referer( 'kitmage_wallet_save_buckets' );

	$bucket       = isset( $_POST['bucket'] ) ? (array) wp_unslash( $_POST['bucket'] ) : array();
	$original_slug = isset( $_POST['original_slug'] ) ? kitmage_wallet_sanitize_bucket_slug( wp_unslash( $_POST['original_slug'] ) ) : '';

	$result = kitmage_wallet_upsert_bucket( $bucket, $original_slug );

	if ( is_wp_error( $result ) ) {
		kitmage_wallet_admin_redirect( array( 'wallet_errors' => implode( '|', $result->get_error_messages() ) ) );
	}

	kitmage_wallet_admin_redirect( array( 'wallet_success' => rawurlencode( __( 'Fund saved.', 'kitmage-wallet' ) ) ) );
}

function kitmage_wallet_handle_delete_bucket() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to save wallet settings.', 'kitmage-wallet' ) );
	}

	check_admin_referer( 'kitmage_wallet_delete_bucket' );

	$slug   = isset( $_POST['slug'] ) ? kitmage_wallet_sanitize_bucket_slug( wp_unslash( $_POST['slug'] ) ) : '';
	$result = kitmage_wallet_delete_bucket( $slug );

	if ( is_wp_error( $result ) ) {
		kitmage_wallet_admin_redirect( array( 'wallet_errors' => implode( '|', $result->get_error_messages() ) ) );
	}

	kitmage_wallet_admin_redirect( array( 'wallet_success' => rawurlencode( __( 'Fund deleted.', 'kitmage-wallet' ) ) ) );
}

function kitmage_wallet_admin_redirect( $args ) {
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=kitmage-wallet' ) ) );
	exit;
}

function kitmage_wallet_parse_notice_messages( $messages ) {
	if ( empty( $messages ) || ! is_string( $messages ) ) {
		return array();
	}

	$messages = explode( '|', rawurldecode( $messages ) );
	$clean    = array();

	foreach ( $messages as $message ) {
		$message = sanitize_text_field( $message );
		if ( '' !== $message ) {
			$clean[] = $message;
		}
	}

	return $clean;
}
