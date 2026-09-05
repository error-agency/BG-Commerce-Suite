<?php
/**
 * Main plugin orchestrator: owns the container, builds services and the
 * module registry, then fires the public registration hook for DLC add-ons.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3;

use BgCommerce3\Addon\Remote_Catalog;
use BgCommerce3\Container\Container;
use BgCommerce3\Module\Module_Registry;
use BgCommerce3\Rest\Rest;
use BgCommerce3\Checkout\Checkout;
use BgCommerce3\Admin\Settings\Settings_Page;
use BgCommerce3\Admin\Order\MetaBox;
use BgCommerce3\Admin\Order\Orders_Column;
use BgCommerce3\Background\Auto_Status;
use BgCommerce3\Background\Cod_Payout_Sync;
use BgCommerce3\Background\Locations_Sync;
use BgCommerce3\Support\Label_Pdf_Store;
use BgCommerce3\Email\Emails;
use BgCommerce3\Shipping\Pricing;
use BgCommerce3\Privacy\Policy;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Container */
	private $container;

	/** @var bool */
	private $booted = false;

	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @return Container
	 */
	public function container() {
		return $this->container;
	}

	/**
	 * Build services, register modules (built-in + DLC), and initialise.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		self::import_legacy_settings_once();
		self::maybe_upgrade_storage();
		self::maybe_cleanup_legacy_runtime();
		Label_Pdf_Store::register();
		Policy::init();
		Remote_Catalog::init();

		$c = $this->container;

		// Module registry.
		$c['modules'] = static function () {
			return new Module_Registry( BGCS3_API_VERSION );
		};

		/** @var Module_Registry $registry */
		$registry = $c['modules'];

		/**
		 * Public registration hook. DLC add-on plugins hook here to add their
		 * own modules (couriers, reputation, accounting, …).
		 *
		 * @param Module_Registry $registry
		 * @param Container       $container
		 */
		do_action( 'bgcs3_register_modules', $registry, $c );

		// Boot every enabled, compatible module.
		$registry->boot( $c );

		// Core background tasks (courier-agnostic): tracking-status sync and the
		// daily refresh of every enabled courier's cached reference data.
		$c['auto_status'] = static function ( $c ) {
			return new Auto_Status( $c );
		};
		$c['auto_status']->init();

		$c['cod_payout_sync'] = static function ( $c ) {
			return new Cod_Payout_Sync( $c );
		};
		$c['cod_payout_sync']->init();

		$c['locations_sync'] = static function ( $c ) {
			return new Locations_Sync( $c );
		};
		$c['locations_sync']->init();

		// REST API.
		$c['rest'] = static function ( $c ) {
			return new Rest( $c );
		};
		$c['rest']->init();

		// Checkout (classic + Blocks) — renders selector, persists selection.
		$c['checkout'] = static function ( $c ) {
			return new Checkout( $c );
		};
		$c['checkout']->init();

		// WooCommerce transactional emails are registered for both frontend and admin
		// requests so WooCommerce can list/configure them and background label
		// creation can trigger the same mail class.
		$c['emails'] = static function ( $c ) {
			return new Emails( $c );
		};
		$c['emails']->init();

		// Admin.
		if ( is_admin() ) {
			$c['settings_page'] = static function ( $c ) {
				return new Settings_Page( $c );
			};
			$c['settings_page']->init();

			$c['order_metabox'] = static function ( $c ) {
				return new MetaBox( $c );
			};
			$c['order_metabox']->init();

			$c['orders_column'] = static function ( $c ) {
				return new Orders_Column( $c );
			};
			$c['orders_column']->init();
		}

		do_action( 'bgcs3_booted', $this );
	}


	/**
	 * Remove recurring Action Scheduler jobs left by the retired BGCS 2.x stack.
	 *
	 * This is deliberately runtime-only cleanup: no legacy options, order meta,
	 * cached locations or files are deleted. We also refuse to touch the old
	 * scheduler group while a known legacy plugin is still active, so a merchant
	 * can safely compare/migrate before deactivating the old stack.
	 *
	 * The marker is written only after Action Scheduler is available and the
	 * cleanup actually ran; otherwise a later request retries automatically.
	 */
	private static function maybe_cleanup_legacy_runtime() {
		if ( 'yes' === get_option( 'bgcs3_legacy_runtime_cleaned', 'no' ) ) {
			return;
		}

		if ( function_exists( 'bgcs3_legacy_conflicts' ) && bgcs3_legacy_conflicts() ) {
			return;
		}

		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( array(
			'bgcs_schedule_tracking_sync',
			'bgcs_update_order_tracking_status',
			'bgcs_update_orders_tracking_status',
			'bgcs_sync_locations',
		) as $hook ) {
			as_unschedule_all_actions( $hook, array(), 'bgcs' );
		}

		update_option( 'bgcs3_legacy_runtime_cleaned', 'yes', false );
	}

	/**
	 * Keep grouped BGCS configuration out of WordPress autoload and advance the
	 * installed schema marker on ordinary plugin updates as well as activation.
	 */
	private static function maybe_upgrade_storage() {
		$installed = (string) get_option( 'bgcs3_installed_version', '0.0.0' );
		if ( version_compare( $installed, BGCS3_VERSION, '>=' ) ) {
			return;
		}

		foreach ( array( 'checkout', 'ui', 'speedy', 'econt', 'boxnow', 'pigeon', 'cod_reports' ) as $group ) {
			$name = 'bgcs3_' . $group;
			$current = get_option( $name, null );
			if ( null !== $current ) {
				update_option( $name, $current, false );
			}
		}

		// 3.0.21: finish the legacy static-price migration before the installed
		// version marker advances. This is idempotent per courier and intentionally
		// excludes BOX NOW, which owns a single weight-range pricing model.
		if ( version_compare( $installed, '3.0.21', '<' ) ) {
			Pricing::migrate_legacy( 'speedy', array( 'office', 'locker', 'address' ) );
			Pricing::migrate_legacy( 'econt', array( 'office', 'locker', 'address' ) );
			Pricing::migrate_legacy( 'pigeon', array( 'office', 'locker', 'address' ) );
		}

		// 3.0.49: BGCS-AUDIT-002 — Econt's `local_storage` field promised to control
		// whether the customer's selection is kept in their browser, but nothing
		// read it. The behaviour now exists, in Core, as
		// `checkout.remember_selection`. A merchant who had deliberately switched
		// the Econt field off (a real privacy choice on shared computers) must not
		// silently get it back on now that the setting finally works, so the old
		// value is carried over exactly once. The Econt value is left in place for
		// rollback and is no longer rendered or read.
		if ( version_compare( $installed, '3.0.49', '<' ) ) {
			Checkout::migrate_remember_selection();
		}

		// 3.0.50: undo 3.0.49's carry-over of Speedy's PMT-only free-shipping
		// choice. The old key governed one fee and the new one governs two, so
		// copying it forward billed a customer who had earned free shipping for a
		// charge the merchant had never opted into. Runs for installs that took
		// 3.0.49 and for those coming straight from 3.0.48, where the carry-over
		// is now simply never made.
		if ( version_compare( $installed, '3.0.50', '<' ) ) {
			\BgCommerce3\Modules\Shipping\Speedy\Speedy::undo_free_shipping_carryover();
		}

		// 3.0.16: migrate the old per-courier tracking-mail enable flag into the
		// native WooCommerce email exactly once. Old courier values are retained
		// untouched for rollback, but no longer rendered or read at runtime.
		if ( version_compare( $installed, '3.0.16', '<' ) && false === get_option( 'woocommerce_bgcs3_shipment_created_settings', false ) ) {
			$enabled = 'no';
			foreach ( array( 'speedy', 'econt', 'boxnow', 'pigeon' ) as $courier_id ) {
				if ( 'yes' === bgcs3_get_option( $courier_id, 'send_tracking_email', 'no' ) ) {
					$enabled = 'yes';
					break;
				}
			}
			add_option( 'woocommerce_bgcs3_shipment_created_settings', array( 'enabled' => $enabled ), '', false );
		}

		update_option( 'bgcs3_installed_version', BGCS3_VERSION, false );
	}

	/**
	 * Activation callback: set defaults without overwriting existing config.
	 */
	public static function on_activation() {
		// Import first so versioned migrations can see any useful legacy values.
		// Do not advance the installed-version marker before migrations run: a
		// merchant may deactivate an older BGCS build, upload the new ZIP, then
		// reactivate it, and that path must still execute the upgrade logic.
		self::import_legacy_settings_once();
		self::maybe_upgrade_storage();
	}

	/**
	 * Copy useful legacy settings into the independent BGCS 3 namespace.
	 * This is intentionally conservative and idempotent.
	 */
	private static function import_legacy_settings_once() {
		if ( 'yes' === get_option( 'bgcs3_legacy_settings_imported', 'no' ) ) {
			return;
		}

		$groups = array( 'checkout', 'ui', 'speedy', 'econt', 'boxnow', 'pigeon', 'cod_reports' );
		$copied = 0;

		foreach ( $groups as $group ) {
			$new_name = 'bgcs3_' . $group;
			if ( false !== get_option( $new_name, false ) ) {
				continue;
			}

			$legacy = get_option( 'bgcs_' . $group, false );
			if ( ! is_array( $legacy ) ) {
				continue;
			}

			if ( ! in_array( $group, array( 'checkout', 'ui' ), true ) ) {
				$legacy['enable'] = 'no';
			}

			// Migration must not resurrect legacy automation merely because the
			// new plugin was activated. Merchants can opt in again after review.
			if ( 'checkout' === $group ) {
				$legacy['auto_sync_locations']   = 'no';
				$legacy['update_order_statuses'] = 'no';
			}

			add_option( $new_name, $legacy, '', false );
			$copied++;
		}

		add_option( 'bgcs3_legacy_settings_imported', 'yes' );
		add_option( 'bgcs3_legacy_settings_import_count', $copied );
	}
}
