<?php
/**
 * Protected location search for settings and order administration.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Rest;

use BgCommerce3\Module\Module_Registry;
use BgCommerce3\Modules\Shipping\Courier_Interface;

defined( 'ABSPATH' ) || exit;

class Admin_Locations_Endpoint extends Controller {

	const COURIER_PATTERN = '(?P<courier>[a-z0-9_-]+)';
	const RESOURCE_PATTERN = '(?P<resource>cities|streets|offices)';

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/' . self::COURIER_PATTERN . '/' . self::RESOURCE_PATTERN,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'query'   => array(
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_optional_query' ),
					),
					'city_id' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_optional_identifier' ),
					),
					'type'    => array(
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( $this, 'validate_location_type' ),
					),
				),
			)
		);
	}

	public function validate_optional_query( $value ) {
		return bgcs3_strlen( trim( (string) $value ) ) <= 80;
	}

	public function validate_optional_identifier( $value ) {
		$length = bgcs3_strlen( trim( (string) $value ) );
		return 0 === $length || $length <= 80;
	}

	public function validate_location_type( $value ) {
		$value = sanitize_key( (string) $value );
		return '' === $value || in_array( $value, array( 'office', 'locker' ), true );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search( \WP_REST_Request $request ) {
		$module = $this->resolve_courier( $request['courier'] );
		if ( is_wp_error( $module ) ) {
			return $module;
		}

		$resource = sanitize_key( (string) $request['resource'] );
		$query    = trim( (string) $request->get_param( 'query' ) );
		$city_id  = trim( (string) $request->get_param( 'city_id' ) );

		if ( in_array( $resource, array( 'cities', 'streets' ), true ) && bgcs3_strlen( $query ) < 2 ) {
			return new \WP_Error( 'bgcs3_search_too_short', __( 'Enter at least two characters.', 'bg-commerce-suite' ), array( 'status' => 400 ) );
		}
		if ( 'streets' === $resource && '' === $city_id ) {
			return new \WP_Error( 'bgcs3_city_required', __( 'Select a city first.', 'bg-commerce-suite' ), array( 'status' => 400 ) );
		}

		$result = $module->admin_location_search(
			$resource,
			array(
				'query'   => $query,
				'city_id' => $city_id,
				'type'    => sanitize_key( (string) $request->get_param( 'type' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = array();
		foreach ( array_slice( (array) $result, 0, 50 ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			$label = isset( $item['label'] ) ? $item['label'] : ( isset( $item['text'] ) ? $item['text'] : '' );
			if ( '' === (string) $label ) {
				continue;
			}
			$items[] = array(
				'id'   => sanitize_text_field( (string) $item['id'] ),
				'text' => sanitize_text_field( (string) $label ),
			);
		}

		return rest_ensure_response( array( 'results' => $items ) );
	}

	/**
	 * Resolve a registered courier even while it is disabled.
	 *
	 * @param string $slug Courier id.
	 * @return Courier_Interface|\WP_Error
	 */
	private function resolve_courier( $slug ) {
		/** @var Module_Registry $registry */
		$registry = $this->container['modules'];
		$module   = $registry->get( sanitize_key( (string) $slug ) );

		if ( ! $module instanceof Courier_Interface ) {
			return new \WP_Error( 'bgcs3_unknown_courier', __( 'Unknown courier.', 'bg-commerce-suite' ), array( 'status' => 404 ) );
		}

		return $module;
	}
}
