<?php
/**
 * Locations Sync Background Task: refreshes each enabled courier's cached
 * reference data (offices/lockers, services, contracts) on a daily schedule via
 * WooCommerce Action Scheduler. The manual equivalent is the "Синхронизирай
 * данните" button in the courier settings tab.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Background;

use BgCommerce3\Container\Container;
use BgCommerce3\Module\Categories;
use BgCommerce3\Support\Sync_Result;
use BgCommerce3\Support\Options;

defined( 'ABSPATH' ) || exit;

class Locations_Sync {

	const HOOK  = 'bgcs3_sync_locations';
	const GROUP = 'bgcs3';

	/** @var Container */
	private $container;

	/**
	 * @param Container $container Core DI container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function init() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Schedule (or unschedule) the daily sync based on the setting.
	 */
	public function maybe_schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$enabled   = 'yes' === bgcs3_get_option( 'checkout', 'auto_sync_locations', 'no' ) && $this->has_enabled_courier();
		$scheduled = as_has_scheduled_action( self::HOOK, array(), self::GROUP );

		if ( $enabled && ! $scheduled ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
		} elseif ( ! $enabled && $scheduled && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Whether at least one shipping module is currently enabled.
	 *
	 * @return bool
	 */
	private function has_enabled_courier() {
		if ( ! isset( $this->container['modules'] ) ) {
			return false;
		}
		$grouped = $this->container['modules']->by_category();
		foreach ( isset( $grouped[ Categories::SHIPPING ] ) ? $grouped[ Categories::SHIPPING ] : array() as $module ) {
			if ( $module->is_enabled() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Refresh every enabled courier's cached data.
	 */
	public function run() {
		if ( ! isset( $this->container['modules'] ) ) {
			return;
		}

		$grouped  = $this->container['modules']->by_category();
		$couriers = isset( $grouped[ Categories::SHIPPING ] ) ? $grouped[ Categories::SHIPPING ] : array();

		foreach ( $couriers as $module ) {
			if ( $module->is_enabled() && method_exists( $module, 'sync_data' ) ) {
				$result = Sync_Result::from_mixed( $module->sync_data() )->to_array();
				if ( 'success' === $result['level'] ) {
					// Keep the settings screen's "last successful sync" indicator in
					// sync with automatic jobs as well as the manual sync button.
					Options::set( $module->id(), '_last_sync_at', time() );
				}

				if ( function_exists( 'wc_get_logger' ) && 'success' !== $result['level'] ) {
					wc_get_logger()->warning(
						$result['message'],
						array(
							'source'    => 'bgcs-locations-sync',
							'courier'   => $module->id(),
							'level'     => $result['level'],
							'counts'    => $result['counts'],
						)
					);
				}
			}
		}
	}
}
