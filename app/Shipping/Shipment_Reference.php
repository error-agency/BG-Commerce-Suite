<?php
/**
 * Stable external/merchant shipment reference (Rule 27) — never generated from
 * the current timestamp, so a retry after a network timeout produces the SAME
 * reference the provider already saw, instead of a guaranteed-different one.
 *
 * Deterministic from: site instance + order ID + shipment edition. The
 * edition increments only on an explicit Cancel-then-Recreate (Rule 24), so a
 * blind retry of the SAME creation attempt keeps referencing the same
 * shipment identity, while a genuine recreate after cancellation gets a new,
 * distinguishable reference.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Shipment_Reference {

	const META_EDITION = '_bgcs3_shipment_edition';

	/**
	 * @param \WC_Order $order Order.
	 * @return string Stable reference, e.g. "a1b2c3d4-123-1".
	 */
	public static function for_order( \WC_Order $order ) {
		return self::site_instance() . '-' . $order->get_id() . '-' . self::edition( $order );
	}

	/**
	 * Current shipment edition for an order (starts at 1). Bump this via
	 * {@see bump_edition()} after an explicit, confirmed cancellation, before
	 * the next create — never on a plain retry of the same attempt.
	 *
	 * @param \WC_Order $order Order.
	 * @return int
	 */
	public static function edition( \WC_Order $order ) {
		$edition = (int) $order->get_meta( self::META_EDITION );
		return $edition > 0 ? $edition : 1;
	}

	/**
	 * Advance to the next shipment edition (call after a confirmed cancel,
	 * before allowing a new create). Does not save the order — caller saves.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public static function bump_edition( \WC_Order $order ) {
		$order->update_meta_data( self::META_EDITION, self::edition( $order ) + 1 );
	}

	/**
	 * A short, stable identifier for this site install. Deterministic (no
	 * stored option, no generation race) — derived from the site URL, which
	 * does not change across requests or ordinary deploys.
	 *
	 * @return string 8 hex characters.
	 */
	private static function site_instance() {
		$url = function_exists( 'home_url' ) ? home_url() : '';
		return substr( md5( $url ), 0, 8 );
	}
}
