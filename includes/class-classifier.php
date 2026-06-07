<?php
namespace BEEOCHAuthNetDiag;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies pending diagnostic records ~2 minutes after an approved charge.
 * Runs via Action Scheduler or wp-cron.
 *
 * This class deliberately does NOT make any Authorize.Net API calls.
 * It only reads WooCommerce order state and updates the diagnostic row.
 */
class Classifier {

	/** @var Repository */
	private Repository $repo;

	/** @var Settings */
	private Settings $settings;

	public function __construct( Repository $repo, Settings $settings ) {
		$this->repo     = $repo;
		$this->settings = $settings;
	}

	// ── Hook registration ─────────────────────────────────────────────────────

	public function register(): void {
		add_action( 'beeoch_authnet_verify_pending', array( $this, 'verify_pending' ), 10, 2 );
	}

	// ── Verification callback ─────────────────────────────────────────────────

	/**
	 * @param mixed $diagnostic_id  Row primary key.
	 * @param mixed $order_id       WooCommerce order ID.
	 */
	public function verify_pending( $diagnostic_id, $order_id ): void {
		try {
			$this->run_verification( (int) $diagnostic_id, (int) $order_id );
		} catch ( \Throwable $e ) {
			// Silent — verification failure must not propagate.
		}
	}

	private function run_verification( int $diagnostic_id, int $order_id ): void {
		if ( ! $diagnostic_id ) {
			return;
		}

		$record = $this->repo->find_by_id( $diagnostic_id );
		if ( ! $record ) {
			return;
		}

		// Already resolved — hooks updated it in the original request.
		if ( in_array( $record->diagnostic_status, array( 'completed', 'declined', 'api_error', 'network_error', 'fatal_error' ), true ) ) {
			return;
		}

		// Load the WC order.
		$order       = null;
		$order_status = '';
		$wc_trans_id  = '';

		if ( $order_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && method_exists( $order, 'get_status' ) ) {
				$order_status = (string) $order->get_status();
				$wc_trans_id  = method_exists( $order, 'get_transaction_id' )
					? (string) $order->get_transaction_id()
					: '';
			}
		}

		$transaction_saved  = (int) $record->transaction_saved;
		$payment_completed  = (int) $record->payment_completed;

		// If the WC order has the transaction ID set, treat transaction as saved.
		if ( ! $transaction_saved && $wc_trans_id !== '' ) {
			$transaction_saved = 1;
		}

		// Determine final status.
		if ( $transaction_saved && $payment_completed ) {
			$final_status = 'completed';
		} elseif ( $transaction_saved && ! $payment_completed ) {
			// Edge case: transaction ID saved but payment_complete() was not called.
			$final_status = 'transaction_saved_not_completed';
		} else {
			// Authorize.Net approved but WC never saved the transaction.
			$final_status = 'approved_not_registered';
		}

		$update = array(
			'diagnostic_status'  => $final_status,
			'final_order_status' => $order_status ?: null,
		);

		// Capture any DB error visible at this moment.
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) && empty( $record->database_error ) ) {
			$update['database_error'] = substr( $wpdb->last_error, 0, 1000 );
		}

		// Update transaction_id if we found it on the order but not in the record.
		if ( $wc_trans_id && empty( $record->transaction_id ) ) {
			$update['transaction_id']   = $wc_trans_id;
			$update['transaction_saved'] = 1;
		}

		if ( $final_status === 'completed' ) {
			$update['resolved_at_gmt'] = current_time( 'mysql', true );
		}

		$this->repo->update_event( $diagnostic_id, $update );

		// Send alert for suspicious outcomes.
		$alert_statuses = array( 'approved_not_registered', 'transaction_saved_not_completed', 'fatal_error' );
		if ( in_array( $final_status, $alert_statuses, true ) ) {
			$this->send_alert( $record, $final_status, $order );
		}
	}

	// ── Alert email ───────────────────────────────────────────────────────────

	/**
	 * @param object      $record
	 * @param string      $status
	 * @param mixed|null  $order
	 */
	private function send_alert( object $record, string $status, $order = null ): void {
		if ( ! $this->settings->is_alerts_enabled() ) {
			return;
		}

		$recipient = $this->settings->get_alert_email();
		if ( ! $recipient || ! is_email( $recipient ) ) {
			$recipient = get_option( 'admin_email' );
		}

		$site      = get_bloginfo( 'name' );
		$label     = $this->status_label( $status );
		$order_num = $record->order_number ?: ( $record->order_id ?: 'N/A' );
		$trans_id  = $record->transaction_id ?: 'N/A';
		$admin_url = add_query_arg(
			array(
				'page'      => 'beeoch-authnet-diagnostics',
				'record_id' => $record->id,
			),
			admin_url( 'admin.php' )
		);

		$subject = sprintf( '[%s] Authorize.Net Payment Alert: %s – Order %s', $site, $label, $order_num );

		$body  = "A payment event requires your attention.\n\n";
		$body .= "Status:          {$label}\n";
		$body .= "Order Number:    {$order_num}\n";
		$body .= "Transaction ID:  {$trans_id}\n";
		$body .= "Diagnostic ID:   {$record->id}\n";
		$body .= "Created (GMT):   {$record->created_at_gmt}\n\n";
		$body .= "Review details: {$admin_url}\n\n";
		$body .= "-- {$site}\n";
		$body .= "This is an automated alert from the BEE-OCH Authorize.Net Diagnostics plugin.\n";

		wp_mail( $recipient, $subject, $body );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function status_label( string $status ): string {
		$labels = array(
			'approved_not_registered'      => 'Approved Not Registered',
			'transaction_saved_not_completed' => 'Transaction Saved, Not Completed',
			'fatal_error'                  => 'Fatal Error',
		);
		return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
	}
}
