<?php
/**
 * Courier capability matrix for canonical BGCS shipment states.
 *
 * The raw provider vocabulary remains owned by each courier implementation.
 * This catalog only describes which normalized states Core can confidently
 * obtain for each built-in courier and provides merchant-facing context.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Tracking_Status_Catalog {

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function states() {
		return array(
			Tracking_State::CREATED => array(
				'label' => Tracking_State::label( Tracking_State::CREATED ),
				'couriers' => array( 'speedy', 'econt', 'boxnow', 'pigeon' ),
			),
			Tracking_State::ACCEPTED => array(
				'label' => Tracking_State::label( Tracking_State::ACCEPTED ),
				'couriers' => array( 'speedy', 'econt', 'pigeon' ),
			),
			Tracking_State::IN_TRANSIT => array(
				'label' => Tracking_State::label( Tracking_State::IN_TRANSIT ),
				'couriers' => array( 'speedy', 'econt', 'boxnow', 'pigeon' ),
			),
			Tracking_State::OUT_FOR_DELIVERY => array(
				'label' => Tracking_State::label( Tracking_State::OUT_FOR_DELIVERY ),
				'couriers' => array( 'speedy', 'econt', 'pigeon' ),
			),
			Tracking_State::AVAILABLE_FOR_PICKUP => array(
				'label' => Tracking_State::label( Tracking_State::AVAILABLE_FOR_PICKUP ),
				'couriers' => array( 'speedy', 'econt', 'boxnow', 'pigeon' ),
			),
			Tracking_State::DELIVERED => array(
				'label' => Tracking_State::label( Tracking_State::DELIVERED ),
				'couriers' => array( 'speedy', 'econt', 'boxnow', 'pigeon' ),
			),
			Tracking_State::DELIVERY_FAILED => array(
				'label' => Tracking_State::label( Tracking_State::DELIVERY_FAILED ),
				'couriers' => array( 'speedy', 'econt', 'pigeon' ),
			),
			Tracking_State::REDIRECTED => array(
				'label' => Tracking_State::label( Tracking_State::REDIRECTED ),
				'couriers' => array( 'speedy', 'econt', 'pigeon' ),
			),
			Tracking_State::RETURN_IN_PROGRESS => array(
				'label' => Tracking_State::label( Tracking_State::RETURN_IN_PROGRESS ),
				'couriers' => array( 'speedy', 'econt', 'boxnow', 'pigeon' ),
			),
			Tracking_State::RETURNED => array(
				'label' => Tracking_State::label( Tracking_State::RETURNED ),
				'couriers' => array( 'speedy', 'econt', 'boxnow', 'pigeon' ),
			),
			Tracking_State::CANCELLED => array(
				'label' => Tracking_State::label( Tracking_State::CANCELLED ),
				'couriers' => array( 'speedy', 'econt', 'boxnow', 'pigeon' ),
			),
			Tracking_State::EXCEPTION => array(
				'label' => Tracking_State::label( Tracking_State::EXCEPTION ),
				'couriers' => array( 'speedy', 'econt', 'boxnow', 'pigeon' ),
			),
		);
	}

	/**
	 * Provider-specific raw events worth surfacing in the settings UI without
	 * pretending they are separate universal WooCommerce states.
	 *
	 * @return array<string,string[]>
	 */
	public static function provider_details() {
		return array(
			'speedy' => array(
				__( 'Deferred delivery', 'bg-commerce-suite' ),
				__( 'Redirected shipment', 'bg-commerce-suite' ),
				__( 'Insurance department processing', 'bg-commerce-suite' ),
				__( 'Destruction / theft / administrative closure', 'bg-commerce-suite' ),
			),
			'econt' => array(
				__( 'Failed delivery', 'bg-commerce-suite' ),
				__( 'Redirect', 'bg-commerce-suite' ),
				__( 'Cancelled before/after sending', 'bg-commerce-suite' ),
				__( 'Returning to sender / returned', 'bg-commerce-suite' ),
				__( 'Destruction', 'bg-commerce-suite' ),
			),
			'boxnow' => array(
				__( 'Wait for load — waiting for courier at locker', 'bg-commerce-suite' ),
				__( 'Expired / Expired-return — storage period expired and return', 'bg-commerce-suite' ),
				__( 'Cancelled-return — cancelled return', 'bg-commerce-suite' ),
			),
			'pigeon' => array(
				__( 'Locker / warehouse storage period expired', 'bg-commerce-suite' ),
				__( 'Delivery issue / abandoned shipment', 'bg-commerce-suite' ),
				__( 'Redirect', 'bg-commerce-suite' ),
			),
		);
	}

	/**
	 * @param string $courier_id Courier id.
	 * @return string[]
	 */
	public static function supported_states( $courier_id ) {
		$courier_id = sanitize_key( (string) $courier_id );
		$out = array();
		foreach ( self::states() as $state => $meta ) {
			if ( in_array( $courier_id, (array) $meta['couriers'], true ) ) {
				$out[] = $state;
			}
		}
		return $out;
	}

	/**
	 * @param string $state Canonical state.
	 * @return string[] Courier ids.
	 */
	public static function couriers_for( $state ) {
		$states = self::states();
		return isset( $states[ $state ] ) ? (array) $states[ $state ]['couriers'] : array();
	}

	/**
	 * @param string $id Courier id.
	 * @return string
	 */
	public static function courier_name( $id ) {
		$names = array(
			'speedy' => 'Speedy',
			'econt'  => 'Econt',
			'boxnow' => 'BOX NOW',
			'pigeon' => 'Pigeon Express',
		);
		return isset( $names[ $id ] ) ? $names[ $id ] : $id;
	}

	/**
	 * Human description of which couriers expose a normalized state.
	 *
	 * @param string $state Canonical state.
	 * @return string
	 */
	public static function availability_note( $state ) {
		$ids = self::couriers_for( $state );
		$names = array_map( array( __CLASS__, 'courier_name' ), $ids );
		if ( 1 === count( $names ) ) {
			return sprintf( __( 'This status is specific to %s.', 'bg-commerce-suite' ), $names[0] );
		}
		return sprintf( __( 'Recognized for: %s.', 'bg-commerce-suite' ), implode( ', ', $names ) );
	}
}
