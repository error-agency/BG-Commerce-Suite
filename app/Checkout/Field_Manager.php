<?php
/**
 * Hides the WooCommerce address fields that our courier selector makes
 * redundant (classic checkout), so the customer only fills name/phone/email
 * and picks an office/address in one menu. The hidden fields are auto-filled
 * from the BGCS Selection before WooCommerce validation and again on the order.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Checkout;

defined( 'ABSPATH' ) || exit;

class Field_Manager {

	const BODY_CLASS = 'bgcs3-clean-fields-active';

	/**
	 * Billing fields managed (hidden + not required) when "clean checkout" is on.
	 * billing_city is deliberately included: BGCS becomes the single visible
	 * city source and synchronises the native WooCommerce value behind the scenes.
	 *
	 * @var string[]
	 */
	const MANAGED = array(
		'billing_company',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_country',
	);

	/**
	 * Shipping fields that duplicate BGCS clean-checkout controls.
	 * Only the city is hidden here; the rest of the shipping-address section is
	 * left to WooCommerce/theme behaviour when a separate shipping address is used.
	 *
	 * @var string[]
	 */
	const SHIPPING_MANAGED = array(
		'shipping_city',
	);

	public function init() {
		if ( 'yes' !== bgcs3_get_option( 'checkout', 'hide_fields', 'no' ) ) {
			return;
		}

		add_filter( 'woocommerce_checkout_fields', array( $this, 'adjust_fields' ), 1000 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'hide_css' ), 20 );
	}

	/**
	 * Hide the managed fields deterministically by their WooCommerce field-id
	 * wrappers (theme-agnostic — does not rely on our extra class, which some
	 * themes strip).
	 */
	public function hide_css() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$selectors = array();
		foreach ( array_merge( self::MANAGED, self::SHIPPING_MANAGED ) as $key ) {
			$selectors[] = 'body.' . self::BODY_CLASS . ' #' . $key . '_field';
		}
		$selectors[] = 'body.' . self::BODY_CLASS . ' .bgcs-hidden-field';

		$css = implode( ',', $selectors ) . '{display:none !important;}';

		// Attach to our style if present, otherwise print standalone.
		if ( wp_style_is( 'bgcs-checkout', 'enqueued' ) || wp_style_is( 'bgcs-checkout', 'registered' ) ) {
			wp_add_inline_style( 'bgcs-checkout', $css );
		} else {
			wp_register_style( 'bgcs-hide-fields', false );
			wp_enqueue_style( 'bgcs-hide-fields' );
			wp_add_inline_style( 'bgcs-hide-fields', $css );
		}
	}

	/**
	 * Make a checkout field optional and add our deterministic hidden marker.
	 *
	 * @param array<string,array<string,mixed>> $group Field group.
	 * @param string                            $key   Field key.
	 * @return array<string,array<string,mixed>>
	 */
	private function hide_field( array $group, $key ) {
		if ( ! isset( $group[ $key ] ) || ! is_array( $group[ $key ] ) ) {
			return $group;
		}

		$was_required = ! empty( $group[ $key ]['required'] );
		$group[ $key ]['required'] = false;

		if ( empty( $group[ $key ]['custom_attributes'] ) || ! is_array( $group[ $key ]['custom_attributes'] ) ) {
			$group[ $key ]['custom_attributes'] = array();
		}
		$group[ $key ]['custom_attributes']['data-bgcs-original-required'] = $was_required ? '1' : '0';

		if ( empty( $group[ $key ]['class'] ) || ! is_array( $group[ $key ]['class'] ) ) {
			$group[ $key ]['class'] = array();
		}

		if ( ! in_array( 'bgcs-hidden-field', $group[ $key ]['class'], true ) ) {
			$group[ $key ]['class'][] = 'bgcs-hidden-field';
		}

		return $group;
	}

	/**
	 * Make managed WooCommerce fields optional and visually hidden.
	 *
	 * @param array<string,array<string,array<string,mixed>>> $fields Checkout fields.
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public function adjust_fields( $fields ) {
		// Clean checkout only owns native address fields while a BGCS rate is the
		// active shipping method. External methods must retain their normal fields
		// and required-state contract (for example a plugin that needs region/state).
		if ( ! $this->uses_bgcs_shipping() ) {
			return $fields;
		}

		if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
			$fields['billing'] = array();
		}
		if ( ! isset( $fields['shipping'] ) || ! is_array( $fields['shipping'] ) ) {
			$fields['shipping'] = array();
		}

		foreach ( self::MANAGED as $key ) {
			$fields['billing'] = $this->hide_field( $fields['billing'], $key );
		}
		foreach ( self::SHIPPING_MANAGED as $key ) {
			$fields['shipping'] = $this->hide_field( $fields['shipping'], $key );
		}

		return $fields;
	}

	/**
	 * Add an initial body marker to avoid a flash of native address fields when
	 * the current WooCommerce session already uses a BGCS shipping rate. The
	 * front-end script updates the marker whenever the selected method changes.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return $classes;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return $classes;
		}
		if ( $this->uses_bgcs_shipping() ) {
			$classes[] = self::BODY_CLASS;
		}
		return array_values( array_unique( $classes ) );
	}

	/**
	 * Resolve the shipping methods from the current checkout request/session.
	 * update_order_review sends the serialized form in post_data, while the final
	 * checkout request normally posts shipping_method directly.
	 *
	 * @return string[]
	 */
	private function selected_shipping_methods() {
		$methods = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce owns checkout nonces upstream.
		if ( isset( $_POST['shipping_method'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted = wp_unslash( $_POST['shipping_method'] );
			$methods = is_array( $posted ) ? $posted : array( $posted );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- update_order_review payload.
		if ( empty( $methods ) && isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
			$parsed = array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			parse_str( wp_unslash( $_POST['post_data'] ), $parsed );
			if ( isset( $parsed['shipping_method'] ) ) {
				$posted = $parsed['shipping_method'];
				$methods = is_array( $posted ) ? $posted : array( $posted );
			}
		}

		if ( empty( $methods ) && function_exists( 'WC' ) && WC()->session ) {
			$methods = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		}

		return array_values( array_filter( array_map( 'strval', $methods ) ) );
	}

	/**
	 * Whether the active shipping method is owned by BG Commerce Suite.
	 *
	 * @return bool
	 */
	private function uses_bgcs_shipping() {
		$methods = $this->selected_shipping_methods();
		if ( empty( $methods ) ) {
			return false;
		}

		// WooCommerce can have more than one shipping package. Clean checkout may
		// own the native address fields only when every selected package uses a
		// BGCS rate. One external selected rate is enough to preserve the complete
		// WooCommerce field contract for third-party integrations.
		foreach ( $methods as $rate_id ) {
			if ( 0 !== strpos( $rate_id, 'bgcs3_' ) ) {
				return false;
			}
		}

		return true;
	}

}
