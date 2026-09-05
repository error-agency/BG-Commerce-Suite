<?php
/**
 * WooCommerce Blocks (Store API) integration: registers the front-end scripts
 * used by BGCS on Cart/Checkout Blocks and exposes the same `bgcsCheckout`
 * config used by the classic build.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Checkout;

defined( 'ABSPATH' ) || exit;

class Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

	/** @var string cart|checkout */
	private $surface = 'checkout';

	/**
	 * @param string $surface cart|checkout.
	 */
	public function __construct( $surface = 'checkout' ) {
		$this->surface = 'cart' === $surface ? 'cart' : 'checkout';
	}

	/**
	 * @return string
	 */
	public function get_name() {
		return 'bg-commerce-suite';
	}

	public function initialize() {
		$version = defined( 'BGCS3_VERSION' ) ? BGCS3_VERSION : '1.0.0';
		$data    = Checkout::frontend_data();
		$data['blocksSurface'] = $this->surface;

		// Lightweight semantic shipping-state bridge. It is intentionally loaded on
		// both Cart and Checkout Blocks: WooCommerce renders any numeric zero as
		// "Free", while BGCS also uses zero as its pre-selection pending placeholder.
		wp_register_script( 'bgcs-availability', BGCS3_URL . 'assets/js/bgcs-availability.js', array( 'wp-data' ), $version, true );
		wp_localize_script( 'bgcs-availability', 'bgcsCheckout', $data );

		// The Cart Block only needs the semantic price-state bridge. The full BGCS
		// selector is a Checkout inner block and depends on wc-blocks-checkout plus
		// Leaflet, so do not enqueue that heavier bundle on the cart page.
		if ( 'cart' === $this->surface ) {
			return;
		}

		$asset_file = BGCS3_PATH . 'assets/build/blocks.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;
		wp_register_script( 'bgcs-checkout-state', BGCS3_URL . 'assets/js/bgcs-checkout-state.js', array(), $version, true );
		wp_register_script(
			'bgcs-blocks-selection-state',
			BGCS3_URL . 'assets/js/bgcs-blocks-selection-state.js',
			array( 'bgcs-checkout-state', 'wc-blocks-checkout' ),
			$version,
			true
		);
		$dependencies = array_values( array_unique( array_merge( $asset['dependencies'], array( 'bgcs-availability', 'bgcs-blocks-selection-state' ) ) ) );
		wp_register_script( 'bgcs-blocks', BGCS3_URL . 'assets/build/blocks.js', $dependencies, $asset['version'], true );
		wp_localize_script( 'bgcs-blocks', 'bgcsCheckout', $data );

		if ( file_exists( BGCS3_PATH . 'assets/build/blocks.css' ) ) {
			wp_register_style( 'bgcs-blocks', BGCS3_URL . 'assets/build/blocks.css', array(), $asset['version'] );
			if ( file_exists( BGCS3_PATH . 'assets/build/blocks-rtl.css' ) ) {
				wp_style_add_data( 'bgcs-blocks', 'rtl', 'replace' );
			}

			// IntegrationInterface only auto-enqueues the script handles returned
			// by get_script_handles(). Styles registered in initialize() must be
			// explicitly enqueued. Without this, the Blocks selector (and especially
			// Leaflet's map container) is present in the DOM but has no usable height.
			wp_enqueue_style( 'bgcs-blocks' );
		}
	}

	/**
	 * @return string[]
	 */
	public function get_script_handles() {
		return 'cart' === $this->surface ? array( 'bgcs-availability' ) : array( 'bgcs-blocks' );
	}

	/**
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return 'cart' === $this->surface ? array() : array( 'bgcs-blocks' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_script_data() {
		$data = Checkout::frontend_data();
		$data['blocksSurface'] = $this->surface;
		return $data;
	}
}
