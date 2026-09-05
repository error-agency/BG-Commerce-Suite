<?php
/**
 * Quote-to-order persistence helpers.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Support\Selection;

defined( 'ABSPATH' ) || exit;

final class Order_Persistence {

	const QUOTE_SNAPSHOTS_META = '_bgcs3_quote_snapshots';

	/**
	 * Compare the exact priced selection with the canonical checkout selection.
	 *
	 * @param mixed     $priced_selection Raw `_bgcs3_selection` rate meta.
	 * @param Selection $canonical       Canonical session selection.
	 * @return bool
	 */
	public static function selection_matches( $priced_selection, Selection $canonical ) {
		if ( ! is_array( $priced_selection ) ) {
			return false;
		}

		$priced = Selection::from_array( $priced_selection );
		return $priced->to_array() == $canonical->to_array(); // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- associative map order is not semantic.
	}

	/**
	 * Replace all public BGCS snapshot fields without touching private operational meta.
	 *
	 * @param \WC_Order           $order  Order or Store API draft order.
	 * @param array<string,mixed> $fields Current readable snapshot.
	 * @return void
	 */
	public static function replace_readable_meta( \WC_Order $order, array $fields ) {
		foreach ( $order->get_meta_data() as $meta ) {
			$data = is_object( $meta ) && method_exists( $meta, 'get_data' ) ? (array) $meta->get_data() : array();
			$key  = isset( $data['key'] ) ? (string) $data['key'] : '';
			if ( 0 === strpos( $key, 'bgcs3_' ) ) {
				$order->delete_meta_data( $key );
			}
		}

		foreach ( $fields as $key => $value ) {
			$key = sanitize_key( (string) $key );
			$is_empty = is_array( $value ) ? empty( $value ) : '' === (string) $value;
			if ( 0 !== strpos( $key, 'bgcs3_' ) || $is_empty ) {
				continue;
			}
			$order->update_meta_data( $key, $value );
		}
	}

	/**
	 * Sum only shipping lines owned by one BGCS courier.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $courier Courier id.
	 * @return float
	 */
	public static function courier_shipping_total( \WC_Order $order, $courier ) {
		$method_id = 'bgcs3_' . sanitize_key( (string) $courier );
		$total     = 0.0;

		foreach ( $order->get_shipping_methods() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_method_id' ) || $method_id !== (string) $item->get_method_id() ) {
				continue;
			}
			$total += method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
		}

		return $total;
	}

	/**
	 * Read the tax persisted on one shipping line.
	 *
	 * @param mixed $item Shipping line.
	 * @return float
	 */
	private static function shipping_tax( $item ) {
		if ( is_object( $item ) && method_exists( $item, 'get_total_tax' ) ) {
			return (float) $item->get_total_tax();
		}

		if ( ! is_object( $item ) || ! method_exists( $item, 'get_taxes' ) ) {
			return 0.0;
		}

		$taxes = $item->get_taxes();
		$taxes = is_array( $taxes ) && isset( $taxes['total'] ) && is_array( $taxes['total'] ) ? $taxes['total'] : array();

		return array_sum( array_map( 'floatval', $taxes ) );
	}

	/**
	 * Persist the priced selection and financial context for one BGCS package.
	 *
	 * @param \WC_Order_Item_Shipping $item        Shipping line being created.
	 * @param int|string              $package_key WooCommerce package key.
	 * @param \WC_Order               $order       Order or draft order.
	 * @return void
	 */
	public static function capture_quote_snapshot( \WC_Order_Item_Shipping $item, $package_key, \WC_Order $order ) {
		$snapshot_key = sanitize_key( (string) $package_key );
		$snapshots = $order->get_meta( self::QUOTE_SNAPSHOTS_META );
		$snapshots = is_array( $snapshots ) ? $snapshots : array();

		// WooCommerce builds package 0 first. Treat it as the start of a fresh
		// shipping-line snapshot so an updated Store API draft cannot retain a
		// package that disappeared since the previous request.
		if ( '0' === $snapshot_key ) {
			$snapshots = array();
			$order->delete_meta_data( self::QUOTE_SNAPSHOTS_META );
		}

		$method_id = (string) $item->get_method_id();
		if ( 0 !== strpos( $method_id, 'bgcs3_' ) ) {
			return;
		}

		$raw_selection = $item->get_meta( '_bgcs3_selection' );
		if ( ! is_array( $raw_selection ) ) {
			return;
		}

		$selection     = Selection::from_array( $raw_selection );
		$instance      = method_exists( $item, 'get_instance_id' ) ? max( 0, (int) $item->get_instance_id() ) : 0;
		$rate_id       = $method_id . ( $instance > 0 ? ':' . $instance : '' );
		$shipping_total = (float) $item->get_total();
		$shipping_tax   = self::shipping_tax( $item );
		$price_meta     = array();

		foreach ( array( '_bgcs3_payment_context', '_bgcs3_pricing_mode', '_bgcs3_pricing_source', '_bgcs3_base_cost', '_bgcs3_courier_service_payer', '_bgcs3_surcharges', '_bgcs3_pmt_amount', '_bgcs3_pmt_base', '_bgcs3_pmt_source', '_bgcs3_pmt_payer', '_bgcs3_pricing_weight', '_bgcs3_pricing_weight_threshold', '_bgcs3_contract_currency', '_bgcs3_pricing_rule', '_bgcs3_price_breakdown', '_bgcs3_delivery_estimate' ) as $pricing_key ) {
			$value = $item->get_meta( $pricing_key );
			if ( is_array( $value ) ? ! empty( $value ) : '' !== (string) $value ) {
				$price_meta[ substr( $pricing_key, strlen( '_bgcs3_' ) ) ] = $value;
			}
		}

		$snapshots[ $snapshot_key ] = array(
			'package_key'                 => $snapshot_key,
			'rate_id'                     => $rate_id,
			'method_id'                   => $method_id,
			'instance_id'                 => $instance,
			'courier'                     => $selection->courier,
			'total'                       => $shipping_total,
			'shipping_total'              => $shipping_total,
			'shipping_tax'                => $shipping_tax,
			'shipping_total_including_tax' => round( $shipping_total + $shipping_tax, 6 ),
			'validated'                   => filter_var( $item->get_meta( '_bgcs3_validated' ), FILTER_VALIDATE_BOOLEAN ),
			'price_state'                 => sanitize_key( (string) $item->get_meta( '_bgcs3_price_state' ) ),
			'selection'                   => $selection->to_array(),
			'pricing'                     => $price_meta,
		);
		ksort( $snapshots, SORT_NATURAL );
		$order->update_meta_data( self::QUOTE_SNAPSHOTS_META, $snapshots );
	}

	/**
	 * Verify every captured BGCS package was priced for the order selection.
	 *
	 * @param \WC_Order $order     Order.
	 * @param Selection $selection Canonical selection.
	 * @return bool
	 */
	public static function quote_snapshots_match( \WC_Order $order, Selection $selection ) {
		$snapshots = $order->get_meta( self::QUOTE_SNAPSHOTS_META );
		if ( ! is_array( $snapshots ) || empty( $snapshots ) ) {
			return false;
		}

		$current_quotes = array();
		foreach ( $order->get_shipping_methods() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_method_id' ) ) {
				continue;
			}

			$method_id = (string) $item->get_method_id();
			if ( 0 !== strpos( $method_id, 'bgcs3_' ) ) {
				continue;
			}

			$instance         = method_exists( $item, 'get_instance_id' ) ? max( 0, (int) $item->get_instance_id() ) : 0;
			$rate_id          = $method_id . ( $instance > 0 ? ':' . $instance : '' );
			$total            = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
			$tax              = self::shipping_tax( $item );
			$current_quotes[] = $rate_id . '|' . number_format( $total, 6, '.', '' ) . '|' . number_format( $tax, 6, '.', '' );
		}
		if ( count( $current_quotes ) !== count( $snapshots ) ) {
			return false;
		}

		$snapshot_quotes = array();
		foreach ( $snapshots as $snapshot ) {
			if ( ! is_array( $snapshot )
				|| empty( $snapshot['rate_id'] )
				|| empty( $snapshot['method_id'] )
				|| ! isset( $snapshot['instance_id'] )
				|| ! isset( $snapshot['total'] )
				|| ! is_numeric( $snapshot['total'] )
				|| ! isset( $snapshot['shipping_total'] )
				|| ! is_numeric( $snapshot['shipping_total'] )
				|| ! isset( $snapshot['shipping_tax'] )
				|| ! is_numeric( $snapshot['shipping_tax'] )
				|| ! isset( $snapshot['shipping_total_including_tax'] )
				|| ! is_numeric( $snapshot['shipping_total_including_tax'] )
				|| (string) $snapshot['rate_id'] !== (string) $snapshot['method_id'] . ( (int) $snapshot['instance_id'] > 0 ? ':' . (int) $snapshot['instance_id'] : '' )
				|| number_format( (float) $snapshot['total'], 6, '.', '' ) !== number_format( (float) $snapshot['shipping_total'], 6, '.', '' )
				|| number_format( (float) $snapshot['shipping_total_including_tax'], 6, '.', '' ) !== number_format( (float) $snapshot['shipping_total'] + (float) $snapshot['shipping_tax'], 6, '.', '' )
				|| empty( $snapshot['validated'] )
				|| ! in_array( isset( $snapshot['price_state'] ) ? $snapshot['price_state'] : '', array( 'calculated', 'free' ), true )
				|| ! self::selection_matches( isset( $snapshot['selection'] ) ? $snapshot['selection'] : null, $selection ) ) {
				return false;
			}

			$snapshot_quotes[] = (string) $snapshot['rate_id'] . '|' . number_format( (float) $snapshot['shipping_total'], 6, '.', '' ) . '|' . number_format( (float) $snapshot['shipping_tax'], 6, '.', '' );
		}

		sort( $current_quotes, SORT_STRING );
		sort( $snapshot_quotes, SORT_STRING );

		return $current_quotes === $snapshot_quotes;
	}
}
