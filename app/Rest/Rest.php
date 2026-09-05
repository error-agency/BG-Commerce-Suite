<?php
/**
 * REST bootstrap: collects controllers and registers their routes on
 * rest_api_init. DLC add-ons can hook 'bgcs3_rest_controllers' to add their own.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Rest;

use BgCommerce3\Container\Container;

defined( 'ABSPATH' ) || exit;

class Rest {

	/** @var Container */
	private $container;

	/**
	 * @param Container $container Core DI container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$controllers = array(
			new Status_Endpoint( $this->container ),
			new Locations_Endpoint( $this->container ),
			new Admin_Locations_Endpoint( $this->container ),
			new Selection_Endpoint( $this->container ),
			new Quote_Endpoint( $this->container ),
			new Availability_Endpoint( $this->container ),
			new Webhook_Endpoint( $this->container ),
		);

		/**
		 * Allow modules/DLC to add REST controllers.
		 *
		 * @param Controller[] $controllers
		 * @param Container    $container
		 */
		$controllers = apply_filters( 'bgcs3_rest_controllers', $controllers, $this->container );

		foreach ( $controllers as $controller ) {
			if ( $controller instanceof Controller ) {
				$controller->register_routes();
			}
		}
	}
}
