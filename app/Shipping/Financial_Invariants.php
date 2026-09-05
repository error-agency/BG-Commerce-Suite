<?php
/**
 * Cross-courier financial invariants.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Financial_Invariants {

	/**
	 * Gross shipping amount already charged on the WooCommerce order.
	 *
	 * @param \WC_Order $order Order.
	 * @return float
	 */
	public static function order_shipping_gross( \WC_Order $order ) {
		$total = (float) $order->get_shipping_total();
		if ( method_exists( $order, 'get_shipping_tax' ) ) {
			$total += (float) $order->get_shipping_tax();
		}
		return round( max( 0.0, $total ), 2 );
	}

	/**
	 * Rebuild the canonical PMT base from a finalized WooCommerce order.
	 *
	 * The order total already includes products after discounts, taxes, fees and
	 * shipping. Removing only the PMT component yields the same base used during
	 * checkout, irrespective of whether transport is sender- or recipient-paid.
	 *
	 * @param \WC_Order $order      Order.
	 * @param float     $pmt_amount PMT component included in the order total.
	 * @return float
	 */
	public static function order_pmt_base( \WC_Order $order, $pmt_amount ) {
		$total = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;

		return round( max( 0.0, $total - max( 0.0, (float) $pmt_amount ) ), 2 );
	}

	/**
	 * A courier service cannot also be charged directly to the recipient when
	 * WooCommerce has already charged a positive shipping line. That creates the
	 * same double-charge class as "order shipping + receiverDueAmount".
	 *
	 * Recipient-paid courier service remains possible only when the WooCommerce
	 * shipping line is zero, which is the explicit direct-pay shape.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $payer      Courier payer in provider semantics.
	 * @param string    $recipient  Provider value meaning recipient.
	 * @param string    $courier    Human-readable courier name.
	 * @return true|\WP_Error
	 */
	public static function validate_no_double_shipping_charge( \WC_Order $order, $payer, $recipient, $courier ) {
		if ( strtoupper( (string) $payer ) !== strtoupper( (string) $recipient ) ) {
			return true;
		}

		$shipping = self::order_shipping_gross( $order );
		if ( $shipping <= 0.0001 ) {
			return true;
		}

		return new \WP_Error(
			'bgcs3_double_shipping_charge',
			sprintf(
				/* translators: 1: courier name, 2: gross shipping amount, 3: order currency */
				__( '%1$s shipment creation is blocked: WooCommerce already charged %2$s %3$s for shipping, while the courier service is configured to be paid by the recipient. This would charge delivery twice. Set the courier-service payer to Sender, or use a checkout pricing mode that charges 0 shipping before using recipient-paid courier service.', 'bg-commerce-suite' ),
				(string) $courier,
				number_format( $shipping, 2, '.', '' ),
				strtoupper( (string) $order->get_currency() )
			)
		);
	}
}
