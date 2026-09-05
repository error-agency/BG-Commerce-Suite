<?php
/**
 * Per-courier tracking-synchronization settings Core injects into every
 * shipping module's settings tab (Rule 246), mirroring how {@see Pricing}
 * injects the pricing ladder — no courier plugin declares these fields
 * itself.
 *
 * Deliberately separate from "Automatic WooCommerce order status changes"
 * (Rule 247): this class only controls whether/how often Core FETCHES fresh
 * tracking data. Whether a resolved state is then allowed to change the
 * WooCommerce order status remains the existing, independent
 * `checkout.update_order_statuses` setting — a merchant can want always-fresh
 * tracking with fully manual order-status management (Rule 247/248).
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

final class Tracking_Sync {

	/**
	 * Allowed sync intervals, in minutes (Rule 246).
	 *
	 * @return int[]
	 */
	/**
	 * Minutes between checks for one shipment when nothing is configured.
	 *
	 * Four hours is six checks a day, which sits inside Speedy's published
	 * "3 to 5 times per day for a shipment". The previous default of one hour
	 * was 24.
	 */
	const DEFAULT_INTERVAL = 240;

	public static function allowed_intervals() {
		// The longer options exist because couriers ask for them. Speedy's own
		// good practice is 3-5 checks per DAY for a shipment; 30 minutes is 48,
		// which is not a neutral choice made on their servers' behalf.
		return array( 30, 60, 120, 240, 360, 480 );
	}

	/**
	 * Settings fields Core injects for a courier module (merged after the
	 * module's own settings_fields(), same pattern as Pricing::fields_for()).
	 *
	 * @param Courier_Interface $module Courier.
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields_for( Courier_Interface $module ) {
		unset( $module );

		return array(
			// Rule 248 — safe default is ON: fresh tracking data without any
			// consequential order change, since WC status automation stays a
			// separate opt-in below.
			'tracking_sync_enabled'  => array(
				'type'           => 'checkbox',
				'label'          => __( 'Automatic tracking synchronization', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Automatically update shipment statuses in the background', 'bg-commerce-suite' ),
				'default'        => 'yes',
				'description'    => __( 'Only fetches and saves the current courier status — it does not change the order status by itself (see the setting below under “General”).', 'bg-commerce-suite' ),
			),
			'tracking_sync_interval' => array(
				'type'        => 'select',
				'label'       => __( 'Synchronization interval', 'bg-commerce-suite' ),
				'default'     => '240',
				'options'     => array(
					'30'  => __( '30 minutes', 'bg-commerce-suite' ),
					'60'  => __( '60 minutes', 'bg-commerce-suite' ),
					'120' => __( '2 hours', 'bg-commerce-suite' ),
					'240' => __( '4 hours (recommended)', 'bg-commerce-suite' ),
					'360' => __( '6 hours', 'bg-commerce-suite' ),
					'480' => __( '8 hours', 'bg-commerce-suite' ),
				),
				'description' => __( 'How often to check an active shipment for a new status. Speedy recommends 3–5 checks per shipment per day; every 4 hours is 6 checks. A shorter interval does not provide more information — shipments travel overnight and the status does not change — but it increases load on the courier.', 'bg-commerce-suite' ),
				'show_if'     => array( 'tracking_sync_enabled' => 'yes' ),
			),
		);
	}

	/**
	 * Settings section grouping the injected fields (Rule 246 — same
	 * shared-Core-framework settings screen every courier already uses).
	 *
	 * @param Courier_Interface $module Courier.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sections_for( Courier_Interface $module ) {
		unset( $module );

		return array(
			array(
				'title'  => __( 'Tracking', 'bg-commerce-suite' ),
				'desc'   => __( 'Automatically update the shipment status from the courier.', 'bg-commerce-suite' ),
				'icon'   => 'activity',
				'fields' => array( 'tracking_sync_enabled', 'tracking_sync_interval' ),
			),
		);
	}

	/**
	 * @param string $courier_id Courier module id.
	 * @return bool
	 */
	public static function is_enabled( $courier_id ) {
		return 'yes' === Module_Settings::get( $courier_id, 'tracking_sync_enabled' );
	}

	/**
	 * @param string $courier_id Courier module id.
	 * @return int One of allowed_intervals() — an invalid/missing stored value falls back to 60.
	 */
	public static function interval_minutes( $courier_id ) {
		$raw = (int) Module_Settings::get( $courier_id, 'tracking_sync_interval' );
		return in_array( $raw, self::allowed_intervals(), true ) ? $raw : self::DEFAULT_INTERVAL;
	}

	/**
	 * Pure due-time check (Rule 246) — no live clock/WP dependency beyond an
	 * optional injectable "now" for tests.
	 *
	 * @param int      $last_synced_at Unix timestamp of the last successful sync, 0 = never synced.
	 * @param int      $interval_minutes Configured interval, in minutes.
	 * @param int|null $now            Injectable "current time" for tests; defaults to time().
	 * @return bool
	 */
	public static function is_due( $last_synced_at, $interval_minutes, $now = null ) {
		if ( 0 >= $last_synced_at ) {
			return true;
		}
		$now = null === $now ? time() : $now;
		return ( $now - $last_synced_at ) >= ( $interval_minutes * 60 );
	}
}
