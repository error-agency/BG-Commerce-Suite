<?php
/**
 * Base WooCommerce shipping method for couriers. One thin subclass per courier
 * sets the courier id; delivery type (office/locker/address) is an instance
 * setting, so a store adds the method per zone once for each delivery type.
 *
 * Pricing is read from the customer's Selection (WC session) and delegated to
 * the courier's quote() — a single source of truth shared with the checkout.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Shipping\Pricing;
use BgCommerce3\Support\Selection_Store;
use BgCommerce3\Support\Shipping_Availability;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

abstract class Method extends \WC_Shipping_Method {

	/**
	 * Courier id this method serves (e.g. 'econt').
	 *
	 * @return string
	 */
	abstract public function get_courier_id();

	/**
	 * @param int $instance_id Zone instance id.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id          = 'bgcs3_' . $this->get_courier_id();
		$this->instance_id = absint( $instance_id );

		$courier            = $this->get_courier();
		$module_title       = $courier ? (string) Module_Settings::get( $courier->id(), 'method_title' ) : '';
		$this->method_title = '' !== $module_title ? $module_title : ( $courier ? $courier->name() : $this->id );
		$this->method_description = $courier
			/* translators: %s: courier name. */
			? sprintf( __( 'Shipping with %s — automatic price calculation.', 'bg-commerce-suite' ), $courier->name() )
			: '';

		$this->supports = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->title      = $this->get_option( 'title', $this->method_title );
		$this->tax_status = $this->get_option( 'tax_status', 'none' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Mark this instance as an opt-in, non-persistent checkout fallback.
	 *
	 * WooCommerce skips zone-aware methods whose instance id is zero. Removing
	 * the zone capability lets the normal rate pipeline calculate this temporary
	 * object without inventing or storing a fake zone instance.
	 */
	public function enable_runtime_fallback() {
		$this->instance_id = 0;
		$this->enabled     = 'yes';
		$this->supports    = array_values(
			array_diff(
				$this->supports,
				array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' )
			)
		);
	}

	public function init_form_fields() {
		$courier = $this->get_courier();
		$labels  = array(
			'office'  => __( 'Office', 'bg-commerce-suite' ),
			'locker'  => __( 'Locker', 'bg-commerce-suite' ),
			'address' => __( 'Address', 'bg-commerce-suite' ),
		);

		$type_options = array();
		if ( $courier ) {
			foreach ( $courier->delivery_types() as $type ) {
				$type_options[ $type ] = isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
			}
		}

		$this->instance_form_fields = array(
			'title'              => array(
				'title'       => __( 'Title', 'bg-commerce-suite' ),
				'type'        => 'text',
				'description' => __( 'This is what the customer sees at checkout.', 'bg-commerce-suite' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
			),
			'delivery_types'     => array(
				'title'       => __( 'Allowed delivery types', 'bg-commerce-suite' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Which options the customer sees at checkout (selected by the customer from one menu).', 'bg-commerce-suite' ),
				'default'     => array_keys( $type_options ),
				'options'     => $type_options,
				'desc_tip'    => true,
			),
			'tax_status'         => array(
				'title'   => __( 'Tax', 'bg-commerce-suite' ),
				'type'    => 'select',
				'default' => 'none',
				'options' => array(
					'taxable' => __( 'Taxable', 'bg-commerce-suite' ),
					'none'    => __( 'No tax', 'bg-commerce-suite' ),
				),
			),
		);
	}

	/**
	 * @param array<string,mixed> $package WC shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		$courier = $this->get_courier();
		if ( ! $courier ) {
			return;
		}
		$availability_store = new Availability_Store();

		$allowed_types = $this->get_allowed_types();
		$store         = new Selection_Store();
		$selection     = $store->get();

		$rate = array(
			'id'        => $this->get_rate_id(),
			'label'     => $this->title,
			'cost'      => 0,
			'package'   => $package,
			'meta_data' => array(
				// Underscore-prefixed → hidden on the order line, but still
				// available on the rate for the checkout JS marker.
				'_bgcs3_courier'        => $courier->id(),
				'_bgcs3_delivery_types' => implode( ',', $allowed_types ),
				'_bgcs3_validated'      => false,
				'_bgcs3_price_state'    => 'pending',
				// The courier name as this method set it, before any filter has
				// seen it. WC_Shipping_Rate offers no way back to the stored
				// label: get_label() re-enters `woocommerce_shipping_rate_label`,
				// and the magic __get( 'label' ) forwards to get_label(). Anything
				// that needs the untouched name — here or in an add-on — must read
				// it from a value the method itself published.
				'_bgcs3_method_title'   => $this->title,
			),
		);

		$matches = $selection
			&& $selection->courier === $courier->id()
			&& in_array( $selection->delivery_type, $allowed_types, true )
			&& $selection->is_complete();

		// Physical eligibility is independent from the currently selected courier.
		// Couriers may expose this optional capability so Core can show a truthful,
		// non-selectable card instead of silently removing the shipping option.
		if ( method_exists( $courier, 'package_availability' ) ) {
			$package_availability = $courier->package_availability( $package );
			if ( $package_availability instanceof Shipping_Availability ) {
				$availability_store->record( $courier->id(), $courier->name(), $package, $package_availability );
				return;
			}
		}
		if ( ! $selection || $selection->courier !== $courier->id() ) {
			$availability_store->clear( $courier->id(), $package );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Не логвай пълния Selection обект — съдържа клиентски адрес/офис/град
			// (PII). Само неутралните полета, нужни за debug на rate matching
			// (Rule 37/86 — logger не трябва да изтича лични данни).
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[BGCS] calc_shipping courier=%s matched=%s allowed=%s selection_courier=%s selection_type=%s selection_complete=%s',
				$courier->id(),
				$matches ? '1' : '0',
				implode( ',', $allowed_types ),
				$selection ? $selection->courier : 'null',
				$selection ? $selection->delivery_type : 'null',
				$selection ? ( $selection->is_complete() ? '1' : '0' ) : 'null'
			) );
		}

		if ( $matches ) {
			$selection_validation = $courier->validate( $selection );
			if ( is_wp_error( $selection_validation ) ) {
				$availability_store->record(
					$courier->id(),
					$courier->name(),
					$package,
					Shipping_Availability::unavailable(
						$courier->id() . '_selection_invalid',
						$selection_validation->get_error_message(),
						'Courier selection validation failed. code=' . $selection_validation->get_error_code()
					)
				);
				return;
			}

			$package_validation = $courier->validate_package( $package, $selection );
			if ( is_wp_error( $package_validation ) ) {
				$availability_store->record(
					$courier->id(),
					$courier->name(),
					$package,
					Shipping_Availability::unavailable(
						$courier->id() . '_package_invalid',
						$package_validation->get_error_message(),
						'Courier package validation failed. code=' . $package_validation->get_error_code()
					)
				);
				return;
			}

			// Pricing ladder: per-type free shipping → per-type static price (weight-gated) →
			// the courier's contract/API quote.
			$cart_total = 0.0;
			if ( function_exists( 'WC' ) && WC()->cart ) {
				// Pricing-rule OrderTotal is the goods value, not shipping/fees. Use
				// the current post-discount contents totals (with item taxes) because
				// they are stable before Woo starts calculating shipping and fees.
				if ( method_exists( WC()->cart, 'get_cart_contents_total' ) && method_exists( WC()->cart, 'get_cart_contents_tax' ) ) {
					$cart_total = (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax();
				} elseif ( method_exists( WC()->cart, 'get_displayed_subtotal' ) ) {
					$cart_total = (float) WC()->cart->get_displayed_subtotal();
				}
			}
			$weight_info    = Weight::cart_weight_info( $courier->id(), $package );
			$package_weight = $weight_info['weight'];
			$weight_known   = $weight_info['weight_known'];
			$resolved       = Pricing::resolve( $courier->id(), $selection->delivery_type, $cart_total, $package_weight, $weight_known );

			// Custom pricing is authoritative. If the merchant selected own prices,
			// never silently replace a missing/non-matching rule with a courier API
			// quote; that makes a configured fixed price appear to be ignored.
			if ( null === $resolved && Pricing::MODE_OWN === Pricing::mode( $courier->id() ) ) {
				$availability_store->record(
					$courier->id(),
					$courier->name(),
					$package,
					Shipping_Availability::unavailable(
						$courier->id() . '_custom_price_missing',
						__( 'This delivery method has no configured price for the current cart. Please choose another method.', 'bg-commerce-suite' ),
						'Own-price mode did not match delivery type, package weight and order value.'
					)
				);
				return;
			}

			$final_cost = 0.0;

			if ( null !== $resolved ) {
				$base_cost       = (float) $resolved['cost'];
				$surcharges      = array();
				$surcharge_total = 0.0;

				if ( 'free' === $resolved['source'] ) {
					// Free transport and contractual payment-service charges are separate
					// concepts. Couriers opt in explicitly; the generic default remains 0.
					if ( method_exists( $courier, 'calculate_free_shipping_surcharges' ) ) {
						$surcharges = (array) $courier->calculate_free_shipping_surcharges( $package, $selection );
					}
					$surcharges = (array) apply_filters( 'bgcs3_shipping_surcharges', $surcharges, $courier, $package, $selection, 0.0 );

					foreach ( $surcharges as $surcharge ) {
						if ( is_array( $surcharge ) && isset( $surcharge['amount'] ) ) {
							$surcharge_total += (float) $surcharge['amount'];
						} elseif ( is_numeric( $surcharge ) ) {
							$surcharge_total += (float) $surcharge;
						}
					}

					$final_cost                               = round( $surcharge_total, 2 );
					$rate['meta_data']['_bgcs3_validated']     = true;
					$rate['meta_data']['_bgcs3_selection']     = $selection->to_array();
					$rate['meta_data']['_bgcs3_free_shipping'] = true;
					$rate['meta_data']['_bgcs3_price_state']    = 'free';
					$rate['meta_data']['_bgcs3_pricing_mode']  = 'free';
					$rate['meta_data']['_bgcs3_pricing_source'] = 'free';
					$rate['meta_data']['_bgcs3_base_cost']     = 0.0;
					$rate['meta_data']['_bgcs3_surcharges']    = $surcharges;
				} else {
					// A courier may reject a generic static rate when its own contract
					// semantics make that configuration unsafe (e.g. wrong service payer).
					if ( method_exists( $courier, 'validate_static_pricing' ) ) {
						$static_validation = $courier->validate_static_pricing( $selection, $base_cost );
						if ( is_wp_error( $static_validation ) ) {
							$availability_store->record(
								$courier->id(),
								$courier->name(),
								$package,
								Shipping_Availability::unavailable(
									$courier->id() . '_static_price_invalid',
									$static_validation->get_error_message(),
									'Static price validation failed. code=' . $static_validation->get_error_code()
								)
							);
							return;
						}
					}

					// Static base rate. Query courier for applicable surcharges (e.g. PMT recovery).
					if ( method_exists( $courier, 'calculate_surcharges' ) ) {
						$surcharges = (array) $courier->calculate_surcharges( $package, $selection, $base_cost );
					}
					$surcharges = (array) apply_filters( 'bgcs3_shipping_surcharges', $surcharges, $courier, $package, $selection, $base_cost );

					foreach ( $surcharges as $surcharge ) {
						if ( is_array( $surcharge ) && isset( $surcharge['amount'] ) ) {
							$surcharge_total += (float) $surcharge['amount'];
						} elseif ( is_numeric( $surcharge ) ) {
							$surcharge_total += (float) $surcharge;
						}
					}

					$final_cost = round( $base_cost + $surcharge_total, 2 );

					$rate['meta_data']['_bgcs3_validated']                = true;
					$rate['meta_data']['_bgcs3_price_state']              = 'calculated';
					$rate['meta_data']['_bgcs3_selection']                = $selection->to_array();
					$rate['meta_data']['_bgcs3_pricing_mode']             = 'static';
					$rate['meta_data']['_bgcs3_pricing_source']           = isset( $resolved['source'] ) ? sanitize_key( (string) $resolved['source'] ) : 'static';
					$rate['meta_data']['_bgcs3_base_cost']                = $base_cost;
					$rate['meta_data']['_bgcs3_surcharges']               = $surcharges;
					$rate['meta_data']['_bgcs3_pricing_weight']           = $package_weight;
					$rate['meta_data']['_bgcs3_pricing_weight_threshold'] = isset( $resolved['weight_threshold'] ) ? (float) $resolved['weight_threshold'] : 0.0;
					if ( ! empty( $resolved['pricing_rule'] ) && is_array( $resolved['pricing_rule'] ) ) {
						$rate['meta_data']['_bgcs3_pricing_rule'] = $resolved['pricing_rule'];
					}
					if ( ! empty( $resolved['contract_currency'] ) ) {
						$rate['meta_data']['_bgcs3_contract_currency'] = strtoupper( (string) $resolved['contract_currency'] );
					}
				}

				if ( isset( $surcharges['pmt'] ) ) {
					$pmt = is_array( $surcharges['pmt'] ) ? $surcharges['pmt'] : array( 'amount' => (float) $surcharges['pmt'] );
					$rate['meta_data']['_bgcs3_pmt_amount'] = isset( $pmt['amount'] ) ? (float) $pmt['amount'] : 0.0;
					foreach ( array( 'base', 'source', 'payer' ) as $meta_key ) {
						if ( isset( $pmt[ $meta_key ] ) ) {
							$rate['meta_data'][ '_bgcs3_pmt_' . $meta_key ] = $pmt[ $meta_key ];
						}
					}
				}
			} else {
				$price = $courier->quote( $package, $selection );

				if ( $price->valid ) {
					$final_cost                                           = (float) $price->cost;
					$rate['meta_data']['_bgcs3_validated']                = true;
					$rate['meta_data']['_bgcs3_price_state']              = 'calculated';
					$rate['meta_data']['_bgcs3_selection']                = $selection->to_array();
					$rate['meta_data']['_bgcs3_pricing_mode']             = ! empty( $price->mode ) ? $price->mode : 'api';
					$rate['meta_data']['_bgcs3_pricing_source']           = ! empty( $price->source ) ? sanitize_key( (string) $price->source ) : ( ! empty( $price->mode ) ? sanitize_key( (string) $price->mode ) : 'api' );
					$rate['meta_data']['_bgcs3_base_cost']                = $price->base_cost > 0 ? $price->base_cost : $price->cost;
					$rate['meta_data']['_bgcs3_surcharges']               = $price->surcharges;
					$rate['meta_data']['_bgcs3_pricing_weight']           = $package_weight;
					$rate['meta_data']['_bgcs3_pricing_weight_threshold'] = $price->weight_threshold;
					if ( ! empty( $price->warnings ) ) {
						$rate['meta_data']['_bgcs3_warnings'] = array_values( array_filter( $price->warnings ) );
					}
					$delivery_estimate = Delivery_Estimate::sanitize( $price->delivery_estimate );
					if ( ! empty( $delivery_estimate ) ) {
						$rate['meta_data']['_bgcs3_delivery_estimate'] = $delivery_estimate;
					}
					if ( ! empty( $price->meta['price_breakdown'] ) && is_array( $price->meta['price_breakdown'] ) ) {
						$rate['meta_data']['_bgcs3_price_breakdown'] = $price->meta['price_breakdown'];
					}
					if ( ! empty( $price->meta['courier_service_payer'] ) ) {
						$rate['meta_data']['_bgcs3_courier_service_payer'] = strtoupper( (string) $price->meta['courier_service_payer'] );
					}

					if ( isset( $price->surcharges['pmt'] ) ) {
						$pmt = is_array( $price->surcharges['pmt'] ) ? $price->surcharges['pmt'] : array( 'amount' => (float) $price->surcharges['pmt'] );
						$rate['meta_data']['_bgcs3_pmt_amount'] = isset( $pmt['amount'] ) ? (float) $pmt['amount'] : 0.0;
						foreach ( array( 'base', 'source', 'payer' ) as $meta_key ) {
							if ( isset( $pmt[ $meta_key ] ) ) {
								$rate['meta_data'][ '_bgcs3_pmt_' . $meta_key ] = $pmt[ $meta_key ];
							}
						}
					}
				} else {
					// A complete courier selection with a failed API quote is not a zero-cost
					// shipping rate. Do not leave an unvalidated selectable rate behind: that
					// can let checkout proceed with free delivery after an API failure.
					$availability = $price->availability instanceof Shipping_Availability
						? $price->availability
						: Shipping_Availability::error(
							$courier->id() . '_price_failed',
							__( 'We cannot calculate this delivery price right now. Please try again or choose another method.', 'bg-commerce-suite' ),
							! empty( $price->errors ) ? implode( ' ', array_filter( $price->errors ) ) : 'Courier quote returned an invalid result without an error.'
						);
					$availability_store->record( $courier->id(), $courier->name(), $package, $availability );
					return;
				}
			}

			// Gross VAT decomposition:
			// Fixed contract prices and Speedy quote prices are VAT-inclusive (gross).
			// Decomposing gross into net + taxes when taxable guarantees customer pays exact gross amount.
			$rate_cost  = $final_cost;
			$rate_taxes = false;

			if ( 'taxable' === $this->tax_status ) {
				if ( function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() ) {
					$tax_rates = class_exists( '\WC_Tax' ) ? \WC_Tax::get_shipping_tax_rates() : array();
					if ( ! empty( $tax_rates ) ) {
						$taxes      = class_exists( '\WC_Tax' ) ? \WC_Tax::calc_inclusive_tax( $final_cost, $tax_rates ) : array();
						$tax_amount = array_sum( $taxes );
						$rate_cost  = round( $final_cost - $tax_amount, 4 );
						$rate_taxes = $taxes;
					} else {
						$rate_cost  = $final_cost;
						$rate_taxes = array();
					}
				} else {
					$rate_cost  = $final_cost;
					$rate_taxes = false;
				}
			}

			$rate['cost']  = $rate_cost;
			$rate['taxes'] = $rate_taxes;

			// Surcharges are part of the shipping rate, not separate Woo fee lines.
			// Persist that VAT treatment with every component so the order audit can
			// explain whether Woo decomposed the gross rate as taxable or tax-free.
			if ( ! empty( $rate['meta_data']['_bgcs3_surcharges'] ) && is_array( $rate['meta_data']['_bgcs3_surcharges'] ) ) {
				foreach ( $rate['meta_data']['_bgcs3_surcharges'] as $name => $surcharge ) {
					if ( ! is_array( $surcharge ) ) {
						continue;
					}
					$surcharge['tax_treatment'] = 'shipping_rate';
					$surcharge['tax_status']    = $this->tax_status;
					$rate['meta_data']['_bgcs3_surcharges'][ $name ] = $surcharge;
				}
			}

			// Keep the method title presentation-neutral. Free/pending/calculated is
			// carried by `_bgcs3_price_state`; consumers (Flow/native checkout) decide
			// how to present that semantic state without mutating the courier name.
		}

		if ( $matches ) {
			$availability_store->clear( $courier->id(), $package );
		}

		$rate = apply_filters( 'bgcs3_shipping_rate', $rate, $this, $selection );
		$rate['meta_data']['_bgcs3_payment_context'] = Cod::is_chosen() ? 'cod' : 'prepaid';
		// The estimate deliberately does not touch the label. It travels as the
		// rate's own delivery_time property (Blocks) and as a Classic-side hook,
		// both wired in Shipping\Hooks. Appending it here would be discarded by
		// the Store API boundary and frozen into the order's method_title.

		// WooCommerce Store API intentionally strips underscore-prefixed shipping
		// rate meta. Expose a small, presentation-only public mirror so Cart/Checkout
		// Blocks can distinguish a provisional zero (pending) from genuine free
		// transport. These runtime markers are removed before the shipping item is
		// persisted to the order by Checkout::clean_shipping_item_meta().
		$rate = $this->expose_checkout_runtime_meta( $rate );

		$this->add_rate( $rate );
	}

	/**
	 * Mirror the minimum semantic shipping state into public rate meta for the
	 * WooCommerce Store API. Core Store API responses omit `_`-prefixed meta,
	 * which otherwise leaves Blocks with only a numeric zero and makes it render
	 * a not-yet-priced courier as "Free".
	 *
	 * @param array<string,mixed> $rate Shipping rate array passed to add_rate().
	 * @return array<string,mixed>
	 */
	private function expose_checkout_runtime_meta( $rate ) {
		if ( empty( $rate['meta_data'] ) || ! is_array( $rate['meta_data'] ) ) {
			$rate['meta_data'] = array();
		}

		$meta = $rate['meta_data'];
		$state = isset( $meta['_bgcs3_price_state'] ) ? sanitize_key( (string) $meta['_bgcs3_price_state'] ) : '';
		if ( ! in_array( $state, array( 'pending', 'calculated', 'free', 'unavailable' ), true ) ) {
			$state = empty( $meta['_bgcs3_validated'] ) ? 'pending' : ( ! empty( $meta['_bgcs3_free_shipping'] ) ? 'free' : 'calculated' );
		}

		$rate['meta_data']['courier']        = isset( $meta['_bgcs3_courier'] ) ? sanitize_key( (string) $meta['_bgcs3_courier'] ) : '';
		$rate['meta_data']['delivery_types'] = isset( $meta['_bgcs3_delivery_types'] ) ? sanitize_text_field( (string) $meta['_bgcs3_delivery_types'] ) : '';
		$rate['meta_data']['price_state']    = $state;
		$rate['meta_data']['validated']      = empty( $meta['_bgcs3_validated'] ) ? '0' : '1';
		$rate['meta_data']['free_shipping']  = empty( $meta['_bgcs3_free_shipping'] ) ? '0' : '1';
		// Public mirror of the untouched courier name, for the same reason the
		// state is mirrored: Store API strips `_`-prefixed meta, and a renderer
		// on that side has no other way back to the name this method set.
		$rate['meta_data']['method_title']   = isset( $meta['_bgcs3_method_title'] ) ? sanitize_text_field( (string) $meta['_bgcs3_method_title'] ) : '';
		$estimate_text = isset( $meta['_bgcs3_delivery_estimate'] ) ? Delivery_Estimate::format( $meta['_bgcs3_delivery_estimate'] ) : '';
		if ( '' !== $estimate_text ) {
			$rate['meta_data']['delivery_estimate'] = sanitize_text_field( $estimate_text );
		}

		if ( ! empty( $meta['_bgcs3_warnings'] ) ) {
			$warnings = is_array( $meta['_bgcs3_warnings'] ) ? $meta['_bgcs3_warnings'] : array( $meta['_bgcs3_warnings'] );
			$warnings = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $warnings ) ) ) );
			if ( ! empty( $warnings ) ) {
				$rate['meta_data']['warnings'] = wp_json_encode( $warnings );
			}
		}

		return $rate;
	}

	/**
	 * Delivery types the merchant allows for this instance, constrained to what
	 * the courier supports. Falls back to all supported types.
	 *
	 * @return string[]
	 */
	protected function get_allowed_types() {
		$courier   = $this->get_courier();
		$supported = $courier ? $courier->delivery_types() : array();

		$configured = $this->get_option( 'delivery_types' );
		if ( ! is_array( $configured ) || empty( $configured ) ) {
			return $supported;
		}

		$allowed = array_values( array_intersect( $configured, $supported ) );

		return empty( $allowed ) ? $supported : $allowed;
	}

	/**
	 * @return Courier_Interface|null
	 */
	protected function get_courier() {
		$container = bgcs3()->container();

		if ( ! isset( $container['modules'] ) ) {
			return null;
		}

		$module = $container['modules']->get( $this->get_courier_id() );

		return ( $module instanceof Courier_Interface ) ? $module : null;
	}
}
