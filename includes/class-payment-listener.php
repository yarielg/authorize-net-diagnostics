<?php
namespace BEEOCHAuthNetDiag;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the three payment-lifecycle hooks that confirm a successful
 * transaction inside the same PHP request as the API call.
 *
 * Every public callback is wrapped in try/catch so this plugin can NEVER
 * throw into WooCommerce's payment flow.
 *
 * No typed parameters on callbacks — prevents TypeError if WC classes are
 * unavailable in an edge-case load order.
 */
class PaymentListener {

	/** @var Repository */
	private Repository $repo;

	public function __construct( Repository $repo ) {
		$this->repo = $repo;
	}

	// ── Hook registration ─────────────────────────────────────────────────────

	public function register(): void {
		add_action(
			'wc_payment_gateway_authorize_net_cim_credit_card_add_transaction_data',
			array( $this, 'handle_transaction_saved' ),
			10, 3
		);
		add_action(
			'wc_payment_gateway_authorize_net_cim_credit_card_payment_processed',
			array( $this, 'handle_payment_processed' ),
			10, 2
		);
		add_action( 'woocommerce_order_status_failed',  array( $this, 'handle_order_failed' ),  10, 2 );
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'handle_order_on_hold' ), 10, 2 );
	}

	// ── Transaction-saved ─────────────────────────────────────────────────────

	public function handle_transaction_saved( $order, $response, $gateway ): void {
		try {
			$this->process_transaction_saved( $order );
		} catch ( \Throwable $e ) {
			// Silent — never affect payment.
		}
	}

	private function process_transaction_saved( $order ): void {
		if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$order_id       = (int) $order->get_id();
		$transaction_id = method_exists( $order, 'get_transaction_id' )
			? (string) $order->get_transaction_id()
			: '';

		$record_id = $this->resolve_record_id( $order_id, $transaction_id );
		if ( ! $record_id ) {
			return;
		}

		$update = array(
			'transaction_saved' => 1,
			'transaction_id'    => $transaction_id ?: null,
		);

		// Capture any DB error visible at this moment.
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) ) {
			$update['database_error'] = substr( $wpdb->last_error, 0, 1000 );
		}

		$this->repo->update_event( $record_id, $update );
	}

	// ── Payment processed (payment_complete() was called) ─────────────────────

	public function handle_payment_processed( $order, $gateway ): void {
		try {
			$this->process_payment_completed( $order );
		} catch ( \Throwable $e ) {
			// Silent.
		}
	}

	private function process_payment_completed( $order ): void {
		if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$order_id     = (int) $order->get_id();
		$order_status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';

		$record_id = $this->resolve_record_id( $order_id );
		if ( ! $record_id ) {
			return;
		}

		global $wpdb;
		$update = array(
			'payment_completed'  => 1,
			'diagnostic_status'  => 'completed',
			'final_order_status' => $order_status,
			'resolved_at_gmt'    => current_time( 'mysql', true ),
		);
		if ( ! empty( $wpdb->last_error ) ) {
			$update['database_error'] = substr( $wpdb->last_error, 0, 1000 );
		}

		$this->repo->update_event( $record_id, $update );

		// Tell the shutdown handler there is nothing to do.
		ShutdownHandler::mark_resolved();
	}

	// ── Order status transitions ──────────────────────────────────────────────

	public function handle_order_failed( $order_id, $order = null ): void {
		try {
			$this->process_status_transition( $order_id, $order, 'failed' );
		} catch ( \Throwable $e ) {
			// Silent.
		}
	}

	public function handle_order_on_hold( $order_id, $order = null ): void {
		try {
			$this->process_status_transition( $order_id, $order, 'on_hold' );
		} catch ( \Throwable $e ) {
			// Silent.
		}
	}

	private function process_status_transition( $order_id, $order, string $diag_status ): void {
		if ( ! $order_id ) {
			return;
		}

		if ( ! $order || ! method_exists( $order, 'get_payment_method' ) ) {
			if ( function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( (int) $order_id );
			}
		}
		if ( ! $order || ! method_exists( $order, 'get_payment_method' ) ) {
			return;
		}
		if ( $order->get_payment_method() !== 'authorize_net_cim_credit_card' ) {
			return;
		}

		$int_order_id = (int) $order->get_id();
		$record_id    = $this->resolve_record_id( $int_order_id );
		if ( ! $record_id ) {
			return;
		}

		$current = $this->repo->find_by_id( $record_id );
		if ( $current && $current->diagnostic_status === 'completed' ) {
			return;
		}

		$order_status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';

		$update = array(
			'diagnostic_status'  => $diag_status,
			'final_order_status' => $order_status,
		);

		// WooCommerce writes the gateway error message as an order note immediately
		// before firing woocommerce_order_status_failed / on-hold, so we can read it here.
		$error_note = $this->get_latest_order_note( $int_order_id );
		if ( $error_note !== '' ) {
			$update['database_error'] = $error_note;
		}

		// Also capture any live DB error.
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) && empty( $update['database_error'] ) ) {
			$update['database_error'] = substr( $wpdb->last_error, 0, 1000 );
		}

		$this->repo->update_event( $record_id, $update );
	}

	/**
	 * Read the most recent order note (internal/system notes only).
	 * WooCommerce adds the gateway error message as a note before firing the
	 * status-transition hook, so this will contain the actual failure reason.
	 *
	 * Uses wc_get_order_notes() which is HPOS-compatible.
	 * Falls back gracefully if the function is unavailable.
	 *
	 * @param int $order_id
	 * @return string  Sanitized note text, or empty string.
	 */
	private function get_latest_order_note( int $order_id ): string {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			return '';
		}

		try {
			$notes = wc_get_order_notes( array(
				'order_id' => $order_id,
				'limit'    => 3,       // grab 3 in case the very latest is a status label
				'order'    => 'DESC',
				'type'     => 'internal',
			) );

			if ( empty( $notes ) ) {
				return '';
			}

			foreach ( $notes as $note ) {
				$content = wp_strip_all_tags( $note->content ?? '' );
				// Skip bare status-change labels like "Order status changed from X to Y."
				if ( $content === '' || preg_match( '/^Order status changed/i', $content ) ) {
					continue;
				}
				return substr( $content, 0, 1000 );
			}
		} catch ( \Throwable $e ) {
			// Never fail because of note reading.
		}

		return '';
	}

	// ── Record resolution ─────────────────────────────────────────────────────

	private function resolve_record_id( int $order_id, string $transaction_id = '' ): ?int {
		// Primary path: all three hooks fire in the same request.
		$active = ApiListener::get_active_record_id();
		if ( $active ) {
			return $active;
		}
		// Fallback DB lookup (webhook / async path).
		if ( $order_id ) {
			$row = $this->repo->find_pending_by_order( $order_id, $transaction_id );
			if ( $row ) {
				return (int) $row->id;
			}
			$row = $this->repo->find_saved_not_completed( $order_id );
			if ( $row ) {
				return (int) $row->id;
			}
		}
		return null;
	}
}
