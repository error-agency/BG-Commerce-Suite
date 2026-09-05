<?php
/**
 * Safe courier COD payout reconciliation shared by tracking and background
 * payout reports.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Cod_Payout {

	const META_PAID          = '_bgcs3_cod_paid';
	const META_PAID_DATE     = '_bgcs3_cod_paid_date';
	const META_AMOUNT        = '_bgcs3_cod_paid_amount';
	const META_CURRENCY      = '_bgcs3_cod_paid_currency';
	const META_EXPECTED_AMOUNT   = '_bgcs3_cod_expected_amount';
	const META_EXPECTED_CURRENCY = '_bgcs3_cod_expected_currency';
	const META_FEE           = '_bgcs3_cod_payout_fee';
	const META_NET           = '_bgcs3_cod_payout_net';
	const META_DIFFERENCE    = '_bgcs3_cod_payout_difference';
	const META_REPORT_REF    = '_bgcs3_cod_payout_report_reference';
	const META_STATUS        = '_bgcs3_cod_payout_status';
	const META_FINGERPRINT   = '_bgcs3_cod_payout_fingerprint';
	const META_SHIPMENT_REF  = '_bgcs3_cod_payout_shipment_reference';
	const META_COURIER       = '_bgcs3_cod_payout_courier';
	const META_WAYBILL       = '_bgcs3_cod_payout_waybill';
	const META_SYNCED_AT     = '_bgcs3_cod_payout_synced_at';
	const META_SOURCE        = '_bgcs3_cod_payout_source';
	const META_MISMATCH      = '_bgcs3_cod_payout_mismatch';

	/** Accounting amounts are compared to the cent, with float noise tolerance. */
	const AMOUNT_TOLERANCE = 0.009;

	/**
	 * Exact, formatting-insensitive shipment reference.
	 *
	 * @param mixed $value Waybill/reference.
	 * @return string
	 */
	public static function normalize_waybill( $value ) {
		return strtoupper( preg_replace( '/[^A-Z0-9]/i', '', trim( (string) $value ) ) );
	}

	/** PII-free identity for duplicate rows inside one provider report. */
	public static function row_fingerprint( array $row ) {
		$amount = self::normalize_amount( isset( $row['amount'] ) ? $row['amount'] : null );
		return hash(
			'sha256',
			implode(
				'|',
				array(
					self::normalize_waybill( isset( $row['waybill'] ) ? $row['waybill'] : '' ),
					null === $amount ? '' : number_format( $amount, 2, '.', '' ),
					self::normalize_currency( isset( $row['currency'] ) ? $row['currency'] : '' ),
					sanitize_key( isset( $row['courier'] ) ? (string) $row['courier'] : '' ),
					self::normalize_date( isset( $row['paid_date'] ) ? $row['paid_date'] : '' ),
					sanitize_text_field( isset( $row['report_reference'] ) ? (string) $row['report_reference'] : '' ),
					sanitize_text_field( isset( $row['shipment_reference'] ) ? (string) $row['shipment_reference'] : '' ),
				)
			)
		);
	}

	/**
	 * Expected payout identity/accounting facts from the shipment snapshot.
	 *
	 * The label snapshot wins over the current order total because a shipment may
	 * have been created with an explicit per-order COD override. Falling back to
	 * the order is only for legacy label snapshots that predate the COD fields.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return array<string,mixed>
	 */
	public static function expected( \WC_Order $order ) {
		$selection = $order->get_meta( '_bgcs3_selection' );
		$label     = $order->get_meta( '_bgcs3_label' );
		$label     = is_array( $label ) ? $label : array();

		$courier = is_array( $selection ) && ! empty( $selection['courier'] )
			? sanitize_key( (string) $selection['courier'] )
			: ( ! empty( $label['courier'] ) ? sanitize_key( (string) $label['courier'] ) : '' );
		$waybill = ! empty( $label['number'] ) ? self::normalize_waybill( $label['number'] ) : '';
		$shipment_reference = ! empty( $label['meta']['shipment_reference'] )
			? sanitize_text_field( (string) $label['meta']['shipment_reference'] )
			: '';

		$is_cod = Cod::is_order( $order );
		if ( array_key_exists( 'is_cod', $label ) ) {
			$is_cod = (bool) $label['is_cod'];
		}

		$amount = isset( $label['cod_amount'] ) && is_numeric( $label['cod_amount'] )
			? (float) $label['cod_amount']
			: Cod::amount( $order );
		$currency = ! empty( $label['cod_currency'] )
			? self::normalize_currency( $label['cod_currency'] )
			: self::normalize_currency( $order->get_currency() );

		return array(
			'order_id'  => (int) $order->get_id(),
			'is_cod'    => $is_cod && $amount > 0,
			'waybill'   => $waybill,
			'shipment_reference' => $shipment_reference,
			'courier'   => $courier,
			'amount'    => round( $amount, 2 ),
			'currency'  => $currency,
		);
	}

	/**
	 * Pure validation used by both API report reconciliation and tracking facts.
	 *
	 * @param array<string,mixed> $expected Expected order facts.
	 * @param array<string,mixed> $row      Courier payout row.
	 * @return string[] Conflict reasons; empty means an exact safe match.
	 */
	public static function validate_values( array $expected, array $row ) {
		$reasons = array();

		if ( empty( $expected['is_cod'] ) ) {
			$reasons[] = 'not_cod';
		}

		$expected_waybill = isset( $expected['waybill'] ) ? self::normalize_waybill( $expected['waybill'] ) : '';
		$reported_waybill = isset( $row['waybill'] ) ? self::normalize_waybill( $row['waybill'] ) : '';
		if ( '' === $expected_waybill || '' === $reported_waybill || $expected_waybill !== $reported_waybill ) {
			$reasons[] = 'waybill_mismatch';
		}

		$expected_courier = isset( $expected['courier'] ) ? sanitize_key( (string) $expected['courier'] ) : '';
		$reported_courier = isset( $row['courier'] ) ? sanitize_key( (string) $row['courier'] ) : '';
		if ( '' === $expected_courier || '' === $reported_courier || $expected_courier !== $reported_courier ) {
			$reasons[] = 'courier_mismatch';
		}

		$expected_reference = isset( $expected['shipment_reference'] ) ? trim( (string) $expected['shipment_reference'] ) : '';
		$reported_reference = isset( $row['shipment_reference'] ) ? trim( (string) $row['shipment_reference'] ) : '';
		if ( '' !== $expected_reference && '' !== $reported_reference && $expected_reference !== $reported_reference ) {
			$reasons[] = 'shipment_reference_mismatch';
		}

		$reported_amount = self::normalize_amount( isset( $row['amount'] ) ? $row['amount'] : null );
		if ( null === $reported_amount ) {
			$reasons[] = 'invalid_amount';
		} elseif ( abs( (float) $expected['amount'] - $reported_amount ) > self::AMOUNT_TOLERANCE ) {
			$reasons[] = 'amount_mismatch';
		}

		$expected_currency = isset( $expected['currency'] ) ? self::normalize_currency( $expected['currency'] ) : '';
		$reported_currency = isset( $row['currency'] ) ? self::normalize_currency( $row['currency'] ) : '';
		if ( '' === $reported_currency ) {
			$reasons[] = 'invalid_currency';
		} elseif ( '' === $expected_currency || $expected_currency !== $reported_currency ) {
			$reasons[] = 'currency_mismatch';
		}

		$paid_date = isset( $row['paid_date'] ) ? self::normalize_date( $row['paid_date'] ) : '';
		if ( '' === $paid_date ) {
			$reasons[] = 'invalid_paid_date';
		}

		$status = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : 'paid';
		if ( 'paid' !== $status ) {
			$reasons[] = 'invalid_payout_status';
		}

		$fee = isset( $row['fee'] ) ? self::normalize_amount( $row['fee'] ) : null;
		$net = isset( $row['net'] ) ? self::normalize_amount( $row['net'] ) : null;
		if ( null !== $fee && $fee < 0 ) {
			$reasons[] = 'invalid_fee';
		}
		if ( null !== $net && $net < 0 ) {
			$reasons[] = 'invalid_net';
		}
		if ( null !== $reported_amount && null !== $fee && null !== $net
			&& abs( $reported_amount - $fee - $net ) > self::AMOUNT_TOLERANCE
		) {
			$reasons[] = 'net_mismatch';
		}

		return array_values( array_unique( $reasons ) );
	}

	/**
	 * Apply one exact payout row to an order. Never changes order/payment status
	 * and never creates refunds; it only records courier-confirmed payout facts.
	 *
	 * @param \WC_Order          $order  Order.
	 * @param array<string,mixed> $row    Normalized courier row.
	 * @param string             $source Source identifier.
	 * @return string updated|already_paid|mismatch
	 */
	public static function apply_row( \WC_Order $order, array $row, $source = 'courier_api' ) {
		$expected = self::expected( $order );
		$reasons  = self::validate_values( $expected, $row );
		$now      = time();
		$amount   = self::normalize_amount( isset( $row['amount'] ) ? $row['amount'] : null );
		$currency = self::normalize_currency( isset( $row['currency'] ) ? $row['currency'] : '' );
		$paid_date = self::normalize_date( isset( $row['paid_date'] ) ? $row['paid_date'] : '' );
		$fee       = self::normalize_amount( isset( $row['fee'] ) ? $row['fee'] : null );
		$net       = self::normalize_amount( isset( $row['net'] ) ? $row['net'] : null );
		$net       = null !== $net ? $net : $amount;
		$report_reference = self::report_reference( $row, $expected, $amount, $currency, $paid_date );
		$fingerprint = self::fingerprint( $expected, $amount, $currency, $paid_date, $report_reference );

		if ( $reasons ) {
			self::record_mismatch( $order, $expected, $row, $reasons, $source, $now );
			return 'mismatch';
		}

		$already_paid = 'yes' === (string) $order->get_meta( self::META_PAID );
		$existing_fingerprint = (string) $order->get_meta( self::META_FINGERPRINT );
		if ( $already_paid && '' !== $existing_fingerprint && ! hash_equals( $existing_fingerprint, $fingerprint ) ) {
			self::record_mismatch( $order, $expected, $row, array( 'payout_identity_conflict' ), $source, $now );
			return 'mismatch';
		}

		$order->update_meta_data( self::META_PAID, 'yes' );
		$order->update_meta_data( self::META_PAID_DATE, $paid_date );
		$order->update_meta_data( self::META_AMOUNT, null === $amount ? 0.0 : $amount );
		$order->update_meta_data( self::META_CURRENCY, $currency );
		$order->update_meta_data( self::META_EXPECTED_AMOUNT, (float) $expected['amount'] );
		$order->update_meta_data( self::META_EXPECTED_CURRENCY, (string) $expected['currency'] );
		$order->update_meta_data( self::META_FEE, null === $fee ? '' : $fee );
		$order->update_meta_data( self::META_NET, null === $net ? 0.0 : $net );
		$order->update_meta_data( self::META_DIFFERENCE, round( (float) $amount - (float) $expected['amount'], 2 ) );
		$order->update_meta_data( self::META_REPORT_REF, $report_reference );
		$order->update_meta_data( self::META_STATUS, 'paid' );
		$order->update_meta_data( self::META_FINGERPRINT, $fingerprint );
		$order->update_meta_data( self::META_SHIPMENT_REF, (string) $expected['shipment_reference'] );
		$order->update_meta_data( self::META_COURIER, (string) $expected['courier'] );
		$order->update_meta_data( self::META_WAYBILL, (string) $expected['waybill'] );
		$order->update_meta_data( self::META_SYNCED_AT, $now );
		$order->update_meta_data( self::META_SOURCE, sanitize_key( (string) $source ) );
		$order->delete_meta_data( self::META_MISMATCH );

		if ( ! $already_paid ) {
			$note = 'manual' === sanitize_key( (string) $source )
				/* translators: %s: shipment label number. */
				? __( 'COD marked as paid manually in COD reports. Shipment label: %s.', 'bg-commerce-suite' )
				/* translators: %s: shipment label number. */
				: __( 'COD automatically reconciled from courier report. Shipment label: %s.', 'bg-commerce-suite' );
			$order->add_order_note( sprintf( $note, (string) $expected['waybill'] ) );
		}
		$order->save();

		return $already_paid ? 'already_paid' : 'updated';
	}

	/** Record an explicit merchant assertion through the same receipt schema. */
	public static function mark_manually( \WC_Order $order, $paid_date ) {
		$expected = self::expected( $order );
		if ( empty( $expected['is_cod'] ) || empty( $expected['waybill'] ) ) {
			return 'mismatch';
		}
		return self::apply_row(
			$order,
			array(
				'waybill'          => $expected['waybill'],
				'amount'           => $expected['amount'],
				'currency'         => $expected['currency'],
				'courier'          => $expected['courier'],
				'paid_date'        => $paid_date,
				'fee'              => null,
				'net'              => $expected['amount'],
				'report_reference' => 'manual:' . (int) $order->get_id() . ':' . self::normalize_date( $paid_date ),
				'shipment_reference' => $expected['shipment_reference'],
				'status'           => 'paid',
			),
			'manual'
		);
	}

	/** Clear the complete payout receipt when a merchant moves it to pending. */
	public static function reset_to_pending( \WC_Order $order ) {
		foreach ( array(
			self::META_PAID,
			self::META_PAID_DATE,
			self::META_AMOUNT,
			self::META_CURRENCY,
			self::META_EXPECTED_AMOUNT,
			self::META_EXPECTED_CURRENCY,
			self::META_FEE,
			self::META_NET,
			self::META_DIFFERENCE,
			self::META_REPORT_REF,
			self::META_STATUS,
			self::META_FINGERPRINT,
			self::META_SHIPMENT_REF,
			self::META_COURIER,
			self::META_WAYBILL,
			self::META_SYNCED_AT,
			self::META_SOURCE,
			self::META_MISMATCH,
		) as $key ) {
			$order->delete_meta_data( $key );
		}
		$order->add_order_note( __( 'COD moved back to pending (COD reports).', 'bg-commerce-suite' ) );
		$order->save();
	}

	/** Persist an actionable conflict without overwriting a prior paid receipt. */
	private static function record_mismatch( \WC_Order $order, array $expected, array $row, array $reasons, $source, $now ) {
		$order->update_meta_data( self::META_EXPECTED_AMOUNT, isset( $expected['amount'] ) ? (float) $expected['amount'] : 0.0 );
		$order->update_meta_data( self::META_EXPECTED_CURRENCY, isset( $expected['currency'] ) ? (string) $expected['currency'] : '' );
		$order->update_meta_data( self::META_STATUS, 'requires_review' );
		$order->update_meta_data(
			self::META_MISMATCH,
			array(
				'reasons'             => array_values( array_unique( $reasons ) ),
				'expected_amount'     => isset( $expected['amount'] ) ? (float) $expected['amount'] : 0.0,
				'expected_currency'   => isset( $expected['currency'] ) ? (string) $expected['currency'] : '',
				'reported_amount'     => self::normalize_amount( isset( $row['amount'] ) ? $row['amount'] : null ),
				'reported_currency'   => self::normalize_currency( isset( $row['currency'] ) ? $row['currency'] : '' ),
				'difference'          => null !== self::normalize_amount( isset( $row['amount'] ) ? $row['amount'] : null )
					? round( (float) self::normalize_amount( $row['amount'] ) - (float) $expected['amount'], 2 )
					: null,
				'courier'             => isset( $row['courier'] ) ? sanitize_key( (string) $row['courier'] ) : '',
				'waybill'             => isset( $row['waybill'] ) ? self::normalize_waybill( $row['waybill'] ) : '',
				'report_reference'    => isset( $row['report_reference'] ) ? sanitize_text_field( (string) $row['report_reference'] ) : '',
				'shipment_reference'  => isset( $row['shipment_reference'] ) ? sanitize_text_field( (string) $row['shipment_reference'] ) : '',
				'paid_date'            => isset( $row['paid_date'] ) ? self::normalize_date( $row['paid_date'] ) : '',
				'fee'                  => isset( $row['fee'] ) ? self::normalize_amount( $row['fee'] ) : null,
				'net'                  => isset( $row['net'] ) ? self::normalize_amount( $row['net'] ) : null,
				'status'               => isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '',
				'source'              => sanitize_key( (string) $source ),
				'synced_at'           => $now,
			)
		);
		$order->update_meta_data( self::META_SYNCED_AT, $now );
		$order->save();
	}

	/** Stable provider reference, or a deterministic local receipt reference. */
	private static function report_reference( array $row, array $expected, $amount, $currency, $paid_date ) {
		$reference = isset( $row['report_reference'] ) ? trim( (string) $row['report_reference'] ) : '';
		if ( '' !== $reference ) {
			return substr( sanitize_text_field( $reference ), 0, 191 );
		}
		$identity = implode( '|', array( $expected['courier'], $expected['waybill'], (string) $amount, $currency, $paid_date ) );
		return 'local:' . substr( hash( 'sha256', $identity ), 0, 24 );
	}

	/** PII-free idempotency key for one payout receipt. */
	private static function fingerprint( array $expected, $amount, $currency, $paid_date, $report_reference ) {
		return hash(
			'sha256',
			implode( '|', array( $expected['courier'], $expected['waybill'], (string) $amount, $currency, $paid_date, $report_reference ) )
		);
	}

	/**
	 * Reconcile payout facts carried directly by a tracking/status response.
	 * Currently Econt exposes these facts in ShipmentStatus. A collected COD is
	 * deliberately not enough; only a real paid amount + paid timestamp qualifies.
	 *
	 * @param \WC_Order          $order    Order.
	 * @param string             $courier Courier id.
	 * @param array<string,mixed> $provider Provider snapshot.
	 * @return string skipped|updated|already_paid|mismatch
	 */
	public static function apply_from_tracking( \WC_Order $order, $courier, array $provider ) {
		$amount   = isset( $provider['cd_paid_amount'] ) ? self::normalize_amount( $provider['cd_paid_amount'] ) : null;
		$currency = isset( $provider['cd_paid_currency'] ) ? self::normalize_currency( $provider['cd_paid_currency'] ) : '';
		$paid_date = isset( $provider['cd_paid_time'] ) ? self::normalize_date( $provider['cd_paid_time'] ) : '';

		if ( null === $amount || $amount <= 0 || '' === $currency || '' === $paid_date ) {
			return 'skipped';
		}

		$expected = self::expected( $order );
		if ( empty( $expected['waybill'] ) ) {
			return 'skipped';
		}

		return self::apply_row(
			$order,
			array(
				'waybill'   => $expected['waybill'],
				'amount'    => $amount,
				'currency'  => $currency,
				'courier'   => sanitize_key( (string) $courier ),
				'paid_date' => $paid_date,
			),
			'tracking'
		);
	}

	/**
	 * @param mixed $value Amount.
	 * @return float|null
	 */
	public static function normalize_amount( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		$value = preg_replace( '/[^0-9,.-]/u', '', trim( (string) $value ) );
		if ( '' === $value || ! preg_match( '/\d/', $value ) ) {
			return null;
		}
		$comma = strrpos( $value, ',' );
		$dot   = strrpos( $value, '.' );
		if ( false !== $comma && false !== $dot ) {
			if ( $comma > $dot ) {
				$value = str_replace( '.', '', $value );
				$value = str_replace( ',', '.', $value );
			} else {
				$value = str_replace( ',', '', $value );
			}
		} elseif ( false !== $comma ) {
			$value = str_replace( ',', '.', $value );
		}
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * @param mixed $value Currency.
	 * @return string
	 */
	public static function normalize_currency( $value ) {
		$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', trim( (string) $value ) ) );
		return 3 === strlen( $value ) ? $value : '';
	}

	/**
	 * Normalize provider date/datetime to Y-m-d without guessing ambiguous forms.
	 *
	 * @param mixed $value Date or ISO-style datetime.
	 * @return string
	 */
	public static function normalize_date( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}
		if ( preg_match( '/^(\d{2})[.\/-](\d{2})[.\/-](\d{4})/', $value, $m ) && checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
			return $m[3] . '-' . $m[2] . '-' . $m[1];
		}
		return '';
	}
}
