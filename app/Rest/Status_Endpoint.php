<?php
/**
 * GET /bg-commerce-suite/v3/status — sanity endpoint that reports plugin
 * versions and the registered modules. Admin-only.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Rest;

use BgCommerce3\Module\Categories;
use BgCommerce3\Module\Module_Registry;

defined( 'ABSPATH' ) || exit;

class Status_Endpoint extends Controller {

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function get_status() {
		/** @var Module_Registry $registry */
		$registry = $this->container['modules'];

		$incompatible = $registry->incompatible();

		$modules = array();
		foreach ( $registry->all() as $module ) {
			$id         = $module->id();
			$compatible = ! array_key_exists( $id, $incompatible );
			$enabled    = $module->is_enabled();
			$modules[]  = array(
				'id'             => $id,
				'name'           => $module->name(),
				'class'          => get_class( $module ),
				'category'       => $module->category(),
				'category_label' => Categories::label( $module->category() ),
				'enabled'        => $enabled,
				'requires_api'   => $module->requires_api(),
				'compatible'     => $compatible,
				'booted'         => $compatible && $enabled,
			);
		}

		return new \WP_REST_Response(
			array(
				'plugin'       => 'BG Commerce Suite',
				'version'      => BGCS3_VERSION,
				'api_version'  => BGCS3_API_VERSION,
				'modules'      => $modules,
				'incompatible' => $registry->incompatible(),
				'conflicts'    => $registry->conflicts(),
				'location_api' => Rest_Abuse_Guard::stats(),
			),
			200
		);
	}
}
