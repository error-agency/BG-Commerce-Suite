<?php
/**
 * Small diagnostics registry for provider tracking statuses Core cannot map yet.
 *
 * Stores only the courier id and raw status/code string — never payloads, names,
 * addresses or other shipment data. The list is bounded so diagnostics cannot
 * grow without limit when a provider introduces a new vocabulary item.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Modules\Shipping\Courier_Interface;

defined( 'ABSPATH' ) || exit;

final class Tracking_Unmapped_Registry {

	const OPTION = 'bgcs3_unmapped_tracking_statuses';
	const LIMIT  = 50;

	/**
	 * Inspect one provider event and remember it only when it normalizes to UNKNOWN.
	 *
	 * @param Courier_Interface   $courier Courier module.
	 * @param array<string,mixed> $event Provider event.
	 */
	public static function record_event( Courier_Interface $courier, array $event ) {
		$normalized = Tracking_State::sanitize( $courier->normalize_status( $event ) );
		if ( Tracking_State::UNKNOWN !== $normalized ) {
			return;
		}

		$raw = '';
		foreach ( array( 'code', 'status', 'state', 'event' ) as $key ) {
			if ( isset( $event[ $key ] ) && '' !== trim( (string) $event[ $key ] ) ) {
				$raw = (string) $event[ $key ];
				break;
			}
		}
		self::record_code( $courier->id(), $raw );
	}

	/**
	 * @param string $courier_id Courier id.
	 * @param string $raw_status Raw provider status/code.
	 */
	public static function record_code( $courier_id, $raw_status ) {
		$courier_id = sanitize_key( (string) $courier_id );
		$raw_status = trim( sanitize_text_field( (string) $raw_status ) );
		if ( '' === $courier_id || '' === $raw_status ) {
			return;
		}

		// Keep the diagnostic value short and payload-free.
		if ( function_exists( 'mb_substr' ) ) {
			$raw_status = mb_substr( $raw_status, 0, 120 );
		} else {
			$raw_status = substr( $raw_status, 0, 120 );
		}

		$items = get_option( self::OPTION, array() );
		$items = is_array( $items ) ? $items : array();
		$key   = $courier_id . ':' . md5( strtolower( $raw_status ) );
		$now   = time();

		if ( isset( $items[ $key ] ) && is_array( $items[ $key ] ) ) {
			$items[ $key ]['count']     = max( 1, (int) $items[ $key ]['count'] ) + 1;
			$items[ $key ]['last_seen'] = $now;
		} else {
			$items[ $key ] = array(
				'courier'    => $courier_id,
				'status'     => $raw_status,
				'count'      => 1,
				'first_seen' => $now,
				'last_seen'  => $now,
			);
		}

		uasort(
			$items,
			static function ( $a, $b ) {
				return (int) ( $b['last_seen'] ?? 0 ) <=> (int) ( $a['last_seen'] ?? 0 );
			}
		);
		$items = array_slice( $items, 0, self::LIMIT, true );
		update_option( self::OPTION, $items, false );
	}

	/**
	 * @param string $courier_id Courier id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_courier( $courier_id ) {
		$courier_id = sanitize_key( (string) $courier_id );
		$items      = get_option( self::OPTION, array() );
		$items      = is_array( $items ) ? $items : array();
		$out        = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && $courier_id === (string) ( $item['courier'] ?? '' ) ) {
				$out[] = $item;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return (int) ( $b['last_seen'] ?? 0 ) <=> (int) ( $a['last_seen'] ?? 0 );
			}
		);
		return $out;
	}
}
