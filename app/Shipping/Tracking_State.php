<?php
/**
 * Canonical BGCS shipment states (Rule 41) — куриерите превеждат собствения си
 * raw tracking код/статус към една от тези стойности; Core решава какво (ако
 * изобщо нещо) да направи с WooCommerce статуса въз основа на нормализираното
 * състояние, никога въз основа на суровия provider код директно.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Tracking_State {

	const CREATED              = 'created';
	const ACCEPTED             = 'accepted';
	const IN_TRANSIT            = 'in_transit';
	const OUT_FOR_DELIVERY      = 'out_for_delivery';
	const AVAILABLE_FOR_PICKUP  = 'available_for_pickup';
	const DELIVERED             = 'delivered';
	const DELIVERY_FAILED       = 'delivery_failed';
	const REDIRECTED            = 'redirected';
	const RETURN_IN_PROGRESS    = 'return_in_progress';
	const RETURNED              = 'returned';
	const CANCELLED             = 'cancelled';
	const EXCEPTION             = 'exception';
	const UNKNOWN                = 'unknown';

	/**
	 * Всички валидни стойности.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::CREATED,
			self::ACCEPTED,
			self::IN_TRANSIT,
			self::OUT_FOR_DELIVERY,
			self::AVAILABLE_FOR_PICKUP,
			self::DELIVERED,
			self::DELIVERY_FAILED,
			self::REDIRECTED,
			self::RETURN_IN_PROGRESS,
			self::RETURNED,
			self::CANCELLED,
			self::EXCEPTION,
			self::UNKNOWN,
		);
	}

	/**
	 * @param string $state Candidate state.
	 * @return string Валидна стойност — UNKNOWN, ако $state не е разпозната.
	 */
	public static function sanitize( $state ) {
		return in_array( $state, self::all(), true ) ? $state : self::UNKNOWN;
	}

	/**
	 * Терминални състояния (Rule 50) — след потвърждение спира нормалния
	 * recurring polling; ръчен refresh остава достъпен. Дефинирано тук за да е
	 * едно място за бъдещата scheduler логика, не се прилага автоматично все още.
	 *
	 * @param string $state State.
	 * @return bool
	 */
	public static function is_terminal( $state ) {
		return in_array( $state, array( self::DELIVERED, self::RETURNED, self::CANCELLED ), true );
	}

	/**
	 * Merchant-facing Bulgarian label (Rule 76/240 — header badge / timeline
	 * current-status text). UNKNOWN deliberately has no label — callers should
	 * simply not show a status badge rather than display "Unknown" as if it
	 * were a real courier-reported state.
	 *
	 * @param string $state One of the class constants.
	 * @return string
	 */
	public static function label( $state ) {
		$labels = array(
			self::CREATED             => __( 'Created', 'bg-commerce-suite' ),
			self::ACCEPTED            => __( 'Accepted', 'bg-commerce-suite' ),
			self::IN_TRANSIT          => __( 'In transit', 'bg-commerce-suite' ),
			self::OUT_FOR_DELIVERY    => __( 'Out for delivery', 'bg-commerce-suite' ),
			self::AVAILABLE_FOR_PICKUP => __( 'Ready for pickup', 'bg-commerce-suite' ),
			self::DELIVERED           => __( 'Delivered', 'bg-commerce-suite' ),
			self::DELIVERY_FAILED     => __( 'Failed delivery', 'bg-commerce-suite' ),
			self::REDIRECTED          => __( 'Redirected', 'bg-commerce-suite' ),
			self::RETURN_IN_PROGRESS  => __( 'Returning', 'bg-commerce-suite' ),
			self::RETURNED            => __( 'Returned', 'bg-commerce-suite' ),
			self::CANCELLED           => __( 'Cancelled', 'bg-commerce-suite' ),
			self::EXCEPTION           => __( 'Delivery issue', 'bg-commerce-suite' ),
		);
		$state = self::sanitize( $state );
		return isset( $labels[ $state ] ) ? $labels[ $state ] : '';
	}

	/**
	 * Panel status-badge tone for a state (see {@see \BgCommerce3\Admin\Order\Panel}).
	 *
	 * @param string $state One of the class constants.
	 * @return string neutral|success|warning|danger
	 */
	public static function tone( $state ) {
		$tones = array(
			self::DELIVERED          => 'success',
			self::DELIVERY_FAILED    => 'warning',
			self::REDIRECTED         => 'warning',
			self::RETURN_IN_PROGRESS => 'warning',
			self::RETURNED           => 'warning',
			self::CANCELLED          => 'danger',
			self::EXCEPTION          => 'danger',
		);
		$state = self::sanitize( $state );
		return isset( $tones[ $state ] ) ? $tones[ $state ] : 'neutral';
	}
}
