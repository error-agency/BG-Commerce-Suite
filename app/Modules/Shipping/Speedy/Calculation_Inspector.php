<?php
/**
 * Interpret Speedy's per-service calculation response without losing errors.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\Speedy;

use BgCommerce3\Support\Shipping_Availability;

defined( 'ABSPATH' ) || exit;

final class Calculation_Inspector {

	/**
	 * @param array<string,mixed> $response       Speedy CalculationResponse.
	 * @param int                 $service_id     Requested service id.
	 * @param string              $store_currency WooCommerce currency.
	 * @return array<string,mixed>
	 */
	public static function inspect( array $response, $service_id, $store_currency ) {
		$service_id     = (int) $service_id;
		$store_currency = strtoupper( trim( (string) $store_currency ) );
		$rows           = self::calculation_rows( isset( $response['calculations'] ) ? $response['calculations'] : array() );
		$calculation    = self::find_service( $rows, $service_id );

		if ( empty( $calculation ) ) {
			$code = empty( $rows ) ? 'speedy_calculation_missing' : 'speedy_service_missing';
			return self::failure(
				Shipping_Availability::unavailable(
					$code,
					__( 'Speedy is not available for the selected service and destination. Please choose another delivery option.', 'bg-commerce-suite' ),
					'Speedy calculation response does not contain the requested service result. requested_service=' . $service_id . ' returned_services=' . implode( ',', self::service_ids( $rows ) )
				)
			);
		}

		if ( ! empty( $calculation['error'] ) ) {
			$error = is_array( $calculation['error'] ) ? $calculation['error'] : array( 'message' => (string) $calculation['error'] );
			return self::failure(
				Shipping_Availability::unavailable(
					'speedy_service_unavailable',
					__( 'Speedy is not available for the selected service and destination. Please choose another delivery option.', 'bg-commerce-suite' ),
					'Speedy per-service calculation error: ' . self::bounded_json( $error )
				)
			);
		}

		$price = isset( $calculation['price'] ) && is_array( $calculation['price'] ) ? $calculation['price'] : array();
		if ( ! array_key_exists( 'total', $price ) ) {
			return self::failure(
				Shipping_Availability::error(
					'speedy_price_missing',
					__( 'We cannot calculate the Speedy delivery price right now. Please try again or choose another method.', 'bg-commerce-suite' ),
					'Speedy calculation result has no price.total field for service ' . $service_id . '. fields=' . implode( ',', array_keys( $price ) )
				)
			);
		}

		if ( null === $price['total'] || '' === trim( (string) $price['total'] ) || ! is_numeric( $price['total'] ) ) {
			return self::failure(
				Shipping_Availability::error(
					'speedy_price_invalid',
					__( 'We cannot calculate the Speedy delivery price right now. Please try again or choose another method.', 'bg-commerce-suite' ),
					'Speedy price.total is not numeric for service ' . $service_id . '. type=' . gettype( $price['total'] )
				)
			);
		}

		$total = (float) $price['total'];
		if ( $total <= 0 ) {
			return self::failure(
				Shipping_Availability::error(
					'speedy_price_non_positive',
					__( 'We cannot calculate the Speedy delivery price right now. Please try again or choose another method.', 'bg-commerce-suite' ),
					'Speedy price.total is non-positive for service ' . $service_id . '. total=' . $total
				)
			);
		}

		$currency = isset( $price['currency'] ) ? strtoupper( trim( (string) $price['currency'] ) ) : '';
		if ( '' !== $currency && '' !== $store_currency && $currency !== $store_currency ) {
			return self::failure(
				Shipping_Availability::error(
					'speedy_currency_mismatch',
					__( 'Speedy returned a delivery price in a different currency. Please choose another method.', 'bg-commerce-suite' ),
					'Speedy/store currency mismatch. quote=' . $currency . ' store=' . $store_currency
				)
			);
		}

		return array(
			'valid'        => true,
			'calculation'  => $calculation,
			'total'        => $total,
			'currency'     => $currency,
			'availability' => null,
		);
	}

	/** @return array<string,mixed> */
	private static function failure( Shipping_Availability $availability ) {
		return array(
			'valid'        => false,
			'calculation'  => array(),
			'total'        => 0.0,
			'currency'     => '',
			'availability' => $availability,
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function calculation_rows( $calculations ) {
		if ( ! is_array( $calculations ) ) {
			return array();
		}
		$rows = array();
		foreach ( $calculations as $row ) {
			if ( is_array( $row ) && isset( $row[0] ) && is_array( $row[0] ) && ! isset( $row['serviceId'] ) ) {
				foreach ( $row as $nested ) {
					if ( is_array( $nested ) ) {
						$rows[] = $nested;
					}
				}
			} elseif ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/** @return array<string,mixed> */
	private static function find_service( array $rows, $service_id ) {
		foreach ( $rows as $row ) {
			if ( isset( $row['serviceId'] ) && (int) $row['serviceId'] === (int) $service_id ) {
				return $row;
			}
		}
		return ( 1 === count( $rows ) && ! isset( $rows[0]['serviceId'] ) ) ? $rows[0] : array();
	}

	/** @return string[] */
	private static function service_ids( array $rows ) {
		$ids = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['serviceId'] ) ) {
				$ids[] = (string) (int) $row['serviceId'];
			}
		}
		return $ids;
	}

	private static function bounded_json( array $data ) {
		$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return substr( is_string( $json ) ? $json : '', 0, 1000 );
	}
}

