<?php
/**
 * Зарежда и индексира WooCommerce поръчките за COD отчетите.
 *
 * @package BgCommerce3\CodReports
 */

namespace BgCommerce3\Modules\Accounting\CodReports;

use BgCommerce3\Shipping\Cod_Payout;

defined( 'ABSPATH' ) || exit;

class Report_Repository {

	const META_PAID      = '_bgcs3_cod_paid';
	const META_PAID_DATE = '_bgcs3_cod_paid_date';

	/**
	 * COD gateway идентификатори, разширяеми от courier add-on-ите.
	 *
	 * @return string[]
	 */
	private function cod_methods() {
		return \BgCommerce3\Shipping\Cod::methods();
	}

	/**
	 * Whether an order still belongs to the configured COD report dataset.
	 *
	 * @param mixed $order Candidate order.
	 * @return bool
	 */
	public function is_cod_order( $order ) {
		return $order instanceof \WC_Order
			&& in_array( $order->get_payment_method(), $this->cod_methods(), true );
	}

	/**
	 * Връща редовете за избрания период и куриер.
	 *
	 * @param array<string,string> $filters Филтри на отчета.
	 * @return array<int,array<string,mixed>>
	 */
	public function rows( array $filters ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$cod    = $this->cod_methods();
		$orders = array();
		$page   = 1;

		do {
			$result = wc_get_orders(
				array(
					'limit'          => 200,
					'paged'          => $page,
					'paginate'       => true,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'date_created'   => $filters['from'] . '...' . $filters['to'],
					'payment_method' => $cod,
					'meta_key'       => '_bgcs3_label', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare'   => 'EXISTS',
				)
			);

			if ( ! is_object( $result ) || ! isset( $result->orders, $result->max_num_pages ) ) {
				break;
			}

			$orders = array_merge( $orders, $result->orders );
			$page++;
		} while ( $page <= (int) $result->max_num_pages );

		$rows = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( ! in_array( $order->get_payment_method(), $cod, true ) ) {
				continue;
			}

			$selection = $order->get_meta( '_bgcs3_selection' );
			$courier   = is_array( $selection ) && ! empty( $selection['courier'] ) ? (string) $selection['courier'] : '';
			if ( '' !== $filters['courier'] && $courier !== $filters['courier'] ) {
				continue;
			}

			$label    = $order->get_meta( '_bgcs3_label' );
			$expected = Cod_Payout::expected( $order );
			$waybill  = isset( $expected['waybill'] ) ? (string) $expected['waybill'] : '';

			// Rule 78/81 anomaly: order says COD (guaranteed true here — this
			// query is already scoped to COD payment methods) but the shipment
			// snapshot says the actual shipment was NOT created as COD. Only
			// flag when the snapshot actually HAS the `is_cod` field (BUG-012 —
			// added to Label_Result in Core) — legacy labels created before
			// that fix have no such key and must not be treated as a false
			// "confirmed mismatch"; missing data is not evidence of a problem.
			$mismatch = is_array( $label ) && array_key_exists( 'is_cod', $label ) && ! $label['is_cod'];

			$rows[] = array(
				'order'     => $order,
				'courier'   => $courier,
				'waybill'   => $waybill,
				'amount'    => isset( $expected['amount'] ) ? (float) $expected['amount'] : (float) $order->get_total(),
				'currency'  => ! empty( $expected['currency'] ) ? (string) $expected['currency'] : $order->get_currency(),
				'paid'      => 'yes' === (string) $order->get_meta( self::META_PAID ),
				'paid_date' => (string) $order->get_meta( self::META_PAID_DATE ),
				'mismatch'  => $mismatch,
			);
		}

		return $rows;
	}

	/**
	 * Нормализира номер на товарителница за точно сравнение.
	 *
	 * @param string $value Стойност от поръчка или импорт.
	 * @return string
	 */
	public static function normalize_waybill( $value ) {
		return Cod_Payout::normalize_waybill( $value );
	}

	/**
	 * Карта на точните товарителници към COD поръчките.
	 *
	 * Ако два различни ордера нормализират до един и същ номер (грешка при
	 * въвеждане, преизползван demo номер и т.н.), НЕ избираме произволно кой
	 * побеждава — двусмислен waybill излиза от картата изцяло, за да не се
	 * приложи payout статус към грешна поръчка мълчаливо. При импорт такъв ред
	 * просто изглежда "unmatched" (виж Report_Importer::preview()) вместо да
	 * бъде объркан с чужда поръчка.
	 *
	 * @return array<string,\WC_Order>
	 */
	public function waybill_map() {
		$map       = array();
		$ambiguous = array();
		$page      = 1;
		$cod       = $this->cod_methods();

		do {
			$result = wc_get_orders( array( 'limit' => 200, 'paged' => $page, 'paginate' => true, 'payment_method' => $cod, 'meta_key' => '_bgcs3_label', 'meta_compare' => 'EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( ! is_object( $result ) || ! isset( $result->orders, $result->max_num_pages ) ) {
				break;
			}

			foreach ( $result->orders as $order ) {
				if ( ! $order instanceof \WC_Order || ! in_array( $order->get_payment_method(), $cod, true ) ) {
					continue;
				}

				$label  = $order->get_meta( '_bgcs3_label' );
				$number = is_array( $label ) && isset( $label['number'] ) ? self::normalize_waybill( $label['number'] ) : '';
				if ( strlen( $number ) < 4 ) {
					continue;
				}

				if ( isset( $map[ $number ] ) && (int) $map[ $number ]->get_id() !== (int) $order->get_id() ) {
					$ambiguous[ $number ] = true;
					continue;
				}
				$map[ $number ] = $order;
			}

			$page++;
		} while ( $page <= (int) $result->max_num_pages );

		foreach ( array_keys( $ambiguous ) as $number ) {
			unset( $map[ $number ] );
		}

		return $map;
	}
}
