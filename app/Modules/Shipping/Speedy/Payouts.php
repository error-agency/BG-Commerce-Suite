<?php
/**
 * Speedy payout report normalisation for POST /v1/payments.
 *
 * @package BgCommerce3\Speedy
 */

namespace BgCommerce3\Modules\Shipping\Speedy;

defined( 'ABSPATH' ) || exit;

class Payouts {

	/**
	 * Keep the common COD-report UI to a conservative one-month window.
	 *
	 * @param string $from Start date, Y-m-d.
	 * @param string $to   End date, Y-m-d.
	 * @return true|\WP_Error
	 */
	public static function check_range( $from, $to ) {
		return self::validate_range( $from, $to, 'speedy' );
	}

	/**
	 * @param array<string,mixed> $response API response.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( array $response ) {
		$payouts = isset( $response['payouts'] ) && is_array( $response['payouts'] ) ? $response['payouts'] : array();
		$rows    = array();

		foreach ( $payouts as $payout ) {
			if ( ! is_array( $payout ) ) {
				continue;
			}
			$paid_date = self::iso_date( isset( $payout['date'] ) ? $payout['date'] : '' );
			$currency  = isset( $payout['currency'] ) ? (string) $payout['currency'] : '';
			$details   = isset( $payout['details'] ) && is_array( $payout['details'] ) ? $payout['details'] : array();

			foreach ( $details as $detail ) {
				if ( ! is_array( $detail ) || empty( $detail['shipmentId'] ) ) {
					continue;
				}
				$rows[] = array(
					'waybill'        => (string) $detail['shipmentId'],
					'amount'         => isset( $detail['amount'] ) ? (string) $detail['amount'] : '',
					'currency'       => ! empty( $detail['currency'] ) ? (string) $detail['currency'] : $currency,
					'courier'        => 'speedy',
					'collected_date' => self::iso_date( isset( $detail['deliveryDate'] ) ? $detail['deliveryDate'] : '' ),
					'paid_date'      => $paid_date,
					'fee'            => null,
					'net'            => isset( $detail['amount'] ) ? (string) $detail['amount'] : '',
					'report_reference' => implode( ':', array_filter( array( isset( $payout['docId'] ) ? (string) $payout['docId'] : '', isset( $detail['lineNo'] ) ? (string) $detail['lineNo'] : '' ), 'strlen' ) ),
					'shipment_reference' => isset( $detail['ref1'] ) ? (string) $detail['ref1'] : '',
					'status'         => 'paid',
				);
			}
		}

		return $rows;
	}

	private static function validate_range( $from, $to, $code ) {
		if ( ! self::is_date( $from ) || ! self::is_date( $to ) ) {
			return new \WP_Error( 'bgcs3_' . $code . '_payout_date', __( 'Select start and end dates in YYYY-MM-DD format.', 'bg-commerce-suite' ) );
		}
		if ( strtotime( $to ) < strtotime( $from ) ) {
			return new \WP_Error( 'bgcs3_' . $code . '_payout_order', __( 'The end date cannot be before the start date.', 'bg-commerce-suite' ) );
		}
		if ( strtotime( $to ) > strtotime( '+1 month', strtotime( $from ) ) ) {
			return new \WP_Error( 'bgcs3_' . $code . '_payout_window', __( 'The period cannot be longer than one month. Fetch several shorter periods.', 'bg-commerce-suite' ) );
		}
		return true;
	}

	private static function is_date( $value ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	private static function iso_date( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
