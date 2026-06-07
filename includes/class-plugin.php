<?php
namespace BEEOCHAuthNetDiag;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap singleton.
 * Initializes all runtime components once WooCommerce is confirmed available.
 */
class Plugin {

	private static ?self $instance = null;

	/** @var Repository */
	private Repository $repo;

	/** @var Settings */
	private Settings $settings;

	// ── Singleton ─────────────────────────────────────────────────────────────

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->repo     = new Repository();
		$this->settings = new Settings();
	}

	// ── Initialization ────────────────────────────────────────────────────────

	private function init(): void {
		// Always: maybe upgrade DB schema.
		Installer::maybe_upgrade();

		// Always: register scheduling hooks so scheduled actions run even when
		// WC is unavailable (cleanup keeps running regardless).
		( new Cleanup( $this->repo, $this->settings ) )->register();

		// Register cron fallback hook early.
		add_action( 'beeoch_authnet_verify_pending', array( $this, 'dispatch_verify_pending' ), 10, 2 );

		// Runtime hooks that need WooCommerce.
		if ( $this->is_woocommerce_active() ) {
			$this->init_listeners();
		}

		// Admin UI.
		if ( is_admin() ) {
			$this->init_admin();
		}
	}

	// ── Listeners ─────────────────────────────────────────────────────────────

	private function init_listeners(): void {
		( new ApiListener( $this->repo, $this->settings ) )->register();
		( new PaymentListener( $this->repo ) )->register();
		( new Classifier( $this->repo, $this->settings ) )->register();
	}

	// ── Verification dispatcher (WP-cron path) ────────────────────────────────

	/**
	 * @param mixed $diagnostic_id
	 * @param mixed $order_id
	 */
	public function dispatch_verify_pending( $diagnostic_id, $order_id ): void {
		$classifier = new Classifier( $this->repo, $this->settings );
		$classifier->verify_pending( $diagnostic_id, $order_id );
	}

	// ── Admin ─────────────────────────────────────────────────────────────────

	private function init_admin(): void {
		require_once BEEOCH_AUTHNET_DIAG_DIR . 'includes/class-list-table.php';
		require_once BEEOCH_AUTHNET_DIAG_DIR . 'includes/class-admin-page.php';
		( new AdminPage( $this->repo, $this->settings ) )->register();
	}

	// ── Guards ────────────────────────────────────────────────────────────────

	private function is_woocommerce_active(): bool {
		return function_exists( 'WC' ) || class_exists( 'WooCommerce' );
	}

	// ── Accessors (for admin) ─────────────────────────────────────────────────

	public function get_repo(): Repository {
		return $this->repo;
	}

	public function get_settings(): Settings {
		return $this->settings;
	}
}
