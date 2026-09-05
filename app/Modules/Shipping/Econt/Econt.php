<?php
/**
 * Econt courier module for BG Commerce Suite.
 *
 * Phase 0: identity, enable flag, settings-tab declaration, client + locations
 * wiring. Phase 1 fills checkout schema, pricing, labels and tracking.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\Econt;

use BgCommerce3\Admin\Icons;
use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Abstract_Courier;
use BgCommerce3\Shipping\Cod;
use BgCommerce3\Shipping\Courier_Error;
use BgCommerce3\Shipping\Delivery_Estimate;
use BgCommerce3\Shipping\Hooks as Shipping_Hooks;
use BgCommerce3\Shipping\Overrides;
use BgCommerce3\Shipping\Office_Store;
use BgCommerce3\Shipping\Package_Dimensions;
use BgCommerce3\Shipping\Pricing;
use BgCommerce3\Shipping\Pickup_Request;
use BgCommerce3\Shipping\Setup_Status;
use BgCommerce3\Shipping\Shipment_Creation;
use BgCommerce3\Shipping\Tracking_State;
use BgCommerce3\Shipping\Tracking_Store;
use BgCommerce3\Shipping\Weight;
use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Price_Result;
use BgCommerce3\Support\Label_Result;
use BgCommerce3\Support\Label_Pdf_Store;
use BgCommerce3\Support\Tracking_Result;
use BgCommerce3\Support\Cache;
use BgCommerce3\Support\Sync_Result;
use BgCommerce3\Support\Module_Settings;
use BgCommerce3\Support\Options;

defined( 'ABSPATH' ) || exit;

class Econt extends Abstract_Courier {

	const ID = 'econt';

	/** @var Client|null */
	private $client;

	/** @var Locations|null */
	private $locations;

	/** @var Label_Builder|null */
	private $label_builder;

	/**
	 * @return string
	 */
	public function id() {
		return self::ID;
	}

	/**
	 * @return string
	 */
	public function name() {
		return __( 'Econt', 'bg-commerce-suite' );
	}

	/**
	 * Delivery types, filtered by global show_* settings.
	 *
	 * @return string[]
	 */
	public function supported_delivery_types() {
		return array( 'office', 'locker', 'address' );
	}

	public function delivery_types() {
		$all = array(
			'office'  => 'show_office',
			'locker'  => 'show_locker',
			'address' => 'show_address',
		);

		$types = array();
		foreach ( $all as $type => $option_key ) {
			if ( 'no' !== bgcs3_get_option( self::ID, $option_key, 'yes' ) ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * @return Client
	 */
	/**
	 * Core waybill fields Econt's API has no counterpart for.
	 *
	 * Econt carries no „чупливо“ flag and no second free reference, so rendering
	 * them would invite the merchant to fill in a value that is then discarded —
	 * the same silent loss this module just stopped doing with the rest of the
	 * panel (BUG-035).
	 *
	 * @return string[]
	 */
	public function hidden_waybill_fields() {
		return array( 'fragile', 'ref2', 'payer' );
	}

	/**
	 * BGCS-AUDIT-004/-006 — Econt payment semantics for the order snapshot.
	 *
	 * **The sender always pays the courier service.** That is not a default that
	 * can be configured away: BGCS adds the customer's delivery price to the
	 * WooCommerce order, so billing Econt's services to the receiver would
	 * collect the delivery a second time outside WooCommerce. `Label_Builder`
	 * sends exactly that — `paymentReceiverMethod` empty, `paymentSenderMethod`
	 * set — and `payer` is in {@see hidden_waybill_fields()} because there is
	 * nothing for the merchant to choose. The `payment_type` setting
	 * (CASH/CREDIT/VOUCHER) is *how* the sender pays, not *who* pays.
	 *
	 * Core previously derived this from `payment_side`, an option that has never
	 * existed in this module, and so recorded RECIPIENT on every Econt shipment.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return array<string,mixed>
	 */
	public function label_snapshot_financials( \WC_Order $order, array $wb ) {
		return array(
			'payer'        => 'SENDER',
			'cod_amount'   => $this->snapshot_cod_amount( $order, $wb ),
			'cod_currency' => strtoupper( (string) $order->get_currency() ),
		);
	}

	/**
	 * The COD amount Econt is actually told to collect.
	 *
	 * Mirrors the gate in `Label_Builder::services()`: with `cd_enabled = no`
	 * the shipment carries no `cdAmount` at all, so recording the WooCommerce
	 * total as collectable would misstate a payout that will never arrive. The
	 * per-order tri-state override wins over the module setting, exactly as it
	 * does when the payload is built.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return float
	 */
	private function snapshot_cod_amount( \WC_Order $order, array $wb ) {
		$mode = Overrides::mode( $wb, 'cod_mode' );

		if ( Overrides::DISABLED === $mode ) {
			return 0.0;
		}
		if ( Overrides::CUSTOM !== $mode && 'yes' !== (string) Module_Settings::get( self::ID, 'cd_enabled' ) ) {
			return 0.0;
		}

		return Cod::resolve_amount( $order, $wb );
	}

	/**
	 * Shipment-level defaults that Econt accepts in ShippingLabel / UpdateLabel.
	 * Empty means inherit the module setting; explicit "no"/"0" values let one
	 * order disable a shop-wide default without changing that default.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function waybill_fields() {
		$inherit = array(
			''    => __( 'Use settings', 'bg-commerce-suite' ),
			'yes' => __( 'Yes', 'bg-commerce-suite' ),
			'no'  => __( 'No', 'bg-commerce-suite' ),
		);

		return array(
			'sender_office_code' => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender office code', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings', 'bg-commerce-suite' ),
				'description' => __( 'Overrides the Econt sender office for this order only.', 'bg-commerce-suite' ),
			),
			'sender_company' => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender company', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings', 'bg-commerce-suite' ),
			),
			'sender_contact_name' => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender contact person', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings', 'bg-commerce-suite' ),
			),
			'sender_phone' => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender phone', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings', 'bg-commerce-suite' ),
			),
			'sender_email' => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender email', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings', 'bg-commerce-suite' ),
			),
			'shipment_type' => array(
				'group'   => 'packages',
				'type'    => 'select',
				'label'   => __( 'Econt shipment type', 'bg-commerce-suite' ),
				'options' => array(
					''               => __( 'Use settings', 'bg-commerce-suite' ),
					'document'       => __( 'Document', 'bg-commerce-suite' ),
					'pack'           => __( 'Parcel / pack', 'bg-commerce-suite' ),
					'pallet'         => __( 'Pallet', 'bg-commerce-suite' ),
					'cargo'          => __( 'Cargo', 'bg-commerce-suite' ),
					'documentpallet' => __( 'Document pallet', 'bg-commerce-suite' ),
					'big_letter'     => __( 'Big letter', 'bg-commerce-suite' ),
					'small_letter'   => __( 'Small letter', 'bg-commerce-suite' ),
				),
			),
			'econt_pack5' => array(
				'group' => 'packages',
				'type'  => 'number',
				'label' => __( 'Econt PACK 5 count', 'bg-commerce-suite' ),
				'min'   => 0,
				'step'  => 1,
			),
			'econt_pack6' => array(
				'group' => 'packages',
				'type'  => 'number',
				'label' => __( 'Econt PACK 6 count', 'bg-commerce-suite' ),
				'min'   => 0,
				'step'  => 1,
			),
			'econt_pack8' => array(
				'group' => 'packages',
				'type'  => 'number',
				'label' => __( 'Econt PACK 8 count', 'bg-commerce-suite' ),
				'min'   => 0,
				'step'  => 1,
			),
			'econt_pack9' => array(
				'group' => 'packages',
				'type'  => 'number',
				'label' => __( 'Econt PACK 9 count', 'bg-commerce-suite' ),
				'min'   => 0,
				'step'  => 1,
			),
			'econt_pack10' => array(
				'group' => 'packages',
				'type'  => 'number',
				'label' => __( 'Econt PACK 10 count', 'bg-commerce-suite' ),
				'min'   => 0,
				'step'  => 1,
			),
			'econt_pack12' => array(
				'group' => 'packages',
				'type'  => 'number',
				'label' => __( 'Econt PACK 12 count', 'bg-commerce-suite' ),
				'min'   => 0,
				'step'  => 1,
			),
			'econt_refrigerated_pack' => array(
				'group' => 'packages',
				'type'  => 'number',
				'label' => __( 'Econt refrigerated pack (REF) count', 'bg-commerce-suite' ),
				'min'   => 0,
				'step'  => 1,
			),
			'payment_type' => array(
				'group'   => 'payment',
				'type'    => 'select',
				'label'   => __( 'Courier payment method', 'bg-commerce-suite' ),
				'options' => array(
					''        => __( 'Use settings', 'bg-commerce-suite' ),
					'CASH'    => __( 'Cash', 'bg-commerce-suite' ),
					'CREDIT'  => __( 'Credit', 'bg-commerce-suite' ),
					'VOUCHER' => __( 'Voucher (sender only)', 'bg-commerce-suite' ),
				),
				'description' => __( 'Econt allows cash/credit for the recipient and cash/credit/voucher for the sender.', 'bg-commerce-suite' ),
			),
			'cd_pay_options' => array(
				'group'       => 'payment',
				'type'        => 'text',
				'label'       => __( 'COD / PPP payout agreement', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings; 0 = no template', 'bg-commerce-suite' ),
			),
			'invoice_before_payment' => array(
				'group'   => 'payment',
				'type'    => 'select',
				'label'   => __( 'Invoice before COD payment', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'sms_notification' => array(
				'group'   => 'services',
				'type'    => 'select',
				'label'   => __( 'SMS notification', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'email_on_delivery' => array(
				'group'   => 'services',
				'type'    => 'select',
				'label'   => __( 'Email on delivery', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'delivery_receipt' => array(
				'group'   => 'services',
				'type'    => 'select',
				'label'   => __( 'Delivery receipt (DC)', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'digital_receipt' => array(
				'group'   => 'services',
				'type'    => 'select',
				'label'   => __( 'Digital delivery receipt (EDC)', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'goods_receipt' => array(
				'group'   => 'services',
				'type'    => 'select',
				'label'   => __( 'Goods receipt (DC-CP)', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'two_way_shipment' => array(
				'group'   => 'services',
				'type'    => 'select',
				'label'   => __( 'Two-way shipment (DP)', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'delivery_to_floor' => array(
				'group'   => 'services',
				'type'    => 'select',
				'label'   => __( 'Delivery to floor', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'keep_upright' => array(
				'group'   => 'services',
				'type'    => 'select',
				'label'   => __( 'Keep upright', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'partial_delivery' => array(
				'group'   => 'services',
				'type'    => 'select',
				'label'   => __( 'Partial delivery', 'bg-commerce-suite' ),
				'options' => $inherit,
			),
			'priority_time_from' => array(
				'group'       => 'services',
				'type'        => 'text',
				'label'       => __( 'Priority time — from', 'bg-commerce-suite' ),
				'placeholder' => __( 'HH:MM; 0 = disable', 'bg-commerce-suite' ),
			),
			'priority_time_to' => array(
				'group'       => 'services',
				'type'        => 'text',
				'label'       => __( 'Priority time — to', 'bg-commerce-suite' ),
				'placeholder' => __( 'HH:MM; 0 = disable', 'bg-commerce-suite' ),
			),
			'instructions_take' => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Pickup instruction template ID', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings; 0 = none', 'bg-commerce-suite' ),
			),
			'instructions_give' => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Handover instruction template ID', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings; 0 = none', 'bg-commerce-suite' ),
			),
			'instructions_return' => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Return instruction template ID', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings; 0 = none', 'bg-commerce-suite' ),
			),
			'request_courier' => array(
				'group'       => 'extra',
				'type'        => 'select',
				'label'       => __( 'Request courier pickup', 'bg-commerce-suite' ),
				'options'     => $inherit,
				'description' => __( 'Applies to this order only.', 'bg-commerce-suite' ),
			),
			'request_courier_from' => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Courier pickup from', 'bg-commerce-suite' ),
				'placeholder' => __( 'HH:MM; blank = settings; 0 = no explicit time', 'bg-commerce-suite' ),
			),
			'request_courier_to' => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Courier pickup until', 'bg-commerce-suite' ),
				'placeholder' => __( 'HH:MM; blank = settings; 0 = no explicit time', 'bg-commerce-suite' ),
			),
		);
	}

	public function client() {
		if ( null === $this->client ) {
			$this->client = new Client();
		}
		return $this->client;
	}

	/**
	 * @return Locations
	 */
	public function locations() {
		if ( null === $this->locations ) {
			$this->locations = new Locations( $this->client() );
		}
		return $this->locations;
	}

	/**
	 * Whether the Econt account credentials are configured.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return $this->client()->has_credentials();
	}

	/**
	 * Explicit account check used by the shared courier account workspace.
	 * Passive page rendering never performs this request.
	 *
	 * @return Sync_Result
	 */
	public function check_connection() {
		if ( ! $this->has_credentials() ) {
			Options::set( self::ID, '_api_health', array( 'ok' => false, 'at' => time() ) );
			return Sync_Result::error( __( 'Econt username and/or password is missing.', 'bg-commerce-suite' ) );
		}

		$profiles = $this->locations()->profiles_result();
		if ( is_wp_error( $profiles ) ) {
			Options::set( self::ID, '_api_health', array( 'ok' => false, 'at' => time() ) );
			return Sync_Result::error( __( 'Econt did not confirm the account.', 'bg-commerce-suite' ), array( $profiles->get_error_message() ) );
		}

		Options::set( self::ID, '_api_health', array( 'ok' => true, 'at' => time(), 'profiles' => count( $profiles ) ) );
		return Sync_Result::success(
			sprintf(
				/* translators: %d number of Econt client profiles. */
				_n( 'The Econt connection is successful. %d customer profile was found.', 'The Econt connection is successful. %d customer profiles were found.', count( $profiles ), 'bg-commerce-suite' ),
				count( $profiles )
			)
		);
	}


	/**
	 * Real readiness checks for the shared courier setup assistant.
	 *
	 * @return array<int,array{id:string,label:string,state:string,hint:string}>
	 */
	public function setup_status() {
		$rows = array();

		if ( $this->has_credentials() ) {
			$health = (array) bgcs3_get_option( self::ID, '_api_health', array() );
			if ( array_key_exists( 'ok', $health ) && true === $health['ok'] ) {
				$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_OK );
			} elseif ( array_key_exists( 'ok', $health ) && false === $health['ok'] ) {
				$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_FAIL, __( 'The last Econt connection check failed.', 'bg-commerce-suite' ) );
			} else {
				$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_WARN, __( 'The data is saved, but the connection has not been checked yet.', 'bg-commerce-suite' ) );
			}
		} else {
			$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_FAIL, __( 'Enter the Econt username and password.', 'bg-commerce-suite' ) );
		}

		$profile_id = trim( (string) bgcs3_get_option( self::ID, 'econt_profile_id', '' ) );
		/* translators: %s: comma-separated list of missing Econt location datasets. */
		$rows[] = Setup_Status::row(
			'profile',
			__( 'Sender profile', 'bg-commerce-suite' ),
			'' !== $profile_id ? Setup_Status::STATE_OK : Setup_Status::STATE_WARN,
			'' !== $profile_id ? '' : __( 'Select and synchronize an Econt sender profile to enable account-specific COD/PPP agreements, instruction templates and standalone courier pickup.', 'bg-commerce-suite' )
		);

		$sender_handover = (string) Module_Settings::get( self::ID, 'sender_handover' );
		$sender_office   = trim( (string) bgcs3_get_option( self::ID, 'sender_office_code', '' ) );
		$sender_address  = trim( (string) Module_Settings::get( self::ID, 'sender_address_key' ) );
		$sender_ok       = 'address' === $sender_handover ? '' !== $sender_address : '' !== $sender_office;
		$rows[] = Setup_Status::row(
			'sender',
			__( 'Sender', 'bg-commerce-suite' ),
			$sender_ok ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL,
			$sender_ok ? '' : ( 'address' === $sender_handover ? __( 'Select an address for courier pickup.', 'bg-commerce-suite' ) : __( 'Select an office for sending shipments.', 'bg-commerce-suite' ) )
		);

		$active_types = $this->delivery_types();
		$missing_locations = array();
		if ( in_array( 'office', $active_types, true ) && ! Office_Store::has( self::ID, 'office' ) ) {
			$missing_locations[] = __( 'offices', 'bg-commerce-suite' );
		}
		if ( in_array( 'locker', $active_types, true ) && ! Office_Store::has( self::ID, 'locker' ) ) {
			$missing_locations[] = __( 'lockers', 'bg-commerce-suite' );
		}
		$locations_message = '';
		if ( ! empty( $missing_locations ) ) {
			/* translators: %s: comma-separated list of missing courier location types. */
			$locations_message = sprintf( __( 'Synchronize the missing locations: %s.', 'bg-commerce-suite' ), implode( ', ', $missing_locations ) );
		}
		$rows[] = Setup_Status::row(
			'locations',
			__( 'Offices and lockers', 'bg-commerce-suite' ),
			empty( $missing_locations ) ? Setup_Status::STATE_OK : Setup_Status::STATE_WARN,
			$locations_message
		);

		$pricing_ok = Pricing::MODE_OWN !== Pricing::mode( self::ID ) || Pricing::has_active_rules( self::ID );
		$rows[] = Setup_Status::row(
			'pricing',
			__( 'Pricing', 'bg-commerce-suite' ),
			$pricing_ok ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL,
			$pricing_ok ? '' : __( 'Add at least one active rule under “Custom prices”.', 'bg-commerce-suite' )
		);

		$delivery_ok = $this->is_enabled() && ! empty( $active_types );
		$rows[] = Setup_Status::row(
			'method',
			__( 'Delivery types', 'bg-commerce-suite' ),
			$delivery_ok ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL,
			$delivery_ok ? '' : __( 'Enable the module and at least one delivery type for checkout.', 'bg-commerce-suite' )
		);

		return $rows;
	}

	/**
	 * Econt label payload builder (споделен от quote() и create_label()).
	 *
	 * @return Label_Builder
	 */
	private function label_builder() {
		if ( null === $this->label_builder ) {
			$this->label_builder = new Label_Builder( self::ID );
		}
		return $this->label_builder;
	}

	/**
	 * Declare the admin settings tab for this courier.
	 *
	 * @return array<string,mixed>
	 */
	public function settings_tab() {
		return array(
			'id'    => self::ID,
			'title' => $this->name(),
			'group' => self::ID,
		);
	}

	/**
	 * Credentials / environment for the Econt API.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function settings_fields() {
		$profiles = $this->client()->has_credentials() ? $this->locations()->profile_options() : array();
		$selected_profile = (string) bgcs3_get_option( self::ID, 'econt_profile_id', '' );
		$sender_addresses = $this->client()->has_credentials() ? $this->locations()->sender_address_options( $selected_profile ) : array();
		$profile_field = array(
			'type'        => 'select',
			'label'       => __( 'Sender profile', 'bg-commerce-suite' ),
			'default'     => '',
			'options'     => array( '' => __( '— Select a profile —', 'bg-commerce-suite' ) ) + $profiles,
			'searchable'  => true,
			'label_key'   => 'econt_profile_label',
			'description' => __( 'The customer profile determines which data can be updated from the API.', 'bg-commerce-suite' ),
		);
		// API-fed dropdowns (fall back to text until credentials are synced).
		$offices = $this->client()->has_credentials() ? $this->locations()->all_offices_options() : array();
		if ( ! empty( $offices ) ) {
			$sender_office_field = array(
				'type'        => 'select',
				'label'       => __( 'Sender — office', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => array( '' => __( '— Select an office —', 'bg-commerce-suite' ) ) + $offices,
				'description' => __( 'Loaded from the Econt nomenclature. If the list is empty, click “Sync data”.', 'bg-commerce-suite' ),
				'searchable'  => true,
				'label_key'   => 'sender_office_label',
				'show_if'     => array( 'sender_handover' => 'office' ),
			);
		} else {
			$sender_office_field = array(
				'type'        => 'text',
				'label'       => __( 'Sender — office code', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Enter the username/password and save — an office list will then appear.', 'bg-commerce-suite' ),
				'show_if'     => array( 'sender_handover' => 'office' ),
			);
		}

		$pay_options = $this->client()->has_credentials() ? $this->locations()->cd_pay_options( $selected_profile ) : array();
		$instruction_take   = $this->client()->has_credentials() ? $this->locations()->instruction_options( 'take', $selected_profile ) : array();
		$instruction_give   = $this->client()->has_credentials() ? $this->locations()->instruction_options( 'give', $selected_profile ) : array();
		$instruction_return = $this->client()->has_credentials() ? $this->locations()->instruction_options( 'return', $selected_profile ) : array();
		if ( ! empty( $pay_options ) ) {
			$cd_template_field = array(
				'type'        => 'select',
				'label'       => __( 'COD / PPP payout agreement', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => array( '' => __( '— No template —', 'bg-commerce-suite' ) ) + $pay_options,
				'description' => __( 'Account agreement returned by Econt. Agreements marked “Postal money transfer (PPP)” pay the COD amount as a postal money transfer.', 'bg-commerce-suite' ),
			);
		} else {
			$cd_template_field = array(
				'type'        => 'text',
				'label'       => __( 'COD / PPP payout agreement', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Number/ID of the COD / PPP payout agreement. Sync Econt data to select an account agreement from a list.', 'bg-commerce-suite' ),
			);
		}

		return array(
			// -- Среда & автентикация -----------------------------------------
			'env'      => array(
				'type'    => 'select',
				'label'   => __( 'Environment', 'bg-commerce-suite' ),
				'default' => 'demo',
				'options' => array(
					'demo' => __( 'Demo (test)', 'bg-commerce-suite' ),
					'live' => __( 'Production', 'bg-commerce-suite' ),
				),
			),
			'user'     => array(
				'type'        => 'text',
				'label'       => __( 'Username (Econt)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Username from the Econt profile (demo.econt.com for testing).', 'bg-commerce-suite' ),
			),
			'password'           => array(
				'type'    => 'password',
				'label'   => __( 'Password (Econt)', 'bg-commerce-suite' ),
				'default' => '',
			),

			// -- Данни за подателя --------------------------------------------
			'econt_profile_id'   => $profile_field,
			'sender_company'     => array(
				'type'        => 'text',
				'label'       => __( 'Sender — company/person', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Name of the company or individual sender.', 'bg-commerce-suite' ),
			),
			'sender_name'        => array(
				'type'    => 'text',
				'label'   => __( 'Sender — contact person', 'bg-commerce-suite' ),
				'default' => '',
			),
			'sender_phone'       => array(
				'type'    => 'text',
				'label'   => __( 'Sender — phone', 'bg-commerce-suite' ),
				'default' => '',
			),
			'sender_email'       => array(
				'type'    => 'text',
				'label'   => __( 'Sender — email', 'bg-commerce-suite' ),
				'default' => '',
			),
			'sender_handover'    => array(
				'type'        => 'select',
				'label'       => __( 'How shipments are handed to Econt', 'bg-commerce-suite' ),
				'default'     => 'office',
				'options'     => array(
					'office'  => __( 'Drop off at Econt office', 'bg-commerce-suite' ),
					'address' => __( 'Courier pickup from sender address', 'bg-commerce-suite' ),
				),
				'description' => __( 'Choose whether the merchant hands the shipment over at an Econt office or a courier collects it from an address in the selected Econt profile.', 'bg-commerce-suite' ),
			),
			'sender_address_key' => array(
				'type'        => ! empty( $sender_addresses ) ? 'select' : 'text',
				'label'       => __( 'Sender — pickup address', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => ! empty( $sender_addresses ) ? array( '' => __( '— Select an address —', 'bg-commerce-suite' ) ) + $sender_addresses : array(),
				'description' => __( 'Address returned by the selected Econt client profile. Synchronize data after changing the profile.', 'bg-commerce-suite' ),
				'show_if'     => array( 'sender_handover' => 'address' ),
			),
			'sender_office_code' => $sender_office_field,

			// -- Видимост на типовете доставка --------------------------------
			'show_office'        => array(
				'type'           => 'checkbox',
				'label'          => __( 'Office delivery', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Show the “To office” option at checkout', 'bg-commerce-suite' ),
				'default'        => 'yes',
			),
			'show_locker'        => array(
				'type'           => 'checkbox',
				'label'          => __( 'Delivery to locker (APS)', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Show the “To locker” option at checkout', 'bg-commerce-suite' ),
				'default'        => 'yes',
			),
			'show_address'       => array(
				'type'           => 'checkbox',
				'label'          => __( 'Address delivery', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Show the “To address” option at checkout', 'bg-commerce-suite' ),
				'default'        => 'yes',
			),

			// -- Наложен платеж -----------------------------------------------
			'cd_enabled'         => array(
				'type'        => 'select',
				'label'       => __( 'Cash on delivery', 'bg-commerce-suite' ),
				'default'     => 'yes',
				'options'     => array(
					'yes' => __( 'Yes', 'bg-commerce-suite' ),
					'no'  => __( 'No', 'bg-commerce-suite' ),
				),
				'description' => __( 'Allow cash on delivery for the shipment.', 'bg-commerce-suite' ),
			),
			'cd_pay_options'     => array_merge( $cd_template_field, array( 'show_if' => array( 'cd_enabled' => 'yes' ) ) ),

			// -- Плащане на доставката ----------------------------------------
			// Courier-service payer is intentionally not configurable here. BGCS
			// adds the customer delivery price to the WooCommerce order, therefore
			// Econt must bill the courier services to the sender. Receiver payment
			// would collect the delivery a second time outside WooCommerce.
			'payment_type'       => array(
				'type'        => 'select',
				'label'       => __( 'Payment method', 'bg-commerce-suite' ),
				'default'     => 'CASH',
				'options'     => array(
					'CASH'    => __( 'Cash', 'bg-commerce-suite' ),
					'CREDIT'  => __( 'Credit', 'bg-commerce-suite' ),
					'VOUCHER' => __( 'Voucher (sender only)', 'bg-commerce-suite' ),
				),
			),

			// Безплатната доставка се управлява централно от Core (секция „Цени и
			// безплатна доставка“ — праг по тип До офис/АПС/адрес).

			'shipment_type' => array(
				'type'        => 'select',
				'label'       => __( 'Default Econt shipment type', 'bg-commerce-suite' ),
				'default'     => 'pack',
				'options'     => array(
					'document'       => __( 'Document', 'bg-commerce-suite' ),
					'pack'           => __( 'Parcel / pack', 'bg-commerce-suite' ),
					'pallet'         => __( 'Pallet', 'bg-commerce-suite' ),
					'cargo'          => __( 'Cargo', 'bg-commerce-suite' ),
					'documentpallet' => __( 'Document pallet', 'bg-commerce-suite' ),
					'big_letter'     => __( 'Big letter', 'bg-commerce-suite' ),
					'small_letter'   => __( 'Small letter', 'bg-commerce-suite' ),
				),
				'description' => __( 'Ordinary WooCommerce goods use “Parcel / pack” by default. Financial shipment types are intentionally not exposed here.', 'bg-commerce-suite' ),
			),

			'econt_pack5' => array(
				'type'        => 'number',
				'label'       => __( 'Econt PACK 5', 'bg-commerce-suite' ),
				'default'     => '0',
				'min'         => 0,
				'step'        => 1,
				'description' => __( 'Number of PACK 5 services requested for the shipment.', 'bg-commerce-suite' ),
			),
			'econt_pack6' => array(
				'type'        => 'number',
				'label'       => __( 'Econt PACK 6', 'bg-commerce-suite' ),
				'default'     => '0',
				'min'         => 0,
				'step'        => 1,
				'description' => __( 'Number of PACK 6 services requested for the shipment.', 'bg-commerce-suite' ),
			),
			'econt_pack8' => array(
				'type'        => 'number',
				'label'       => __( 'Econt PACK 8', 'bg-commerce-suite' ),
				'default'     => '0',
				'min'         => 0,
				'step'        => 1,
				'description' => __( 'Number of PACK 8 services requested for the shipment.', 'bg-commerce-suite' ),
			),
			'econt_pack9' => array(
				'type'        => 'number',
				'label'       => __( 'Econt PACK 9', 'bg-commerce-suite' ),
				'default'     => '0',
				'min'         => 0,
				'step'        => 1,
				'description' => __( 'Number of PACK 9 services requested for the shipment.', 'bg-commerce-suite' ),
			),
			'econt_pack10' => array(
				'type'        => 'number',
				'label'       => __( 'Econt PACK 10', 'bg-commerce-suite' ),
				'default'     => '0',
				'min'         => 0,
				'step'        => 1,
				'description' => __( 'Number of PACK 10 services requested for the shipment.', 'bg-commerce-suite' ),
			),
			'econt_pack12' => array(
				'type'        => 'number',
				'label'       => __( 'Econt PACK 12', 'bg-commerce-suite' ),
				'default'     => '0',
				'min'         => 0,
				'step'        => 1,
				'description' => __( 'Number of PACK 12 services requested for the shipment.', 'bg-commerce-suite' ),
			),
			'econt_refrigerated_pack' => array(
				'type'        => 'number',
				'label'       => __( 'Refrigerated pack (REF)', 'bg-commerce-suite' ),
				'default'     => '0',
				'min'         => 0,
				'step'        => 1,
				'description' => __( 'Number of refrigerated/cooler packs requested for the shipment.', 'bg-commerce-suite' ),
			),

			// -- Допълнителни услуги ------------------------------------------
			'sms_notification'   => array(
				'type'        => 'select',
				'label'       => __( 'SMS notification', 'bg-commerce-suite' ),
				'default'     => 'no',
				'options'     => array(
					'no'  => __( 'No', 'bg-commerce-suite' ),
					'yes' => __( 'Yes', 'bg-commerce-suite' ),
				),
				'description' => __( 'Send an SMS to the recipient when the shipment arrives.', 'bg-commerce-suite' ),
			),
			'email_on_delivery' => array(
				'type'        => 'select',
				'label'       => __( 'Email on delivery', 'bg-commerce-suite' ),
				'default'     => 'no',
				'options'     => array( 'no' => __( 'No', 'bg-commerce-suite' ), 'yes' => __( 'Yes', 'bg-commerce-suite' ) ),
				'description' => __( 'Use Econt email-on-delivery notification with the recipient email from the order.', 'bg-commerce-suite' ),
			),
			'delivery_receipt' => array(
				'type'        => 'select',
				'label'       => __( 'Delivery receipt (DC)', 'bg-commerce-suite' ),
				'default'     => 'no',
				'options'     => array( 'no' => __( 'No', 'bg-commerce-suite' ), 'yes' => __( 'Yes', 'bg-commerce-suite' ) ),
				'description' => __( 'Request Econt delivery receipt service.', 'bg-commerce-suite' ),
			),
			'digital_receipt' => array(
				'type'        => 'select',
				'label'       => __( 'Digital delivery receipt (EDC)', 'bg-commerce-suite' ),
				'default'     => 'no',
				'options'     => array( 'no' => __( 'No', 'bg-commerce-suite' ), 'yes' => __( 'Yes', 'bg-commerce-suite' ) ),
				'description' => __( 'Request Econt digital delivery receipt service.', 'bg-commerce-suite' ),
			),
			'goods_receipt' => array(
				'type'        => 'select',
				'label'       => __( 'Goods receipt (DC-CP)', 'bg-commerce-suite' ),
				'default'     => 'no',
				'options'     => array( 'no' => __( 'No', 'bg-commerce-suite' ), 'yes' => __( 'Yes', 'bg-commerce-suite' ) ),
				'description' => __( 'Request confirmation for receiving goods.', 'bg-commerce-suite' ),
			),
			'two_way_shipment' => array(
				'type'        => 'select',
				'label'       => __( 'Two-way shipment (DP)', 'bg-commerce-suite' ),
				'default'     => 'no',
				'options'     => array( 'no' => __( 'No', 'bg-commerce-suite' ), 'yes' => __( 'Yes', 'bg-commerce-suite' ) ),
				'description' => __( 'Request Econt two-way shipment service. Availability is validated by Econt before creation.', 'bg-commerce-suite' ),
			),
			'delivery_to_floor' => array(
				'type'        => 'select',
				'label'       => __( 'Delivery to floor', 'bg-commerce-suite' ),
				'default'     => 'no',
				'options'     => array( 'no' => __( 'No', 'bg-commerce-suite' ), 'yes' => __( 'Yes', 'bg-commerce-suite' ) ),
				'description' => __( 'Available only for address delivery. BGCS blocks this option for office/Econtomat destinations.', 'bg-commerce-suite' ),
			),
			'declared_value'     => array(
				'type'        => 'text',
				'label'       => __( 'Declared value', 'bg-commerce-suite' ),
				'default'     => '0',
				'description' => __( '0 = no declared value, 1 = always, N = above amount N.', 'bg-commerce-suite' ),
			),
			'invoice_before_payment' => array(
				'type'        => 'select',
				'label'       => __( 'Invoice before COD payment', 'bg-commerce-suite' ),
				'default'     => 'no',
				'options'     => array(
					'no'  => __( 'No', 'bg-commerce-suite' ),
					'yes' => __( 'Yes', 'bg-commerce-suite' ),
				),
				'description' => __( 'Provide an invoice to the recipient before payment of cash on delivery.', 'bg-commerce-suite' ),
				'show_if'     => array( 'cd_enabled' => 'yes' ),
			),
			'pay_after'          => array(
				'type'        => 'select',
				'label'       => __( 'Payment after review', 'bg-commerce-suite' ),
				'default'     => 'none',
				'options'     => array(
					'none'   => __( 'No', 'bg-commerce-suite' ),
					'accept' => __( 'After acceptance', 'bg-commerce-suite' ),
					'test'   => __( 'After test', 'bg-commerce-suite' ),
				),
				'description' => __( 'The customer can pay after accepting or testing the goods.', 'bg-commerce-suite' ),
				'show_if'     => array( 'cd_enabled' => 'yes' ),
			),
			'only_courier_request' => array(
				'type'        => 'select',
				'label'       => __( 'Request courier with shipment', 'bg-commerce-suite' ),
				'default'     => 'no',
				'options'     => array(
					'no'  => __( 'No', 'bg-commerce-suite' ),
					'yes' => __( 'Yes', 'bg-commerce-suite' ),
				),
				'description' => __( 'When the shipment label is created, also request a courier pickup in the configured time window.', 'bg-commerce-suite' ),
			),
			'courier_request_time_from' => array(
				'type'        => 'text',
				'label'       => __( 'Courier pickup request from (time)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'For example: 10:00', 'bg-commerce-suite' ),
				'show_if'     => array( 'only_courier_request' => 'yes' ),
			),
			'courier_request_time_to' => array(
				'type'        => 'text',
				'label'       => __( 'Courier pickup request until (time)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'For example: 17:00', 'bg-commerce-suite' ),
				'show_if'     => array( 'only_courier_request' => 'yes' ),
			),
			'instructions_take'   => ! empty( $instruction_take ) ? array(
				'type'        => 'select',
				'label'       => __( 'Pickup instruction', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => array( '' => __( '— None —', 'bg-commerce-suite' ) ) + $instruction_take,
				'description' => __( 'Econt take instruction template returned by the selected account.', 'bg-commerce-suite' ),
			) : array( 'type' => 'text', 'label' => __( 'Pickup instruction', 'bg-commerce-suite' ), 'default' => '', 'description' => __( 'Template ID. Sync Econt data to load account templates.', 'bg-commerce-suite' ) ),
			'instructions_give'   => ! empty( $instruction_give ) ? array(
				'type'        => 'select',
				'label'       => __( 'Handover instruction', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => array( '' => __( '— None —', 'bg-commerce-suite' ) ) + $instruction_give,
				'description' => __( 'Econt give instruction template returned by the selected account.', 'bg-commerce-suite' ),
			) : array( 'type' => 'text', 'label' => __( 'Handover instruction', 'bg-commerce-suite' ), 'default' => '', 'description' => __( 'Template ID. Sync Econt data to load account templates.', 'bg-commerce-suite' ) ),
			'instructions_return' => ! empty( $instruction_return ) ? array(
				'type'        => 'select',
				'label'       => __( 'Return instruction', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => array( '' => __( '— None —', 'bg-commerce-suite' ) ) + $instruction_return,
				'description' => __( 'Econt return instruction template returned by the selected account.', 'bg-commerce-suite' ),
			) : array( 'type' => 'text', 'label' => __( 'Return instruction', 'bg-commerce-suite' ), 'default' => '', 'description' => __( 'Template ID. Sync Econt data to load account templates.', 'bg-commerce-suite' ) ),
			'keep_upright'        => array(
				'type'           => 'checkbox',
				'label'          => __( 'Keep upright', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Mark the shipment as “Keep upright”', 'bg-commerce-suite' ),
				'default'        => 'no',
			),
			'partial_delivery'    => array(
				'type'           => 'checkbox',
				'label'          => __( 'Partial delivery', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Allow partial delivery (partialDelivery)', 'bg-commerce-suite' ),
				'default'        => 'no',
			),
			'priority_time_from'  => array(
				'type'        => 'text',
				'label'       => __( 'Priority time — from (HH:MM)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Optional — start of the delivery time window (priorityTimeFrom).', 'bg-commerce-suite' ),
			),
			'priority_time_to'    => array(
				'type'        => 'text',
				'label'       => __( 'Priority time — to (HH:MM)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Optional — end of the delivery time window (priorityTimeTo).', 'bg-commerce-suite' ),
			),

			// -- Физически параметри ------------------------------------------
			'default_length'     => array(
				'type'        => 'text',
				'label'       => __( 'Default length (cm)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Leave empty if you do not use volumetric weight.', 'bg-commerce-suite' ),
			),
			'default_width'      => array(
				'type'        => 'text',
				'label'       => __( 'Default width (cm)', 'bg-commerce-suite' ),
				'default'     => '',
			),
			'default_height'     => array(
				'type'        => 'text',
				'label'       => __( 'Default height (cm)', 'bg-commerce-suite' ),
				'default'     => '',
			),

			// BGCS-AUDIT-002 / TASK-F1 — three fields lived here that changed
			// nothing. `shipping_to_style` and `hide_quarter_fields` promised
			// checkout behaviour that was never implemented and have been removed.
			// `local_storage` is now Core's `checkout.remember_selection`: browser
			// persistence of the customer's selection is not Econt-specific, and
			// implementing it per courier is the Core/module duplication the
			// architecture forbids. Stored `bgcs3_econt` values are left untouched
			// for rollback; the Core key is seeded from them once on upgrade.
		);
	}

	/**
	 * Wire into Core services. Called only when enabled.
	 *
	 * Phase 1: register the WC shipping method, REST location endpoints,
	 * checkout hooks and admin order actions.
	 *
	 * @param Container $container Core DI container.
	 */
	/**
	 * Sync: drop Econt caches, then pre-warm the settings dropdown sources
	 * (all offices + the client profile with its COD pay options).
	 *
	 * @return array{success:bool,message:string}
	 */
	public function sync_data() {
		$count = Cache::flush_courier( $this->id() );

		$offices     = $this->locations()->all_offices_options();
		$pay_options = $this->locations()->cd_pay_options();
		$pools       = $this->locations()->replace_if_valid();
		$errors      = array();
		$counts      = array( 'offices' => count( $offices ), 'cod_options' => count( $pay_options ), 'cache' => $count );

		$profiles = $this->locations()->profile_options();
		$selected = (string) bgcs3_get_option( self::ID, 'econt_profile_id', '' );
		if ( '' === $selected && 1 === count( $profiles ) ) {
			$selected = (string) key( $profiles );
			Options::set( self::ID, 'econt_profile_id', $selected );
			Options::set( self::ID, 'econt_profile_label', (string) reset( $profiles ) );
		}
		$updated = '' !== $selected ? $this->merge_sender_profile( $selected, false ) : array();
		if ( '' !== $selected && '' === (string) Module_Settings::get( self::ID, 'sender_address_key' ) ) {
			$address_options = $this->locations()->sender_address_options( $selected );
			if ( 1 === count( $address_options ) ) {
				Options::set( self::ID, 'sender_address_key', (string) key( $address_options ) );
			}
		}
		if ( is_wp_error( $updated ) ) {
			$errors[] = $updated->get_error_message();
			$updated  = array();
		}

		return $this->sync_result( $pools, $counts, $updated, $errors );
	}

	public function supports_sender_refresh() {
		return true;
	}

	public function sender_refresh_label() {
		return __( 'Update sender details from Econt', 'bg-commerce-suite' );
	}

	public function refresh_sender_data() {
		$profile_id = (string) bgcs3_get_option( self::ID, 'econt_profile_id', '' );
		if ( '' === $profile_id ) {
			return Sync_Result::error( __( 'Select a sender profile first.', 'bg-commerce-suite' ) );
		}
		$updated = $this->merge_sender_profile( $profile_id, true );
		return is_wp_error( $updated )
			? Sync_Result::error( $updated->get_error_message() )
			: Sync_Result::success( __( 'The sender details were updated from the selected profile.', 'bg-commerce-suite' ), array(), $updated );
	}

	public function admin_location_search( $resource, array $args ) {
		if ( 'offices' !== $resource ) {
			return parent::admin_location_search( $resource, $args );
		}
		return $this->search_stored_locations( isset( $args['type'] ) && 'locker' === $args['type'] ? 'locker' : 'office', isset( $args['query'] ) ? $args['query'] : '' );
	}

	private function merge_sender_profile( $profile_id, $force ) {
		$profile = $this->locations()->profile_by_id( $profile_id );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$client = isset( $profile['client'] ) && is_array( $profile['client'] ) ? $profile['client'] : array();
		$phones = isset( $client['phones'] ) && is_array( $client['phones'] ) ? array_values( array_filter( $client['phones'] ) ) : array();
		$phone  = isset( $phones[0] ) && is_array( $phones[0] )
			? ( isset( $phones[0]['phone'] ) ? $phones[0]['phone'] : ( isset( $phones[0]['number'] ) ? $phones[0]['number'] : '' ) )
			: ( isset( $phones[0] ) ? $phones[0] : '' );
		$map = array(
			'sender_company' => isset( $client['name'] ) ? $client['name'] : '',
			'sender_name'    => isset( $client['molName'] ) ? $client['molName'] : '',
			'sender_phone'   => $phone,
			'sender_email'   => isset( $client['email'] ) ? $client['email'] : '',
		);
		$updated = array();
		foreach ( $map as $key => $api_value ) {
			$api_value = sanitize_text_field( (string) $api_value );
			if ( '' === $api_value || ( ! $force && '' !== (string) bgcs3_get_option( self::ID, $key, '' ) ) ) {
				continue;
			}
			Options::set( self::ID, $key, $api_value );
			$updated[] = $key;
		}
		return $updated;
	}

	public function register( Container $container ) {
		Shipping_Hooks::init();

		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
	}

	/**
	 * @param array<string,string> $methods Registered WC shipping methods.
	 * @return array<string,string>
	 */
	public function register_shipping_method( $methods ) {
		$methods[ 'bgcs3_' . self::ID ] = Shipping_Method::class;
		return $methods;
	}

	/**
	 * Apply the documented sender origin: either senderOfficeCode or senderAddress.
	 *
	 * @param array<string,mixed> $label Label payload.
	 * @param array<string,mixed> $wb Per-order overrides.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function apply_sender_origin( array $label, array $wb = array() ) {
		$handover = (string) Module_Settings::get( self::ID, 'sender_handover' );
		if ( 'address' === $handover ) {
			$key = trim( (string) Module_Settings::get( self::ID, 'sender_address_key' ) );
			$address = $this->locations()->sender_address_by_key( $key );
			if ( is_wp_error( $address ) ) {
				return $address;
			}
			unset( $label['senderOfficeCode'] );
			$label['senderAddress'] = $address;
			return $label;
		}

		$office = isset( $wb['x']['sender_office_code'] ) && '' !== trim( (string) $wb['x']['sender_office_code'] )
			? trim( (string) $wb['x']['sender_office_code'] )
			: trim( (string) bgcs3_get_option( self::ID, 'sender_office_code', '' ) );
		if ( '' === $office ) {
			return new \WP_Error( 'bgcs3_econt_sender_office_missing', __( 'Select an Econt sender office or switch the handover method to courier pickup from a sender address.', 'bg-commerce-suite' ) );
		}
		unset( $label['senderAddress'] );
		$label['senderOfficeCode'] = $office;
		return $label;
	}

	/** Resolve the effective ordinary-goods shipment type. */
	private function resolved_shipment_type( array $wb ) {
		$x = isset( $wb['x'] ) && is_array( $wb['x'] ) ? $wb['x'] : array();
		$type = isset( $x['shipment_type'] ) && '' !== trim( (string) $x['shipment_type'] )
			? strtolower( trim( (string) $x['shipment_type'] ) )
			: strtolower( trim( (string) Module_Settings::get( self::ID, 'shipment_type' ) ) );
		$allowed = array( 'document', 'pack', 'pallet', 'cargo', 'documentpallet', 'big_letter', 'small_letter' );
		return in_array( $type, $allowed, true ) ? $type : 'pack';
	}

	/** Find a synchronized Econt office/APS row by provider code. */
	private function synced_office_row( $code ) {
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return array();
		}
		foreach ( array( 'office', 'locker' ) as $type ) {
			foreach ( Office_Store::get( self::ID, $type ) as $row ) {
				if ( is_array( $row ) && isset( $row['id'] ) && $code === (string) $row['id'] ) {
					return $row;
				}
			}
		}
		return array();
	}

	/** Validate explicit Core package rows before Econt packs[] serialization. */
	private function validate_package_rows( array $wb ) {
		if ( empty( $wb['packages'] ) || ! is_array( $wb['packages'] ) ) {
			return true;
		}
		foreach ( $wb['packages'] as $index => $pack ) {
			if ( ! is_array( $pack ) ) {
				return Label_Result::error( __( 'One of the Econt package rows is invalid. Remove it or enter length, width, height and weight for every package.', 'bg-commerce-suite' ) );
			}
			foreach ( array( 'length', 'width', 'height', 'weight' ) as $key ) {
				if ( ! isset( $pack[ $key ] ) || (float) $pack[ $key ] <= 0 ) {
					return Label_Result::error(
						sprintf(
							/* translators: %d package row number. */
							__( 'Econt package %d is incomplete. Enter length, width, height and weight for every package, or remove the package rows and use the total shipment values.', 'bg-commerce-suite' ),
							(int) $index + 1
						)
					);
				}
			}
		}
		return true;
	}

	/** Convert the standalone courier-request date/time to Econt's JSON unix timestamp. */
	private function courier_request_timestamp( $date, $time ) {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$input = trim( (string) $date ) . ' ' . trim( (string) $time );
		$value = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $input, $timezone );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $value || ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) ) {
			return new \WP_Error( 'bgcs3_econt_courier_time', __( 'Enter a valid Econt courier pickup date and time.', 'bg-commerce-suite' ) );
		}
		return $value->getTimestamp();
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
	 * Quote a shipping price via Econt's createLabel in "calculate" mode.
	 *
	 * NOTE: the request/response payload follows Econt's documented structure
	 * but MUST be validated against a real demo account; on any failure or
	 * missing sender config it returns an unpriced (graceful) result.
	 *
	 * @param array<string,mixed> $package   WC shipping package.
	 * @param Selection           $selection Customer selection.
	 * @return Price_Result
	 */
	public function quote( array $package, Selection $selection ) {
		$receiver = array( 'name' => __( 'Recipient', 'bg-commerce-suite' ) );
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$customer = WC()->customer;
			$first = method_exists( $customer, 'get_billing_first_name' ) ? trim( (string) $customer->get_billing_first_name() ) : '';
			$last  = method_exists( $customer, 'get_billing_last_name' ) ? trim( (string) $customer->get_billing_last_name() ) : '';
			$name  = trim( $first . ' ' . $last );
			if ( '' !== $name ) {
				$receiver['name'] = $name;
			}
			if ( method_exists( $customer, 'get_billing_phone' ) ) {
				$phone = trim( (string) $customer->get_billing_phone() );
				if ( '' !== $phone ) {
					$receiver['phone'] = $phone;
				}
			}
			if ( method_exists( $customer, 'get_billing_email' ) ) {
				$email = trim( (string) $customer->get_billing_email() );
				if ( '' !== $email ) {
					$receiver['email'] = $email;
				}
			}
		}

		$quote_wb         = array();
		$quote_dimensions = Package_Dimensions::resolve_for_package(
			$package,
			array(),
			array(
				'length' => Module_Settings::get( self::ID, 'default_length' ),
				'width'  => Module_Settings::get( self::ID, 'default_width' ),
				'height' => Module_Settings::get( self::ID, 'default_height' ),
			)
		);
		if ( ! empty( $quote_dimensions ) && 'product' === $quote_dimensions['source'] ) {
			$quote_wb['depth']  = $quote_dimensions['length'];
			$quote_wb['width']  = $quote_dimensions['width'];
			$quote_wb['height'] = $quote_dimensions['height'];
		}

		$label = $this->label_builder()->build(
			$selection,
			$this->label_builder()->package_weight( $package ),
			$receiver,
			null,
			$quote_wb
		);

		if ( is_wp_error( $label ) ) {
			return Price_Result::error( $label->get_error_message() );
		}
		if ( null === $label ) {
			return Price_Result::error( __( 'Econt: incomplete configuration for price calculation.', 'bg-commerce-suite' ) );
		}
		$label = $this->apply_sender_origin( $label );
		if ( is_wp_error( $label ) ) {
			return Price_Result::error( $label->get_error_message() );
		}

		$response = $this->client()->call( Client::LABEL_CREATE, array( 'label' => $label, 'mode' => 'calculate' ) );

		if ( is_wp_error( $response ) || empty( $response['label'] ) ) {
			$message = is_wp_error( $response ) ? $response->get_error_message() : __( 'Price calculation failed.', 'bg-commerce-suite' );
			return Price_Result::error( $message );
		}

		$data  = $response['label'];
		$price = isset( $data['totalPrice'] ) ? (float) $data['totalPrice'] : 0.0;

		$warnings = array();
		if ( ! empty( $data['warnings'] ) ) {
			/* translators: %s: warning returned by Econt. */
			$warnings[] = sprintf( __( 'Econt delivery warning: %s', 'bg-commerce-suite' ), (string) $data['warnings'] );
		}
		if ( ! empty( $response['delayedDeliveryWarning'] ) ) {
			/* translators: %s: warning returned by Econt. */
			$warnings[] = sprintf( __( 'Econt delivery warning: %s', 'bg-commerce-suite' ), (string) $response['delayedDeliveryWarning'] );
		}
		if ( ! empty( $response['delayedRequestWarning'] ) ) {
			/* translators: %s: courier-request warning returned by Econt. */
			$warnings[] = sprintf( __( 'Econt courier-request warning: %s', 'bg-commerce-suite' ), (string) $response['delayedRequestWarning'] );
		}
		if ( ! empty( $response['payAfterAcceptIgnored'] ) ) {
			$warnings[] = sprintf(
				/* translators: %s: reason returned by Econt for ignoring the requested Review/Test service. */
				__( 'Econt ignored the “review/test before payment” request: %s. The shipment exists, but without the requested review/test service.', 'bg-commerce-suite' ),
				(string) $response['payAfterAcceptIgnored']
			);
		}

		$result           = new Price_Result();
		$result->valid    = ( $price > 0 );
		$result->cost     = $price;
		$result->currency = isset( $data['currency'] ) ? (string) $data['currency'] : get_woocommerce_currency();
		$result->warnings = array_values( array_unique( array_filter( $warnings ) ) );
		// Econt separates the two in its own model: expectedDeliveryDate is the
		// estimate, while deliveryTime is the moment of a delivery that already
		// happened. Only the former may be shown to a customer as an ETA.
		$result->delivery_estimate = Delivery_Estimate::normalize(
			isset( $data['expectedDeliveryDate'] ) ? $data['expectedDeliveryDate'] : '',
			self::ID,
			Delivery_Estimate::KIND_ESTIMATE
		);
		$result->meta = array(
			'econt_calculation' => $data,
			'payer'             => 'SENDER',
		);

		return $result;
	}

	/**
	 * Create a real Econt waybill for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return Label_Result
	 */
	/**
	 * Build the documented ShippingLabel body for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>|Label_Result
	 */
	private function order_label_payload( \WC_Order $order ) {
		$selection = $this->order_selection( $order );
		if ( ! $selection ) {
			return Label_Result::error( __( 'The order has no saved delivery selection.', 'bg-commerce-suite' ) );
		}

		$wb = $order->get_meta( '_bgcs3_wb' );
		$wb = is_array( $wb ) ? $wb : array();
		$x  = isset( $wb['x'] ) && is_array( $wb['x'] ) ? $wb['x'] : array();

		$package_validation = $this->validate_package_rows( $wb );
		if ( true !== $package_validation ) {
			return $package_validation;
		}
		$shipment_type = $this->resolved_shipment_type( $wb );

		// Econt explicitly documents that payAfterAccept/payAfterTest is ignored
		// for automatic post stations (and payAfterTest also for Econt Drive).
		// Do not create/update a shipment while pretending the requested service
		// will be present when the destination is an Econtomat/locker.
		$requested_obp = ! empty( $wb['obp'] ) ? (string) $wb['obp'] : '';
		if ( '' === $requested_obp ) {
			$default_pay_after = (string) Module_Settings::get( self::ID, 'pay_after' );
			$requested_obp = 'test' === $default_pay_after ? 'TEST' : ( 'accept' === $default_pay_after ? 'OPEN' : 'NO' );
		}
		if ( 'locker' === (string) $selection->delivery_type && in_array( $requested_obp, array( 'OPEN', 'TEST' ), true ) ) {
			return Label_Result::error(
				__( 'Econt does not apply “Review” / “Review and test” to Econtomat (automatic post station) deliveries. Set the per-order review/test option to “No”, or change the delivery destination before creating the shipment.', 'bg-commerce-suite' )
			);
		}

		$receiver_office_code = isset( $selection->office['id'] ) ? trim( (string) $selection->office['id'] ) : '';
		$receiver_office = $this->synced_office_row( $receiver_office_code );
		if ( 'TEST' === $requested_obp && ! empty( $receiver_office['is_drive'] ) ) {
			return Label_Result::error( __( 'Econt does not apply “Review and test” to Econt Drive deliveries. Use “Review”, disable the option, or choose another destination.', 'bg-commerce-suite' ) );
		}
		// BGCS-AUDIT-017 — `Office.shipmentTypes` and `label.shipmentType` are two
		// different vocabularies. Comparing them directly refused `pack` at all
		// 572 synced offices. `Shipment_Type_Map` enforces only the documented
		// correspondences and answers null for everything else, so an ordinary
		// parcel is never refused here — Econt decides.
		if ( false === Shipment_Type_Map::office_accepts( isset( $receiver_office['shipment_types'] ) ? $receiver_office['shipment_types'] : array(), $shipment_type ) ) {
			return Label_Result::error(
				sprintf(
					/* translators: 1: Econt shipment type, 2: office code. */
					__( 'Econt shipment type “%1$s” is not accepted by destination office %2$s. Choose a supported shipment type or another destination.', 'bg-commerce-suite' ),
					$shipment_type,
					$receiver_office_code
				)
			);
		}

		$sender_handover = (string) Module_Settings::get( self::ID, 'sender_handover' );
		if ( 'office' === $sender_handover ) {
			$sender_office_code = isset( $x['sender_office_code'] ) && '' !== trim( (string) $x['sender_office_code'] )
				? trim( (string) $x['sender_office_code'] )
				: trim( (string) bgcs3_get_option( self::ID, 'sender_office_code', '' ) );
			$sender_office = $this->synced_office_row( $sender_office_code );
			// BGCS-AUDIT-017 — same vocabulary map as the receiver guard above.
			if ( false === Shipment_Type_Map::office_accepts( isset( $sender_office['shipment_types'] ) ? $sender_office['shipment_types'] : array(), $shipment_type ) ) {
				return Label_Result::error(
					sprintf(
						/* translators: 1: Econt shipment type, 2: office code. */
						__( 'Econt shipment type “%1$s” is not accepted by sender office %2$s. Choose a supported shipment type or another sender handover location.', 'bg-commerce-suite' ),
						$shipment_type,
						$sender_office_code
					)
				);
			}
		}

		$delivery_to_floor_override = isset( $x['delivery_to_floor'] ) ? (string) $x['delivery_to_floor'] : '';
		$delivery_to_floor = '' !== $delivery_to_floor_override
			? ( 'yes' === $delivery_to_floor_override )
			: ( 'yes' === (string) Module_Settings::get( self::ID, 'delivery_to_floor' ) );
		if ( $delivery_to_floor && 'address' !== (string) $selection->delivery_type ) {
			return Label_Result::error( __( 'Econt “Delivery to floor” can be used only for delivery to an address. Disable the option or select address delivery.', 'bg-commerce-suite' ) );
		}

		// “Partial delivery” is Econt's Review/Test and Choice service, not an
		// independent boolean. The provider requires Review (or Review+Test), a
		// digital packing list with more than one item, and return instructions.
		$partial_override = isset( $x['partial_delivery'] ) ? (string) $x['partial_delivery'] : '';
		$partial_delivery = '' !== $partial_override
			? ( 'yes' === $partial_override )
			: ( 'yes' === (string) Module_Settings::get( self::ID, 'partial_delivery' ) );
		if ( $partial_delivery ) {
			if ( ! in_array( $requested_obp, array( 'OPEN', 'TEST' ), true ) ) {
				return Label_Result::error( __( 'Econt Partial delivery requires “Review” or “Review and test”. Enable one of those options before creating the shipment.', 'bg-commerce-suite' ) );
			}

			$return_override = isset( $x['instructions_return'] ) ? trim( (string) $x['instructions_return'] ) : '';
			$return_instruction = '0' === $return_override
				? ''
				: ( '' !== $return_override ? $return_override : trim( (string) Module_Settings::get( self::ID, 'instructions_return' ) ) );
			if ( '' === $return_instruction || (int) $return_instruction <= 0 ) {
				return Label_Result::error( __( 'Econt Partial delivery requires a valid return-instruction template. Configure “Return instruction” in Econt settings or for this order.', 'bg-commerce-suite' ) );
			}

			$item_units = 0;
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$item_units += max( 0, (int) $item->get_quantity() );
			}
			if ( $item_units < 2 ) {
				return Label_Result::error( __( 'Econt Partial delivery (Review/Test and Choice) requires more than one item in the digital packing list.', 'bg-commerce-suite' ) );
			}
		}

		$phone = ! empty( $wb['phone'] ) ? trim( (string) $wb['phone'] ) : trim( (string) $order->get_billing_phone() );
		if ( '' === $phone && method_exists( $order, 'get_shipping_phone' ) ) {
			$phone = trim( (string) $order->get_shipping_phone() );
		}
		if ( '' === $phone ) {
			return Label_Result::error( __( 'The order has no recipient phone number. Add a phone number to the order and try again.', 'bg-commerce-suite' ) );
		}

		// BGCS customer-facing shipping is part of the WooCommerce order, so
		// courier-service charges are always assigned to the sender. This prevents
		// Econt from collecting the same delivery charge again from the receiver.
		$payment_type = ! empty( $x['payment_type'] )
			? strtoupper( (string) $x['payment_type'] )
			: strtoupper( (string) Module_Settings::get( self::ID, 'payment_type' ) );
		if ( ! in_array( $payment_type, array( 'CASH', 'CREDIT', 'VOUCHER' ), true ) ) {
			return Label_Result::error( __( 'Invalid Econt sender payment method. Econt accepts Cash, Credit or Voucher.', 'bg-commerce-suite' ) );
		}

		$weight = ( isset( $wb['weight'] ) && '' !== $wb['weight'] )
			? max( Weight::MIN_KG, (float) $wb['weight'] )
			: $this->label_builder()->order_weight( $order );

		$label = $this->label_builder()->build(
			$selection,
			$weight,
			array(
				'name'  => ! empty( $wb['contact_name'] ) ? trim( (string) $wb['contact_name'] ) : trim( $order->get_formatted_billing_full_name() ),
				'phone' => $phone,
				'email' => ! empty( $wb['email'] ) ? trim( (string) $wb['email'] ) : $order->get_billing_email(),
			),
			$order,
			$wb
		);

		if ( is_wp_error( $label ) ) {
			return Label_Result::error( $label->get_error_message() );
		}
		if ( null === $label ) {
			return Label_Result::error( __( 'Econt shipment configuration is incomplete.', 'bg-commerce-suite' ) );
		}
		$label = $this->apply_sender_origin( $label, $wb );
		if ( is_wp_error( $label ) ) {
			return Label_Result::error( $label->get_error_message() );
		}

		// Account-bound references must belong to the currently selected Econt
		// profile. This catches stale addresses/templates before createLabel.
		$selected_profile = trim( (string) bgcs3_get_option( self::ID, 'econt_profile_id', '' ) );
		if ( '' !== $selected_profile ) {
			$profile = $this->locations()->profile_by_id( $selected_profile );
			if ( is_wp_error( $profile ) ) {
				return Label_Result::error( $profile->get_error_message() );
			}
			if ( 'address' === (string) Module_Settings::get( self::ID, 'sender_handover' ) ) {
				$sender_address_key = trim( (string) Module_Settings::get( self::ID, 'sender_address_key' ) );
				if ( 0 !== strpos( $sender_address_key, $selected_profile . ':' ) ) {
					return Label_Result::error( __( 'The selected Econt pickup address does not belong to the current sender profile. Synchronize Econt data and select the address again.', 'bg-commerce-suite' ) );
				}
			}
			foreach ( (array) ( isset( $label['instructions'] ) ? $label['instructions'] : array() ) as $instruction ) {
				if ( ! is_array( $instruction ) || empty( $instruction['type'] ) || empty( $instruction['id'] ) ) {
					continue;
				}
				$known = $this->locations()->instruction_options( (string) $instruction['type'], $selected_profile );
				if ( ! empty( $known ) && ! isset( $known[ (string) $instruction['id'] ] ) ) {
					return Label_Result::error( __( 'One of the selected Econt instruction templates is not available for the current sender profile. Synchronize Econt data and select valid templates.', 'bg-commerce-suite' ) );
				}
			}
		}

		$selected_template = isset( $label['services']['cdPayOptionsTemplate'] ) ? trim( (string) $label['services']['cdPayOptionsTemplate'] ) : '';
		if ( '' !== $selected_template ) {
			$known_templates = $this->locations()->cd_pay_option_details( (string) bgcs3_get_option( self::ID, 'econt_profile_id', '' ) );
			if ( ! empty( $known_templates ) && ! isset( $known_templates[ $selected_template ] ) ) {
				return Label_Result::error( __( 'The selected Econt COD / PPP payout agreement is not available for the current account. Synchronize Econt data and select a valid agreement.', 'bg-commerce-suite' ) );
			}
		}

		return $label;
	}

	/**
	 * Wrap a ShippingLabel in Econt's documented request envelope.
	 * Courier pickup time belongs next to `label`, not inside ShippingLabel.
	 *
	 * @param array<string,mixed> $label ShippingLabel payload.
	 * @param string              $mode  create|calculate; update has no mode.
	 * @param bool                $with_pickup Whether pickup settings may be sent.
	 * @return array<string,mixed>
	 */
	private function label_request_payload( array $label, $mode = '', $with_pickup = true, array $wb = array() ) {
		$body = array( 'label' => $label );
		if ( '' !== $mode ) {
			$body['mode'] = $mode;
		}

		$x = isset( $wb['x'] ) && is_array( $wb['x'] ) ? $wb['x'] : array();
		$request_override = isset( $x['request_courier'] ) ? (string) $x['request_courier'] : '';
		$request_courier  = '' !== $request_override
			? ( 'yes' === $request_override )
			: ( 'yes' === (string) Module_Settings::get( self::ID, 'only_courier_request' ) );

		if ( $with_pickup && $request_courier ) {
			$from_override = isset( $x['request_courier_from'] ) ? trim( (string) $x['request_courier_from'] ) : '';
			$to_override   = isset( $x['request_courier_to'] ) ? trim( (string) $x['request_courier_to'] ) : '';
			$from = '0' === $from_override
				? ''
				: ( '' !== $from_override ? $from_override : trim( (string) Module_Settings::get( self::ID, 'courier_request_time_from' ) ) );
			$to = '0' === $to_override
				? ''
				: ( '' !== $to_override ? $to_override : trim( (string) Module_Settings::get( self::ID, 'courier_request_time_to' ) ) );
			if ( preg_match( '/^\d{1,2}:\d{2}$/', $from ) ) {
				$body['requestCourierTimeFrom'] = $from;
			}
			if ( preg_match( '/^\d{1,2}:\d{2}$/', $to ) ) {
				$body['requestCourierTimeTo'] = $to;
			}
		}

		return $body;
	}

	/**
	 * @param array<string,mixed>|\WP_Error $response API response.
	 * @param string                         $fallback Fallback error.
	 * @return Label_Result
	 */
	private function label_result_from_response( $response, $fallback, $fail_on_ignored = false ) {
		if ( is_wp_error( $response ) || empty( $response['label'] ) ) {
			return Label_Result::error( is_wp_error( $response ) ? $response->get_error_message() : $fallback );
		}

		// Econt may create/update the label successfully but explicitly report
		// that payAfterAccept/payAfterTest was ignored (for example for an
		// automatic station). On UPDATE we can fail safely because the existing
		// shipment remains known to BGCS. On CREATE we must keep the successful
		// shipment number (otherwise a real courier shipment would be orphaned),
		// so the warning is stored in result meta and Core writes it as a note.
		$warnings = array();
		if ( ! empty( $response['payAfterAcceptIgnored'] ) ) {
			$ignored_warning = sprintf(
				/* translators: %s: reason returned by Econt for ignoring the requested Review/Test service. */
				__( 'Econt ignored the “review/test before payment” request: %s. The shipment exists, but without the requested review/test service.', 'bg-commerce-suite' ),
				(string) $response['payAfterAcceptIgnored']
			);
			if ( $fail_on_ignored ) {
				return Label_Result::error( $ignored_warning );
			}
			$warnings[] = $ignored_warning;
		}
		if ( ! empty( $response['delayedDeliveryWarning'] ) ) {
			/* translators: %s: warning returned by Econt. */
			$warnings[] = sprintf( __( 'Econt delivery warning: %s', 'bg-commerce-suite' ), (string) $response['delayedDeliveryWarning'] );
		}
		if ( ! empty( $response['delayedRequestWarning'] ) ) {
			/* translators: %s: courier-request warning returned by Econt. */
			$warnings[] = sprintf( __( 'Econt courier-request warning: %s', 'bg-commerce-suite' ), (string) $response['delayedRequestWarning'] );
		}

		$data = $response['label'];
		if ( ! empty( $data['warnings'] ) ) {
			/* translators: %s: warning returned by Econt. */
			$warnings[] = sprintf( __( 'Econt delivery warning: %s', 'bg-commerce-suite' ), (string) $data['warnings'] );
		}
		if ( ! empty( $data['shipmentEdition']['editionError'] ) ) {
			/* translators: %s: shipment edition error returned by Econt. */
			return Label_Result::error( sprintf( __( 'Econt did not apply the shipment edition: %s', 'bg-commerce-suite' ), (string) $data['shipmentEdition']['editionError'] ) );
		}
		$number = isset( $data['shipmentNumber'] ) ? (string) $data['shipmentNumber'] : '';
		if ( '' === $number ) {
			return Label_Result::error( __( 'Econt did not return a shipment label number.', 'bg-commerce-suite' ) );
		}
		$result = new Label_Result();
		$result->success    = true;
		$result->courier    = self::ID;
		$result->number     = $number;
		$result->pdf_url    = isset( $data['pdfURL'] ) ? (string) $data['pdfURL'] : '';
		$result->label_status = '' !== $result->pdf_url ? 'remote' : 'missing';
		$result->created_at = time();
		// Same rule as the quote path: deliveryTime records a completed delivery,
		// so it is never promoted to an estimate.
		$delivery_estimate = Delivery_Estimate::normalize(
			isset( $data['expectedDeliveryDate'] ) ? $data['expectedDeliveryDate'] : '',
			self::ID,
			Delivery_Estimate::KIND_ESTIMATE
		);
		if ( ! empty( $delivery_estimate ) ) {
			$result->meta['delivery_estimate'] = $delivery_estimate;
		}
		if ( ! empty( $warnings ) ) {
			$result->meta['provider_warning'] = implode( ' ', $warnings );
		}
		if ( ! empty( $response['courierRequestID'] ) ) {
			$result->meta['courier_request_id'] = (string) $response['courierRequestID'];
		}
		return $result;
	}

	/**
	 * Create a real Econt waybill for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return Label_Result
	 */
	public function create_label( \WC_Order $order ) {
		$preflight = $this->preflight_shipment( $order );
		if ( $preflight->is_blocked() ) {
			return $preflight->label_error();
		}

		$label = $this->order_label_payload( $order );
		if ( $label instanceof Label_Result ) {
			return $preflight->reject( $label, 'econt_payload' );
		}
		$wb = $order->get_meta( '_bgcs3_wb' );
		$wb = is_array( $wb ) ? $wb : array();
		$sender   = isset( $label['senderClient'] ) && is_array( $label['senderClient'] ) ? $label['senderClient'] : array();
		$receiver = isset( $label['receiverClient'] ) && is_array( $label['receiverClient'] ) ? $label['receiverClient'] : array();
		$services = isset( $label['services'] ) && is_array( $label['services'] ) ? $label['services'] : array();
		$service_keys = array_keys( $services );
		sort( $service_keys, SORT_STRING );
		$create_payload = $this->label_request_payload( $label, 'create', true, $wb );
		$preflight
			->section(
				'sender',
				array(
					'account_id'      => (string) bgcs3_get_option( self::ID, 'econt_profile_id', '' ),
					'location_type'   => (string) Module_Settings::get( self::ID, 'sender_handover' ),
					'location_ready'  => ! empty( $label['senderAddress'] ) || ! empty( $label['senderOfficeCode'] ),
					'contact_present' => ! empty( $sender['name'] ) || ! empty( $sender['phones'] ),
				)
			)
			->section(
				'recipient_payload',
				array(
					'private_person' => ! empty( $receiver['privatePerson'] ),
					'office_ready'   => ! empty( $label['receiverOfficeCode'] ),
					'address_ready'  => ! empty( $label['receiverAddress'] ),
					'name_present'   => ! empty( $receiver['name'] ),
					'phone_present'  => ! empty( $receiver['phones'] ),
				)
			)
			->section(
				'package_payload',
				array(
					'weight_kg'       => ! empty( $label['weight'] ) ? (float) $label['weight'] : 0.0,
					'parcel_count'    => ! empty( $label['packCount'] ) ? (int) $label['packCount'] : 0,
					'contents_present' => ! empty( $label['shipmentDescription'] ),
				)
			)
			->section(
				'services',
				array(
					'requested'          => $service_keys,
					'instructions_count' => ! empty( $label['instructions'] ) && is_array( $label['instructions'] ) ? count( $label['instructions'] ) : 0,
				)
			)
			->section(
				'payer',
				array(
					'courier_service' => 'SENDER',
					'cod_pmt'         => ! empty( $services['cdAmount'] ) ? 'SENDER' : '',
					'package'         => 'SENDER',
					'declared_value'  => 'SENDER',
				)
			)
			->payload_ready( $create_payload );

		// Econt documents `mode=validate` for the same createLabel payload. Use it
		// before the destructive create call so account-specific restrictions
		// (Review/Test/Choice, payment method, packing list, instructions, etc.)
		// are rejected before a real shipment number is issued.
		$validation = $this->client()->call( Client::LABEL_CREATE, $this->label_request_payload( $label, 'validate', true, $wb ) );
		if ( is_wp_error( $validation ) ) {
			/* translators: %s: validation error returned by Econt. */
			$error = Label_Result::error( sprintf( __( 'Econt rejected the shipment settings during validation: %s', 'bg-commerce-suite' ), $validation->get_error_message() ) );
			return $preflight->reject( $error, 'econt_provider_validation' );
		}
		if ( ! empty( $validation['payAfterAcceptIgnored'] ) ) {
			/* translators: %s: reason returned by Econt for rejecting the requested Review/Test option. */
			$error = Label_Result::error( sprintf( __( 'Econt cannot apply the requested Review/Test option: %s', 'bg-commerce-suite' ), (string) $validation['payAfterAcceptIgnored'] ) );
			return $preflight->reject( $error, 'econt_service_validation' );
		}

		// Financial invariant: the WooCommerce order already contains the
		// customer-facing delivery amount. Econt must not report an additional
		// courier-service amount payable by the receiver, otherwise the customer
		// would be charged twice (once in the order total and once by Econt).
		$validation_label = isset( $validation['label'] ) && is_array( $validation['label'] ) ? $validation['label'] : array();
		$receiver_due     = isset( $validation_label['receiverDueAmount'] ) ? (float) $validation_label['receiverDueAmount'] : 0.0;
		if ( $receiver_due > 0.009 ) {
			$receiver_currency = ! empty( $validation_label['currency'] ) ? strtoupper( (string) $validation_label['currency'] ) : $order->get_currency();
			$error = Label_Result::error(
				sprintf(
					/* translators: 1: amount payable by receiver, 2: currency. */
					__( 'Econt reports an additional courier-service charge of %1$s %2$s payable by the recipient. Shipment creation is blocked because delivery is already included in the WooCommerce order total and this would charge the customer twice.', 'bg-commerce-suite' ),
					wc_format_decimal( $receiver_due, 2 ),
					$receiver_currency
				)
			);
			return $preflight->reject( $error, 'econt_double_charge' );
		}

		$creation = Shipment_Creation::remote_started( $order, $this );
		if ( true !== $creation ) {
			return $creation;
		}
		$create_response = $this->client()->call( Client::LABEL_CREATE, $create_payload );
		$created_label   = ! is_wp_error( $create_response ) && ! empty( $create_response['label'] ) && is_array( $create_response['label'] )
			? $create_response['label']
			: array();
		$created_number  = ! empty( $created_label['shipmentNumber'] ) ? (string) $created_label['shipmentNumber'] : '';
		if ( is_wp_error( $create_response ) || '' === $created_number ) {
			Shipment_Creation::remote_failed( $order, $create_response );
		} else {
			Shipment_Creation::remote_accepted(
				$order,
				array(
					'shipment_number' => $created_number,
					'tracking_numbers' => array( $created_number ),
					'label_reference' => $created_number,
				)
			);
		}
		$result = $this->label_result_from_response( $create_response, __( 'Shipment label creation failed.', 'bg-commerce-suite' ) );

		if ( $result->success ) {
			if ( ! empty( $result->meta['courier_request_id'] ) ) {
				$result->meta['courier_request_date'] = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
				$result->meta['courier_request_time_from'] = isset( $create_payload['requestCourierTimeFrom'] ) ? (string) $create_payload['requestCourierTimeFrom'] : '';
				$result->meta['courier_request_time_to'] = isset( $create_payload['requestCourierTimeTo'] ) ? (string) $create_payload['requestCourierTimeTo'] : '';
			}
			$result->shipment_number = $result->number;
			$result->tracking_numbers = array( $result->number );
			$result->label_reference = $result->number;
			$read_back = $this->client()->get_shipment_statuses( array( $result->number ) );
			$result->meta['read_back_status'] = is_wp_error( $read_back )
				? 'unavailable'
				: ( Shipment_Creation::response_confirms( $read_back, array( 'shipmentNumber', 'shipment_number' ), $result->number ) ? 'verified' : 'partial' );
			if ( '' !== (string) $result->pdf_url ) {
				$pdf = $this->client()->download_label_pdf( $result->pdf_url );
				if ( ! is_wp_error( $pdf ) && 0 === strpos( $pdf, '%PDF-' ) ) {
					$local_url = Label_Pdf_Store::save( self::ID, $result->number . '.pdf', $pdf );
					if ( $local_url ) {
						$result->pdf_url      = $local_url;
						$result->label_status = 'available';
					}
				}
				if ( 'available' !== $result->label_status ) {
					$warning = __( 'Econt created the shipment, but BGCS could not cache the label PDF locally. The temporary Econt label link was retained.', 'bg-commerce-suite' );
					$result->meta['provider_warning'] = ! empty( $result->meta['provider_warning'] )
						? (string) $result->meta['provider_warning'] . ' ' . $warning
						: $warning;
				}
			}
			foreach ( array( 'totalPrice' => 'econt_total_price', 'currency' => 'econt_currency', 'senderDueAmount' => 'econt_sender_due', 'receiverDueAmount' => 'econt_receiver_due' ) as $source_key => $meta_key ) {
				if ( array_key_exists( $source_key, $validation_label ) ) {
					$result->meta[ $meta_key ] = $validation_label[ $source_key ];
				}
			}
		}

		return $result;
	}

	/**
	 * Core policy: declined. Econt does publish LabelService.updateLabel, but
	 * BGCS requires Save order settings -> manual Cancel -> manual Create.
	 *
	 * @return bool
	 */
	public function supports_label_update() {
		return false;
	}

	/**
	 * Fail-safe only. Retained so a stale cached admin script cannot reach a
	 * real Econt edit: it always refuses and points at the manual flow.
	 *
	 * @param \WC_Order $order Order.
	 * @return Label_Result
	 */
	public function update_label( \WC_Order $order ) {
		unset( $order );
		return Label_Result::error( __( 'BGCS does not edit an existing Econt shipment automatically. Save the order settings, cancel the current shipment manually, then create a new shipment label.', 'bg-commerce-suite' ) );
	}

	/**
	 * @param \WC_Order $order  Order.
	 * @param string    $number Shipment number.
	 * @return mixed|\WP_Error
	 */
	protected function cancel_shipment( \WC_Order $order, $number ) {
		$response = $this->client()->call( Client::LABEL_DELETE, array( 'shipmentNumbers' => array( $number ) ) );
		if ( is_wp_error( $response ) || empty( $response ) ) {
			// Econt's official JSON example permits an empty successful 2xx body.
			return $response;
		}

		$results = isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : array();
		if ( empty( $results ) ) {
			return Courier_Error::unknown( __( 'Econt returned an unrecognized cancellation response. The shipment remains active in BGCS.', 'bg-commerce-suite' ) );
		}

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || (string) ( isset( $result['shipmentNum'] ) ? $result['shipmentNum'] : '' ) !== (string) $number ) {
				continue;
			}
			if ( ! empty( $result['error'] ) ) {
				return Courier_Error::validation( __( 'Econt did not confirm cancellation of the active shipment.', 'bg-commerce-suite' ) );
			}
			return $response;
		}

		return Courier_Error::unknown( __( 'Econt did not identify the requested shipment in its cancellation response. The shipment remains active in BGCS.', 'bg-commerce-suite' ) );
	}

	/**
	 * @param string $number Shipment number.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function fetch_tracking( $number ) {
		return $this->client()->get_shipment_statuses( array( $number ) );
	}

	/**
	 * Econt getShipmentStatuses accepts an array of shipment numbers, so Core can
	 * refresh a whole chunk with one request instead of one HTTP call per order.
	 *
	 * @return bool
	 */
	public function supports_bulk_tracking() {
		return true;
	}

	/**
	 * Econt do not publish a small per-request ceiling for this method. Keep the
	 * chunk deliberately below Core's hard ceiling so one transient failure never
	 * delays an unnecessarily large set of orders.
	 *
	 * @return int
	 */
	public function tracking_batch_size() {
		return 50;
	}

	/**
	 * One getShipmentStatuses request for several Econt waybills.
	 *
	 * @param string[] $numbers   Shipment numbers.
	 * @param bool     $last_only Ignored; Econt returns the current status object.
	 * @return array<string,Tracking_Result>
	 */
	public function bulk_tracking( array $numbers, $last_only = false ) {
		unset( $last_only );

		$response = $this->client()->get_shipment_statuses( $numbers );
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) ( isset( $response['shipmentStatuses'] ) ? $response['shipmentStatuses'] : array() ) as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['error'] ) || empty( $item['status'] ) || ! is_array( $item['status'] ) ) {
				continue;
			}

			$status = $item['status'];
			$number = isset( $status['shipmentNumber'] ) ? trim( (string) $status['shipmentNumber'] ) : '';
			if ( '' === $number ) {
				continue;
			}

			$result          = new Tracking_Result();
			$result->success = true;
			$this->fill_status_tracking( $result, $status );
			$out[ $number ] = $result;
		}

		return $out;
	}

	/**
	 * @param Tracking_Result     $result   Result.
	 * @param array<string,mixed> $response API response.
	 * @return void
	 */
	protected function fill_tracking( Tracking_Result $result, array $response ) {
		$status = isset( $response['shipmentStatuses'][0]['status'] ) && is_array( $response['shipmentStatuses'][0]['status'] )
			? $response['shipmentStatuses'][0]['status']
			: array();

		$this->fill_status_tracking( $result, $status );
	}

	/**
	 * Convert one official ShipmentStatus node into canonical events plus a
	 * compact provider snapshot. The snapshot intentionally excludes names,
	 * phones, addresses and URLs; it contains only operational/accounting facts
	 * useful on the WooCommerce order.
	 *
	 * @param Tracking_Result     $result Result.
	 * @param array<string,mixed> $status ShipmentStatus node.
	 * @return void
	 */
	private function fill_status_tracking( Tracking_Result $result, array $status ) {
		$result->status = isset( $status['shortDeliveryStatus'] ) ? (string) $status['shortDeliveryStatus'] : '';
		$receiver_delivery_type = isset( $status['receiverDeliveryType'] ) ? strtolower( (string) $status['receiverDeliveryType'] ) : '';

		$provider_map = array(
			'shipmentNumber'        => 'shipment_number',
			'createdTime'           => 'created_time',
			'sendTime'              => 'send_time',
			'deliveryTime'          => 'delivery_time',
			'shipmentType'          => 'shipment_type',
			'packCount'             => 'pack_count',
			'weight'                => 'weight',
			'senderDeliveryType'    => 'sender_delivery_type',
			'receiverDeliveryType'  => 'receiver_delivery_type',
			'cdCollectedAmount'     => 'cd_collected_amount',
			'cdCollectedCurrency'   => 'cd_collected_currency',
			'cdCollectedTime'       => 'cd_collected_time',
			'cdPaidAmount'          => 'cd_paid_amount',
			'cdPaidCurrency'        => 'cd_paid_currency',
			'cdPaidTime'            => 'cd_paid_time',
			'totalPrice'            => 'total_price',
			'currency'              => 'currency',
			'senderDueAmount'       => 'sender_due_amount',
			'receiverDueAmount'     => 'receiver_due_amount',
			'otherDueAmount'        => 'other_due_amount',
			'deliveryAttemptCount'  => 'delivery_attempt_count',
			'expectedDeliveryDate'  => 'expected_delivery_date',
			'warnings'              => 'warnings',
			'routingCode'           => 'routing_code',
			'storageOfficeName'     => 'storage_office_name',
			'shortDeliveryStatusEn' => 'short_status_en',
		);
		foreach ( $provider_map as $source => $target ) {
			if ( isset( $status[ $source ] ) && is_scalar( $status[ $source ] ) && '' !== trim( (string) $status[ $source ] ) ) {
				$result->meta[ $target ] = $status[ $source ];
			}
		}

		if ( ! empty( $status['createdTime'] ) ) {
			$result->events[] = array( 'time' => $status['createdTime'], 'code' => 'created', 'text' => __( 'Shipment created', 'bg-commerce-suite' ) );
		}
		if ( ! empty( $status['sendTime'] ) ) {
			$result->events[] = array( 'time' => $status['sendTime'], 'code' => 'sent', 'text' => __( 'The shipment has been sent', 'bg-commerce-suite' ) );
		}

		if ( ! empty( $status['trackingEvents'] ) && is_array( $status['trackingEvents'] ) ) {
			foreach ( $status['trackingEvents'] as $event ) {
				$result->events[] = array(
					'time'                   => isset( $event['time'] ) ? $event['time'] : '',
					'code'                   => isset( $event['destinationType'] ) ? strtolower( (string) $event['destinationType'] ) : '',
					'text'                   => isset( $event['destinationDetails'] ) && '' !== (string) $event['destinationDetails'] ? $event['destinationDetails'] : ( isset( $event['officeName'] ) ? $event['officeName'] : '' ),
					'receiver_delivery_type' => $receiver_delivery_type,
				);
			}
		}

		if ( ! empty( $status['deliveryTime'] ) ) {
			$result->events[] = array( 'time' => $status['deliveryTime'], 'code' => 'delivered', 'text' => __( 'Delivered', 'bg-commerce-suite' ) );
		}

		$short_en = isset( $status['shortDeliveryStatusEn'] ) ? trim( (string) $status['shortDeliveryStatusEn'] ) : '';
		$short_bg = isset( $status['shortDeliveryStatus'] ) ? trim( (string) $status['shortDeliveryStatus'] ) : '';
		if ( '' !== $short_en || '' !== $short_bg ) {
			$latest_time = '';
			$latest_ts   = 0;
			foreach ( $result->events as $existing_event ) {
				$event_time = isset( $existing_event['time'] ) ? $existing_event['time'] : '';
				$event_ts   = Tracking_Store::timestamp( $event_time );
				if ( $event_ts >= $latest_ts ) {
					$latest_ts   = $event_ts;
					$latest_time = $event_time;
				}
			}
			$short_bg_key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $short_bg, 'UTF-8' ) : strtolower( $short_bg );
			$result->events[] = array(
				'time'                   => $latest_time,
				'code'                   => '' !== $short_en ? 'short:' . strtolower( $short_en ) : 'short_bg:' . $short_bg_key,
				'text'                   => '' !== $short_bg ? $short_bg : $short_en,
				'receiver_delivery_type' => $receiver_delivery_type,
			);
		}
	}

	/**
	 * Normalize Econt's documented ShipmentStatus/trackingEvents fields.
	 * deliveryTime is represented as a synthetic `delivered` event; destinationType
	 * remains an event/location classifier and is mapped conservatively.
	 *
	 * @param array<string,mixed> $event Tracking event (see fill_tracking()).
	 * @return string One of Tracking_State::*.
	 */
	public function normalize_status( array $event ) {
		$code = isset( $event['code'] ) ? strtolower( trim( (string) $event['code'] ) ) : '';
		$receiver_type = isset( $event['receiver_delivery_type'] ) ? strtolower( trim( (string) $event['receiver_delivery_type'] ) ) : '';

		$short_map = array(
			'short:prepared in eecont'              => Tracking_State::CREATED,
			'short:accepted in econt'               => Tracking_State::ACCEPTED,
			'short:in route'                        => Tracking_State::IN_TRANSIT,
			'short:in courier'                      => Tracking_State::IN_TRANSIT,
			'short:in pick up courier'              => Tracking_State::ACCEPTED,
			'short:accepted in office'              => Tracking_State::ACCEPTED,
			'short:in delivery courier\'s office'   => false !== strpos( $receiver_type, 'office' ) ? Tracking_State::AVAILABLE_FOR_PICKUP : Tracking_State::IN_TRANSIT,
			'short:arrived in office'               => false !== strpos( $receiver_type, 'office' ) ? Tracking_State::AVAILABLE_FOR_PICKUP : Tracking_State::IN_TRANSIT,
			'short:arrival departure from hub'      => Tracking_State::IN_TRANSIT,
			'short:delivered'                       => Tracking_State::DELIVERED,
			'short:cancelled after sending'         => Tracking_State::CANCELLED,
			'short:cancelled before sending'        => Tracking_State::CANCELLED,
			'short:is returning to sender'          => Tracking_State::RETURN_IN_PROGRESS,
			'short:returned to sender'              => Tracking_State::RETURNED,
			'short_bg:подготвена в eecont'           => Tracking_State::CREATED,
			'short_bg:приета в еконт'                => Tracking_State::ACCEPTED,
			'short_bg:пътува по линия'               => Tracking_State::IN_TRANSIT,
			'short_bg:в куриер'                      => Tracking_State::IN_TRANSIT,
			'short_bg:в офис'                        => Tracking_State::IN_TRANSIT,
			'short_bg:в офис на приемащ куриер'      => Tracking_State::IN_TRANSIT,
			'short_bg:приета в офис'                 => Tracking_State::ACCEPTED,
			'short_bg:в офис на предаващ куриер'     => Tracking_State::IN_TRANSIT,
			'short_bg:пристигнала в офис'            => false !== strpos( $receiver_type, 'office' ) ? Tracking_State::AVAILABLE_FOR_PICKUP : Tracking_State::IN_TRANSIT,
			'short_bg:постъпила за обработка в логистичен център' => Tracking_State::IN_TRANSIT,
			'short_bg:върната'                       => Tracking_State::RETURNED,
			'short_bg:доставена'                     => Tracking_State::DELIVERED,
			'short_bg:анулирана след изпращане'      => Tracking_State::CANCELLED,
			'short_bg:анулирана преди изпращане'     => Tracking_State::CANCELLED,
			'short_bg:връща се към подател'          => Tracking_State::RETURN_IN_PROGRESS,
			'short_bg:върната и доставена към подател' => Tracking_State::RETURNED,
		);
		if ( isset( $short_map[ $code ] ) ) {
			return $short_map[ $code ];
		}

		if ( 'delivered' === $code ) {
			return Tracking_State::DELIVERED;
		}
		if ( 'created' === $code ) {
			return Tracking_State::CREATED;
		}
		if ( in_array( $code, array( 'in_pickup_courier', 'in_pickup_office' ), true ) ) {
			return Tracking_State::ACCEPTED;
		}
		if ( in_array( $code, array( 'sent', 'courier_direction', 'arrival_departure_from_hub', 'office' ), true ) ) {
			return Tracking_State::IN_TRANSIT;
		}
		if ( 'in_delivery_courier' === $code ) {
			return Tracking_State::OUT_FOR_DELIVERY;
		}
		if ( 'in_delivery_office' === $code ) {
			// Only call an office event "ready for pickup" when the shipment itself
			// is destined to an office; otherwise it may just be an intermediate hub.
			return ( false !== strpos( $receiver_type, 'office' ) ) ? Tracking_State::AVAILABLE_FOR_PICKUP : Tracking_State::IN_TRANSIT;
		}
		if ( 'failed_delivery' === $code ) {
			return Tracking_State::DELIVERY_FAILED;
		}
		if ( 'redirect' === $code ) {
			return Tracking_State::REDIRECTED;
		}
		if ( in_array( $code, array( 'return', 'is_returning_to_sender' ), true ) ) {
			return Tracking_State::RETURN_IN_PROGRESS;
		}
		if ( 'returned_to_sender' === $code ) {
			return Tracking_State::RETURNED;
		}
		if ( 'destroy' === $code ) {
			return Tracking_State::EXCEPTION;
		}

		return Tracking_State::UNKNOWN;
	}

	/**
	 * Public tracking page (econt.com/services/track-shipment).
	 *
	 * @param string $number Shipment number.
	 * @return string
	 */
	public function tracking_url( $number ) {
		return 'https://www.econt.com/services/track-shipment/' . rawurlencode( (string) $number );
	}

	/**
	 * Econt provides an official PaymentReport service for COD / money transfer.
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
	 * Econt-specific readable order fields (own custom fields on the order).
	 *
	 * @param \WC_Order                     $order     Order.
	 * @param \BgCommerce3\Support\Selection $selection Selection.
	 * @return array<string,string>
	 */
	public function order_meta_fields( \WC_Order $order, $selection ) {
		$dt          = $selection->delivery_type;
		$shipping_to = ( 'locker' === $dt ) ? 'APS' : ( ( 'address' === $dt ) ? 'ADDRESS' : 'OFFICE' );

		$fields = array(
			'bgcs3_econt_shipping_to' => $shipping_to,
			'bgcs3_econt_city'        => isset( $selection->city['name'] ) ? (string) $selection->city['name'] : '',
			'bgcs3_econt_post_code'   => isset( $selection->city['post_code'] ) ? (string) $selection->city['post_code'] : '',
		);

		if ( in_array( $dt, array( 'office', 'locker' ), true ) ) {
			$fields['bgcs3_econt_office_code'] = isset( $selection->office['id'] ) ? (string) $selection->office['id'] : '';
			$fields['bgcs3_econt_office_name'] = isset( $selection->office['text'] ) ? (string) $selection->office['text'] : '';
		} else {
			$fields['bgcs3_econt_street']    = isset( $selection->address['street'] ) ? (string) $selection->address['street'] : '';
			$fields['bgcs3_econt_street_no'] = isset( $selection->address['num'] ) ? (string) $selection->address['num'] : '';
		}

		$total = \BgCommerce3\Shipping\Order_Persistence::courier_shipping_total( $order, self::ID );
		if ( $total > 0 ) {
			$fields['bgcs3_econt_price'] = number_format( $total, 2, '.', '' );
		}

		return $fields;
	}

	/** Render the standalone Econt courier pickup request in the Methods workspace. */
	public function render_methods_custom() {
		$active = $this->stored_courier_request();
		$error  = trim( (string) bgcs3_get_option( self::ID, 'courier_request_error', '' ) );

		echo '<section class="bgcs-card bgcs-card--standalone bgcs-econt-courier">';
		echo '<div class="bgcs-card__head"><span class="bgcs-card__icon">' . Icons::svg( 'truck', 20 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="bgcs-card__titles"><span class="bgcs-card__title">' . esc_html__( 'Standalone courier pickup request', 'bg-commerce-suite' ) . '</span>';
		echo '<span class="bgcs-card__desc">' . esc_html__( 'Use Econt ShipmentService.requestCourier to ask a courier to collect prepared shipments from the selected sender address.', 'bg-commerce-suite' ) . '</span></span></div>';
		echo '<div class="bgcs-card__body">';

		if ( '' !== $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( 'address' !== (string) Module_Settings::get( self::ID, 'sender_handover' ) ) {
			echo '<p>' . esc_html__( 'Standalone courier pickup is available when the sender handover method is “Courier pickup from sender address”. Change it in the Sender section first.', 'bg-commerce-suite' ) . '</p>';
			echo '</div></section>';
			return;
		}

		$status_code = isset( $active['status'] ) ? (string) $active['status'] : '';
		$is_active   = Pickup_Request::is_active( $active );
		if ( ! empty( $active['id'] ) ) {
			echo '<p><strong>' . esc_html__( 'Last courier request', 'bg-commerce-suite' ) . ':</strong> #' . esc_html( $active['id'] ) . ' — ' . esc_html( $this->courier_request_status_label( $status_code ) ) . '</p>';
			echo '<p>' . esc_html( sprintf( /* translators: %d: shipment count. */ __( 'Attached shipments: %d.', 'bg-commerce-suite' ), count( (array) ( isset( $active['shipments'] ) ? $active['shipments'] : array() ) ) ) ) . '</p>';
			if ( ! empty( $active['updated_at'] ) ) {
				echo '<p><strong>' . esc_html__( 'Last update', 'bg-commerce-suite' ) . ':</strong> ' . esc_html( wp_date( 'd.m.Y H:i', (int) $active['updated_at'] ) ) . '</p>';
			}
			if ( ! empty( $active['note'] ) ) {
				echo '<p>' . esc_html( $active['note'] ) . '</p>';
			}
			if ( ! empty( $active['reject_reason'] ) ) {
				echo '<p>' . esc_html( $active['reject_reason'] ) . '</p>';
			}
			echo '<button type="submit" class="bgcs-btn bgcs-btn--outline bgcs-btn--sm" name="econt_courier_action" value="refresh">' . esc_html__( 'Refresh request status', 'bg-commerce-suite' ) . '</button>';
			if ( $is_active ) {
				echo '<p class="description">' . esc_html__( 'A new request is blocked while the current Econt pickup request is pending or being processed.', 'bg-commerce-suite' ) . '</p>';
				echo '</div></section>';
				return;
			}
		}

		$pending = $this->pending_econt_shipments();
		$today   = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$type    = (string) Module_Settings::get( self::ID, 'shipment_type' );
		$weight  = max( Weight::MIN_KG, (float) Module_Settings::get( self::ID, 'default_weight' ) );

		echo '<div class="bgcs-fieldgrid">';
		$this->render_econt_courier_field( 'date', __( 'Pickup date', 'bg-commerce-suite' ), 'date', $today );
		$this->render_econt_courier_field( 'time_from', __( 'From time', 'bg-commerce-suite' ), 'time', '09:00' );
		$this->render_econt_courier_field( 'time_to', __( 'To time', 'bg-commerce-suite' ), 'time', '17:00' );
		$this->render_econt_courier_field( 'pack_count', __( 'Estimated package count', 'bg-commerce-suite' ), 'number', '1', false, '1', '1' );
		$this->render_econt_courier_field( 'weight', __( 'Estimated total weight (kg)', 'bg-commerce-suite' ), 'number', (string) $weight, false, '0.01', (string) Weight::MIN_KG );
		echo '<div class="bgcs-field"><label class="bgcs-field__label" for="bgcs-econt-cr-type">' . esc_html__( 'Shipment type', 'bg-commerce-suite' ) . '</label><select id="bgcs-econt-cr-type" name="econt_courier[shipment_type]" class="widefat">';
		foreach ( $this->econt_shipment_type_options() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $type, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></div>';
		echo '</div>';
		echo '<p class="description">';
		/* translators: %d: number of prepared Econt shipments. */
		printf( esc_html__( 'Prepared Econt shipments that will be attached: %d', 'bg-commerce-suite' ), count( $pending ) );
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'The request is sent with the sender profile/address configured above. Econt remains authoritative for pickup availability and may return a delay warning.', 'bg-commerce-suite' ) . '</p>';
		echo '<button type="submit" class="bgcs-btn bgcs-btn--primary bgcs-btn--sm" name="econt_courier_action" value="create">' . esc_html__( 'Request courier', 'bg-commerce-suite' ) . '</button>';
		echo '</div></section>';
	}

	/** One input for the standalone Econt courier-request form. */
	private function render_econt_courier_field( $key, $label, $type, $value, $full = false, $step = '', $min = '' ) {
		$id = 'bgcs-econt-cr-' . sanitize_key( $key );
		echo '<div class="bgcs-field' . ( $full ? ' bgcs-field--full' : '' ) . '"><label class="bgcs-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<input id="' . esc_attr( $id ) . '" class="widefat" type="' . esc_attr( $type ) . '" name="' . esc_attr( 'econt_courier[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '"' . ( '' !== $step ? ' step="' . esc_attr( $step ) . '"' : '' ) . ( '' !== $min ? ' min="' . esc_attr( $min ) . '"' : '' ) . ' /></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Persist/execute standalone courier request actions from the Methods workspace. */
	public function save_settings_custom( $scope = '' ) {
		$scope = sanitize_key( (string) $scope );
		if ( '' !== $scope && 'methods' !== $scope ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verified the settings nonce/capability.
		$action = isset( $_POST['econt_courier_action'] ) ? sanitize_key( wp_unslash( $_POST['econt_courier_action'] ) ) : '';
		if ( 'refresh' === $action ) {
			$this->refresh_econt_courier_request();
			return;
		}
		if ( 'create' !== $action ) {
			return;
		}
		$this->create_econt_courier_request();
	}

	/** Build and send Econt ShipmentService.requestCourier. */
	private function create_econt_courier_request() {
		$owner = Pickup_Request::acquire( self::ID );
		if ( false === $owner ) {
			Options::set( self::ID, 'courier_request_error', __( 'A courier pickup operation is already running. Wait for it to finish before trying again.', 'bg-commerce-suite' ) );
			return;
		}
		try {
			$this->create_econt_courier_request_locked();
		} finally {
			Pickup_Request::release( self::ID, $owner );
		}
	}

	/** Create while holding the courier-wide mutation lock. */
	private function create_econt_courier_request_locked() {
		if ( 'address' !== (string) Module_Settings::get( self::ID, 'sender_handover' ) ) {
			Options::set( self::ID, 'courier_request_error', __( 'Switch the Econt sender handover method to courier pickup from an address before requesting a courier.', 'bg-commerce-suite' ) );
			return;
		}
		$active = $this->stored_courier_request();
		if ( Pickup_Request::is_active( $active ) ) {
			Options::set( self::ID, 'courier_request_error', __( 'There is already an active Econt courier pickup request. Refresh its status before creating another one.', 'bg-commerce-suite' ) );
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by Core.
		$raw = isset( $_POST['econt_courier'] ) && is_array( $_POST['econt_courier'] ) ? map_deep( wp_unslash( $_POST['econt_courier'] ), 'sanitize_text_field' ) : array();
		$form = array();
		foreach ( $raw as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$form[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		$date = isset( $form['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $form['date'] ) ? $form['date'] : '';
		$from = isset( $form['time_from'] ) && preg_match( '/^\d{1,2}:\d{2}$/', $form['time_from'] ) ? $form['time_from'] : '';
		$to   = isset( $form['time_to'] ) && preg_match( '/^\d{1,2}:\d{2}$/', $form['time_to'] ) ? $form['time_to'] : '';
		if ( '' === $date || '' === $from || '' === $to ) {
			Options::set( self::ID, 'courier_request_error', __( 'Enter a valid pickup date and a complete from/to time interval.', 'bg-commerce-suite' ) );
			return;
		}
		$profile_id = trim( (string) bgcs3_get_option( self::ID, 'econt_profile_id', '' ) );
		if ( '' === $profile_id ) {
			Options::set( self::ID, 'courier_request_error', __( 'Select an Econt sender profile before requesting a courier.', 'bg-commerce-suite' ) );
			return;
		}
		$profile = $this->locations()->profile_by_id( $profile_id );
		if ( is_wp_error( $profile ) ) {
			Options::set( self::ID, 'courier_request_error', $profile->get_error_message() );
			return;
		}
		$address_key = trim( (string) Module_Settings::get( self::ID, 'sender_address_key' ) );
		if ( 0 !== strpos( $address_key, $profile_id . ':' ) ) {
			Options::set( self::ID, 'courier_request_error', __( 'The selected Econt pickup address does not belong to the current sender profile. Synchronize data and select the address again.', 'bg-commerce-suite' ) );
			return;
		}
		$address = $this->locations()->sender_address_by_key( $address_key );
		if ( is_wp_error( $address ) ) {
			Options::set( self::ID, 'courier_request_error', $address->get_error_message() );
			return;
		}
		$client = isset( $profile['client'] ) && is_array( $profile['client'] ) ? $profile['client'] : array();
		if ( empty( $client ) ) {
			Options::set( self::ID, 'courier_request_error', __( 'The selected Econt profile did not return sender client data.', 'bg-commerce-suite' ) );
			return;
		}
		$type = isset( $form['shipment_type'] ) ? strtolower( $form['shipment_type'] ) : 'pack';
		if ( ! isset( $this->econt_shipment_type_options()[ $type ] ) ) {
			$type = 'pack';
		}
		$request_from = $this->courier_request_timestamp( $date, $from );
		$request_to   = $this->courier_request_timestamp( $date, $to );
		if ( is_wp_error( $request_from ) || is_wp_error( $request_to ) ) {
			$error = is_wp_error( $request_from ) ? $request_from : $request_to;
			Options::set( self::ID, 'courier_request_error', $error->get_error_message() );
			return;
		}
		if ( $request_to <= $request_from ) {
			Options::set( self::ID, 'courier_request_error', __( 'Econt courier pickup end time must be later than the start time.', 'bg-commerce-suite' ) );
			return;
		}
		$body = array(
			'requestTimeFrom'   => $request_from,
			'requestTimeTo'     => $request_to,
			'shipmentType'      => $type,
			'shipmentPackCount' => max( 1, isset( $form['pack_count'] ) ? (int) $form['pack_count'] : 1 ),
			'shipmentWeight'    => max( Weight::MIN_KG, isset( $form['weight'] ) ? (float) $form['weight'] : Weight::MIN_KG ),
			'senderClient'      => $client,
			'senderAddress'     => $address,
		);
		$agent_name = trim( (string) Module_Settings::get( self::ID, 'sender_name' ) );
		$agent_phone = trim( (string) Module_Settings::get( self::ID, 'sender_phone' ) );
		$agent_email = trim( (string) Module_Settings::get( self::ID, 'sender_email' ) );
		if ( '' !== $agent_name || '' !== $agent_phone || '' !== $agent_email ) {
			$body['senderAgent'] = array_filter( array(
				'name'   => $agent_name,
				'phones' => '' !== $agent_phone ? array( $agent_phone ) : array(),
				'email'  => $agent_email,
			) );
		}
		$orders = $this->pending_econt_shipments();
		if ( empty( $orders ) ) {
			Options::set( self::ID, 'courier_request_error', __( 'Create at least one Econt shipment label before requesting a courier. Every pickup request must be linked to a prepared shipment.', 'bg-commerce-suite' ) );
			return;
		}
		$body['attachShipments'] = array_keys( $orders );
		$response = $this->client()->request_courier( $body );
		if ( is_wp_error( $response ) ) {
			Options::set( self::ID, 'courier_request_error', $response->get_error_message() );
			return;
		}
		$request_id = isset( $response['courierRequestID'] ) ? trim( (string) $response['courierRequestID'] ) : '';
		if ( '' === $request_id ) {
			Options::set( self::ID, 'courier_request_error', __( 'Econt accepted the courier request call but did not return a courierRequestID.', 'bg-commerce-suite' ) );
			return;
		}
		$warnings = array_filter( array(
			isset( $response['warnings'] ) ? (string) $response['warnings'] : '',
			isset( $response['delayedRequestWarning'] ) ? (string) $response['delayedRequestWarning'] : '',
		) );
		$shipments = $this->econt_pickup_shipments( $orders );
		$stored = Pickup_Request::record(
			self::ID,
			$request_id,
			Pickup_Request::PENDING,
			$date,
			$from,
			$to,
			$shipments,
			Pickup_Request::fingerprint( self::ID, $body, $shipments )
		);
		$stored['attachments']   = array_keys( $orders );
		$stored['note']          = implode( ' ', $warnings );
		$stored['reject_reason'] = '';
		Options::set( self::ID, 'courier_request_error', '' );
		Options::set( self::ID, 'courier_request', $stored );
		Pickup_Request::attach_orders( $stored, '_bgcs3_econt_courier_request' );
		foreach ( $orders as $shipment_number => $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( sprintf( /* translators: %s courier request id. */ __( 'Econt: the shipment was included in courier pickup request #%s.', 'bg-commerce-suite' ), $request_id ) );
				$order->save();
			}
		}
	}

	/** Refresh the stored request through ShipmentService.getRequestCourierStatus. */
	private function refresh_econt_courier_request() {
		$owner = Pickup_Request::acquire( self::ID );
		if ( false === $owner ) {
			Options::set( self::ID, 'courier_request_error', __( 'A courier pickup operation is already running. Wait for it to finish before trying again.', 'bg-commerce-suite' ) );
			return;
		}
		try {
			$this->refresh_econt_courier_request_locked();
		} finally {
			Pickup_Request::release( self::ID, $owner );
		}
	}

	/** Refresh while holding the courier-wide mutation lock. */
	private function refresh_econt_courier_request_locked() {
		$active = $this->stored_courier_request();
		if ( empty( $active['id'] ) ) {
			return;
		}
		$response = $this->client()->get_courier_request_status( (string) $active['id'] );
		if ( is_wp_error( $response ) ) {
			Options::set( self::ID, 'courier_request_error', $response->get_error_message() );
			return;
		}
		$row = isset( $response['requestCourierStatus'][0] ) && is_array( $response['requestCourierStatus'][0] ) ? $response['requestCourierStatus'][0] : array();
		if ( ! empty( $row['error'] ) ) {
			$message = is_array( $row['error'] ) && isset( $row['error']['message'] ) ? (string) $row['error']['message'] : __( 'Econt returned an error while reading the courier request status.', 'bg-commerce-suite' );
			Options::set( self::ID, 'courier_request_error', $message );
			return;
		}
		$status = isset( $row['status'] ) && is_array( $row['status'] ) ? $row['status'] : array();
		$code = isset( $status['status'] ) ? strtolower( trim( (string) $status['status'] ) ) : '';
		if ( '' === $code ) {
			Options::set( self::ID, 'courier_request_error', __( 'Econt did not return a courier request status.', 'bg-commerce-suite' ) );
			return;
		}
		$active['provider_status'] = $code;
		$active['status']        = Pickup_Request::status( $code );
		$active['status_code']   = $active['status'];
		$active['note']          = isset( $status['note'] ) ? (string) $status['note'] : '';
		$active['reject_reason'] = isset( $status['reject_reason'] ) ? (string) $status['reject_reason'] : '';
		$active['checked_at']    = time();
		$active['updated_at']    = $active['checked_at'];
		Options::set( self::ID, 'courier_request_error', '' );
		Options::set( self::ID, 'courier_request', $active );
		Pickup_Request::update_orders( $active );

		if ( in_array( $code, array( 'reject', 'reject_client' ), true ) ) {
			foreach ( (array) $active['shipments'] as $shipment ) {
				$order = ! empty( $shipment['order_id'] ) ? wc_get_order( (int) $shipment['order_id'] ) : false;
				if ( $order ) {
					$order->add_order_note( sprintf( /* translators: %s courier request status. */ __( 'Econt courier pickup request ended with status: %s.', 'bg-commerce-suite' ), $this->courier_request_status_label( $active['status'] ) ) );
					$order->save();
				}
			}
			Pickup_Request::detach_orders( $active, '_bgcs3_econt_courier_request' );
		}
	}

	/** @return array<string,mixed> */
	private function stored_courier_request() {
		$value = bgcs3_get_option( self::ID, 'courier_request', array() );
		$value = is_array( $value ) ? Pickup_Request::normalize( $value, self::ID ) : array();
		if ( ! empty( $value['shipments'] ) ) {
			foreach ( $value['shipments'] as &$shipment ) {
				if ( empty( $shipment['order_id'] ) && ! empty( $shipment['waybill'] ) ) {
					$shipment['order_id'] = $this->order_id_by_econt_shipment( (string) $shipment['waybill'] );
				}
			}
			unset( $shipment );
		}
		return $value;
	}

	/** Human label for Econt's documented courier-request status enum. */
	private function courier_request_status_label( $status ) {
		$map = array(
			Pickup_Request::PENDING    => __( 'Pending', 'bg-commerce-suite' ),
			Pickup_Request::PROCESSING => __( 'Processing', 'bg-commerce-suite' ),
			Pickup_Request::COLLECTED  => __( 'Collected', 'bg-commerce-suite' ),
			Pickup_Request::REJECTED   => __( 'Rejected', 'bg-commerce-suite' ),
			Pickup_Request::CANCELLED  => __( 'Cancelled', 'bg-commerce-suite' ),
		);
		$status = strtolower( trim( (string) $status ) );
		return isset( $map[ $status ] ) ? $map[ $status ] : ( '' !== $status ? $status : __( 'Unknown', 'bg-commerce-suite' ) );
	}

	/** Shipment types suitable for ordinary WooCommerce goods. */
	private function econt_shipment_type_options() {
		return array(
			'document'       => __( 'Document', 'bg-commerce-suite' ),
			'pack'           => __( 'Parcel / pack', 'bg-commerce-suite' ),
			'pallet'         => __( 'Pallet', 'bg-commerce-suite' ),
			'cargo'          => __( 'Cargo', 'bg-commerce-suite' ),
			'documentpallet' => __( 'Document pallet', 'bg-commerce-suite' ),
			'big_letter'     => __( 'Big letter', 'bg-commerce-suite' ),
			'small_letter'   => __( 'Small letter', 'bg-commerce-suite' ),
		);
	}

	/** Econt labels waiting to be assigned to a standalone pickup request. @return array<string,int> shipment => order id */
	private function pending_econt_shipments() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders( array( 'limit' => 200, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects', 'meta_key' => '_bgcs3_label', 'meta_compare' => 'EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$found = array();
		foreach ( (array) $orders as $order ) {
			if ( ! is_a( $order, 'WC_Order' ) || '' !== (string) $order->get_meta( '_bgcs3_econt_courier_request' ) || is_array( $order->get_meta( Pickup_Request::META_KEY ) ) ) {
				continue;
			}
			$label = $order->get_meta( '_bgcs3_label' );
			if ( ! is_array( $label ) || self::ID !== ( isset( $label['courier'] ) ? (string) $label['courier'] : '' ) || empty( $label['number'] ) ) {
				continue;
			}
			if ( ! empty( $label['meta']['courier_request_id'] ) ) {
				continue;
			}
			$found[ (string) $label['number'] ] = (int) $order->get_id();
			if ( count( $found ) >= 100 ) {
				break;
			}
		}
		return $found;
	}

	/** @return array<int,array<string,mixed>> */
	private function econt_pickup_shipments( array $orders ) {
		$shipments = array();
		foreach ( $orders as $waybill => $order_id ) {
			$order = wc_get_order( $order_id );
			$label = $order ? $order->get_meta( '_bgcs3_label' ) : array();
			$shipments[] = array(
				'order_id'           => (int) $order_id,
				'waybill'            => (string) $waybill,
				'shipment_reference' => is_array( $label ) && ! empty( $label['meta']['shipment_reference'] ) ? (string) $label['meta']['shipment_reference'] : '',
			);
		}
		return Pickup_Request::shipments( $shipments );
	}

	/** Find an Econt order by the stored label number. */
	private function order_id_by_econt_shipment( $shipment_number ) {
		foreach ( $this->pending_econt_shipments() as $number => $order_id ) {
			if ( (string) $number === (string) $shipment_number ) {
				return $order_id;
			}
		}
		// Rejected requests are marked on the order, so pending_econt_shipments()
		// skips them; scan recent Econt labels once more without that marker gate.
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$orders = wc_get_orders( array( 'limit' => 200, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects', 'meta_key' => '_bgcs3_label', 'meta_compare' => 'EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		foreach ( (array) $orders as $order ) {
			$label = is_a( $order, 'WC_Order' ) ? $order->get_meta( '_bgcs3_label' ) : array();
			if ( is_array( $label ) && self::ID === ( isset( $label['courier'] ) ? (string) $label['courier'] : '' ) && (string) $shipment_number === (string) ( isset( $label['number'] ) ? $label['number'] : '' ) ) {
				return (int) $order->get_id();
			}
		}
		return 0;
	}

	/**
	 * Logical grouping of settings_fields() into cards (presentation only).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings_sections() {
		return array(
			array(
				'title'  => __( 'API access', 'bg-commerce-suite' ),
				'desc'   => __( 'Environment and login credentials.', 'bg-commerce-suite' ),
				'icon'   => 'plug',
				'fields' => array( 'env', 'user', 'password' ),
			),
			array(
				'title'  => __( 'Sender', 'bg-commerce-suite' ),
				'desc'   => __( 'Sender details and handover point: Econt office or courier pickup address.', 'bg-commerce-suite' ),
				'icon'   => 'user',
				'fields' => array( 'econt_profile_id', 'sender_company', 'sender_name', 'sender_phone', 'sender_email', 'sender_handover', 'sender_address_key', 'sender_office_code' ),
			),
			array(
				'title'  => __( 'Payment and cash on delivery', 'bg-commerce-suite' ),
				'desc'   => __( 'COD agreement, payout and sender payment method.', 'bg-commerce-suite' ),
				'icon'   => 'credit-card',
				'fields' => array( 'cd_enabled', 'cd_pay_options', 'payment_type', 'invoice_before_payment', 'pay_after' ),
			),
			array(
				'title'  => __( 'Additional services', 'bg-commerce-suite' ),
				'desc'   => __( 'SMS, declared value, courier pickup request and instructions.', 'bg-commerce-suite' ),
				'icon'   => 'sliders',
				'fields' => array( 'shipment_type', 'sms_notification', 'email_on_delivery', 'declared_value', 'delivery_receipt', 'digital_receipt', 'goods_receipt', 'two_way_shipment', 'delivery_to_floor', 'econt_pack5', 'econt_pack6', 'econt_pack8', 'econt_pack9', 'econt_pack10', 'econt_pack12', 'econt_refrigerated_pack', 'only_courier_request', 'courier_request_time_from', 'courier_request_time_to', 'instructions_take', 'instructions_give', 'instructions_return', 'keep_upright', 'partial_delivery', 'priority_time_from', 'priority_time_to' ),
			),
			array(
				'title'  => __( 'Dimensions and weight', 'bg-commerce-suite' ),
				'desc'   => __( 'Default shipment values.', 'bg-commerce-suite' ),
				'icon'   => 'ruler',
				'fields' => array( 'default_weight', 'default_length', 'default_width', 'default_height' ),
			),
			array(
				'title'  => __( 'Checkout', 'bg-commerce-suite' ),
				'desc'   => __( 'Delivery types and form behavior.', 'bg-commerce-suite' ),
				'icon'   => 'settings',
				'fields' => array( 'show_office', 'show_locker', 'show_address' ),
			),
		);
	}
}
