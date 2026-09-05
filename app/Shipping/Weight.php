<?php
/**
 * Общо тегло на пратка — единна логика за всички куриери.
 *
 * Продуктовите тегла идват в мерната единица на магазина (кг или г), а всички
 * куриерски API-та работят в килограми. Настройката „Тегло по подразбиране“ е
 * винаги в килограми и се ползва само когато продуктите нямат зададено тегло.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

final class Weight {

	/**
	 * Минимално тегло, което куриерските API-та приемат.
	 */
	const MIN_KG = 0.01;

	/**
	 * Изследва теглото на количката за известност на теглата на физическите продукти.
	 *
	 * @param string              $courier_id Courier id.
	 * @param array<string,mixed> $package    WooCommerce shipping package.
	 * @return array{weight:float,weight_known:bool,has_physical:bool}
	 */
	public static function cart_weight_info( $courier_id, array $package ) {
		$measured     = 0.0;
		$weight_known = true;
		$has_physical = false;

		if ( ! empty( $package['contents'] ) && is_array( $package['contents'] ) ) {
			foreach ( $package['contents'] as $item ) {
				if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
					continue;
				}
				$product = $item['data'];
				if ( method_exists( $product, 'is_virtual' ) && $product->is_virtual() ) {
					continue;
				}

				$has_physical = true;
				$w            = method_exists( $product, 'get_weight' ) ? $product->get_weight() : null;
				$quantity     = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;

				if ( '' === $w || null === $w || false === $w || (float) $w <= 0 ) {
					$weight_known = false;
				} else {
					$measured += (float) $w * $quantity;
				}
			}
		}

		if ( ! $has_physical ) {
			return array(
				'weight'       => 0.0,
				'weight_known' => false,
				'has_physical' => false,
			);
		}

		if ( ! $weight_known ) {
			$default_weight = (float) Module_Settings::get( $courier_id, 'default_weight' );
			return array(
				'weight'       => max( self::MIN_KG, round( $default_weight, 3 ) ),
				// The merchant explicitly configured a fallback weight for products
				// without one. It is therefore usable for bounded custom-price rules.
				// Purely virtual packages still return false above.
				'weight_known' => true,
				'has_physical' => true,
			);
		}

		$normalized = self::normalize( $courier_id, $measured );

		return array(
			'weight'       => $normalized,
			'weight_known' => true,
			'has_physical' => true,
		);
	}

	/**
	 * Изследва теглото на поръчката.
	 *
	 * @param string    $courier_id Courier id.
	 * @param \WC_Order $order      Order.
	 * @return array{weight:float,weight_known:bool,has_physical:bool}
	 */
	public static function order_weight_info( $courier_id, \WC_Order $order ) {
		$measured     = 0.0;
		$weight_known = true;
		$has_physical = false;

		foreach ( $order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( ! $product ) {
				continue;
			}
			if ( method_exists( $product, 'is_virtual' ) && $product->is_virtual() ) {
				continue;
			}

			$has_physical = true;
			$w            = $product->get_weight();
			$quantity     = (int) $item->get_quantity();

			if ( '' === $w || null === $w || false === $w || (float) $w <= 0 ) {
				$weight_known = false;
			} else {
				$measured += (float) $w * $quantity;
			}
		}

		if ( ! $has_physical ) {
			return array(
				'weight'       => 0.0,
				'weight_known' => false,
				'has_physical' => false,
			);
		}

		if ( ! $weight_known ) {
			$default_weight = (float) Module_Settings::get( $courier_id, 'default_weight' );
			return array(
				'weight'       => max( self::MIN_KG, round( $default_weight, 3 ) ),
				// The merchant explicitly configured a fallback weight for products
				// without one. It is therefore usable for bounded custom-price rules.
				// Purely virtual packages still return false above.
				'weight_known' => true,
				'has_physical' => true,
			);
		}

		$normalized = self::normalize( $courier_id, $measured );

		return array(
			'weight'       => $normalized,
			'weight_known' => true,
			'has_physical' => true,
		);
	}

	/**
	 * Тегло на количката (при изчисляване на цена) в килограми.
	 *
	 * @param string              $courier_id Courier id (за настройката по подразбиране).
	 * @param array<string,mixed> $package    WooCommerce shipping package.
	 * @return float
	 */
	public static function for_package( $courier_id, array $package ) {
		$info = self::cart_weight_info( $courier_id, $package );
		return $info['weight'];
	}

	/**
	 * Тегло на поръчката (при издаване на товарителница) в килограми.
	 *
	 * @param string    $courier_id Courier id.
	 * @param \WC_Order $order      Order.
	 * @return float
	 */
	public static function for_order( $courier_id, \WC_Order $order ) {
		$info = self::order_weight_info( $courier_id, $order );
		return $info['weight'];
	}

	/**
	 * Превръща измерено тегло в килограми и слага резервната стойност.
	 *
	 * @param string $courier_id Courier id.
	 * @param float  $measured   Измерено тегло в мерната единица на магазина.
	 * @return float
	 */
	public static function normalize( $courier_id, $measured ) {
		$weight = (float) $measured;

		if ( $weight > 0 ) {
			if ( function_exists( 'wc_get_weight' ) ) {
				$store_unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );
				$weight     = (float) wc_get_weight( $weight, 'kg', $store_unit );
			} else {
				$unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );
				if ( 'g' === $unit ) {
					$weight = $weight / 1000;
				} elseif ( 'lbs' === $unit ) {
					$weight = $weight * 0.45359237;
				} elseif ( 'oz' === $unit ) {
					$weight = $weight * 0.028349523125;
				}
			}
		}

		if ( $weight <= 0 ) {
			$weight = (float) Module_Settings::get( $courier_id, 'default_weight' );
		}

		return max( self::MIN_KG, round( $weight, 3 ) );
	}
}
