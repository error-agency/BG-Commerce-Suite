<?php
/**
 * Speedy WooCommerce shipping method (thin subclass; logic in Shipping\Method).
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\Speedy;

use BgCommerce3\Shipping\Method;

defined( 'ABSPATH' ) || exit;

class Shipping_Method extends Method {

	/**
	 * @return string
	 */
	public function get_courier_id() {
		return Speedy::ID;
	}
}
