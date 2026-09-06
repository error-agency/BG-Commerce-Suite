<?php
/**
 * Checkout orchestration for both classic and Blocks checkout:
 * - enqueues the shared Selector (classic) / registers the Blocks integration,
 * - prints the per-rate selector container in classic checkout,
 * - persists the Selection (from session) into order meta on order creation,
 * - validates that a selection exists when a bgcs method is chosen.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Checkout;

use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Selection_Store;
use BgCommerce3\Shipping\Availability_Store;
use BgCommerce3\Shipping\Delivery_Estimate;
use BgCommerce3\Shipping\Order_Persistence;
use BgCommerce3\Shipping\Selection_Synchronizer;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

class Checkout {

	const META_KEY = '_bgcs3_selection';

	/** @var Container */
	private $container;

	/**
	 * @param Container $container Core DI container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function init() {
		if ( empty( self::enabled_couriers() ) ) {
			return;
		}

		// Front-end assets (classic).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_classic' ) );

		// Classic: a hidden data marker after each bgcs rate (lives in order_review,
		// which WooCommerce rebuilds on every update) + a STABLE selector host in
		// the checkout form (survives update_checkout). The JS pairs them.
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'render_rate_meta' ), 10, 2 );
		add_action( 'woocommerce_review_order_after_shipping', array( $this, 'render_availability_cards' ), 20 );

		/**
		 * Where the selector host is printed. Must be a hook in form-checkout.php
		 * (outside the AJAX-refreshed fragments) so the selector survives
		 * update_checkout. Known-safe values: woocommerce_after_order_notes,
		 * woocommerce_checkout_after_customer_details,
		 * woocommerce_checkout_before_order_review_heading,
		 * woocommerce_checkout_after_order_review.
		 *
		 * @param string $hook Action name.
		 */
		$selector_hook = (string) apply_filters( 'bgcs3_selector_hook', 'woocommerce_after_order_notes' );
		add_action( $selector_hook, array( $this, 'render_host' ), 10 );

		// Optionally hide redundant address fields (classic).
		( new Field_Manager() )->init();

		// Clean checkout uses the BGCS city picker as the single visible city
		// source. Synchronise it into WooCommerce's native city fields BEFORE
		// checkout-field validation, so hidden billing/shipping city inputs never
		// produce a required-field error and downstream WC code still receives a
		// normal billing_city / shipping_city value.
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'sync_clean_checkout_city' ), 9999 );

		// Courier delivery requires a reachable recipient. Keep the native
		// WooCommerce phone field present and required even when an old checkout
		// designer preset or another earlier filter removed it.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'require_billing_phone' ), 999 );

		// Blocks: register UI integration and the official Store API cart-update
		// callback used by extensionCartUpdate(). Cart and Checkout have separate
		// IntegrationRegistry hooks; registering only the Checkout hook leaves the
		// Cart Block without BGCS runtime assets (and Woo then renders pending 0 as
		// "Free"). Use the same integration class for both surfaces.
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_update_callback' ) );
		add_action( 'woocommerce_blocks_cart_block_registration', array( $this, 'register_cart_blocks_integration' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_blocks_integration' ) );

		// Persist selection → order meta (classic + Blocks/Store API).
		add_action( 'woocommerce_checkout_create_order', array( $this, 'persist' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'persist' ), 10, 1 );

		// Keep the order's shipping line clean: drop the internal rate markers
		// (courier / allowed types / validation) — the readable bgcs3_* fields and
		// the order's _bgcs3_selection already capture the chosen delivery.
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( $this, 'clean_shipping_item_meta' ), 10, 4 );

		// Include the waybill number + tracking link in order emails.
		// 10 before 20: what the customer chose, then the waybill once it exists.
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_delivery' ), 10, 4 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_tracking' ), 20, 4 );

		// Classic validation.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );

		// Store API / Blocks validation. A BGCS method may be visible as a 0-cost
		// placeholder while the customer is still choosing a destination, but it
		// must never be accepted as a payable order until the current courier quote
		// has been validated. This server-side gate is independent from theme/JS.
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'validate_store_api_cart' ), 10, 2 );

		// Final shared pre-payment gate (WooCommerce 9.9+). Keep this in addition to
		// the cart validation so a direct Store API checkout request cannot bypass
		// the selected-destination/rate invariant. On older WooCommerce versions the
		// unknown hook is simply never fired.
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( $this, 'validate_order_before_payment' ), 10, 2 );
	}

	/**
	 * Ensure classic checkout always collects the recipient phone number.
	 *
	 * @param array<string,array<string,array<string,mixed>>> $fields Checkout fields.
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public function require_billing_phone( $fields ) {
		if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
			$fields['billing'] = array();
		}

		if ( ! isset( $fields['billing']['billing_phone'] ) || ! is_array( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone'] = array(
				'type'         => 'tel',
				'label'        => __( 'Phone', 'bg-commerce-suite' ),
				'required'     => true,
				'class'        => array( 'form-row-wide' ),
				'priority'     => 100,
				'autocomplete' => 'tel',
			);
		}

		$fields['billing']['billing_phone']['type']         = 'tel';
		$fields['billing']['billing_phone']['required']     = true;
		$fields['billing']['billing_phone']['autocomplete'] = 'tel';

		return $fields;
	}

	/**
	 * Enqueue the classic build (skipped on Blocks checkout and if not built).
	 */
	public function enqueue_classic() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		// On Blocks checkout the integration handles its own assets.
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) {
			return;
		}

		// Vendored Leaflet is only needed when the optional map selector is enabled.
		$show_map = 'yes' === bgcs3_get_option( 'checkout', 'show_map', 'yes' );
		if ( $show_map ) {
			wp_enqueue_style( 'bgcs-leaflet', BGCS3_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
			wp_enqueue_script( 'bgcs-leaflet', BGCS3_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
		}

		// Our selector (plain jQuery + optional Leaflet). 'bgcs-checkout' is the
		// handle Field_Manager attaches its inline hide-CSS to, and the handle
		// courier add-ons declare as a dependency for their own checkout scripts.
		wp_enqueue_style( 'bgcs-checkout', BGCS3_URL . 'assets/css/bgcs-checkout.css', array(), BGCS3_VERSION );
		wp_enqueue_script(
			'bgcs-checkout-state',
			BGCS3_URL . 'assets/js/bgcs-checkout-state.js',
			array(),
			BGCS3_VERSION,
			true
		);
		$checkout_deps = array( 'jquery', 'bgcs-checkout-state' );
		if ( $show_map ) {
			$checkout_deps[] = 'bgcs-leaflet';
		}
		wp_enqueue_script(
			'bgcs-checkout',
			BGCS3_URL . 'assets/js/bgcs-checkout.js',
			$checkout_deps,
			BGCS3_VERSION,
			true
		);

		wp_localize_script( 'bgcs-checkout', 'bgcsCheckout', self::frontend_data() );
	}

	/**
	 * Render informational states inside the AJAX-refreshed Classic order review.
	 * These cards deliberately contain no radio input and are not WC rates.
	 */
	public function render_availability_cards() {
		$rows = ( new Availability_Store() )->current_public();
		$rows = (array) apply_filters( 'bgcs3_shipping_availability', $rows );
		$html = Availability_Presenter::cards_html( $rows );
		if ( '' === $html ) {
			return;
		}
		echo '<tr class="bgcs-availability-row"><td colspan="2"><div data-bgcs-availability-root>' . $html . '</div></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated exclusively by the escaping presenter.
	}

	/**
	 * Print the selector mount point after a bgcs shipping rate.
	 *
	 * @param \WC_Shipping_Rate $method Rate.
	 * @param int               $index  Index.
	 */
	/**
	 * Hidden data marker printed after each bgcs rate (inside order_review).
	 * Carries courier + allowed delivery types; the JS reads it for the chosen
	 * rate and drives the stable selector host.
	 *
	 * @param \WC_Shipping_Rate $method Rate.
	 * @param int               $index  Index.
	 */
	public function render_rate_meta( $method, $index ) {
		if ( 0 !== strpos( $method->get_id(), 'bgcs3_' ) ) {
			return;
		}

		$meta           = $method->get_meta_data();
		$courier        = isset( $meta['_bgcs3_courier'] ) ? $meta['_bgcs3_courier'] : '';
		$delivery_types = isset( $meta['_bgcs3_delivery_types'] ) ? $meta['_bgcs3_delivery_types'] : '';
		$validated      = ! empty( $meta['_bgcs3_validated'] );
		$is_free        = ! empty( $meta['_bgcs3_free_shipping'] );
		$price_state    = isset( $meta['_bgcs3_price_state'] ) ? sanitize_key( (string) $meta['_bgcs3_price_state'] ) : '';
		$cost           = is_callable( array( $method, 'get_cost' ) ) ? (float) $method->get_cost() : 0.0;
		$warnings       = isset( $meta['_bgcs3_warnings'] ) && is_array( $meta['_bgcs3_warnings'] ) ? $meta['_bgcs3_warnings'] : array();

		if ( '' === $courier ) {
			return;
		}

		if ( ! in_array( $price_state, array( 'pending', 'calculated', 'free', 'unavailable' ), true ) ) {
			$price_state = ! $validated ? 'pending' : ( $is_free ? 'free' : 'calculated' );
		}

		printf(
			'<span class="bgcs-rate-meta" style="display:none" data-bgcs-rate="1" data-rate-id="%s" data-courier="%s" data-delivery-types="%s" data-validated="%s" data-price-state="%s" data-cost="%s"></span>',
			esc_attr( $method->get_id() ),
			esc_attr( $courier ),
			esc_attr( $delivery_types ),
			$validated ? '1' : '0',
			esc_attr( $price_state ),
			esc_attr( wc_format_decimal( $cost ) )
		);

		if ( ! empty( $warnings ) ) {
			echo '<div class="bgcs-rate-warnings" role="status">';
			foreach ( array_unique( array_filter( array_map( 'strval', $warnings ) ) ) as $warning ) {
				echo '<div class="bgcs-rate-warning">' . esc_html( $warning ) . '</div>';
			}
			echo '</div>';
		}

		// Merchant-configured short description under the method.
		$description = (string) Module_Settings::get( $courier, 'method_description' );
		if ( '' !== $description ) {
			echo '<div class="bgcs-rate-desc">' . esc_html( $description ) . '</div>';
		}
	}

	/**
	 * Server-rendered selector (real form fields) in the stable checkout form:
	 * radio "deliver to" + searchable city select + office select + address
	 * inputs. The JS wires the cascade; courier add-ons may add their own slots.
	 */
	public function render_host() {
		$types = array(
			'office'  => array( '🏢', __( 'To office', 'bg-commerce-suite' ) ),
			'locker'  => array( '📦', __( 'To locker', 'bg-commerce-suite' ) ),
			'address' => array( '🏠', __( 'To address', 'bg-commerce-suite' ) ),
		);

		/**
		 * Extra CSS classes on the selector host (e.g. a design add-on's
		 * button-style preset class).
		 *
		 * @param string[] $classes Class list.
		 */
		$classes = (array) apply_filters( 'bgcs3_selector_classes', array( 'bgcs-selector' ) );
		?>
		<div id="bgcs-selector-host" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="display:none" data-courier="">
			<div class="bgcs-types" role="radiogroup">
				<?php
				foreach ( $types as $type => $info ) :
					/**
					 * Icon markup for a delivery-type button. A design add-on may
					 * replace the default emoji with an SVG or an <img>.
					 *
					 * @param string $icon_html Icon markup (span.bgcs-type__icon).
					 * @param string $type      office | locker | address.
					 */
					$icon_html = apply_filters(
						'bgcs3_selector_type_icon',
						'<span class="bgcs-type__icon" aria-hidden="true">' . esc_html( $info[0] ) . '</span>',
						$type
					);
					?>
					<label class="bgcs-type" data-type="<?php echo esc_attr( $type ); ?>" for="bgcs3_dt_<?php echo esc_attr( $type ); ?>" style="display:none">
						<input type="radio" name="bgcs3_delivery_type" id="bgcs3_dt_<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $type ); ?>" />
						<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above / filtered markup. ?>
						<span class="bgcs-type__label"><?php echo esc_html( $info[1] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<?php
			/**
			 * Extra markup slots inside the selector. Courier add-ons print their
			 * own containers here (e.g. a locker map widget).
			 */
			do_action( 'bgcs3_selector_slots' );
			?>

			<!--
			  City search (plain, robust — no select2). For office/locker it filters
			  the map + office list to the chosen city; for address it is the
			  delivery city.
			-->
			<div class="bgcs-field bgcs-city-row form-row" style="display:none">
				<label for="bgcs3_city_search"><?php esc_html_e( 'City', 'bg-commerce-suite' ); ?></label>
				<input
					type="text"
					id="bgcs3_city_search"
					class="bgcs-city-search"
					autocomplete="off"
					role="combobox"
					aria-autocomplete="list"
					aria-expanded="false"
					aria-controls="bgcs3_city_suggestions"
					placeholder="<?php esc_attr_e( 'Search city…', 'bg-commerce-suite' ); ?>"
				/>
				<ul class="bgcs-office-suggestions bgcs-city-suggestions" id="bgcs3_city_suggestions" role="listbox" hidden></ul>
				<input type="hidden" name="bgcs3_city" id="bgcs3_city" value="" />
			</div>

			<div id="bgcs3_delivery_map" class="bgcs-map" style="display:none"></div>

			<!-- Office / locker picker — searchable list, scoped to the chosen city. -->
			<div class="bgcs-field bgcs-office-search-row form-row" style="display:none">
				<label for="bgcs3_office_search" class="bgcs-office-label"><?php esc_html_e( 'Select an office', 'bg-commerce-suite' ); ?></label>
				<input
					type="text"
					id="bgcs3_office_search"
					class="bgcs-office-search"
					autocomplete="off"
					role="combobox"
					aria-autocomplete="list"
					aria-expanded="false"
					aria-controls="bgcs3_office_suggestions"
					placeholder="<?php esc_attr_e( 'Search by name or postcode…', 'bg-commerce-suite' ); ?>"
				/>
				<ul class="bgcs-office-suggestions" id="bgcs3_office_suggestions" role="listbox" hidden></ul>
				<p class="bgcs-office-empty" hidden><?php esc_html_e( 'No results', 'bg-commerce-suite' ); ?></p>
				<input type="hidden" name="bgcs3_office" id="bgcs3_office" value="" />
			</div>

			<!-- Address delivery: street/number (city comes from the field above). -->
			<div class="bgcs-address-rows" style="display:none">
				<p class="bgcs-field bgcs-street-row form-row">
					<label for="bgcs3_address_street"><?php esc_html_e( 'Street', 'bg-commerce-suite' ); ?></label>
					<input
						type="text"
						name="bgcs3_address_street"
						id="bgcs3_address_street"
						autocomplete="off"
						role="combobox"
						aria-autocomplete="list"
						aria-expanded="false"
						aria-controls="bgcs3_street_suggestions"
					/>
					<ul class="bgcs-office-suggestions bgcs-street-suggestions" id="bgcs3_street_suggestions" role="listbox" hidden></ul>
				</p>
				<p class="bgcs-field form-row">
					<label for="bgcs3_address_num"><?php esc_html_e( 'Number', 'bg-commerce-suite' ); ?></label>
					<input type="text" name="bgcs3_address_num" id="bgcs3_address_num" />
				</p>
				<p class="bgcs-field form-row">
					<label for="bgcs3_address_note"><?php esc_html_e( 'Additional (optional)', 'bg-commerce-suite' ); ?></label>
					<input type="text" name="bgcs3_address_note" id="bgcs3_address_note" />
				</p>
			</div>

			<p class="bgcs-selected" style="display:none"></p>
			<p class="bgcs-selector-status" role="status" aria-live="polite" hidden></p>

			<input type="hidden" name="bgcs3_selection" id="bgcs3_selection" value="" />
		</div>
		<?php
	}

	/**
	 * @param object $integration_registry Blocks IntegrationRegistry.
	 */
	public function register_blocks_integration( $integration_registry ) {
		if ( method_exists( $integration_registry, 'register' ) ) {
			$integration_registry->register( new Blocks_Integration( 'checkout' ) );
		}
	}

	/**
	 * Register the lightweight BGCS semantic shipping-state bridge for Cart Block.
	 * The checkout selector bundle itself stays checkout-only.
	 *
	 * @param object $integration_registry Blocks IntegrationRegistry.
	 */
	public function register_cart_blocks_integration( $integration_registry ) {
		if ( method_exists( $integration_registry, 'register' ) ) {
			$integration_registry->register( new Blocks_Integration( 'cart' ) );
		}
	}

	/**
	 * Register the official Store API extension cart-update callback.
	 *
	 * WooCommerce documents this registration on `woocommerce_blocks_loaded`.
	 * Older compatible WooCommerce builds that do not expose the helper continue
	 * to use BGCS's nonce-protected REST selection endpoint from blocks.js.
	 */
	public function register_store_api_update_callback() {
		static $registered = false;
		if ( $registered || ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'bg-commerce-suite',
				'callback'  => array( $this, 'store_api_update_selection' ),
			)
		);
		$registered = true;
	}

	/**
	 * Store API extension callback used by the Checkout block selector.
	 *
	 * @param array<string,mixed> $data Selection payload.
	 */
	public function store_api_update_selection( $data ) {
		$selection = Selection::from_array( is_array( $data ) ? $data : array() );

		/** @var \BgCommerce3\Module\Module_Registry $registry */
		$registry = $this->container['modules'];
		$module   = $registry->get( $selection->courier );
		if ( ! $module instanceof Courier_Interface || ! $module->is_enabled() ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'Unknown or inactive courier.', 'bg-commerce-suite' ), 'error' );
			}
			return;
		}

		$allowed_types = (array) $module->delivery_types();
		if ( '' === $selection->delivery_type || ! in_array( $selection->delivery_type, $allowed_types, true ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'This delivery type is not available for the selected courier.', 'bg-commerce-suite' ), 'error' );
			}
			return;
		}

		// Store the new state before validation, including incomplete drafts. This
		// immediately retires the previous office/address/rate when the shopper
		// changes courier, delivery type or city.
		$store = new Selection_Store();
		if ( ! $store->set( $selection ) ) {
			return;
		}

		if ( $selection->is_complete() ) {
			$valid = $module->validate( $selection );
			if ( is_wp_error( $valid ) && function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $valid->get_error_message(), 'error' );
			}
		}

		// Selection is not part of WooCommerce's native shipping-package hash, so
		// explicitly invalidate cached package rates before the Store API returns
		// the refreshed cart to the Checkout block.
		\BgCommerce3\Shipping\Hooks::sync_and_recalc();
	}

	/**
	 * In clean checkout, the BGCS city selector replaces the visible native
	 * WooCommerce city fields. Keep the native data contract intact by copying
	 * the selected BGCS city into billing_city and shipping_city before WC runs
	 * its checkout-field validation.
	 *
	 * This is intentionally limited to classic checkout requests that currently
	 * use a BGCS shipping rate. A stale BGCS selection must never overwrite the
	 * customer's city when a non-BGCS shipping method is selected.
	 *
	 * @param array<string,mixed> $data Sanitised WooCommerce posted data.
	 * @return array<string,mixed>
	 */
	public function sync_clean_checkout_city( $data ) {
		if ( 'yes' !== bgcs3_get_option( 'checkout', 'hide_fields', 'no' ) ) {
			return $data;
		}

		if ( ! $this->request_uses_bgcs_shipping() ) {
			return $data;
		}

		$selection = $this->posted_selection();
		if ( ! $selection || empty( $selection->city['name'] ) ) {
			return $data;
		}

		$city = sanitize_text_field( (string) $selection->city['name'] );
		if ( '' === $city ) {
			return $data;
		}

		$data['billing_city']  = $city;
		$data['shipping_city'] = $city;

		// The postcode is already hidden in clean checkout. When the courier city
		// provides one, keep WooCommerce's native address context aligned as well.
		if ( ! empty( $selection->city['post_code'] ) ) {
			$postcode = sanitize_text_field( (string) $selection->city['post_code'] );
			if ( '' !== $postcode ) {
				$data['billing_postcode']  = $postcode;
				$data['shipping_postcode'] = $postcode;
			}
		}

		/**
		 * Filters native WooCommerce city/postcode values derived from the BGCS
		 * selection. Invoice/tax add-ons can opt out or adjust the values without
		 * coupling Core to a specific add-on.
		 *
		 * @param array<string,mixed>              $data      Checkout posted data.
		 * @param \BgCommerce3\Support\Selection $selection BGCS selection.
		 */
		return (array) apply_filters( 'bgcs3_clean_checkout_posted_address', $data, $selection );
	}

	/**
	 * Whether the current classic checkout request has a BGCS shipping rate.
	 * Prefer the posted shipping_method (the request being validated), then fall
	 * back to the WooCommerce session for compatibility with customised themes.
	 *
	 * @return bool
	 */
	private function request_uses_bgcs_shipping() {
		$methods = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC checkout nonce is verified by WooCommerce upstream.
		if ( isset( $_POST['shipping_method'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce owns checkout nonces upstream.
			$posted  = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['shipping_method'] ) );
			$methods = is_array( $posted ) ? $posted : array( $posted );
		}

		if ( empty( $methods ) && function_exists( 'WC' ) && WC()->session ) {
			$methods = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		}

		if ( empty( $methods ) ) {
			return false;
		}

		foreach ( $methods as $rate_id ) {
			if ( 0 !== strpos( (string) $rate_id, 'bgcs3_' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Save the session Selection onto the order if a bgcs method is used.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function persist( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! $this->order_uses_bgcs( $order ) ) {
			return;
		}

		// The accepted session value is canonical. Posted JSON is only a legacy
		// fallback when the checkout update hook could not initialize a session.
		$selection = ( new Selection_Store() )->get();
		if ( ! $selection ) {
			$selection = $this->posted_selection();
		}

		if ( ! $selection || ! $selection->is_complete() ) {
			$this->throw_persistence_error( __( 'Please select an office/locker or delivery address.', 'bg-commerce-suite' ) );
		}

		$order_error = $this->order_shipping_selection_error( $order, $selection );
		if ( '' !== $order_error ) {
			$this->throw_persistence_error( $order_error );
		}

		$snapshots = $order->get_meta( Order_Persistence::QUOTE_SNAPSHOTS_META );
		if ( is_array( $snapshots ) && ! empty( $snapshots ) && ! Order_Persistence::quote_snapshots_match( $order, $selection ) ) {
			$this->throw_persistence_error( __( 'The courier could not confirm a shipping price for the current selection. Please review the delivery choice and try again.', 'bg-commerce-suite' ) );
		}

		$order->update_meta_data( self::META_KEY, $selection->to_array() );

		self::save_readable_meta( $order, $selection );
		$this->fill_address( $order, $selection );
	}

	/**
	 * Append the courier + waybill number + tracking link to order emails once a
	 * waybill exists on the order.
	 *
	 * @param mixed $order         WC_Order.
	 * @param bool  $sent_to_admin Admin email?
	 * @param bool  $plain_text    Plain-text email?
	 * @param mixed $email         WC_Email.
	 */
	public function email_tracking( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$label = $order->get_meta( '_bgcs3_label' );
		if ( empty( $label['number'] ) ) {
			return;
		}

		$number    = (string) $label['number'];
		$selection = $order->get_meta( '_bgcs3_selection' );
		$courier_id = is_array( $selection ) && ! empty( $selection['courier'] ) ? $selection['courier'] : '';
		$module     = isset( $this->container['modules'] ) ? $this->container['modules']->get( $courier_id ) : null;
		$courier    = ( $module ) ? $module->name() : $courier_id;
		$track_url  = ( $module && method_exists( $module, 'tracking_url' ) ) ? $module->tracking_url( $number ) : '';

		if ( $plain_text ) {
			/* translators: 1: courier name, 2: shipment label number. */
			echo "\n" . esc_html( sprintf( __( 'Shipping with %1$s — shipment label: %2$s', 'bg-commerce-suite' ), $courier, $number ) ) . "\n";
			if ( $track_url ) {
				echo esc_html__( 'Tracking: ', 'bg-commerce-suite' ) . esc_url( $track_url ) . "\n";
			}
			return;
		}

		echo '<div style="margin:16px 0;padding:14px 16px;border:1px solid #e3e3f3;border-radius:10px;background:#eef2ff">';
		echo '<p style="margin:0 0 4px;color:#1e1b4b"><strong>' . esc_html__( 'Shipping', 'bg-commerce-suite' ) . ':</strong> ' . esc_html( $courier ) . '</p>';
		echo '<p style="margin:0;color:#1e1b4b"><strong>' . esc_html__( 'Shipment label', 'bg-commerce-suite' ) . ':</strong> ' . esc_html( $number );
		if ( $track_url ) {
			echo ' — <a href="' . esc_url( $track_url ) . '" target="_blank" rel="noopener" style="color:#4f46e5;font-weight:600;text-decoration:none">' . esc_html__( 'Track shipment', 'bg-commerce-suite' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Print the chosen delivery in every order email: which courier, which of the
	 * three options, and the pickup point or street address.
	 *
	 * WooCommerce prints the shipping block, but that is an address with no
	 * indication of the courier or of whether it is a door delivery, an office or
	 * a parcel machine — reading „Люба Величкова 7“ does not tell the merchant
	 * whether someone is driving there. This runs at order time and needs no
	 * waybill, unlike {@see email_tracking()} directly below it.
	 *
	 * Courier-agnostic on purpose: everything comes from `_bgcs3_selection`, so all
	 * four couriers are covered by this one block and a fifth needs no work.
	 *
	 * @param mixed $order         WC_Order.
	 * @param bool  $sent_to_admin Admin email?
	 * @param bool  $plain_text    Plain-text email?
	 * @param mixed $email         WC_Email.
	 */
	public function email_delivery( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$selection = $order->get_meta( self::META_KEY );
		if ( ! is_array( $selection ) || empty( $selection['courier'] ) ) {
			return;
		}

		$rows = $this->delivery_rows( $order, $selection );
		if ( empty( $rows ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Selected delivery', 'bg-commerce-suite' ) . "\n";
			foreach ( $rows as $label => $value ) {
				echo esc_html( $label . ': ' . $value ) . "\n";
			}
			return;
		}

		echo '<div style="margin:16px 0;padding:14px 16px;border:1px solid #e3e3f3;border-radius:10px;background:#f8fafc">';
		echo '<p style="margin:0 0 6px;color:#1e1b4b;font-size:15px"><strong>' . esc_html__( 'Selected delivery', 'bg-commerce-suite' ) . '</strong></p>';
		foreach ( $rows as $label => $value ) {
			echo '<p style="margin:0 0 3px;color:#1e1b4b"><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The label => value pairs describing the chosen delivery.
	 *
	 * Split out from the markup so the decisions — which courier name, which
	 * wording per delivery type, which address line — are testable without
	 * rendering an email.
	 *
	 * @param \WC_Order           $order     Order.
	 * @param array<string,mixed> $selection Stored selection.
	 * @return array<string,string>
	 */
	public function delivery_rows( \WC_Order $order, array $selection ) {
		$type   = isset( $selection['delivery_type'] ) ? (string) $selection['delivery_type'] : '';
		$office = isset( $selection['office'] ) && is_array( $selection['office'] ) ? $selection['office'] : array();
		$city   = isset( $selection['city'] ) && is_array( $selection['city'] ) ? $selection['city'] : array();

		$types = array(
			'office'  => __( 'To courier office', 'bg-commerce-suite' ),
			'locker'  => __( 'To locker', 'bg-commerce-suite' ),
			'address' => __( 'To address', 'bg-commerce-suite' ),
		);

		$rows = array(
			__( 'Courier', 'bg-commerce-suite' ) => $this->courier_name( (string) $selection['courier'] ),
		);

		if ( isset( $types[ $type ] ) ) {
			$rows[ __( 'Delivery method', 'bg-commerce-suite' ) ] = $types[ $type ];
		}

		if ( 'address' === $type ) {
			// The order's own shipping line is authoritative here: the customer may
			// have corrected it after choosing, and that correction is what ships.
			$line = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
			if ( '' === $line && ! empty( $selection['address'] ) && is_array( $selection['address'] ) ) {
				$line = trim(
					( isset( $selection['address']['street'] ) ? $selection['address']['street'] : '' ) . ' ' .
					( isset( $selection['address']['num'] ) ? $selection['address']['num'] : '' )
				);
			}
			if ( '' !== $line ) {
				$rows[ __( 'Address', 'bg-commerce-suite' ) ] = $line;
			}
		} elseif ( ! empty( $office['text'] ) ) {
			$rows[ 'locker' === $type ? __( 'Locker', 'bg-commerce-suite' ) : __( 'Office', 'bg-commerce-suite' ) ] = (string) $office['text'];
		}

		// The pickup-point label usually carries its own street but rarely the
		// town, and the town is what tells the merchant where the parcel goes.
		$town = ! empty( $city['name'] ) ? (string) $city['name'] : (string) $order->get_shipping_city();
		$zip  = ! empty( $city['post_code'] ) ? (string) $city['post_code'] : (string) $order->get_shipping_postcode();
		$town = trim( $town . ( '' !== $zip ? ' ' . $zip : '' ) );

		if ( '' !== $town ) {
			$rows[ __( 'City / locality', 'bg-commerce-suite' ) ] = $town;
		}

		$delivery_estimate = Delivery_Estimate::for_order( $order );
		$estimate = Delivery_Estimate::visible( $delivery_estimate, 'email' ) ? Delivery_Estimate::format( $delivery_estimate ) : '';
		if ( '' !== $estimate ) {
			$rows[ __( 'Expected delivery', 'bg-commerce-suite' ) ] = $estimate;
		}

		/**
		 * Filters the delivery rows printed in order emails.
		 *
		 * @param array<string,string> $rows      Label => value.
		 * @param \WC_Order            $order     Order.
		 * @param array<string,mixed>  $selection Stored selection.
		 */
		return (array) apply_filters( 'bgcs3_email_delivery_rows', $rows, $order, $selection );
	}

	/**
	 * Display name of a courier module, falling back to its id when the add-on is
	 * inactive — an old order must still say who delivered it.
	 *
	 * @param string $courier_id Module id.
	 * @return string
	 */
	private function courier_name( $courier_id ) {
		$module = isset( $this->container['modules'] ) ? $this->container['modules']->get( $courier_id ) : null;

		return ( $module ) ? $module->name() : $courier_id;
	}

	/**
	 * Remove our internal rate markers from the persisted shipping line item, so
	 * the order doesn't show "courier / office,locker,address / validated". The
	 * chosen delivery is recorded in _bgcs3_selection + the readable bgcs3_* fields.
	 *
	 * @param mixed $item        WC_Order_Item_Shipping.
	 * @param mixed $package_key Package key.
	 * @param mixed $package     Package.
	 * @param mixed $order       Order.
	 */
	public function clean_shipping_item_meta( $item, $package_key, $package, $order ) {
		if ( ! $item instanceof \WC_Order_Item_Shipping ) {
			return;
		}

		// Capture the exact package/rate/selection contract before cleaning the line.
		if ( $order instanceof \WC_Order ) {
			Order_Persistence::capture_quote_snapshot( $item, $package_key, $order );
		}

		// Transfer legacy single-package pricing audit fields before cleaning line item.
		if ( $order instanceof \WC_Order ) {
			foreach ( array( '_bgcs3_payment_context', '_bgcs3_pricing_mode', '_bgcs3_pricing_source', '_bgcs3_base_cost', '_bgcs3_courier_service_payer', '_bgcs3_surcharges', '_bgcs3_pmt_amount', '_bgcs3_pmt_base', '_bgcs3_pmt_source', '_bgcs3_pmt_payer', '_bgcs3_pricing_weight', '_bgcs3_pricing_weight_threshold', '_bgcs3_contract_currency', '_bgcs3_pricing_rule', '_bgcs3_price_breakdown', '_bgcs3_delivery_estimate' ) as $key ) {
				$val = $item->get_meta( $key );
				if ( is_array( $val ) ) {
					if ( ! empty( $val ) ) {
						$order->update_meta_data( $key, $val );
					}
				} elseif ( '' !== (string) $val ) {
					$order->update_meta_data( $key, is_numeric( $val ) ? (float) $val : $val );
				}
			}
		}

		foreach ( array( '_bgcs3_courier', '_bgcs3_delivery_types', '_bgcs3_validated', '_bgcs3_selection', '_bgcs3_free_shipping', '_bgcs3_errors', '_bgcs3_warnings', '_bgcs3_payment_context', '_bgcs3_pricing_mode', '_bgcs3_pricing_source', '_bgcs3_base_cost', '_bgcs3_courier_service_payer', '_bgcs3_surcharges', '_bgcs3_pmt_amount', '_bgcs3_pmt_base', '_bgcs3_pmt_source', '_bgcs3_pmt_payer', '_bgcs3_pricing_weight', '_bgcs3_pricing_weight_threshold', '_bgcs3_contract_currency', '_bgcs3_pricing_rule', '_bgcs3_price_breakdown', '_bgcs3_delivery_estimate', 'courier', 'delivery_types', 'price_state', 'validated', 'free_shipping', 'warnings', 'delivery_estimate' ) as $key ) {
			$item->delete_meta_data( $key );
		}
	}

	/**
	 * Store human-readable order fields in addition
	 * to the internal `_bgcs3_selection` blob — visible in the order, emails and
	 * exports. No underscore prefix → shown in the Custom Fields box.
	 *
	 * @param \WC_Order                     $order     Order.
	 * @param \BgCommerce3\Support\Selection $selection Selection.
	 */
	public static function save_readable_meta( \WC_Order $order, $selection ) {
		$labels = array(
			'office'  => __( 'office', 'bg-commerce-suite' ),
			'locker'  => __( 'locker', 'bg-commerce-suite' ),
			'address' => __( 'address', 'bg-commerce-suite' ),
		);

		$fields = array(
			'bgcs3_courier'       => $selection->courier,
			'bgcs3_delivery_type' => isset( $labels[ $selection->delivery_type ] ) ? $labels[ $selection->delivery_type ] : $selection->delivery_type,
		);

		if ( ! empty( $selection->city['name'] ) ) {
			$fields['bgcs3_city'] = $selection->city['name'];
		}
		if ( ! empty( $selection->city['post_code'] ) ) {
			$fields['bgcs3_post_code'] = $selection->city['post_code'];
		}
		if ( ! empty( $selection->office['id'] ) ) {
			$fields['bgcs3_office_id'] = $selection->office['id'];
			$fields['bgcs3_office']    = isset( $selection->office['text'] ) ? $selection->office['text'] : '';
		} elseif ( ! empty( $selection->address ) ) {
			$fields['bgcs3_address'] = trim( ( isset( $selection->address['street'] ) ? $selection->address['street'] : '' ) . ' ' . ( isset( $selection->address['num'] ) ? $selection->address['num'] : '' ) );
		}

		// Courier-specific readable fields (e.g. Speedy site/office ids, service,
		// price) — saved as their own custom fields for exports/label software.
		if ( function_exists( 'bgcs3' ) ) {
			$container = bgcs3()->container();
			$module    = isset( $container['modules'] ) ? $container['modules']->get( $selection->courier ) : null;
			if ( $module && method_exists( $module, 'order_meta_fields' ) ) {
				$fields = array_merge( $fields, (array) $module->order_meta_fields( $order, $selection ) );
			}
		}

		Order_Persistence::replace_readable_meta( $order, $fields );
	}

	/**
	 * Abort order creation instead of persisting an incomplete or stale snapshot.
	 *
	 * @param string $message Customer-safe validation message.
	 * @return void
	 * @throws \Exception When order persistence invariants are not satisfied.
	 */
	private function throw_persistence_error( $message ) {
		if ( class_exists( '\\WC_Data_Exception' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not direct output.
			throw new \WC_Data_Exception( 'bgcs3_order_persistence', $message );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not direct output.
		throw new \Exception( $message );
	}

	/**
	 * Read the selection from the posted hidden field (classic checkout).
	 * WooCommerce verifies the checkout nonce before order creation.
	 *
	 * @return \BgCommerce3\Support\Selection|null
	 */
	private function posted_selection() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC checkout nonce already verified upstream.
		if ( empty( $_POST['bgcs3_selection'] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw  = sanitize_textarea_field( wp_unslash( $_POST['bgcs3_selection'] ) );
		$data = json_decode( $raw, true );

		return is_array( $data ) ? \BgCommerce3\Support\Selection::from_array( $data ) : null;
	}

	/**
	 * Auto-fill the order's billing/shipping address from the selection, so the
	 * order has a usable address even when the standard fields are hidden.
	 *
	 * @param \WC_Order                       $order     Order.
	 * @param \BgCommerce3\Support\Selection   $selection Selection.
	 */
	private function fill_address( \WC_Order $order, $selection ) {
		$city         = ! empty( $selection->city['name'] ) ? $selection->city['name'] : '';
		$post_code    = ! empty( $selection->city['post_code'] ) ? $selection->city['post_code'] : '';
		$is_address   = 'address' === $selection->delivery_type;

		if ( $is_address ) {
			$line1 = trim(
				( isset( $selection->address['street'] ) ? $selection->address['street'] : '' ) . ' ' .
				( isset( $selection->address['num'] ) ? $selection->address['num'] : '' )
			);
		} else {
			// Office/locker: a human-readable pickup-point label, not a street
			// address — valid for the SHIPPING block only (below), never billing.
			$line1 = ! empty( $selection->office['text'] ) ? $selection->office['text'] : '';
		}

		$order->set_billing_country( 'BG' );
		$order->set_shipping_country( 'BG' );

		// Shipping = the delivery target from the selection (authoritative). Set
		// empty values too: Store API can persist the same draft more than once,
		// and absence in the new selection must clear the previous destination.
		$order->set_shipping_city( $city );
		$order->set_shipping_postcode( $post_code );
		$order->set_shipping_address_1( $line1 );
		if ( ! $is_address && method_exists( $order, 'set_shipping_address_2' ) ) {
			$order->set_shipping_address_2( '' );
		}

		// Billing precedence: the customer's own account profile first, the
		// delivery selection only as a last resort (and only a REAL street
		// address — an office/locker pickup-point label is never a legitimate
		// billing address) — and never when an add-on (e.g. Наредба Н-18)
		// reports this request wants its own invoice fields to be authoritative
		// instead (a VAT invoice's billing data must reflect exactly what the
		// customer typed for it, never be silently substituted). Core
		// deliberately doesn't know which add-on that is — filterable, so the
		// Core/add-on boundary (rules.md §2) stays intact.
		if ( ! apply_filters( 'bgcs3_autofill_billing_address', true, $order, $selection ) ) {
			return;
		}

		$fill = self::resolve_billing_fill(
			array(
				'city'      => (string) $order->get_billing_city(),
				'postcode'  => (string) $order->get_billing_postcode(),
				'address_1' => (string) $order->get_billing_address_1(),
			),
			$this->billing_from_customer_profile(),
			$is_address ? array(
				'city'      => $city,
				'postcode'  => $post_code,
				'address_1' => $line1,
			) : array()
		);

		if ( '' !== $fill['city'] ) {
			$order->set_billing_city( $fill['city'] );
		}
		if ( '' !== $fill['postcode'] ) {
			$order->set_billing_postcode( $fill['postcode'] );
		}
		if ( '' !== $fill['address_1'] ) {
			$order->set_billing_address_1( $fill['address_1'] );
		}
	}

	/**
	 * Pure billing-field precedence resolver (no WP/WC dependency, unit-testable
	 * directly): existing values always win, then the customer's saved profile,
	 * then the delivery-selection fallback (empty when not applicable — e.g. an
	 * office/locker delivery, which must never reach billing at all).
	 *
	 * @param array{city:string,postcode:string,address_1:string} $current  Current order billing values.
	 * @param array{city:string,postcode:string,address_1:string} $profile  Customer's saved profile values.
	 * @param array{city:string,postcode:string,address_1:string} $fallback Delivery-selection fallback (empty array = none).
	 * @return array{city:string,postcode:string,address_1:string} Values to apply; '' means "leave unchanged".
	 */
	public static function resolve_billing_fill( array $current, array $profile, array $fallback ) {
		$result = array(
			'city'      => '',
			'postcode'  => '',
			'address_1' => '',
		);

		foreach ( array( 'city', 'postcode', 'address_1' ) as $field ) {
			if ( '' !== (string) ( isset( $current[ $field ] ) ? $current[ $field ] : '' ) ) {
				continue; // Never overwrite what's already there.
			}
			if ( ! empty( $profile[ $field ] ) ) {
				$result[ $field ] = $profile[ $field ];
				continue;
			}
			if ( ! empty( $fallback[ $field ] ) ) {
				$result[ $field ] = $fallback[ $field ];
			}
		}

		return $result;
	}

	/**
	 * The logged-in customer's own saved account billing address, if any.
	 * Guests (and customers who never saved one) get all-empty strings.
	 *
	 * @return array{city:string,postcode:string,address_1:string}
	 */
	private function billing_from_customer_profile() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return array(
				'city'      => '',
				'postcode'  => '',
				'address_1' => '',
			);
		}

		return array(
			'city'      => (string) get_user_meta( $user_id, 'billing_city', true ),
			'postcode'  => (string) get_user_meta( $user_id, 'billing_postcode', true ),
			'address_1' => (string) get_user_meta( $user_id, 'billing_address_1', true ),
		);
	}

	/**
	 * Classic checkout validation.
	 *
	 * @param array     $data   Posted data.
	 * @param \WP_Error $errors Errors.
	 */
	public function validate( $data, $errors ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$uses   = false;

		foreach ( $chosen as $rate_id ) {
			if ( 0 === strpos( (string) $rate_id, 'bgcs3_' ) ) {
				$uses = true;
				break;
			}
		}

		if ( ! $uses ) {
			return;
		}

		$selection = ( new Selection_Store() )->get();
		if ( ! $selection ) {
			$selection = $this->posted_selection();
		}

		if ( ! $selection || ! $selection->is_complete() ) {
			$errors->add( 'bgcs3_selection', __( 'Please select an office/locker or delivery address.', 'bg-commerce-suite' ) );
			return;
		}

		// A complete selection is not enough: the chosen rate must have survived
		// WooCommerce's latest shipping calculation with a confirmed price. This
		// closes the stale-rate/zero-price path when a courier API quote fails.
		$rate_error = $this->chosen_bgcs_rate_validation_error( $chosen, $selection );
		if ( '' !== $rate_error ) {
			$errors->add( 'bgcs3_rate_validation', $rate_error );
		}
	}


	/**
	 * Store API / Blocks checkout validation.
	 *
	 * @param \WP_Error $errors Validation errors.
	 * @param mixed     $cart   WC_Cart (unused; the shipping/session state is authoritative).
	 */
	public function validate_store_api_cart( $errors, $cart = null ) {
		unset( $cart );
		if ( ! $errors instanceof \WP_Error || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		if ( ! $this->chosen_uses_bgcs( $chosen ) ) {
			return;
		}

		$selection = ( new Selection_Store() )->get();
		if ( ! $selection || ! $selection->is_complete() ) {
			$errors->add( 'bgcs3_selection', __( 'Please select an office/locker or delivery address.', 'bg-commerce-suite' ) );
			return;
		}

		$rate_error = $this->chosen_bgcs_rate_validation_error( $chosen, $selection );
		if ( '' !== $rate_error ) {
			$errors->add( 'bgcs3_rate_validation', $rate_error );
		}
	}

	/**
	 * Final pre-payment validation shared by Classic Checkout and Store API.
	 *
	 * @param \WC_Order $order  Draft/order being paid.
	 * @param \WP_Error $errors Validation errors.
	 */
	public function validate_order_before_payment( $order, $errors ) {
		if ( ! $order instanceof \WC_Order || ! $errors instanceof \WP_Error || ! $this->order_uses_bgcs( $order ) ) {
			return;
		}

		$selection_data = $order->get_meta( self::META_KEY );
		$selection      = is_array( $selection_data ) ? Selection::from_array( $selection_data ) : null;
		if ( ! $selection ) {
			$selection = ( new Selection_Store() )->get();
		}

		if ( ! $selection || ! $selection->is_complete() ) {
			$errors->add( 'bgcs3_selection', __( 'Please select an office/locker or delivery address.', 'bg-commerce-suite' ) );
			return;
		}

		$order_error = $this->order_shipping_selection_error( $order, $selection );
		if ( '' !== $order_error ) {
			$errors->add( 'bgcs3_order_shipping_mismatch', $order_error );
			return;
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
			if ( ! $this->chosen_uses_bgcs( $chosen ) ) {
				$errors->add( 'bgcs3_rate_validation', __( 'The selected courier is not synchronized with the shipping method. Please review the delivery choice and try again.', 'bg-commerce-suite' ) );
				return;
			}

			$rate_error = $this->chosen_bgcs_rate_validation_error( $chosen, $selection );
			if ( '' !== $rate_error ) {
				$errors->add( 'bgcs3_rate_validation', $rate_error );
			}
		}
	}

	/**
	 * @param string[] $chosen Chosen method ids.
	 * @return bool
	 */
	private function chosen_uses_bgcs( array $chosen ) {
		foreach ( $chosen as $rate_id ) {
			if ( 0 === strpos( (string) $rate_id, 'bgcs3_' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate the chosen BGCS rates against the rates produced by WooCommerce's
	 * current shipping packages.
	 *
	 * @param string[]      $chosen    Chosen shipping rate ids.
	 * @param Selection|null $selection Canonical selection when available.
	 * @return string Empty string when all BGCS rates are confirmed.
	 */
	private function chosen_bgcs_rate_validation_error( array $chosen, Selection $selection = null ) {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() || ! method_exists( WC()->shipping(), 'get_packages' ) ) {
			return '';
		}

		$packages = WC()->shipping()->get_packages();
		foreach ( $chosen as $package_index => $rate_id ) {
			$rate_id = (string) $rate_id;
			if ( 0 !== strpos( $rate_id, 'bgcs3_' ) ) {
				continue;
			}

			$rates = isset( $packages[ $package_index ]['rates'] ) && is_array( $packages[ $package_index ]['rates'] )
				? $packages[ $package_index ]['rates']
				: array();
			if ( ! isset( $rates[ $rate_id ] ) || ! $rates[ $rate_id ] instanceof \WC_Shipping_Rate ) {
				return __( 'The courier could not confirm a shipping price for the current selection. Please review the delivery choice and try again.', 'bg-commerce-suite' );
			}

			$meta = $rates[ $rate_id ]->get_meta_data();
			if ( $selection && ! Selection_Synchronizer::rate_is_settled_for( $rates[ $rate_id ], $selection->courier ) ) {
				if ( ! empty( $meta['_bgcs3_errors'] ) && is_array( $meta['_bgcs3_errors'] ) ) {
					return implode( ' ', array_map( 'sanitize_text_field', $meta['_bgcs3_errors'] ) );
				}
				if ( isset( $meta['_bgcs3_courier'] ) && sanitize_key( (string) $meta['_bgcs3_courier'] ) !== $selection->courier ) {
					return __( 'The selected courier is not synchronized with the shipping method. Please review the delivery choice and try again.', 'bg-commerce-suite' );
				}
				return __( 'The courier could not confirm a shipping price for the current selection. Please review the delivery choice and try again.', 'bg-commerce-suite' );
			}

			if ( $selection && ! Selection_Synchronizer::rate_selection_matches( $rates[ $rate_id ], $selection ) ) {
				return __( 'The courier could not confirm a shipping price for the current selection. Please review the delivery choice and try again.', 'bg-commerce-suite' );
			}

			if ( ! $selection && empty( $meta['_bgcs3_validated'] ) ) {
				return __( 'The courier could not confirm a shipping price for the current selection. Please review the delivery choice and try again.', 'bg-commerce-suite' );
			}
		}

		return '';
	}

	/**
	 * Ensure persisted shipping lines cannot disagree with the canonical courier.
	 *
	 * @param \WC_Order $order     Order.
	 * @param Selection $selection Canonical selection.
	 * @return string Empty string when consistent.
	 */
	private function order_shipping_selection_error( \WC_Order $order, Selection $selection ) {
		foreach ( $order->get_shipping_methods() as $item ) {
			$method_id = (string) $item->get_method_id();
			if ( 0 !== strpos( $method_id, 'bgcs3_' ) ) {
				continue;
			}

			$courier = sanitize_key( substr( $method_id, strlen( 'bgcs3_' ) ) );
			if ( $courier !== $selection->courier ) {
				return __( 'The selected courier is not synchronized with the order shipping method. Please review the delivery choice and try again.', 'bg-commerce-suite' );
			}
		}

		return '';
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private function order_uses_bgcs( \WC_Order $order ) {
		foreach ( $order->get_shipping_methods() as $item ) {
			if ( 0 === strpos( (string) $item->get_method_id(), 'bgcs3_' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enabled courier modules.
	 *
	 * @return Courier_Interface[]
	 */
	public static function enabled_couriers() {
		$couriers = array();

		$container = bgcs3()->container();
		if ( ! isset( $container['modules'] ) ) {
			return $couriers;
		}

		foreach ( $container['modules']->all() as $module ) {
			if ( $module instanceof Courier_Interface && $module->is_enabled() ) {
				$couriers[ $module->id() ] = $module;
			}
		}

		return $couriers;
	}

	/**
	 * Config passed to the front-end (classic + Blocks).
	 *
	 * @return array<string,mixed>
	 */
	/**
	 * Carry a merchant's old Econt “Remember last address” choice into the Core
	 * setting that finally implements it (BGCS-AUDIT-002 / TASK-F1).
	 *
	 * The Econt field was rendered, saved and never read, so switching it off did
	 * nothing. Now that `checkout.remember_selection` really controls browser
	 * persistence, a merchant who had switched the old field off — a deliberate
	 * privacy choice on shared or public computers — must not silently get the
	 * behaviour turned back on.
	 *
	 * Idempotent, and never overwrites a Core value the merchant has already set.
	 * The Econt value is deliberately left in place for rollback.
	 *
	 * @return bool Whether a value was carried over.
	 */
	public static function migrate_remember_selection() {
		$checkout = get_option( 'bgcs3_checkout', array() );
		$checkout = is_array( $checkout ) ? $checkout : array();

		if ( array_key_exists( 'remember_selection', $checkout ) ) {
			return false;
		}

		$legacy = bgcs3_get_option( 'econt', 'local_storage', '' );
		if ( ! in_array( $legacy, array( 'yes', 'no' ), true ) ) {
			return false;
		}

		$checkout['remember_selection'] = $legacy;
		update_option( 'bgcs3_checkout', $checkout, false );

		return true;
	}

	public static function frontend_data() {
		$couriers = array();

		foreach ( self::enabled_couriers() as $courier ) {
			$couriers[ $courier->id() ] = array(
				'name'          => $courier->name(),
				'deliveryTypes' => $courier->delivery_types(),
			);
		}

		$data = array(
			'restUrl'       => esc_url_raw( rest_url( 'bg-commerce-suite/v3/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'couriers'      => $couriers,
			'design'        => bgcs3_get_option( 'checkout', 'design', 'default' ),
			'showMap'       => 'yes' === bgcs3_get_option( 'checkout', 'show_map', 'yes' ),
			'cleanCheckout' => 'yes' === bgcs3_get_option( 'checkout', 'hide_fields', 'no' ),
			// BGCS-AUDIT-002 — whether the customer's last selection may be kept
			// in their own browser. A merchant who switches this off must actually
			// get no writes to localStorage, not a setting that changes nothing.
			'rememberSelection' => 'yes' === bgcs3_get_option( 'checkout', 'remember_selection', 'yes' ),
			'leafletImages' => BGCS3_URL . 'assets/vendor/leaflet/images/',
			'i18n'          => array(
				'searchCity'   => __( 'Start typing a city…', 'bg-commerce-suite' ),
				'loading'      => __( 'Loading…', 'bg-commerce-suite' ),
				'noResults'    => __( 'No results', 'bg-commerce-suite' ),
				'chooseOffice' => __( 'Select an office', 'bg-commerce-suite' ),
				'chooseLocker' => __( 'Select a locker', 'bg-commerce-suite' ),
				'selected'     => __( 'Selected:', 'bg-commerce-suite' ),
				'blockTitle'   => __( 'BG Commerce Suite — delivery selection', 'bg-commerce-suite' ),
				'city'         => __( 'City', 'bg-commerce-suite' ),
				'typeOffice'   => __( 'To office', 'bg-commerce-suite' ),
				'typeLocker'   => __( 'To locker', 'bg-commerce-suite' ),
				'typeAddress'  => __( 'To address', 'bg-commerce-suite' ),
				'street'       => __( 'Street', 'bg-commerce-suite' ),
				'streetNum'    => __( 'Number', 'bg-commerce-suite' ),
				'note'         => __( 'Additional (optional)', 'bg-commerce-suite' ),
				'requestError'        => __( 'We could not load the data. Try again.', 'bg-commerce-suite' ),
				'awaitingCalculation' => __( 'Awaiting calculation', 'bg-commerce-suite' ),
				'freeShipping'        => __( 'Free shipping', 'bg-commerce-suite' ),
			),
		);

		/**
		 * Filter the data localized to the checkout selector (window.bgcsCheckout).
		 * Courier add-ons may add their own keys, but prefer localizing a separate
		 * object on their own script handle.
		 *
		 * @param array<string,mixed> $data Frontend data.
		 */
		return (array) apply_filters( 'bgcs3_checkout_frontend_data', $data );
	}
}
