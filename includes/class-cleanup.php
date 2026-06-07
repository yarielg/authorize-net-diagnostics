<?php
namespace BEEOCHAuthNetDiag;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled daily cleanup of diagnostic records.
 * Uses Action Scheduler when available, wp-cron otherwise.
 */
class Cleanup {

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
		add_action( 'beeoch_authnet_daily_cleanup', array( $this, 'run_cleanup' ) );
	}

	// ── Cleanup callback ──────────────────────────────────────────────────────

	public function run_cleanup(): void {
		$this->delete_completed_records();
		$this->delete_error_records();
		$this->delete_stale_pending();
	}

	// ── Delete completed (short retention) ────────────────────────────────────

	private function delete_completed_records(): void {
		$hours   = (int) $this->settings->get_short_retention_hours();
		$hours   = max( 1, $hours );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

		$this->repo->delete_by_status_and_age( array( 'completed' ), $cutoff );

		// Also purge non-transaction API calls quickly.
		$non_tx_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 4 * HOUR_IN_SECONDS ) );
		$this->repo->delete_non_transaction_records( $non_tx_cutoff );
	}

	// ── Delete error/suspicious records (long retention) ──────────────────────

	private function delete_error_records(): void {
		$days   = (int) $this->settings->get_long_retention_days();
		$days   = max( 1, $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$this->repo->delete_by_status_and_age(
			array(
				'approved_not_registered',
				'transaction_saved_not_completed',
				'fatal_error',
				'api_error',
				'network_error',
				'declined',
				'failed',
				'on_hold',
			),
			$cutoff
		);
	}

	// ── Delete stale pending records (not resolved within 1 hour) ─────────────

	private function delete_stale_pending(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$this->repo->delete_by_status_and_age( array( 'pending' ), $cutoff );
	}
}
