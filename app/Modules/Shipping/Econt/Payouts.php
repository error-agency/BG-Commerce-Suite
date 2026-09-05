<?php
/**
 * Econt PaymentReport response normalisation.
 *
 * @package BgCommerce3\Econt
 */

namespace BgCommerce3\Modules\Shipping\Econt;

defined( 'ABSPATH' ) || exit;

class Payouts {

	/**
	 * @param string $from Start date, Y-m-d.
	 * @param string $to   End date, Y-m-d.
	 * @return true|\WP_Error
	 */
	public static function check_range( $from, $to ) {
		if ( ! self::is_date( $from ) || ! self::is_date( $to ) ) {
			return new \WP_Error( 'bgcs3_econt_payout_date', __( 'Select start and end dates in YYYY-MM-DD format.', 'bg-commerce-suite' ) );
		}
		if ( strtotime( $to ) < strtotime( $from ) ) {
			return new \WP_Error( 'bgcs3_econt_payout_order', __( 'The end date cannot be before the start date.', 'bg-commerce-suite' ) );
		}
		return true;
	}

	/**
	 * The official schema describes PaymentReportRow, while installations may
	 * return either one row or a list. Accept both without guessing new fields.
	 *
	 * @param array<string|int,mixed> $response API response.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( array $response ) {
		$raw = self::extract_rows( $response );
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['num'] ) ) {
				continue;
			}
			$out[] = array(
				'waybill'    => (string) $row['num'],
				'amount'     => isset( $row['amount'] ) ? (string) $row['amount'] : '',
				'currency'     => isset( $row['currency'] ) ? strtoupper( (string) $row['currency'] ) : '',
				'courier'      => 'econt',
				'paid_date'    => self::iso_date( isset( $row['payDate'] ) ? $row['payDate'] : '' ),
				'payment_type' => isset( $row['type'] ) && '' !== (string) $row['type']
					? (string) $row['type']
					: ( isset( $row['payType'] ) ? (string) $row['payType'] : '' ),
				'fee'          => null,
				'net'          => isset( $row['amount'] ) ? (string) $row['amount'] : '',
				'report_reference' => implode( ':', array_filter( array( 'econt', (string) $row['num'], isset( $row['payDate'] ) ? (string) $row['payDate'] : '', isset( $row['createdTime'] ) ? (string) $row['createdTime'] : '' ), 'strlen' ) ),
				'status'       => 'paid',
			);
		}
		return $out;
	}

	private static function extract_rows( array $response ) {
		if ( isset( $response['num'] ) ) {
			return array( $response );
		}
		foreach ( array( 'rows', 'results', 'paymentReport', 'data' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
				$candidate = $response[ $key ];
				if ( isset( $candidate['num'] ) ) {
					return array( $candidate );
				}
				return array_values( array_filter( $candidate, 'is_array' ) );
			}
		}
		if ( array_keys( $response ) === range( 0, count( $response ) - 1 ) ) {
			return array_values( array_filter( $response, 'is_array' ) );
		}
		return array();
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
		if ( preg_match( '/^(\d{2})[.\/-](\d{2})[.\/-](\d{4})/', $value, $m ) ) {
			return $m[3] . '-' . $m[2] . '-' . $m[1];
		}
		return '';
	}
}
