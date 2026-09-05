<?php
/**
 * Shared checkout/shipping hooks for all couriers (registered once by Core when
 * at least one courier is active).
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Selection_Store;

defined( 'ABSPATH' ) || exit;

class Hooks {

	/**
	 * Neutral Bulgarian postcode used only to discover the merchant's shipping
	 * zone before a new customer has selected a real city or office.
	 */
	const FALLBACK_BG_POSTCODE = '1000';

	/** @var bool */
	private static $booted = false;

	/** @var bool */
	private static $loading_configured_zone_methods = false;

	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// On each checkout review update: persist the posted selection into the
		// session (same request) and recalc shipping so the price reflects it.
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'sync_and_recalc' ), 1 );

		// Payment method is not part of WooCommerce's shipping package cache key.
		// Re-check the cached BGCS rate before creating the order so a direct submit
		// cannot persist a prepaid quote after the shopper selected COD.
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'ensure_current_payment_quote' ), 1 );

		// WooCommerce normally stops before building shipping packages when a
		// required destination field is empty. Let the initial Bulgarian checkout
		// reach bootstrap_destination(), which supplies a non-persistent discovery
		// country/postcode from the store settings (or the emergency fallback).
		add_filter( 'woocommerce_shipping_calculator_enable_postcode', array( __CLASS__, 'allow_initial_calculation_without_postcode' ) );

		// A new anonymous customer may have no saved country or postcode. Bulgarian
		// shipping zones would then hide every BGCS method, including the selector
		// that supplies the real city/postcode. Bootstrap only the in-memory package
		// destination; never write these values to the customer.
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'bootstrap_destination' ), 5 );

		// Optional zero-configuration mode. WooCommerce normally calculates only
		// persisted zone instances, even when a courier class is registered. Supply
		// non-persistent BGCS method objects when the matched zone has no BGCS setup.
		add_filter( 'woocommerce_shipping_zone_shipping_methods', array( __CLASS__, 'fallback_shipping_methods' ), 20, 4 );

		// A provisional BGCS rate intentionally has cost 0 until the customer has
		// selected a complete office/locker/address. Zero here means "not priced",
		// never "free shipping". Replace WooCommerce's formatted zero with the
		// neutral pending-price text while the chosen rate is unvalidated.
		// Run after themes/checkout plugins that turn every zero-cost rate into
		// "Free". BGCS carries an explicit semantic price state, so only a rate
		// resolved by the free-shipping rule may be presented as free.
		add_filter( 'woocommerce_cart_shipping_total', array( __CLASS__, 'pending_shipping_total_label' ), 99999, 2 );
		add_filter( 'woocommerce_cart_shipping_method_full_label', array( __CLASS__, 'pending_shipping_method_label' ), 99999, 2 );

		// WooCommerce 9.2 gave WC_Shipping_Rate a delivery_time property. The Store
		// API exposes it and Blocks renders it as the rate's description line, so
		// this one filter is the whole of the Blocks integration — no JavaScript.
		// On older WooCommerce the getter does not exist, the filter never fires,
		// and Classic rendering below is unaffected.
		add_filter( 'woocommerce_shipping_rate_delivery_time', array( __CLASS__, 'rate_delivery_time' ), 10, 2 );

		// Classic checkout ignores delivery_time, so the estimate is rendered as
		// its own element on WooCommerce's per-rate action. Flow's standalone
		// checkout is Classic underneath and does not override cart-shipping.php,
		// so this serves both, and Flow supplies only the styling.
		add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'render_rate_delivery_time' ), 10, 2 );
	}


	/**
	 * Replace WooCommerce's formatted zero shipping total while the selected BGCS
	 * rate is only a destination-selection placeholder.
	 *
	 * @param string $formatted Formatted shipping total.
	 * @param mixed  $cart      WC_Cart instance when provided by WooCommerce.
	 * @return string
	 */
	public static function pending_shipping_total_label( $formatted, $cart = null ) {
		unset( $cart );

		if ( self::chosen_bgcs_rate_has_state( 'pending' ) ) {
			return esc_html__( 'Awaiting calculation', 'bg-commerce-suite' );
		}

		// A genuine Core free-shipping rule is a different semantic state from a
		// provisional zero. Make that distinction explicit instead of relying on
		// a theme to guess from numeric cost alone.
		if ( self::chosen_bgcs_rate_has_state( 'free', true ) ) {
			return esc_html__( 'Free shipping', 'bg-commerce-suite' );
		}

		return $formatted;
	}

	/**
	 * The announceable price state of a BGCS rate, as plain text.
	 *
	 * This is the public wording contract for the semantic state. Core owns the
	 * words; every consumer owns its own markup and decides where to put them.
	 * Returns an empty string when the rate carries no state worth announcing,
	 * including genuinely free transport that still charges a positive
	 * payment-service surcharge.
	 *
	 * Since 3.0.52 the method label no longer carries this wording, so a renderer
	 * that wants to show the state must ask for it here (or read the public
	 * `price_state` rate meta) instead of parsing the label.
	 *
	 * @param mixed $rate WC_Shipping_Rate.
	 * @return string Translated state text, or '' when none applies.
	 */
	public static function rate_price_state_text( $rate ) {
		if ( ! self::rate_is_bgcs( $rate ) ) {
			return '';
		}

		$state = self::rate_price_state( $rate );

		if ( 'pending' === $state ) {
			return __( 'Awaiting calculation', 'bg-commerce-suite' );
		}

		// Free transport may still carry a separately configured payment-service
		// surcharge (for example Speedy PMT). Do not hide a real positive charge.
		if ( 'free' === $state && self::rate_customer_cost( $rate ) <= 0.0001 ) {
			return __( 'Free shipping', 'bg-commerce-suite' );
		}

		return '';
	}

	/**
	 * The courier name is identity, not a status line — this filter never adds to it.
	 *
	 * A shipping row has two slots: the method name and its price. The semantic
	 * state belongs in the price slot, because that is the thing the state is
	 * actually about, and because both surfaces already own that slot:
	 * `pending_shipping_total_label()` for the classic cart total, and
	 * `assets/js/bgcs-availability.js` for the Cart/Checkout block rate rows and
	 * totals row. Writing the state into the name as well announced it twice and
	 * turned "Доставка със Спиди" into "Доставка със Спиди: Безплатна доставка".
	 *
	 * What remains here is the one thing only this filter can do. WooCommerce
	 * renders no price for a zero-cost rate, but a theme may format its own
	 * "Free" into the label before this point. On a `pending` rate that claim is
	 * false — the price is not zero, it is not yet known — so the label is
	 * rebuilt from the raw courier title to drop it. Nothing is appended.
	 *
	 * Genuinely free transport is left completely alone: the zero is true, and
	 * WooCommerce already words it correctly in the price slot.
	 *
	 * @param string $label Formatted rate label.
	 * @param mixed  $rate  WC_Shipping_Rate.
	 * @return string
	 */
	public static function pending_shipping_method_label( $label, $rate ) {
		if ( ! self::rate_is_bgcs( $rate ) ) {
			return $label;
		}

		if ( 'pending' !== self::rate_price_state( $rate ) ) {
			return $label;
		}

		// Rebuild from the raw rate title at a deliberately late filter priority,
		// so a theme's "free" wording for the provisional zero does not survive.
		$base_label = trim( wp_strip_all_tags( self::rate_base_label( $rate, $label ) ) );
		if ( '' === $base_label ) {
			return $label;
		}

		return rtrim( $base_label, " \t\n\r\0\x0B:" );
	}

	/**
	 * Whether at least one currently chosen BGCS rate has the requested semantic
	 * price state. Live WooCommerce packages are preferred; the session cache is
	 * only a fallback for themes that render before WC_Shipping exposes packages.
	 *
	 * @param string $state             pending|calculated|free|unavailable.
	 * @param bool   $require_zero_cost For a genuine free label, avoid hiding a
	 *                                  positive surcharge carried by the rate.
	 * @return bool
	 */
	private static function chosen_bgcs_rate_has_state( $state, $require_zero_cost = false ) {
		$state = sanitize_key( (string) $state );
		if ( '' === $state || ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		$chosen   = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$packages = array();

		if ( WC()->shipping() && method_exists( WC()->shipping(), 'get_packages' ) ) {
			$packages = (array) WC()->shipping()->get_packages();
		}

		foreach ( $packages as $package_key => $package ) {
			$rates = isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : array();
			$rate_id = isset( $chosen[ $package_key ] ) ? (string) $chosen[ $package_key ] : '';

			// WooCommerce can resolve the default selected rate before the session
			// has persisted chosen_shipping_methods (common on the first cart render).
			if ( '' === $rate_id && function_exists( 'wc_get_chosen_shipping_method_for_package' ) ) {
				$rate_id = (string) wc_get_chosen_shipping_method_for_package( $package_key, $package );
			}

			if ( 0 !== strpos( $rate_id, 'bgcs3_' ) || ! isset( $rates[ $rate_id ] ) ) {
				continue;
			}

			$rate = $rates[ $rate_id ];
			if ( $state === self::rate_price_state( $rate ) && ( ! $require_zero_cost || self::rate_customer_cost( $rate ) <= 0.0001 ) ) {
				return true;
			}
		}

		// Fallback to the same package snapshots WooCommerce stores in the session.
		foreach ( $chosen as $package_key => $rate_id ) {
			$rate_id = (string) $rate_id;
			if ( 0 !== strpos( $rate_id, 'bgcs3_' ) ) {
				continue;
			}

			$stored = WC()->session->get( 'shipping_for_package_' . $package_key );
			if ( ! is_array( $stored ) || empty( $stored['rates'] ) || ! is_array( $stored['rates'] ) || ! isset( $stored['rates'][ $rate_id ] ) ) {
				continue;
			}

			$rate = $stored['rates'][ $rate_id ];
			if ( $state === self::rate_price_state( $rate ) && ( ! $require_zero_cost || self::rate_customer_cost( $rate ) <= 0.0001 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Publish the courier estimate as the rate's own delivery_time.
	 *
	 * @param string $delivery_time Current value.
	 * @param mixed  $rate          WC_Shipping_Rate-like object.
	 * @return string
	 */
	public static function rate_delivery_time( $delivery_time, $rate ) {
		$estimate = self::rate_estimate( $rate );

		return empty( $estimate ) ? $delivery_time : Delivery_Estimate::describe( $estimate );
	}

	/**
	 * Print the courier estimate under a Classic checkout shipping rate.
	 *
	 * @param mixed $rate  WC_Shipping_Rate-like object.
	 * @param mixed $index Rate index in the package. Unused.
	 * @return void
	 */
	public static function render_rate_delivery_time( $rate, $index = null ) {
		unset( $index );

		$estimate = self::rate_estimate( $rate );
		if ( empty( $estimate ) ) {
			return;
		}

		$text = Delivery_Estimate::describe( $estimate );
		if ( '' === $text ) {
			return;
		}

		echo '<span class="bgcs3-rate-eta">' . esc_html( $text ) . '</span>';
	}

	/**
	 * The normalized estimate a BGCS rate carries, if any.
	 *
	 * Read from meta rather than the label: meta is the only accessor that does
	 * not re-enter the label filter chain, and the label no longer carries it.
	 *
	 * @param mixed $rate WC_Shipping_Rate-like object.
	 * @return array<string,string> Empty when there is no estimate.
	 */
	private static function rate_estimate( $rate ) {
		if ( ! self::rate_is_bgcs( $rate ) || ! method_exists( $rate, 'get_meta_data' ) ) {
			return array();
		}

		$meta = (array) $rate->get_meta_data();

		return isset( $meta['_bgcs3_delivery_estimate'] )
			? Delivery_Estimate::sanitize( $meta['_bgcs3_delivery_estimate'] )
			: array();
	}

	/**
	 * @param mixed $rate WC_Shipping_Rate-like object.
	 * @return bool
	 */
	private static function rate_is_bgcs( $rate ) {
		if ( ! is_object( $rate ) ) {
			return false;
		}

		$rate_id = method_exists( $rate, 'get_id' ) ? (string) $rate->get_id() : ( isset( $rate->id ) ? (string) $rate->id : '' );
		return 0 === strpos( $rate_id, 'bgcs3_' );
	}

	/**
	 * @param mixed $rate WC_Shipping_Rate-like object.
	 * @return string pending|calculated|free|unavailable|unknown.
	 */
	private static function rate_price_state( $rate ) {
		if ( ! self::rate_is_bgcs( $rate ) ) {
			return 'unknown';
		}

		$meta = method_exists( $rate, 'get_meta_data' ) ? (array) $rate->get_meta_data() : array();
		if ( empty( $meta ) && isset( $rate->meta_data ) && is_array( $rate->meta_data ) ) {
			$meta = $rate->meta_data;
		}

		$price_state = isset( $meta['_bgcs3_price_state'] ) ? sanitize_key( (string) $meta['_bgcs3_price_state'] ) : '';
		if ( in_array( $price_state, array( 'pending', 'calculated', 'free', 'unavailable' ), true ) ) {
			return $price_state;
		}

		$is_free   = ! empty( $meta['_bgcs3_free_shipping'] );
		$validated = isset( $meta['_bgcs3_validated'] ) ? filter_var( $meta['_bgcs3_validated'], FILTER_VALIDATE_BOOLEAN ) : false;

		if ( ! $validated ) {
			return 'pending';
		}

		return $is_free ? 'free' : 'calculated';
	}

	/**
	 * @param mixed $rate WC_Shipping_Rate-like object.
	 * @return float
	 */
	private static function rate_customer_cost( $rate ) {
		if ( ! is_object( $rate ) ) {
			return 0.0;
		}

		if ( method_exists( $rate, 'get_cost' ) ) {
			return (float) $rate->get_cost();
		}

		return isset( $rate->cost ) ? (float) $rate->cost : 0.0;
	}

	/**
	 * Get the presentation-neutral courier title, avoiding a theme-generated
	 * "Free" fragment that may already exist in the formatted fallback label.
	 *
	 * @param mixed  $rate     WC_Shipping_Rate-like object.
	 * @param string $fallback Already formatted label.
	 * @return string
	 */
	private static function rate_base_label( $rate, $fallback ) {
		// The name this shipping method published for itself, before any filter.
		//
		// There is no way back to it through WC_Shipping_Rate: get_label() runs
		// `woocommerce_shipping_rate_label`, and the magic __get( 'label' )
		// forwards to get_label(). Reading either from inside a label filter
		// returns whatever every other participant has already appended — which
		// is exactly the value this method exists to discard. Meta is the only
		// accessor that does not re-enter the chain.
		if ( is_object( $rate ) && method_exists( $rate, 'get_meta_data' ) ) {
			$meta = (array) $rate->get_meta_data();
			foreach ( array( '_bgcs3_method_title', 'method_title' ) as $key ) {
				if ( isset( $meta[ $key ] ) && '' !== trim( wp_strip_all_tags( (string) $meta[ $key ] ) ) ) {
					return (string) $meta[ $key ];
				}
			}
		}

		// A rate calculated before this meta existed, replayed from a session
		// cache. Nothing better is reachable, so the caller's own input stands.
		return (string) $fallback;
	}

	/**
	 * Provide active BGCS couriers without requiring a persisted zone instance.
	 * Existing BGCS zone configuration (enabled or disabled) always wins.
	 *
	 * @param array<int|string,object>    $methods         Enabled methods in the matched zone.
	 * @param array<int,object>           $raw_methods     Raw enabled zone methods.
	 * @param array<string,object|string> $allowed_classes Registered shipping method classes.
	 * @param object                      $zone            Matched WooCommerce shipping zone.
	 * @return array<int|string,object>
	 */
	public static function fallback_shipping_methods( $methods, $raw_methods, $allowed_classes, $zone ) {
		if ( self::$loading_configured_zone_methods || 'yes' !== bgcs3_get_option( 'checkout', 'shipping_zone_fallback', 'no' ) ) {
			return $methods;
		}

		$is_admin_request = function_exists( 'is_admin' ) && is_admin();
		$is_ajax_request  = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		if ( $is_admin_request && ! $is_ajax_request ) {
			return $methods;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return $methods;
		}

		$country = strtoupper( (string) WC()->customer->get_shipping_country() );
		if ( '' === $country ) {
			$country = self::configured_bg_destination();
		}
		if ( 'BG' !== $country ) {
			return $methods;
		}

		if ( ! is_object( $zone ) || ! method_exists( $zone, 'get_shipping_methods' ) ) {
			return $methods;
		}

		self::$loading_configured_zone_methods = true;
		try {
			$configured_methods = $zone->get_shipping_methods( false );
		} finally {
			self::$loading_configured_zone_methods = false;
		}

		foreach ( (array) $configured_methods as $configured_method ) {
			$method_id = is_object( $configured_method ) && isset( $configured_method->id )
				? (string) $configured_method->id
				: '';
			if ( 0 === strpos( $method_id, 'bgcs3_' ) ) {
				return $methods;
			}
		}

		foreach ( (array) $allowed_classes as $method_id => $method_class ) {
			$method_id = (string) $method_id;
			if ( 0 !== strpos( $method_id, 'bgcs3_' ) ) {
				continue;
			}

			$class_name = is_object( $method_class ) ? get_class( $method_class ) : $method_class;
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}

			$method = new $class_name( 0 );
			if ( ! method_exists( $method, 'enable_runtime_fallback' ) ) {
				continue;
			}

			$method->enable_runtime_fallback();
			$methods[ 'bgcs-fallback-' . $method_id ] = $method;
		}

		return $methods;
	}

	/**
	 * Make BGCS rates discoverable for a new Bulgarian checkout session.
	 *
	 * @param array<int,array<string,mixed>> $packages Shipping packages.
	 * @return array<int,array<string,mixed>>
	 */
	public static function bootstrap_destination( $packages ) {
		if ( ! self::is_checkout_request() || ! is_array( $packages ) ) {
			return $packages;
		}

		foreach ( $packages as $key => $package ) {
			if ( ! is_array( $package ) || empty( $package['destination'] ) || ! is_array( $package['destination'] ) ) {
				continue;
			}

			$destination_country = isset( $package['destination']['country'] ) ? strtoupper( (string) $package['destination']['country'] ) : '';
			$postcode = isset( $package['destination']['postcode'] ) ? trim( (string) $package['destination']['postcode'] ) : '';

			if ( '' === $destination_country ) {
				$destination_country = self::configured_bg_destination();
				if ( '' !== $destination_country ) {
					$packages[ $key ]['destination']['country'] = $destination_country;
				}
			}

			if ( 'BG' !== $destination_country || '' !== $postcode ) {
				continue;
			}

			$packages[ $key ]['destination']['postcode'] = self::store_postcode( $destination_country );
		}

		return $packages;
	}

	/**
	 * Allow the first Bulgarian checkout calculation to reach the package
	 * filter even when WooCommerce considers the empty postcode required.
	 *
	 * @param bool $enabled Whether WooCommerce should require a postcode first.
	 * @return bool
	 */
	public static function allow_initial_calculation_without_postcode( $enabled ) {
		if ( ! $enabled || ! self::is_checkout_request() || ! function_exists( 'WC' ) || ! WC()->customer ) {
			return $enabled;
		}

		$country  = strtoupper( (string) WC()->customer->get_shipping_country() );
		$postcode = trim( (string) WC()->customer->get_shipping_postcode() );
		if ( '' === $country ) {
			$country = self::configured_bg_destination();
		}

		return ( 'BG' === $country && '' === $postcode ) ? false : $enabled;
	}

	/**
	 * Use Bulgaria only when WooCommerce allows exactly one shipping country.
	 * This resolves an empty initial checkout session without overriding a real
	 * customer destination or guessing when the merchant serves more countries.
	 *
	 * @return string Bulgaria ISO-2 code or an empty string.
	 */
	private static function configured_bg_destination() {
		if ( function_exists( 'bgcs3_get_option' ) && 'yes' === bgcs3_get_option( 'checkout', 'shipping_zone_fallback', 'no' ) ) {
			return 'BG';
		}

		if ( ! function_exists( 'WC' ) || ! WC()->countries || ! method_exists( WC()->countries, 'get_shipping_countries' ) ) {
			return '';
		}

		$shipping_countries = WC()->countries->get_shipping_countries();
		if ( ! is_array( $shipping_countries ) || 1 !== count( $shipping_countries ) ) {
			return '';
		}

		$country_codes = array_keys( $shipping_countries );
		$country       = strtoupper( (string) $country_codes[0] );

		return 'BG' === $country ? $country : '';
	}

	/**
	 * Prefer the configured WooCommerce store postcode when the store and
	 * destination are in the same country. The hard fallback is used only when
	 * the store address is incomplete or belongs to another country.
	 *
	 * @param string $destination_country Destination ISO-2 country.
	 * @return string
	 */
	private static function store_postcode( $destination_country ) {
		$destination_country = strtoupper( (string) $destination_country );
		$store_country       = '';
		$store_postcode      = '';

		if ( function_exists( 'WC' ) && WC()->countries ) {
			$store_country  = strtoupper( (string) WC()->countries->get_base_country() );
			$store_postcode = trim( (string) WC()->countries->get_base_postcode() );
		}

		if ( '' === $store_postcode ) {
			$store_postcode = trim( (string) get_option( 'woocommerce_store_postcode', '' ) );
		}

		if ( '' === $store_country ) {
			$base          = explode( ':', (string) get_option( 'woocommerce_default_country', '' ), 2 );
			$store_country = strtoupper( (string) $base[0] );
		}

		if ( '' === $store_postcode || $store_country !== $destination_country ) {
			return self::FALLBACK_BG_POSTCODE;
		}

		return function_exists( 'wc_format_postcode' )
			? wc_format_postcode( $store_postcode, $destination_country )
			: $store_postcode;
	}

	/**
	 * Initial checkout page or WooCommerce's checkout-review AJAX request.
	 *
	 * @return bool
	 */
	private static function is_checkout_request() {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		$is_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'WC_DOING_AJAX' ) && WC_DOING_AJAX );
		if ( ! $is_ajax ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request routing only.
		$endpoint = isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : '';
		return 'update_order_review' === $endpoint;
	}

	/**
	 * @param string $post_data Serialized checkout form data.
	 */
	public static function sync_and_recalc( $post_data = '' ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}
		$store = new Selection_Store();
		$posted = array();

		// The selection travels with the checkout form (#bgcs3_selection). Reading
		// it here — in the SAME request that recalculates shipping — avoids the
		// REST/session cross-context mismatch that left rates unpriced.
		if ( is_string( $post_data ) && '' !== $post_data ) {
			parse_str( $post_data, $posted );

			if ( ! empty( $posted['bgcs3_selection'] ) ) {
				$raw  = wp_unslash( is_string( $posted['bgcs3_selection'] ) ? $posted['bgcs3_selection'] : '' );
				$data = json_decode( $raw, true );
				if ( is_array( $data ) ) {
					$store->set( Selection::from_array( $data ) );
				}
			}

			if ( ! empty( $posted['payment_method'] ) && is_string( $posted['payment_method'] ) ) {
				WC()->session->set( 'chosen_payment_method', sanitize_text_field( $posted['payment_method'] ) );
			}
		}

		// WC_AJAX posts shipping_method as a top-level field, while custom
		// renderers may also include it in post_data. This hook runs before
		// WooCommerce persists either value, so seed the verified current package
		// choice now and prevent a stale BGCS session rate from overwriting it.
		$posted_methods = isset( $posted['shipping_method'] ) && is_array( $posted['shipping_method'] )
			? $posted['shipping_method']
			: array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the AJAX nonce before this hook.
		if ( isset( $_POST['shipping_method'] ) && is_array( $_POST['shipping_method'] ) ) {
			$posted_methods = wp_unslash( $_POST['shipping_method'] );
		}
		if ( ! empty( $posted_methods ) ) {
			self::seed_posted_shipping_methods( $posted_methods );
		}

		$selection = $store->get();
		if ( $selection ) {
			$synchronized = Selection_Synchronizer::synchronize( $selection );
			self::align_posted_shipping_methods( $synchronized );
			return;
		}

		// No BGCS selection exists yet. Preserve the old initial-calculation path.
		$packages = WC()->cart->get_shipping_packages();
		foreach ( array_keys( $packages ) as $key ) {
			WC()->session->set( 'shipping_for_package_' . $key, null );
		}
		WC()->cart->calculate_shipping();
	}

	/**
	 * Provisionally apply posted rate IDs before courier synchronization.
	 *
	 * WooCommerce invokes this hook before it has populated the request's shipping
	 * package snapshots. Selection_Synchronizer immediately recalculates shipping,
	 * so WooCommerce validates each provisional ID against fresh package rates
	 * before the method can reach checkout totals or order creation.
	 *
	 * @param array<int|string,mixed> $posted_methods Posted package choices.
	 */
	private static function seed_posted_shipping_methods( array $posted_methods ) {
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );

		foreach ( $posted_methods as $package_key => $posted_rate_id ) {
			if ( ! is_scalar( $posted_rate_id ) ) {
				continue;
			}

			$rate_id = sanitize_text_field( (string) $posted_rate_id );
			if ( '' !== $rate_id ) {
				$chosen[ $package_key ] = $rate_id;
			}
		}

		WC()->session->set( 'chosen_shipping_methods', $chosen );
	}

	/**
	 * Recalculate BGCS shipping when its cached rate belongs to another payment
	 * context. This is the server-side guard behind the frontend gateway-change
	 * refresh and also protects custom checkout renderers/direct submissions.
	 */
	public static function ensure_current_payment_quote() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		$chosen   = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$expected = Cod::is_chosen() ? 'cod' : 'prepaid';
		$relevant = array();

		foreach ( $chosen as $package_key => $rate_id ) {
			$rate_id = (string) $rate_id;
			if ( 0 !== strpos( $rate_id, 'bgcs3_' ) ) {
				continue;
			}

			$relevant[] = $package_key;
			$stored     = WC()->session->get( 'shipping_for_package_' . $package_key );
			$rate       = is_array( $stored ) && isset( $stored['rates'][ $rate_id ] ) ? $stored['rates'][ $rate_id ] : null;
			$meta       = is_object( $rate ) && method_exists( $rate, 'get_meta_data' ) ? (array) $rate->get_meta_data() : array();
			$context    = isset( $meta['_bgcs3_payment_context'] ) ? sanitize_key( (string) $meta['_bgcs3_payment_context'] ) : '';

			if ( $expected === $context ) {
				array_pop( $relevant );
			}
		}

		if ( empty( $relevant ) ) {
			return;
		}

		$payment_method = Cod::chosen_method();
		if ( '' !== $payment_method ) {
			WC()->session->set( 'chosen_payment_method', $payment_method );
		}

		foreach ( array_unique( $relevant ) as $package_key ) {
			WC()->session->set( 'shipping_for_package_' . $package_key, null );
		}

		WC()->cart->calculate_shipping();
		if ( method_exists( WC()->cart, 'calculate_totals' ) ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Keep WooCommerce's top-level posted shipping methods aligned with the
	 * canonical selection. WC_AJAX reads this field after the review-update
	 * action and would otherwise restore the stale browser choice.
	 *
	 * @param array<string,mixed> $synchronized Synchronizer result.
	 */
	private static function align_posted_shipping_methods( array $synchronized ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the AJAX nonce before this hook.
		if ( ! isset( $_POST['shipping_method'] ) || ! is_array( $_POST['shipping_method'] ) ) {
			return;
		}

		$chosen   = isset( $synchronized['chosen'] ) && is_array( $synchronized['chosen'] ) ? $synchronized['chosen'] : array();
		$relevant = isset( $synchronized['relevant_package_keys'] ) && is_array( $synchronized['relevant_package_keys'] )
			? $synchronized['relevant_package_keys']
			: array();

		foreach ( $relevant as $package_key ) {
			$rate_id = isset( $chosen[ $package_key ] ) ? (string) $chosen[ $package_key ] : '';
			if ( '' === $rate_id ) {
				unset( $_POST['shipping_method'][ $package_key ] );
				continue;
			}

			$_POST['shipping_method'][ $package_key ] = sanitize_text_field( $rate_id );
		}
	}
}
