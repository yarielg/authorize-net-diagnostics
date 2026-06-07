<?php
namespace BEEOCHAuthNetDiag;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation, deactivation, and database schema.
 * Must not reference WooCommerce or Authorize.Net classes.
 */
class Installer {

	const DB_VERSION_OPTION = 'beeoch_authnet_diag_db_version';
	const DB_VERSION        = '1.0.0';
	const TABLE_SUFFIX      = 'beeoch_authnet_diagnostics';

	// ── Activation ────────────────────────────────────────────────────────────

	public static function activate(): void {
		self::create_table();
		self::schedule_cleanup();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	// ── Deactivation ──────────────────────────────────────────────────────────

	public static function deactivate(): void {
		self::unschedule_cleanup();
	}

	// ── Table creation ────────────────────────────────────────────────────────

	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table      = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset    = $wpdb->get_charset_collate();

		// dbDelta requires: two spaces before data type, PRIMARY KEY with two spaces.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  correlation_key varchar(100) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned DEFAULT NULL,
  order_number varchar(100) DEFAULT NULL,
  transaction_id varchar(100) DEFAULT NULL,
  request_type varchar(100) DEFAULT NULL,
  diagnostic_status varchar(50) NOT NULL DEFAULT 'pending',
  http_status smallint(5) DEFAULT NULL,
  api_response_code varchar(100) DEFAULT NULL,
  api_response_message text DEFAULT NULL,
  request_duration decimal(10,5) DEFAULT NULL,
  request_body longtext DEFAULT NULL,
  response_body longtext DEFAULT NULL,
  transaction_saved tinyint(1) NOT NULL DEFAULT 0,
  payment_completed tinyint(1) NOT NULL DEFAULT 0,
  final_order_status varchar(50) DEFAULT NULL,
  fatal_error longtext DEFAULT NULL,
  database_error longtext DEFAULT NULL,
  created_at_gmt datetime NOT NULL,
  updated_at_gmt datetime NOT NULL,
  resolved_at_gmt datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY order_number (order_number(50)),
  KEY transaction_id (transaction_id),
  KEY diagnostic_status (diagnostic_status),
  KEY created_at_gmt (created_at_gmt),
  KEY correlation_key (correlation_key(50))
) {$charset};";

		dbDelta( $sql );
	}

	// ── Cleanup scheduling ────────────────────────────────────────────────────

	public static function schedule_cleanup(): void {
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_next_scheduled_action( 'beeoch_authnet_daily_cleanup', array(), 'beeoch-authnet-diag' ) ) {
				as_schedule_recurring_action(
					strtotime( 'tomorrow 02:00:00' ),
					DAY_IN_SECONDS,
					'beeoch_authnet_daily_cleanup',
					array(),
					'beeoch-authnet-diag'
				);
			}
		} else {
			if ( ! wp_next_scheduled( 'beeoch_authnet_daily_cleanup' ) ) {
				wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', 'beeoch_authnet_daily_cleanup' );
			}
		}
	}

	public static function unschedule_cleanup(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'beeoch_authnet_daily_cleanup', array(), 'beeoch-authnet-diag' );
		} else {
			wp_clear_scheduled_hook( 'beeoch_authnet_daily_cleanup' );
		}
	}

	// ── Upgrade check ─────────────────────────────────────────────────────────

	public static function maybe_upgrade(): void {
		$installed = get_option( self::DB_VERSION_OPTION, '' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_table();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}
}
