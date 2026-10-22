<?php
/**
 * Administrator approval screens.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a Penalis Writers screen under Users.
 */
final class Penalis_Admin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_penalis_account_action', array( __CLASS__, 'handle_account_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_status_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_status_column' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_styles' ) );
	}

	/**
	 * Register the menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_users_page(
			'Penalis Writers',
			'Penalis Writers',
			'list_users',
			'penalis-writers',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render a small admin stylesheet.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function admin_styles( $hook ) {
		if ( 'users_page_penalis-writers' !== $hook ) {
			return;
		}

		wp_register_style( 'penalis-admin', false, array(), PENALIS_PLATFORM_VERSION );
		wp_enqueue_style( 'penalis-admin' );
		wp_add_inline_style(
			'penalis-admin',
			'.penalis-status{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.03em}'
			. '.penalis-status--active{background:#dcfce7;color:#166534}'
			. '.penalis-status--pending_verification,.penalis-status--pending_approval{background:#fef3c7;color:#92400e}'
			. '.penalis-status--rejected,.penalis-status--suspended{background:#fee2e2;color:#991b1b}'
			. '.penalis-status--legacy{background:#e5e7eb;color:#374151}'
			. '.penalis-actions form{display:inline-block;margin:0 4px 4px 0}'
			. '.penalis-log{margin:8px 0 0;padding-left:18px;color:#50575e;font-size:12px}'
		);
	}

	/**
	 * Add a status column to the Users list.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public static function add_status_column( $columns ) {
		$columns['penalis_status'] = 'Penalis status';
		return $columns;
	}

	/**
	 * Render the status column.
	 *
	 * @param string $output      Output.
	 * @param string $column_name Column.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public static function render_status_column( $output, $column_name, $user_id ) {
		if ( 'penalis_status' !== $column_name ) {
			return $output;
		}

		$status = Penalis_Account_Status::get_status( $user_id );

		if ( '' === $status ) {
			return '<span class="penalis-status penalis-status--legacy">Legacy / unmanaged</span>';
		}

		return sprintf(
			'<span class="penalis-status penalis-status--%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( Penalis_Policy::status_label( $status ) )
		);
	}

	/**
	 * Render the management page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'penalis-platform' ) );
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = 25;
		$args     = array(
			'number'  => $per_page,
			'offset'  => ( $paged - 1 ) * $per_page,
			'fields'  => 'all',
			'orderby' => 'registered',
			'order'   => 'DESC',
		);

		if ( '' !== $status && Penalis_Policy::is_valid_status( $status ) ) {
			$args['meta_key']   = Penalis_Account_Status::META_STATUS; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = $status; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		} else {
			// "All managed" excludes legacy accounts, which intentionally have
			// no Penalis status metadata.
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => Penalis_Account_Status::META_STATUS,
					'compare' => 'EXISTS',
				),
			);
		}

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$query = new WP_User_Query( $args );
		$users = $query->get_results();
		$total = (int) $query->get_total();

		$statuses = array(
			''                                          => 'All managed',
			Penalis_Policy::STATUS_PENDING_VERIFICATION => 'Pending verification',
			Penalis_Policy::STATUS_PENDING_APPROVAL     => 'Pending approval',
			Penalis_Policy::STATUS_ACTIVE               => 'Active',
			Penalis_Policy::STATUS_REJECTED             => 'Rejected',
			Penalis_Policy::STATUS_SUSPENDED            => 'Suspended',
		);

		?>
		<div class="wrap">
			<h1>Penalis Writers</h1>

			<?php if ( isset( $_GET['penalis_message'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( self::message_for( sanitize_key( wp_unslash( $_GET['penalis_message'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['penalis_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( self::message_for( sanitize_key( wp_unslash( $_GET['penalis_error'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="penalis-writers">
				<label for="penalis-status-filter" class="screen-reader-text">Filter by status</label>
				<select id="penalis-status-filter" name="status">
					<?php foreach ( $statuses as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search name, username, email">
				<?php submit_button( 'Filter', 'secondary', '', false ); ?>
			</form>

			<p class="description">
				Legacy accounts without Penalis metadata are intentionally excluded from this list. They are never promoted or enabled automatically.
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col">Writer</th>
						<th scope="col">Email</th>
						<th scope="col">Status</th>
						<th scope="col">Registered</th>
						<th scope="col">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $users ) ) : ?>
						<tr><td colspan="5">No accounts match this filter.</td></tr>
					<?php else : ?>
						<?php foreach ( $users as $user ) : ?>
							<?php
							$user_id        = (int) $user->ID;
							$user_status    = Penalis_Account_Status::get_status( $user_id );
							$access         = Penalis_Account_Status::is_access_enabled( $user_id );
							$can_approve    = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_ACTIVE );
							$can_reject     = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_REJECTED );
							$can_suspend    = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_SUSPENDED );
							$can_reactivate = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_ACTIVE );
							$can_reopen     = Penalis_Policy::can_transition( $user_status, Penalis_Policy::STATUS_PENDING_APPROVAL );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $user->display_name ); ?></strong><br>
									<code><?php echo esc_html( $user->user_login ); ?></code>
								</td>
								<td><?php echo esc_html( $user->user_email ); ?></td>
								<td>
									<span class="penalis-status penalis-status--<?php echo esc_attr( $user_status ); ?>">
										<?php echo esc_html( Penalis_Policy::status_label( $user_status ) ); ?>
									</span>
									<?php if ( Penalis_Account_Status::has_recent_email_failure( $user_id ) ) : ?>
										<br><small>Recent email delivery failure</small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $user->user_registered ); ?></td>
								<td class="penalis-actions">
									<?php if ( Penalis_Policy::STATUS_PENDING_VERIFICATION === $user_status ) : ?>
										<?php self::action_button( $user_id, 'resend_verification', 'Resend verification' ); ?>
									<?php endif; ?>

									<?php if ( $can_approve && Penalis_Policy::STATUS_PENDING_APPROVAL === $user_status ) : ?>
										<?php self::action_button( $user_id, 'approve', 'Approve' ); ?>
									<?php endif; ?>

									<?php if ( $can_reject ) : ?>
										<?php self::action_button( $user_id, 'reject', 'Reject', true ); ?>
									<?php endif; ?>

									<?php if ( $can_suspend ) : ?>
										<?php self::action_button( $user_id, 'suspend', 'Suspend', true ); ?>
									<?php endif; ?>

									<?php if ( $can_reactivate && Penalis_Policy::STATUS_SUSPENDED === $user_status ) : ?>
										<?php self::action_button( $user_id, 'reactivate', 'Reactivate' ); ?>
									<?php endif; ?>

									<?php if ( $can_reopen && Penalis_Policy::STATUS_REJECTED === $user_status ) : ?>
										<?php self::action_button( $user_id, 'reopen', 'Reopen application' ); ?>
									<?php endif; ?>

									<?php if ( $access ) : ?>
										<br><small>Dashboard access enabled</small>
									<?php endif; ?>

									<?php
									$log = Penalis_Account_Status::get_log( $user_id );
									if ( ! empty( $log ) ) :
										?>
										<ul class="penalis-log">
											<?php foreach ( array_slice( array_reverse( $log ), 0, 3 ) as $entry ) : ?>
												<li>
													<?php
													echo esc_html(
														sprintf(
															'%s: %s → %s (%s)',
															gmdate( 'Y-m-d H:i', (int) $entry['time'] ),
															$entry['from'] ? $entry['from'] : 'new',
															$entry['to'],
															$entry['reason'] ? $entry['reason'] : 'n/a'
														)
													);
													?>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $total / $per_page );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render a nonce-protected action button.
	 *
	 * @param int    $user_id User ID.
	 * @param string $action  Action.
	 * @param string $label   Label.
	 * @param bool   $danger  Whether the action is destructive.
	 * @return void
	 */
	private static function action_button( $user_id, $action, $label, $danger = false ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="penalis_account_action">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
			<input type="hidden" name="penalis_action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( 'penalis_account_' . $action . '_' . $user_id ); ?>
			<button type="submit" class="button <?php echo $danger ? 'button-link-delete' : 'button-secondary'; ?>">
				<?php echo esc_html( $label ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Handle administrator actions.
	 *
	 * @return void
	 */
	public static function handle_account_action() {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'penalis-platform' ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$action  = isset( $_POST['penalis_action'] ) ? sanitize_key( wp_unslash( $_POST['penalis_action'] ) ) : '';

		check_admin_referer( 'penalis_account_' . $action . '_' . $user_id );

		if ( $user_id <= 0 ) {
			self::redirect( 'invalid_user' );
		}

		$allowed_actions = array( 'approve', 'reject', 'suspend', 'reactivate', 'reopen', 'resend_verification' );
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			self::redirect( 'invalid_action' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			self::redirect( 'invalid_user' );
		}

		// Never allow an administrator to be suspended or rejected here.
		if ( user_can( $user, 'manage_options' ) ) {
			self::redirect( 'protected_account' );
		}

		$actor = get_current_user_id();

		switch ( $action ) {
			case 'approve':
				$result = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_ACTIVE, 'admin_approved', $actor );
				break;

			case 'reactivate':
				$result = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_ACTIVE, 'admin_reactivated', $actor );
				break;

			case 'reject':
				$result = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_REJECTED, 'admin_rejected', $actor );
				break;

			case 'reopen':
				$result = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_PENDING_APPROVAL, 'admin_reopened', $actor );
				break;

			case 'suspend':
				$result = Penalis_Account_Status::transition( $user_id, Penalis_Policy::STATUS_SUSPENDED, 'admin_suspended', $actor );
				break;

			case 'resend_verification':
				$result = self::resend_verification( $user_id );
				break;

			default:
				$result = new WP_Error( 'penalis_invalid_action', 'Invalid action.', array( 'status' => 400 ) );
		}

		if ( is_wp_error( $result ) ) {
			self::redirect( 'error', $result->get_error_code() );
		}

		$new_status = Penalis_Account_Status::get_status( $user_id );

		if ( in_array( $action, array( 'approve', 'reactivate', 'reject', 'suspend' ), true ) ) {
			Penalis_Emails::send_status_notification( $user_id, $new_status );
		}

		self::redirect( 'success', 'done' );
	}

	/**
	 * Resend a verification email for an administrator.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private static function resend_verification( $user_id ) {
		$status = Penalis_Account_Status::get_status( $user_id );

		if ( Penalis_Policy::STATUS_PENDING_VERIFICATION !== $status ) {
			return new WP_Error( 'penalis_invalid_transition', 'This account is not awaiting verification.', array( 'status' => 409 ) );
		}

		$token = Penalis_Account_Status::issue_verification_token( $user_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$sent = Penalis_Emails::send_verification( $user_id, $token );

		if ( ! $sent ) {
			return new WP_Error( 'penalis_email_failed', 'The verification email could not be sent.', array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Redirect back to the management page.
	 *
	 * @param string $type  Message type.
	 * @param string $value Message key.
	 * @return void
	 */
	private static function redirect( $type, $value = '1' ) {
		$args = array(
			'page' => 'penalis-writers',
		);

		if ( 'success' === $type ) {
			$args['penalis_message'] = $value;
		} else {
			$args['penalis_error'] = $value;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'users.php' ) ) );
		exit;
	}

	/**
	 * Map message keys to human text.
	 *
	 * @param string $key Message key.
	 * @return string
	 */
	private static function message_for( $key ) {
		$messages = array(
			'done'                       => 'The account status was updated.',
			'error'                      => 'The account status could not be updated.',
			'invalid_user'               => 'The selected account was not found.',
			'invalid_action'             => 'That action is not allowed.',
			'protected_account'          => 'Administrator accounts cannot be changed here.',
			'penalis_invalid_transition' => 'That status change is not allowed for this account.',
			'penalis_email_failed'       => 'The verification email could not be sent. The account was not advanced.',
		);

		return isset( $messages[ $key ] ) ? $messages[ $key ] : 'The request was completed.';
	}

	/**
	 * Show a notice when writer applications are waiting.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'users', 'users_page_penalis-writers' ), true ) ) {
			return;
		}

		if ( 'users_page_penalis-writers' === $screen->id ) {
			return;
		}

		$count = get_transient( 'penalis_pending_approval_count' );

		if ( false === $count ) {
			$count = Penalis_Account_Status::count_by_status( Penalis_Policy::STATUS_PENDING_APPROVAL );
			set_transient( 'penalis_pending_approval_count', (int) $count, 5 * MINUTE_IN_SECONDS );
		}

		$count = (int) $count;

		if ( $count <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of pending writer applications. */
					_n( '%d writer application is awaiting review.', '%d writer applications are awaiting review.', $count, 'penalis-platform' ),
					$count
				)
			),
			esc_url( admin_url( 'users.php?page=penalis-writers&status=pending_approval' ) ),
			esc_html__( 'Review applications', 'penalis-platform' )
		);
	}
}
