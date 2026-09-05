<?php
/**
 * Speedy courier module. Office / locker (APT) / address delivery via the
 * api.speedy.bg/v1 JSON API (credentials in body). serviceId is a fixed
 * setting. Pricing via /calculate, label via /shipment + /print, tracking via
 * /track, cancel via /shipment/cancel.
 *
 * Payloads follow the Speedy API; validate against a real contract account.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\Speedy;

use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Abstract_Courier;
use BgCommerce3\Shipping\Cod;
use BgCommerce3\Shipping\Courier_Error;
use BgCommerce3\Shipping\Delivery_Estimate;
use BgCommerce3\Shipping\Financial_Invariants;
use BgCommerce3\Shipping\Hooks as Shipping_Hooks;
use BgCommerce3\Shipping\Office_Store;
use BgCommerce3\Shipping\Overrides;
use BgCommerce3\Shipping\Package_Dimensions;
use BgCommerce3\Shipping\Pricing;
use BgCommerce3\Shipping\Setup_Status;
use BgCommerce3\Shipping\Shipment_Creation;
use BgCommerce3\Shipping\Shipment_Reference;
use BgCommerce3\Shipping\Tracking_State;
use BgCommerce3\Shipping\Weight;
use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Price_Result;
use BgCommerce3\Support\Label_Result;
use BgCommerce3\Support\Tracking_Result;
use BgCommerce3\Support\Label_Pdf_Store;
use BgCommerce3\Support\Cache;
use BgCommerce3\Support\Sync_Result;
use BgCommerce3\Support\Module_Settings;
use BgCommerce3\Support\Options;
use BgCommerce3\Support\Shipment_Diagnostics;

defined( 'ABSPATH' ) || exit;

class Speedy extends Abstract_Courier {

	const ID                       = 'speedy';
	const COUNTRY_BG               = 100;
	// Confirmed by Speedy itself, not by us. `POST /validation/shipment` refuses a
	// 150-character description with „Съдържание: Максималната позволена дължина е
	// 100“, so the number is real even though the machine-readable schema declares
	// no `maxLength` and the documentation states a length only for `shipmentNote`.
	// The old defect was never this value — it was cutting text to it in silence.
	const CONTENTS_MAX_LENGTH      = 100;
	const SHIPMENT_NOTE_MAX_LENGTH = 200;
	const LOCKER_CAPACITY_POLICY_MERCHANT_INSTRUCTION = 'merchant_instruction';
	const LOCKER_CAPACITY_POLICY_SPEEDY_DEFAULT       = 'speedy_default';
	const DEFAULT_LOCKER_CAPACITY_NOTE = 'Само до посочения АПС! При запълнен автомат — задържане в разпределителен хъб до освобождаване на клетка, без автоматично пренасочване.';

	/** @var Client|null */
	private $client;

	/** @var Locations|null */
	private $locations;

	/** @var bool Guard against recursive fee probing during Woo totals calculation. */
	private $resolving_pmt_fees = false;

	public function id() {
		return self::ID;
	}

	public function name() {
		return __( 'Speedy', 'bg-commerce-suite' );
	}

	/**
	 * Optional MetaBox capability (duck-typed, not part of Courier_Interface —
	 * no Module API change): Speedy's real API accepts `content.parcels[]`
	 * with individual per-parcel size+weight (docs/speedy_api_reference.md §4
	 * "Мулти-колет"), so the order metabox may offer a real multi-pack editor
	 * instead of the legacy single-weight/dims fields.
	 *
	 * @return bool
	 */
	public function supports_multi_pack() {
		return true;
	}

	/**
	 * Documented input limits for Core's generic waybill fields.
	 *
	 * Core puts these on the field itself, so a merchant is stopped at the
	 * courier's real limit while typing rather than discovering it as a refusal
	 * after filling in the whole panel. Declared per courier because the limits
	 * genuinely differ — Pigeon allows 200 for an item description, Speedy 100.
	 *
	 * @return array<string,int>
	 */
	public function waybill_field_limits() {
		return array( 'contents' => self::CONTENTS_MAX_LENGTH );
	}

	/**
	 * Optional MetaBox capability: valid `content.package` values.
	 *
	 * Speedy publishes no packaging nomenclature — `ShipmentContent.package` is
	 * a plain string in the official JSON schema (api.speedy.bg/v1/schema) and
	 * no `/location/*` or `/services` resource returns a packaging list — so
	 * this is the curated set from Speedy's own request examples. Contracts with
	 * custom packaging can extend it through the filter rather than falling back
	 * to free text.
	 *
	 * @return array<string,string>
	 */
	public function package_types() {
		return (array) apply_filters(
			'bgcs3_speedy_package_types',
			array(
				'BOX'      => __( 'Box (BOX)', 'bg-commerce-suite' ),
				'ENVELOPE' => __( 'Envelope (ENVELOPE)', 'bg-commerce-suite' ),
				'PALLET'   => __( 'Pallet (PALLET)', 'bg-commerce-suite' ),
			)
		);
	}

	/**
	 * Optional MetaBox capability: Speedy-specific per-order waybill fields,
	 * rendered by Core in the same 2-column grid as its own fields and stored
	 * under `_bgcs3_wb['x']`. Every yes/no field carries an empty „inherit“
	 * option so a per-order choice is always distinguishable from „не е пипано“.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function waybill_fields() {
		$inherit = array(
			''    => __( 'Use settings', 'bg-commerce-suite' ),
			'yes' => __( 'Yes', 'bg-commerce-suite' ),
			'no'  => __( 'No', 'bg-commerce-suite' ),
		);

		// Cached for a day by Locations::services(), so this costs no API call
		// on an order screen.
		$services         = $this->locations()->cached_services();
		$service_options  = array( '' => __( 'Use settings', 'bg-commerce-suite' ) ) + $services;
		$contracts        = $this->locations()->cached_contracts();
		$contract_options = array( '' => __( 'Use settings', 'bg-commerce-suite' ) ) + $contracts;
		$offices          = $this->locations()->cached_all_offices_options();
		$office_options   = array( '' => __( 'Use settings', 'bg-commerce-suite' ) ) + $offices;

		return array(
			'sender_type'          => array(
				'group'       => 'extra',
				'type'        => 'select',
				'label'       => __( 'Parcel handover', 'bg-commerce-suite' ),
				'options'     => array(
					''       => __( 'Use settings', 'bg-commerce-suite' ),
					'client' => __( 'Courier pickup from contracted address', 'bg-commerce-suite' ),
					'office' => __( 'Drop off at Speedy office', 'bg-commerce-suite' ),
				),
				'description' => __( 'Changes parcel handover only for this order; the Speedy module defaults are not modified.', 'bg-commerce-suite' ),
			),
			'sender_client_id'     => array(
				'group'       => 'extra',
				'type'        => 'select',
				'label'       => __( 'Sender contract object', 'bg-commerce-suite' ),
				'options'     => $contract_options,
				'description' => __( 'Optional contract-object override. When empty, the configured Speedy sender is used.', 'bg-commerce-suite' ),
			),
			'sender_dropoff_office_id' => array(
				'group'       => 'extra',
				'type'        => 'select',
				'label'       => __( 'Drop-off office', 'bg-commerce-suite' ),
				'options'     => $office_options,
				'description' => __( 'Used for office handover. When empty, the configured Speedy drop-off office is used.', 'bg-commerce-suite' ),
			),
			'service_id'           => array(
				'group'       => 'services',
				'type'        => 'select',
				'label'       => __( 'Service', 'bg-commerce-suite' ),
				'options'     => $service_options,
				'description' => __( 'Per-order service override. Leaving this on “Use settings” keeps the Speedy default.', 'bg-commerce-suite' ),
			),
			'note'                 => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Shipment note', 'bg-commerce-suite' ),
				'full'        => true,
				'placeholder' => __( 'printed on the shipment label (up to 200 characters)', 'bg-commerce-suite' ),
			),
			'saturday'             => array(
				'group'       => 'services',
				'type'    => 'select',
				'label'   => __( 'Saturday delivery', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'deferred_days'        => array(
				'group'       => 'services',
				'type'    => 'select',
				'label'   => __( 'Deferred delivery', 'bg-commerce-suite' ),
				'options' => array(
					''  => __( 'Use settings', 'bg-commerce-suite' ),
					'0' => __( 'No delay', 'bg-commerce-suite' ),
					'1' => __( '1 business day', 'bg-commerce-suite' ),
					'2' => __( '2 business days', 'bg-commerce-suite' ),
				),
			),
			'fixed_time'           => array(
				'group'       => 'services',
				'type'        => 'text',
				'label'       => __( 'Delivery at a specific time', 'bg-commerce-suite' ),
				'placeholder' => __( 'for example, 1130 for 11:30', 'bg-commerce-suite' ),
			),
			'delivery_to_floor'    => array(
				'group'       => 'services',
				'type'        => 'number',
				'label'       => __( 'Delivery to floor', 'bg-commerce-suite' ),
				'min'         => '0',
				'step'        => '1',
				'placeholder' => __( 'floor number', 'bg-commerce-suite' ),
			),
			'cod_processing'       => array(
				'group'       => 'payment',
				'type'    => 'select',
				'label'   => __( 'Cash-on-delivery processing', 'bg-commerce-suite' ),
				'options' => array(
					''                      => __( 'Use settings', 'bg-commerce-suite' ),
					'CASH'                  => __( 'Cash', 'bg-commerce-suite' ),
					'POSTAL_MONEY_TRANSFER' => __( 'Postal money transfer (PMT)', 'bg-commerce-suite' ),
				),
			),
			'admin_fee'            => array(
				'group'       => 'payment',
				'type'    => 'select',
				'label'   => __( 'Administrative fee', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'third_party_client_id' => array(
				'group'       => 'payment',
				'type'        => 'number',
				'label'       => __( 'Third-party client ID', 'bg-commerce-suite' ),
				'min'         => '1',
				'step'        => '1',
				'placeholder' => __( 'inherit from Speedy settings', 'bg-commerce-suite' ),
				'description' => __( 'Used only when the courier-service payer for this order is Third party.', 'bg-commerce-suite' ),
			),
			'obp_return_service'   => array(
				'group'       => 'services',
				'show_if'     => array( 'control' => 'bgcs-wb-obp', 'value' => array( 'OPEN', 'TEST' ) ),
				'type'    => 'select',
				'label'   => __( 'Review/test: return service', 'bg-commerce-suite' ),
				'options' => $service_options,
			),
			'obp_return_payer'     => array(
				'group'       => 'services',
				'show_if'     => array( 'control' => 'bgcs-wb-obp', 'value' => array( 'OPEN', 'TEST' ) ),
				'type'    => 'select',
				'label'   => __( 'Review/test: return payer', 'bg-commerce-suite' ),
				'options' => array(
					''          => __( 'Use settings', 'bg-commerce-suite' ),
					'SENDER'    => __( 'Sender', 'bg-commerce-suite' ),
					'RECIPIENT' => __( 'Recipient', 'bg-commerce-suite' ),
					'THIRD_PARTY' => __( 'Third party', 'bg-commerce-suite' ),
				),
			),
			'return_voucher'       => array(
				'group'       => 'services',
				'type'    => 'select',
				'label'   => __( 'Return voucher', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'return_voucher_service' => array(
				'group'   => 'services',
				'type'    => 'select',
				'label'   => __( 'Return voucher: service', 'bg-commerce-suite' ),
				'options' => $service_options,
			),
			'return_voucher_payer' => array(
				'group'   => 'services',
				'type'    => 'select',
				'label'   => __( 'Return voucher: payer', 'bg-commerce-suite' ),
				'options' => array(
					''          => __( 'Use settings', 'bg-commerce-suite' ),
					'SENDER'    => __( 'Sender', 'bg-commerce-suite' ),
					'RECIPIENT' => __( 'Recipient', 'bg-commerce-suite' ),
					'THIRD_PARTY' => __( 'Third party', 'bg-commerce-suite' ),
				),
			),
			'return_voucher_validity' => array(
				'group'       => 'services',
				'type'        => 'number',
				'label'       => __( 'Return voucher: validity (days)', 'bg-commerce-suite' ),
				'min'         => '1',
				'step'        => '1',
				'placeholder' => __( 'inherit from Speedy settings', 'bg-commerce-suite' ),
			),
			'rod'                  => array(
				'group'       => 'services',
				'type'        => 'select',
				'label'       => __( 'Return receipt', 'bg-commerce-suite' ),
				'options'     => $inherit,
				'description' => __( 'Return signed document to sender.', 'bg-commerce-suite' ),
			),
			'documents'            => array(
				'group'       => 'packages',
				'type'    => 'select',
				'label'   => __( 'The shipment contains documents', 'bg-commerce-suite' ),
				'options' => array(
					''    => __( 'No', 'bg-commerce-suite' ),
					'yes' => __( 'Yes', 'bg-commerce-suite' ),
				),
			),
			'palletized'           => array(
				'group'       => 'packages',
				'type'    => 'select',
				'label'   => __( 'Palletized shipment', 'bg-commerce-suite' ),
				'options' => array(
					''    => __( 'No', 'bg-commerce-suite' ),
					'yes' => __( 'Yes', 'bg-commerce-suite' ),
				),
			),
		);
	}

	/**
	 * Raw per-order extra field (`_bgcs3_wb['x'][$key]`), '' when unset.
	 *
	 * @param array<string,mixed> $wb  Waybill overrides.
	 * @param string              $key Field key.
	 * @return string
	 */
	private function wbx( array $wb, $key ) {
		return ( isset( $wb['x'][ $key ] ) && is_scalar( $wb['x'][ $key ] ) ) ? trim( (string) $wb['x'][ $key ] ) : '';
	}

	/**
	 * Tri-state yes/no extra field: an explicit per-order „Да“/„Не“ wins,
	 * an empty value inherits the store setting.
	 *
	 * @param array<string,mixed> $wb          Waybill overrides.
	 * @param string              $key         Extra field key.
	 * @param string              $setting_key Setting to inherit from ('' = inherit to false).
	 * @return bool
	 */
	private function wbx_bool( array $wb, $key, $setting_key = '' ) {
		$value = $this->wbx( $wb, $key );
		if ( 'yes' === $value ) {
			return true;
		}
		if ( 'no' === $value ) {
			return false;
		}

		return '' !== $setting_key && 'yes' === bgcs3_get_option( self::ID, $setting_key, 'no' );
	}

	public function supported_delivery_types() {
		return array( 'office', 'locker', 'address' );
	}

	public function delivery_types() {
		$types = array();
		if ( 'no' !== Module_Settings::get( self::ID, 'show_office' ) ) {
			$types[] = 'office';
		}
		if ( 'no' !== Module_Settings::get( self::ID, 'show_locker' ) ) {
			$types[] = 'locker';
		}
		if ( 'no' !== Module_Settings::get( self::ID, 'show_address' ) ) {
			$types[] = 'address';
		}
		return $types;
	}

	public function client() {
		if ( null === $this->client ) {
			$this->client = new Client();
		}
		return $this->client;
	}

	public function locations() {
		if ( null === $this->locations ) {
			$this->locations = new Locations( $this->client() );
		}
		return $this->locations;
	}

	public function settings_tab() {
		return array(
			'id'    => self::ID,
			'title' => $this->name(),
			'group' => self::ID,
		);
	}

	public function settings_fields() {
		// Service list comes from Speedy /services. Until credentials are saved the
		// list is empty → fall back to a plain text field, then a dropdown appears.
		$services = $this->locations()->cached_services();
		if ( ! empty( $services ) ) {
			$service_field = array(
				'type'        => 'select',
				'label'       => __( 'Service', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => array( '' => __( '— Select a service —', 'bg-commerce-suite' ) ) + $services,
				'description' => __( 'Loaded from your Speedy profile (/services).', 'bg-commerce-suite' ),
			);
		} else {
			$service_field = array(
				'type'        => 'text',
				'label'       => __( 'Service ID', 'bg-commerce-suite' ),
				'default'     => '505',
				'description' => __( 'Enter the username/password and save — a service list will then appear.', 'bg-commerce-suite' ),
			);
		}

		// The contracted object identifies the sender. Handover separately controls
		// whether Speedy collects there or the merchant drops the parcel at an office.
		$contracts = $this->locations()->cached_contracts();
		$saved_client_id = (string) Module_Settings::get( self::ID, 'client_id' );
		if ( '' !== $saved_client_id && ! isset( $contracts[ $saved_client_id ] ) ) {
			$saved_client_label = (string) bgcs3_get_option( self::ID, 'client_label', '' );
			$contracts[ $saved_client_id ] = '' !== $saved_client_label
				? $saved_client_label
				: '#' . $saved_client_id . ' · ' . __( 'not synchronized', 'bg-commerce-suite' );
		}
		$client_field = array(
			'type'        => 'select',
			'label'       => __( 'Sender (contracted location)', 'bg-commerce-suite' ),
			'default'     => '',
			'options'     => array( '' => __( '— Select a contracted location —', 'bg-commerce-suite' ) ) + $contracts,
			'description' => __( 'Required for an accurate Speedy price. The selected contract object determines the sender identity and pickup address.', 'bg-commerce-suite' ),
			'searchable'  => true,
			'label_key'   => 'client_label',
		);

		$handover_field = array(
			'type'        => 'select',
			'label'       => __( 'Parcel handover', 'bg-commerce-suite' ),
			'default'     => 'address',
			'options'     => array(
				'address' => __( 'Courier pickup from contracted address', 'bg-commerce-suite' ),
				'office'  => __( 'Drop off at Speedy office', 'bg-commerce-suite' ),
			),
			'description' => __( 'The contract object remains the sender. An office changes only where the parcel is handed over.', 'bg-commerce-suite' ),
		);

		// Only offices explicitly accepting merchant drop-off are selectable.
		$all_offices = $this->client()->has_credentials() ? $this->locations()->cached_all_offices_options() : array();
		$saved_office_id = (string) bgcs3_get_option( self::ID, 'dropoff_office_id', '' );
		if ( '' !== $saved_office_id && ! isset( $all_offices[ $saved_office_id ] ) ) {
			$saved_office_label = (string) bgcs3_get_option( self::ID, 'dropoff_office_label', '' );
			$all_offices[ $saved_office_id ] = '' !== $saved_office_label
				? $saved_office_label
				: '#' . $saved_office_id . ' · ' . __( 'not synchronized', 'bg-commerce-suite' );
		}
		$dropoff_field = array(
			'type'        => 'select',
			'label'       => __( 'Drop-off office', 'bg-commerce-suite' ),
			'default'     => '',
			'options'     => array( '' => __( '— Select an office —', 'bg-commerce-suite' ) ) + $all_offices,
			'description' => __( 'Available only for offices where Speedy explicitly allows parcel drop-off.', 'bg-commerce-suite' ),
			'searchable'  => true,
			'label_key'   => 'dropoff_office_label',
			'show_if'     => array( 'sender_handover' => 'office' ),
		);

		// Return-shipment service id (OBP / voucher) reuses the services list.
		$return_service_field = ! empty( $services )
			? array( 'type' => 'select', 'options' => array( '' => __( '— Select a service —', 'bg-commerce-suite' ) ) + $services )
			: array( 'type' => 'text' );

		return array(
			'username'       => array(
				'type'        => 'text',
				'label'       => __( 'Username (Speedy)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'API username from your contracted Speedy profile.', 'bg-commerce-suite' ),
			),
			'password'       => array(
				'type'    => 'password',
				'label'   => __( 'Password (Speedy)', 'bg-commerce-suite' ),
				'default' => '',
			),
			'language'       => array(
				'type'    => 'select',
				'label'   => __( 'API language', 'bg-commerce-suite' ),
				'default' => 'BG',
				'options' => array(
					'BG' => 'BG',
					'EN' => 'EN',
				),
			),
			'service_id'        => $service_field,

			// -- Подател (sender) ---------------------------------------------
			'client_id'         => $client_field,
			'sender_handover'   => $handover_field,
			'dropoff_office_id' => $dropoff_field,

			// -- Видими типове доставка в checkout ------------------------------
			'show_office'       => array(
				'type'           => 'checkbox',
				'label'          => __( 'Office delivery', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Show “To office” at checkout', 'bg-commerce-suite' ),
				'default'        => 'yes',
			),
			'show_locker'       => array(
				'type'           => 'checkbox',
				'label'          => __( 'Delivery to locker (APT)', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Show “To locker” at checkout', 'bg-commerce-suite' ),
				'default'        => 'yes',
			),
			'locker_capacity_policy' => array(
				'type'        => 'select',
				'label'       => __( 'Behavior when the locker is full', 'bg-commerce-suite' ),
				'default'     => self::LOCKER_CAPACITY_POLICY_MERCHANT_INSTRUCTION,
				'options'     => array(
					self::LOCKER_CAPACITY_POLICY_MERCHANT_INSTRUCTION => __( 'Merchant instruction: hold at the sorting hub', 'bg-commerce-suite' ),
					self::LOCKER_CAPACITY_POLICY_SPEEDY_DEFAULT       => __( 'Speedy default behavior', 'bg-commerce-suite' ),
				),
				'description' => __( 'With the first option BGCS adds a waybill note to locker deliveries only. It is an instruction to Speedy, not a guarantee the API can enforce.', 'bg-commerce-suite' ),
				'show_if'     => array( 'show_locker' => 'yes' ),
			),
			'locker_capacity_note' => array(
				'type'        => 'text',
				'label'       => __( 'Note sent when the locker is full', 'bg-commerce-suite' ),
				'default'     => self::DEFAULT_LOCKER_CAPACITY_NOTE,
				'description' => __( 'Sent as shipmentNote, capped at 200 characters, when the order carries no waybill note of its own.', 'bg-commerce-suite' ),
				'show_if'     => array(
					'show_locker'            => 'yes',
					'locker_capacity_policy' => self::LOCKER_CAPACITY_POLICY_MERCHANT_INSTRUCTION,
				),
			),
			'show_address'      => array(
				'type'           => 'checkbox',
				'label'          => __( 'Address delivery', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Show “To address” at checkout', 'bg-commerce-suite' ),
				'default'        => 'yes',
				'description'    => __( 'If you disable all delivery types, Speedy will not appear as a shipping option at checkout.', 'bg-commerce-suite' ),
			),

			// -- Доставка и цени: платец на куриерската услуга ------------------
			'service_payer'     => array(
				'type'        => 'select',
				'label'       => __( 'Courier service payer', 'bg-commerce-suite' ),
				'default'     => 'SENDER',
				'options'     => array(
					'RECIPIENT'   => __( 'Recipient (Speedy collects the courier service)', 'bg-commerce-suite' ),
					'SENDER'      => __( 'Sender (the store pays Speedy)', 'bg-commerce-suite' ),
					'THIRD_PARTY' => __( 'Third party (by contract)', 'bg-commerce-suite' ),
				),
				'description' => __( 'With API pricing and cash on delivery, Recipient is valid: WooCommerce shows the Speedy price in the order, while BGCS removes the recipient-paid courier component from the COD amount so delivery is collected only once by Speedy. For prepaid orders the effective courier-service payer is Sender because the customer already paid the WooCommerce shipping line. With “Custom prices”, the payer remains Sender. This setting covers the courier service only — the declared-value insurance premium and the packaging fee are always billed to the sender, so the customer is never asked at the door for more than the order total.', 'bg-commerce-suite' ),
				'show_if'     => array( 'pricing_mode' => Pricing::MODE_API ),
			),
			'own_prices_sender_note' => array(
				'type'        => 'note',
				'default'     => '',
				'description' => __( 'With custom pricing, the shipment label is created at the sender\'s expense.', 'bg-commerce-suite' ),
				'show_if'     => array( 'pricing_mode' => Pricing::MODE_OWN ),
			),
			'third_party_client_id' => array(
				'type'        => 'text',
				'label'       => __( 'Third party: customer number', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Required when the payer is “Third party” (payment.thirdPartyClientId).', 'bg-commerce-suite' ),
				'show_if'     => array( 'pricing_mode' => Pricing::MODE_API, 'service_payer' => 'THIRD_PARTY' ),
			),

			// -- Наложен платеж --------------------------------------------------
			'cod_processing'    => array(
				'type'    => 'select',
				'label'   => __( 'Cash on delivery', 'bg-commerce-suite' ),
				'default' => 'CASH',
				'options' => array(
					'CASH'                 => __( 'Standard cash on delivery', 'bg-commerce-suite' ),
					'POSTAL_MONEY_TRANSFER' => __( 'Postal money transfer (PMT)', 'bg-commerce-suite' ),
				),
			),
			'cod_pmt_fee_payer' => array(
				'type'        => 'select',
				'label'       => __( 'PMT fee', 'bg-commerce-suite' ),
				'default'     => 'SENDER',
				'options'     => array(
					'SENDER'    => __( 'Use the fee from the Speedy contract', 'bg-commerce-suite' ),
					'RECIPIENT' => __( 'Do not add the fee to the customer price', 'bg-commerce-suite' ),
				),
				'description' => __( 'This setting does not change the payer in the Speedy API. When the first option is selected, BGCS adds the PMT component to the customer shipping price only when Speedy reports it as payable by the sender; otherwise the customer is never charged for it twice.', 'bg-commerce-suite' ),
				'show_if'     => array( 'cod_processing' => 'POSTAL_MONEY_TRANSFER' ),
			),
			'cod_pmt_percentage' => array(
				'type'        => 'text',
				'label'       => __( 'PMT fee percentage (%)', 'bg-commerce-suite' ),
				'default'     => '0.8',
				'description' => __( 'Percentage of the cash-on-delivery amount according to the contract (for example, 0.8 for 0.8%).', 'bg-commerce-suite' ),
				'show_if'     => array( 'cod_processing' => 'POSTAL_MONEY_TRANSFER', 'cod_pmt_fee_payer' => 'SENDER' ),
			),
			'cod_pmt_min_amount' => array(
				'type'        => 'text',
				'label'       => __( 'Minimum PMT fee', 'bg-commerce-suite' ),
				'default'     => '0.26',
				'description' => __( 'Minimum postal money transfer fee (for example, 0.26).', 'bg-commerce-suite' ),
				'show_if'     => array( 'cod_processing' => 'POSTAL_MONEY_TRANSFER', 'cod_pmt_fee_payer' => 'SENDER' ),
			),
			// TASK-S1 — `cod_pmt_on_free_shipping` used to live here. The rule now
			// covers the handling fee as well, so it moved to
			// `surcharges_on_free_shipping` below and is no longer rendered or read.
			// Its stored value stays in `bgcs3_speedy` for rollback and is NOT
			// carried over: it governed one fee, the new setting governs two, and
			// copying it forward charged a customer who had earned free shipping.
			// See Speedy::undo_free_shipping_carryover().

			// TASK-S1 — the handling and preparation of the shipment. Speedy
			// reports it as several components (`manualHandlingFee`, `fillInFee`,
			// `loadUnload`); the merchant asked for one fee, so BGCS sums them and
			// presents one. Charged like the PMT
			// fee: only under own/free pricing, because an API price already
			// contains it and adding it there would charge the customer twice.
			'handling_fee' => array(
				'type'        => 'text',
				'label'       => __( 'Handling and preparation fee', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Added to the customer\'s shipping price with custom pricing. Leave empty for none. Synchronising with Speedy fills this in from your contract — the synced value is used, and this field is what applies when Speedy has not priced the order (custom pricing, or the API is unavailable).', 'bg-commerce-suite' ),
			),
			'surcharges_on_free_shipping' => array(
				'type'           => 'checkbox',
				'label'          => __( 'Fees with free shipping', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Charge the PMT and handling fees even when shipping is free', 'bg-commerce-suite' ),
				'default'        => 'no',
				'description'    => __( 'Off by default: when a free-shipping rule applies, both fees stay with the sender and the customer pays nothing for delivery. Switch on to keep the transport free but still recover the fees.', 'bg-commerce-suite' ),
			),
			'administrative_fee' => array(
				'type'           => 'checkbox',
				'label'          => __( 'Administrative fee', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Apply the contractual administrative fee', 'bg-commerce-suite' ),
				'default'        => 'no',
				'description'    => __( 'A yes/no flag sent to Speedy (payment.administrativeFee), not an amount — the amount comes from the contract. Applied only when using the Speedy API price and payer “Recipient”. The actual Speedy breakdown is shown in the order.', 'bg-commerce-suite' ),
				'show_if'        => array( 'pricing_mode' => Pricing::MODE_API, 'service_payer' => 'RECIPIENT' ),
			),

			// -- Печат и опаковка ----------------------------------------------
			'print_paper_size'  => array(
				'type'    => 'select',
				'label'   => __( 'Label size (print)', 'bg-commerce-suite' ),
				'default' => 'A6',
				'options' => array(
					'A6'      => 'A6',
					'A4'      => 'A4',
					'A4_4xA6' => __( 'A4 with 4×A6', 'bg-commerce-suite' ),
				),
			),
			'default_package'   => array(
				'type'        => 'select',
				'label'       => __( 'Default packaging', 'bg-commerce-suite' ),
				'default'     => 'BOX',
				'options'     => $this->package_types(),
				'description' => __( 'Used when no other packaging is selected in the order.', 'bg-commerce-suite' ),
			),

			// -- Услуги / съдържание ------------------------------------------
			'declared_value'    => array(
				'type'           => 'checkbox',
				'label'          => __( 'Declared value', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Declare shipment value (insurance)', 'bg-commerce-suite' ),
				'default'        => 'no',
			),
			'fragile'           => array(
				'type'           => 'checkbox',
				'label'          => __( 'Fragile', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Mark as fragile (when declared value is used)', 'bg-commerce-suite' ),
				'default'        => 'no',
				'show_if'        => array( 'declared_value' => 'yes' ),
			),
			'saturday_delivery' => array(
				'type'           => 'checkbox',
				'label'          => __( 'Saturday delivery', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Request Saturday delivery', 'bg-commerce-suite' ),
				'default'        => 'no',
			),
			'deferred_days'     => array(
				'type'        => 'select',
				'label'       => __( 'Deferred delivery', 'bg-commerce-suite' ),
				'default'     => '0',
				'options'     => array(
					'0' => __( 'No delay', 'bg-commerce-suite' ),
					'1' => __( '1 business day', 'bg-commerce-suite' ),
					'2' => __( '2 business days', 'bg-commerce-suite' ),
				),
				'description' => __( 'Defers delivery by business days (service.deferredDays). Speedy allows 0, 1 or 2.', 'bg-commerce-suite' ),
			),
			'return_of_documents' => array(
				'type'           => 'checkbox',
				'label'          => __( 'Return receipt', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Request return of signed document to sender', 'bg-commerce-suite' ),
				'default'        => 'no',
			),

			// -- Преглед/тест преди плащане (OBP) -----------------------------
			'obp_option'        => array(
				'type'    => 'select',
				'label'   => __( 'Review/test before payment', 'bg-commerce-suite' ),
				'default' => 'NO',
				'options' => array(
					'NO'   => __( 'None', 'bg-commerce-suite' ),
					'OPEN' => __( 'Review (open before payment)', 'bg-commerce-suite' ),
					'TEST' => __( 'Review and test (before payment)', 'bg-commerce-suite' ),
				),
			),
			'obp_return_service_id' => array_merge(
				$return_service_field,
				array(
					'label'       => __( 'Review/test: return service', 'bg-commerce-suite' ),
					'default'     => '',
					'description' => __( 'Return-shipment service when the customer refuses after review/test. Loaded from Speedy.', 'bg-commerce-suite' ),
					'show_if'     => array( 'obp_option' => array( 'OPEN', 'TEST' ) ),
				)
			),
			'obp_return_payer'  => array(
				'type'    => 'select',
				'label'   => __( 'Review/test: return payer', 'bg-commerce-suite' ),
				'default' => 'SENDER',
				'options' => array(
					'SENDER'    => __( 'Sender', 'bg-commerce-suite' ),
					'RECIPIENT' => __( 'Recipient', 'bg-commerce-suite' ),
					'THIRD_PARTY' => __( 'Third party', 'bg-commerce-suite' ),
				),
				'show_if' => array( 'obp_option' => array( 'OPEN', 'TEST' ) ),
			),

			// -- Ваучер за връщане --------------------------------------------
			'return_voucher'    => array(
				'type'           => 'checkbox',
				'label'          => __( 'Return voucher', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Attach return-shipment voucher', 'bg-commerce-suite' ),
				'default'        => 'no',
			),
			'return_voucher_service_id' => array_merge(
				$return_service_field,
				array(
					'label'   => __( 'Voucher: return service', 'bg-commerce-suite' ),
					'default' => '',
					'show_if' => array( 'return_voucher' => 'yes' ),
				)
			),
			'return_voucher_payer' => array(
				'type'    => 'select',
				'label'   => __( 'Voucher: payer', 'bg-commerce-suite' ),
				'default' => 'SENDER',
				'options' => array(
					'SENDER'    => __( 'Sender', 'bg-commerce-suite' ),
					'RECIPIENT' => __( 'Recipient', 'bg-commerce-suite' ),
					'THIRD_PARTY' => __( 'Third party', 'bg-commerce-suite' ),
				),
				'show_if' => array( 'return_voucher' => 'yes' ),
			),
			'return_voucher_validity' => array(
				'type'        => 'text',
				'label'       => __( 'Voucher: validity (days)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Number of days the return voucher is valid (optional).', 'bg-commerce-suite' ),
				'show_if'     => array( 'return_voucher' => 'yes' ),
			),

			'default_width'     => array(
				'type'        => 'text',
				'label'       => __( 'Dimensions — width (cm)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Optional. Enter width/depth/height for volumetric pricing.', 'bg-commerce-suite' ),
			),
			'default_depth'     => array(
				'type'    => 'text',
				'label'   => __( 'Dimensions — depth (cm)', 'bg-commerce-suite' ),
				'default' => '',
			),
			'default_height'    => array(
				'type'    => 'text',
				'label'   => __( 'Dimensions — height (cm)', 'bg-commerce-suite' ),
				'default' => '',
			),
		);
	}

	/**
	 * Logical grouping of settings_fields() into cards (presentation only).
	 * Field keys are unchanged; any field not listed falls into an "Други" card.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	/**
	 * The normal screen has ~5 sections (Master Instruction §5): connection,
	 * sender, delivery/pricing, COD, automation (the last is Core-injected via
	 * Pricing::sections_for()/Tracking_Sync::sections_for()). Everything
	 * technical or rarely used is marked 'advanced' => true and Core groups
	 * every such section into one trailing "Разширени настройки" accordion
	 * (§33/§34) instead of scattering API-debug-panel fields across the main
	 * screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings_sections() {
		return array(
			// 1. Свързване със Speedy.
			array(
				'title'  => __( 'Connect to Speedy', 'bg-commerce-suite' ),
				'desc'   => __( 'Data from your contracted Speedy profile.', 'bg-commerce-suite' ),
				'icon'   => 'plug',
				'fields' => array( 'username', 'password' ),
			),
			// 2. Подател.
			array(
				'title'  => __( 'Sender', 'bg-commerce-suite' ),
				'desc'   => __( 'Whose name the shipment is sent under.', 'bg-commerce-suite' ),
				'icon'   => 'user',
				'fields' => array( 'client_id', 'sender_handover', 'dropoff_office_id' ),
			),
			// 3. Доставка и цени.
			array(
				'title'  => __( 'Shipping and pricing', 'bg-commerce-suite' ),
				'desc'   => __( 'Which delivery types are visible and who pays the courier service.', 'bg-commerce-suite' ),
				'icon'   => 'truck',
				'fields' => array( 'service_id', 'show_office', 'show_locker', 'show_address', 'locker_capacity_policy', 'locker_capacity_note', 'service_payer', 'own_prices_sender_note', 'third_party_client_id', 'administrative_fee' ),
			),
			// 4. Наложен платеж.
			array(
				'title'  => __( 'Cash on delivery', 'bg-commerce-suite' ),
				'desc'   => __( 'Standard cash on delivery or postal money transfer.', 'bg-commerce-suite' ),
				'icon'   => 'credit-card',
				'fields' => array( 'cod_processing', 'cod_pmt_fee_payer' ),
			),
			// TASK-S1 — fees the merchant recovers from the customer under custom
			// pricing. Not under „Cash on delivery“: the handling fee applies to a
			// prepaid order just as much.
			array(
				'title'  => __( 'Fees and surcharges', 'bg-commerce-suite' ),
				'desc'   => __( 'Recovered from the customer with custom pricing. An API-priced order already includes them.', 'bg-commerce-suite' ),
				'icon'   => 'credit-card',
				'fields' => array( 'handling_fee', 'surcharges_on_free_shipping' ),
			),
			// 6. Разширени настройки — grouped by Core into one trailing accordion.
			array(
				'title'    => __( 'API', 'bg-commerce-suite' ),
				'icon'     => 'plug',
				'advanced' => true,
				'fields'   => array( 'language' ),
			),
			array(
				'title'    => __( 'PMT — fallback calculation', 'bg-commerce-suite' ),
				'desc'     => __( 'Used only if Speedy does not return the fee in the response.', 'bg-commerce-suite' ),
				'icon'     => 'credit-card',
				'advanced' => true,
				'fields'   => array( 'cod_pmt_percentage', 'cod_pmt_min_amount' ),
			),
			array(
				'title'    => __( 'Additional services', 'bg-commerce-suite' ),
				'desc'     => __( 'Insurance, review/test, return voucher and Saturday delivery.', 'bg-commerce-suite' ),
				'icon'     => 'sliders',
				'advanced' => true,
				'fields'   => array( 'declared_value', 'fragile', 'saturday_delivery', 'deferred_days', 'obp_option', 'obp_return_service_id', 'obp_return_payer', 'return_voucher', 'return_voucher_service_id', 'return_voucher_payer', 'return_voucher_validity', 'return_of_documents' ),
			),
			array(
				'title'    => __( 'Dimensions, packaging and printing', 'bg-commerce-suite' ),
				'desc'     => __( 'Default shipment label values.', 'bg-commerce-suite' ),
				'icon'     => 'ruler',
				'advanced' => true,
				'fields'   => array( 'default_weight', 'default_width', 'default_depth', 'default_height', 'default_package', 'print_paper_size' ),
			),
		);
	}

	public function register( Container $container ) {
		Shipping_Hooks::init();
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
	}

	/**
	 * Sync: drop Speedy caches, then pre-warm services + contract clients so the
	 * settings dropdowns are populated immediately.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function sync_data() {
		$count = Cache::flush_courier( $this->id() );

		// Re-warm the lists the merchant configures from (best-effort).
		$services  = $this->locations()->services();
		$contracts = $this->locations()->contracts();
		$offices   = $this->locations()->all_offices_options();

		$pools = $this->locations()->replace_if_valid();

		// Единствен договорен клиент — избери го автоматично.
		if ( '' === (string) Module_Settings::get( self::ID, 'client_id' ) && 1 === count( $contracts ) ) {
			Options::set( self::ID, 'client_id', (string) key( $contracts ) );
			Options::set( self::ID, 'client_label', (string) reset( $contracts ) );
		}

		// TASK-S1 — the merchant's own terms with Speedy, so the settings screen can
		// show what the contract actually permits instead of the merchant guessing.
		$contract_info = $this->sync_contract_info();

		return $this->sync_result(
			$pools,
			array(
				'cache'           => $count,
				'services'        => count( $services ),
				'contracts'       => count( $contracts ),
				'dropoff_offices' => count( $offices ),
				'contract_terms'  => $contract_info ? 1 : 0,
			)
		);
	}

	/**
	 * Store the account's contract entitlements (`ContractInfo`).
	 *
	 * Booleans only — Speedy publishes no tariff endpoint, so the amounts behind
	 * these entitlements are observed from a calculation instead, by
	 * {@see record_observed_fees()}. See docs/speedy-fees-and-surcharges.md §3.
	 *
	 * @return bool Whether terms were stored.
	 */
	private function sync_contract_info() {
		$info = $this->client()->get_contract_info();
		if ( is_wp_error( $info ) || ! is_array( $info ) ) {
			// A contract read failing must not fail the whole sync: locations and
			// services are what the merchant is usually here for.
			return false;
		}

		$cod = isset( $info['cod'] ) && is_array( $info['cod'] ) ? $info['cod'] : array();

		// A field Speedy did not send is not a field Speedy said no to. Storing
		// null for an absent key keeps "never learned" apart from a real refusal,
		// so the panel can say which one it is instead of printing "not in your
		// contract" over a gap in the data.
		$flag = static function ( array $source, $key ) {
			return array_key_exists( $key, $source ) ? (bool) $source[ $key ] : null;
		};

		// `CODAdditionalServiceContractInfo.internationalCODAnnexes` — the
		// countries this account may collect cash on delivery in.
		$annexes = array();
		if ( isset( $cod['internationalCODAnnexes'] ) && is_array( $cod['internationalCODAnnexes'] ) ) {
			foreach ( $cod['internationalCODAnnexes'] as $annex ) {
				if ( is_array( $annex ) && ! empty( $annex['countryName'] ) ) {
					$annexes[] = sanitize_text_field( (string) $annex['countryName'] );
				}
			}
		}

		$special = isset( $info['specialDeliveryRequirements'] ) && is_array( $info['specialDeliveryRequirements'] )
			? $info['specialDeliveryRequirements']
			: array();

		Options::set(
			self::ID,
			'_contract_info',
			array(
				'contract_id'                => isset( $info['id'] ) ? (int) $info['id'] : 0,
				'money_transfer_allowed'     => $flag( $cod, 'moneyTransferAllowed' ),
				'has_cod_annex'              => $flag( $cod, 'hasCODAnnex' ),
				'cod_fiscal_receipt'         => $flag( $cod, 'codFiscalReceiptAllowed' ),
				'administrative_fee_allowed' => $flag( $info, 'administrativeFeeAllowed' ),
				'international_cod_annexes'  => $annexes,
				'special_delivery_required'  => $flag( $special, 'requiredForAllShipments' ),
				'special_delivery_count'     => isset( $special['requirements'] ) && is_array( $special['requirements'] )
					? count( $special['requirements'] )
					: 0,
				'synced_at'                  => time(),
			)
		);

		return true;
	}
	/**
	 * Remember what Speedy actually charged, per fee component.
	 *
	 * This is the only way to learn the contract rates: there is no tariff
	 * endpoint, so every real calculation doubles as an observation. The
	 * handling components are summed into the single fee the merchant asked for.
	 *
	 * @param array<string,mixed> $price A Speedy `ShipmentPrice` structure.
	 * @return void
	 */
	private function record_observed_fees( array $price ) {
		$details = isset( $price['details'] ) && is_array( $price['details'] ) ? $price['details'] : array();
		if ( empty( $details ) ) {
			return;
		}

		// The names come from `ShipmentPrice.details`, which has its OWN vocabulary
		// — it is not `ShipmentAmounts`. Confirmed against a live contract:
		// `details` carries `codPremium`, `manualHandlingFee`, `fillInFee` and
		// `loadUnload`, and has no `moneyTransferPremium`, `packings` or `tro` at
		// all. Reading the `ShipmentAmounts` spelling silently recorded nothing.
		//
		// `codPremium` is the cash-on-delivery premium under either processing
		// type: with POSTAL_MONEY_TRANSFER it arrives as a contract percentage
		// (observed 0.8% → 0.80 on 100.00), with CASH as a flat amount
		// (observed 2.00), which is exactly the distinction the merchant needs.
		$components = array(
			'pmt'      => array( 'codPremium' ),
			'handling' => array( 'manualHandlingFee', 'fillInFee', 'loadUnload' ),
		);

		$fees = array(
			'currency'  => isset( $price['currency'] ) ? strtoupper( sanitize_text_field( (string) $price['currency'] ) ) : '',
			'synced_at' => time(),
		);

		foreach ( $components as $name => $keys ) {
			$amount      = 0.0;
			$percent     = null;
			$vat_percent = null;
			$seen        = false;

			foreach ( $keys as $key ) {
				if ( ! isset( $details[ $key ] ) || ! is_array( $details[ $key ] ) ) {
					continue;
				}
				$seen    = true;
				$amount += isset( $details[ $key ]['amount'] ) ? (float) $details[ $key ]['amount'] : 0.0;
				if ( null === $percent && isset( $details[ $key ]['percent'] ) && (float) $details[ $key ]['percent'] > 0 ) {
					$percent = (float) $details[ $key ]['percent'];
				}
				if ( null === $vat_percent && isset( $details[ $key ]['vatPercent'] ) && is_numeric( $details[ $key ]['vatPercent'] ) ) {
					$vat_percent = max( 0.0, (float) $details[ $key ]['vatPercent'] );
				}
			}

			// Speedy sends every component it knows about, most of them zero. A
			// zero is not an observation: recording it would make "what Speedy
			// last charged" win over the merchant's own fee with the value 0,
			// silently switching off a fee they configured deliberately. The
			// sender's preparation fee exists even on a contract where Speedy
			// charges nothing for handling.
			// Speedy sends every component it knows about, most of them zero. A
			// zero is not an observation: recording it would make "what Speedy
			// last charged" win over the merchant's own fee with the value 0,
			// silently switching off a fee they configured deliberately. The
			// sender's preparation fee exists even on a contract where Speedy
			// charges nothing for handling.
			if ( $seen && ( $amount > 0 || ( null !== $percent && $percent > 0 ) ) ) {
				$fees[ $name ] = array(
					'amount'      => round( max( 0.0, $amount ), 2 ),
					'percent'     => $percent,
					'vat_percent' => $vat_percent,
				);
			}
		}

		if ( count( $fees ) > 2 ) {
			Options::set( self::ID, '_synced_fees', $fees );
		}
	}

	/**
	 * Undo 3.0.49's carry-over of the PMT-only free-shipping choice.
	 *
	 * 3.0.49 copied `cod_pmt_on_free_shipping` into
	 * `surcharges_on_free_shipping`, which reads as a faithful migration and is
	 * not: the old key governed the PMT fee ALONE, while the new one governs the
	 * PMT fee AND the handling fee. Copying a "yes" therefore extended a
	 * merchant's consent to a second charge they had never been asked about, and
	 * it charged it in the one case the rule exists to prevent — a customer who
	 * has earned free shipping being billed for delivery anyway.
	 *
	 * The correction removes the copied value rather than writing "no", so the
	 * field returns to genuinely unset and resolves through its declared default.
	 * A merchant who does want the fees recovered on free shipping turns the
	 * setting on once, against a description that now names both fees.
	 *
	 * Conservative on purpose: only a value identical to the legacy one is
	 * removed, so a choice made after upgrading is left alone. Erring toward not
	 * charging is the safe direction — the cost of being wrong here is a fee the
	 * merchant re-enables, not a customer overcharged at the door.
	 *
	 * @return bool Whether a carried-over value was removed.
	 */
	public static function undo_free_shipping_carryover() {
		$stored = get_option( 'bgcs3_' . self::ID, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( ! array_key_exists( 'surcharges_on_free_shipping', $stored )
			|| ! array_key_exists( 'cod_pmt_on_free_shipping', $stored ) ) {
			return false;
		}

		if ( (string) $stored['surcharges_on_free_shipping'] !== (string) $stored['cod_pmt_on_free_shipping'] ) {
			return false;
		}

		unset( $stored['surcharges_on_free_shipping'] );
		update_option( 'bgcs3_' . self::ID, $stored, false );

		return true;
	}

	/**
	 * Show the merchant what Speedy says their terms and rates actually are
	 * (TASK-S1).
	 *
	 * The point of the panel is that these numbers are not guesses: the
	 * entitlements come from `client/contract/info` and the rates are what Speedy
	 * charged on a real calculation. Where a rate is shown, the matching setting
	 * below it is the fallback, not the authority.
	 *
	 * @return void
	 */
	public function render_account_custom() {
		$contract = $this->contract_info();
		$fees     = $this->synced_fees();

		if ( empty( $contract ) && empty( $fees ) ) {
			echo '<p class="bgcs-help">' . esc_html__( 'Sync with Speedy to see the terms and fees your contract actually carries.', 'bg-commerce-suite' ) . '</p>';
			return;
		}

		if ( ! empty( $contract ) ) {
			$this->render_contract_terms( $contract );
		}

		if ( ! empty( $fees ) ) {
			$this->render_contract_fees( $fees );
		}

		$this->render_contract_conflicts( $contract );
	}

	/**
	 * How a single entitlement should read.
	 *
	 * Three states, not two. `null` means Speedy never told us — a sync that
	 * never ran, a read that failed, or a payload shaped differently. Printing
	 * that as "not in your contract" is the panel asserting something it does
	 * not know, which is worse than saying nothing.
	 *
	 * @param bool|null $value Stored entitlement.
	 * @return array{0:string,1:string} Label and tone.
	 */
	private function contract_term_state( $value ) {
		if ( null === $value ) {
			return array( __( 'not checked', 'bg-commerce-suite' ), 'warning' );
		}

		return $value
			? array( __( 'in your contract', 'bg-commerce-suite' ), 'success' )
			: array( __( 'not in your contract', 'bg-commerce-suite' ), 'neutral' );
	}

	/**
	 * The entitlements Speedy reports, each next to what it actually governs.
	 *
	 * @param array<string,mixed> $contract Stored contract info.
	 * @return void
	 */
	private function render_contract_terms( array $contract ) {
		echo '<h4>' . esc_html__( 'Your Speedy contract', 'bg-commerce-suite' ) . '</h4>';

		$synced      = isset( $contract['synced_at'] ) ? (int) $contract['synced_at'] : 0;
		$contract_id = isset( $contract['contract_id'] ) ? (int) $contract['contract_id'] : 0;

		$meta = array();
		if ( $contract_id > 0 ) {
			/* translators: %d: Speedy contract id. */
			$meta[] = sprintf( __( 'Contract %d', 'bg-commerce-suite' ), $contract_id );
		}
		if ( $synced > 0 ) {
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			$meta[] = sprintf( __( 'read from Speedy %s ago', 'bg-commerce-suite' ), human_time_diff( $synced ) );
		} else {
			$meta[] = __( 'never read from Speedy', 'bg-commerce-suite' );
		}
		echo '<p class="bgcs-sync-status">' . esc_html( implode( ' · ', $meta ) ) . '</p>';

		$terms = array(
			'money_transfer_allowed'     => array(
				__( 'Postal money transfer (PMT)', 'bg-commerce-suite' ),
				__( 'Lets cash on delivery be settled as a postal money transfer instead of cash.', 'bg-commerce-suite' ),
			),
			'has_cod_annex'              => array(
				__( 'Cash-on-delivery annex', 'bg-commerce-suite' ),
				__( 'The annex that permits collecting money from the recipient at all.', 'bg-commerce-suite' ),
			),
			'cod_fiscal_receipt'         => array(
				__( 'COD fiscal receipt', 'bg-commerce-suite' ),
				__( 'Whether Speedy may issue a fiscal receipt for the collected amount.', 'bg-commerce-suite' ),
			),
			'administrative_fee_allowed' => array(
				__( 'Administrative fee', 'bg-commerce-suite' ),
				__( 'Whether the contractual administrative fee may be charged on a shipment.', 'bg-commerce-suite' ),
			),
		);

		echo '<div class="bgcs-table-wrap"><table class="bgcs-table"><tbody>';
		foreach ( $terms as $key => $term ) {
			list( $label, $tone ) = $this->contract_term_state( array_key_exists( $key, $contract ) ? $contract[ $key ] : null );
			printf(
				'<tr><td><strong>%1$s</strong><br><span class="bgcs-field__desc">%2$s</span></td><td style="text-align:right"><span class="bgcs-contract__state bgcs-tone--%3$s">%4$s</span></td></tr>',
				esc_html( $term[0] ),
				esc_html( $term[1] ),
				esc_attr( $tone ),
				esc_html( $label )
			);
		}
		echo '</tbody></table></div>';

		$annexes = isset( $contract['international_cod_annexes'] ) && is_array( $contract['international_cod_annexes'] )
			? $contract['international_cod_annexes']
			: array();
		if ( $annexes ) {
			echo '<p class="bgcs-help">' . esc_html(
				sprintf(
					/* translators: %s: comma-separated country names. */
					__( 'International cash on delivery is available for: %s.', 'bg-commerce-suite' ),
					implode( ', ', $annexes )
				)
			) . '</p>';
		}

		$special_count = isset( $contract['special_delivery_count'] ) ? (int) $contract['special_delivery_count'] : 0;
		if ( $special_count > 0 ) {
			$all = ! empty( $contract['special_delivery_required'] );
			echo '<p class="bgcs-help">' . esc_html(
				$all
					/* translators: %d: number of special delivery requirements. */
					? sprintf( __( 'Your contract carries %d special delivery requirement(s), applied to every shipment.', 'bg-commerce-suite' ), $special_count )
					/* translators: %d: number of special delivery requirements. */
					: sprintf( __( 'Your contract carries %d special delivery requirement(s).', 'bg-commerce-suite' ), $special_count )
			) . '</p>';
		}
	}

	/**
	 * What Speedy last charged, per component.
	 *
	 * @param array<string,mixed> $fees Stored observed fees.
	 * @return void
	 */
	private function render_contract_fees( array $fees ) {
		// `codPremium` is the COD premium under EITHER processing type — the PMT
		// fee with POSTAL_MONEY_TRANSFER, the cash fee with CASH. Labelling it
		// "postal money transfer" regardless told a shop on cash processing that
		// it was being charged for a service its contract does not even carry.
		$processing = (string) Module_Settings::get( self::ID, 'cod_processing' );
		$cod_label  = 'POSTAL_MONEY_TRANSFER' === $processing
			? __( 'Postal money transfer fee', 'bg-commerce-suite' )
			: __( 'Cash-on-delivery premium', 'bg-commerce-suite' );

		$labels = array(
			'pmt'      => $cod_label,
			'handling' => __( 'Handling and preparation', 'bg-commerce-suite' ),
		);

		$rows = array();
		foreach ( $labels as $key => $label ) {
			if ( empty( $fees[ $key ] ) || ! is_array( $fees[ $key ] ) ) {
				continue;
			}
			$rows[ $key ] = array( $label, $fees[ $key ] );
		}

		if ( ! $rows ) {
			return;
		}

		echo '<h4>' . esc_html__( 'Fees Speedy last charged', 'bg-commerce-suite' ) . '</h4>';

		$currency = isset( $fees['currency'] ) ? (string) $fees['currency'] : '';
		$synced   = isset( $fees['synced_at'] ) ? (int) $fees['synced_at'] : 0;
		if ( $synced > 0 ) {
			echo '<p class="bgcs-sync-status">' . esc_html(
				sprintf(
					/* translators: %s: human-readable time difference, e.g. "2 hours". */
					__( 'Observed on a real calculation %s ago', 'bg-commerce-suite' ),
					human_time_diff( $synced )
				)
			) . '</p>';
		}

		echo '<div class="bgcs-table-wrap"><table class="bgcs-table"><tbody>';
		foreach ( $rows as $row ) {
			$amount  = isset( $row[1]['amount'] ) ? (float) $row[1]['amount'] : 0.0;
			$percent = isset( $row[1]['percent'] ) ? (float) $row[1]['percent'] : 0.0;

			$value = number_format( $amount, 2, '.', '' ) . ( '' !== $currency ? ' ' . $currency : '' );
			if ( $percent > 0 ) {
				/* translators: %s: contract percentage. */
				$value .= ' ' . sprintf( __( '(%s%% by contract)', 'bg-commerce-suite' ), rtrim( rtrim( number_format( $percent, 2, '.', '' ), '0' ), '.' ) );
			}

			printf(
				'<tr><td><strong>%1$s</strong></td><td style="text-align:right">%2$s</td></tr>',
				esc_html( $row[0] ),
				esc_html( $value )
			);
		}
		echo '</tbody></table></div>';

		echo '<p class="bgcs-help">' . esc_html__( 'Speedy publishes no tariff list, so these are what it charged on the most recent priced order. They are used with custom pricing; the fields under “Fees and surcharges” apply when there is nothing to go on.', 'bg-commerce-suite' ) . '</p>';
	}

	/**
	 * A setting the contract cannot honour is a shipment that fails later, or a
	 * switch wired to nothing. Say so here rather than at that point.
	 *
	 * @param array<string,mixed> $contract Stored contract info.
	 * @return void
	 */
	private function render_contract_conflicts( array $contract ) {
		$pmt = array_key_exists( 'money_transfer_allowed', $contract ) ? $contract['money_transfer_allowed'] : null;
		if ( false === $pmt && 'POSTAL_MONEY_TRANSFER' === (string) Module_Settings::get( self::ID, 'cod_processing' ) ) {
			echo '<div class="bgcs-alert bgcs-alert--warning"><div>' . esc_html__( 'Cash on delivery is set to postal money transfer, but your Speedy contract does not include it. Ask Speedy to add it, or switch the COD processing type.', 'bg-commerce-suite' ) . '</div></div>';
		}

		// The administrative fee is a flag, not an amount: switching it on when
		// the contract does not carry it changes no price and reports no error,
		// which reads as a broken setting.
		$admin = array_key_exists( 'administrative_fee_allowed', $contract ) ? $contract['administrative_fee_allowed'] : null;
		if ( false === $admin && 'yes' === Module_Settings::get( self::ID, 'administrative_fee' ) ) {
			echo '<div class="bgcs-alert bgcs-alert--warning"><div>' . esc_html__( 'The administrative fee is switched on, but your Speedy contract does not carry it. Speedy will ignore the flag and the delivery price will not change.', 'bg-commerce-suite' ) . '</div></div>';
		}
	}
	/** The account's contract entitlements as last synced. */
	public function contract_info() {
		$info = bgcs3_get_option( self::ID, '_contract_info', array() );
		return is_array( $info ) ? $info : array();
	}

	/** Fee components as Speedy last reported them. */
	public function synced_fees() {
		$fees = bgcs3_get_option( self::ID, '_synced_fees', array() );
		return is_array( $fees ) ? $fees : array();
	}

	/**
	 * Whether Speedy username/password are saved. Generic UI (Core Settings_Page)
	 * uses this — not client()->has_credentials() directly — to keep Core free
	 * of any Speedy-specific coupling.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return $this->client()->has_credentials();
	}

	/**
	 * Speedy exposes one fixed production API endpoint; there is no generic env
	 * setting to infer and no hostname rule belongs in Core preflight.
	 *
	 * @return string
	 */
	public function preflight_environment() {
		return 'production';
	}

	/**
	 * "Свържи със Speedy" / "Провери връзката" (§6): validates the saved
	 * credentials with one non-destructive `client/contract` call, caches a
	 * health snapshot for setup_status(), and returns a merchant-facing
	 * Bulgarian result. Never creates a shipment, never logs/echoes secrets.
	 *
	 * @return Sync_Result
	 */
	public function check_connection() {
		$result = $this->client()->validate_credentials();

		if ( is_wp_error( $result ) ) {
			Options::set(
				self::ID,
				'_api_health',
				array(
					'ok' => false,
					'at' => time(),
				)
			);
			return Sync_Result::error( __( 'Speedy did not accept the entered credentials.', 'bg-commerce-suite' ), array( $result->get_error_message() ) );
		}

		$clients = isset( $result['clients'] ) && is_array( $result['clients'] ) ? $result['clients'] : array();

		Options::set(
			self::ID,
			'_api_health',
			array(
				'ok' => true,
				'at' => time(),
			)
		);

		// The same response drives the settings dropdown. Do not discard it and
		// force a second API call when the merchant already has a selected client.
		$contracts = $this->locations()->cache_contract_clients( $clients );
		if ( '' === (string) Module_Settings::get( self::ID, 'client_id' ) && 1 === count( $contracts ) ) {
			Options::set( self::ID, 'client_id', (string) key( $contracts ) );
			Options::set( self::ID, 'client_label', (string) reset( $contracts ) );
		}

		return Sync_Result::success(
			__( 'The Speedy connection is successful.', 'bg-commerce-suite' ),
			array( 'contracts' => count( $clients ) )
		);
	}

	/**
	 * "SPEEDY STATUS" (§9): six real checks, not a decorative checklist.
	 *
	 * @return array<int,array{id:string,label:string,state:string,hint:string}>
	 */
	public function setup_status() {
		$rows = array();

		// 1. API — backed by the cached result of check_connection(), not just
		// "credentials are non-empty".
		if ( $this->client()->has_credentials() ) {
			$health = (array) bgcs3_get_option( self::ID, '_api_health', array() );
			if ( array_key_exists( 'ok', $health ) && true === $health['ok'] ) {
				$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_OK );
			} elseif ( array_key_exists( 'ok', $health ) && false === $health['ok'] ) {
				$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_FAIL, __( 'The last connection check failed.', 'bg-commerce-suite' ) );
			} else {
				$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_WARN, __( 'The credentials are entered, but the connection has not been checked yet — click “Check connection”.', 'bg-commerce-suite' ) );
			}
		} else {
			$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_FAIL, __( 'Speedy username/password is missing.', 'bg-commerce-suite' ) );
		}

		// 2. Sender — a contract object is always required; office handover adds
		// a drop-off office but never replaces the contract sender.
		$client_id       = (string) Module_Settings::get( self::ID, 'client_id' );
		$handover        = (string) Module_Settings::get( self::ID, 'sender_handover' );
		$office_id       = (string) bgcs3_get_option( self::ID, 'dropoff_office_id', '' );
		$contracts       = $this->locations()->cached_contracts();
		$offices         = $this->locations()->cached_all_offices_options();
		$has_sender      = '' !== $client_id && isset( $contracts[ $client_id ] );
		$has_sender      = $has_sender && ( 'office' !== $handover || ( '' !== $office_id && isset( $offices[ $office_id ] ) ) );
		$rows[] = Setup_Status::row(
			'sender',
			__( 'Sender', 'bg-commerce-suite' ),
			$has_sender ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL,
			$has_sender ? '' : __( 'Select a synchronized contract object and, for office handover, a valid drop-off office.', 'bg-commerce-suite' )
		);

		// 3. Locations — a valid snapshot exists (offices or lockers).
		$has_locations = Office_Store::has( self::ID, 'office' ) || Office_Store::has( self::ID, 'locker' );
		$rows[] = Setup_Status::row(
			'locations',
			__( 'Offices and lockers', 'bg-commerce-suite' ),
			$has_locations ? Setup_Status::STATE_OK : Setup_Status::STATE_WARN,
			$has_locations ? '' : __( 'No locations have been synchronized yet — click “Sync directories”.', 'bg-commerce-suite' )
		);

		// 4. Pricing — API mode is always ready; own prices needs >=1 active rule.
		if ( Pricing::MODE_OWN === Pricing::mode( self::ID ) ) {
			$pricing_ok = Pricing::has_active_rules( self::ID );
			$rows[]     = Setup_Status::row(
				'pricing',
				__( 'Pricing', 'bg-commerce-suite' ),
				$pricing_ok ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL,
				$pricing_ok ? '' : __( 'Add at least one rule under “Custom prices”.', 'bg-commerce-suite' )
			);
		} else {
			$rows[] = Setup_Status::row( 'pricing', __( 'Pricing', 'bg-commerce-suite' ), Setup_Status::STATE_OK );
		}

		// 5. COD — a recognized processing type is configured.
		$processing = (string) Module_Settings::get( self::ID, 'cod_processing' );
		$rows[]     = Setup_Status::row(
			'cod',
			__( 'Cash on delivery', 'bg-commerce-suite' ),
			in_array( $processing, array( 'CASH', 'POSTAL_MONEY_TRANSFER' ), true ) ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL
		);

		// 6. Checkout availability — the module and at least one delivery type.
		$delivery_ok = $this->is_enabled() && ! empty( $this->delivery_types() );
		$rows[] = Setup_Status::row(
			'method',
			__( 'Delivery types', 'bg-commerce-suite' ),
			$delivery_ok ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL,
			$delivery_ok ? '' : __( 'Enable the module and at least one delivery type for checkout.', 'bg-commerce-suite' )
		);

		return $rows;
	}

	public function supports_sender_refresh() {
		return true;
	}

	public function sender_refresh_label() {
		return __( 'Update sender details from Speedy', 'bg-commerce-suite' );
	}

	public function refresh_sender_data() {
		$client_id = (string) Module_Settings::get( self::ID, 'client_id' );
		$handover  = (string) Module_Settings::get( self::ID, 'sender_handover' );
		$office_id = (string) bgcs3_get_option( self::ID, 'dropoff_office_id', '' );
		$contracts = $this->locations()->refresh_contracts();
		if ( '' === $client_id || ! isset( $contracts[ $client_id ] ) ) {
			return Sync_Result::error( __( 'Select a valid contracted sender object.', 'bg-commerce-suite' ) );
		}

		$office_label = '';
		if ( 'office' === $handover ) {
			$offices = $this->locations()->refresh_all_offices_options();
			if ( '' === $office_id || ! isset( $offices[ $office_id ] ) ) {
				return Sync_Result::error( __( 'Select a valid Speedy drop-off office.', 'bg-commerce-suite' ) );
			}
			$office_label = $offices[ $office_id ];
		}

		Options::set( self::ID, 'client_label', $contracts[ $client_id ] );
		$updated = array( 'client_label' );
		if ( '' !== $office_label ) {
			Options::set( self::ID, 'dropoff_office_label', $office_label );
			$updated[] = 'dropoff_office_label';
		}

		return Sync_Result::success( __( 'The Speedy sender origin is confirmed.', 'bg-commerce-suite' ), array(), $updated );
	}

	public function admin_location_search( $resource, array $args ) {
		if ( 'cities' === $resource ) {
			return $this->locations()->cities( isset( $args['query'] ) ? $args['query'] : '', 'BG' );
		}
		if ( 'streets' === $resource ) {
			return $this->locations()->streets(
				isset( $args['city_id'] ) ? $args['city_id'] : '',
				isset( $args['query'] ) ? $args['query'] : ''
			);
		}
		if ( 'offices' === $resource ) {
			return $this->search_stored_locations( isset( $args['type'] ) && 'locker' === $args['type'] ? 'locker' : 'office', isset( $args['query'] ) ? $args['query'] : '' );
		}
		return parent::admin_location_search( $resource, $args );
	}

	/**
	 * @param array<string,string> $methods Methods.
	 * @return array<string,string>
	 */
	public function register_shipping_method( $methods ) {
		$methods[ 'bgcs3_' . self::ID ] = Shipping_Method::class;
		return $methods;
	}

	/**
	 * Computes the deterministic PMT base amount from package, cart/order and base shipping cost.
	 * Explicitly excludes the PMT surcharge itself to prevent circular dependencies and instability.
	 *
	 * PMT_BASE = items_subtotal + taxes + non-PMT fees - discounts + base_shipping
	 *
	 * @param array<string,mixed> $package   WC shipping package.
	 * @param float               $base_cost Base shipping cost.
	 * @return float
	 */
	public function resolve_pmt_base( array $package, $base_cost = 0.0 ) {
		$pmt_base  = 0.0;
		$base_cost = (float) $base_cost;

		if ( function_exists( 'WC' ) && WC()->cart ) {
			$cart = WC()->cart;

			// Merchandise gross after discounts. Woo keeps discount totals excluding
			// tax and discount-tax separately, so subtract both from gross subtotal.
			$cart_subtotal = method_exists( $cart, 'get_subtotal' ) ? (float) $cart->get_subtotal() : ( method_exists( $cart, 'get_displayed_subtotal' ) ? (float) $cart->get_displayed_subtotal() : 0.0 );
			$cart_tax      = method_exists( $cart, 'get_subtotal_tax' ) ? (float) $cart->get_subtotal_tax() : 0.0;
			$discount      = ( method_exists( $cart, 'get_discount_total' ) ? (float) $cart->get_discount_total() : 0.0 ) + ( method_exists( $cart, 'get_discount_tax' ) ? (float) $cart->get_discount_tax() : 0.0 );

			if ( $cart_subtotal <= 0 && method_exists( $cart, 'get_total' ) ) {
				$cart_subtotal = (float) $cart->get_total( 'edit' );
			}

			$merchandise = max( 0.0, $cart_subtotal + $cart_tax - $discount );
			$fees_total  = $this->current_cycle_fee_gross( $cart, $base_cost );
			$pmt_base    = $merchandise + $fees_total + $base_cost;
		} elseif ( ! empty( $package['contents_cost'] ) ) {
			$pmt_base = (float) $package['contents_cost'] + $base_cost;
		}

		return round( max( 0.0, $pmt_base ), 2 );
	}

	/**
	 * Resolve positive Woo fees for the current totals cycle without recursively
	 * calling calculate_totals(). Woo normally triggers fee callbacks after the
	 * shipping phase, so reading get_fee_total() here can otherwise reuse stale
	 * values from a previous AJAX cycle. We temporarily run only the Fees API
	 * callbacks against the known base-shipping amount, inspect the fee objects,
	 * then restore the cart state.
	 *
	 * @param object $cart      WC_Cart-like object.
	 * @param float  $base_cost Gross base shipping amount known by BGCS.
	 * @return float Gross fee amount.
	 */
	private function current_cycle_fee_gross( $cart, $base_cost ) {
		$fallback = ( method_exists( $cart, 'get_fee_total' ) ? (float) $cart->get_fee_total() : 0.0 )
			+ ( method_exists( $cart, 'get_fee_tax' ) ? (float) $cart->get_fee_tax() : 0.0 );

		if ( $this->resolving_pmt_fees
			|| ! method_exists( $cart, 'fees_api' )
			|| ! method_exists( $cart, 'calculate_fees' )
			|| ! method_exists( $cart, 'get_fees' ) ) {
			return round( $fallback, 2 );
		}

		$fees_api = $cart->fees_api();
		if ( ! is_object( $fees_api )
			|| ! method_exists( $fees_api, 'get_fees' )
			|| ! method_exists( $fees_api, 'remove_all_fees' )
			|| ! method_exists( $fees_api, 'set_fees' ) ) {
			return round( $fallback, 2 );
		}

		$original_fees          = $fees_api->get_fees();
		$original_shipping      = method_exists( $cart, 'get_shipping_total' ) ? $cart->get_shipping_total() : null;
		$original_shipping_tax  = method_exists( $cart, 'get_shipping_tax' ) ? $cart->get_shipping_tax() : null;
		$original_shipping_taxes = method_exists( $cart, 'get_shipping_taxes' ) ? $cart->get_shipping_taxes() : null;

		$this->resolving_pmt_fees = true;
		try {
			$fees_api->remove_all_fees();

			// Fee callbacks are allowed to inspect the shipping amount. Mirror the
			// gross->net decomposition used by Core so they see the current base rate.
			$shipping_net   = (float) $base_cost;
			$shipping_taxes = array();
			if ( $base_cost > 0 && class_exists( '\\WC_Tax' ) && function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() ) {
				$rates = \WC_Tax::get_shipping_tax_rates();
				if ( ! empty( $rates ) ) {
					$shipping_taxes = \WC_Tax::calc_inclusive_tax( $base_cost, $rates );
					$shipping_net   = $base_cost - array_sum( $shipping_taxes );
				}
			}
			if ( method_exists( $cart, 'set_shipping_total' ) ) {
				$cart->set_shipping_total( $shipping_net );
			}
			if ( method_exists( $cart, 'set_shipping_tax' ) ) {
				$cart->set_shipping_tax( array_sum( $shipping_taxes ) );
			}
			if ( method_exists( $cart, 'set_shipping_taxes' ) ) {
				$cart->set_shipping_taxes( $shipping_taxes );
			}

			$cart->calculate_fees();
			$fees  = (array) $cart->get_fees();
			$gross = 0.0;

			foreach ( $fees as $fee ) {
				if ( ! is_object( $fee ) ) {
					continue;
				}
				$amount = isset( $fee->amount ) ? (float) $fee->amount : ( isset( $fee->total ) ? (float) $fee->total : 0.0 );
				$tax    = 0.0;

				if ( $amount > 0 && ! empty( $fee->taxable ) && class_exists( '\\WC_Tax' ) ) {
					$tax_class = isset( $fee->tax_class ) ? (string) $fee->tax_class : '';
					$customer  = method_exists( $cart, 'get_customer' ) ? $cart->get_customer() : null;
					$rates     = \WC_Tax::get_rates( $tax_class, $customer );
					$tax       = array_sum( \WC_Tax::calc_tax( $amount, $rates, false ) );
				} elseif ( isset( $fee->tax ) && is_numeric( $fee->tax ) ) {
					$tax = (float) $fee->tax;
				}

				$gross += $amount + $tax;
			}

			return round( (float) apply_filters( 'bgcs3_speedy_pmt_fee_gross', $gross, $fees, $cart, $base_cost ), 2 );
		} catch ( \Throwable $e ) {
			// A third-party fee callback must never make Speedy shipping fatal. Fall
			// back to the last Woo totals, and leave live API/preflight to catch a
			// material mismatch before the waybill is created.
			return round( $fallback, 2 );
		} finally {
			$fees_api->set_fees( is_array( $original_fees ) ? $original_fees : array() );
			if ( null !== $original_shipping && method_exists( $cart, 'set_shipping_total' ) ) {
				$cart->set_shipping_total( $original_shipping );
			}
			if ( null !== $original_shipping_tax && method_exists( $cart, 'set_shipping_tax' ) ) {
				$cart->set_shipping_tax( $original_shipping_tax );
			}
			if ( null !== $original_shipping_taxes && method_exists( $cart, 'set_shipping_taxes' ) ) {
				$cart->set_shipping_taxes( $original_shipping_taxes );
			}
			$this->resolving_pmt_fees = false;
		}
	}

	/**
	 * Resolves the Postal Money Transfer (PMT / ППП) surcharge for a COD base amount.
	 * Contract formula: configured percentage (default 0.8%), minimum configured amount (default 0.26).
	 *
	 * @param float          $cod_base  COD base amount.
	 * @param Selection|null $selection Selection.
	 * @return float
	 */
	public function pmt_amount_for( $cod_base, $selection = null ) {
		$charge = $this->pmt_charge_for( $cod_base, 0.0, 0.0, $selection );

		return $charge['amount'];
	}

	/**
	 * Resolve one explainable PMT amount from the configured contract floor and
	 * the amount Speedy already included in a live quote.
	 *
	 * `amount` is the final customer-recovered PMT component. `additional_amount`
	 * is the only amount that may be added on top of the quoted courier total.
	 * Keeping both prevents the API component from being charged twice.
	 *
	 * @param float          $cod_base        PMT base excluding the PMT component.
	 * @param float          $api_amount      Current or base-adjusted API component.
	 * @param float          $included_amount Part of $api_amount already present in the courier quote.
	 * @param Selection|null $selection       Selection, reserved for compatible filters.
	 * @return array{amount:float,additional_amount:float,included_amount:float,base:float,source:string,percentage:float,minimum:float}
	 */
	public function pmt_charge_for( $cod_base, $api_amount = 0.0, $included_amount = 0.0, $selection = null ) {
		$cod_base        = round( max( 0.0, (float) $cod_base ), 2 );
		$api_amount      = round( max( 0.0, (float) $api_amount ), 2 );
		$included_amount = round( min( $api_amount, max( 0.0, (float) $included_amount ) ), 2 );

		$pct_raw = str_replace( ',', '.', trim( (string) Module_Settings::get( self::ID, 'cod_pmt_percentage' ) ) );
		$min_raw = str_replace( ',', '.', trim( (string) Module_Settings::get( self::ID, 'cod_pmt_min_amount' ) ) );
		$pct     = max( 0.0, is_numeric( $pct_raw ) ? (float) $pct_raw : 0.8 );
		$minimum = max( 0.0, is_numeric( $min_raw ) ? (float) $min_raw : 0.26 );

		if ( $cod_base <= 0 ) {
			return array(
				'amount'            => 0.0,
				'additional_amount' => 0.0,
				'included_amount'   => $included_amount,
				'base'              => 0.0,
				'source'            => 'formula',
				'percentage'        => $pct,
				'minimum'           => $minimum,
			);
		}

		$formula = round( $cod_base * ( $pct / 100 ), 2 );
		$amount  = max( $minimum, $formula, $api_amount );
		$source  = 'formula';
		if ( $api_amount > 0 && $api_amount >= $formula && $api_amount >= $minimum ) {
			$source = 'api';
		} elseif ( $minimum >= $formula ) {
			$source = 'minimum';
		}

		unset( $selection );

		return array(
			'amount'            => round( $amount, 2 ),
			'additional_amount' => round( max( 0.0, $amount - $included_amount ), 2 ),
			'included_amount'   => $included_amount,
			'base'              => $cod_base,
			'source'            => $source,
			'percentage'        => $pct,
			'minimum'           => round( $minimum, 2 ),
		);
	}

	/**
	 * Resolve the sender-paid PMT surcharge without allowing the Speedy response
	 * to bypass the merchant's configured percentage/minimum contract floor.
	 *
	 * @param float          $cod_base        COD base amount.
	 * @param float          $reported_amount Sender-paid amount reported by Speedy.
	 * @param Selection|null $selection       Selection.
	 * @return float
	 */
	public function sender_pmt_amount_for( $cod_base, $reported_amount = 0.0, $selection = null ) {
		$charge = $this->pmt_charge_for( $cod_base, $reported_amount, 0.0, $selection );

		return $charge['amount'];
	}

	/**
	 * Attempts to fetch the contract moneyTransfer component from Speedy /calculate.
	 * Amount and payer are kept together so RECIPIENT/THIRD_PARTY are never
	 * accidentally treated as merchant-paid SENDER fees.
	 *
	 * @param array<string,mixed> $package   WC package.
	 * @param Selection           $selection Customer selection.
	 * @param float               $pmt_base  Deterministic PMT base.
	 * @return array{amount:float,payer:string,source:string}|null
	 */
	private function fetch_contract_pmt_info( array $package, Selection $selection, $pmt_base ) {
		$service_id = (string) bgcs3_get_option( self::ID, 'service_id', '' );
		$sender     = $this->sender();

		if ( '' === $service_id || empty( $sender ) || ! $this->client()->has_credentials() ) {
			return null;
		}

		$service = array(
			'serviceIds'           => array( (int) $service_id ),
			'autoAdjustPickupDate' => true,
			'additionalServices'   => array(
				'cod' => array(
					'amount'         => (float) $pmt_base,
					'processingType' => 'POSTAL_MONEY_TRANSFER',
				),
			),
		);

		$body = array(
			'sender'    => $sender,
			'recipient' => $this->recipient( $selection, array( 'privatePerson' => true ), false ),
			'service'   => $service,
			'content'   => $this->content_block( $this->package_weight( $package ), $package ),
			'payment'   => $this->payment_block( $this->payer( array(), true ) ),
		);

		$response = $this->client()->calculate( $body );
		if ( is_wp_error( $response ) || empty( $response['calculations'][0]['price']['returnAmounts']['moneyTransfer'] ) ) {
			return null;
		}

		$price_currency = isset( $response['calculations'][0]['price']['currency'] ) ? strtoupper( (string) $response['calculations'][0]['price']['currency'] ) : '';
		$store_currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		if ( '' !== $price_currency && '' !== $store_currency && $price_currency !== $store_currency ) {
			// Never apply a returned monetary component in another currency without
			// an explicit FX mechanism. The configured local PMT formula remains the
			// safe fallback for explicit static contract pricing.
			return null;
		}

		$mt    = $response['calculations'][0]['price']['returnAmounts']['moneyTransfer'];
		$payer = isset( $mt['payer'] ) ? strtoupper( (string) $mt['payer'] ) : '';
		if ( ! in_array( $payer, array( 'SENDER', 'RECIPIENT', 'THIRD_PARTY' ), true ) ) {
			$payer = 'UNKNOWN';
		}

		return array(
			'amount' => isset( $mt['amount'] ) ? max( 0.0, (float) $mt['amount'] ) : 0.0,
			'payer'  => $payer,
			'source' => 'api',
		);
	}

	/**
	 * Calculate courier-specific surcharges (e.g. PMT recovery) for a package + selection.
	 *
	 * @param array<string,mixed> $package   WC shipping package.
	 * @param Selection           $selection Customer selection.
	 * @param float               $base_cost Base shipping cost.
	 * @return array<string,mixed>
	 */
	public function calculate_surcharges( array $package, Selection $selection, $base_cost = 0.0 ) {
		$surcharges = array();

		// TASK-S1 — the handling and preparation of the shipment is charged
		// whether or not the order is cash on delivery, so it is resolved before
		// the COD gate below.
		$handling = $this->handling_surcharge();
		if ( null !== $handling ) {
			$surcharges['handling'] = $handling;
		}

		if ( ! Cod::is_chosen() ) {
			return $surcharges;
		}

		$processing = (string) Module_Settings::get( self::ID, 'cod_processing' );
		$fee_payer  = (string) Module_Settings::get( self::ID, 'cod_pmt_fee_payer' );

		if ( 'POSTAL_MONEY_TRANSFER' !== $processing || 'SENDER' !== $fee_payer ) {
			return $surcharges;
		}

		$pmt_base = $this->resolve_pmt_base( $package, $base_cost );
		if ( $pmt_base <= 0 ) {
			return $surcharges;
		}

		// The merchant-facing setting means exactly this: add the configured
		// contractual PMT fee to the customer price. The deterministic formula is
		// authoritative here and must not disappear merely because a Speedy
		// calculation reports a different return-amount payer. This keeps checkout
		// behaviour consistent for API and custom pricing:
		// fee = max(configured minimum, PMT base * configured percentage).
		$observed   = $this->synced_pmt_for_base( $pmt_base );
		$api_amount = is_array( $observed ) ? (float) $observed['amount'] : 0.0;
		$pmt_charge = $this->pmt_charge_for( $pmt_base, $api_amount, 0.0, $selection );
		$pmt_amount = $pmt_charge['amount'];

		if ( $pmt_amount > 0 ) {
			$surcharges['pmt'] = array_merge(
				$pmt_charge,
				array(
					'name'           => 'pmt',
					'label'          => __( 'Postal money transfer fee', 'bg-commerce-suite' ),
					'payer'          => 'SENDER',
					'api_percentage' => is_array( $observed ) ? $observed['percentage'] : null,
					'vat_percent'    => is_array( $observed ) ? $observed['vat_percent'] : null,
					'tax_treatment'  => 'shipping_rate',
				)
			);
		}

		return $surcharges;
	}

	/**
	 * When Core resolves free transport, merchants can choose whether the PMT
	 * payment-service fee remains payable. Default is no for backward compatibility.
	 *
	 * @param array<string,mixed> $package   WC package.
	 * @param Selection           $selection Selection.
	 * @return array<string,mixed>
	 */
	public function calculate_free_shipping_surcharges( array $package, Selection $selection ) {
		// TASK-S1 — one rule for both fees: when a free-shipping rule applies the
		// customer pays nothing for delivery and the fees stay with the sender.
		// Off by default, which is both the merchant's instruction and what the
		// PMT-only predecessor already did.
		if ( 'yes' !== Module_Settings::get( self::ID, 'surcharges_on_free_shipping' ) ) {
			return array();
		}

		return $this->calculate_surcharges( $package, $selection, 0.0 );
	}

	/**
	 * The handling-and-preparation surcharge, or null when there is none.
	 *
	 * Speedy reports this as separate components — `manualHandlingFee`,
	 * `fillInFee` and `loadUnload` (товаро-разтоварни операции) — and BGCS
	 * presents them as the one fee the merchant asked for. Not every contract
	 * charges any of them. What Speedy last charged wins; the setting applies when
	 * there is nothing observed, which is the normal case under custom pricing
	 * where Speedy never prices the order at all.
	 *
	 * @return array<string,mixed>|null
	 */
	private function handling_surcharge() {
		$synced = $this->synced_fee( 'handling' );
		$amount = null !== $synced ? $synced : self::to_amount( Module_Settings::get( self::ID, 'handling_fee' ) );

		if ( $amount <= 0 ) {
			return null;
		}

		return array(
			'name'          => 'handling',
			'label'         => __( 'Handling and preparation', 'bg-commerce-suite' ),
			'amount'        => round( $amount, 2 ),
			'source'        => null !== $synced ? 'api' : 'override',
			'payer'         => 'RECIPIENT',
			'tax_treatment' => 'shipping_rate',
		);
	}

	/**
	 * Recalculate the last observed Speedy PMT rate against the current base.
	 * The old absolute amount belongs to another cart and must not be copied.
	 *
	 * @param float $pmt_base Current PMT base.
	 * @return array{amount:float,percentage:float,vat_percent:float}|null
	 */
	private function synced_pmt_for_base( $pmt_base ) {
		$entry = $this->synced_fee_entry( 'pmt' );
		if ( null === $entry || empty( $entry['percent'] ) || (float) $entry['percent'] <= 0 ) {
			return null;
		}

		$percentage  = max( 0.0, (float) $entry['percent'] );
		$vat_percent = isset( $entry['vat_percent'] ) ? max( 0.0, (float) $entry['vat_percent'] ) : 0.0;
		$amount       = max( 0.0, (float) $pmt_base ) * ( $percentage / 100 );
		if ( $vat_percent > 0 ) {
			$amount *= 1 + ( $vat_percent / 100 );
		}

		return array(
			'amount'      => round( $amount, 2 ),
			'percentage'  => $percentage,
			'vat_percent' => $vat_percent,
		);
	}

	/**
	 * A fee component as Speedy last reported it, in the shop's own currency.
	 *
	 * Returns null — meaning "fall back to the setting" — when nothing has been
	 * synced, when the observation is stale, or when Speedy priced in another
	 * currency. A foreign-currency amount is never converted: BGCS has no
	 * exchange mechanism, and guessing one would put a wrong number in front of
	 * a customer.
	 *
	 * @param string $component 'pmt' or 'handling'.
	 * @return float|null
	 */
	private function synced_fee( $component ) {
		$entry = $this->synced_fee_entry( $component );

		return null !== $entry && isset( $entry['amount'] ) && is_numeric( $entry['amount'] )
			? max( 0.0, (float) $entry['amount'] )
			: null;
	}

	/**
	 * A raw observed fee entry when its currency matches the shop.
	 *
	 * @param string $component Fee component.
	 * @return array<string,mixed>|null
	 */
	private function synced_fee_entry( $component ) {
		$fees = bgcs3_get_option( self::ID, '_synced_fees', array() );
		if ( ! is_array( $fees ) || empty( $fees[ $component ] ) || ! is_array( $fees[ $component ] ) ) {
			return null;
		}

		$entry    = $fees[ $component ];
		$currency = isset( $fees['currency'] ) ? strtoupper( (string) $fees['currency'] ) : '';
		$shop     = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';

		if ( '' !== $currency && '' !== $shop && $currency !== $shop ) {
			return null;
		}

		return $entry;
	}

	/**
	 * A merchant-typed monetary value, comma or dot.
	 *
	 * Takes the value rather than the key on purpose: a settings key resolved
	 * through a variable is invisible to the coverage guard in
	 * tests/test-settings-guards.php, which would then report the field as dead.
	 *
	 * @param mixed $value Raw setting value.
	 * @return float
	 */
	private static function to_amount( $value ) {
		$raw = str_replace( ',', '.', trim( (string) $value ) );

		return is_numeric( $raw ) ? max( 0.0, (float) $raw ) : 0.0;
	}

	/**
	 * Custom pricing is merchant-defined and cannot be reconciled automatically
	 * with a recipient-paid Speedy service amount. BGCS therefore keeps custom
	 * pricing on the sender account; live API pricing has its own payer-aware flow.
	 *
	 * @param Selection $selection Selection.
	 * @param float     $base_cost WooCommerce static shipping amount.
	 * @return true|\WP_Error
	 */
	public function validate_static_pricing( Selection $selection, $base_cost = 0.0 ) {
		unset( $selection );

		$payer = $this->payer();
		if ( (float) $base_cost > 0.0001 && 'RECIPIENT' === $payer ) {
			return new \WP_Error(
				'bgcs3_speedy_static_payer',
				__( 'Recipient-paid Speedy courier service requires live API pricing so BGCS can reconcile the amount collected by Speedy with the WooCommerce order. Use Sender/Third party with custom pricing, or switch to Speedy API pricing.', 'bg-commerce-suite' )
			);
		}

		return true;
	}


	/** @inheritdoc */
	public function validate( Selection $selection ) {
		$valid = parent::validate( $selection );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		return $this->validate_synced_pickup_point( $selection );
	}

	/**
	 * @param array<string,mixed> $package   Package.
	 * @param Selection           $selection Selection.
	 * @return Price_Result
	 */
	public function quote( array $package, Selection $selection ) {
		$service_id = (string) bgcs3_get_option( self::ID, 'service_id', '' );
		if ( '' === $service_id || ! $this->client()->has_credentials() ) {
			return Price_Result::unavailable(
				'speedy_not_configured',
				__( 'Speedy is temporarily unavailable. Please choose another delivery method.', 'bg-commerce-suite' ),
				'Speedy checkout quote skipped: service id or credentials are missing.'
			);
		}

		$sender = $this->sender();
		if ( empty( $sender ) ) {
			return Price_Result::unavailable(
				'speedy_sender_missing',
				__( 'Speedy is temporarily unavailable. Please choose another delivery method.', 'bg-commerce-suite' ),
				'Speedy checkout quote skipped: sender contract client, or the office required by office handover, is missing.'
			);
		}

		$cod_active      = Cod::is_chosen();
		$effective_payer = $this->payer( array(), $cod_active );

		$service = array(
			'serviceIds'           => array( (int) $service_id ),
			'autoAdjustPickupDate' => true,
		);
		if ( 'yes' === Module_Settings::get( self::ID, 'saturday_delivery' ) ) {
			$service['saturdayDelivery'] = true;
		}

		$processing = (string) Module_Settings::get( self::ID, 'cod_processing' );
		$cod_amount = $cod_active ? $this->resolve_checkout_cod_base( $package, 0.0, $effective_payer ) : 0.0;
		$calc       = null;
		$base_price = 0.0;
		$customer_price = 0.0;
		$pmt_charge     = null;
		$converged  = ! $cod_active;
		$max_passes = 5;

		// The courier price is needed before the correct COD/PMT base is known,
		// while Speedy's COD-related return amount depends on that base. Iterate
		// until the COD base sent to Speedy matches merchandise + the transport
		// price returned by that same calculation to currency precision. If a
		// contract does not settle, fail safely instead of using a stale PMT amount.
		for ( $attempt = 0; $attempt < $max_passes; $attempt++ ) {
			$request_cod     = round( (float) $cod_amount, 2 );
			$current_service = $service;
			if ( $cod_active && $request_cod > 0 ) {
				$current_service['additionalServices']['cod'] = array(
					'amount'         => $request_cod,
					'processingType' => $processing,
				);
			}

			$body = array(
				'sender'    => $sender,
				'recipient' => $this->recipient( $selection, array( 'privatePerson' => true ), false ),
				'service'   => $current_service,
				'content'   => $this->content_block( $this->package_weight( $package ), $package ),
				'payment'   => $this->payment_block( $effective_payer ),
			);

			$response = $this->client()->calculate( $body );
			if ( is_wp_error( $response ) ) {
				$technical = 'Speedy calculate transport failure. code=' . $response->get_error_code();
				if ( $response instanceof Courier_Error ) {
					$technical .= ' type=' . $response->type() . ' retryable=' . ( $response->is_retryable() ? 'yes' : 'no' );
				}
				return ( $response instanceof Courier_Error && $response->is_retryable() )
					? Price_Result::temporary_error(
						'speedy_api_temporary',
						__( 'We cannot connect to Speedy right now. Please try again or choose another delivery method.', 'bg-commerce-suite' ),
						$technical
					)
					: Price_Result::unavailable(
						'speedy_api_rejected',
						__( 'Speedy is not available for the current delivery details. Please choose another delivery method.', 'bg-commerce-suite' ),
						$technical
					);
			}

			$store_currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
			$inspected      = Calculation_Inspector::inspect( is_array( $response ) ? $response : array(), (int) $service_id, $store_currency );
			if ( empty( $inspected['valid'] ) ) {
				$result               = new Price_Result();
				$result->availability = $inspected['availability'];
				$result->errors       = array( $inspected['availability']->customer_message );
				return $result;
			}

			$calc       = $inspected['calculation'];
			$base_price = (float) $inspected['total'];
			$customer_price = $base_price;
			$pmt_charge     = null;

			if ( $cod_active
				&& $base_price > 0
				&& 'POSTAL_MONEY_TRANSFER' === $processing
				&& 'SENDER' === (string) Module_Settings::get( self::ID, 'cod_pmt_fee_payer' ) ) {
				$api_pmt       = $this->api_pmt_component( isset( $calc['price'] ) && is_array( $calc['price'] ) ? $calc['price'] : array() );
				$included_pmt  = is_array( $api_pmt ) ? (float) $api_pmt['amount'] : 0.0;
				$non_pmt_price = round( max( 0.0, $base_price - $included_pmt ), 2 );
				$pmt_base      = $this->resolve_pmt_base( $package, $non_pmt_price );
				$pmt_charge    = $this->pmt_charge_for( $pmt_base, $included_pmt, $included_pmt, $selection );
				$pmt_charge['vat_percent'] = is_array( $api_pmt ) ? $api_pmt['vat_percent'] : null;
				$customer_price = round( $non_pmt_price + $pmt_charge['amount'], 2 );
			}

			if ( ! $cod_active || $base_price <= 0 ) {
				$converged = true;
				break;
			}

			$sender_paid_pmt = is_array( $pmt_charge ) ? (float) $pmt_charge['amount'] : 0.0;
			$next_cod = round( $this->resolve_checkout_cod_base( $package, $customer_price, $effective_payer, $sender_paid_pmt ), 2 );
			if ( abs( $next_cod - $request_cod ) <= 0.001 ) {
				$cod_amount = $request_cod;
				$converged  = true;
				break;
			}

			$cod_amount = $next_cod;
		}

		if ( $cod_active && ! $converged ) {
			return Price_Result::temporary_error(
				'speedy_cod_not_converged',
				__( 'We cannot calculate the final Speedy delivery price right now. Please try again or choose another delivery method.', 'bg-commerce-suite' ),
				'Speedy price/COD base did not converge after ' . $max_passes . ' passes.'
			);
		}

		if ( ! is_array( $calc ) || $base_price <= 0 ) {
			return Price_Result::temporary_error(
				'speedy_price_invalid',
				__( 'We cannot calculate the Speedy delivery price right now. Please try again or choose another delivery method.', 'bg-commerce-suite' ),
				'Speedy quote reached the final guard without a positive inspected total.'
			);
		}

		$result                   = new Price_Result();
		$result->valid            = true;
		$result->base_cost        = is_array( $pmt_charge )
			? round( max( 0.0, $base_price - $pmt_charge['included_amount'] ), 2 )
			: $base_price;
		$result->mode             = 'api';
		// TASK-S1 — Speedy publishes no tariff endpoint, so a real calculation is
		// the only place the contract rates are visible. Record what it charged, so
		// the settings screen can show the merchant their actual terms and so
		// custom pricing has a real number to surcharge with instead of a typed
		// guess. Observation only: an API-priced order already includes these fees
		// and must never be surcharged on top.
		$this->record_observed_fees( isset( $calc['price'] ) && is_array( $calc['price'] ) ? $calc['price'] : array() );

		$result->source           = 'api';
		$result->destination_type = $selection->delivery_type;
		$result->weight           = $this->package_weight( $package );
		$result->currency         = isset( $calc['price']['currency'] ) ? (string) $calc['price']['currency'] : get_woocommerce_currency();
		// deliveryDeadline is a commitment, not a prediction — it is worded as one.
		$result->delivery_estimate = Delivery_Estimate::normalize(
			isset( $calc['deliveryDeadline'] ) ? $calc['deliveryDeadline'] : '',
			self::ID,
			Delivery_Estimate::KIND_DEADLINE
		);
		$result->meta             = array(
			'speedy_calculation'    => $calc,
			'cod_base'              => $cod_amount,
			'courier_service_payer' => $effective_payer,
			'price_breakdown'       => $this->price_breakdown( isset( $calc['price'] ) && is_array( $calc['price'] ) ? $calc['price'] : array() ),
		);

		if ( is_array( $pmt_charge ) && $pmt_charge['amount'] > 0 ) {
				$result->add_surcharge(
					'pmt',
					$pmt_charge['amount'],
					array_merge(
						$pmt_charge,
						array(
							'label'         => __( 'Postal money transfer fee', 'bg-commerce-suite' ),
							'payer'         => 'SENDER',
							'tax_treatment' => 'shipping_rate',
						)
					)
				);
		}

		$result->cost = round( $result->base_cost + $result->surcharge_total, 2 );

		return $result;
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return Label_Result
	 */
	public function create_label( \WC_Order $order ) {
		$diag = Shipment_Diagnostics::begin( self::ID );
		$preflight = $this->preflight_shipment( $order );
		if ( $preflight->is_blocked() ) {
			$diag->record( 'blocked_at', 'common_preflight' );
			$diag->record( 'preflight', $preflight->snapshot() );
			$diag->save( $order );
			return $preflight->label_error();
		}

		$body = $this->shipment_body( $order );
		if ( $body instanceof Label_Result ) {
			$preflight->reject( $body, 'speedy_payload' );
			$diag->record( 'effective', $this->diagnostic_intent( $order ) );
			$diag->record( 'blocked_before_payload', implode( ' ', (array) $body->errors ) );
			$diag->save( $order );
			return $body;
		}

		$sender    = isset( $body['sender'] ) && is_array( $body['sender'] ) ? $body['sender'] : array();
		$recipient = isset( $body['recipient'] ) && is_array( $body['recipient'] ) ? $body['recipient'] : array();
		$service   = isset( $body['service'] ) && is_array( $body['service'] ) ? $body['service'] : array();
		$payment   = isset( $body['payment'] ) && is_array( $body['payment'] ) ? $body['payment'] : array();
		$content   = isset( $body['content'] ) && is_array( $body['content'] ) ? $body['content'] : array();
		$additional_services = isset( $service['additionalServices'] ) && is_array( $service['additionalServices'] ) ? array_keys( $service['additionalServices'] ) : array();
		sort( $additional_services, SORT_STRING );
		$preflight
			->section(
				'sender',
				array(
					'account_id'      => ! empty( $sender['clientId'] ) ? (string) $sender['clientId'] : '',
					'location_type'   => ! empty( $sender['dropoffOfficeId'] ) ? 'office' : 'client',
					'location_id'     => ! empty( $sender['dropoffOfficeId'] ) ? (string) $sender['dropoffOfficeId'] : '',
					'contact_present' => ! empty( $sender['contactName'] ) || ! empty( $sender['phone1']['number'] ),
				)
			)
			->section(
				'recipient_payload',
				array(
					'private_person'  => ! empty( $recipient['privatePerson'] ),
					'office_id'       => ! empty( $recipient['pickupOfficeId'] ) ? (string) $recipient['pickupOfficeId'] : '',
					'city_id'         => ! empty( $recipient['address']['siteId'] ) ? (string) $recipient['address']['siteId'] : '',
					'name_present'    => ! empty( $recipient['clientName'] ) || ! empty( $recipient['contactName'] ),
					'phone_present'   => ! empty( $recipient['phone1']['number'] ),
				)
			)
			->section(
				'package_payload',
				array(
					'weight_kg'       => ! empty( $content['totalWeight'] ) ? (float) $content['totalWeight'] : 0.0,
					'parcel_count'    => ! empty( $content['parcels'] ) && is_array( $content['parcels'] ) ? count( $content['parcels'] ) : 1,
					'contents_present' => ! empty( $content['contents'] ),
				)
			)
			->section(
				'services',
				array(
					'service_id'           => ! empty( $service['serviceId'] ) ? (int) $service['serviceId'] : 0,
					'additional_services'  => $additional_services,
				)
			)
			->section(
				'payer',
				array(
					'courier_service' => ! empty( $payment['courierServicePayer'] ) ? (string) $payment['courierServicePayer'] : '',
					'cod_pmt'         => ! empty( $service['additionalServices']['cod']['processingType'] ) ? 'SENDER' : '',
					'package'         => ! empty( $payment['packagePayer'] ) ? (string) $payment['packagePayer'] : '',
					'declared_value'  => ! empty( $payment['declaredValuePayer'] ) ? (string) $payment['declaredValuePayer'] : '',
				)
			)
			->payload_ready( $body );

		$diag->record( 'effective', $this->diagnostic_intent( $order ) );
		$diag->record( 'preflight', $preflight->snapshot() );
		$diag->record( 'payload', $body );

		$destination_contract = $this->validate_destination_service_contract( $body, $diag );
		if ( true !== $destination_contract ) {
			$preflight->block( 'speedy_destination_services', __( 'Speedy destination-service validation failed. Review the create-shipment error shown to the administrator.', 'bg-commerce-suite' ) );
			$diag->record( 'preflight', $preflight->snapshot() );
			$diag->record( 'blocked_at', 'destination_services' );
			$diag->save( $order );
			return Label_Result::error( $destination_contract );
		}

		$validation = $this->validate_shipment_body( $body, $diag );
		if ( true !== $validation ) {
			$preflight->block( 'speedy_validation', __( 'Speedy shipment validation failed. Review the create-shipment error shown to the administrator.', 'bg-commerce-suite' ) );
			$diag->record( 'preflight', $preflight->snapshot() );
			$diag->record( 'blocked_at', 'validation' );
			$diag->save( $order );
			return Label_Result::error( $validation );
		}

		$recipient_price = $this->validate_recipient_paid_price_contract( $body, $order, $diag );
		if ( true !== $recipient_price ) {
			$preflight->block( 'speedy_recipient_price', __( 'Speedy recipient-paid price validation failed. Review the create-shipment error shown to the administrator.', 'bg-commerce-suite' ) );
			$diag->record( 'preflight', $preflight->snapshot() );
			$diag->record( 'blocked_at', 'recipient_payer_calculation' );
			$diag->save( $order );
			return Label_Result::error( $recipient_price );
		}

		$creation = Shipment_Creation::remote_started( $order, $this );
		if ( true !== $creation ) {
			return $creation;
		}
		$response = $this->client()->create_shipment( $body );
		$diag->record_response( 'create_response', $response );
		if ( is_wp_error( $response ) || empty( $response['id'] ) || empty( $response['parcels'][0]['id'] ) ) {
			Shipment_Creation::remote_failed( $order, $response );
			$diag->record( 'blocked_at', 'create' );
			$diag->save( $order );
			return $this->label_from_response( $response, __( 'Shipment label creation failed.', 'bg-commerce-suite' ) );
		}
		$created_parcels = array();
		foreach ( (array) $response['parcels'] as $parcel ) {
			if ( is_array( $parcel ) && ! empty( $parcel['id'] ) ) {
				$created_parcels[] = (string) $parcel['id'];
			}
		}
		Shipment_Creation::remote_accepted(
			$order,
			array(
				'shipment_number' => (string) $response['id'],
				'parcel_ids'      => $created_parcels,
				'tracking_numbers' => $created_parcels,
				'label_reference' => $created_parcels[0],
			)
		);

		// Read the fresh shipment back as a diagnostic guard. Never cancel a courier
		// shipment automatically: cancellation is always an explicit administrator
		// action in BGCS. If Speedy accepted the create request but did not persist
		// an explicitly requested optional service, keep the created shipment linked
		// to the order and surface a clear warning so the administrator can decide
		// whether to cancel it and create a new one manually.
		$result        = $this->label_from_response( $response, __( 'Shipment label creation failed.', 'bg-commerce-suite' ) );
		$service_check = $this->verify_created_service_options( (string) $response['id'], $body, $diag );
		$result->meta['read_back_status'] = true === $service_check ? 'verified' : 'partial';
		if ( $result->success && true !== $service_check ) {
			$result->meta['provider_warning'] = sprintf(
				/* translators: %s: requested option that Speedy did not confirm. */
				__( 'Speedy created the shipment but did not confirm: %s. Check MySpeedy. BGCS did not change or cancel the shipment.', 'bg-commerce-suite' ),
				$service_check
			);
			$diag->record( 'unconfirmed_option', $service_check );
		}

		$diag->save( $order );

		return $result;
	}

	/**
	 * What the merchant asked for, before any of it becomes API vocabulary.
	 *
	 * Recorded as the first diagnostic stage so a snapshot can distinguish
	 * "the option never made it out of the order screen" from "the option was
	 * sent and the courier dropped it" — the two failures look identical in
	 * MySpeedy and were indistinguishable during the 3.0.25 staging test.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private function diagnostic_intent( \WC_Order $order ) {
		$wb = $order->get_meta( '_bgcs3_wb' );
		$wb = is_array( $wb ) ? $wb : array();

		$selection = $order->get_meta( '_bgcs3_selection' );
		$selection = is_array( $selection ) ? $selection : array();

		$order_obp    = isset( $wb['obp'] ) ? (string) $wb['obp'] : '';
		$global_obp   = (string) Module_Settings::get( self::ID, 'obp_option' );
		$order_notes  = isset( $wb['contents'] ) ? (string) $wb['contents'] : '';

		$service_id = $this->wbx( $wb, 'service_id' );
		if ( '' === $service_id ) {
			$service_id = (string) bgcs3_get_option( self::ID, 'service_id', '' );
		}

		return array(
			'order_id'            => $order->get_id(),
			'delivery_type'       => isset( $selection['delivery_type'] ) ? (string) $selection['delivery_type'] : '',
			'payment_method'      => (string) $order->get_payment_method(),
			'obp_order_override'  => '' === $order_obp ? '[inherit]' : $order_obp,
			'obp_global_default'  => $global_obp,
			'obp_effective'       => '' !== $order_obp ? $order_obp : $global_obp,
			'obp_return_service'  => $this->wbx( $wb, 'obp_return_service' ),
			'obp_return_payer'    => $this->wbx( $wb, 'obp_return_payer' ),
			'service_id_order'    => $this->wbx( $wb, 'service_id' ),
			'service_id_resolved' => $service_id,
			// Kept after the truncation was removed: comparing this against the
			// `contents` in the payload stage is what proves nothing trims the
			// description on the way out any more.
			'contents_override_length' => function_exists( 'bgcs3_strlen' ) ? bgcs3_strlen( $order_notes ) : strlen( $order_notes ),
			'contents_source'          => '' !== $order_notes ? 'order override' : 'generated from items',
			'cod_mode'            => isset( $wb['cod_mode'] ) ? (string) $wb['cod_mode'] : '',
			'cod_resolved'        => Cod::resolve_amount( $order, $wb ),
			'dv_mode'             => isset( $wb['dv_mode'] ) ? (string) $wb['dv_mode'] : '',
		);
	}

	/**
	 * Validate the selected service and all requested additional services against
	 * Speedy's destination-aware service contract before shipment validation.
	 *
	 * /services/destination returns ExtendedCourierService rows. Their
	 * additionalServices are the provider's authoritative allowance matrix for
	 * the exact sender/recipient: FORBIDDEN, ALLOWED or REQUIRED. We fail closed
	 * when Speedy does not expose the chosen service or when a requested/required
	 * option cannot be satisfied. No courier state is changed by this call.
	 *
	 * @param array<string,mixed>  $body CreateShipmentRequest body.
	 * @param Shipment_Diagnostics $diag Snapshot collector (inert when disabled).
	 * @return true|string
	 */
	private function validate_destination_service_contract( array $body, Shipment_Diagnostics $diag ) {
		$service_id = isset( $body['service']['serviceId'] ) ? (int) $body['service']['serviceId'] : 0;
		if ( $service_id <= 0 ) {
			return __( 'Speedy service ID is missing. The shipment was not created.', 'bg-commerce-suite' );
		}

		$shipment_recipient = isset( $body['recipient'] ) && is_array( $body['recipient'] ) ? $body['recipient'] : array();
		$recipient = array();
		if ( isset( $shipment_recipient['clientId'] ) && (int) $shipment_recipient['clientId'] > 0 ) {
			$recipient['clientId'] = (int) $shipment_recipient['clientId'];
		} else {
			$recipient['privatePerson'] = ! empty( $shipment_recipient['privatePerson'] );
			if ( ! empty( $shipment_recipient['pickupOfficeId'] ) ) {
				$recipient['pickupOfficeId'] = (int) $shipment_recipient['pickupOfficeId'];
			} elseif ( ! empty( $shipment_recipient['address'] ) && is_array( $shipment_recipient['address'] ) ) {
				$address = $shipment_recipient['address'];
				$recipient['addressLocation'] = array(
					'countryId' => isset( $address['countryId'] ) ? (int) $address['countryId'] : self::COUNTRY_BG,
					'siteId'    => isset( $address['siteId'] ) ? (int) $address['siteId'] : 0,
				);
				if ( ! empty( $address['postCode'] ) ) {
					$recipient['addressLocation']['postCode'] = (string) $address['postCode'];
				}
			}
		}

		if ( empty( $recipient['clientId'] ) && empty( $recipient['pickupOfficeId'] ) && empty( $recipient['addressLocation']['siteId'] ) ) {
			return __( 'Speedy could not determine the destination for service validation. The shipment was not created.', 'bg-commerce-suite' );
		}

		$shipment_sender = isset( $body['sender'] ) && is_array( $body['sender'] ) ? $body['sender'] : array();
		$sender = array();
		if ( ! empty( $shipment_sender['clientId'] ) ) {
			$sender['clientId'] = (int) $shipment_sender['clientId'];
		} elseif ( ! empty( $shipment_sender['dropoffOfficeId'] ) ) {
			$sender['privatePerson']  = false;
			$sender['dropoffOfficeId'] = (int) $shipment_sender['dropoffOfficeId'];
		}

		$response = $this->client()->get_destination_services( $recipient, $sender );
		$diag->record_destination_services( $response, $service_id );
		if ( is_wp_error( $response ) ) {
			return sprintf(
				/* translators: %s: Speedy destination-services error. */
				__( 'Speedy could not confirm the services available for this destination: %s', 'bg-commerce-suite' ),
				$response->get_error_message()
			);
		}

		$services = isset( $response['services'] ) && is_array( $response['services'] ) ? $response['services'] : array();
		$selected = null;
		foreach ( $services as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && (int) $row['id'] === $service_id ) {
				$selected = $row;
				break;
			}
		}
		if ( null === $selected ) {
			return sprintf(
				/* translators: %d: Speedy service ID. */
				__( 'Speedy service %d is not available for this sender and destination. Choose another service before creating the shipment.', 'bg-commerce-suite' ),
				$service_id
			);
		}

		$allowances = isset( $selected['additionalServices'] ) && is_array( $selected['additionalServices'] ) ? $selected['additionalServices'] : array();
		$requested  = isset( $body['service']['additionalServices'] ) && is_array( $body['service']['additionalServices'] ) ? $body['service']['additionalServices'] : array();

		$requested_flags = array(
			'cod'               => ! empty( $requested['cod'] ),
			'obpd'              => ! empty( $requested['obpd'] ),
			'declaredValue'     => ! empty( $requested['declaredValue'] ),
			'fixedTimeDelivery' => array_key_exists( 'fixedTimeDelivery', $requested ),
			'deliveryToFloor'   => array_key_exists( 'deliveryToFloor', $requested ),
			'rod'               => ! empty( $requested['returns']['rod'] ),
			'returnReceipt'     => ! empty( $requested['returns']['returnReceipt'] ) || ! empty( $requested['returns']['electronicReturnReceipt'] ),
			'swap'              => ! empty( $requested['returns']['swap'] ),
			'rop'               => ! empty( $requested['returns']['rop'] ),
			'returnVoucher'     => ! empty( $requested['returns']['returnVoucher'] ),
			'specialDelivery'   => ! empty( $requested['specialDeliveryId'] ),
		);

		$labels = array(
			'cod'               => __( 'Cash on delivery', 'bg-commerce-suite' ),
			'obpd'              => __( 'Review / Review and test before payment', 'bg-commerce-suite' ),
			'declaredValue'     => __( 'Declared value', 'bg-commerce-suite' ),
			'fixedTimeDelivery' => __( 'Fixed delivery time', 'bg-commerce-suite' ),
			'deliveryToFloor'   => __( 'Delivery to floor', 'bg-commerce-suite' ),
			'rod'               => __( 'Return of documents (ROD)', 'bg-commerce-suite' ),
			'returnReceipt'     => __( 'Return receipt', 'bg-commerce-suite' ),
			'swap'              => __( 'SWAP', 'bg-commerce-suite' ),
			'rop'               => __( 'Return of pallets', 'bg-commerce-suite' ),
			'returnVoucher'     => __( 'Return voucher', 'bg-commerce-suite' ),
			'specialDelivery'   => __( 'Special delivery', 'bg-commerce-suite' ),
		);

		foreach ( $allowances as $key => $definition ) {
			if ( ! is_array( $definition ) || empty( $definition['allowance'] ) ) {
				continue;
			}
			$allowance = strtoupper( (string) $definition['allowance'] );
			$is_requested = ! empty( $requested_flags[ $key ] );
			$label = isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key;

			if ( 'FORBIDDEN' === $allowance && $is_requested ) {
				return sprintf(
					/* translators: %s: Speedy additional service name. */
					__( 'Speedy does not allow “%s” for the selected service and destination. Change the shipment settings before creating the label.', 'bg-commerce-suite' ),
					$label
				);
			}
			if ( 'REQUIRED' === $allowance && ! $is_requested ) {
				return sprintf(
					/* translators: %s: Speedy required additional service name. */
					__( 'Speedy requires “%s” for the selected service and destination, but BGCS has not been configured to send it. Choose a compatible service or enable/configure the required option before creating the label.', 'bg-commerce-suite' ),
					$label
				);
			}
		}

		return true;
	}

	/**
	 * Validate the exact create request via Speedy's official
	 * POST /validation/shipment endpoint.
	 *
	 * @param array<string,mixed>  $body CreateShipmentRequest body.
	 * @param Shipment_Diagnostics $diag Snapshot collector (inert when disabled).
	 * @return true|string
	 */
	private function validate_shipment_body( array $body, Shipment_Diagnostics $diag ) {
		$validation = $this->client()->validate_shipment( $body );
		$diag->record_response( 'validation', $validation );
		if ( is_wp_error( $validation ) ) {
			return sprintf(
				/* translators: %s: Speedy validation error. */
				__( 'Speedy rejected the shipment settings before creation: %s', 'bg-commerce-suite' ),
				$validation->get_error_message()
			);
		}

		if ( ! array_key_exists( 'valid', $validation ) ) {
			return __( 'Speedy did not explicitly confirm the shipment settings as valid. The shipment was not created.', 'bg-commerce-suite' );
		}
		if ( ! (bool) $validation['valid'] ) {
			return __( 'Speedy reported that the shipment settings are not valid. The shipment was not created.', 'bg-commerce-suite' );
		}

		return true;
	}

	/**
	 * Recalculate an API-priced recipient-paid shipment immediately before create.
	 *
	 * WooCommerce displays the Speedy transport amount as its shipping line while
	 * Speedy collects that same transport amount directly from the COD recipient.
	 * If the courier price changed after checkout, silently creating the shipment
	 * would make the order total and the amount actually collected diverge.
	 *
	 * @param array<string,mixed>  $body  CreateShipmentRequest body.
	 * @param \WC_Order            $order Order.
	 * @param Shipment_Diagnostics $diag  Diagnostics.
	 * @return true|string
	 */
	private function validate_recipient_paid_price_contract( array $body, \WC_Order $order, Shipment_Diagnostics $diag ) {
		$payer = isset( $body['payment']['courierServicePayer'] ) ? strtoupper( (string) $body['payment']['courierServicePayer'] ) : '';
		if ( 'RECIPIENT' !== $payer ) {
			return true;
		}

		$stored = $order->get_meta( '_bgcs3_base_cost' );
		if ( '' === (string) $stored || ! is_numeric( $stored ) || (float) $stored <= 0 ) {
			// Legacy orders may predate pricing audit metadata. Do not invent a
			// historical checkout price; validation/create remains authoritative.
			$diag->record( 'recipient_payer_calculation', array( 'status' => 'no_checkout_base_snapshot' ) );
			return true;
		}

		$calc_body = array();
		foreach ( array( 'sender', 'recipient', 'content', 'payment' ) as $key ) {
			if ( isset( $body[ $key ] ) ) {
				$calc_body[ $key ] = $body[ $key ];
			}
		}
		$service = isset( $body['service'] ) && is_array( $body['service'] ) ? $body['service'] : array();
		$service_id = isset( $service['serviceId'] ) ? (int) $service['serviceId'] : 0;
		if ( $service_id <= 0 ) {
			return __( 'Speedy recipient-paid shipment cannot be price-verified because the courier service is missing.', 'bg-commerce-suite' );
		}
		unset( $service['serviceId'] );
		$service['serviceIds'] = array( $service_id );
		$calc_body['service']   = $service;

		$response = $this->client()->calculate( $calc_body );
		$diag->record_response( 'recipient_payer_calculation', $response );
		if ( is_wp_error( $response ) ) {
			return sprintf(
				/* translators: %s: Speedy calculation error. */
				__( 'Speedy could not verify the current recipient-paid courier price before shipment creation: %s', 'bg-commerce-suite' ),
				$response->get_error_message()
			);
		}

		$calc = isset( $response['calculations'][0] ) && is_array( $response['calculations'][0] ) ? $response['calculations'][0] : array();
		$current = isset( $calc['price']['total'] ) && is_numeric( $calc['price']['total'] ) ? round( (float) $calc['price']['total'], 2 ) : 0.0;
		if ( $current <= 0 ) {
			return __( 'Speedy did not return a valid recipient-paid courier price before shipment creation.', 'bg-commerce-suite' );
		}

		$current_currency = isset( $calc['price']['currency'] ) ? strtoupper( (string) $calc['price']['currency'] ) : '';
		$order_currency   = strtoupper( (string) $order->get_currency() );
		if ( '' !== $current_currency && '' !== $order_currency && $current_currency !== $order_currency ) {
			return sprintf(
				/* translators: 1: current Speedy currency, 2: WooCommerce order currency. */
				__( 'Speedy now prices the recipient-paid courier service in %1$s, while the WooCommerce order is in %2$s. Recalculate the order shipping before creating the shipment.', 'bg-commerce-suite' ),
				$current_currency,
				$order_currency
			);
		}

		$checkout = round( (float) $stored, 2 );
		if ( abs( $current - $checkout ) > 0.02 ) {
			return sprintf(
				/* translators: 1: current Speedy courier price, 2: price stored on the WooCommerce order, 3: currency. */
				__( 'The current Speedy recipient-paid courier price is %1$s %3$s, but the WooCommerce order contains %2$s %3$s for shipping. Recalculate/update the order shipping before creating the shipment so the customer is not charged a different total.', 'bg-commerce-suite' ),
				number_format( $current, 2, '.', '' ),
				number_format( $checkout, 2, '.', '' ),
				'' !== $current_currency ? $current_currency : $order_currency
			);
		}

		return true;
	}

	/**
	 * Verify deterministic service-level values after creation. We intentionally
	 * do not compare free-text content/address formatting here because Speedy may
	 * normalize them. This check is limited to service flags and additional
	 * services that must not silently disappear from the created shipment.
	 *
	 * @param string               $shipment_id Created Speedy shipment id.
	 * @param array<string,mixed>  $body        Requested CreateShipmentRequest.
	 * @param Shipment_Diagnostics $diag        Snapshot collector (inert when disabled).
	 * @return true|string Human-readable mismatch on failure.
	 */
	private function verify_created_service_options( $shipment_id, array $body, Shipment_Diagnostics $diag ) {
		$expected_service = isset( $body['service'] ) && is_array( $body['service'] ) ? $body['service'] : array();
		$expected_add     = isset( $expected_service['additionalServices'] ) && is_array( $expected_service['additionalServices'] ) ? $expected_service['additionalServices'] : array();

		// No optional/service flags requested means there is nothing extra to
		// read back; the create response itself already confirms the shipment.
		$needs_check = ! empty( $expected_add )
			|| array_key_exists( 'saturdayDelivery', $expected_service )
			|| array_key_exists( 'deferredDays', $expected_service )
			|| self::payment_needs_readback( isset( $body['payment'] ) && is_array( $body['payment'] ) ? $body['payment'] : array() );
		if ( ! $needs_check ) {
			return true;
		}

		$info = $this->client()->shipment_info( array( $shipment_id ) );
		$diag->record_response( 'readback', $info );
		if ( is_wp_error( $info ) ) {
			return sprintf(
				/* translators: %s: Speedy error. */
				__( 'post-creation verification failed: %s', 'bg-commerce-suite' ),
				$info->get_error_message()
			);
		}

		$actual = isset( $info['shipments'][0] ) && is_array( $info['shipments'][0] ) ? $info['shipments'][0] : array();
		if ( empty( $actual ) ) {
			return __( 'post-creation verification returned no shipment data', 'bg-commerce-suite' );
		}

		$actual_service = isset( $actual['service'] ) && is_array( $actual['service'] ) ? $actual['service'] : array();
		$actual_add     = isset( $actual_service['additionalServices'] ) && is_array( $actual_service['additionalServices'] ) ? $actual_service['additionalServices'] : array();

		if ( isset( $expected_service['serviceId'] ) && (int) $expected_service['serviceId'] !== (int) ( $actual_service['serviceId'] ?? 0 ) ) {
			return __( 'courier service', 'bg-commerce-suite' );
		}
		if ( array_key_exists( 'saturdayDelivery', $expected_service )
			&& (bool) $expected_service['saturdayDelivery'] !== (bool) ( $actual_service['saturdayDelivery'] ?? false ) ) {
			return __( 'Saturday delivery', 'bg-commerce-suite' );
		}
		if ( array_key_exists( 'deferredDays', $expected_service )
			&& (int) $expected_service['deferredDays'] !== (int) ( $actual_service['deferredDays'] ?? 0 ) ) {
			return __( 'deferred delivery', 'bg-commerce-suite' );
		}

		$expected_cod = isset( $expected_add['cod'] ) && is_array( $expected_add['cod'] ) ? $expected_add['cod'] : array();
		if ( ! empty( $expected_cod ) ) {
			$actual_cod = isset( $actual_add['cod'] ) && is_array( $actual_add['cod'] ) ? $actual_add['cod'] : array();
			if ( empty( $actual_cod )
				|| abs( (float) ( $expected_cod['amount'] ?? 0 ) - (float) ( $actual_cod['amount'] ?? 0 ) ) > 0.001
				|| strtoupper( (string) ( $expected_cod['processingType'] ?? '' ) ) !== strtoupper( (string) ( $actual_cod['processingType'] ?? '' ) ) ) {
				return __( 'cash on delivery', 'bg-commerce-suite' );
			}
		}

		$expected_dv = isset( $expected_add['declaredValue'] ) && is_array( $expected_add['declaredValue'] ) ? $expected_add['declaredValue'] : array();
		if ( ! empty( $expected_dv ) ) {
			$actual_dv = isset( $actual_add['declaredValue'] ) && is_array( $actual_add['declaredValue'] ) ? $actual_add['declaredValue'] : array();
			if ( empty( $actual_dv )
				|| abs( (float) ( $expected_dv['amount'] ?? 0 ) - (float) ( $actual_dv['amount'] ?? 0 ) ) > 0.001
				|| (bool) ( $expected_dv['fragile'] ?? false ) !== (bool) ( $actual_dv['fragile'] ?? false ) ) {
				return __( 'declared value / fragile', 'bg-commerce-suite' );
			}
		}

		$expected_obpd = isset( $expected_add['obpd'] ) && is_array( $expected_add['obpd'] ) ? $expected_add['obpd'] : array();
		if ( ! empty( $expected_obpd ) ) {
			$actual_obpd = isset( $actual_add['obpd'] ) && is_array( $actual_add['obpd'] ) ? $actual_add['obpd'] : array();
			$matches = ! empty( $actual_obpd )
				&& strtoupper( (string) ( $expected_obpd['option'] ?? '' ) ) === strtoupper( (string) ( $actual_obpd['option'] ?? '' ) )
				&& (int) ( $expected_obpd['returnShipmentServiceId'] ?? 0 ) === (int) ( $actual_obpd['returnShipmentServiceId'] ?? 0 )
				&& strtoupper( (string) ( $expected_obpd['returnShipmentPayer'] ?? '' ) ) === strtoupper( (string) ( $actual_obpd['returnShipmentPayer'] ?? '' ) );
			if ( ! $matches ) {
				return __( 'Review / Review and test before payment', 'bg-commerce-suite' );
			}
		}

		foreach ( array(
			'fixedTimeDelivery' => __( 'fixed delivery time', 'bg-commerce-suite' ),
			'deliveryToFloor'   => __( 'delivery to floor', 'bg-commerce-suite' ),
		) as $key => $label ) {
			if ( isset( $expected_add[ $key ] ) && (int) $expected_add[ $key ] !== (int) ( $actual_add[ $key ] ?? 0 ) ) {
				return $label;
			}
		}

		$expected_returns = isset( $expected_add['returns'] ) && is_array( $expected_add['returns'] ) ? $expected_add['returns'] : array();
		if ( ! empty( $expected_returns ) ) {
			$actual_returns = isset( $actual_add['returns'] ) && is_array( $actual_add['returns'] ) ? $actual_add['returns'] : array();
			foreach ( array( 'returnVoucher', 'rod' ) as $key ) {
				if ( ! empty( $expected_returns[ $key ] ) && empty( $actual_returns[ $key ] ) ) {
					return 'returnVoucher' === $key ? __( 'return voucher', 'bg-commerce-suite' ) : __( 'return of documents', 'bg-commerce-suite' );
				}
			}
		}

		// Free text is still not compared for equality — Speedy normalises spacing
		// and casing, and warning about that would be noise. Truncation, though, has
		// a signature normalisation does not: what came back is a strict, shorter
		// prefix of what went out. That is the one difference worth reporting, and
		// it is the difference that hid the original defect for so long.
		// BGCS-AUDIT-019 — Speedy returns the `payment` block from
		// `shipment/info`, and §42 names the payer among the things a HTTP 200
		// does not prove. Until now it was the one requested thing never read
		// back, which is also the most expensive one to get silently substituted:
		// if the account's contract does not permit the requested role, Speedy
		// applies its own and BGCS reported success without a word.
		$payment_check = $this->verify_payment_block(
			isset( $body['payment'] ) && is_array( $body['payment'] ) ? $body['payment'] : array(),
			isset( $actual['payment'] ) && is_array( $actual['payment'] ) ? $actual['payment'] : array()
		);
		if ( true !== $payment_check ) {
			return $payment_check;
		}

		$sent_contents   = isset( $body['content']['contents'] ) ? (string) $body['content']['contents'] : '';
		$stored_contents = isset( $actual['content']['contents'] ) ? (string) $actual['content']['contents'] : '';
		if ( '' !== $sent_contents && '' !== $stored_contents && $sent_contents !== $stored_contents ) {
			$sent_length   = function_exists( 'bgcs3_strlen' ) ? bgcs3_strlen( $sent_contents ) : strlen( $sent_contents );
			$stored_length = function_exists( 'bgcs3_strlen' ) ? bgcs3_strlen( $stored_contents ) : strlen( $stored_contents );
			if ( $stored_length < $sent_length && 0 === strpos( $sent_contents, $stored_contents ) ) {
				return sprintf(
					/* translators: 1: characters stored by the courier, 2: characters sent. */
					__( 'the shipment description was shortened by the courier (%1$d of %2$d characters kept)', 'bg-commerce-suite' ),
					(int) $stored_length,
					(int) $sent_length
				);
			}
		}

		return true;
	}

	/**
	 * Is this payment block worth an extra round trip to read back?
	 *
	 * A plain sender-pays shipment is the default on both sides, so confirming it
	 * costs an HTTP request on every single label to learn nothing. Anything the
	 * merchant asked for that differs from that — a recipient- or third-party-paid
	 * role, the contract administrative fee — is exactly where the account's
	 * contract can quietly override the request, and is worth checking.
	 *
	 * @param array<string,mixed> $payment Payment block that was sent.
	 * @return bool
	 */
	private static function payment_needs_readback( array $payment ) {
		foreach ( array( 'courierServicePayer', 'declaredValuePayer', 'packagePayer' ) as $role ) {
			if ( isset( $payment[ $role ] ) && 'SENDER' !== strtoupper( (string) $payment[ $role ] ) ) {
				return true;
			}
		}

		return ! empty( $payment['administrativeFee'] ) || ! empty( $payment['thirdPartyClientId'] );
	}

	/**
	 * Compare the payment block Speedy stored with the one BGCS asked for.
	 *
	 * Only fields that were actually sent AND actually returned are compared:
	 * warning about a field the provider does not echo would be a false alarm,
	 * and the finding is explicit that silence must not become noise.
	 *
	 * @param array<string,mixed> $sent   Payment block sent with the create.
	 * @param array<string,mixed> $stored Payment block from `shipment/info`.
	 * @return true|string True, or a human name for what did not match.
	 */
	private function verify_payment_block( array $sent, array $stored ) {
		if ( empty( $sent ) || empty( $stored ) ) {
			return true;
		}

		$roles = array(
			'courierServicePayer' => __( 'courier service payer', 'bg-commerce-suite' ),
			'declaredValuePayer'  => __( 'declared-value payer', 'bg-commerce-suite' ),
			'packagePayer'        => __( 'packaging payer', 'bg-commerce-suite' ),
		);

		foreach ( $roles as $key => $label ) {
			if ( ! isset( $sent[ $key ], $stored[ $key ] ) ) {
				continue;
			}
			if ( strtoupper( (string) $sent[ $key ] ) !== strtoupper( (string) $stored[ $key ] ) ) {
				return sprintf(
					/* translators: 1: payer role, 2: requested payer, 3: payer Speedy applied. */
					__( '%1$s (asked for %2$s, Speedy recorded %3$s)', 'bg-commerce-suite' ),
					$label,
					strtoupper( (string) $sent[ $key ] ),
					strtoupper( (string) $stored[ $key ] )
				);
			}
		}

		if ( array_key_exists( 'administrativeFee', $sent ) && array_key_exists( 'administrativeFee', $stored )
			&& (bool) $sent['administrativeFee'] !== (bool) $stored['administrativeFee'] ) {
			return __( 'the contract administrative fee', 'bg-commerce-suite' );
		}

		if ( array_key_exists( 'thirdPartyClientId', $sent ) && array_key_exists( 'thirdPartyClientId', $stored )
			&& (int) $sent['thirdPartyClientId'] !== (int) $stored['thirdPartyClientId'] ) {
			return __( 'the third-party payer account', 'bg-commerce-suite' );
		}

		return true;
	}

	/**
	 * Core policy: BGCS never edits an existing shipment through the API.
	 * Speedy does publish `POST /shipment/update`, but the merchant-facing flow
	 * is Save order settings -> manual Cancel -> manual Create, so this optional
	 * capability is deliberately declined.
	 *
	 * @return bool
	 */
	public function supports_label_update() {
		return false;
	}

	/**
	 * Fail-safe only. Retained so a stale cached admin script cannot reach a
	 * real Speedy edit: it always refuses and points at the manual flow.
	 *
	 * @param \WC_Order $order Order.
	 * @return Label_Result
	 */
	public function update_label( \WC_Order $order ) {
		unset( $order );
		return Label_Result::error( __( 'BGCS does not edit an existing Speedy shipment automatically. Save the order settings, cancel the current shipment manually, then create a new shipment label.', 'bg-commerce-suite' ) );
	}

	/**
	 * Builds the shipment payload shared by create and in-place update, or a
	 * failed Label_Result carrying the reason it cannot be built. Keeping this
	 * in one place is what guarantees an edited shipment is validated by exactly
	 * the same rules as a new one.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>|Label_Result
	 */
	private function shipment_body( \WC_Order $order ) {
		$selection = $this->order_selection( $order );
		if ( ! $selection ) {
			return Label_Result::error( __( 'The order has no saved delivery selection.', 'bg-commerce-suite' ) );
		}

		// Admin waybill overrides (recipient contact, COD amount, declared value,
		// dimensions, payer, OBP, refs, description) edited in the order metabox.
		$wb = $order->get_meta( '_bgcs3_wb' );
		$wb = is_array( $wb ) ? $wb : array();

		$invalid_package_row = Package_Dimensions::invalid_complete_row( isset( $wb['packages'] ) ? $wb['packages'] : array() );
		if ( $invalid_package_row > 0 ) {
			return Label_Result::error(
				sprintf(
					/* translators: %d package row number. */
					__( 'Speedy package %d is incomplete. Enter length, width, height and weight for every package, or remove the package rows and use the total shipment values.', 'bg-commerce-suite' ),
					$invalid_package_row
				)
			);
		}

		$service_id = $this->wbx( $wb, 'service_id' );
		if ( '' === $service_id ) {
			$service_id = (string) bgcs3_get_option( self::ID, 'service_id', '' );
		}
		if ( '' === $service_id || (int) $service_id <= 0 ) {
			return Label_Result::error( __( 'Service ID is missing from the Speedy settings and from the per-order shipment overrides.', 'bg-commerce-suite' ) );
		}

		// A waybill can be created long after checkout, after a merchant changed
		// global Speedy settings. Re-validate the courier-service payer against the
		// pricing contract stored on the order so an originally merchant-paid fixed
		// rate cannot silently become recipient-paid at label creation time.
		$pricing_mode              = method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( '_bgcs3_pricing_mode' ) : '';
		$stored_contract_currency = method_exists( $order, 'get_meta' ) ? trim( (string) $order->get_meta( '_bgcs3_contract_currency' ) ) : '';
		$stored_pricing_rule      = method_exists( $order, 'get_meta' ) ? $order->get_meta( '_bgcs3_pricing_rule' ) : array();
		$current_contract_currency = trim( (string) Module_Settings::get( self::ID, 'contract_currency' ) );
		$explicit_contract        = 'static' === $pricing_mode
			&& ( ! empty( $stored_pricing_rule ) || '' !== $stored_contract_currency || '' !== $current_contract_currency );
		if ( $explicit_contract && 'SENDER' !== $this->payer( $wb ) ) {
			return Label_Result::error( __( 'Shipment label creation is blocked: with contracted static prices, the courier service payer must be “Sender”.', 'bg-commerce-suite' ) );
		}

		// Recipient resolution: shipping info takes precedence over billing.
		$contact_name = '';
		if ( ! empty( $wb['contact_name'] ) ) {
			$contact_name = trim( (string) $wb['contact_name'] );
		} else {
			$shipping_name = '';
			if ( method_exists( $order, 'get_shipping_first_name' ) || method_exists( $order, 'get_shipping_last_name' ) ) {
				$first         = method_exists( $order, 'get_shipping_first_name' ) ? (string) $order->get_shipping_first_name() : '';
				$last          = method_exists( $order, 'get_shipping_last_name' ) ? (string) $order->get_shipping_last_name() : '';
				$shipping_name = trim( $first . ' ' . $last );
			}
			if ( '' === $shipping_name && method_exists( $order, 'get_formatted_shipping_full_name' ) ) {
				$shipping_name = trim( (string) $order->get_formatted_shipping_full_name() );
			}

			if ( '' !== $shipping_name ) {
				$contact_name = $shipping_name;
			} else {
				$billing_name = '';
				if ( method_exists( $order, 'get_billing_first_name' ) || method_exists( $order, 'get_billing_last_name' ) ) {
					$b_first      = method_exists( $order, 'get_billing_first_name' ) ? (string) $order->get_billing_first_name() : '';
					$b_last       = method_exists( $order, 'get_billing_last_name' ) ? (string) $order->get_billing_last_name() : '';
					$billing_name = trim( $b_first . ' ' . $b_last );
				}
				if ( '' === $billing_name && method_exists( $order, 'get_formatted_billing_full_name' ) ) {
					$billing_name = trim( (string) $order->get_formatted_billing_full_name() );
				}
				$contact_name = $billing_name;
			}
		}

		$company = '';
		if ( method_exists( $order, 'get_shipping_company' ) ) {
			$company = trim( (string) $order->get_shipping_company() );
		}
		if ( '' === $company && method_exists( $order, 'get_billing_company' ) ) {
			$company = trim( (string) $order->get_billing_company() );
		}

		$phone = '';
		if ( ! empty( $wb['phone'] ) ) {
			$phone = trim( (string) $wb['phone'] );
		} elseif ( method_exists( $order, 'get_shipping_phone' ) && '' !== trim( (string) $order->get_shipping_phone() ) ) {
			$phone = trim( (string) $order->get_shipping_phone() );
		} else {
			$phone = trim( (string) $order->get_billing_phone() );
		}

		$email = ! empty( $wb['email'] ) ? (string) $wb['email'] : (string) $order->get_billing_email();

		// Speedy rejects shipments without a recipient phone — fail early with a
		// clear message instead of a raw API error.
		if ( '' === $phone ) {
			return Label_Result::error( __( 'The order has no recipient phone number. Enter a phone number in the “Phone” field of the shipment label panel and try again.', 'bg-commerce-suite' ) );
		}

		// „Чупливо“ съществува в API-то само като свойство на обявената стойност
		// (ShipmentDeclaredValueAdditionalService.fragile) — без обявена стойност
		// Speedy няма къде да го запише. Вместо мълчаливо да го изхвърлим (както
		// беше досега — товарителницата излизаше с „Чупливост: Не“), казваме ясно
		// какво липсва (rules.md §3).
		if ( $this->fragile_requested( $wb ) && $this->declared_value_amount( $order, $wb ) <= 0 ) {
			return Label_Result::error( __( 'Speedy accepts “Fragile” only together with a declared value (insurance). Select “Declared value: Manual value” and enter an amount, enable “Declared value” in the Speedy settings, or set “Fragile” back to “No”.', 'bg-commerce-suite' ) );
		}

		// Company recipient support:
		// When company is present, clientName is company, contactName is person name, privatePerson is false.
		// When private individual, clientName is person name, privatePerson is true.
		$recipient_base = array(
			'phone1' => array( 'number' => $phone ),
			'email'  => $email,
		);

		if ( '' !== $company ) {
			$recipient_base['privatePerson'] = false;
			$recipient_base['clientName']    = $company;
			if ( '' !== $contact_name ) {
				$recipient_base['contactName'] = $contact_name;
			}
		} else {
			$recipient_base['privatePerson'] = true;
			$recipient_base['clientName']    = $contact_name;
		}

		$recipient = $this->recipient(
			$selection,
			$recipient_base,
			true
		);

		$sender = $this->sender( $wb );
		if ( empty( $sender ) ) {
			return Label_Result::error( __( 'Configure a Speedy contract sender and, for office handover, a valid drop-off office.', 'bg-commerce-suite' ) );
		}

		$resolved_payer = $this->shipment_payer( $order, $wb );
		$service        = $this->service_block( $service_id, $wb );
		$additional     = $this->additional_services( $order, $wb, (int) $service_id, $resolved_payer );
		if ( ! empty( $additional ) ) {
			$service['additionalServices'] = $additional;
		}

		// Speedy defines OBPD as options available before payment of COD. It is
		// not a generic delivery flag: COD and all three ShipmentOBPD fields must
		// be present, and the service is not applicable to locker/APS delivery.
		if ( ! empty( $additional['obpd'] ) ) {
			if ( empty( $additional['cod'] ) ) {
				return Label_Result::error( __( 'Speedy Review / Review and test requires cash on delivery. Enable COD for this order or set Review/Test to No.', 'bg-commerce-suite' ) );
			}
			if ( 'locker' === (string) $selection->delivery_type ) {
				return Label_Result::error( __( 'Speedy Review / Review and test is not available for delivery to a locker/APS. Choose an office/address or set Review/Test to No.', 'bg-commerce-suite' ) );
			}
			$obpd = $additional['obpd'];
			if ( empty( $obpd['returnShipmentServiceId'] ) || ! in_array( strtoupper( (string) ( $obpd['returnShipmentPayer'] ?? '' ) ), array( 'SENDER', 'RECIPIENT', 'THIRD_PARTY' ), true ) ) {
				return Label_Result::error( __( 'Speedy Review / Review and test requires a valid return service and return payer. Check the order-specific or default Speedy settings.', 'bg-commerce-suite' ) );
			}
		}

		// Preflight Financial Check (Master Instruction §10, 16, 17, 18, 19, 20):
		// Independent reconciliation between WooCommerce payable total, stored pricing audit data, and effective Speedy COD amount.
		if ( Cod::is_order( $order ) || ! empty( $additional['cod']['amount'] ) ) {
			$expected_payable = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
			$effective_speedy = ! empty( $additional['cod']['amount'] ) ? (float) $additional['cod']['amount'] : 0.0;
			$order_currency   = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'EUR';

			// With recipient-paid courier service, WooCommerce may still show the live
			// Speedy price in the order. That amount is collected directly by Speedy,
			// so inherited COD excludes only that courier component. The customer's
			// total remains unchanged: COD + recipient-paid transport = order total.
			if ( 'RECIPIENT' === $resolved_payer && Overrides::INHERIT === Overrides::mode( $wb, 'cod_mode' ) ) {
				$expected_payable = max( 0.0, $expected_payable - $this->recipient_direct_shipping_amount( $order ) );
			}

			if ( ! empty( $additional['cod']['includeShippingPrice'] ) ) {
				return Label_Result::error( __( 'Invalid configuration: includeShippingPrice cannot be enabled with full cash on delivery.', 'bg-commerce-suite' ) );
			}

			// If manual override in metabox was specified, use that as expected
			if ( ! empty( $wb['cod_mode'] ) && \BgCommerce3\Shipping\Overrides::CUSTOM === $wb['cod_mode'] ) {
				$expected_payable = (float) $wb['cod_amount'];
			} elseif ( ! empty( $wb['cod_mode'] ) && \BgCommerce3\Shipping\Overrides::DISABLED === $wb['cod_mode'] ) {
				$expected_payable = 0.0;
			}

			$diff = abs( round( $expected_payable, 2 ) - round( $effective_speedy, 2 ) );
			if ( $diff > 0.01 ) {
				return Label_Result::error(
					sprintf(
						/* translators: 1: expected COD amount, 2: Speedy COD amount, 3: currency, 4: difference */
						__( 'Financial mismatch while preparing the shipment label: expected COD amount %1$s %3$s, amount sent to Speedy %2$s %3$s (difference: %4$s %3$s). Shipment label creation is blocked to prevent an incorrect amount.', 'bg-commerce-suite' ),
						number_format( $expected_payable, 2 ),
						number_format( $effective_speedy, 2 ),
						$order_currency,
						number_format( $diff, 2 )
					)
				);
			}

			// Reconcile stored pricing audit data if present
			if ( method_exists( $order, 'get_meta' ) && method_exists( $order, 'get_shipping_total' ) ) {
				$stored_base_cost = $order->get_meta( '_bgcs3_base_cost' );
				$stored_pmt       = $order->get_meta( '_bgcs3_pmt_amount' );
				if ( '' !== (string) $stored_base_cost && '' !== (string) $stored_pmt ) {
					$shipping_gross = (float) $order->get_shipping_total() + ( method_exists( $order, 'get_shipping_tax' ) ? (float) $order->get_shipping_tax() : 0.0 );
					$expected_shipping = (float) $stored_base_cost + (float) $stored_pmt;
					if ( abs( round( $shipping_gross, 2 ) - round( $expected_shipping, 2 ) ) > 0.02 ) {
						return Label_Result::error(
							sprintf(
								/* translators: 1: shipping gross, 2: expected shipping, 3: currency */
								__( 'Financial mismatch in the shipping price: charged on the order %1$s %3$s, expected by audit %2$s %3$s. Shipment label creation is blocked.', 'bg-commerce-suite' ),
								number_format( $shipping_gross, 2 ),
								number_format( $expected_shipping, 2 ),
								$order_currency
							)
						);
					}
				}

				$stored_pmt_base  = $order->get_meta( '_bgcs3_pmt_base' );
				$stored_pmt_payer = strtoupper( (string) $order->get_meta( '_bgcs3_pmt_payer' ) );
				if ( '' !== (string) $stored_pmt_base && '' !== (string) $stored_pmt && (float) $stored_pmt > 0 ) {
					$derived_pmt_base = Financial_Invariants::order_pmt_base( $order, $stored_pmt );
					if ( abs( $derived_pmt_base - round( (float) $stored_pmt_base, 2 ) ) > 0.02 ) {
						return Label_Result::error(
							sprintf(
								/* translators: 1: stored base, 2: independently derived base, 3: currency */
								__( 'Financial mismatch in the PMT base: stored %1$s %3$s, derived from the order %2$s %3$s. Shipment label creation is blocked.', 'bg-commerce-suite' ),
								number_format( (float) $stored_pmt_base, 2 ),
								number_format( $derived_pmt_base, 2 ),
								$order_currency
							)
						);
					}
				}

				if ( '' !== $stored_pmt_payer && ! in_array( $stored_pmt_payer, array( 'SENDER', 'RECIPIENT', 'THIRD_PARTY' ), true ) ) {
					return Label_Result::error( __( 'Shipment label creation is blocked: the saved PMT payer is invalid.', 'bg-commerce-suite' ) );
				}
			}
		}

		$payment = $this->payment_block( $resolved_payer, $wb );
		$third_party_needed = 'THIRD_PARTY' === $resolved_payer
			|| 'THIRD_PARTY' === strtoupper( (string) ( $additional['obpd']['returnShipmentPayer'] ?? '' ) )
			|| 'THIRD_PARTY' === strtoupper( (string) ( $additional['returns']['returnVoucher']['payer'] ?? '' ) );
		if ( $third_party_needed && empty( $payment['thirdPartyClientId'] ) ) {
			return Label_Result::error( __( 'Speedy requires a Third-party client ID when any shipment or return service is paid by a third party. Set it in this order or in the Speedy settings.', 'bg-commerce-suite' ) );
		}

		$body = array(
			'sender'    => $sender,
			'recipient' => $recipient,
			'service'   => $service,
			'content'   => $this->order_content_block( $order, $wb ),
			'payment'   => $payment,
			'ref1'      => Shipment_Reference::for_order( $order ),
			'ref2'      => ( ! empty( $wb['ref1'] ) ) ? (string) $wb['ref1'] : (string) $order->get_order_number(),
		);
		if ( ! empty( $wb['ref2'] ) ) {
			$body['ref2'] = (string) $wb['ref2'];
		}

		$note = $this->shipment_note( $wb, $selection );
		if ( '' !== $note ) {
			$body['shipmentNote'] = $note;
		}

		return $body;
	}

	/**
	 * Maps a create/update shipment response into a Label_Result and stores the
	 * PDF. Both endpoints return the same shape (CreateShipmentResponse).
	 *
	 * @param array<string,mixed>|\WP_Error $response      API response.
	 * @param string                        $generic_error Message when the response is malformed.
	 * @return Label_Result
	 */
	private function label_from_response( $response, $generic_error ) {
		if ( is_wp_error( $response ) || empty( $response['id'] ) || empty( $response['parcels'][0]['id'] ) ) {
			$message = is_wp_error( $response ) ? $response->get_error_message() : $generic_error;
			return Label_Result::error( $message );
		}

		$shipment_id = (string) $response['id'];
		$parcel_ids  = array();
		foreach ( (array) $response['parcels'] as $parcel ) {
			if ( is_array( $parcel ) && ! empty( $parcel['id'] ) ) {
				$parcel_ids[] = (string) $parcel['id'];
			}
		}
		$barcode = $parcel_ids[0];

		$result             = new Label_Result();
		$result->success    = true;
		$result->courier    = self::ID;
		$result->number     = $barcode;
		$result->created_at = time();
		$result->shipment_number = $shipment_id;
		$result->parcel_ids      = $parcel_ids;
		$result->tracking_numbers = $parcel_ids;
		$result->label_reference = $barcode;
		$result->meta            = array( 'shipment_id' => $shipment_id, 'parcel_ids' => $parcel_ids );
		$delivery_estimate = Delivery_Estimate::normalize(
			isset( $response['deliveryDeadline'] ) ? $response['deliveryDeadline'] : '',
			self::ID,
			Delivery_Estimate::KIND_DEADLINE
		);
		if ( ! empty( $delivery_estimate ) ) {
			$result->meta['delivery_estimate'] = $delivery_estimate;
		}
		if ( ! empty( $response['price'] ) && is_array( $response['price'] ) ) {
			$result->meta['price_breakdown'] = $this->price_breakdown( $response['price'] );
		}

		// Fetch + store the PDF (best-effort; the waybill still exists if this fails).
		$pdf = $this->client()->print_pdf( $parcel_ids, (string) Module_Settings::get( self::ID, 'print_paper_size' ) );
		if ( ! is_wp_error( $pdf ) && '' !== $pdf ) {
			$url = Label_Pdf_Store::save( self::ID, $barcode . '.pdf', $pdf );
			if ( $url ) {
				$result->pdf_url = $url;
			}
		}

		return $result;
	}

	/**
	 * Normalize Speedy's ShipmentPrice.details map into a generic, display-safe
	 * audit structure. The API uses receipt item titles as map keys, therefore we
	 * preserve the returned title instead of guessing which line is the
	 * administrative fee. This makes the exact contract surcharge visible to the
	 * merchant whenever Speedy returns it.
	 *
	 * @param array<string,mixed> $price Speedy ShipmentPrice structure.
	 * @return array<string,mixed>
	 */
	private function price_breakdown( array $price ) {
		$items = array();
		foreach ( (array) ( isset( $price['details'] ) ? $price['details'] : array() ) as $title => $detail ) {
			if ( ! is_array( $detail ) || ! isset( $detail['amount'] ) ) {
				continue;
			}
			$items[] = array(
				'label'       => sanitize_text_field( (string) $title ),
				'amount'      => (float) $detail['amount'],
				'percent'     => isset( $detail['percent'] ) ? (float) $detail['percent'] : null,
				'vat_percent' => isset( $detail['vatPercent'] ) ? (float) $detail['vatPercent'] : 0.0,
			);
		}

		return array(
			'currency' => isset( $price['currency'] ) ? strtoupper( sanitize_text_field( (string) $price['currency'] ) ) : '',
			'amount'   => isset( $price['amount'] ) ? (float) $price['amount'] : 0.0,
			'vat'      => isset( $price['vat'] ) ? (float) $price['vat'] : 0.0,
			'total'    => isset( $price['total'] ) ? (float) $price['total'] : 0.0,
			'items'    => $items,
		);
	}

	/**
	 * PMT component already included in a Speedy ShipmentPrice total.
	 *
	 * @param array<string,mixed> $price Speedy ShipmentPrice structure.
	 * @return array{amount:float,vat_percent:float}|null
	 */
	private function api_pmt_component( array $price ) {
		$details = isset( $price['details'] ) && is_array( $price['details'] ) ? $price['details'] : array();
		$entry   = isset( $details['codPremium'] ) && is_array( $details['codPremium'] ) ? $details['codPremium'] : array();

		if ( ! isset( $entry['amount'] ) || ! is_numeric( $entry['amount'] ) || (float) $entry['amount'] <= 0 ) {
			return null;
		}

		return array(
			'amount'      => round( max( 0.0, (float) $entry['amount'] ), 2 ),
			'vat_percent' => isset( $entry['vatPercent'] ) && is_numeric( $entry['vatPercent'] )
				? max( 0.0, (float) $entry['vatPercent'] )
				: 0.0,
		);
	}

	/**
	 * @param \WC_Order $order  Order.
	 * @param string    $number Barcode.
	 * @return mixed|\WP_Error
	 */
	protected function cancel_shipment( \WC_Order $order, $number ) {
		// Спиди отказва по вътрешния shipment id, не по баркода.
		$shipment_id = (string) $this->label_meta( $order, 'shipment_id', '' );

		if ( '' === $shipment_id ) {
			return false;
		}

		return $this->client()->cancel_shipment( $shipment_id, __( 'Cancelled by store', 'bg-commerce-suite' ) );
	}

	/**
	 * @param string $number Barcode.
	 * @return array<string,mixed>|\WP_Error
	 */
	/** @var bool Whether the next fetch may ask for the newest operation only. */
	private $last_operation_only = false;

	/**
	 * Speedy's own good practice: when the caller tracks repeatedly and keeps
	 * the status locally, ask for the last operation only — smaller response,
	 * faster call. Safe here ONLY because history is already stored; on the
	 * first-ever fetch the full history is requested, otherwise a shipment
	 * picked up mid-flight would lose every event before this moment.
	 *
	 * @param \WC_Order $order Order.
	 * @return Tracking_Result
	 */
	public function tracking( \WC_Order $order ) {
		$stored                    = $order->get_meta( '_bgcs3_tracking' );
		$this->last_operation_only = is_array( $stored ) && ! empty( $stored['events'] );

		$result = parent::tracking( $order );

		$this->last_operation_only = false;

		return $result;
	}

	protected function fetch_tracking( $number ) {
		return $this->client()->track( $number, $this->last_operation_only );
	}

	/**
	 * Speedy accept up to ten parcels in one `track` request.
	 *
	 * @return bool
	 */
	public function supports_bulk_tracking() {
		return true;
	}

	/**
	 * @return int Their documented maximum.
	 */
	public function tracking_batch_size() {
		return 10;
	}

	/**
	 * One request, many parcels.
	 *
	 * At 400 shipments a day this is 40 calls instead of 400 — and Speedy ask
	 * for exactly this rather than repeated single-parcel polling.
	 *
	 * @param string[] $numbers   Waybill numbers.
	 * @param bool     $last_only Every order already has stored history.
	 * @return array<string,Tracking_Result> Keyed by waybill number.
	 */
	public function bulk_tracking( array $numbers, $last_only = false ) {
		$response = $this->client()->track( $numbers, $last_only );

		if ( is_wp_error( $response ) ) {
			// Rule 256 — one failed call must leave every order in the chunk
			// exactly as it was, not mark them all as having no tracking.
			return array();
		}

		$out = array();

		foreach ( (array) ( isset( $response['parcels'] ) ? $response['parcels'] : array() ) as $parcel ) {
			if ( ! empty( $parcel['error'] ) ) {
				// Individual parcel returned an error -> skip to prevent false tracking state.
				continue;
			}

			// `parcelId`, NOT `id`. The request sends `id`, the response answers
			// with `parcelId` — verified against the live API. The single-parcel
			// path never noticed, because it reads `parcels[0]` by index; only
			// the batched path has to match a parcel back to its order, and
			// reading the wrong key made batching silently apply nothing at all.
			$id = '';
			foreach ( array( 'parcelId', 'id' ) as $key ) {
				if ( ! empty( $parcel[ $key ] ) ) {
					$id = (string) $parcel[ $key ];
					break;
				}
			}
			if ( '' === $id ) {
				continue;
			}

			$result          = new Tracking_Result();
			$result->success = true;
			$this->fill_events( $result, isset( $parcel['operations'] ) ? (array) $parcel['operations'] : array() );

			$out[ $id ] = $result;
		}

		return $out;
	}

	/**
	 * @param Tracking_Result     $result   Result.
	 * @param array<string,mixed> $response API response.
	 * @return void
	 */
	protected function fill_tracking( Tracking_Result $result, array $response ) {
		$operations = isset( $response['parcels'][0]['operations'] ) ? $response['parcels'][0]['operations'] : array();

		$this->fill_events( $result, (array) $operations );
	}

	/**
	 * Shared event mapping, so the single and batched paths cannot describe the
	 * same operation differently.
	 *
	 * @param Tracking_Result      $result     Result.
	 * @param array<int,mixed>     $operations Operations node.
	 * @return void
	 */
	private function fill_events( Tracking_Result $result, array $operations ) {
		foreach ( $operations as $op ) {
			$result->events[] = array(
				'time' => isset( $op['dateTime'] ) ? $op['dateTime'] : '',
				'code' => isset( $op['operationCode'] ) ? (string) $op['operationCode'] : '',
				'text' => isset( $op['description'] ) ? $op['description'] : '',
			);
		}
	}

	/**
	 * Normalize Speedy's documented Track & Trace operation codes to BGCS states.
	 * Exact-code matching only; unlisted provider codes stay UNKNOWN and therefore
	 * cannot trigger a WooCommerce status transition by accident.
	 *
	 * @param array<string,mixed> $event Tracking event.
	 * @return string One of Tracking_State::*.
	 */
	public function normalize_status( array $event ) {
		$code = isset( $event['code'] ) ? (int) $event['code'] : 0;

		if ( -14 === $code ) {
			return Tracking_State::DELIVERED;
		}
		if ( 148 === $code ) {
			return Tracking_State::CREATED;
		}
		if ( 39 === $code ) {
			return Tracking_State::ACCEPTED;
		}
		if ( in_array( $code, array( 1, 2, 21, 38, 116, 144, 152, 175, 176, 217 ), true ) ) {
			return Tracking_State::IN_TRANSIT;
		}
		if ( 11 === $code ) {
			// 'Received in Office' can be an intermediate office; without order
			// context it is not safe to tell the merchant the parcel is ready.
			return Tracking_State::IN_TRANSIT;
		}
		if ( in_array( $code, array( 134, 1134 ), true ) ) {
			return Tracking_State::AVAILABLE_FOR_PICKUP;
		}
		if ( 12 === $code ) {
			return Tracking_State::OUT_FOR_DELIVERY;
		}
		if ( 44 === $code ) {
			return Tracking_State::DELIVERY_FAILED;
		}
		if ( 115 === $code ) {
			return Tracking_State::REDIRECTED;
		}
		if ( in_array( $code, array( 111, 121, 123 ), true ) ) {
			return Tracking_State::RETURN_IN_PROGRESS;
		}
		if ( 124 === $code ) {
			return Tracking_State::RETURNED;
		}
		if ( 128 === $code ) {
			return Tracking_State::CANCELLED;
		}
		if ( in_array( $code, array( 69, 112, 114, 125, 127, 129, 136, 164, 169, 181, 190, 195 ), true ) ) {
			return Tracking_State::EXCEPTION;
		}

		return Tracking_State::UNKNOWN;
	}

	/**
	 * Speedy provides official payout reports via POST /v1/payments.
	 *
	 * @return bool
	 */
	public function supports_cod_payouts() {
		return true;
	}

	/**
	 * @param string $from Start, Y-m-d.
	 * @param string $to   End, Y-m-d.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function cod_payouts( $from, $to ) {
		$valid = Payouts::check_range( $from, $to );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$response = $this->client()->cod_payouts( $from, $to );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return Payouts::rows( (array) $response );
	}

	/**
	 * @param string $number Parcel number.
	 * @return string
	 */
	public function tracking_url( $number ) {
		return 'https://www.speedy.bg/bg/track-shipment?shipmentNumber=' . rawurlencode( (string) $number );
	}

	/**
	 * Speedy-specific readable order fields (saved as their own custom fields).
	 * Mirrors the data the customer chose: destination type, site, office/APT or
	 * address, the chosen service and the shipping price.
	 *
	 * @param \WC_Order                     $order     Order.
	 * @param \BgCommerce3\Support\Selection $selection Selection.
	 * @return array<string,string>
	 */
	public function order_meta_fields( \WC_Order $order, $selection ) {
		$dt          = $selection->delivery_type;
		$shipping_to = ( 'locker' === $dt ) ? 'APT' : ( ( 'address' === $dt ) ? 'ADDRESS' : 'OFFICE' );

		$fields = array(
			'bgcs3_speedy_shipping_to'  => $shipping_to,
			'bgcs3_speedy_country_id'   => (string) self::COUNTRY_BG,
			'bgcs3_speedy_country_name' => __( 'Bulgaria', 'bg-commerce-suite' ),
			'bgcs3_speedy_site_id'      => isset( $selection->city['id'] ) ? (string) $selection->city['id'] : '',
			'bgcs3_speedy_site_name'    => isset( $selection->city['name'] ) ? (string) $selection->city['name'] : '',
			'bgcs3_speedy_post_code'    => isset( $selection->city['post_code'] ) ? (string) $selection->city['post_code'] : '',
		);

		if ( 'locker' === $dt ) {
			$fields['bgcs3_speedy_apt_id']   = isset( $selection->office['id'] ) ? (string) $selection->office['id'] : '';
			$fields['bgcs3_speedy_apt_name'] = isset( $selection->office['text'] ) ? (string) $selection->office['text'] : '';
		} elseif ( 'office' === $dt ) {
			$fields['bgcs3_speedy_office_id']   = isset( $selection->office['id'] ) ? (string) $selection->office['id'] : '';
			$fields['bgcs3_speedy_office_name'] = isset( $selection->office['text'] ) ? (string) $selection->office['text'] : '';
		} else {
			$fields['bgcs3_speedy_street']      = isset( $selection->address['street'] ) ? (string) $selection->address['street'] : '';
			$fields['bgcs3_speedy_street_no']   = isset( $selection->address['num'] ) ? (string) $selection->address['num'] : '';
			$fields['bgcs3_speedy_addr_note']   = isset( $selection->address['note'] ) ? (string) $selection->address['note'] : '';
		}

		$service_id = (string) bgcs3_get_option( self::ID, 'service_id', '' );
		if ( '' !== $service_id ) {
			$fields['bgcs3_speedy_service_id'] = $service_id;
			$services                         = $this->locations()->services();
			if ( isset( $services[ $service_id ] ) ) {
				$fields['bgcs3_speedy_service_name'] = $services[ $service_id ];
			}
		}

		$shipping_total = \BgCommerce3\Shipping\Order_Persistence::courier_shipping_total( $order, self::ID );
		if ( $shipping_total > 0 ) {
			$fields['bgcs3_speedy_price'] = number_format( $shipping_total, 2, '.', '' );
		}

		return $fields;
	}

	/**
	 * Build the Speedy recipient block per delivery type, exactly as the Speedy
	 * API expects:
	 *  - office/locker: { pickupOfficeId }
	 *  - address (calculate): { addressLocation: { siteId } }
	 *  - address (shipment): { address: { countryId, siteId, streetId|streetName, streetNo } }
	 *
	 * @param Selection           $selection    Selection.
	 * @param array<string,mixed> $base         Base recipient fields (name/phone/email/privatePerson).
	 * @param bool                $for_shipment Shipment (true) vs calculate (false).
	 * @return array<string,mixed>
	 */
	private function recipient( Selection $selection, array $base, $for_shipment = false ) {
		$site_id = isset( $selection->city['id'] ) ? (int) $selection->city['id'] : 0;

		if ( in_array( $selection->delivery_type, array( 'office', 'locker' ), true ) ) {
			$base['pickupOfficeId']          = isset( $selection->office['id'] ) ? (int) $selection->office['id'] : 0;
			$base['autoSelectNearestOffice'] = false;
			return $base;
		}

		if ( $for_shipment ) {
			$street_id       = isset( $selection->address['street_id'] ) ? (int) $selection->address['street_id'] : 0;
			$base['address'] = array(
				'countryId' => self::COUNTRY_BG,
				'siteId'    => $site_id,
				'streetNo'  => isset( $selection->address['num'] ) ? (string) $selection->address['num'] : '',
			);
			if ( $street_id > 0 ) {
				$base['address']['streetId'] = $street_id;
			} else {
				$base['address']['streetName'] = isset( $selection->address['street'] ) ? (string) $selection->address['street'] : '';
			}

			// Structured address details
			if ( ! empty( $selection->address['block'] ) ) {
				$base['address']['blockNo'] = (string) $selection->address['block'];
			} elseif ( ! empty( $selection->address['block_no'] ) ) {
				$base['address']['blockNo'] = (string) $selection->address['block_no'];
			}

			if ( ! empty( $selection->address['entrance'] ) ) {
				$base['address']['entranceNo'] = (string) $selection->address['entrance'];
			} elseif ( ! empty( $selection->address['entrance_no'] ) ) {
				$base['address']['entranceNo'] = (string) $selection->address['entrance_no'];
			}

			if ( ! empty( $selection->address['floor'] ) ) {
				$base['address']['floorNo'] = (string) $selection->address['floor'];
			} elseif ( ! empty( $selection->address['floor_no'] ) ) {
				$base['address']['floorNo'] = (string) $selection->address['floor_no'];
			}

			if ( ! empty( $selection->address['apartment'] ) ) {
				$base['address']['apartmentNo'] = (string) $selection->address['apartment'];
			} elseif ( ! empty( $selection->address['apartment_no'] ) ) {
				$base['address']['apartmentNo'] = (string) $selection->address['apartment_no'];
			}

			$complex_id = isset( $selection->address['complex_id'] ) ? (int) $selection->address['complex_id'] : ( isset( $selection->address['quarter_id'] ) ? (int) $selection->address['quarter_id'] : 0 );
			if ( $complex_id > 0 ) {
				$base['address']['complexId'] = $complex_id;
			} elseif ( ! empty( $selection->address['complex'] ) ) {
				$base['address']['complexName'] = (string) $selection->address['complex'];
			} elseif ( ! empty( $selection->address['quarter'] ) ) {
				$base['address']['complexName'] = (string) $selection->address['quarter'];
			}

			if ( ! empty( $selection->address['note'] ) ) {
				$base['address']['addressNote'] = (string) $selection->address['note'];
			} elseif ( ! empty( $selection->address['address_note'] ) ) {
				$base['address']['addressNote'] = (string) $selection->address['address_note'];
			}
		} else {
			$base['addressLocation'] = array( 'countryId' => self::COUNTRY_BG, 'siteId' => $site_id );
		}

		return $base;
	}

	/**
	 * Sender block required by the calculate/shipment API. The contract client
	 * remains authoritative; office handover adds dropoffOfficeId to clientId.
	 *
	 * @return array<string,mixed>
	 */
	private function sender( array $wb = array() ) {
		$sender        = array();
		$sender_type   = $this->wbx( $wb, 'sender_type' );
		$client_id     = $this->wbx( $wb, 'sender_client_id' );
		$dropoff       = $this->wbx( $wb, 'sender_dropoff_office_id' );
		$global_client = (string) Module_Settings::get( self::ID, 'client_id' );

		if ( '' === $sender_type ) {
			$client_id   = $global_client;
			$dropoff     = (string) bgcs3_get_option( self::ID, 'dropoff_office_id', '' );
			$sender_type = 'office' === (string) Module_Settings::get( self::ID, 'sender_handover' ) ? 'office' : 'client';
		} else {
			if ( '' === $client_id ) {
				$client_id = $global_client;
			}
			if ( 'office' === $sender_type && '' === $dropoff ) {
				$dropoff = (string) bgcs3_get_option( self::ID, 'dropoff_office_id', '' );
			}
		}

		if ( (int) $client_id > 0 && 'client' === $sender_type ) {
			$sender = array( 'clientId' => (int) $client_id );
		} elseif ( (int) $client_id > 0 && 'office' === $sender_type && (int) $dropoff > 0 ) {
			$sender = array(
				'clientId'        => (int) $client_id,
				'dropoffOfficeId' => (int) $dropoff,
			);
		}

		// Advanced: allow overriding the sender (e.g. drop-off-at-office mode).
		return (array) apply_filters( 'bgcs3_speedy_sender', $sender, $wb );
	}

	/**
	 * COD base used during live checkout calculation. Fee callbacks are allowed
	 * to see the real Speedy checkout price, but that transport amount is added
	 * to COD only when it is NOT collected directly from the recipient by Speedy.
	 *
	 * @param array<string,mixed> $package   WooCommerce package.
	 * @param float               $base_cost Current Speedy transport price.
	 * @param string              $payer     Effective courier-service payer.
	 * @param float               $sender_paid_surcharges Shipping components recovered through COD even when transport is paid directly.
	 * @return float
	 */
	private function resolve_checkout_cod_base( array $package, $base_cost, $payer, $sender_paid_surcharges = 0.0 ) {
		$base_cost = max( 0.0, (float) $base_cost );

		if ( 'RECIPIENT' !== strtoupper( (string) $payer ) ) {
			return $this->resolve_pmt_base( $package, $base_cost );
		}

		$pmt_base = 0.0;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$cart          = WC()->cart;
			$cart_subtotal = method_exists( $cart, 'get_subtotal' ) ? (float) $cart->get_subtotal() : ( method_exists( $cart, 'get_displayed_subtotal' ) ? (float) $cart->get_displayed_subtotal() : 0.0 );
			$cart_tax      = method_exists( $cart, 'get_subtotal_tax' ) ? (float) $cart->get_subtotal_tax() : 0.0;
			$discount      = ( method_exists( $cart, 'get_discount_total' ) ? (float) $cart->get_discount_total() : 0.0 ) + ( method_exists( $cart, 'get_discount_tax' ) ? (float) $cart->get_discount_tax() : 0.0 );

			if ( $cart_subtotal <= 0 && method_exists( $cart, 'get_total' ) ) {
				$cart_subtotal = (float) $cart->get_total( 'edit' );
			}

			$merchandise = max( 0.0, $cart_subtotal + $cart_tax - $discount );
			$fees_total  = $this->current_cycle_fee_gross( $cart, $base_cost );
			$pmt_base    = $merchandise + $fees_total;
		} elseif ( ! empty( $package['contents_cost'] ) ) {
			$pmt_base = (float) $package['contents_cost'];
		}

		// Recipient-paid transport is outside COD, but a sender-paid PMT component
		// recovered from the customer remains inside the amount Speedy must collect.
		// This is the same split later used by resolved_cod_amount_for_payer().
		$pmt_base += max( 0.0, (float) $sender_paid_surcharges );

		return round( max( 0.0, $pmt_base ), 2 );
	}

	/**
	 * Who pays the courier service (waybill override → setting → SENDER).
	 *
	 * When Recipient is configured together with an API-priced checkout, it is
	 * meaningful for COD orders: Speedy collects the courier service directly and
	 * BGCS excludes that component from COD. For prepaid orders the WooCommerce
	 * shipping line has already been paid to the store, so Sender becomes the
	 * effective payer to avoid collecting the same delivery again.
	 *
	 * @param array<string,mixed> $wb         Waybill overrides.
	 * @param bool|null           $cod_active Whether the current flow is COD. Null keeps the configured value.
	 * @return string
	 */
	private function payer( $wb = array(), $cod_active = null ) {
		if ( Pricing::MODE_OWN === Pricing::mode( self::ID ) ) {
			return 'SENDER';
		}

		$p = ( ! empty( $wb['payer'] ) )
			? strtoupper( (string) $wb['payer'] )
			: strtoupper( (string) Module_Settings::get( self::ID, 'service_payer' ) );
		$p = in_array( $p, array( 'RECIPIENT', 'SENDER', 'THIRD_PARTY' ), true ) ? $p : 'SENDER';

		if ( 'RECIPIENT' === $p && false === $cod_active ) {
			return 'SENDER';
		}

		return $p;
	}

	/**
	 * Effective payer for shipment creation. The checkout-selected payer is kept
	 * on the order so later changes in global settings cannot silently change who
	 * pays an already-priced shipment. Custom/free pricing remains sender-paid.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return string
	 */
	private function shipment_payer( \WC_Order $order, array $wb ) {
		$pricing_mode = strtoupper( (string) $order->get_meta( '_bgcs3_pricing_mode' ) );
		if ( in_array( strtolower( $pricing_mode ), array( 'static', 'free' ), true ) ) {
			return 'SENDER';
		}

		$cod_mode   = Overrides::mode( $wb, 'cod_mode' );
		$cod_active = Cod::is_order( $order );
		if ( Overrides::DISABLED === $cod_mode ) {
			$cod_active = false;
		} elseif ( Overrides::CUSTOM === $cod_mode && isset( $wb['cod_amount'] ) && '' !== (string) $wb['cod_amount'] ) {
			$cod_active = (float) $wb['cod_amount'] > 0;
		}

		if ( ! empty( $wb['payer'] ) ) {
			$override = strtoupper( (string) $wb['payer'] );
			$override = in_array( $override, array( 'SENDER', 'RECIPIENT', 'THIRD_PARTY' ), true ) ? $override : 'SENDER';
			return ( 'RECIPIENT' === $override && ! $cod_active ) ? 'SENDER' : $override;
		}

		$stored = strtoupper( trim( (string) $order->get_meta( '_bgcs3_courier_service_payer' ) ) );
		if ( in_array( $stored, array( 'SENDER', 'RECIPIENT', 'THIRD_PARTY' ), true ) ) {
			if ( 'RECIPIENT' === $stored && ! $cod_active ) {
				return 'SENDER';
			}
			return $stored;
		}

		return $this->payer( $wb, $cod_active );
	}

	/**
	 * Canonical financial facts for the local shipment snapshot.
	 *
	 * The snapshot must reflect the exact payload semantics used at create time.
	 * In particular, recipient-paid Speedy courier service is collected outside
	 * the COD amount, so the stored COD value is lower than the WooCommerce order
	 * total by exactly the recipient-paid transport component.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return array{payer:string,cod_amount:float,cod_currency:string}
	 */
	public function label_snapshot_financials( \WC_Order $order, array $wb ) {
		$payer = $this->shipment_payer( $order, $wb );

		return array(
			'payer'        => $payer,
			'cod_amount'   => $this->resolved_cod_amount_for_payer( $order, $wb, $payer ),
			'cod_currency' => strtoupper( (string) $order->get_currency() ),
		);
	}

	/**
	 * Speedy transport amount that the recipient pays directly, rather than as
	 * part of COD. The live API base price is persisted separately from BGCS
	 * sender-paid surcharges (for example a merchant-recovered PMT premium), so
	 * only the courier component is removed from COD.
	 *
	 * @param \WC_Order $order Order.
	 * @return float
	 */
	private function recipient_direct_shipping_amount( \WC_Order $order ) {
		$shipping_gross = (float) $order->get_shipping_total();
		if ( method_exists( $order, 'get_shipping_tax' ) ) {
			$shipping_gross += (float) $order->get_shipping_tax();
		}
		$shipping_gross = round( max( 0.0, $shipping_gross ), 2 );

		$stored_base = $order->get_meta( '_bgcs3_base_cost' );
		if ( '' !== (string) $stored_base && is_numeric( $stored_base ) ) {
			$base = round( max( 0.0, (float) $stored_base ), 2 );
			if ( $base > 0 ) {
				return min( $base, $shipping_gross > 0 ? $shipping_gross : $base );
			}
		}

		$stored_pmt = $order->get_meta( '_bgcs3_pmt_amount' );
		$pmt_amount = ( '' !== (string) $stored_pmt && is_numeric( $stored_pmt ) ) ? max( 0.0, (float) $stored_pmt ) : 0.0;
		return round( max( 0.0, $shipping_gross - $pmt_amount ), 2 );
	}

	/**
	 * Resolve the exact COD amount for the effective Speedy payer. A manual COD
	 * override is always literal. With inherited COD and recipient-paid courier
	 * service, only the transport component is removed because Speedy collects it
	 * separately from the recipient.
	 *
	 * @param \WC_Order            $order          Order.
	 * @param array<string,mixed> $wb             Waybill overrides.
	 * @param string              $courier_payer Effective courier-service payer.
	 * @return float
	 */
	private function resolved_cod_amount_for_payer( \WC_Order $order, array $wb, $courier_payer ) {
		$mode = Overrides::mode( $wb, 'cod_mode' );
		if ( Overrides::DISABLED === $mode ) {
			return 0.0;
		}

		if ( Overrides::CUSTOM === $mode && isset( $wb['cod_amount'] ) && '' !== (string) $wb['cod_amount'] ) {
			return round( max( 0.0, (float) $wb['cod_amount'] ), 2 );
		}

		$amount = Cod::amount( $order );
		if ( 'RECIPIENT' === strtoupper( (string) $courier_payer ) && $amount > 0 ) {
			$amount -= $this->recipient_direct_shipping_amount( $order );
		}

		return round( max( 0.0, $amount ), 2 );
	}

	/**
	 * Payment block — the payer applies to the courier service, declared value
	 * and packaging (as the Speedy waybill expects).
	 *
	 * The `service` block of a shipment: the configured service plus the
	 * schedule-affecting options (ShipmentService — saturdayDelivery,
	 * deferredDays), each resolved per-order-override → setting.
	 *
	 * @param string              $service_id Configured service id.
	 * @param array<string,mixed> $wb         Waybill overrides.
	 * @return array<string,mixed>
	 */
	private function service_block( $service_id, array $wb ) {
		$service = array(
			'serviceId'            => (int) $service_id,
			'autoAdjustPickupDate' => true,
		);

		// Send the resolved boolean explicitly. This matters on a full update: omitting
		// the property lets Speedy auto-determine Saturday delivery and would not
		// reliably clear a previously enabled value.
		$service['saturdayDelivery'] = $this->wbx_bool( $wb, 'saturday', 'saturday_delivery' );

		// Deferred delivery is also sent explicitly, including zero, so a later
		// update can clear a previous 1/2-day deferral.
		$deferred = $this->wbx( $wb, 'deferred_days' );
		if ( '' === $deferred ) {
			$deferred = (string) Module_Settings::get( self::ID, 'deferred_days' );
		}
		$service['deferredDays'] = in_array( $deferred, array( '0', '1', '2' ), true ) ? (int) $deferred : 0;

		return $service;
	}

	/**
	 * Забележка към товарителницата (CreateShipmentRequest.shipmentNote), capped
	 * at the contract's 200-character limit.
	 *
	 * @param array<string,mixed> $wb        Waybill overrides.
	 * @param Selection           $selection Saved order delivery selection.
	 * @return string '' when none.
	 */
	private function shipment_note( array $wb, Selection $selection ) {
		$note = $this->wbx( $wb, 'note' );
		if (
			'' === $note
			&& 'locker' === (string) $selection->delivery_type
			&& self::LOCKER_CAPACITY_POLICY_MERCHANT_INSTRUCTION === (string) Module_Settings::get( self::ID, 'locker_capacity_policy' )
		) {
			$note = trim( (string) Module_Settings::get( self::ID, 'locker_capacity_note' ) );
		}

		return function_exists( 'bgcs3_substr' ) ? bgcs3_substr( $note, 0, self::SHIPMENT_NOTE_MAX_LENGTH ) : substr( $note, 0, self::SHIPMENT_NOTE_MAX_LENGTH );
	}

	/**
	 * Who pays for a charge that is not the courier service itself.
	 *
	 * Fixed to the sender, because that is what the settings screen leads the
	 * merchant to expect: „Courier service payer“ says nothing about an insurance
	 * premium or a packaging fee, and a customer asked at the door for more than
	 * the order total is a dispute, not a sale.
	 *
	 * A shop that genuinely wants the recipient to carry these can say so through
	 * the filter — deliberately not a settings field, so the common case stays
	 * one decision rather than three (ADR-008, Variant A).
	 *
	 * @param string              $role           'declared_value' or 'package'.
	 * @param string              $service_payer  The courier-service payer.
	 * @param array<string,mixed> $wb             Waybill overrides.
	 * @return string SENDER|RECIPIENT|THIRD_PARTY
	 */
	private function ancillary_payer( $role, $service_payer, $wb = array() ) {
		/**
		 * Filter who pays a Speedy charge other than the courier service.
		 *
		 * @param string              $payer         SENDER|RECIPIENT|THIRD_PARTY.
		 * @param string              $role          'declared_value' or 'package'.
		 * @param string              $service_payer The courier-service payer.
		 * @param array<string,mixed> $wb            Waybill overrides for this order.
		 */
		$payer = apply_filters( 'bgcs3_speedy_ancillary_payer', 'SENDER', $role, $service_payer, (array) $wb );

		$payer = strtoupper( trim( (string) $payer ) );

		// A filter returning something Speedy has no role for would have the
		// courier refuse the whole shipment; fall back rather than fail.
		return in_array( $payer, array( 'SENDER', 'RECIPIENT', 'THIRD_PARTY' ), true ) ? $payer : 'SENDER';
	}

	/**
	 * @param string              $payer Payer.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return array<string,mixed>
	 */
	private function payment_block( $payer, $wb = array() ) {
		// BGCS-AUDIT-013 — Speedy models three independent roles
		// (`ShipmentPayment.schema.json`), and BGCS used to set all three from the
		// one „Courier service payer“ setting. A merchant who chose RECIPIENT so
		// the customer pays the delivery at the door was also, silently, charging
		// them the declared-value insurance premium and the packaging fee —
		// neither offered as a choice, neither reflected in the WooCommerce total,
		// and both outside what that field's own label describes.
		//
		// The premium and the packaging stay with the sender unless a shop
		// deliberately says otherwise. See ADR-008.
		$payment = array(
			'courierServicePayer' => $payer,
			'declaredValuePayer'  => $this->ancillary_payer( 'declared_value', $payer, $wb ),
			'packagePayer'        => $this->ancillary_payer( 'package', $payer, $wb ),
		);

		// THIRD_PARTY е валиден платец само с посочен договорен клиент
		// (ShipmentPayment.thirdPartyClientId) — иначе Спиди отказва пратката.
		// Важи за всяка от ролите, включително двете, които вече се решават
		// отделно — иначе филтър, който насочи премията към трета страна, би
		// произвел пратка, която Speedy отказва.
		$uses_third_party = in_array(
			'THIRD_PARTY',
			array( $payer, $payment['declaredValuePayer'], $payment['packagePayer'] ),
			true
		);
		$obp_payer = $this->wbx( (array) $wb, 'obp_return_payer' );
		if ( '' === $obp_payer ) {
			$obp_payer = (string) Module_Settings::get( self::ID, 'obp_return_payer' );
		}
		$voucher_payer = $this->wbx( (array) $wb, 'return_voucher_payer' );
		if ( '' === $voucher_payer ) {
			$voucher_payer = (string) Module_Settings::get( self::ID, 'return_voucher_payer' );
		}
		$uses_third_party = $uses_third_party || 'THIRD_PARTY' === $obp_payer || 'THIRD_PARTY' === $voucher_payer;

		if ( $uses_third_party ) {
			$third_party_raw = $this->wbx( (array) $wb, 'third_party_client_id' );
			$third_party     = '' !== $third_party_raw
				? (int) $third_party_raw
				: (int) Module_Settings::get( self::ID, 'third_party_client_id' );
			if ( $third_party > 0 ) {
				$payment['thirdPartyClientId'] = $third_party;
			}
		}

		// `payment.administrativeFee` е BOOLEAN в договора на Speedy
		// (ShipmentPayment.schema.json) — флаг „начисли административната такса
		// по договора“, а не сума. Досега пращахме число от текстово поле.
		//
		// Enforced independently of the settings UI (§24/§25): only applies at
		// API pricing + payer RECIPIENT. Own prices always ships SENDER (see
		// payer() above), so this branch is naturally unreachable for own
		// prices, but the explicit pricing_mode check documents and guarantees
		// the rule even if payer() semantics ever change.
		if ( 'RECIPIENT' === $payer
			&& Pricing::MODE_API === Pricing::mode( self::ID )
			&& $this->wbx_bool( (array) $wb, 'admin_fee', 'administrative_fee' ) ) {
			$payment['administrativeFee'] = true;
		}

		return $payment;
	}

	/**
	 * Content block for a shipment: contents description + parcel weight/size.
	 * Honors admin waybill overrides (weight, dimensions, description).
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return array<string,mixed>
	 */
	private function order_content_block( \WC_Order $order, $wb = array() ) {
		$wb     = is_array( $wb ) ? $wb : array();
		$weight   = ( ! empty( $wb['weight'] ) ) ? max( Weight::MIN_KG, (float) $wb['weight'] ) : $this->order_weight( $order );
		$contents = ( ! empty( $wb['contents'] ) ) ? (string) $wb['contents'] : $this->order_contents( $order );

		$package = ( ! empty( $wb['package_type'] ) ) ? trim( (string) $wb['package_type'] ) : trim( (string) Module_Settings::get( self::ID, 'default_package' ) );

		// Two kinds of text end up in this field and they deserve opposite treatment.
		//
		// What the merchant typed is sent whole, never trimmed. Speedy caps the
		// description at 100 — confirmed by its own validation refusal, not guessed
		// — so an over-long entry is stopped before anything is created, with the
		// courier's own message naming the field and the number. Losing half of
		// what someone deliberately wrote, silently, is how the original defect
		// went unnoticed for so long.
		//
		// The fallback text is ours: `order_contents()` joins the product names
		// because the merchant left the field empty. Three ordinary product names
		// already reach ~85 characters, so refusing to create a shipment over text
		// nobody wrote would be absurd. We generated it, so we may shorten it.
		if ( ! empty( $wb['contents'] ) ) {
			$sent_contents = $contents;
		} else {
			$sent_contents = function_exists( 'bgcs3_substr' )
				? bgcs3_substr( $contents, 0, self::CONTENTS_MAX_LENGTH )
				: substr( $contents, 0, self::CONTENTS_MAX_LENGTH );
		}

		$content = array(
			'package'  => '' !== $package ? $package : 'BOX',
			'contents' => $sent_contents,
		);

		// ShipmentContent flags — изпращат се само когато са включени, за да не
		// презаписват договорни настройки с изричен `false`.
		if ( $this->wbx_bool( $wb, 'documents' ) ) {
			$content['documents'] = true;
		}
		if ( $this->wbx_bool( $wb, 'palletized' ) ) {
			$content['palletized'] = true;
		}

		// Real per-parcel packages, each with its own size+weight
		// (docs/speedy_api_reference.md §4 "Мулти-колет": `content.parcels[]`
		// with `seqNo`/`size`/`weight` per entry) — takes priority over the
		// legacy single-weight/dims fields below when present and complete.
		$parcels = $this->packages_to_parcels( ( ! empty( $wb['packages'] ) && is_array( $wb['packages'] ) ) ? $wb['packages'] : array() );
		if ( ! empty( $parcels ) ) {
			$content['parcelsCount'] = count( $parcels );
			$content['parcels']      = $parcels;
			return $content;
		}

		$legacy_count = ( isset( $wb['parcels'] ) && (int) $wb['parcels'] > 1 ) ? (int) $wb['parcels'] : 1;
		$content['parcelsCount'] = $legacy_count;

		// Multiple parcels (legacy count-only field): send count + total weight
		// (no per-parcel dimensions — only the modern `packages` path above has those).
		if ( $legacy_count > 1 ) {
			$content['totalWeight'] = $weight;
			return $content;
		}

		$dimensions = Package_Dimensions::resolve_for_order(
			$order,
			array(
				'length' => isset( $wb['depth'] ) ? $wb['depth'] : '',
				'width'  => isset( $wb['width'] ) ? $wb['width'] : '',
				'height' => isset( $wb['height'] ) ? $wb['height'] : '',
			),
			array(
				'length' => Module_Settings::get( self::ID, 'default_depth' ),
				'width'  => Module_Settings::get( self::ID, 'default_width' ),
				'height' => Module_Settings::get( self::ID, 'default_height' ),
			)
		);
		$w = ! empty( $dimensions ) ? (float) $dimensions['width'] : 0.0;
		$d = ! empty( $dimensions ) ? (float) $dimensions['length'] : 0.0;
		$h = ! empty( $dimensions ) ? (float) $dimensions['height'] : 0.0;

		if ( $w > 0 && $d > 0 && $h > 0 ) {
			$content['parcels'] = array(
				array(
					'seqNo'  => 1,
					'weight' => $weight,
					'size'   => array(
						'width'  => ceil( $w ),
						'depth'  => ceil( $d ),
						'height' => ceil( $h ),
					),
				),
			);
		} else {
			$content['totalWeight'] = $weight;
		}

		return $content;
	}

	/**
	 * Converts `_bgcs3_wb['packages']` (our courier-agnostic multi-pack editor
	 * shape) into Speedy's `content.parcels[]` entries. Any single incomplete
	 * pack (missing/zero weight or a dimension) discards the WHOLE array —
	 * never sends a partially-valid multi-parcel payload — so the caller
	 * falls back to the legacy count/weight-only path instead.
	 *
	 * @param array<int,array<string,mixed>> $packages Packs from the admin editor.
	 * @return array<int,array<string,mixed>>
	 */
	private function packages_to_parcels( array $packages ) {
		if ( empty( $packages ) ) {
			return array();
		}

		$parcels = array();
		$seq     = 1;
		foreach ( $packages as $pack ) {
			$length = isset( $pack['length'] ) ? (float) $pack['length'] : 0.0;
			$width  = isset( $pack['width'] ) ? (float) $pack['width'] : 0.0;
			$height = isset( $pack['height'] ) ? (float) $pack['height'] : 0.0;
			$weight = isset( $pack['weight'] ) ? (float) $pack['weight'] : 0.0;

			if ( $length <= 0 || $width <= 0 || $height <= 0 || $weight <= 0 ) {
				return array();
			}

			$parcels[] = array(
				'seqNo'  => $seq++,
				'weight' => max( Weight::MIN_KG, round( $weight, 3 ) ),
				// Speedy calls the 3rd dimension "depth" (see the legacy
				// single-pack path above, which maps our own "Дължина"/length
				// field the same way) — our pack row calls it "length"; same
				// physical measurement, different field name.
				'size'   => array(
					'width'  => ceil( $width ),
					'depth'  => ceil( $length ),
					'height' => ceil( $height ),
				),
			);
		}

		return $parcels;
	}

	/**
	 * Contents description from the order items (comma-joined names).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function order_contents( \WC_Order $order ) {
		$names = array();
		foreach ( $order->get_items() as $item ) {
			$names[] = $item->get_name();
		}
		$text = trim( implode( ', ', array_filter( $names ) ) );
		return '' !== $text ? $text : __( 'Goods', 'bg-commerce-suite' );
	}

	/**
	 * Content block: weight, plus parcel dimensions when configured (volumetric).
	 *
	 * @param float $weight Weight (kg).
	 * @return array<string,mixed>
	 */
	private function content_block( $weight, array $package = array() ) {
		$content = array(
			'parcelsCount' => 1,
			'totalWeight'  => $weight,
		);

		$dimensions = Package_Dimensions::resolve_for_package(
			$package,
			array(),
			array(
				'length' => Module_Settings::get( self::ID, 'default_depth' ),
				'width'  => Module_Settings::get( self::ID, 'default_width' ),
				'height' => Module_Settings::get( self::ID, 'default_height' ),
			)
		);
		$w = ! empty( $dimensions ) ? (float) $dimensions['width'] : 0.0;
		$d = ! empty( $dimensions ) ? (float) $dimensions['length'] : 0.0;
		$h = ! empty( $dimensions ) ? (float) $dimensions['height'] : 0.0;

		if ( $w > 0 && $d > 0 && $h > 0 ) {
			$content['parcels'] = array(
				array(
					'seqNo'  => 1,
					'size'   => array(
						'width'  => ceil( $w ),
						'depth'  => ceil( $d ),
						'height' => ceil( $h ),
					),
					'weight' => $weight,
				),
			);
		}

		return $content;
	}

	/**
	 * Build service.additionalServices for a shipment from the configured Speedy
	 * options: COD, declared value, open-before-payment (OBP)
	 * and return voucher.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private function additional_services( \WC_Order $order, $wb = array(), $service_id = 0, $courier_payer = 'SENDER' ) {
		$wb         = is_array( $wb ) ? $wb : array();
		$additional = array();

		// COD amount: tri-state admin override (Overrides::INHERIT/CUSTOM/DISABLED)
		// resolved against the order's canonical COD amount. A blank/unset override
		// NEVER disables COD by itself — only an explicit `cod_mode=disabled` does
		// (Master Instruction Rule 15 — fixes the historical Speedy COD regression).
		$cod_amount = $this->resolved_cod_amount_for_payer( $order, $wb, $courier_payer );

		if ( $cod_amount > 0 ) {
			$processing = $this->wbx( $wb, 'cod_processing' );
			if ( ! in_array( $processing, array( 'CASH', 'POSTAL_MONEY_TRANSFER' ), true ) ) {
				$processing = (string) Module_Settings::get( self::ID, 'cod_processing' );
			}

			$additional['cod'] = array(
				'amount'         => round( $cod_amount, 2 ),
				'processingType' => $processing,
			);

			// COD в валутата на поръчката (ShipmentCODAdditionalService.currencyCode);
			// без него Спиди приема сумата в договорната валута на клиента.
			$currency = (string) $order->get_currency();
			if ( '' !== $currency ) {
				$additional['cod']['currencyCode'] = $currency;
			}

			// BGCS sends the exact COD base explicitly. When the courier service is
			// recipient-paid, its transport component is already excluded from cod.amount
			// and Speedy collects it separately; for sender-paid shipments the full
			// customer-payable amount is already in cod.amount. Do not add shipping twice.
			$additional['cod']['includeShippingPrice'] = false;

			// Fiscal receipt: Safely disabled pending official Speedy fiscal device/VAT configuration specification (§5).
			// Do not send unverified fiscal payloads to Speedy API.
		}

		// Declared value: same tri-state resolution as COD above. INHERIT falls back
		// to the courier setting (order total when „Обявена стойност“ is enabled),
		// not to zero — a blank override never silently drops insurance coverage.
		$declared = $this->declared_value_amount( $order, $wb );

		if ( $declared > 0 ) {
			$additional['declaredValue'] = array(
				'amount'  => round( $declared, 2 ),
				'fragile' => $this->fragile_requested( $wb ),
			);
		}

		// Open-before-payment (преглед/тест преди плащане) — admin override wins.
		$obp = ( ! empty( $wb['obp'] ) ) ? (string) $wb['obp'] : (string) Module_Settings::get( self::ID, 'obp_option' );
		if ( in_array( $obp, array( 'OPEN', 'TEST' ), true ) ) {
			$obpd = array( 'option' => $obp );

			$obp_service = $this->wbx( $wb, 'obp_return_service' );
			if ( '' === $obp_service ) {
				$obp_service = (string) bgcs3_get_option( self::ID, 'obp_return_service_id', '' );
			}
			// Speedy's ShipmentOBPD contract requires a return service id.
			// If the merchant did not choose a special return service, using the
			// current shipment service is the only non-lossy default.
			if ( '' === $obp_service && (int) $service_id > 0 ) {
				$obp_service = (string) (int) $service_id;
			}
			if ( '' !== $obp_service ) {
				$obpd['returnShipmentServiceId'] = (int) $obp_service;
			}

			$obp_payer = $this->wbx( $wb, 'obp_return_payer' );
			if ( ! in_array( $obp_payer, array( 'SENDER', 'RECIPIENT', 'THIRD_PARTY' ), true ) ) {
				$obp_payer = (string) Module_Settings::get( self::ID, 'obp_return_payer' );
			}
			$obpd['returnShipmentPayer'] = $obp_payer;
			$additional['obpd']          = $obpd;
		}

		// Доставка в точен час (fixedTimeDelivery, HHMM: 1130 = 11:30).
		$fixed_time = preg_replace( '/\D/', '', $this->wbx( $wb, 'fixed_time' ) );
		if ( '' !== (string) $fixed_time && (int) $fixed_time > 0 ) {
			$additional['fixedTimeDelivery'] = (int) $fixed_time;
		}

		// Доставка до етаж (deliveryToFloor).
		$floor = $this->wbx( $wb, 'delivery_to_floor' );
		if ( '' !== $floor && is_numeric( $floor ) && (int) $floor > 0 ) {
			$additional['deliveryToFloor'] = (int) $floor;
		}

		// Return voucher (ваучер за връщане). The container key is `returns`
		// (ShipmentReturnAdditionalServices) — a stray singular `return` is not
		// part of ShipmentAdditionalServices at all, so Speedy dropped the whole
		// block and every waybill came back with „Ваучер за връщане: Не“.
		$returns = array();

		if ( $this->wbx_bool( $wb, 'return_voucher', 'return_voucher' ) ) {
			$voucher_payer = $this->wbx( $wb, 'return_voucher_payer' );
			if ( ! in_array( $voucher_payer, array( 'SENDER', 'RECIPIENT', 'THIRD_PARTY' ), true ) ) {
				$voucher_payer = (string) Module_Settings::get( self::ID, 'return_voucher_payer' );
			}
			$voucher = array( 'payer' => $voucher_payer );

			$voucher_service_raw = $this->wbx( $wb, 'return_voucher_service' );
			$voucher_service     = '' !== $voucher_service_raw
				? (int) $voucher_service_raw
				: (int) bgcs3_get_option( self::ID, 'return_voucher_service_id', 0 );
			if ( $voucher_service > 0 ) {
				$voucher['serviceId'] = $voucher_service;
			}

			$validity_raw = $this->wbx( $wb, 'return_voucher_validity' );
			$validity     = '' !== $validity_raw
				? (int) $validity_raw
				: (int) Module_Settings::get( self::ID, 'return_voucher_validity' );
			if ( $validity > 0 ) {
				$voucher['validityPeriod'] = $validity;
			}
			$returns['returnVoucher'] = $voucher;
		}

		// Обратна разписка (ShipmentRODAdditionalService) — подписаният документ
		// се връща на подателя.
		if ( $this->wbx_bool( $wb, 'rod', 'return_of_documents' ) ) {
			$returns['rod'] = array( 'enabled' => true );
		}

		if ( ! empty( $returns ) ) {
			$additional['returns'] = $returns;
		}

		return $additional;
	}

	/**
	 * Resolved declared value (обявена стойност) for an order: the same
	 * tri-state override resolution the shipment builder uses, extracted so
	 * `create_label()` can validate the fragile/declared-value pairing BEFORE
	 * sending anything to Speedy.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return float
	 */
	private function declared_value_amount( \WC_Order $order, array $wb ) {
		$default = ( 'yes' === Module_Settings::get( self::ID, 'declared_value' ) )
			? (float) $order->get_total()
			: 0.0;

		return Overrides::resolve( $wb, 'dv_mode', 'declared_value', $default );
	}

	/**
	 * Whether „Чупливо“ is requested (per-order override wins over the setting).
	 *
	 * @param array<string,mixed> $wb Waybill overrides.
	 * @return bool
	 */
	private function fragile_requested( array $wb ) {
		$value = array_key_exists( 'fragile', $wb ) ? (string) $wb['fragile'] : '';

		if ( in_array( $value, array( 'yes', '1' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( 'no', '0' ), true ) ) {
			return false;
		}

		// Blank is inheritance, not an implicit "No". This keeps the per-order
		// editor from freezing the current global default when another field is saved.
		return 'yes' === Module_Settings::get( self::ID, 'fragile' );
	}

	/**
	 * @param array<string,mixed> $package Package.
	 * @return float
	 */
	private function package_weight( array $package ) {
		return Weight::for_package( self::ID, $package );
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return float
	 */
	private function order_weight( \WC_Order $order ) {
		return Weight::for_order( self::ID, $order );
	}
}
