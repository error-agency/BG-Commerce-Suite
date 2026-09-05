<?php
/**
 * POST /bg-commerce-suite/v3/quote — price a selection against the current cart
 * for immediate feedback in the selector. Stores the selection in session too,
 * so the normal WC shipping calculation stays in sync. Nonce-protected.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Rest;

use BgCommerce3\Module\Module_Registry;
use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Shipping\Availability_Store;
use BgCommerce3\Shipping\Selection_Synchronizer;
use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Selection_Store;

defined( 'ABSPATH' ) || exit;

class Quote_Endpoint extends Controller {

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/quote',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'quote' ),
				'permission_callback' => array( $this, 'write_permission' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function quote( \WP_REST_Request $request ) {
		$guard = Rest_Abuse_Guard::check_checkout_write( $request, 'quote' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$payload   = $request->get_json_params();
		$selection = Selection::from_array( is_array( $payload ) ? $payload : array() );

		/** @var Module_Registry $registry */
		$registry = $this->container['modules'];
		$module   = $registry->get( $selection->courier );

		if ( ! $module instanceof Courier_Interface || ! $module->is_enabled() ) {
			return new \WP_Error( 'bgcs3_unknown_courier', __( 'Unknown or inactive courier.', 'bg-commerce-suite' ), array( 'status' => 400 ) );
		}

		if ( ! $selection->is_complete() ) {
			return new \WP_Error( 'bgcs3_incomplete_selection', __( 'Choose a complete delivery destination before requesting a price.', 'bg-commerce-suite' ), array( 'status' => 400 ) );
		}

		$allowed_types = (array) $module->delivery_types();
		if ( ! in_array( $selection->delivery_type, $allowed_types, true ) ) {
			return new \WP_Error( 'bgcs3_invalid_delivery_type', __( 'This delivery type is not available for the selected courier.', 'bg-commerce-suite' ), array( 'status' => 400 ) );
		}

		$valid = $module->validate( $selection );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Keep the same canonical session state used by Classic, Blocks and Flow.
		// A stale REST request must never overwrite a newer checkout selection.
		$store = new Selection_Store();
		if ( ! $store->set( $selection ) ) {
			return new \WP_Error( 'bgcs3_stale_selection', __( 'A newer delivery selection is already active. Refresh the checkout and try again.', 'bg-commerce-suite' ), array( 'status' => 409 ) );
		}

		if ( ! function_exists( 'WC' ) ) {
			return new \WP_Error( 'bgcs3_no_wc', __( 'WooCommerce is not active.', 'bg-commerce-suite' ), array( 'status' => 400 ) );
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! WC()->cart ) {
			return new \WP_Error( 'bgcs3_no_cart', __( 'There is no active cart.', 'bg-commerce-suite' ), array( 'status' => 400 ) );
		}

		// Do not call the provider directly here. WC_Shipping_Method is the single
		// pricing boundary: it applies free/static/API pricing, taxes, COD context,
		// package validation and WooCommerce's package cache. This also prevents a
		// REST quote followed by update_checkout from issuing two independent calls.
		Selection_Synchronizer::synchronize( $selection );
		$packages = WC()->shipping() && method_exists( WC()->shipping(), 'get_packages' )
			? (array) WC()->shipping()->get_packages()
			: array();
		$summary  = self::summarize_rates( $packages, $selection->courier );

		$availability = null;
		$public_errors = array();
		if ( ! $summary['valid'] ) {
			$cart_packages = (array) WC()->cart->get_shipping_packages();
			foreach ( ( new Availability_Store() )->current_public( $cart_packages ) as $row ) {
				if ( is_array( $row ) && isset( $row['courier'] ) && $selection->courier === sanitize_key( (string) $row['courier'] ) ) {
					$availability = $row;
					break;
				}
			}
			$public_errors[] = $availability && ! empty( $availability['customer_message'] )
				? (string) $availability['customer_message']
				: __( 'We cannot calculate this delivery price right now. Please try again or choose another method.', 'bg-commerce-suite' );
		}

		return rest_ensure_response(
			array(
				'valid'    => $summary['valid'],
				'cost'     => $summary['cost'],
				'currency' => get_woocommerce_currency(),
				'free'     => $summary['free'],
				'warnings' => $summary['warnings'],
				'errors'   => $public_errors,
				'availability' => $availability,
			)
		);
	}

	/**
	 * Aggregate the settled rate for every WooCommerce shipping package.
	 * Cost is returned gross, matching the old provider quote response.
	 *
	 * @param array<int|string,array<string,mixed>> $packages   Calculated WC packages.
	 * @param string                                $courier_id Courier id.
	 * @return array{valid:bool,cost:float,free:bool,warnings:array<int,string>}
	 */
	public static function summarize_rates( array $packages, $courier_id ) {
		$courier_id = sanitize_key( (string) $courier_id );
		$total      = 0.0;
		$matched    = 0;
		$all_free   = true;
		$warnings   = array();

		if ( '' === $courier_id || empty( $packages ) ) {
			return array( 'valid' => false, 'cost' => 0.0, 'free' => false, 'warnings' => array() );
		}

		foreach ( $packages as $package ) {
			$rates = is_array( $package ) && isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : array();
			$found = false;

			foreach ( $rates as $rate ) {
				if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_meta_data' ) || ! method_exists( $rate, 'get_cost' ) ) {
					continue;
				}
				$meta      = (array) $rate->get_meta_data();
				$owner     = isset( $meta['_bgcs3_courier'] ) ? sanitize_key( (string) $meta['_bgcs3_courier'] ) : '';
				$state     = isset( $meta['_bgcs3_price_state'] ) ? sanitize_key( (string) $meta['_bgcs3_price_state'] ) : '';
				$validated = ! empty( $meta['_bgcs3_validated'] );
				if ( $courier_id !== $owner || ! $validated || ! in_array( $state, array( 'calculated', 'free' ), true ) ) {
					continue;
				}

				$taxes = method_exists( $rate, 'get_taxes' ) ? (array) $rate->get_taxes() : array();
				$total += (float) $rate->get_cost() + array_sum( array_map( 'floatval', $taxes ) );
				$all_free = $all_free && 'free' === $state;
				$found    = true;
				++$matched;

				$rate_warnings = isset( $meta['_bgcs3_warnings'] ) ? $meta['_bgcs3_warnings'] : array();
				if ( ! is_array( $rate_warnings ) ) {
					$decoded = json_decode( (string) $rate_warnings, true );
					$rate_warnings = is_array( $decoded ) ? $decoded : array( $rate_warnings );
				}
				$warnings = array_merge( $warnings, $rate_warnings );
				break;
			}

			if ( ! $found ) {
				return array( 'valid' => false, 'cost' => 0.0, 'free' => false, 'warnings' => array() );
			}
		}

		$warnings = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $warnings ) ) ) );
		return array(
			'valid'    => $matched === count( $packages ),
			'cost'     => round( $total, wc_get_price_decimals() ),
			'free'     => $matched > 0 && $all_free,
			'warnings' => $warnings,
		);
	}
}
