<?php
namespace BEEOCHAuthNetDiag;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes plugin settings.
 * Stored as a single non-autoloaded option.
 */
class Settings {

	const OPTION_KEY = 'beeoch_authnet_diag_settings';

	private array $data = array();

	private bool $loaded = false;

	// ── Load ──────────────────────────────────────────────────────────────────

	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$saved        = get_option( self::OPTION_KEY );  // false if not yet saved
		$this->data   = is_array( $saved ) ? $saved : array();
		$this->loaded = true;
	}

	// ── Save ──────────────────────────────────────────────────────────────────

	/**
	 * Persist settings. Uses add_option on first save, update_option on subsequent
	 * saves, both with autoload = 'no'.
	 *
	 * @param array $data Sanitized settings array.
	 * @return bool True on success.
	 */
	public function save( array $data ): bool {
		$this->data   = $data;
		$this->loaded = true;

		$existing = get_option( self::OPTION_KEY );
		if ( $existing === false ) {
			// First-time save: add_option is reliable for new options.
			return add_option( self::OPTION_KEY, $data, '', 'no' );
		}
		// Subsequent saves: if value unchanged update_option returns false
		// (no DB write needed), which is correct — not an error.
		update_option( self::OPTION_KEY, $data, 'no' );
		return true;
	}

	// ── Getters ───────────────────────────────────────────────────────────────

	public function is_enabled(): bool {
		$this->load();
		return (bool) ( $this->data['enabled'] ?? true );
	}

	public function get_short_retention_hours(): int {
		$this->load();
		return (int) ( $this->data['short_retention_hours'] ?? 24 );
	}

	public function get_long_retention_days(): int {
		$this->load();
		return (int) ( $this->data['long_retention_days'] ?? 45 );
	}

	public function is_alerts_enabled(): bool {
		$this->load();
		return (bool) ( $this->data['enable_alerts'] ?? false );
	}

	public function get_alert_email(): string {
		$this->load();
		return (string) ( $this->data['alert_email'] ?? '' );
	}

	// ── Build from POST ───────────────────────────────────────────────────────

	/**
	 * Build a settings array from a raw $_POST. Applies wp_unslash before
	 * sanitizing so WordPress magic-quote escaping does not corrupt values.
	 *
	 * @param array $post Raw $_POST array.
	 * @return array
	 */
	public static function from_post( array $post ): array {
		$post = wp_unslash( $post );
		return array(
			'enabled'               => ! empty( $post['enabled'] ),
			'short_retention_hours' => max( 1, (int) ( $post['short_retention_hours'] ?? 24 ) ),
			'long_retention_days'   => max( 1, (int) ( $post['long_retention_days'] ?? 45 ) ),
			'enable_alerts'         => ! empty( $post['enable_alerts'] ),
			'alert_email'           => sanitize_email( $post['alert_email'] ?? '' ),
		);
	}
}
