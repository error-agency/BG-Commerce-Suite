<?php
/**
 * Base REST controller. Concrete endpoints extend this and implement
 * register_routes(). Provides shared namespace + permission helpers.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Rest;

use BgCommerce3\Container\Container;

defined( 'ABSPATH' ) || exit;

abstract class Controller {

	const NAMESPACE_V1 = 'bg-commerce-suite/v3';

	/** @var Container */
	protected $container;

	/**
	 * @param Container $container Core DI container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Register this controller's routes.
	 */
	abstract public function register_routes();

	/**
	 * Permission for public read endpoints (autocomplete, quotes).
	 * Reads are open but inputs are sanitised and results cached.
	 *
	 * @return bool
	 */
	public function public_permission() {
		return true;
	}

	/**
	 * Permission for admin/management endpoints.
	 *
	 * @return bool
	 */
	public function admin_permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Permission for front-end write endpoints (selection/quote). Requires a
	 * valid wp_rest nonce (sent automatically by wp.apiFetch) for CSRF safety.
	 * Works for both guests and logged-in customers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function write_permission( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}
}
