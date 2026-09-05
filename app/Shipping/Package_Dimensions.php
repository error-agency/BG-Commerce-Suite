<?php
/**
 * Physical package dimension helpers shared by courier integrations.
 *
 * Product dimensions are useful for proving that a shipment definitely cannot
 * fit a courier's physical limits. They are not a carton-packing oracle: for
 * multi-item carts the result is intentionally a lower bound, while explicit
 * merchant/order package rows remain authoritative.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Package_Dimensions {

	/** BOX NOW compartment dimensions in centimetres: H x W x L. */
	const BOXNOW_COMPARTMENTS = array(
		1 => array( 8.0, 45.0, 60.0 ),
		2 => array( 17.0, 45.0, 60.0 ),
		3 => array( 36.0, 45.0, 60.0 ),
	);

	/**
	 * Inspect a WooCommerce shipping package.
	 *
	 * @param array<string,mixed> $package WooCommerce shipping package.
	 * @return array<string,mixed>
	 */
	public static function for_package( array $package ) {
		$items = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
		return self::profile_from_items( $items, false );
	}

	/**
	 * Inspect the products in an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function for_order( \WC_Order $order ) {
		return self::profile_from_items( $order->get_items( 'line_item' ), true );
	}

	/**
	 * Resolve one authoritative/defensible rectangular package size for a cart
	 * package. The priority is deliberate:
	 *
	 * 1. explicit per-request dimensions;
	 * 2. the dimensions of a single physical product unit;
	 * 3. configured courier defaults.
	 *
	 * We never invent a combined carton for multiple product units. Product
	 * dimensions are only promoted to shipment dimensions when there is exactly
	 * one physical unit, where the inference is lossless.
	 *
	 * @param array<string,mixed> $package  WooCommerce shipping package.
	 * @param array<string,mixed> $explicit Explicit `length|width|height` in cm.
	 * @param array<string,mixed> $defaults Courier defaults in cm.
	 * @return array{length:float,width:float,height:float,source:string}|array{}
	 */
	public static function resolve_for_package( array $package, array $explicit = array(), array $defaults = array() ) {
		$resolved = self::complete_dimensions( $explicit );
		if ( ! empty( $resolved ) ) {
			$resolved['source'] = 'explicit';
			return $resolved;
		}

		$product = self::single_unit_dimensions_from_items(
			isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array(),
			false
		);
		if ( ! empty( $product ) ) {
			$product['source'] = 'product';
			return $product;
		}

		$resolved = self::complete_dimensions( $defaults );
		if ( ! empty( $resolved ) ) {
			$resolved['source'] = 'default';
			return $resolved;
		}

		return array();
	}

	/**
	 * Same resolver as {@see resolve_for_package()} for an existing order.
	 *
	 * @param \WC_Order           $order    Order.
	 * @param array<string,mixed> $explicit Explicit `length|width|height` in cm.
	 * @param array<string,mixed> $defaults Courier defaults in cm.
	 * @return array{length:float,width:float,height:float,source:string}|array{}
	 */
	public static function resolve_for_order( \WC_Order $order, array $explicit = array(), array $defaults = array() ) {
		$resolved = self::complete_dimensions( $explicit );
		if ( ! empty( $resolved ) ) {
			$resolved['source'] = 'explicit';
			return $resolved;
		}

		$product = self::single_unit_dimensions_from_items( $order->get_items( 'line_item' ), true );
		if ( ! empty( $product ) ) {
			$product['source'] = 'product';
			return $product;
		}

		$resolved = self::complete_dimensions( $defaults );
		if ( ! empty( $resolved ) ) {
			$resolved['source'] = 'default';
			return $resolved;
		}

		return array();
	}

	/**
	 * Validate the courier-agnostic package editor rows used by Econt, Speedy
	 * and Pigeon. Once the merchant starts using explicit rows, every row must
	 * contain a positive length/width/height/weight; silently falling back to a
	 * legacy one-parcel payload would discard intentional package data.
	 *
	 * @param mixed $rows Raw package rows.
	 * @return int Zero when valid/unused, otherwise the 1-based invalid row.
	 */
	public static function invalid_complete_row( $rows ) {
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return 0;
		}

		foreach ( array_values( $rows ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return $index + 1;
			}
			foreach ( array( 'length', 'width', 'height', 'weight' ) as $key ) {
				if ( ! isset( $row[ $key ] ) || ! is_numeric( $row[ $key ] ) || (float) $row[ $key ] <= 0 ) {
					return $index + 1;
				}
			}
		}

		return 0;
	}

	/**
	 * Smallest BOX NOW compartment that can fit one rectangular item in any
	 * orientation. Returns 0 when the item exceeds the largest compartment.
	 *
	 * @param float $length Length in cm.
	 * @param float $width  Width in cm.
	 * @param float $height Height in cm.
	 * @return int
	 */
	public static function boxnow_size_for_dimensions( $length, $width, $height ) {
		$item = array_map( 'floatval', array( $length, $width, $height ) );
		if ( min( $item ) <= 0 ) {
			return 0;
		}
		sort( $item, SORT_NUMERIC );

		foreach ( self::BOXNOW_COMPARTMENTS as $size => $box ) {
			$box_sorted = $box;
			sort( $box_sorted, SORT_NUMERIC );
			if ( $item[0] <= $box_sorted[0] && $item[1] <= $box_sorted[1] && $item[2] <= $box_sorted[2] ) {
				return (int) $size;
			}
		}

		return 0;
	}

	/**
	 * @param array<int|string,mixed> $items    Cart package contents or order items.
	 * @param bool                    $is_order Whether $items are WC_Order_Item_Product objects.
	 * @return array<string,mixed>
	 */
	private static function profile_from_items( array $items, $is_order ) {
		$physical_units           = 0;
		$known_units              = 0;
		$minimum_compartment_size = 0;
		$oversize                 = false;
		$known_weight_units        = 0;
		$max_unit_weight_kg        = 0.0;
		$oversize_product_ids     = array();
		$oversize_products       = array();
		$overweight_products     = array();

		foreach ( $items as $item ) {
			$product  = null;
			$quantity = 0;

			if ( $is_order ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
					continue;
				}
				$product  = $item->get_product();
				$quantity = max( 1, (int) $item->get_quantity() );
			} else {
				$product  = isset( $item['data'] ) && is_object( $item['data'] ) ? $item['data'] : null;
				$quantity = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			}

			if ( ! $product || ! method_exists( $product, 'is_virtual' ) || $product->is_virtual() ) {
				continue;
			}

			$physical_units += $quantity;

			$unit_weight = self::product_weight_kg( $product );
			if ( $unit_weight > 0 ) {
				$known_weight_units += $quantity;
				$max_unit_weight_kg = max( $max_unit_weight_kg, $unit_weight );
				if ( $unit_weight > 20.0 ) {
					$overweight_product              = self::product_reference( $product, $quantity );
					$overweight_product['weight_kg'] = round( $unit_weight, 6 );
					$overweight_products[]           = $overweight_product;
				}
			}

			$dims = self::product_dimensions_cm( $product );
			if ( empty( $dims ) ) {
				continue;
			}

			$known_units += $quantity;
			$size = self::boxnow_size_for_dimensions( $dims['length'], $dims['width'], $dims['height'] );
			if ( 0 === $size ) {
				$oversize = true;
				$oversize_product                  = self::product_reference( $product, $quantity );
				$oversize_product['dimensions_cm'] = $dims;
				$oversize_products[]               = $oversize_product;
				if ( method_exists( $product, 'get_id' ) ) {
					$oversize_product_ids[] = (int) $product->get_id();
				}
				continue;
			}
			$minimum_compartment_size = max( $minimum_compartment_size, $size );
		}

		return array(
			'has_physical'              => $physical_units > 0,
			'physical_units'            => $physical_units,
			'dimensions_known'          => $physical_units > 0 && $known_units === $physical_units,
			'known_units'               => $known_units,
			'weights_known'              => $physical_units > 0 && $known_weight_units === $physical_units,
			'max_unit_weight_kg'         => $max_unit_weight_kg,
			'minimum_compartment_size'  => $minimum_compartment_size,
			'single_unit'               => 1 === $physical_units,
			'oversize'                  => $oversize,
			'oversize_product_ids'      => array_values( array_unique( $oversize_product_ids ) ),
			'oversize_products'         => $oversize_products,
			'overweight'                => ! empty( $overweight_products ),
			'overweight_products'       => $overweight_products,
		);
	}

	/**
	 * Public product identity used only for a customer-facing eligibility reason.
	 *
	 * @param object $product  WC_Product-like object.
	 * @param int    $quantity Package quantity.
	 * @return array<string,mixed>
	 */
	private static function product_reference( $product, $quantity ) {
		return array(
			'id'        => method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0,
			'parent_id' => method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0,
			'name'      => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '',
			'quantity'  => max( 1, (int) $quantity ),
		);
	}

	/**
	 * Dimensions of the one physical product unit in a cart/order. Returns
	 * nothing for multi-unit shipments because combining items into a carton is
	 * a packing problem and cannot be inferred safely from product dimensions.
	 *
	 * @param array<int|string,mixed> $items    Cart contents or order items.
	 * @param bool                    $is_order Order-item shape.
	 * @return array{length:float,width:float,height:float}|array{}
	 */
	private static function single_unit_dimensions_from_items( array $items, $is_order ) {
		$physical_units = 0;
		$dimensions     = array();

		foreach ( $items as $item ) {
			$product  = null;
			$quantity = 0;

			if ( $is_order ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
					continue;
				}
				$product  = $item->get_product();
				$quantity = max( 1, (int) $item->get_quantity() );
			} else {
				$product  = isset( $item['data'] ) && is_object( $item['data'] ) ? $item['data'] : null;
				$quantity = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			}

			if ( ! $product || ! method_exists( $product, 'is_virtual' ) || $product->is_virtual() ) {
				continue;
			}

			$physical_units += $quantity;
			if ( $physical_units > 1 ) {
				return array();
			}

			$dimensions = self::product_dimensions_cm( $product );
		}

		return 1 === $physical_units ? $dimensions : array();
	}

	/**
	 * @param array<string,mixed> $values Dimension map.
	 * @return array{length:float,width:float,height:float}|array{}
	 */
	private static function complete_dimensions( array $values ) {
		$out = array();
		foreach ( array( 'length', 'width', 'height' ) as $key ) {
			if ( ! isset( $values[ $key ] ) || ! is_numeric( $values[ $key ] ) || (float) $values[ $key ] <= 0 ) {
				return array();
			}
			$out[ $key ] = (float) $values[ $key ];
		}
		return $out;
	}

	/**
	 * @param object $product WC_Product-like object.
	 * @return array{length:float,width:float,height:float}|array{}
	 */
	private static function product_dimensions_cm( $product ) {
		foreach ( array( 'get_length', 'get_width', 'get_height' ) as $method ) {
			if ( ! method_exists( $product, $method ) ) {
				return array();
			}
		}

		$length = self::dimension_to_cm( $product->get_length() );
		$width  = self::dimension_to_cm( $product->get_width() );
		$height = self::dimension_to_cm( $product->get_height() );
		if ( $length <= 0 || $width <= 0 || $height <= 0 ) {
			return array();
		}

		return array(
			'length' => $length,
			'width'  => $width,
			'height' => $height,
		);
	}

	/** Convert one WooCommerce product weight to kilograms. */
	private static function product_weight_kg( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_weight' ) ) {
			return 0.0;
		}

		$value = (float) $product->get_weight();
		if ( $value <= 0 ) {
			return 0.0;
		}

		$unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );
		if ( function_exists( 'wc_get_weight' ) ) {
			return (float) wc_get_weight( $value, 'kg', $unit );
		}

		switch ( $unit ) {
			case 'g':
				return $value / 1000;
			case 'lbs':
				return $value * 0.45359237;
			case 'oz':
				return $value * 0.028349523125;
			default:
				return $value;
		}
	}

	/** Convert the WooCommerce store dimension unit to centimetres. */
	private static function dimension_to_cm( $value ) {
		$value = (float) $value;
		if ( $value <= 0 ) {
			return 0.0;
		}

		if ( function_exists( 'wc_get_dimension' ) ) {
			$unit = (string) get_option( 'woocommerce_dimension_unit', 'cm' );
			return (float) wc_get_dimension( $value, 'cm', $unit );
		}

		$unit = (string) get_option( 'woocommerce_dimension_unit', 'cm' );
		switch ( $unit ) {
			case 'm':
				return $value * 100;
			case 'mm':
				return $value / 10;
			case 'in':
				return $value * 2.54;
			case 'yd':
				return $value * 91.44;
			default:
				return $value;
		}
	}
}
