<?php
/**
 * The public contract every module implements — whether it ships inside Core
 * or comes from a separate DLC add-on plugin.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Module;

use BgCommerce3\Container\Container;

defined( 'ABSPATH' ) || exit;

interface Module_Interface {

	/**
	 * Unique, stable id (e.g. 'econt', 'speedy', 'cod_reports').
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function name();

	/**
	 * One of the Categories::* constants.
	 *
	 * @return string
	 */
	public function category();

	/**
	 * Minimum Core Module API version this module requires (e.g. '1.0').
	 * The registry refuses to boot incompatible modules.
	 *
	 * @return string
	 */
	public function requires_api();

	/**
	 * Whether the merchant has enabled this module.
	 *
	 * @return bool
	 */
	public function is_enabled();

	/**
	 * Optional settings-tab declaration for the admin UI, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function settings_tab();

	/**
	 * Admin settings fields for this module. Map of key => field definition:
	 * [ 'type' => 'text|password|select', 'label' => string, 'options' => array,
	 *   'default' => mixed, 'description' => string ]. Stored in option group
	 * named after the module id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function settings_fields();

	/**
	 * Wire the module into Core services. Called only when enabled and
	 * compatible. This is where hooks/shipping methods/REST get attached.
	 *
	 * @param Container $container Core DI container.
	 */
	public function register( Container $container );
}
