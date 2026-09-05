<?php
/**
 * Convenience base for modules. Provides the common enable-flag behaviour so
 * concrete modules only declare identity + register().
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Module;

use BgCommerce3\Container\Container;

defined( 'ABSPATH' ) || exit;

abstract class Abstract_Module implements Module_Interface {

	/**
	 * Default: requires the API version this Core was built against.
	 *
	 * @return string
	 */
	public function requires_api() {
		return '1.0';
	}

	/**
	 * Enabled when `bgcs3_{id}` option has `enable === 'yes'`.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$enabled = 'yes' === bgcs3_get_option( $this->id(), 'enable', 'no' );

		/**
		 * Gate a module behind a licence (add later without touching modules).
		 *
		 * @param bool   $licensed Whether the module may run.
		 * @param string $id       Module id.
		 */
		return $enabled && (bool) apply_filters( 'bgcs3_module_licensed', true, $this->id() );
	}

	/**
	 * No settings tab by default.
	 *
	 * @return array<string,mixed>|null
	 */
	public function settings_tab() {
		return null;
	}

	/**
	 * No settings fields by default.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function settings_fields() {
		return array();
	}

	/**
	 * @param Container $container Core DI container.
	 */
	abstract public function register( Container $container );
}
