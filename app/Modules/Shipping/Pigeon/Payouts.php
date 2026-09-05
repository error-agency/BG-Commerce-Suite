<?php
/**
 * Pigeon cash-on-delivery payouts (`GET /v1/payments/completed`).
 *
 * Validating the window and normalising the rows is pure logic with no HTTP in
 * it, so it lives here and can be asserted directly.
 *
 * @package BgCommerce3\Pigeon
 */

namespace BgCommerce3\Modules\Shipping\Pigeon;

defined( 'ABSPATH' ) || exit;

class Payouts {

	/**
	 * Pigeon pay out in euro and say so — the field is literally `amount_eur`.
	 * A shop trading in another currency will see its rows land as conflicts
	 * rather than be converted; see {@see self::rows()}.
	 */
	const CURRENCY = 'EUR';

	/**
	 * Checks the window Pigeon documents, before spending a request on it.
	 *
	 * @param string $from Start, `Y-m-d`.
	 * @param string $to   End, `Y-m-d`.
	 * @return true|\WP_Error
	 */
	public static function check_range( $from, $to ) {
		$from = trim( (string) $from );
		$to   = trim( (string) $to );

		if ( ! self::is_date( $from ) || ! self::is_date( $to ) ) {
			return new \WP_Error(
				'bgcs3_pigeon_payout_date',
				__( 'Select start and end dates in YYYY-MM-DD format.', 'bg-commerce-suite' )
			);
		}

		if ( strtotime( $to ) < strtotime( $from ) ) {
			return new \WP_Error(
				'bgcs3_pigeon_payout_order',
				__( 'The end date cannot be before the start date.', 'bg-commerce-suite' )
			);
		}

		// "The range must not exceed one calendar month" — a month, not 30 days,
		// so it is measured by adding a month to the start rather than counting
		// seconds. 1 февруари–1 март is inside; 31 януари–3 март is not.
		if ( strtotime( $to ) > strtotime( '+1 month', strtotime( $from ) ) ) {
			return new \WP_Error(
				'bgcs3_pigeon_payout_window',
				__( 'The period cannot be longer than one month. Fetch several shorter periods.', 'bg-commerce-suite' )
			);
		}

		return true;
	}

	/**
	 * Normalises the payout rows into the shape the COD report understands.
	 *
	 * The amount is deliberately NOT converted. Pigeon report euro; if the order
	 * was taken in another currency the report must SAY the two disagree, not
	 * quietly reconcile them — a payout report exists precisely to surface that
	 * kind of difference, and a silent conversion would hide the one thing the
	 * merchant is looking for.
	 *
	 * @param array<string,mixed> $response Decoded API response.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( array $response ) {
		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$raw  = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();

		$rows = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['waybill'] ) ) {
				continue;
			}

			$rows[] = array(
				'waybill'            => (string) $row['waybill'],
				'external_reference' => isset( $row['external_reference'] ) ? (string) $row['external_reference'] : '',
				'amount'             => isset( $row['amount_eur'] ) ? (string) $row['amount_eur'] : '',
				'currency'           => self::CURRENCY,
				'courier'            => 'pigeon',
				'collected_date'     => self::iso( isset( $row['collected_date'] ) ? $row['collected_date'] : '' ),
				'paid_date'          => self::iso( isset( $row['paid_date'] ) ? $row['paid_date'] : '' ),
				'fee'                => null,
				'net'                => isset( $row['amount_eur'] ) ? (string) $row['amount_eur'] : '',
				'report_reference'   => implode( ':', array_filter( array( 'pigeon', (string) $row['waybill'], isset( $row['paid_date'] ) ? (string) $row['paid_date'] : '' ), 'strlen' ) ),
				'shipment_reference' => isset( $row['external_reference'] ) ? (string) $row['external_reference'] : '',
				'status'             => 'paid',
			);
		}

		return $rows;
	}

	/**
	 * The latest payout date in a set of rows, as `Y-m-d`.
	 *
	 * That date, not today, is when the money actually arrived — using today
	 * would date every historical import to the day it was run.
	 *
	 * @param array<int,array<string,mixed>> $rows Normalised rows.
	 * @return string
	 */
	public static function latest_paid_date( array $rows ) {
		$latest = '';

		foreach ( $rows as $row ) {
			$date = isset( $row['paid_date'] ) ? (string) $row['paid_date'] : '';
			if ( '' !== $date && ( '' === $latest || $date > $latest ) ) {
				$latest = $date;
			}
		}

		return $latest;
	}

	/**
	 * `d-m-Y` (what the response uses) to `Y-m-d` (what everything else uses).
	 *
	 * The request takes `Y-m-d` and the response answers `d-m-Y`. Reading one as
	 * the other turns 04-08-2026 into a date in April.
	 *
	 * @param string $value Raw date.
	 * @return string
	 */
	public static function iso( $value ) {
		$value = trim( (string) $value );

		if ( preg_match( '/^(\d{2})-(\d{2})-(\d{4})$/', $value, $m ) ) {
			return $m[3] . '-' . $m[2] . '-' . $m[1];
		}

		// Already ISO, or something we will not guess at.
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_date( $value ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}
}
