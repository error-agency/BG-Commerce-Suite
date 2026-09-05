<?php
/**
 * POST /bg-commerce-suite/v3/selection — validate and store the customer's
 * shipping selection in the WC session. Nonce-protected.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Rest;

use BgCommerce3\Module\Module_Registry;
use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Selection_Store;

defined( 'ABSPATH' ) || exit;

class Selection_Endpoint extends Controller {

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/selection',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save' ),
				'permission_callback' => array( $this, 'write_permission' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$guard = Rest_Abuse_Guard::check_checkout_write( $request, 'selection' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}

		$selection = Selection::from_array( is_array( $payload ) ? $payload : array() );

		/** @var Module_Registry $registry */
		$registry = $this->container['modules'];
		$module   = $registry->get( $selection->courier );

		if ( ! $module instanceof Courier_Interface || ! $module->is_enabled() ) {
			return new \WP_Error( 'bgcs3_unknown_courier', __( 'Unknown or inactive courier.', 'bg-commerce-suite' ), array( 'status' => 400 ) );
		}

		$allowed_types = (array) $module->delivery_types();
		if ( '' === $selection->delivery_type || ! in_array( $selection->delivery_type, $allowed_types, true ) ) {
			return new \WP_Error( 'bgcs3_invalid_delivery_type', __( 'This delivery type is not available for the selected courier.', 'bg-commerce-suite' ), array( 'status' => 400 ) );
		}

		// Store incomplete selections as drafts. This deliberately invalidates a
		// previously completed office/address when the customer switches courier or
		// delivery type in Blocks checkout, so an old validated rate cannot survive
		// behind a newly selected but unfinished destination. Courier-specific
		// validation is only meaningful once the destination is complete.
		if ( ! $selection->is_complete() ) {
			$store = new Selection_Store();
			if ( ! $store->set( $selection ) ) {
				$current = $store->get();
				return rest_ensure_response(
					array(
						'ok'        => true,
						'complete'  => $current ? $current->is_complete() : false,
						'stale'     => true,
						'selection' => $current ? $current->to_array() : array(),
					)
				);
			}
			\BgCommerce3\Shipping\Hooks::sync_and_recalc();
			return rest_ensure_response(
				array(
					'ok'        => true,
					'complete'  => false,
					'selection' => $selection->to_array(),
				)
			);
		}

		$valid = $module->validate( $selection );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$store = new Selection_Store();
		if ( ! $store->set( $selection ) ) {
			$current = $store->get();
			return rest_ensure_response(
				array(
					'ok'        => true,
					'complete'  => $current ? $current->is_complete() : false,
					'stale'     => true,
					'selection' => $current ? $current->to_array() : array(),
				)
			);
		}

		\BgCommerce3\Shipping\Hooks::sync_and_recalc();

		return rest_ensure_response(
			array(
				'ok'        => true,
				'complete'  => true,
				'selection' => $selection->to_array(),
			)
		);
	}
}
