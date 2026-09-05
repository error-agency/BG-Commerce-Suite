<?php
/**
 * Parses courier payout CSV files and prepares safe COD reconciliation data.
 *
 * @package BgCommerce3\CodReports
 */

namespace BgCommerce3\Modules\Accounting\CodReports;

use BgCommerce3\Shipping\Cod_Payout;

defined( 'ABSPATH' ) || exit;

class Report_Importer {
	const MAX_IMPORT_BYTES = 10485760;
	const MAX_IMPORT_ROWS  = 50000;

	/** @var Report_Repository */
	private $repository;

	/**
	 * @param Report_Repository $repository COD order repository.
	 */
	public function __construct( Report_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Build a non-mutating preview from a courier payout CSV file.
	 *
	 * @param string $filepath Uploaded file path.
	 * @param int    $filesize Uploaded file size.
	 * @return array<string,mixed>
	 */
	public function preview_file( $filepath, $filesize ) {
		if ( $filesize <= 0 || $filesize > self::MAX_IMPORT_BYTES ) {
			throw new \RuntimeException( 'invalid_file_size' );
		}

		$handle = fopen( $filepath, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			throw new \RuntimeException( 'unreadable_file' );
		}

		$first_line = fgets( $handle );
		if ( false === $first_line ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new \RuntimeException( 'empty_file' );
		}

		$delimiter = $this->detect_delimiter( $first_line );
		rewind( $handle );
		$first_row = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $first_row ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new \RuntimeException( 'invalid_csv' );
		}
		$first_row[0] = $this->strip_bom( $first_row[0] );

		$columns    = $this->detect_columns( $first_row );
		$has_header = isset( $columns['waybill'] );
		$rows       = array();
		if ( ! $has_header ) {
			$rows[] = $first_row;
		}

		$row_count = count( $rows );
		while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			$row_count++;
			if ( $row_count > self::MAX_IMPORT_ROWS ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				throw new \RuntimeException( 'too_many_rows' );
			}
			if ( is_array( $row ) && array_filter( $row, 'strlen' ) ) {
				$rows[] = $row;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$preview              = $this->preview_rows( $rows, $columns );
		$preview['delimiter'] = "\t" === $delimiter ? 'tab' : $delimiter;

		return $preview;
	}

	/**
	 * Build a preview from payout rows a courier reported over its own API.
	 *
	 * Deliberately routed through {@see self::preview_rows()} rather than given
	 * its own matching: an API row and a CSV row must be judged by the same
	 * rules, including the amount / currency / courier conflict checks. A row
	 * whose currency differs from the order's lands in `conflicts` and cannot be
	 * applied — which is the point. Pigeon, for instance, report in euro, and a
	 * shop trading in leva must SEE that rather than have it quietly converted:
	 * a reconciliation report exists to surface exactly that kind of difference.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows from a courier module.
	 * @return array<string,mixed>
	 */
	public function preview_courier_payouts( array $rows ) {
		if ( count( $rows ) > self::MAX_IMPORT_ROWS ) {
			throw new \RuntimeException( 'too_many_rows' );
		}

		// The row keys ARE the column names, so `build_record()` reads them
		// exactly as it reads a header-mapped CSV.
		$columns = array(
			'waybill'           => 'waybill',
			'amount'            => 'amount',
			'currency'          => 'currency',
			'courier'           => 'courier',
			'paid_date'         => 'paid_date',
			'fee'               => 'fee',
			'net'               => 'net',
			'report_reference'  => 'report_reference',
			'shipment_reference'=> 'shipment_reference',
			'status'            => 'status',
		);

		$preview           = $this->preview_rows( array_values( $rows ), $columns );
		$preview['source'] = 'api';
		$valid = array();
		foreach ( $preview['matches'] as $record ) {
			$reasons = array();
			if ( empty( $record['paid_date'] ) ) {
				$reasons[] = 'invalid_paid_date';
			}
			if ( 'paid' !== sanitize_key( isset( $record['status'] ) ? (string) $record['status'] : '' ) ) {
				$reasons[] = 'invalid_payout_status';
			}
			if ( $reasons ) {
				$record['conflict_reasons'] = $reasons;
				$preview['conflicts'][] = $record;
			} else {
				$valid[] = $record;
			}
		}
		$preview['matches'] = $valid;

		return $preview;
	}

	/**
	 * Build a non-mutating preview from already-parsed rows.
	 *
	 * Split out of {@see self::preview_file()} so a courier that can report its
	 * own payouts over an API goes through the SAME matching, the same
	 * duplicate and already-paid handling, and the same amount / currency /
	 * courier conflict detection as an uploaded file. A second reconciliation
	 * path with its own idea of what counts as a match is exactly what a
	 * payout report must not have.
	 *
	 * @param array<int,array<int|string,mixed>> $rows    Rows.
	 * @param array<string,int|string>           $columns Column key => index or key.
	 * @return array<string,mixed>
	 */
	public function preview_rows( array $rows, array $columns ) {
		$preview = array(
			'matches'       => array(),
			'conflicts'     => array(),
			'unmatched'     => 0,
			'duplicates'    => 0,
			'already_paid'  => 0,
			'rows_processed' => count( $rows ),
			'delimiter'     => '',
			'columns'       => $columns,
		);
		$waybill_map = $this->repository->waybill_map();
		$seen_orders = array();

		foreach ( $rows as $row ) {
			$waybill = $this->find_waybill( $row, $columns, $waybill_map );
			if ( '' === $waybill || ! isset( $waybill_map[ $waybill ] ) ) {
				$preview['unmatched']++;
				continue;
			}

			$order    = $waybill_map[ $waybill ];
			$order_id = (int) $order->get_id();
			$record = $this->build_record( $order, $waybill, $row, $columns );
			$record_fingerprint = Cod_Payout::row_fingerprint(
				array(
					'waybill'            => $record['waybill'],
					'amount'             => $record['reported_amount'],
					'currency'           => $record['reported_currency'],
					'courier'            => $record['reported_courier'],
					'paid_date'          => $record['paid_date'],
					'report_reference'   => $record['report_reference'],
					'shipment_reference' => $record['shipment_reference'],
				)
			);
			if ( isset( $seen_orders[ $order_id ] ) ) {
				if ( hash_equals( $seen_orders[ $order_id ], $record_fingerprint ) ) {
					$preview['duplicates']++;
				} else {
					$record['conflict_reasons'][] = 'duplicate_conflict';
					$record['conflict_reasons'] = array_values( array_unique( $record['conflict_reasons'] ) );
					$preview['conflicts'][] = $record;
				}
				continue;
			}
			$seen_orders[ $order_id ] = $record_fingerprint;
			if ( 'yes' === (string) $order->get_meta( Report_Repository::META_PAID ) ) {
				$preview['already_paid']++;
				continue;
			}

			if ( empty( $record['conflict_reasons'] ) ) {
				$preview['matches'][] = $record;
			} else {
				$preview['conflicts'][] = $record;
			}
		}

		return $preview;
	}

	/**
	 * Apply previously previewed matches after revalidating current order data.
	 *
	 * @param array<string,mixed> $preview      Stored preview.
	 * @param callable            $order_loader Receives an order ID and returns a WC order.
	 * @param string              $paid_date    ISO payout date.
	 * @return array{updated:int,skipped:int}
	 */
	public function apply_preview( array $preview, $order_loader, $paid_date ) {
		$result  = array( 'updated' => 0, 'skipped' => 0 );
		$matches = isset( $preview['matches'] ) && is_array( $preview['matches'] ) ? $preview['matches'] : array();

		foreach ( $matches as $record ) {
			$order = is_callable( $order_loader ) && isset( $record['order_id'] )
				? call_user_func( $order_loader, (int) $record['order_id'] )
				: null;

			if ( ! $this->record_still_matches( $record, $order ) ) {
				$result['skipped']++;
				continue;
			}
			if ( 'yes' === (string) $order->get_meta( Report_Repository::META_PAID ) ) {
				$result['skipped']++;
				continue;
			}

			$row_paid_date = ! empty( $record['paid_date'] ) && '' !== Cod_Payout::normalize_date( $record['paid_date'] )
				? Cod_Payout::normalize_date( $record['paid_date'] )
				: Cod_Payout::normalize_date( $paid_date );
			$apply = Cod_Payout::apply_row(
				$order,
				array(
					'waybill'            => (string) $record['waybill'],
					'amount'             => $record['reported_amount'],
					'currency'           => (string) $record['reported_currency'],
					'courier'            => (string) $record['reported_courier'],
					'paid_date'          => $row_paid_date,
					'fee'                => isset( $record['fee'] ) ? $record['fee'] : null,
					'net'                => isset( $record['net'] ) ? $record['net'] : null,
					'report_reference'   => isset( $record['report_reference'] ) ? $record['report_reference'] : '',
					'shipment_reference' => isset( $record['shipment_reference'] ) ? $record['shipment_reference'] : '',
					'status'              => isset( $record['status'] ) ? $record['status'] : 'paid',
				),
				isset( $preview['source'] ) && 'api' === $preview['source'] ? 'manual_api' : 'csv_import'
			);
			if ( 'updated' === $apply ) {
				$result['updated']++;
			} else {
				$result['skipped']++;
			}
		}

		return $result;
	}

	/**
	 * Revalidate that order identity and accounting values did not change.
	 *
	 * @param array<string,mixed> $record Stored match.
	 * @param mixed               $order  Current order.
	 * @return bool
	 */
	private function record_still_matches( array $record, $order ) {
		if ( ! $this->repository->is_cod_order( $order ) ) {
			return false;
		}

		$expected = Cod_Payout::expected( $order );
		$waybill  = isset( $expected['waybill'] ) ? (string) $expected['waybill'] : '';
		$courier  = isset( $expected['courier'] ) ? (string) $expected['courier'] : '';

		return isset( $record['order_id'], $record['waybill'], $record['expected_amount'], $record['expected_currency'], $record['expected_courier'] )
			&& (int) $record['order_id'] === (int) $order->get_id()
			&& (string) $record['waybill'] === $waybill
			&& abs( (float) $record['expected_amount'] - (float) $expected['amount'] ) <= Cod_Payout::AMOUNT_TOLERANCE
			&& strtoupper( (string) $record['expected_currency'] ) === strtoupper( (string) $expected['currency'] )
			&& (string) $record['expected_courier'] === $courier;
	}

	/**
	 * Detect the most likely CSV delimiter from the first physical line.
	 *
	 * @param string $line First line.
	 * @return string
	 */
	private function detect_delimiter( $line ) {
		$best       = ',';
		$best_count = 1;
		foreach ( array( ',', ';', "\t", '|' ) as $delimiter ) {
			$count = count( str_getcsv( $line, $delimiter ) );
			if ( $count > $best_count ) {
				$best       = $delimiter;
				$best_count = $count;
			}
		}
		return $best;
	}

	/**
	 * Detect supported structured report columns.
	 *
	 * @param string[] $row Header row.
	 * @return array<string,int>
	 */
	private function detect_columns( array $row ) {
		$aliases = array(
			'waybill' => array( 'товарителница', 'номер на товарителница', 'номер товарителница', 'waybill', 'awb', 'tracking number', 'shipment number' ),
			'amount'  => array( 'сума нп', 'наложен платеж', 'изплатена сума', 'сума', 'cod amount', 'amount', 'payout amount' ),
			'currency' => array( 'валута', 'currency' ),
			'courier' => array( 'куриер', 'courier' ),
			'paid_date' => array( 'дата на плащане', 'дата на изплащане', 'paid date', 'payout date' ),
			'fee' => array( 'такса', 'fee', 'payout fee' ),
			'net' => array( 'нетно плащане', 'нетна сума', 'net', 'net payout' ),
			'report_reference' => array( 'референция на отчета', 'report reference', 'payout reference', 'document id' ),
			'shipment_reference' => array( 'референция на пратката', 'shipment reference', 'external reference' ),
			'status' => array( 'статус', 'status', 'payout status' ),
		);
		$columns = array();
		foreach ( $row as $index => $heading ) {
			$normalized = $this->normalize_text( $heading );
			foreach ( $aliases as $type => $values ) {
				if ( in_array( $normalized, $values, true ) ) {
					$columns[ $type ] = $index;
					break;
				}
			}
		}
		return $columns;
	}

	/**
	 * Find an exact normalized waybill present in the COD order index.
	 *
	 * @param string[]               $row         CSV row.
	 * @param array<string,int>      $columns     Detected columns.
	 * @param array<string,\WC_Order> $waybill_map Indexed orders.
	 * @return string
	 */
	private function find_waybill( array $row, array $columns, array $waybill_map ) {
		if ( isset( $columns['waybill'], $row[ $columns['waybill'] ] ) ) {
			return Report_Repository::normalize_waybill( $row[ $columns['waybill'] ] );
		}

		foreach ( $row as $cell ) {
			$candidate = Report_Repository::normalize_waybill( $cell );
			if ( isset( $waybill_map[ $candidate ] ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Build and validate one preview record.
	 *
	 * @param \WC_Order         $order    Matched order.
	 * @param string            $waybill  Normalized waybill.
	 * @param string[]          $row      CSV row.
	 * @param array<string,int> $columns  Detected columns.
	 * @return array<string,mixed>
	 */
	private function build_record( $order, $waybill, array $row, array $columns ) {
		$expected         = Cod_Payout::expected( $order );
		$expected_courier = isset( $expected['courier'] ) ? (string) $expected['courier'] : '';
		$reported_amount  = isset( $columns['amount'], $row[ $columns['amount'] ] ) ? $this->parse_amount( $row[ $columns['amount'] ] ) : null;
		$reported_currency = isset( $columns['currency'], $row[ $columns['currency'] ] ) ? $this->normalize_currency( $row[ $columns['currency'] ] ) : '';
		$reported_courier = isset( $columns['courier'], $row[ $columns['courier'] ] ) ? $this->normalize_courier( $row[ $columns['courier'] ] ) : '';
		$paid_date        = isset( $columns['paid_date'], $row[ $columns['paid_date'] ] ) ? Cod_Payout::normalize_date( $row[ $columns['paid_date'] ] ) : '';
		$fee              = isset( $columns['fee'], $row[ $columns['fee'] ] ) ? $this->parse_amount( $row[ $columns['fee'] ] ) : null;
		$net              = isset( $columns['net'], $row[ $columns['net'] ] ) ? $this->parse_amount( $row[ $columns['net'] ] ) : null;
		$report_reference = isset( $columns['report_reference'], $row[ $columns['report_reference'] ] ) ? sanitize_text_field( (string) $row[ $columns['report_reference'] ] ) : '';
		$shipment_reference = isset( $columns['shipment_reference'], $row[ $columns['shipment_reference'] ] ) ? sanitize_text_field( (string) $row[ $columns['shipment_reference'] ] ) : '';
		$status            = isset( $columns['status'], $row[ $columns['status'] ] ) ? sanitize_key( (string) $row[ $columns['status'] ] ) : 'paid';
		$reasons          = array();

		if ( null === $reported_amount ) {
			$reasons[] = 'invalid_amount';
		} elseif ( abs( (float) $expected['amount'] - $reported_amount ) > Cod_Payout::AMOUNT_TOLERANCE ) {
			$reasons[] = 'amount_mismatch';
		}
		if ( '' === $reported_currency ) {
			$reasons[] = 'invalid_currency';
		} elseif ( strtoupper( (string) $expected['currency'] ) !== $reported_currency ) {
			$reasons[] = 'currency_mismatch';
		}
		if ( '' === $reported_courier ) {
			$reasons[] = 'invalid_courier';
		} elseif ( $expected_courier !== $reported_courier ) {
			$reasons[] = 'courier_mismatch';
		}
		$expected_reference = isset( $expected['shipment_reference'] ) ? (string) $expected['shipment_reference'] : '';
		if ( '' !== $expected_reference && '' !== $shipment_reference && $expected_reference !== $shipment_reference ) {
			$reasons[] = 'shipment_reference_mismatch';
		}

		return array(
			'order_id'           => (int) $order->get_id(),
			'waybill'            => $waybill,
			'expected_amount'    => (float) $expected['amount'],
			'expected_currency'  => strtoupper( (string) $expected['currency'] ),
			'expected_courier'   => $expected_courier,
			'reported_amount'    => $reported_amount,
			'reported_currency'  => $reported_currency,
			'reported_courier'   => $reported_courier,
			'paid_date'          => $paid_date,
			'fee'                => $fee,
			'net'                => $net,
			'report_reference'   => $report_reference,
			'shipment_reference' => $shipment_reference,
			'status'              => $status,
			'conflict_reasons'   => $reasons,
		);
	}

	/**
	 * Parse a courier amount using comma or dot decimal notation.
	 *
	 * @param string $value Raw amount.
	 * @return float|null
	 */
	private function parse_amount( $value ) {
		$value = preg_replace( '/[^0-9,.-]/u', '', (string) $value );
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
	 * Normalize a currency label.
	 *
	 * @param string $value Raw currency.
	 * @return string
	 */
	private function normalize_currency( $value ) {
		$value = $this->normalize_text( $value );
		if ( in_array( $value, array( 'bgn', 'лв', 'лева', 'лев' ), true ) ) {
			return 'BGN';
		}
		if ( in_array( $value, array( 'eur', 'евро' ), true ) || false !== strpos( (string) $value, '€' ) ) {
			return 'EUR';
		}
		return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
	}

	/**
	 * Normalize common courier names to BGCS module IDs.
	 *
	 * @param string $value Raw courier name.
	 * @return string
	 */
	private function normalize_courier( $value ) {
		$value = $this->normalize_text( $value );
		$aliases = array(
			'speedy' => array( 'speedy', 'спиди' ),
			'econt'  => array( 'econt', 'еконт' ),
			'boxnow' => array( 'box now', 'boxnow', 'бокс нау' ),
			'pigeon' => array( 'pigeon', 'пиджън' ),
		);
		foreach ( $aliases as $id => $values ) {
			if ( in_array( $value, $values, true ) ) {
				return $id;
			}
		}
		return preg_replace( '/[^a-z0-9_]/', '', $value );
	}

	/**
	 * Normalize human CSV labels without requiring mbstring.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalize_text( $value ) {
		$value = $this->strip_bom( trim( (string) $value ) );
		$value = strtr(
			$value,
			array(
				'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д', 'Е' => 'е',
				'Ж' => 'ж', 'З' => 'з', 'И' => 'и', 'Й' => 'й', 'К' => 'к', 'Л' => 'л',
				'М' => 'м', 'Н' => 'н', 'О' => 'о', 'П' => 'п', 'Р' => 'р', 'С' => 'с',
				'Т' => 'т', 'У' => 'у', 'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц', 'Ч' => 'ч',
				'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ', 'Ь' => 'ь', 'Ю' => 'ю', 'Я' => 'я',
			)
		);
		$value = strtolower( $value );
		$value = preg_replace( '/[^\p{L}\p{N}€]+/u', ' ', $value );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Remove a UTF-8 BOM from the first cell.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	private function strip_bom( $value ) {
		return preg_replace( '/^\xEF\xBB\xBF/', '', (string) $value );
	}
}
