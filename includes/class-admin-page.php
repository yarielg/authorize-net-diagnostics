<?php
namespace BEEOCHAuthNetDiag;

defined( 'ABSPATH' ) || exit;

/**
 * Admin submenu page: WooCommerce → Authorize.Net Diagnostics.
 */
class AdminPage {

	const PAGE_SLUG = 'beeoch-authnet-diagnostics';

	/** @var Repository */
	private Repository $repo;

	/** @var Settings */
	private Settings $settings;

	public function __construct( Repository $repo, Settings $settings ) {
		$this->repo     = $repo;
		$this->settings = $settings;
	}

	// ── Registration ──────────────────────────────────────────────────────────

	public function register(): void {
		add_action( 'admin_menu',    array( $this, 'add_submenu' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_alert_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init',    array( $this, 'handle_bulk_delete' ) );
		add_action( 'admin_init',    array( $this, 'handle_settings_save' ) );
		add_action( 'admin_init',    array( $this, 'handle_self_test' ) );
	}

	public function add_submenu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Authorize.Net Diagnostics', 'beeoch-authnet-diagnostics' ),
			__( 'Authorize.Net Diag', 'beeoch-authnet-diagnostics' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}
		wp_enqueue_style(
			'beeoch-authnet-diag',
			BEEOCH_AUTHNET_DIAG_URL . 'assets/admin.css',
			array(),
			BEEOCH_AUTHNET_DIAG_VERSION
		);
	}

	// ── Admin notice ──────────────────────────────────────────────────────────

	public function maybe_show_alert_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$count = $this->repo->count_unresolved();
		if ( $count < 1 ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&status_filter=approved_not_registered' );
		printf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			sprintf(
				esc_html(
					_n(
						'BEE-OCH Authorize.Net Diagnostics: %d unresolved suspicious payment event.',
						'BEE-OCH Authorize.Net Diagnostics: %d unresolved suspicious payment events.',
						$count,
						'beeoch-authnet-diagnostics'
					)
				),
				$count
			),
			esc_url( $url ),
			esc_html__( 'Review now', 'beeoch-authnet-diagnostics' )
		);
	}

	// ── Main render ───────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'beeoch-authnet-diagnostics' ) );
		}

		// Detail view.
		if ( isset( $_GET['record_id'] ) && is_numeric( $_GET['record_id'] ) ) {
			$this->render_detail( (int) $_GET['record_id'] );
			return;
		}

		// Tab routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'records';
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Authorize.Net Diagnostics', 'beeoch-authnet-diagnostics' ) . '</h1>';
		$this->render_tabs( $tab );
		if ( $tab === 'settings' ) {
			$this->render_settings();
		} elseif ( $tab === 'health' ) {
			$this->render_health();
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	// ── Tabs ──────────────────────────────────────────────────────────────────

	private function render_tabs( string $current ): void {
		$tabs = array(
			'records'  => __( 'Records', 'beeoch-authnet-diagnostics' ),
			'health'   => __( 'Health Check', 'beeoch-authnet-diagnostics' ),
			'settings' => __( 'Settings', 'beeoch-authnet-diagnostics' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $slug );
			$active = $current === $slug ? ' nav-tab-active' : '';
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				esc_attr( $active ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	// ── List view ─────────────────────────────────────────────────────────────

	private function render_list(): void {
		$table = new ListTable( $this->repo );
		$table->prepare_items();

		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		if ( ! empty( $_GET['status_filter'] ) ) {
			echo '<input type="hidden" name="status_filter" value="' . esc_attr( $_GET['status_filter'] ) . '">';
		}
		$table->search_box( __( 'Search by order ID, order #, or transaction ID', 'beeoch-authnet-diagnostics' ), 'beeoch-search' );
		echo '</form>';

		echo '<form method="post">';
		wp_nonce_field( 'beeoch_bulk_delete', 'beeoch_bulk_nonce' );
		$table->display();
		echo '</form>';
	}

	// ── Detail view ───────────────────────────────────────────────────────────

	private function render_detail( int $record_id ): void {
		$record = $this->repo->find_by_id( $record_id );
		if ( ! $record ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Record Not Found', 'beeoch-authnet-diagnostics' ) . '</h1></div>';
			return;
		}

		$back_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		// Edit order URL.
		$order_edit_url = '';
		if ( $record->order_id ) {
			$order_edit_url = get_edit_post_link( (int) $record->order_id ) ?: '';
			if ( ! $order_edit_url && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( (int) $record->order_id );
				if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
					$order_edit_url = $order->get_edit_order_url();
				}
			}
		}

		echo '<div class="wrap">';
		printf(
			'<h1>%s <a href="%s" class="page-title-action">%s</a></h1>',
			esc_html__( 'Diagnostic Record', 'beeoch-authnet-diagnostics' ),
			esc_url( $back_url ),
			esc_html__( '&larr; Back to list', 'beeoch-authnet-diagnostics' )
		);

		// Status badge.
		printf(
			'<p><strong>%s:</strong> <span class="beeoch-status beeoch-status--%s">%s</span></p>',
			esc_html__( 'Diagnostic Status', 'beeoch-authnet-diagnostics' ),
			esc_attr( $record->diagnostic_status ),
			esc_html( ListTable::status_label( $record->diagnostic_status ) )
		);

		// ── Timeline ─────────────────────────────────────────────────────────
		echo '<h2>' . esc_html__( 'Timeline', 'beeoch-authnet-diagnostics' ) . '</h2>';
		echo '<ol class="beeoch-timeline">';
		echo '<li>' . esc_html__( 'API event recorded: ', 'beeoch-authnet-diagnostics' ) . esc_html( $record->created_at_gmt ) . ' UTC</li>';
		if ( $record->transaction_saved ) {
			echo '<li>' . esc_html__( 'Transaction ID saved to order', 'beeoch-authnet-diagnostics' ) . '</li>';
		}
		if ( $record->payment_completed ) {
			echo '<li>' . esc_html__( 'payment_complete() called', 'beeoch-authnet-diagnostics' ) . '</li>';
		}
		if ( $record->resolved_at_gmt ) {
			echo '<li>' . esc_html__( 'Resolved: ', 'beeoch-authnet-diagnostics' ) . esc_html( $record->resolved_at_gmt ) . ' UTC</li>';
		}
		if ( $record->fatal_error ) {
			echo '<li style="color:#c00;">' . esc_html__( 'Fatal error detected', 'beeoch-authnet-diagnostics' ) . '</li>';
		}
		echo '</ol>';

		// ── Core fields table ─────────────────────────────────────────────────
		echo '<h2>' . esc_html__( 'Details', 'beeoch-authnet-diagnostics' ) . '</h2>';
		echo '<table class="widefat striped beeoch-detail-table">';
		$rows = array(
			__( 'Record ID',         'beeoch-authnet-diagnostics' ) => $record->id,
			__( 'Correlation Key',   'beeoch-authnet-diagnostics' ) => $record->correlation_key,
			__( 'Internal Order ID', 'beeoch-authnet-diagnostics' ) => $record->order_id
				? ( $order_edit_url
					? '<a href="' . esc_url( $order_edit_url ) . '">' . esc_html( '#' . $record->order_id ) . '</a>'
					: esc_html( '#' . $record->order_id ) )
				: '—',
			__( 'Public Order #',    'beeoch-authnet-diagnostics' ) => $record->order_number ?: '—',
			__( 'Transaction ID',    'beeoch-authnet-diagnostics' ) => $record->transaction_id ?: '—',
			__( 'Request Type',      'beeoch-authnet-diagnostics' ) => $record->request_type ?: '—',
			__( 'HTTP Status',       'beeoch-authnet-diagnostics' ) => $record->http_status ?: 'N/A (network error)',
			__( 'Duration (s)',      'beeoch-authnet-diagnostics' ) => $record->request_duration ?: '—',
			__( 'AN Result Code',    'beeoch-authnet-diagnostics' ) => $record->api_response_code ?: '—',
			__( 'AN Message',        'beeoch-authnet-diagnostics' ) => $record->api_response_message ?: '—',
			__( 'Transaction Saved', 'beeoch-authnet-diagnostics' ) => $record->transaction_saved ? '✓ Yes' : '✗ No',
			__( 'Payment Completed', 'beeoch-authnet-diagnostics' ) => $record->payment_completed ? '✓ Yes' : '✗ No',
			__( 'Final Order Status','beeoch-authnet-diagnostics' ) => $record->final_order_status ?: '—',
			__( 'Created (GMT)',     'beeoch-authnet-diagnostics' ) => $record->created_at_gmt,
			__( 'Updated (GMT)',     'beeoch-authnet-diagnostics' ) => $record->updated_at_gmt,
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr>';
			printf( '<th style="width:220px">%s</th>', esc_html( $label ) );
			// $value may already contain a safe <a> tag built above.
			echo '<td>' . wp_kses( (string) $value, array( 'a' => array( 'href' => array() ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</table>';

		// ── Request body ──────────────────────────────────────────────────────
		if ( $record->request_body ) {
			echo '<h2>' . esc_html__( 'Sanitized Request Body', 'beeoch-authnet-diagnostics' ) . '</h2>';
			echo '<pre class="beeoch-code">' . esc_html( $record->request_body ) . '</pre>';
		}

		// ── Response body ─────────────────────────────────────────────────────
		if ( $record->response_body ) {
			echo '<h2>' . esc_html__( 'Sanitized Response Body', 'beeoch-authnet-diagnostics' ) . '</h2>';
			echo '<pre class="beeoch-code">' . esc_html( $record->response_body ) . '</pre>';
		}

		// ── Fatal error ───────────────────────────────────────────────────────
		if ( $record->fatal_error ) {
			echo '<h2 style="color:#c00;">' . esc_html__( 'Fatal Error', 'beeoch-authnet-diagnostics' ) . '</h2>';
			echo '<pre class="beeoch-code" style="border-left:4px solid #c00;">' . esc_html( $record->fatal_error ) . '</pre>';
		}

		// ── DB error ──────────────────────────────────────────────────────────
		if ( $record->database_error ) {
			echo '<h2 style="color:#a80;">' . esc_html__( 'Database Error', 'beeoch-authnet-diagnostics' ) . '</h2>';
			echo '<pre class="beeoch-code" style="border-left:4px solid #a80;">' . esc_html( $record->database_error ) . '</pre>';
		}

		echo '</div>';
	}

	// ── Settings view ─────────────────────────────────────────────────────────

	private function render_settings(): void {
		if ( isset( $_GET['settings-saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'beeoch-authnet-diagnostics' ) . '</p></div>';
		}
		if ( isset( $_GET['settings-error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Settings could not be saved — check database permissions or try deactivating and reactivating the plugin.', 'beeoch-authnet-diagnostics' ) . '</p></div>';
		}
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'beeoch_save_settings', 'beeoch_settings_nonce' ); ?>
			<input type="hidden" name="beeoch_action" value="save_settings">
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Enable Diagnostics', 'beeoch-authnet-diagnostics' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( $this->settings->is_enabled() ); ?> />
							<?php esc_html_e( 'Capture Authorize.Net API events', 'beeoch-authnet-diagnostics' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Keep Successful Records', 'beeoch-authnet-diagnostics' ); ?></th>
					<td>
						<input type="number" name="short_retention_hours" min="1" max="8760"
							value="<?php echo esc_attr( $this->settings->get_short_retention_hours() ); ?>" />
						<?php esc_html_e( 'hours (default: 24)', 'beeoch-authnet-diagnostics' ); ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Keep Suspicious/Error Records', 'beeoch-authnet-diagnostics' ); ?></th>
					<td>
						<input type="number" name="long_retention_days" min="1" max="3650"
							value="<?php echo esc_attr( $this->settings->get_long_retention_days() ); ?>" />
						<?php esc_html_e( 'days (default: 45)', 'beeoch-authnet-diagnostics' ); ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Enable Alert Emails', 'beeoch-authnet-diagnostics' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_alerts" value="1" <?php checked( $this->settings->is_alerts_enabled() ); ?> />
							<?php esc_html_e( 'Send email when a suspicious event is detected', 'beeoch-authnet-diagnostics' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Alert Recipient', 'beeoch-authnet-diagnostics' ); ?></th>
					<td>
						<input type="email" name="alert_email" value="<?php echo esc_attr( $this->settings->get_alert_email() ); ?>"
							placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'beeoch-authnet-diagnostics' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'beeoch-authnet-diagnostics' ) ); ?>
		</form>
		<?php
	}

	// ── Form handlers ─────────────────────────────────────────────────────────

	public function handle_settings_save(): void {
		if (
			! isset( $_POST['beeoch_action'] ) ||
			$_POST['beeoch_action'] !== 'save_settings' ||
			! isset( $_POST['beeoch_settings_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['beeoch_settings_nonce'] ) ), 'beeoch_save_settings' ) ||
			! current_user_can( 'manage_woocommerce' )
		) {
			return;
		}

		$data   = Settings::from_post( $_POST );
		$saved  = $this->settings->save( $data );
		$notice = $saved ? 'settings-saved=1' : 'settings-error=1';

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'tab' => 'settings', $notice => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_bulk_delete(): void {
		if (
			empty( $_POST['beeoch_bulk_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['beeoch_bulk_nonce'] ) ), 'beeoch_bulk_delete' ) ||
			! current_user_can( 'manage_woocommerce' )
		) {
			return;
		}

		if ( empty( $_POST['action'] ) || $_POST['action'] !== 'delete' ) {
			return;
		}

		if ( empty( $_POST['record_ids'] ) || ! is_array( $_POST['record_ids'] ) ) {
			return;
		}

		global $wpdb;
		$table = Installer::get_table_name();
		foreach ( $_POST['record_ids'] as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			}
		}

		wp_safe_redirect(
			add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) )
		);
		exit;
	}

	// ── Self-test handler ─────────────────────────────────────────────────────

	public function handle_self_test(): void {
		if (
			empty( $_POST['beeoch_action'] ) ||
			$_POST['beeoch_action'] !== 'self_test' ||
			empty( $_POST['beeoch_selftest_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['beeoch_selftest_nonce'] ) ), 'beeoch_self_test' ) ||
			! current_user_can( 'manage_woocommerce' )
		) {
			return;
		}

		$id = $this->repo->insert_event( array(
			'correlation_key'    => 'selftest-' . wp_generate_uuid4(),
			'order_id'           => null,
			'order_number'       => 'SELFTEST',
			'transaction_id'     => null,
			'request_type'       => 'selfTest',
			'diagnostic_status'  => 'completed',
			'http_status'        => 200,
			'api_response_code'  => 'I00001',
			'api_response_message' => 'Self-test: DB write OK.',
			'transaction_saved'  => 0,
			'payment_completed'  => 0,
		) );

		$notice = $id
			? 'self_test_ok&self_test_id=' . (int) $id
			: 'self_test_fail';

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'tab' => 'health', $notice => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ── Health check view ─────────────────────────────────────────────────────

	private function render_health(): void {
		// ── Self-test feedback ────────────────────────────────────────────────
		if ( isset( $_GET['self_test_ok'] ) ) {
			$id = isset( $_GET['self_test_id'] ) ? (int) $_GET['self_test_id'] : 0;
			$detail_url = $id
				? add_query_arg( array( 'page' => self::PAGE_SLUG, 'record_id' => $id ), admin_url( 'admin.php' ) )
				: '';
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'Self-test passed: a test row was inserted successfully.', 'beeoch-authnet-diagnostics' );
			if ( $detail_url ) {
				printf( ' <a href="%s">%s</a>', esc_url( $detail_url ), esc_html__( 'View row', 'beeoch-authnet-diagnostics' ) );
			}
			echo '</p></div>';
		}
		if ( isset( $_GET['self_test_fail'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			esc_html_e( 'Self-test FAILED: DB insert returned false. Check database permissions and table existence.', 'beeoch-authnet-diagnostics' );
			echo '</p></div>';
		}

		// ── Gather data ───────────────────────────────────────────────────────
		$h = $this->collect_health_data();

		echo '<div class="beeoch-health">';

		// ── Section: Plugin status ────────────────────────────────────────────
		$this->health_section( __( 'Plugin Status', 'beeoch-authnet-diagnostics' ) );
		$this->health_row(
			__( 'Diagnostics enabled', 'beeoch-authnet-diagnostics' ),
			$h['enabled'] ? __( 'Yes', 'beeoch-authnet-diagnostics' ) : __( 'No — enable in Settings', 'beeoch-authnet-diagnostics' ),
			$h['enabled'] ? 'ok' : 'warn'
		);
		$this->health_row(
			__( 'API capture hook registered', 'beeoch-authnet-diagnostics' ),
			$h['hook_registered']
				? sprintf( __( 'Yes (priority %d)', 'beeoch-authnet-diagnostics' ), $h['hook_registered'] )
				: __( 'NO — hook not attached. WooCommerce or the AN gateway may be inactive.', 'beeoch-authnet-diagnostics' ),
			$h['hook_registered'] ? 'ok' : 'error'
		);

		// ── Section: WooCommerce & Gateway ────────────────────────────────────
		$this->health_section( __( 'WooCommerce & Gateway', 'beeoch-authnet-diagnostics' ) );
		$this->health_row(
			__( 'WooCommerce active', 'beeoch-authnet-diagnostics' ),
			$h['wc_active'] ? __( 'Yes', 'beeoch-authnet-diagnostics' ) : __( 'NO', 'beeoch-authnet-diagnostics' ),
			$h['wc_active'] ? 'ok' : 'error'
		);
		$this->health_row(
			__( 'AN Gateway class exists', 'beeoch-authnet-diagnostics' ),
			$h['gateway_class'] ? __( 'Yes', 'beeoch-authnet-diagnostics' ) : __( 'NO — gateway plugin may be inactive', 'beeoch-authnet-diagnostics' ),
			$h['gateway_class'] ? 'ok' : 'error'
		);
		$this->health_row(
			__( 'AN Credit Card gateway enabled in WC', 'beeoch-authnet-diagnostics' ),
			$h['gateway_enabled'] === null
				? __( 'Unknown (WC not available)', 'beeoch-authnet-diagnostics' )
				: ( $h['gateway_enabled'] ? __( 'Yes', 'beeoch-authnet-diagnostics' ) : __( 'NO — disabled in WC payment settings', 'beeoch-authnet-diagnostics' ) ),
			$h['gateway_enabled'] ? 'ok' : ( $h['gateway_enabled'] === null ? 'warn' : 'error' )
		);

		// ── Section: Database ─────────────────────────────────────────────────
		$this->health_section( __( 'Database', 'beeoch-authnet-diagnostics' ) );
		$this->health_row(
			__( 'Diagnostics table exists', 'beeoch-authnet-diagnostics' ),
			$h['table_exists'] ? __( 'Yes', 'beeoch-authnet-diagnostics' ) : __( 'NO — run deactivate + activate to recreate', 'beeoch-authnet-diagnostics' ),
			$h['table_exists'] ? 'ok' : 'error'
		);
		$this->health_row(
			__( 'Total rows', 'beeoch-authnet-diagnostics' ),
			number_format_i18n( $h['total_rows'] ),
			'info'
		);
		$this->health_row(
			__( 'Rows in last 24 h', 'beeoch-authnet-diagnostics' ),
			number_format_i18n( $h['rows_24h'] ),
			'info'
		);
		$this->health_row(
			__( 'Rows in last 7 days', 'beeoch-authnet-diagnostics' ),
			number_format_i18n( $h['rows_7d'] ),
			'info'
		);
		if ( $h['last_record'] ) {
			$lr  = $h['last_record'];
			$ago = human_time_diff( strtotime( $lr->created_at_gmt ), time() );
			$this->health_row(
				__( 'Last event recorded', 'beeoch-authnet-diagnostics' ),
				sprintf(
					__( '%1$s ago — %2$s (%3$s)', 'beeoch-authnet-diagnostics' ),
					$ago,
					esc_html( $lr->request_type ?: '?' ),
					esc_html( ListTable::status_label( $lr->diagnostic_status ) )
				),
				'info'
			);
		} else {
			$this->health_row(
				__( 'Last event recorded', 'beeoch-authnet-diagnostics' ),
				__( 'No records yet. Place a test order to verify capture.', 'beeoch-authnet-diagnostics' ),
				'warn'
			);
		}

		// ── Section: Queue & Scheduling ───────────────────────────────────────
		$this->health_section( __( 'Queue & Scheduling', 'beeoch-authnet-diagnostics' ) );
		$this->health_row(
			__( 'Action Scheduler available', 'beeoch-authnet-diagnostics' ),
			$h['as_available'] ? __( 'Yes', 'beeoch-authnet-diagnostics' ) : __( 'No — using wp-cron fallback', 'beeoch-authnet-diagnostics' ),
			$h['as_available'] ? 'ok' : 'warn'
		);
		$this->health_row(
			__( 'Pending verification jobs', 'beeoch-authnet-diagnostics' ),
			number_format_i18n( $h['pending_verify_jobs'] ),
			$h['pending_verify_jobs'] > 0 ? 'warn' : 'ok'
		);
		$this->health_row(
			__( 'Next daily cleanup', 'beeoch-authnet-diagnostics' ),
			$h['next_cleanup'] ?: __( 'Not scheduled — deactivate and reactivate the plugin', 'beeoch-authnet-diagnostics' ),
			$h['next_cleanup'] ? 'ok' : 'error'
		);

		// ── Section: Suspicious events ────────────────────────────────────────
		$this->health_section( __( 'Suspicious Events', 'beeoch-authnet-diagnostics' ) );
		$this->health_row(
			__( 'Unresolved suspicious records', 'beeoch-authnet-diagnostics' ),
			$h['unresolved'] > 0
				? sprintf(
					'%d — <a href="%s">%s</a>',
					$h['unresolved'],
					esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&status_filter=approved_not_registered' ) ),
					esc_html__( 'Review', 'beeoch-authnet-diagnostics' )
				)
				: __( '0', 'beeoch-authnet-diagnostics' ),
			$h['unresolved'] > 0 ? 'error' : 'ok'
		);
		$this->health_row(
			__( 'Records stuck in pending (> 5 min)', 'beeoch-authnet-diagnostics' ),
			$h['stale_pending'] > 0
				? sprintf( __( '%d — verification job may not be running', 'beeoch-authnet-diagnostics' ), $h['stale_pending'] )
				: __( '0', 'beeoch-authnet-diagnostics' ),
			$h['stale_pending'] > 0 ? 'error' : 'ok'
		);
		echo '</table>'; // close final health section table

		echo '</div>'; // .beeoch-health

		// ── Self-test button ──────────────────────────────────────────────────
		echo '<h2>' . esc_html__( 'Database Write Self-Test', 'beeoch-authnet-diagnostics' ) . '</h2>';
		echo '<p>' . esc_html__( 'Inserts a synthetic test row to verify the database write path is working. The row will be cleaned up on the next daily cleanup run.', 'beeoch-authnet-diagnostics' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'beeoch_self_test', 'beeoch_selftest_nonce' );
		echo '<input type="hidden" name="beeoch_action" value="self_test">';
		submit_button( __( 'Run DB Self-Test', 'beeoch-authnet-diagnostics' ), 'secondary', 'submit', false );
		echo '</form>';

		// ── Interpretation guide ──────────────────────────────────────────────
		echo '<h2>' . esc_html__( 'How to Read This Page', 'beeoch-authnet-diagnostics' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:750px">';
		$guide = array(
			array(
				__( 'Hook not registered', 'beeoch-authnet-diagnostics' ),
				__( 'The Authorize.Net gateway plugin is inactive or was deactivated after this plugin loaded. Deactivate and reactivate this plugin while WC + AN gateway are both active.', 'beeoch-authnet-diagnostics' ),
			),
			array(
				__( 'No records after a transaction', 'beeoch-authnet-diagnostics' ),
				__( 'The api_request_performed hook fired but the DB insert failed (check Self-Test), OR the diagnostics are disabled in Settings, OR the hook is not registered.', 'beeoch-authnet-diagnostics' ),
			),
			array(
				__( 'Records stuck in "pending"', 'beeoch-authnet-diagnostics' ),
				__( 'The verification job scheduled for 2 minutes after approval has not run. This means Action Scheduler / wp-cron is not processing jobs. Check AS queue or wp-cron configuration.', 'beeoch-authnet-diagnostics' ),
			),
			array(
				__( 'transaction_saved = ✓, payment_completed = ✗', 'beeoch-authnet-diagnostics' ),
				__( 'The transaction ID was written to the order but WooCommerce\'s payment_complete() was never called. The order may be stuck in a non-paid status. Investigate the order manually.', 'beeoch-authnet-diagnostics' ),
			),
			array(
				__( '"Approved Not Registered" status', 'beeoch-authnet-diagnostics' ),
				__( 'Authorize.Net charged the card but neither the transaction ID nor the payment_complete() call reached WooCommerce. This is the primary double-charge risk. Investigate immediately.', 'beeoch-authnet-diagnostics' ),
			),
			array(
				__( 'Self-test passes but no real records appear', 'beeoch-authnet-diagnostics' ),
				__( 'DB writes work but the api_request_performed hook is not firing. Confirm the AN gateway is the active payment method and that a real checkout was attempted (not a test that bypasses the gateway).', 'beeoch-authnet-diagnostics' ),
			),
		);
		foreach ( $guide as $row ) {
			echo '<tr>';
			printf( '<th style="width:260px;vertical-align:top">%s</th>', esc_html( $row[0] ) );
			printf( '<td>%s</td>', esc_html( $row[1] ) );
			echo '</tr>';
		}
		echo '</table>';
	}

	// ── Health data collector ─────────────────────────────────────────────────

	private function collect_health_data(): array {
		// Hook registration.
		$hook_priority = has_action( 'wc_authorize_net_cim_credit_card_api_request_performed' );

		// WC / gateway availability.
		$wc_active     = function_exists( 'WC' ) || class_exists( 'WooCommerce' );
		$gateway_class = class_exists( 'WC_Gateway_Authorize_Net_CIM_Credit_Card' );
		$gateway_enabled = null;
		if ( $wc_active && function_exists( 'WC' ) ) {
			try {
				$gateways = WC()->payment_gateways();
				if ( $gateways ) {
					$all     = $gateways->payment_gateways();
					$gateway = $all['authorize_net_cim_credit_card'] ?? null;
					$gateway_enabled = $gateway && method_exists( $gateway, 'is_available' )
						? (bool) $gateway->get_option( 'enabled' ) === true || $gateway->get_option( 'enabled' ) === 'yes'
						: null;
				}
			} catch ( \Throwable $e ) {
				$gateway_enabled = null;
			}
		}

		// DB stats.
		$table_exists = $this->repo->table_exists();
		$now          = current_time( 'mysql', true );
		$ago_24h      = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$ago_7d       = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		// Scheduling.
		$as_available = function_exists( 'as_schedule_single_action' );
		$pending_verify_jobs = 0;
		if ( $as_available && function_exists( 'as_get_scheduled_actions' ) ) {
			try {
				$jobs = as_get_scheduled_actions( array(
					'hook'   => 'beeoch_authnet_verify_pending',
					'status' => 'pending',
					'group'  => 'beeoch-authnet-diag',
					'per_page' => 100,
				) );
				$pending_verify_jobs = is_array( $jobs ) ? count( $jobs ) : 0;
			} catch ( \Throwable $e ) {
				$pending_verify_jobs = 0;
			}
		}

		// Next cleanup time.
		$next_cleanup = null;
		if ( $as_available && function_exists( 'as_next_scheduled_action' ) ) {
			$ts = as_next_scheduled_action( 'beeoch_authnet_daily_cleanup', array(), 'beeoch-authnet-diag' );
			if ( $ts ) {
				$next_cleanup = gmdate( 'Y-m-d H:i', $ts ) . ' UTC';
			}
		}
		if ( ! $next_cleanup ) {
			$ts = wp_next_scheduled( 'beeoch_authnet_daily_cleanup' );
			if ( $ts ) {
				$next_cleanup = gmdate( 'Y-m-d H:i', $ts ) . ' UTC';
			}
		}

		return array(
			'enabled'             => $this->settings->is_enabled(),
			'hook_registered'     => $hook_priority,
			'wc_active'           => $wc_active,
			'gateway_class'       => $gateway_class,
			'gateway_enabled'     => $gateway_enabled,
			'table_exists'        => $table_exists,
			'total_rows'          => $table_exists ? $this->repo->count_records() : 0,
			'rows_24h'            => $table_exists ? $this->repo->count_since( $ago_24h ) : 0,
			'rows_7d'             => $table_exists ? $this->repo->count_since( $ago_7d ) : 0,
			'last_record'         => $table_exists ? $this->repo->get_last_record() : null,
			'as_available'        => $as_available,
			'pending_verify_jobs' => $pending_verify_jobs,
			'next_cleanup'        => $next_cleanup,
			'unresolved'          => $table_exists ? $this->repo->count_unresolved() : 0,
			'stale_pending'       => $table_exists ? $this->repo->count_stale_pending( 300 ) : 0,
		);
	}

	// ── Health row helpers ────────────────────────────────────────────────────

	private function health_section( string $title ): void {
		static $first = true;
		if ( ! $first ) {
			echo '</table>'; // close previous section's table before opening a new one
		}
		$first = false;
		printf(
			'<h2 class="beeoch-health-section">%s</h2><table class="widefat beeoch-health-table">',
			esc_html( $title )
		);
	}

	private function health_row( string $label, string $value, string $type = 'info' ): void {
		$icons = array(
			'ok'    => '&#10003;',
			'warn'  => '&#9888;',
			'error' => '&#10007;',
			'info'  => '&#8226;',
		);
		$colors = array(
			'ok'    => '#1a5c12',
			'warn'  => '#7a5800',
			'error' => '#842029',
			'info'  => '#2271b1',
		);
		$icon  = $icons[ $type ]  ?? '&#8226;';
		$color = $colors[ $type ] ?? '#2271b1';
		printf(
			'<tr><th style="width:280px">%s</th><td><span style="color:%s;font-weight:600">%s</span> %s</td></tr>',
			esc_html( $label ),
			esc_attr( $color ),
			$icon,
			wp_kses( $value, array( 'a' => array( 'href' => array() ) ) )
		);
	}
}
