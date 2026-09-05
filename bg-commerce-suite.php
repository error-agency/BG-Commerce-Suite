<?php
/**
 * Plugin Name:       BG Commerce Suite
 * Plugin URI:        https://error.bg/bg-commerce-suite
 * Description:       Modular WooCommerce integration for Speedy, Econt, BOX NOW, Pigeon Express and COD reports.
 * Version:           4.3.2
 * Author:            Err.or
 * Author URI:        https://error.bg
 * Text Domain:       bg-commerce-suite
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.3
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.2
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package BgCommerce3
 */

defined( 'ABSPATH' ) || exit;

define( 'BGCS3_VERSION', '4.3.2' );
define( 'BGCS3_API_VERSION', '1.1' );
define( 'BGCS3_FILE', __FILE__ );
define( 'BGCS3_PATH', plugin_dir_path( __FILE__ ) );
define( 'BGCS3_URL', plugin_dir_url( __FILE__ ) );
define( 'BGCS3_BASENAME', plugin_basename( __FILE__ ) );
define( 'BGCS3_MIN_PHP', '7.4' );
define( 'BGCS3_MIN_WP', '6.3' );
define( 'BGCS3_MIN_WC', '8.2' );

// Internal module asset versions are intentionally tied to the BG Commerce Suite release.

require_once BGCS3_PATH . 'app/Autoloader.php';
\BgCommerce3\Autoloader::register();
require_once BGCS3_PATH . 'app/functions.php';
require_once BGCS3_PATH . 'app/Modules/Shipping/BoxNow/runtime-functions.php';

/**
 * Return active legacy BGCS plugins that would produce two concurrent stacks.
 * Files are never changed or deactivated automatically.
 *
 * @return string[]
 */
function bgcs3_legacy_conflicts() {
	$known = array(
		'bgcs-speedy/bgcs-speedy.php',
		'bgcs-econt/bgcs-econt.php',
		'bgcs-boxnow/bgcs-boxnow.php',
		'bgcs-pigeon/bgcs-pigeon.php',
		'bgcs-cod-reports/bgcs-cod-reports.php',
	);

	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		$active  = array_merge( $active, $network );
	}

	return array_values( array_intersect( $known, array_map( 'strval', $active ) ) );
}

/**
 * Register all product modules in the unified catalog. Runtime boot is still
 * gated by each module's own enable flag, so disabled modules remain visible
 * and configurable without registering shipping methods/API work.
 */
function bgcs3_prepare_internal_modules() {
	$base = BGCS3_PATH . 'app/Modules/';

	$manifests = array(
		array(
			'slug'         => 'speedy',
			'namespace'    => 'BgCommerce3\\Modules\\Shipping\\Speedy\\',
			'path'         => $base . 'Shipping/Speedy',
			'module_class' => \BgCommerce3\Modules\Shipping\Speedy\Speedy::class,
			'logo'         => BGCS3_URL . 'assets/img/speedy.svg',
		),
		array(
			'slug'         => 'econt',
			'namespace'    => 'BgCommerce3\\Modules\\Shipping\\Econt\\',
			'path'         => $base . 'Shipping/Econt',
			'module_class' => \BgCommerce3\Modules\Shipping\Econt\Econt::class,
			'logo'         => BGCS3_URL . 'assets/img/econt.png',
		),
		array(
			'slug'         => 'boxnow',
			'namespace'    => 'BgCommerce3\\Modules\\Shipping\\BoxNow\\',
			'path'         => $base . 'Shipping/BoxNow',
			'module_class' => \BgCommerce3\Modules\Shipping\BoxNow\BoxNow::class,
			'logo'         => BGCS3_URL . 'assets/img/boxnow.png',
			'icon'         => 'package',
		),
		array(
			'slug'         => 'pigeon',
			'namespace'    => 'BgCommerce3\\Modules\\Shipping\\Pigeon\\',
			'path'         => $base . 'Shipping/Pigeon',
			'module_class' => \BgCommerce3\Modules\Shipping\Pigeon\Pigeon::class,
			'logo'         => BGCS3_URL . 'assets/img/pigeon.png',
			'icon'         => 'package',
		),
		array(
			'slug'         => 'cod_reports',
			'namespace'    => 'BgCommerce3\\Modules\\Accounting\\CodReports\\',
			'path'         => $base . 'Accounting/CodReports',
			'module_class' => \BgCommerce3\Modules\Accounting\CodReports\Cod_Reports::class,
			'icon'         => 'receipt',
		),
	);

	foreach ( $manifests as $manifest ) {
		\BgCommerce3\Addon\Bootstrap::boot( $manifest );
	}

	// BOX NOW has a small admin helper asset layer that used to live in the
	// standalone add-on bootstrap. It is now owned by the unified plugin.
	add_action( 'admin_enqueue_scripts', 'bgcs3_boxnow_admin_enqueue', 20 );

	/**
	 * BGCS Module API is ready for independent add-on plugins.
	 *
	 * Add-ons should hook here and call \BgCommerce3\Addon\Bootstrap::boot().
	 * The registry itself is created later during Core boot, so Bootstrap queues
	 * the module factory on `bgcs3_register_modules` without depending on plugin
	 * load order. If Core is inactive this action never fires and the add-on stays
	 * inert.
	 */
	do_action( 'bgcs3_api_ready' );
}
add_action( 'plugins_loaded', 'bgcs3_prepare_internal_modules', 5 );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BGCS3_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BGCS3_FILE, true );
	}
);

/**
 * Unified plugin bootstrap.
 */
function bgcs3_bootstrap() {
	load_plugin_textdomain( 'bg-commerce-suite', false, dirname( BGCS3_BASENAME ) . '/languages' );

	$conflicts = bgcs3_legacy_conflicts();
	if ( $conflicts ) {
		add_action(
			'admin_notices',
			static function () use ( $conflicts ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p><strong>BG Commerce Suite:</strong> ' .
					esc_html__( 'Active legacy BGCS plugins were detected. To avoid running two versions at the same time, deactivate the old BG Commerce Suite and its courier add-ons. Their files and settings will not be deleted.', 'bg-commerce-suite' ) .
					'</p><ul style="list-style:disc;padding-left:20px">';
				foreach ( $conflicts as $file ) {
					echo '<li><code>' . esc_html( $file ) . '</code></li>';
				}
				echo '</ul></div>';
			}
		);
		return;
	}

	$errors = bgcs3_environment_errors();
	if ( $errors ) {
		add_action(
			'admin_notices',
			static function () use ( $errors ) {
				echo '<div class="notice notice-error"><p><strong>BG Commerce Suite:</strong></p><ul style="list-style:disc;padding-left:20px">';
				foreach ( $errors as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul></div>';
			}
		);
		return;
	}

	\BgCommerce3\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'bgcs3_bootstrap', 9 );

/**
 * @return string[]
 */
function bgcs3_environment_errors() {
	$errors = array();
	if ( version_compare( PHP_VERSION, BGCS3_MIN_PHP, '<' ) ) {
		$errors[] = sprintf( __( 'Requires PHP %s or newer.', 'bg-commerce-suite' ), BGCS3_MIN_PHP );
	}
	if ( version_compare( get_bloginfo( 'version' ), BGCS3_MIN_WP, '<' ) ) {
		$errors[] = sprintf( __( 'Requires WordPress %s or newer.', 'bg-commerce-suite' ), BGCS3_MIN_WP );
	}
	if ( ! class_exists( 'WooCommerce' ) ) {
		$errors[] = __( 'Requires WooCommerce to be active.', 'bg-commerce-suite' );
	} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, BGCS3_MIN_WC, '<' ) ) {
		$errors[] = sprintf( __( 'Requires WooCommerce %s or newer.', 'bg-commerce-suite' ), BGCS3_MIN_WC );
	}
	return $errors;
}

register_activation_hook( __FILE__, array( '\\BgCommerce3\\Plugin', 'on_activation' ) );
register_deactivation_hook( __FILE__, 'bgcs3_deactivate' );

/**
 * Remove only BGCS 3-owned scheduled work; data is retained.
 */
function bgcs3_deactivate() {
	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		return;
	}
	foreach ( array(
		'bgcs3_schedule_tracking_sync',
		'bgcs3_update_order_tracking_status',
		'bgcs3_update_orders_tracking_status',
		'bgcs3_schedule_cod_payout_sync',
		'bgcs3_sync_cod_payouts',
		'bgcs3_sync_locations',
		'bgcs3_sync_product_catalog',
	) as $hook ) {
		as_unschedule_all_actions( $hook, array(), 'bgcs3' );
	}
}
