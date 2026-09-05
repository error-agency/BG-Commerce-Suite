<?php
/**
 * GET /bg-commerce-suite/v3/availability — public, session-scoped, safe
 * non-selectable shipping states for Blocks and checkout add-ons.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Rest;

use BgCommerce3\Shipping\Availability_Store;

defined( 'ABSPATH' ) || exit;

final class Availability_Endpoint extends Controller {

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_availability' ),
				'permission_callback' => array( $this, 'public_permission' ),
			)
		);
	}

	/** @return \WP_REST_Response */
	public function get_availability() {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		$rows = ( new Availability_Store() )->current_public();
		/**
		 * Public shipping-availability contract for Flow and other renderers.
		 * Rows contain no technical diagnostics or provider response payloads.
		 *
		 * @param array<int,array<string,mixed>> $rows Public rows.
		 */
		$rows = (array) apply_filters( 'bgcs3_shipping_availability', $rows );
		return rest_ensure_response( array( 'availability' => array_values( $rows ) ) );
	}
}
