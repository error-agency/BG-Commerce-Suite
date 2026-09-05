<?php
/**
 * Pigeon courier pickup requests (`POST /v1/courier-requests`).
 *
 * Building the request body and choosing which shipments to attach is pure
 * logic with no HTTP in it, so it lives here and can be asserted directly.
 *
 * @package BgCommerce3\Pigeon
 */

namespace BgCommerce3\Modules\Shipping\Pigeon;

defined( 'ABSPATH' ) || exit;

class Courier_Request {

	/** @var int Pigeon's own cap on attached shipments. */
	const MAX_REFERENCES = 100;

	/**
	 * Builds the request body from the posted form and the pickup settings.
	 *
	 * Validation mirrors the documented contract, so a request that cannot
	 * succeed is refused here with a Bulgarian reason instead of costing a
	 * round trip and coming back as a 422.
	 *
	 * @param array<string,string> $form       Posted values (date, time type/from/to, contact…).
	 * @param array<string,mixed>  $pickup     Pickup address settings (city_id, street_id, number…).
	 * @param string[]             $references Shipment references to attach.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function build( array $form, array $pickup, array $references = array() ) {
		$city_id = isset( $pickup['city_id'] ) ? (int) $pickup['city_id'] : 0;
		if ( $city_id <= 0 ) {
			return new \WP_Error(
				'bgcs3_pigeon_no_pickup_city',
				__( 'The pickup city is missing. The courier request requires a sender address — enter it under “Sender” and choose “Address” as the handover method.', 'bg-commerce-suite' )
			);
		}

		$date = isset( $form['date'] ) ? trim( (string) $form['date'] ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $date_parts ) || ! checkdate( (int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1] ) ) {
			return new \WP_Error( 'bgcs3_pigeon_bad_date', __( 'Select a pickup date.', 'bg-commerce-suite' ) );
		}
		$weekday = (int) gmdate( 'N', strtotime( $date . ' 12:00:00 UTC' ) );
		if ( $weekday >= 6 ) {
			return new \WP_Error( 'bgcs3_pigeon_closed_day', __( 'Pigeon courier pickup must be scheduled for a business day.', 'bg-commerce-suite' ) );
		}

		$time_type = ( isset( $form['time_type'] ) && 'specific_time' === $form['time_type'] ) ? 'specific_time' : 'interval';

		$from = self::time( isset( $form['time_from'] ) ? $form['time_from'] : '' );
		if ( '' === $from ) {
			return new \WP_Error( 'bgcs3_pigeon_bad_time', __( 'Select a pickup time.', 'bg-commerce-suite' ) );
		}

		$to = self::time( isset( $form['time_to'] ) ? $form['time_to'] : '' );
		if ( 'interval' === $time_type && '' === $to ) {
			return new \WP_Error(
				'bgcs3_pigeon_no_time_to',
				__( 'A time window also requires an end time.', 'bg-commerce-suite' )
			);
		}
		if ( 'interval' === $time_type && $to <= $from ) {
			return new \WP_Error(
				'bgcs3_pigeon_bad_interval',
				__( 'The end time must be after the start time.', 'bg-commerce-suite' )
			);
		}

		$body = array(
			'pickup_city_id'             => $city_id,
			'requested_pickup_date'      => $date,
			'requested_pickup_time_type' => $time_type,
			'requested_pickup_time_from' => $from,
		);

		if ( 'interval' === $time_type ) {
			$body['requested_pickup_time_to'] = $to;
		}

		// The street is optional only when the address is described in free
		// text, so one of the two must be there for the courier to find it.
		$street_id = isset( $pickup['street_id'] ) ? (int) $pickup['street_id'] : 0;
		$info      = isset( $form['additional_info'] ) ? trim( (string) $form['additional_info'] ) : '';

		if ( $street_id > 0 ) {
			$body['pickup_street_id'] = $street_id;
			if ( ! empty( $pickup['street_number'] ) ) {
				$body['pickup_street_number'] = self::cut( (string) $pickup['street_number'], 20 );
			}
		} elseif ( '' === $info ) {
			return new \WP_Error(
				'bgcs3_pigeon_no_pickup_street',
				__( 'The pickup street is missing. Select a street in the sender settings or describe the address under “Additional information”.', 'bg-commerce-suite' )
			);
		}

		if ( '' !== $info ) {
			$body['pickup_additional_info'] = $info;
		}

		foreach ( array( 'contact_name' => 255, 'contact_phone' => 50, 'contact_email' => 255, 'company_name' => 255 ) as $key => $limit ) {
			$value = isset( $form[ $key ] ) ? trim( (string) $form[ $key ] ) : '';
			if ( '' !== $value ) {
				$body[ $key ] = self::cut( $value, $limit );
			}
		}

		$references = self::references( $references );
		if ( ! empty( $references ) ) {
			$body['shipment_references'] = $references;
		}

		return $body;
	}

	/**
	 * Normalizes the shipments to attach: unique, non-empty, and never more
	 * than Pigeon accepts — a 101st reference would fail the whole request.
	 *
	 * @param string[] $references Raw references.
	 * @return string[]
	 */
	public static function references( array $references ) {
		$clean = array();

		foreach ( $references as $reference ) {
			if ( ! is_scalar( $reference ) ) {
				continue;
			}
			$reference = trim( (string) $reference );
			if ( '' === $reference ) {
				continue;
			}
			$clean[ $reference ] = self::cut( $reference, 50 );
		}

		return array_slice( array_values( $clean ), 0, self::MAX_REFERENCES );
	}

	/**
	 * `HH:MM`, or '' when the value is not a time.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function time( $value ) {
		$value = trim( (string) $value );

		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $match ) ) {
			return '';
		}

		$hour   = (int) $match[1];
		$minute = (int) $match[2];

		if ( $hour > 23 || $minute > 59 ) {
			return '';
		}

		return sprintf( '%02d:%02d', $hour, $minute );
	}

	/**
	 * @param string $value Value.
	 * @param int    $limit Maximum length.
	 * @return string
	 */
	private static function cut( $value, $limit ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
	}
}
