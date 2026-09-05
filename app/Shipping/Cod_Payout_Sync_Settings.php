<?php
/**
 * Shared automatic COD payout-reconciliation settings for couriers that expose
 * an official payout/report API.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

final class Cod_Payout_Sync_Settings {

	const DEFAULT_INTERVAL = 720; // 12 hours.

	/** @return int[] */
	public static function allowed_intervals() {
		return array( 360, 720, 1440 );
	}

	/**
	 * @param Courier_Interface $module Courier.
	 * @return bool
	 */
	public static function supports( Courier_Interface $module ) {
		return method_exists( $module, 'supports_cod_payouts' )
			&& $module->supports_cod_payouts()
			&& method_exists( $module, 'cod_payouts' );
	}

	/**
	 * @param Courier_Interface $module Courier.
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields_for( Courier_Interface $module ) {
		if ( ! self::supports( $module ) ) {
			return array();
		}

		return array(
			'cod_payout_sync_enabled'  => array(
				'type'           => 'checkbox',
				'label'          => __( 'Automatic COD payout synchronization', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Automatically reconcile courier-confirmed COD payouts with WooCommerce orders', 'bg-commerce-suite' ),
				'default'        => 'yes',
				'description'    => __( 'BGCS reads the courier payout report in the background and marks COD as paid only when the shipment number, amount, currency and payout date match the shipment snapshot. It never creates a WooCommerce refund and never changes the payment status automatically.', 'bg-commerce-suite' ),
			),
			'cod_payout_sync_interval' => array(
				'type'        => 'select',
				'label'       => __( 'COD payout synchronization interval', 'bg-commerce-suite' ),
				'default'     => (string) self::DEFAULT_INTERVAL,
				'options'     => array(
					'360'  => __( '6 hours', 'bg-commerce-suite' ),
					'720'  => __( '12 hours (recommended)', 'bg-commerce-suite' ),
					'1440' => __( '24 hours', 'bg-commerce-suite' ),
				),
				'description' => __( 'Payout reports change much less often than tracking statuses, so a slower interval reduces unnecessary courier API calls.', 'bg-commerce-suite' ),
				'show_if'     => array( 'cod_payout_sync_enabled' => 'yes' ),
			),
		);
	}

	/**
	 * @param Courier_Interface $module Courier.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sections_for( Courier_Interface $module ) {
		if ( ! self::supports( $module ) ) {
			return array();
		}

		return array(
			array(
				'title'  => __( 'COD payouts', 'bg-commerce-suite' ),
				'desc'   => __( 'Safely reconcile courier payout reports with COD orders.', 'bg-commerce-suite' ),
				'icon'   => 'banknote',
				'fields' => self::field_keys_for( $module ),
			),
		);
	}

	/**
	 * @param Courier_Interface|null $module Optional courier.
	 * @return string[]
	 */
	public static function field_keys_for( $module = null ) {
		if ( $module instanceof Courier_Interface && ! self::supports( $module ) ) {
			return array();
		}
		return array( 'cod_payout_sync_enabled', 'cod_payout_sync_interval' );
	}

	/** @param string $courier_id Courier id. @return bool */
	public static function is_enabled( $courier_id ) {
		return 'yes' === Module_Settings::get( sanitize_key( (string) $courier_id ), 'cod_payout_sync_enabled' );
	}

	/** @param string $courier_id Courier id. @return int */
	public static function interval_minutes( $courier_id ) {
		$raw = (int) Module_Settings::get( sanitize_key( (string) $courier_id ), 'cod_payout_sync_interval' );
		return in_array( $raw, self::allowed_intervals(), true ) ? $raw : self::DEFAULT_INTERVAL;
	}
}
