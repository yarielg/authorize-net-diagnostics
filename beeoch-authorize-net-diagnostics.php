<?php
/**
 * Plugin Name: BEE-OCH Authorize.Net Diagnostics
 * Plugin URI:  https://beeoch.com
 * Description: Lightweight standalone diagnostic logger for the WooCommerce Authorize.Net CIM gateway. Captures API events, payment-state transitions, and failure modes without modifying the gateway or WooCommerce core.
 * Version:     1.0.0
 * Author:      BEE-OCH
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.9
 * Text Domain: beeoch-authnet-diagnostics
 */

defined( 'ABSPATH' ) || exit;

define( 'BEEOCH_AUTHNET_DIAG_VERSION', '1.0.0' );
define( 'BEEOCH_AUTHNET_DIAG_FILE',    __FILE__ );
define( 'BEEOCH_AUTHNET_DIAG_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BEEOCH_AUTHNET_DIAG_URL',     plugin_dir_url( __FILE__ ) );

// ── Require class files ───────────────────────────────────────────────────────
require_once BEEOCH_AUTHNET_DIAG_DIR . 'includes/class-repository.php';
require_once BEEOCH_AUTHNET_DIAG_DIR . 'includes/class-installer.php';
require_once BEEOCH_AUTHNET_DIAG_DIR . 'includes/class-settings.php';
require_once BEEOCH_AUTHNET_DIAG_DIR . 'includes/class-shutdown-handler.php';
require_once BEEOCH_AUTHNET_DIAG_DIR . 'includes/class-api-listener.php';
require_once BEEOCH_AUTHNET_DIAG_DIR . 'includes/class-payment-listener.php';
require_once BEEOCH_AUTHNET_DIAG_DIR . 'includes/class-classifier.php';
require_once BEEOCH_AUTHNET_DIAG_DIR . 'includes/class-cleanup.php';
require_once BEEOCH_AUTHNET_DIAG_DIR . 'includes/class-plugin.php';

// ── Activation / deactivation — must not reference WC or AN classes ───────────
register_activation_hook( __FILE__, array( 'BEEOCHAuthNetDiag\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BEEOCHAuthNetDiag\\Installer', 'deactivate' ) );

// ── Declare HPOS compatibility before WC initializes ─────────────────────────
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			BEEOCH_AUTHNET_DIAG_FILE,
			true
		);
	}
} );

// ── Bootstrap after all plugins are loaded ────────────────────────────────────
add_action( 'plugins_loaded', function () {
	BEEOCHAuthNetDiag\Plugin::instance();
}, 20 );
