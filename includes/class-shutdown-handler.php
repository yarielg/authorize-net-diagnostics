<?php
namespace BEEOCHAuthNetDiag;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a shutdown function ONLY while an Authorize.Net payment is
 * actively being tracked. Captures fatal PHP errors that occur between an
 * approved API response and the WooCommerce order-save.
 *
 * PHP shutdown functions cannot be unregistered, so we use a static flag
 * to gate the handler: if the payment completed cleanly, the flag is cleared
 * before shutdown runs.
 */
class ShutdownHandler {

	/** @var int|null  Active diagnostic record ID. */
	private static ?int $active_id = null;

	/** @var bool  True once register() has been called this request. */
	private static bool $registered = false;

	/** @var bool  True if the payment resolved before shutdown. */
	private static bool $resolved = false;

	/** @var Repository|null */
	private static ?Repository $repo = null;

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Start tracking a record for fatal-error detection.
	 *
	 * @param int        $record_id   Diagnostic row ID.
	 * @param Repository $repository
	 */
	public static function register( int $record_id, Repository $repository ): void {
		self::$active_id = $record_id;
		self::$resolved  = false;
		self::$repo      = $repository;

		if ( ! self::$registered ) {
			self::$registered = true;
			register_shutdown_function( array( static::class, 'handle_shutdown' ) );
		}
	}

	/**
	 * Call this when the payment completed successfully so the shutdown
	 * handler knows it has nothing to do.
	 */
	public static function mark_resolved(): void {
		self::$resolved = true;
	}

	/**
	 * Return the currently tracked record ID (used by PaymentListener).
	 */
	public static function get_active_id(): ?int {
		return self::$active_id;
	}

	// ── Shutdown callback ─────────────────────────────────────────────────────

	/**
	 * Called by PHP at script shutdown. Only acts if:
	 *  - A diagnostic record is tracked.
	 *  - The record has NOT been resolved already.
	 *  - A fatal error actually occurred.
	 */
	public static function handle_shutdown(): void {
		if ( self::$resolved || self::$active_id === null || self::$repo === null ) {
			return;
		}

		$error = error_get_last();
		if ( ! $error ) {
			return;
		}

		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
		if ( ! in_array( $error['type'], $fatal_types, true ) ) {
			return;
		}

		// Sanitize: include type, message, file basename, and line.
		// Deliberately exclude full server paths.
		$sanitized = sprintf(
			'[%s] %s in %s on line %d',
			self::error_type_label( $error['type'] ),
			wp_strip_all_tags( $error['message'] ),
			basename( $error['file'] ),
			(int) $error['line']
		);

		try {
			self::$repo->update_event(
				self::$active_id,
				array(
					'diagnostic_status' => 'fatal_error',
					'fatal_error'       => $sanitized,
				)
			);
		} catch ( \Throwable $e ) {
			// Shutdown context — cannot do anything more.
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function error_type_label( int $type ): string {
		$map = array(
			E_ERROR         => 'E_ERROR',
			E_PARSE         => 'E_PARSE',
			E_CORE_ERROR    => 'E_CORE_ERROR',
			E_COMPILE_ERROR => 'E_COMPILE_ERROR',
			E_USER_ERROR    => 'E_USER_ERROR',
		);
		return $map[ $type ] ?? 'E_UNKNOWN';
	}
}
