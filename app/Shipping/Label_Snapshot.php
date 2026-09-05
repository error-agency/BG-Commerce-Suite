<?php
/**
 * The one place a shipment snapshot is built (Rule 28, BUG-012).
 *
 * BGCS-AUDIT-006: the two admin create paths each had their own
 * `populate_label_snapshot()`. `MetaBox`'s version asked the courier module to
 * correct the financial fields through `label_snapshot_financials()`; the
 * `Orders_Column` quick-create version did not, and instead read Speedy's
 * `service_payer` setting and applied it to every courier — a key Econt,
 * BOX NOW and Pigeon do not have, so quick-create recorded `RECIPIENT` for all
 * three regardless of configuration. Which button the merchant pressed decided
 * what the order's financial record said.
 *
 * BGCS-AUDIT-004: Core also guessed the payer per courier, and its Econt branch
 * read `payment_side` — an option that has never existed anywhere in the
 * plugin, so it always fell back to a default and every Econt shipment was
 * recorded as recipient-paid. Econt in fact always bills courier services to
 * the sender, by deliberate design.
 *
 * Hence: Core no longer guesses. It fills in what it can know generically and
 * asks the courier module for the payment semantics it alone knows. A module
 * that says nothing leaves the payer empty — unknown is recorded as unknown,
 * never as an assumed value.
 *
 * The snapshot records BGCS *intent* at create time, taken from the same
 * resolvers the couriers themselves use — not whatever the provider echoes
 * back — so payout reconciliation compares like with like.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Support\Label_Result;

defined( 'ABSPATH' ) || exit;

final class Label_Snapshot {

	/**
	 * Fill the canonical snapshot fields on a freshly created label.
	 *
	 * Mutates rather than returns a new `Label_Result` because the courier has
	 * already populated the fields only it can know — waybill number, PDF URL,
	 * provider warnings — and those must survive.
	 *
	 * @param Label_Result                                        $result  Result from `create_label()`.
	 * @param \WC_Order                                           $order   Order.
	 * @param \BgCommerce3\Modules\Shipping\Courier_Interface|null $courier Courier module, when resolvable.
	 * @return void
	 */
	public static function apply( Label_Result $result, \WC_Order $order, $courier = null ) {
		$wb        = $order->get_meta( '_bgcs3_wb' );
		$wb        = is_array( $wb ) ? $wb : array();
		$selection = $order->get_meta( '_bgcs3_selection' );
		$selection = is_array( $selection ) ? $selection : array();

		$courier_id = ! empty( $selection['courier'] ) ? (string) $selection['courier'] : '';

		$result->delivery_type  = ! empty( $selection['delivery_type'] ) ? (string) $selection['delivery_type'] : '';
		$result->payment_method = (string) $order->get_payment_method();
		$result->cod_amount     = Cod::resolve_amount( $order, $wb );
		$result->cod_currency   = (string) $order->get_currency();

		// The order-level payer override, when the courier honours one at all.
		// Core does not decide what it means — the module below either confirms
		// it, replaces it, or (for a courier with no payer concept) clears it.
		$result->payer = ! empty( $wb['payer'] ) ? (string) $wb['payer'] : '';

		self::apply_courier_financials( $result, $order, $wb, $courier );

		// Rule 27 / BGCS-AUDIT-001 — the stable reference this shipment was
		// created under. It is what BOX NOW (P410) and Pigeon (HTTP 409) actually
		// deduplicate on, so persisting it is what lets a duplicated or orphaned
		// courier shipment be traced back to an exact order + edition.
		$result->meta['shipment_reference'] = Shipment_Reference::for_order( $order );

		$preflight = $order->get_meta( '_bgcs3_preflight' );
		$preflight = is_array( $preflight ) ? $preflight : array();
		$result->environment = $courier && method_exists( $courier, 'preflight_environment' )
			? (string) $courier->preflight_environment()
			: ( ! empty( $preflight['environment'] ) ? (string) $preflight['environment'] : '' );
		$result->payload_fingerprint = ! empty( $preflight['payload']['fingerprint'] ) ? (string) $preflight['payload']['fingerprint'] : '';

		$result->shipment_number = '' !== (string) $result->shipment_number
			? (string) $result->shipment_number
			: ( ! empty( $result->meta['shipment_id'] ) ? (string) $result->meta['shipment_id'] : (string) $result->number );
		$result->parcel_ids = self::string_list(
			! empty( $result->parcel_ids )
				? $result->parcel_ids
				: ( isset( $result->meta['parcel_ids'] ) ? $result->meta['parcel_ids'] : array() )
		);
		$result->tracking_numbers = self::string_list( ! empty( $result->tracking_numbers ) ? $result->tracking_numbers : array( $result->number ) );
		$result->label_reference  = '' !== (string) $result->label_reference ? (string) $result->label_reference : (string) $result->number;
		$result->label_status     = '' !== (string) $result->pdf_url
			? ( 'remote' === (string) $result->label_status ? 'remote' : 'available' )
			: 'missing';

		$result->is_cod         = $result->cod_amount > 0;
		$result->weight         = ! empty( $wb['weight'] ) ? (float) $wb['weight'] : Weight::for_order( $courier_id, $order );
		$result->declared_value = Overrides::resolve( $wb, 'dv_mode', 'declared_value', 0.0 );
		$result->packages       = ( ! empty( $wb['packages'] ) && is_array( $wb['packages'] ) ) ? $wb['packages'] : array();
		$result->package_type   = ! empty( $wb['package_type'] ) ? (string) $wb['package_type'] : '';

		// Some providers can create a pickup request together with the label.
		// Persist the same canonical order association used by standalone pickup
		// requests so the request remains visible and traceable to this edition.
		if ( ! empty( $result->meta['courier_request_id'] ) ) {
			$pickup_shipment = array(
				'order_id'           => (int) $order->get_id(),
				'waybill'            => (string) $result->number,
				'shipment_reference' => (string) $result->meta['shipment_reference'],
			);
			$pickup = Pickup_Request::record(
				$courier_id,
				(string) $result->meta['courier_request_id'],
				Pickup_Request::PENDING,
				isset( $result->meta['courier_request_date'] ) ? (string) $result->meta['courier_request_date'] : '',
				isset( $result->meta['courier_request_time_from'] ) ? (string) $result->meta['courier_request_time_from'] : '',
				isset( $result->meta['courier_request_time_to'] ) ? (string) $result->meta['courier_request_time_to'] : '',
				array( $pickup_shipment ),
				hash( 'sha256', $courier_id . '|' . (string) $result->meta['courier_request_id'] . '|' . (string) $result->meta['shipment_reference'] )
			);
			Pickup_Request::associate_order( $order, $pickup, $pickup_shipment );
		}
	}

	/** @return string[] */
	private static function string_list( $values ) {
		$out = array();
		foreach ( (array) $values as $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$out[] = $value;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Let the courier state the payment semantics of the shipment it just
	 * created.
	 *
	 * A courier may legitimately split what the recipient pays between COD and
	 * the courier service itself, so accounting must never fall back to the full
	 * WooCommerce total when the provider was told to collect less. Equally, a
	 * locker network has no courier-service payer at all and must be able to say
	 * so rather than have one assumed for it.
	 *
	 * Every returned key is optional; a module corrects only what it knows.
	 *
	 * @param Label_Result                                        $result  Snapshot being filled.
	 * @param \WC_Order                                           $order   Order.
	 * @param array<string,mixed>                                 $wb      Waybill overrides.
	 * @param \BgCommerce3\Modules\Shipping\Courier_Interface|null $courier Courier module.
	 * @return void
	 */
	private static function apply_courier_financials( Label_Result $result, \WC_Order $order, array $wb, $courier ) {
		// `method_exists` rather than an interface method: the hook is optional
		// so that a third-party courier add-on written against an earlier Core
		// keeps working. `Abstract_Courier` provides the no-op default.
		if ( ! $courier || ! method_exists( $courier, 'label_snapshot_financials' ) ) {
			return;
		}

		$financials = $courier->label_snapshot_financials( $order, $wb );
		if ( ! is_array( $financials ) ) {
			return;
		}

		if ( array_key_exists( 'payer', $financials ) ) {
			$result->payer = (string) $financials['payer'];
		}
		if ( isset( $financials['cod_amount'] ) && is_numeric( $financials['cod_amount'] ) ) {
			$result->cod_amount = max( 0.0, (float) $financials['cod_amount'] );
		}
		if ( ! empty( $financials['cod_currency'] ) ) {
			$result->cod_currency = strtoupper( (string) $financials['cod_currency'] );
		}
	}
}
