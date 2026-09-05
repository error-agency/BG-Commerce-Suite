<?php
/**
 * Courier contract. Extends Module_Interface with the shipping-specific
 * behaviour: location data, checkout schema, pricing, labels and tracking.
 *
 * A courier in Core or in a DLC add-on implements this once; the rest of the
 * plugin (checkout, admin, automations) stays courier-agnostic.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping;

use BgCommerce3\Module\Module_Interface;
use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Price_Result;
use BgCommerce3\Support\Label_Result;
use BgCommerce3\Support\Tracking_Result;

defined( 'ABSPATH' ) || exit;

interface Courier_Interface extends Module_Interface {

	/**
	 * Supported delivery types: subset of 'office', 'locker', 'address'.
	 *
	 * @return string[]
	 */
	public function delivery_types();

	/**
	 * The API client.
	 *
	 * @return Abstract_Client
	 */
	public function client();

	/**
	 * The location data provider.
	 *
	 * @return Locations_Provider
	 */
	public function locations();

	/**
	 * Declarative checkout-field schema for a delivery type. Consumed by both
	 * the React selector (via REST) and the PHP validator — one source of truth.
	 *
	 * @param string $delivery_type Delivery type.
	 * @return array<string,mixed>
	 */
	public function checkout_schema( $delivery_type );

	/**
	 * Validate a selection server-side.
	 *
	 * @param Selection $selection Customer selection.
	 * @return true|\WP_Error
	 */
	public function validate( Selection $selection );

	/**
	 * Quote the shipping price for a package + selection.
	 *
	 * @param array<string,mixed> $package   WC shipping package.
	 * @param Selection           $selection Customer selection.
	 * @return Price_Result
	 */
	public function quote( array $package, Selection $selection );

	/**
	 * Calculate courier-specific surcharges (e.g. cash on delivery fee, postal
	 * money transfer recovery) for a package + selection. Allows add-ons to
	 * contribute surcharges on top of static base rates without replacing the
	 * Core pricing ladder.
	 *
	 * @param array<string,mixed> $package   WC shipping package.
	 * @param Selection           $selection Customer selection.
	 * @param float               $base_cost Base shipping cost.
	 * @return array<string,mixed> Surcharge items keyed by identifier.
	 */
	public function calculate_surcharges( array $package, Selection $selection, $base_cost = 0.0 );

	/**
	 * Create a waybill for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return Label_Result
	 */
	public function create_label( \WC_Order $order );

	/**
	 * Cancel/delete a waybill for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function delete_label( \WC_Order $order );

	/**
	 * Fetch tracking for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return Tracking_Result
	 */
	public function tracking( \WC_Order $order );

	/**
	 * Normalize a raw courier tracking event to a canonical BGCS shipment state
	 * (Rule 41) — never a WooCommerce status directly. Core's
	 * `Tracking_Status_Policy` decides, separately, whether/how a normalized
	 * state should change the WooCommerce order status.
	 *
	 * Implementations must use exact/documented provider codes, never fuzzy
	 * substring matching (Rule 42), and must return
	 * `\BgCommerce3\Shipping\Tracking_State::UNKNOWN` for anything not
	 * confidently recognized — never guess.
	 *
	 * @param array<string,mixed> $event Tracking event.
	 * @return string One of \BgCommerce3\Shipping\Tracking_State::*.
	 */
	public function normalize_status( array $event );
}
