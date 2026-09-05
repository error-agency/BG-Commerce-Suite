<?php
/**
 * Pigeon Express WooCommerce shipping method (thin subclass; logic in
 * Shipping\Method).
 *
 * @package BgCommerce3\Pigeon
 */

namespace BgCommerce3\Modules\Shipping\Pigeon;

use BgCommerce3\Shipping\Method;

defined( 'ABSPATH' ) || exit;

class Shipping_Method extends Method {

	/**
	 * @return string
	 */
	public function get_courier_id() {
		return Pigeon::ID;
	}
}
