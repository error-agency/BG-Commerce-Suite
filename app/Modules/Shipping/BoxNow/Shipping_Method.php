<?php
/**
 * BoxNow WooCommerce shipping method. Thin wrapper extending the base shipping
 * method class.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\BoxNow;

use BgCommerce3\Shipping\Method;

defined( 'ABSPATH' ) || exit;

class Shipping_Method extends Method {

	/**
	 * Return the courier ID this shipping method represents.
	 *
	 * @return string
	 */
	public function get_courier_id() {
		return BoxNow::ID;
	}
}
