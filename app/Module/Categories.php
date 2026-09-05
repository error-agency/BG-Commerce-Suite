<?php
/**
 * Module categories. New integration types are added here.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Module;

defined( 'ABSPATH' ) || exit;

final class Categories {

	const SHIPPING   = 'shipping';
	const CHECKOUT   = 'checkout';
	const REPUTATION = 'reputation';
	const ACCOUNTING = 'accounting';
	const OTHER      = 'other';

	/**
	 * Human-readable labels.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			self::SHIPPING   => __( 'Shipping', 'bg-commerce-suite' ),
			self::CHECKOUT   => __( 'Checkout', 'bg-commerce-suite' ),
			self::REPUTATION => __( 'Customer reputation', 'bg-commerce-suite' ),
			self::ACCOUNTING => __( 'Accounting', 'bg-commerce-suite' ),
			self::OTHER      => __( 'Other', 'bg-commerce-suite' ),
		);
	}

	/**
	 * @param string $category Category key.
	 * @return string
	 */
	public static function label( $category ) {
		$labels = self::labels();
		return isset( $labels[ $category ] ) ? $labels[ $category ] : $category;
	}
}
