<?php
/**
 * Econt WooCommerce shipping method. Thin subclass — all behaviour lives in the
 * shared base; this only declares which courier it serves.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\Econt;

use BgCommerce3\Shipping\Method;

defined( 'ABSPATH' ) || exit;

class Shipping_Method extends Method {

	/**
	 * @return string
	 */
	public function get_courier_id() {
		return Econt::ID;
	}
}
