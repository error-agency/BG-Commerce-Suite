<?php
/**
 * Normalizes courier-provided delivery estimates for checkout and order use.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Delivery_Estimate {

	const KIND_DEADLINE = 'deadline';
	const KIND_ESTIMATE = 'estimate';

	/**
	 * Convert a provider date/deadline to the small persisted BGCS contract.
	 *
	 * @param mixed  $raw     Provider value.
	 * @param string $courier Courier id.
	 * @param string $kind    'deadline' when the courier commits to a time,
	 *                        'estimate' when it only predicts one.
	 * @return array<string,string>
	 */
	public static function normalize( $raw, $courier, $kind = self::KIND_ESTIMATE ) {
		if ( ! is_scalar( $raw ) || '' === trim( (string) $raw ) ) {
			return array();
		}

		$value = trim( (string) $raw );
		$kind  = self::KIND_DEADLINE === $kind ? self::KIND_DEADLINE : self::KIND_ESTIMATE;
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
				return array();
			}

			return array(
				'value'     => $value,
				'precision' => 'date',
				'courier'   => sanitize_key( $courier ),
				'kind'      => $kind,
			);
		}

		// Some courier APIs still expose Microsoft JSON dates such as
		// /Date(1725530400000+0300)/.
		if ( preg_match( '#^/Date\((-?\d+)(?:[+-]\d{4})?\)/$#', $value, $matches ) ) {
			$value = (string) floor( (float) $matches[1] / 1000 );
		}
		if ( ! is_numeric( $value ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $value ) ) {
			return array();
		}

		$numeric = is_numeric( $value );
		try {
			if ( $numeric ) {
				$timestamp = (float) $value;
				if ( abs( $timestamp ) > 9999999999 ) {
					$timestamp /= 1000;
				}
				$date = ( new \DateTimeImmutable( '@' . (string) (int) $timestamp ) )->setTimezone( self::timezone() );
			} else {
				$date = new \DateTimeImmutable( $value, self::timezone() );
			}
		} catch ( \Exception $exception ) {
			return array();
		}

		// A courier that sends a bare Unix timestamp is naming a day, not an
		// appointment: Econt's expectedDeliveryDate arrives as local midnight.
		// Reported as a datetime it would promise the customer "05.09.2026
		// 00:00". An ISO string that spells out 00:00:00 is left alone — there
		// the courier stated a time on purpose.
		$numeric_midnight = $numeric && '00:00:00' === $date->format( 'H:i:s' );

		return array(
			'value'     => $numeric_midnight ? $date->format( 'Y-m-d' ) : $date->format( 'Y-m-d\TH:i:sP' ),
			'precision' => $numeric_midnight ? 'date' : 'datetime',
			'courier'   => sanitize_key( $courier ),
			'kind'      => $kind,
		);
	}

	/**
	 * Validate an estimate restored from rate/order metadata.
	 *
	 * @param mixed $estimate Stored value.
	 * @return array<string,string>
	 */
	public static function sanitize( $estimate ) {
		if ( ! is_array( $estimate ) || empty( $estimate['value'] ) || empty( $estimate['precision'] ) ) {
			return array();
		}

		// An estimate stored before kind existed is read as the weaker claim, so
		// an old order never retroactively gains a deadline nobody promised.
		$kind = isset( $estimate['kind'] ) ? (string) $estimate['kind'] : self::KIND_ESTIMATE;

		$normalized = self::normalize(
			$estimate['value'],
			isset( $estimate['courier'] ) ? $estimate['courier'] : '',
			$kind
		);

		return ! empty( $normalized ) && $normalized['precision'] === $estimate['precision'] ? $normalized : array();
	}

	/**
	 * Format an estimate using the shop date/time preferences.
	 *
	 * @param mixed $estimate Stored value.
	 * @return string
	 */
	public static function format( $estimate ) {
		$estimate = self::sanitize( $estimate );
		if ( empty( $estimate ) ) {
			return '';
		}

		$date_format = function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'd.m.Y' ) : 'd.m.Y';
		$time_format = function_exists( 'get_option' ) ? (string) get_option( 'time_format', 'H:i' ) : 'H:i';
		try {
			$date = new \DateTimeImmutable( $estimate['value'] );
		} catch ( \Exception $exception ) {
			return '';
		}

		if ( 'date' === $estimate['precision'] ) {
			return $date->format( $date_format );
		}

		$date = $date->setTimezone( self::timezone() );
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( $date_format . ' ' . $time_format, $date->getTimestamp(), self::timezone() );
		}

		return $date->format( $date_format . ' ' . $time_format );
	}

	/**
	 * The customer-facing sentence for an estimate.
	 *
	 * Speedy commits to a deadline; Econt predicts a date. Saying "expected" for
	 * a deadline understates it, and saying "by" for a prediction overstates it,
	 * so the wording follows the kind the courier actually gave.
	 *
	 * @param mixed $estimate Stored value.
	 * @return string '' when there is nothing to say.
	 */
	public static function describe( $estimate ) {
		$formatted = self::format( $estimate );
		if ( '' === $formatted ) {
			return '';
		}

		$estimate = self::sanitize( $estimate );
		if ( self::KIND_DEADLINE === $estimate['kind'] ) {
			return function_exists( '__' )
				/* translators: %s: formatted delivery deadline date. */
				? sprintf( __( 'Delivery by %s', 'bg-commerce-suite' ), $formatted )
				: sprintf( 'Delivery by %s', $formatted );
		}

		return function_exists( '__' )
			/* translators: %s: formatted expected-delivery date. */
			? sprintf( __( 'Expected delivery: %s', 'bg-commerce-suite' ), $formatted )
			: sprintf( 'Expected delivery: %s', $formatted );
	}

	/**
	 * Resolve the checkout snapshot, falling back to a newer label response ETA.
	 *
	 * @param mixed $order WC_Order-like object.
	 * @return array<string,string>
	 */
	public static function for_order( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}

		$estimate = self::sanitize( $order->get_meta( '_bgcs3_delivery_estimate' ) );
		$label    = $order->get_meta( '_bgcs3_label' );
		$label_estimate = is_array( $label ) && ! empty( $label['meta']['delivery_estimate'] )
			? self::sanitize( $label['meta']['delivery_estimate'] )
			: array();

		return ! empty( $label_estimate ) ? $label_estimate : $estimate;
	}

	/** @return \DateTimeZone */
	private static function timezone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
	}
}
