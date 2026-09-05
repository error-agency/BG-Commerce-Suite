<?php
/**
 * BoxNow courier module implementation.
 * Handles BoxNow locker settings, shipping rates, and waybill printing.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\BoxNow;

use BgCommerce3\Admin\Icons;
use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Abstract_Courier;
use BgCommerce3\Shipping\Hooks as Shipping_Hooks;
use BgCommerce3\Shipping\Cod;
use BgCommerce3\Shipping\Overrides;
use BgCommerce3\Shipping\Office_Store;
use BgCommerce3\Shipping\Package_Dimensions;
use BgCommerce3\Shipping\Pricing;
use BgCommerce3\Shipping\Setup_Status;
use BgCommerce3\Shipping\Shipment_Reference;
use BgCommerce3\Shipping\Shipment_Creation;
use BgCommerce3\Shipping\Tracking_State;
use BgCommerce3\Shipping\Tracking_Store;
use BgCommerce3\Shipping\Weight;
use BgCommerce3\Support\Cache;
use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Price_Result;
use BgCommerce3\Support\Shipping_Availability;
use BgCommerce3\Support\Label_Result;
use BgCommerce3\Support\Tracking_Result;
use BgCommerce3\Support\Label_Pdf_Store;
use BgCommerce3\Support\Sync_Result;
use BgCommerce3\Support\Options;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

class BoxNow extends Abstract_Courier {

	const ID = 'boxnow';

	/**
	 * Order meta holding the fingerprints of parcel events already handled
	 * (BGCS-AUDIT-007).
	 */
	const WEBHOOK_SEEN_META = '_bgcs3_boxnow_webhook_seen';

	/** How many event fingerprints to remember per order. Never unbounded. */
	const WEBHOOK_SEEN_LIMIT = 50;

	/** Seven days — see {@see webhook_max_age()} for why that number. */
	const WEBHOOK_MAX_AGE = 604800;

	/**
	 * Largest cash-on-delivery amount BOX NOW accepts (BGCS-AUDIT-012).
	 *
	 * Partner API v1.69: „`P408` — Invalid amountToBeCollected amount. Make sure
	 * you are sending amount in the valid range of (0, 5000>“. The number used to
	 * exist only inside that error's translation, which meant it was known but
	 * only ever used to explain a rejection after the customer had already
	 * checked out. One source now, for both the gate and the message.
	 */
	const COD_MAX = 5000.0;

	/** @var Client|null */
	private $client;

	/** @var Locations|null */
	private $locations;

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
		return __( 'BoxNow', 'bg-commerce-suite' );
	}

	/**
	 * BOX NOW owns its pricing UI through the weight-range table. Core generic
	 * own-price rules are intentionally disabled so merchants have one pricing
	 * source only (free shipping threshold still remains a Core feature).
	 *
	 * @return bool
	 */
	public function uses_core_pricing_rules() {
		return false;
	}

	public function pricing_section_description() {
		return __( 'Free shipping takes priority. After that, BOX NOW weight ranges are used.', 'bg-commerce-suite' );
	}

	/**
	 * Compartment sizes BOX NOW accepts in `items[].compartmentSize`.
	 *
	 * Filterable so a contract with additional sizes does not need a plugin
	 * release; the values stay the API's own integers.
	 *
	 * @return array<string,string>
	 */

	public function compartment_sizes() {
		return (array) apply_filters(
			'bgcs3_boxnow_compartment_sizes',
			array(
				'1' => __( 'Small', 'bg-commerce-suite' ),
				'2' => __( 'Medium', 'bg-commerce-suite' ),
				'3' => __( 'Large', 'bg-commerce-suite' ),
			)
		);
	}

	/**
	 * BOX NOW's `items[]` is a list of parcels, each with its own compartment
	 * size, weight and value — so one order can occupy several lockers.
	 *
	 * @return bool
	 */
	public function supports_multi_pack() {
		return true;
	}

	/**
	 * Columns of one parcel row. BOX NOW bills on a compartment size, not on
	 * centimetres, so Core's default length/width/height columns would be four
	 * inputs the API has nowhere to put.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function pack_columns() {
		return array(
			'compartment_size' => array(
				'type'    => 'select',
				'label'   => __( 'Compartment size', 'bg-commerce-suite' ),
				'options' => $this->compartment_sizes(),
			),
			'weight'           => array(
				'type'        => 'number',
				'label'       => __( 'Weight (kg)', 'bg-commerce-suite' ),
				'step'        => '0.001',
				'min'         => '0',
				'placeholder' => __( 'auto', 'bg-commerce-suite' ),
			),
			'value'            => array(
				'type'        => 'number',
				'label'       => __( 'Value', 'bg-commerce-suite' ),
				'step'        => '0.01',
				'min'         => '0',
				'placeholder' => __( 'auto', 'bg-commerce-suite' ),
			),
		);
	}

	/**
	 * Core waybill fields BOX NOW's API has no counterpart for. Rendering them
	 * would invite the merchant to fill in a value that is then discarded.
	 *
	 * `dimensions`/`fragile`/`payer`/`obp` simply do not exist in a locker
	 * delivery request; `ref2` does not either — BOX NOW's own free reference is
	 * `additionalInformation`, declared below as a courier field.
	 *
	 * @return string[]
	 */
	public function hidden_waybill_fields() {
		// BOX NOW has no separate declared-value/insurance field. `invoiceValue`
		// is the commercial value of the order and must not be presented as a
		// merchant-editable "Declared value" override.
		return array( 'dimensions', 'declared_value', 'fragile', 'payer', 'obp', 'ref2' );
	}

	/**
	 * BGCS-AUDIT-004/-006 — BOX NOW payment semantics for the order snapshot.
	 *
	 * A locker delivery request has **no courier-service payer field**, which is
	 * why `payer` is listed in {@see hidden_waybill_fields()} above. The empty
	 * string here therefore means "this courier has no such concept", not "not
	 * known yet" — and it is deliberately returned rather than omitted, so Core
	 * clears any stale value instead of leaving one that would imply BOX NOW was
	 * told who pays.
	 *
	 * The COD figures match `delivery_request_body()`: the shared resolver and
	 * the order currency, with no courier-side adjustment.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return array<string,mixed>
	 */
	public function label_snapshot_financials( \WC_Order $order, array $wb ) {
		return array(
			'payer'        => '',
			'cod_amount'   => Cod::resolve_amount( $order, $wb ),
			'cod_currency' => strtoupper( (string) $order->get_currency() ),
		);
	}

	/**
	 * Per-order BOX NOW fields, stored by Core under `_bgcs3_wb['x']`.
	 *
	 * Every one of these is an optional property of `POST
	 * /api/v1/delivery-requests` (partner API v1.69). A blank value means „as in
	 * the settings“, so an untouched panel never changes what is sent.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function waybill_fields() {
		$inherit = array(
			''    => __( 'Use settings', 'bg-commerce-suite' ),
			'yes' => __( 'Yes', 'bg-commerce-suite' ),
			'no'  => __( 'No', 'bg-commerce-suite' ),
		);

		$origins               = $this->locations()->origins();
		$origin_options        = array( '' => __( 'Use settings', 'bg-commerce-suite' ) ) + (array) $origins;
		$return_origin_options = array(
			''  => __( 'Use settings', 'bg-commerce-suite' ),
			'0' => __( 'BOX NOW contract default (no return location override)', 'bg-commerce-suite' ),
		) + (array) $origins;

		return array(
			'origin_id'              => array(
				'group'       => 'packages',
				'type'        => 'select',
				'label'       => __( 'Sender warehouse', 'bg-commerce-suite' ),
				'options'     => $origin_options,
				'description' => __( 'Where BOX NOW should collect the shipment for this order.', 'bg-commerce-suite' ),
			),
			'sender_contact_name'    => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender contact name', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = warehouse/settings', 'bg-commerce-suite' ),
				'description' => __( 'Overrides the sender contact for this order only.', 'bg-commerce-suite' ),
			),
			'sender_contact_phone'   => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender contact phone', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = warehouse/settings', 'bg-commerce-suite' ),
			),
			'sender_contact_email'   => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender contact email', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = warehouse/settings', 'bg-commerce-suite' ),
			),
			'type_of_service'        => array(
				'group'       => 'services',
				'type'        => 'select',
				'label'       => __( 'Service type', 'bg-commerce-suite' ),
				'options'     => array(
					''          => __( 'Use settings', 'bg-commerce-suite' ),
					'0'         => __( 'BOX NOW default (omit typeOfService)', 'bg-commerce-suite' ),
					'same-day'  => __( 'Same day', 'bg-commerce-suite' ),
					'next-day'  => __( 'Next day', 'bg-commerce-suite' ),
				),
				'description' => __( 'You may not be approved for all types — this is confirmed by BOX NOW. “BOX NOW default” suppresses a shop-wide type for this order only.', 'bg-commerce-suite' ),
			),
			'allow_return'           => array(
				'group'       => 'services',
				'type'        => 'select',
				'label'       => __( 'Allow return', 'bg-commerce-suite' ),
				'options'     => $inherit,
				'description' => __( 'The customer can return the shipment using the same method by which it was received.', 'bg-commerce-suite' ),
			),
			'show_recipient_info'    => array(
				'group'       => 'services',
				'type'        => 'select',
				'label'       => __( 'Recipient details on the label', 'bg-commerce-suite' ),
				'options'     => $inherit,
				'description' => __( 'Prints the recipient phone and email on the label.', 'bg-commerce-suite' ),
			),
			'return_location_id'     => array(
				'group'       => 'services',
				'type'        => 'select',
				'label'       => __( 'Return warehouse', 'bg-commerce-suite' ),
				'options'     => $return_origin_options,
				'description' => __( 'Where the shipment should be returned if it is not collected. Choose the BOX NOW contract default to suppress a shop-wide return-location override for this order only.', 'bg-commerce-suite' ),
			),
			'notify_email'           => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Voucher email', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings; 0 = disable', 'bg-commerce-suite' ),
			),
			'notify_sms'             => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'SMS on acceptance', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings; 0 = disable', 'bg-commerce-suite' ),
			),
			'additional_information' => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Additional information', 'bg-commerce-suite' ),
				'full'        => true,
				'description' => __( 'Free text that BOX NOW returns through the webhook.', 'bg-commerce-suite' ),
			),
			'label_row1'             => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Label, line 1', 'bg-commerce-suite' ),
				'placeholder' => __( 'up to 20 characters; 0 = blank', 'bg-commerce-suite' ),
			),
			'label_row2'             => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Label, line 2', 'bg-commerce-suite' ),
				'placeholder' => __( 'up to 20 characters; 0 = blank', 'bg-commerce-suite' ),
			),
			'label_row3'             => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Label, line 3', 'bg-commerce-suite' ),
				'placeholder' => __( 'up to 20 characters; 0 = blank', 'bg-commerce-suite' ),
			),
			'label_row4'             => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Label, line 4', 'bg-commerce-suite' ),
				'placeholder' => __( 'up to 20 characters; 0 = blank', 'bg-commerce-suite' ),
			),
		);
	}

	/**
	 * BOX NOW's documented `400 Bad Request` partner codes, in Bulgarian.
	 *
	 * The API answers a rejected request with `{"code":"P411","status":400}` and
	 * nothing else, which reached the merchant verbatim: „Грешка от API на
	 * куриера (HTTP 400). {"code":"P411","status":400}“. That is unactionable —
	 * P411 means the account is not approved for cash on delivery, which the
	 * merchant can fix in one phone call once they know it (observed live on
	 * stage, 2026-08-13). Codes are from the partner API v1.69 error table.
	 *
	 * @return array<string,string>
	 */
	private function error_messages() {
		return array(
			'P400' => __( 'Invalid data in the BOX NOW request.', 'bg-commerce-suite' ),
			'P401' => __( 'Invalid sender warehouse. Select a warehouse from the list in BOX NOW settings.', 'bg-commerce-suite' ),
			'P402' => __( 'Invalid recipient locker. Select a locker from the current list and save the order again.', 'bg-commerce-suite' ),
			'P403' => __( 'Your account is not allowed to use locker-to-locker delivery. Contact BOX NOW.', 'bg-commerce-suite' ),
			'P405' => __( 'Invalid phone number. It must be in international format, for example +359888123456.', 'bg-commerce-suite' ),
			'C404' => __( 'Invalid phone number. It must be in international format, for example +359888123456.', 'bg-commerce-suite' ),
			'P406' => __( 'Invalid compartment size. Only 1, 2 or 3 are allowed.', 'bg-commerce-suite' ),
			'P407' => __( 'Invalid country code. ISO 3166-1 alpha-2 is expected, for example BG.', 'bg-commerce-suite' ),
			// One source for the limit: this message and the checkout gate that
			// should have prevented ever reaching it read the same constant.
			'P408' => sprintf(
				/* translators: %s: the BOX NOW cash-on-delivery limit. */
				__( 'Invalid cash-on-delivery amount. The allowed range is greater than 0 and up to %s.', 'bg-commerce-suite' ),
				$this->format_amount( self::COD_MAX )
			),
			'P409' => __( 'Invalid delivery partner.', 'bg-commerce-suite' ),
			'P410' => __( 'A shipment with this order number already exists in BOX NOW. Check whether the shipment label has already been generated.', 'bg-commerce-suite' ),
			'P411' => __( 'Your BOX NOW account is not allowed to use cash on delivery. Use a prepaid order or ask BOX NOW to enable COD.', 'bg-commerce-suite' ),
			'P412' => __( 'Your account is not allowed to create customer returns. Contact BOX NOW.', 'bg-commerce-suite' ),
			'P413' => __( 'Invalid return warehouse. Select a warehouse from the list in settings.', 'bg-commerce-suite' ),
			'P415' => __( 'Your account is not allowed to use address delivery. Contact BOX NOW.', 'bg-commerce-suite' ),
			'P416' => __( 'Your account is not allowed to use COD for address delivery. Contact BOX NOW.', 'bg-commerce-suite' ),
			'P417' => __( 'Free-text search is not allowed for this account type.', 'bg-commerce-suite' ),
			'P420' => __( 'The shipment cannot be cancelled in its current state. Cancellation is possible only before delivery/return.', 'bg-commerce-suite' ),
			'P421' => __( 'Invalid shipment weight.', 'bg-commerce-suite' ),
			'P422' => __( 'The address was not found by BOX NOW.', 'bg-commerce-suite' ),
			'P423' => __( 'No nearby locker was found.', 'bg-commerce-suite' ),
			'P424' => __( 'Invalid locale format. A value such as bg-BG is expected.', 'bg-commerce-suite' ),
			'P425' => __( 'The shipment is not in a state that allows a return to be declared.', 'bg-commerce-suite' ),
			'P426' => __( 'The shipment does not use a delivery partner and is not eligible for this type of return.', 'bg-commerce-suite' ),
			'P466' => __( 'The request was rejected by BOX NOW for this account.', 'bg-commerce-suite' ),
			'X403' => __( 'This BOX NOW resource is not accessible. Check the Partner ID and account permissions.', 'bg-commerce-suite' ),
		);
	}

	/**
	 * Turns a BOX NOW API failure into a sentence the merchant can act on.
	 *
	 * @param \WP_Error $error Error from the client.
	 * @return string
	 */
	private function explain_error( $error ) {
		$raw = $error->get_error_message();

		if ( ! preg_match( '/"code"\s*:\s*"([A-Z]\d{3})"/', $raw, $match ) ) {
			return $raw;
		}

		$code     = $match[1];
		$messages = $this->error_messages();

		if ( ! isset( $messages[ $code ] ) ) {
			return $raw;
		}

		/* translators: 1: Bulgarian explanation, 2: BOX NOW error code. */
		return sprintf( __( '%1$s (BOX NOW code %2$s)', 'bg-commerce-suite' ), $messages[ $code ], $code );
	}

	/**
	 * The pickup contact BOX NOW should call for a given warehouse.
	 *
	 * `/origins` carries no contact details, so this is the merchant's own: a
	 * per-warehouse row when there is one, otherwise the shop-wide sender. Each
	 * field falls back on its own, so a row that fills in only the phone keeps
	 * the shop's name and e-mail.
	 *
	 * @param string $warehouse_id Origin id used for this shipment.
	 * @return array<string,string>
	 */
	private function sender_contact( $warehouse_id ) {
		$rows = Warehouses::sanitize_rows( bgcs3_get_option( self::ID, 'warehouse_contacts', array() ) );

		return Warehouses::contact_for(
			$warehouse_id,
			$rows,
			array(
				'name'  => (string) Module_Settings::get( self::ID, 'sender_name' ),
				'phone' => (string) Module_Settings::get( self::ID, 'sender_phone' ),
				'email' => (string) Module_Settings::get( self::ID, 'sender_email' ),
			)
		);
	}

	/**
	 * Resolve the warehouse/global sender contact and then apply order-only
	 * overrides. These values are never written back to module settings.
	 *
	 * @param string              $warehouse_id Origin id.
	 * @param array<string,mixed> $wb           Waybill overrides.
	 * @return array<string,string>
	 */
	private function sender_contact_for_order( $warehouse_id, array $wb ) {
		$sender = $this->sender_contact( $warehouse_id );
		$map = array(
			'name'  => 'sender_contact_name',
			'phone' => 'sender_contact_phone',
			'email' => 'sender_contact_email',
		);
		foreach ( $map as $target => $key ) {
			$value = $this->wbx( $wb, $key );
			if ( '' !== $value ) {
				$sender[ $target ] = $value;
			}
		}
		return $sender;
	}

	/**
	 * Raw per-order courier field (`_bgcs3_wb['x'][$key]`), '' when unset.
	 *
	 * @param array<string,mixed> $wb  Waybill overrides.
	 * @param string              $key Field key.
	 * @return string
	 */
	private function wbx( array $wb, $key ) {
		return ( isset( $wb['x'][ $key ] ) && is_scalar( $wb['x'][ $key ] ) ) ? trim( (string) $wb['x'][ $key ] ) : '';
	}

	/**
	 * Tri-state yes/no courier field resolved against a setting.
	 *
	 * @param array<string,mixed> $wb          Waybill overrides.
	 * @param string              $key         Field key.
	 * @param string              $setting_key Setting to fall back to.
	 * @param string              $setting_default Setting default.
	 * @return bool
	 */
	private function wbx_bool( array $wb, $key, $setting_key, $setting_default = 'no' ) {
		$value = $this->wbx( $wb, $key );

		if ( 'yes' === $value ) {
			return true;
		}
		if ( 'no' === $value ) {
			return false;
		}

		return 'yes' === (string) bgcs3_get_option( self::ID, $setting_key, $setting_default );
	}

	/**
	 * BoxNow only supports locker delivery.
	 *
	 * @return string[]
	 */
	public function supported_delivery_types() {
		return array( 'locker' );
	}

	public function delivery_types() {
		if ( 'no' === Module_Settings::get( self::ID, 'show_locker' ) ) {
			return array();
		}
		return array( 'locker' );
	}

	/**
	 * @return Client
	 */
	public function client() {
		if ( null === $this->client ) {
			$this->client = new Client();
		}
		return $this->client;
	}

	/** Whether the saved OAuth credentials are complete. */
	public function has_credentials() {
		return $this->client()->has_credentials();
	}

	/**
	 * Non-secret fingerprint of the OAuth account context used to detect when
	 * cached partner/origin data belongs to previously saved credentials.
	 *
	 * @return string
	 */
	private function account_config_fingerprint() {
		$env       = (string) Module_Settings::get( self::ID, 'env' );
		$client_id = (string) Module_Settings::get( self::ID, 'client_id' );
		$secret    = (string) Module_Settings::get( self::ID, 'client_secret' );
		return hash( 'sha256', $env . '|' . $client_id . '|' . hash( 'sha256', $secret ) );
	}

	/**
	 * Validate BOX NOW credentials and discover the partner account(s) the
	 * credentials can manage. This is an explicit admin action; passive page
	 * rendering never performs the provider request.
	 *
	 * @return Sync_Result
	 */
	public function check_connection() {
		if ( ! $this->client()->has_credentials() ) {
			Options::set( self::ID, '_api_health', array( 'ok' => false, 'at' => time() ) );
			return Sync_Result::error( __( 'Enter the Client ID and Client Secret.', 'bg-commerce-suite' ) );
		}

		$partners = $this->client()->get_entrusted_partners();
		if ( is_wp_error( $partners ) ) {
			Options::set( self::ID, '_api_health', array( 'ok' => false, 'at' => time() ) );
			return Sync_Result::error( __( 'BOX NOW did not accept the API credentials or the profile could not be read.', 'bg-commerce-suite' ), array( $partners->get_error_message() ) );
		}

		$normalized = array();
		foreach ( $partners as $partner ) {
			$id = isset( $partner['id'] ) ? trim( (string) $partner['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}
			$normalized[ $id ] = array(
				'id'               => $id,
				'display_name'     => sanitize_text_field( isset( $partner['displayName'] ) ? (string) $partner['displayName'] : '' ),
				'email'            => sanitize_email( isset( $partner['email'] ) ? (string) $partner['email'] : '' ),
				'phone'            => sanitize_text_field( isset( $partner['phoneNumber'] ) ? (string) $partner['phoneNumber'] : '' ),
				'currency'         => sanitize_text_field( isset( $partner['assignedCurrency'] ) ? (string) $partner['assignedCurrency'] : '' ),
				'region'           => sanitize_text_field( isset( $partner['region'] ) ? (string) $partner['region'] : '' ),
				// BGCS-AUDIT-012 — `permission` is an OBJECT of booleans in the
				// contract (`PartnerPermission`: codPayment, addressAsDestination,
				// warehouseAsOrigin, …), not a scalar. Casting it through
				// is_scalar() discarded the whole thing, which is why the live
				// audit found this field empty and concluded the account
				// entitlement was unavailable locally. It was being thrown away.
				'permission'       => self::normalize_permissions( isset( $partner['permission'] ) ? $partner['permission'] : null ),
				'shipping_regions' => isset( $partner['shippingRegions'] ) && is_array( $partner['shippingRegions'] ) ? array_values( array_map( 'sanitize_text_field', $partner['shippingRegions'] ) ) : array(),
			);
		}

		Options::set( self::ID, '_partners', $normalized );
		Options::set( self::ID, '_api_health', array( 'ok' => true, 'at' => time() ) );
		Options::set( self::ID, '_checked_account_fingerprint', $this->account_config_fingerprint() );

		if ( empty( $normalized ) ) {
			Options::set( self::ID, '_account_profile', array() );
			return Sync_Result::warning( __( 'BOX NOW accepted the API access but did not return a partner profile for management. Check the account permissions and Partner ID.', 'bg-commerce-suite' ) );
		}

		$current = trim( (string) bgcs3_get_option( self::ID, 'partner_id', '' ) );
		if ( '' === $current && 1 === count( $normalized ) ) {
			$current = (string) key( $normalized );
			Options::set( self::ID, 'partner_id', $current );
		}
		if ( '' !== $current && isset( $normalized[ $current ] ) ) {
			Options::set( self::ID, '_account_profile', $normalized[ $current ] );
		} else {
			Options::set( self::ID, '_account_profile', array() );
		}

		return Sync_Result::success(
			__( 'The BOX NOW connection is successful and the partner profile has been updated.', 'bg-commerce-suite' ),
			array( 'partners' => count( $normalized ) )
		);
	}

	/**
	 * Real readiness checks for BOX NOW. The setup assistant is intentionally
	 * based only on locally cached state; opening the admin page never performs
	 * a provider request.
	 *
	 * @return array<int,array{id:string,label:string,state:string,hint:string}>
	 */
	public function setup_status() {
		$rows = array();

		if ( $this->client()->has_credentials() ) {
			$health = (array) bgcs3_get_option( self::ID, '_api_health', array() );
			if ( array_key_exists( 'ok', $health ) && true === $health['ok'] ) {
				$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_OK );
			} elseif ( array_key_exists( 'ok', $health ) && false === $health['ok'] ) {
				$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_FAIL, __( 'The last BOX NOW connection check failed or the credentials have changed.', 'bg-commerce-suite' ) );
			} else {
				$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_WARN, __( 'The OAuth credentials are saved, but the connection has not been checked yet.', 'bg-commerce-suite' ) );
			}
		} else {
			$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_FAIL, __( 'Enter the Client ID and Client Secret.', 'bg-commerce-suite' ) );
		}

		$partner_id = trim( (string) bgcs3_get_option( self::ID, 'partner_id', '' ) );
		$rows[] = Setup_Status::row(
			'partner',
			__( 'Partner profile', 'bg-commerce-suite' ),
			'' !== $partner_id ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL,
			'' !== $partner_id ? '' : __( 'Select a Partner ID after a successful connection check.', 'bg-commerce-suite' )
		);

		$warehouse_id = trim( (string) bgcs3_get_option( self::ID, 'warehouse_id', '' ) );
		$contact = '' !== $warehouse_id ? $this->sender_contact( $warehouse_id ) : array( 'name' => '', 'phone' => '', 'email' => '' );
		$sender_ok = '' !== $warehouse_id && ! empty( $contact['name'] ) && ! empty( $contact['phone'] ) && ! empty( $contact['email'] );
		$rows[] = Setup_Status::row(
			'sender',
			__( 'Sender', 'bg-commerce-suite' ),
			$sender_ok ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL,
			$sender_ok ? '' : __( 'Select a warehouse and enter the sender name, phone and email.', 'bg-commerce-suite' )
		);

		$locations_ok = 'no' === Module_Settings::get( self::ID, 'show_locker' ) || Office_Store::has( self::ID, 'locker' );
		$rows[] = Setup_Status::row(
			'locations',
			__( 'Lockers', 'bg-commerce-suite' ),
			$locations_ok ? Setup_Status::STATE_OK : Setup_Status::STATE_WARN,
			$locations_ok ? '' : __( 'Synchronize the BOX NOW locker list.', 'bg-commerce-suite' )
		);

		$ranges       = Weight_Pricing::sanitize_ranges( bgcs3_get_option( self::ID, 'weight_price_ranges', array() ) );
		$pricing_ok   = ! empty( $ranges );
		$pricing_hint = __( 'Add at least one valid weight-based price range.', 'bg-commerce-suite' );
		$rows[] = Setup_Status::row(
			'pricing',
			__( 'Pricing', 'bg-commerce-suite' ),
			$pricing_ok ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL,
			$pricing_ok ? '' : $pricing_hint
		);

		$delivery_ok = $this->is_enabled() && ! empty( $this->delivery_types() );
		$rows[] = Setup_Status::row(
			'method',
			__( 'Delivery types', 'bg-commerce-suite' ),
			$delivery_ok ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL,
			$delivery_ok ? '' : __( 'Enable BOX NOW and locker delivery.', 'bg-commerce-suite' )
		);

		return $rows;
	}

	/** Normalized cached partner profile for merchant-facing account UI. */
	public function account_profile() {
		$profile = bgcs3_get_option( self::ID, '_account_profile', array() );
		return is_array( $profile ) ? $profile : array();
	}

	/**
	 * The account's `PartnerPermission` flags, as booleans.
	 *
	 * @param mixed $permission Raw `permission` value from the partner payload.
	 * @return array<string,bool>
	 */
	private static function normalize_permissions( $permission ) {
		if ( ! is_array( $permission ) ) {
			return array();
		}

		$flags = array();
		foreach ( $permission as $name => $value ) {
			$name = sanitize_key( (string) $name );
			if ( '' !== $name && is_scalar( $value ) ) {
				$flags[ $name ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			}
		}

		return $flags;
	}

	/**
	 * Whether BOX NOW has told us the account may do something.
	 *
	 * Three-valued on purpose. `null` means "not known" — the profile has never
	 * been synced, or this build of their API did not send the flag — and the
	 * caller must treat that as permission rather than refusal. Blocking a
	 * merchant's checkout because we have not fetched a profile yet would be a
	 * worse defect than the one this guard exists to fix.
	 *
	 * @param string $flag `PartnerPermission` property, e.g. 'codpayment'.
	 * @return bool|null
	 */
	private function account_allows( $flag ) {
		$profile = $this->account_profile();
		$flags   = isset( $profile['permission'] ) && is_array( $profile['permission'] ) ? $profile['permission'] : array();
		$flag    = sanitize_key( (string) $flag );

		return array_key_exists( $flag, $flags ) ? (bool) $flags[ $flag ] : null;
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
	 * Refresh BoxNow reference data: flush caches and persist the locker pool to
	 * the DB store (manual "Синхронизирай" button + the daily cron).
	 *
	 * @return array{success:bool,message:string}
	 */
	public function sync_data() {
		$count   = Cache::flush_courier( $this->id() );
		$lockers = $this->locations()->all_offices( 'locker' );
		$origins = $this->locations()->origins();
		Options::set( self::ID, '_origin_options', is_array( $origins ) ? $origins : array() );
		$stored  = $this->locations()->replace_if_valid();
		$errors  = array();

		if ( is_wp_error( $this->locations()->origins_error() ) ) {
			$errors[] = $this->locations()->origins_error()->get_error_message();
		}

		// Един-единствен склад — избери го автоматично.
		if ( '' === (string) bgcs3_get_option( self::ID, 'warehouse_id', '' ) && 1 === count( $origins ) ) {
			Options::set( self::ID, 'warehouse_id', (string) key( $origins ) );
			Options::set( self::ID, 'warehouse_label', (string) reset( $origins ) );
		}

		// При грешка в записа показваме броя на току-що изтеглените автомати.
		$counts = array( 'cache' => $count, 'lockers' => count( $lockers ), 'origins' => count( $origins ) );

		return $this->sync_result( array( 'lockers' => $stored ), $counts, array(), $errors );
	}

	public function supports_sender_refresh() {
		return true;
	}

	public function sender_refresh_label() {
		return __( 'Update sender details from BOX NOW', 'bg-commerce-suite' );
	}

	public function refresh_sender_data() {
		$warehouse_id = (string) bgcs3_get_option( self::ID, 'warehouse_id', '' );
		$origins      = $this->locations()->origins();
		Options::set( self::ID, '_origin_options', is_array( $origins ) ? $origins : array() );
		if ( is_wp_error( $this->locations()->origins_error() ) ) {
			return Sync_Result::error( $this->locations()->origins_error()->get_error_message(), array(), array( 'sender_name', 'sender_phone', 'sender_email' ) );
		}
		if ( '' === $warehouse_id || ! isset( $origins[ $warehouse_id ] ) ) {
			return Sync_Result::error( __( 'Select a valid sender warehouse.', 'bg-commerce-suite' ) );
		}
		Options::set( self::ID, 'warehouse_label', $origins[ $warehouse_id ] );
		if ( '' === (string) Module_Settings::get( self::ID, 'sender_name' ) ) {
			Options::set( self::ID, 'sender_name', $origins[ $warehouse_id ] );
		}
		return Sync_Result::warning(
			__( 'The warehouse is confirmed. Phone and email remain manual.', 'bg-commerce-suite' ),
			array(),
			array( 'warehouse_label' ),
			array( 'sender_phone', 'sender_email' )
		);
	}

	public function admin_location_search( $resource, array $args ) {
		if ( 'offices' !== $resource ) {
			return parent::admin_location_search( $resource, $args );
		}
		return $this->search_stored_locations( 'locker', isset( $args['query'] ) ? $args['query'] : '' );
	}

	/**
	 * Declare the admin settings tab for BoxNow.
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
	 * Settings fields for the BoxNow module.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function settings_fields() {
		// Sender warehouses come from the authenticated /origins endpoint. When
		// credentials aren't set yet (empty list) fall back to a plain text field
		// so the merchant can save credentials first, then pick from the dropdown.
		// Passive settings rendering must not call BOX NOW. Origin choices are
		// populated only by an explicit sync/refresh action.
		$origins = bgcs3_get_option( self::ID, '_origin_options', array() );
		$origins = is_array( $origins ) ? $origins : array();
		$origins_error = null;
		if ( ! empty( $origins ) ) {
			$warehouse_field = array(
				'type'        => 'select',
				'label'       => __( 'Sender warehouse (origin)', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => array( '' => __( '— Select a warehouse —', 'bg-commerce-suite' ) ) + $origins,
				'description' => __( 'Loaded from BOX NOW (/origins) according to the environment.', 'bg-commerce-suite' ),
				'searchable'  => true,
				'label_key'   => 'warehouse_label',
			);
		} else {
			if ( is_wp_error( $origins_error ) && 'bgcs3_boxnow_empty_origins' === $origins_error->get_error_code() ) {
				$origin_description = __( 'BOX NOW did not return an available sender warehouse for the current environment and Partner ID. Ask BOX NOW to configure an origin for this stage/production account.', 'bg-commerce-suite' );
			} elseif ( is_wp_error( $origins_error ) ) {
				$origin_description = sprintf(
					/* translators: %s BOX NOW API error message. */
					__( 'BOX NOW /origins returned an error: %s', 'bg-commerce-suite' ),
					$origins_error->get_error_message()
				);
			} else {
				$origin_description = __( 'Enter the API credentials and save — a warehouse list will then appear.', 'bg-commerce-suite' );
			}

			$warehouse_field = array(
				'type'        => 'text',
				'label'       => __( 'Warehouse ID (origin)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => $origin_description,
			);
		}

		// Returns go back to a warehouse from the same /origins list; a plain
		// text field until credentials are saved, exactly like the origin above.
		if ( ! empty( $origins ) ) {
			$return_field = array(
				'type'        => 'select',
				'label'       => __( 'Return warehouse (returnLocation)', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => array( '' => __( '— As determined by BOX NOW —', 'bg-commerce-suite' ) ) + $origins,
				'description' => __( 'Where undelivered shipments should be returned. Empty = according to the BOX NOW contract.', 'bg-commerce-suite' ),
				'searchable'  => true,
			);
		} else {
			$return_field = array(
				'type'        => 'text',
				'label'       => __( 'Return warehouse (returnLocation)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Location ID from /origins. It appears as a list after the API credentials are saved.', 'bg-commerce-suite' ),
			);
		}

		$partners = bgcs3_get_option( self::ID, '_partners', array() );
		$partners = is_array( $partners ) ? $partners : array();
		if ( ! empty( $partners ) ) {
			$partner_options = array( '' => __( '— Select a partner —', 'bg-commerce-suite' ) );
			foreach ( $partners as $partner_key => $partner ) {
				$pid = is_array( $partner ) && ! empty( $partner['id'] ) ? (string) $partner['id'] : (string) $partner_key;
				$label = is_array( $partner ) && ! empty( $partner['display_name'] ) ? (string) $partner['display_name'] : $pid;
				$partner_options[ $pid ] = $label;
			}
			$partner_field = array(
				'type' => 'select',
				'label' => __( 'Partner', 'bg-commerce-suite' ),
				'default' => '',
				'options' => $partner_options,
				'description' => __( 'Partner profile returned by BOX NOW for the current OAuth credentials.', 'bg-commerce-suite' ),
			);
		} else {
			$partner_field = array(
				'type' => 'text',
				'label' => __( 'Partner ID', 'bg-commerce-suite' ),
				'default' => '',
				'description' => __( 'Save the API credentials and click “Check connection” to load the profiles you can manage.', 'bg-commerce-suite' ),
			);
		}

		return array(
			// -- Среда & автентикация -----------------------------------------
			'env'            => array(
				'type'    => 'select',
				'label'   => __( 'Environment', 'bg-commerce-suite' ),
				'default' => 'demo',
				'options' => array(
					'demo' => __( 'Test (stage)', 'bg-commerce-suite' ),
					'live' => __( 'Production', 'bg-commerce-suite' ),
				),
			),
			'client_id'      => array(
				'type'        => 'text',
				'label'       => __( 'Client ID', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'OAuth2 Client ID provided by BOX NOW.', 'bg-commerce-suite' ),
			),
			'client_secret'  => array(
				'type'    => 'password',
				'label'   => __( 'Client Secret', 'bg-commerce-suite' ),
				'default' => '',
			),
			'partner_id'     => $partner_field,
			'warehouse_id'   => $warehouse_field,
			'webhook_secret' => array(
				'type'    => 'password',
				'label'   => __( 'Webhook Secret', 'bg-commerce-suite' ),
				'default' => '',
			),

			// -- Данни за подателя --------------------------------------------
			'sender_name'    => array(
				'type'    => 'text',
				'label'   => __( 'Sender — name / company', 'bg-commerce-suite' ),
				'default' => '',
			),
			'sender_phone'   => array(
				'type'        => 'text',
				'label'       => __( 'Sender — phone', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Phone number in +359XXXXXXXXX format.', 'bg-commerce-suite' ),
			),
			'sender_email'   => array(
				'type'    => 'text',
				'label'   => __( 'Sender — email', 'bg-commerce-suite' ),
				'default' => '',
			),

			// -- Параметри по подразбиране ------------------------------------
			'default_size'   => array(
				'type'    => 'select',
				'label'   => __( 'Default compartment size', 'bg-commerce-suite' ),
				'default' => '2',
				'options' => array(
					'1' => __( 'Small', 'bg-commerce-suite' ),
					'2' => __( 'Medium', 'bg-commerce-suite' ),
					'3' => __( 'Large', 'bg-commerce-suite' ),
				),
			),
			// Цената идва от Core (секция „Цени и безплатна доставка“ — статична цена
			// / безплатна доставка До автомат). BoxNow няма договорна цена през API.
			'allow_returns'  => array(
				'type'           => 'checkbox',
				'label'          => __( 'Allow returns', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'allowReturn = true in the shipment request', 'bg-commerce-suite' ),
				'default'        => 'no',
				'description'    => __( 'Requires return logistics to be enabled in your BOX NOW contract.', 'bg-commerce-suite' ),
			),

			// -- Checkout & UX ------------------------------------------------
			'show_locker'    => array(
				'type'           => 'checkbox',
				'label'          => __( 'Delivery to BOX NOW locker', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Show BOX NOW in the shipping options', 'bg-commerce-suite' ),
				'default'        => 'yes',
			),
			'widget_gps'     => array(
				'type'           => 'checkbox',
				'label'          => __( 'GPS on the map', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Allow geolocation on the locker map', 'bg-commerce-suite' ),
				'default'        => 'yes',
				'description'    => __( 'Locker selection at checkout always uses the official BOX NOW map. BOX NOW provides only a production map — in the test environment, select a locker from the list inside the order.', 'bg-commerce-suite' ),
				'show_if'        => array( 'show_locker' => 'yes' ),
			),
			'voucher_email'  => array(
				'type'        => 'text',
				'label'       => __( 'Voucher email (notifyOnAccepted)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Optional — BOX NOW sends the voucher to this email when the shipment is accepted.', 'bg-commerce-suite' ),
			),

			// -- Услуга, известия и етикет --------------------------------------
			'type_of_service' => array(
				'type'        => 'select',
				'label'       => __( 'Default service type', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => array(
					''         => __( 'As determined by BOX NOW', 'bg-commerce-suite' ),
					'same-day' => __( 'Same day', 'bg-commerce-suite' ),
					'next-day' => __( 'Next day', 'bg-commerce-suite' ),
				),
				'description' => __( 'Sends `typeOfService`. If left empty, your BOX NOW contract applies.', 'bg-commerce-suite' ),
			),
			'show_recipient_info' => array(
				'type'           => 'checkbox',
				'label'          => __( 'Recipient details on the label', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Print recipient phone and email (showRecipientInformation)', 'bg-commerce-suite' ),
				'default'        => 'no',
				'description'    => __( 'The label is placed on a shipment in a public locker — enable this only if you really need it.', 'bg-commerce-suite' ),
			),
			'notify_sms'      => array(
				'type'        => 'text',
				'label'       => __( 'SMS on acceptance (notifySMSOnAccepted)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Optional — phone number in +359XXXXXXXXX format to which BOX NOW sends an SMS on acceptance.', 'bg-commerce-suite' ),
			),
			'return_location_id' => array_merge( $return_field, array( 'show_if' => array( 'allow_returns' => 'yes' ) ) ),
			'label_row1'      => array(
				'type'        => 'text',
				'label'       => __( 'Label, line 1', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'Replaces the sender block on the label (overwriteSenderShippingLabelInfo). Up to 20 characters per line. `{order}` is replaced with the order number.', 'bg-commerce-suite' ),
			),
			'label_row2'      => array(
				'type'    => 'text',
				'label'   => __( 'Label, line 2', 'bg-commerce-suite' ),
				'default' => '',
			),
			'label_row3'      => array(
				'type'    => 'text',
				'label'   => __( 'Label, line 3', 'bg-commerce-suite' ),
				'default' => '',
			),
			'label_row4'      => array(
				'type'    => 'text',
				'label'   => __( 'Label, line 4', 'bg-commerce-suite' ),
				'default' => '',
			),
		);
	}

	/**
	 * Wire into Core services.
	 *
	 * @param Container $container DI container.
	 */
	public function register( Container $container ) {
		Shipping_Hooks::init();
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );

		add_filter(
			'bgcs3_cod_payment_methods',
			static function ( $methods ) {
				$methods[] = 'boxnow_payment';
				return $methods;
			}
		);

		add_action( 'bgcs3_selector_slots', 'bgcs3_boxnow_selector_slot' );
		add_action( 'wp_enqueue_scripts', 'bgcs3_boxnow_enqueue', 20 );
	}

	/**
	 * Register BoxNow shipping method in WooCommerce.
	 *
	 * @param array<string,string> $methods
	 * @return array<string,string>
	 */
	public function register_shipping_method( $methods ) {
		$methods[ 'bgcs3_' . self::ID ] = Shipping_Method::class;
		return $methods;
	}


	/** @inheritdoc */
	public function validate( Selection $selection ) {
		$valid = parent::validate( $selection );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// BOX NOW does not publish a staging locker map. In demo mode the checkout
		// can therefore carry a production-map locker which is intentionally
		// replaced with a stage locker in the order panel before label creation.
		if ( 'live' !== (string) Module_Settings::get( self::ID, 'env' ) ) {
			return true;
		}
		return $this->validate_synced_pickup_point( $selection );
	}

	/** @inheritdoc */
	public function validate_package( array $package, Selection $selection ) {
		unset( $selection );
		$availability = $this->package_availability( $package );
		if ( $availability instanceof Shipping_Availability ) {
			return new \WP_Error( 'bgcs3_' . $availability->code, $availability->customer_message );
		}
		return true;
	}

	/**
	 * Optional Core capability: explain a physical rejection before BOX NOW is
	 * selectable. Missing product dimensions remain unknown, never a rejection.
	 *
	 * @param array<string,mixed> $package WooCommerce package.
	 * @return Shipping_Availability|null
	 */
	public function package_availability( array $package ) {
		// BGCS-AUDIT-012 — the two preconditions for cash on delivery are checked
		// here, alongside the physical ones, because Core already knows what to do
		// with the answer: `Method::calculate_shipping()` turns it into an honest
		// non-selectable card. Both used to be discovered only when the merchant
		// pressed "create shipment", by which point the customer had checked out
		// with an order that could never be dispatched through BOX NOW.
		$cod = $this->cod_availability();
		if ( $cod instanceof Shipping_Availability ) {
			return $cod;
		}

		$physical = Package_Dimensions::for_package( $package );
		if ( ! empty( $physical['oversize'] ) ) {
			$products = isset( $physical['oversize_products'] ) ? (array) $physical['oversize_products'] : array();
			$first    = ! empty( $products[0] ) ? $products[0] : array();
			$name     = ! empty( $first['name'] ) ? (string) $first['name'] : __( 'A product in your cart', 'bg-commerce-suite' );
			$dims     = ! empty( $first['dimensions_cm'] ) && is_array( $first['dimensions_cm'] ) ? $first['dimensions_cm'] : array();
			$observed = '';
			if ( isset( $dims['length'], $dims['width'], $dims['height'] ) ) {
				$observed = sprintf( ' (%.2f × %.2f × %.2f cm)', (float) $dims['length'], (float) $dims['width'], (float) $dims['height'] );
			}
			return Shipping_Availability::unavailable(
				'boxnow_oversize',
				sprintf(
					/* translators: 1: product name, 2: normalized dimensions. */
					__( '%1$s%2$s does not fit a BOX NOW locker. The maximum dimensions are 36 × 45 × 60 cm.', 'bg-commerce-suite' ),
					$name,
					$observed
				),
				'BOX NOW physical validation rejected one or more products by normalized dimensions.',
				array(
					'affected_products' => $products,
					'limits'            => array( 'dimensions_cm' => array( 'height' => 36.0, 'width' => 45.0, 'length' => 60.0 ) ),
					'observed_values'   => array( 'dimensions_known' => ! empty( $physical['dimensions_known'] ) ),
				)
			);
		}

		if ( ! empty( $physical['overweight'] ) ) {
			$products = isset( $physical['overweight_products'] ) ? (array) $physical['overweight_products'] : array();
			$first    = ! empty( $products[0] ) ? $products[0] : array();
			$name     = ! empty( $first['name'] ) ? (string) $first['name'] : __( 'A product in your cart', 'bg-commerce-suite' );
			$weight   = isset( $first['weight_kg'] ) ? (float) $first['weight_kg'] : (float) $physical['max_unit_weight_kg'];
			return Shipping_Availability::unavailable(
				'boxnow_overweight_item',
				sprintf(
					/* translators: 1: product name, 2: normalized weight in kilograms. */
					__( '%1$s weighs %2$s kg and exceeds the 20 kg BOX NOW parcel limit.', 'bg-commerce-suite' ),
					$name,
					rtrim( rtrim( number_format( $weight, 3, '.', '' ), '0' ), '.' )
				),
				'BOX NOW physical validation rejected one or more products by normalized unit weight.',
				array(
					'affected_products' => $products,
					'limits'            => array( 'max_weight_kg' => 20.0 ),
					'observed_values'   => array( 'max_unit_weight_kg' => $weight ),
				)
			);
		}

		return null;
	}

	/**
	 * The same two cash-on-delivery preconditions, for an order that already
	 * exists.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return Label_Result|null Null when the shipment may proceed.
	 */
	private function validate_cod_for_order( \WC_Order $order, array $wb ) {
		$amount = Cod::resolve_amount( $order, $wb );
		if ( $amount <= 0 ) {
			return null;
		}

		if ( false === $this->account_allows( 'codpayment' ) ) {
			return Label_Result::error(
				__( 'Your BOX NOW account is not entitled to cash on delivery. Ask BOX NOW to enable it, or use a prepaid order. (code P411)', 'bg-commerce-suite' )
			);
		}

		if ( $amount > self::COD_MAX ) {
			return Label_Result::error(
				sprintf(
					/* translators: 1: requested cash-on-delivery amount, 2: the BOX NOW limit. */
					__( 'The cash-on-delivery amount for this order is %1$s, above the BOX NOW limit of %2$s. Lower the amount in the shipment panel, or send the order with another courier. (code P408)', 'bg-commerce-suite' ),
					$this->format_amount( $amount ),
					$this->format_amount( self::COD_MAX )
				)
			);
		}

		return null;
	}

	/**
	 * Whether the cart, as the customer is currently paying for it, can be sent
	 * cash on delivery through BOX NOW.
	 *
	 * Only applies when the customer has actually chosen cash on delivery: a
	 * prepaid cart of any value is fine, and the limit must not be presented as a
	 * cart-value limit.
	 *
	 * @return Shipping_Availability|null Null when there is nothing to object to.
	 */
	private function cod_availability() {
		if ( ! Cod::is_chosen() ) {
			return null;
		}

		// The account may simply not be allowed to collect money. `null` means we
		// have not been told, and is deliberately treated as allowed.
		if ( false === $this->account_allows( 'codpayment' ) ) {
			return Shipping_Availability::unavailable(
				'boxnow_cod_not_permitted',
				__( 'BOX NOW cannot be used with cash on delivery for this store. Choose another payment method, or another delivery method.', 'bg-commerce-suite' ),
				'BOX NOW partner profile reports codPayment = false (the precondition for API error P411).',
				array(
					'limits'          => array( 'cod_payment_permitted' => false ),
					'observed_values' => array( 'account_permission_known' => true ),
				)
			);
		}

		$amount = $this->checkout_cod_amount();
		if ( $amount <= self::COD_MAX ) {
			return null;
		}

		return Shipping_Availability::unavailable(
			'boxnow_cod_over_limit',
			sprintf(
				/* translators: 1: cart total, 2: the BOX NOW cash-on-delivery limit. */
				__( 'Cash on delivery through BOX NOW is limited to %2$s. This order is %1$s — choose another payment method, or another delivery method.', 'bg-commerce-suite' ),
				$this->format_amount( $amount ),
				$this->format_amount( self::COD_MAX )
			),
			'BOX NOW cash-on-delivery amount exceeds the contract limit (the precondition for API error P408).',
			array(
				'limits'          => array( 'cod_max' => self::COD_MAX ),
				'observed_values' => array( 'cod_amount' => $amount ),
			)
		);
	}

	/**
	 * The amount BOX NOW would be asked to collect, at checkout time.
	 *
	 * There is no order yet, so this is the cart total — the same value
	 * `Cod::amount()` would return once the order exists.
	 *
	 * @return float
	 */
	private function checkout_cod_amount() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		return round( max( 0.0, (float) WC()->cart->get_total( 'edit' ) ), 2 );
	}

	/**
	 * @param float $amount Amount.
	 * @return string
	 */
	private function format_amount( $amount ) {
		if ( function_exists( 'wc_price' ) && function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( wc_price( $amount ) );
		}

		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Get a shipping price quote from the configured weight ranges.
	 *
	 * Core resolves free shipping first. This method is the active source only
	 * when the merchant selected BOX NOW pricing by weight.
	 *
	 * @param array<string,mixed> $package   WC shipping package.
	 * @param Selection           $selection Selection.
	 * @return Price_Result
	 */
	public function quote( array $package, Selection $selection ) {
		$availability = $this->package_availability( $package );
		if ( $availability instanceof Shipping_Availability ) {
			$result               = new Price_Result();
			$result->availability = $availability;
			$result->errors       = array( $availability->customer_message );
			return $result;
		}
		$physical = Package_Dimensions::for_package( $package );

		$ranges = bgcs3_get_option( self::ID, 'weight_price_ranges', array() );
		$ranges = is_array( $ranges ) ? Weight_Pricing::sanitize_ranges( $ranges ) : array();

		if ( empty( $ranges ) ) {
			return Price_Result::error( __( 'Weight-based pricing is selected, but no valid BOX NOW weight range is configured.', 'bg-commerce-suite' ) );
		}

		$weight = Weight::for_package( self::ID, $package );
		$cost   = Weight_Pricing::resolve( $weight, $ranges );

		if ( null === $cost ) {
			return Price_Result::error(
				sprintf(
					/* translators: %s: package weight in kilograms. */
					__( 'No BOX NOW price is configured for a total weight of %s kg. Check the ranges in settings.', 'bg-commerce-suite' ),
					rtrim( rtrim( number_format( $weight, 3, '.', '' ), '0' ), '.' )
				)
			);
		}

		$result           = new Price_Result();
		$result->valid    = true;
		$result->cost     = $cost;
		$result->base_cost = $cost;
		$result->currency = get_woocommerce_currency();
		$result->mode     = 'static';
		$result->source   = 'configured_tariff';
		$result->weight   = $weight;
		$result->meta['boxnow_physical_profile'] = array(
			'dimensions_known'         => ! empty( $physical['dimensions_known'] ),
			'physical_units'           => isset( $physical['physical_units'] ) ? (int) $physical['physical_units'] : 0,
			'minimum_compartment_size' => isset( $physical['minimum_compartment_size'] ) ? (int) $physical['minimum_compartment_size'] : 0,
		);

		return $result;
	}

	/**
	 * Create a BoxNow delivery request waybill.
	 *
	 * @param \WC_Order $order Order.
	 * @return Label_Result
	 */
	public function create_label( \WC_Order $order ) {
		$preflight = $this->preflight_shipment( $order );
		if ( $preflight->is_blocked() ) {
			return $preflight->label_error();
		}

		$selection = $this->order_selection( $order );
		if ( ! $selection ) {
			$error = Label_Result::error( __( 'The order has no saved delivery selection.', 'bg-commerce-suite' ) );
			return $preflight->reject( $error, 'boxnow_selection' );
		}

		if ( empty( $selection->office['id'] ) ) {
			$error = Label_Result::error( __( 'The order has no selected delivery locker.', 'bg-commerce-suite' ) );
			return $preflight->reject( $error, 'boxnow_destination' );
		}

		$wb = $order->get_meta( '_bgcs3_wb' );
		$wb = is_array( $wb ) ? $wb : array();

		// The merchant may route a single order out of another warehouse without
		// changing the shop-wide setting.
		$warehouse_id = $this->wbx( $wb, 'origin_id' );
		if ( '' === $warehouse_id ) {
			$warehouse_id = (string) bgcs3_get_option( self::ID, 'warehouse_id', '' );
		}

		$destination_id = (string) $selection->office['id'];
		$physical_check = $this->validate_physical_package_for_order( $order, $wb );
		if ( $physical_check instanceof Label_Result ) {
			return $preflight->reject( $physical_check, 'boxnow_package' );
		}
		// BGCS-AUDIT-012 — an order placed before the checkout gate existed, or
		// one whose COD amount the merchant raised by hand, must be refused here
		// with something they can act on, rather than by BOX NOW after the HTTP
		// round trip. Never by silently trimming the amount: the courier would
		// then collect less than the order is worth.
		$cod_check = $this->validate_cod_for_order( $order, $wb );
		if ( $cod_check instanceof Label_Result ) {
			return $preflight->reject( $cod_check, 'boxnow_cod' );
		}

		$value_check = $this->validate_parcel_values_for_order( $order, $wb );
		if ( $value_check instanceof Label_Result ) {
			return $preflight->reject( $value_check, 'boxnow_declared_value' );
		}
		$parcels        = $this->parcels( $order, $wb );
		$default_size   = $this->largest_compartment( $parcels );
		$valid_origins  = $this->locations()->origins();
		$origins_error  = $this->locations()->origins_error();
		if ( is_wp_error( $origins_error ) ) {
			$error = Label_Result::error(
				sprintf(
					/* translators: %s BOX NOW API error message. */
					__( 'The sender warehouse could not be confirmed through BOX NOW /origins: %s', 'bg-commerce-suite' ),
					$origins_error->get_error_message()
				)
			);
			return $preflight->reject( $error, 'boxnow_sender_origin' );
		}
		if ( '' === $warehouse_id ) {
			$error = Label_Result::error( __( 'Sender configuration is missing (Warehouse ID).', 'bg-commerce-suite' ) );
			return $preflight->reject( $error, 'boxnow_sender_origin' );
		}
		if ( ! empty( $valid_origins ) && ! array_key_exists( $warehouse_id, $valid_origins ) ) {
			$error = Label_Result::error(
				__( 'The selected sender warehouse is not valid for the current BOX NOW account and environment. Open the BOX NOW settings, select a warehouse from the current list and save.', 'bg-commerce-suite' )
			);
			return $preflight->reject( $error, 'boxnow_sender_origin' );
		}

		// Partner API v1.69 models `returnLocation` as an address/location object,
		// not as `{locationId: ...}`. The setting stores an /origins id because that
		// is stable and merchant-friendly; resolve it to the exact object fields the
		// create endpoint documents before sending the request. Literal `0` is the
		// per-order escape hatch that intentionally suppresses a global override.
		$return_location_id = $this->wbx( $wb, 'return_location_id' );
		if ( '0' === $return_location_id ) {
			$return_location_id = '';
		} elseif ( '' === $return_location_id ) {
			$return_location_id = (string) bgcs3_get_option( self::ID, 'return_location_id', '' );
		}

		$return_location = array();
		if ( '' !== $return_location_id ) {
			$return_origin = $this->locations()->origin( $return_location_id );
			$return_error  = $this->locations()->origins_error();
			if ( is_wp_error( $return_error ) ) {
				$error = Label_Result::error(
					sprintf(
						/* translators: %s BOX NOW API error message. */
						__( 'The return warehouse could not be confirmed through BOX NOW /origins: %s', 'bg-commerce-suite' ),
						$return_error->get_error_message()
					)
				);
				return $preflight->reject( $error, 'boxnow_return_origin' );
			}
			if ( ! is_array( $return_origin ) ) {
				$error = Label_Result::error( __( 'The selected BOX NOW return warehouse is not valid for the current account and environment. Select a current return warehouse or use the BOX NOW contract default.', 'bg-commerce-suite' ) );
				return $preflight->reject( $error, 'boxnow_return_origin' );
			}

			$return_location = $this->return_location_payload( $return_origin );
			if ( empty( $return_location['addressLine1'] ) || empty( $return_location['postalCode'] ) || empty( $return_location['country'] ) ) {
				$error = Label_Result::error( __( 'BOX NOW returned an incomplete return warehouse record (address, postal code or country is missing). Choose another return warehouse or use the BOX NOW contract default.', 'bg-commerce-suite' ) );
				return $preflight->reject( $error, 'boxnow_return_origin' );
			}
		}

		$valid_destinations = $this->client()->get_destinations( (string) $default_size );
		if ( is_wp_error( $valid_destinations ) ) {
			$error = Label_Result::error(
				sprintf(
					/* translators: %s BOX NOW API error message. */
					__( 'The selected locker could not be confirmed through BOX NOW /destinations: %s', 'bg-commerce-suite' ),
					$this->explain_error( $valid_destinations )
				)
			);
			return $preflight->reject( $error, 'boxnow_destination' );
		}

		$valid_destination_ids = array();
		foreach ( $valid_destinations as $destination ) {
			if ( is_array( $destination ) && ! empty( $destination['id'] ) ) {
				$valid_destination_ids[] = (string) $destination['id'];
			}
		}

		if ( ! in_array( $destination_id, $valid_destination_ids, true ) ) {
			// The checkout map is BOX NOW's production service — they publish no
			// staging map — so on the test API the customer's locker is almost
			// always simply "not a stage locker". Saying that outright beats
			// sending the merchant to look for a fault that isn't there.
			if ( 'live' !== Module_Settings::get( self::ID, 'env' ) ) {
				$error = Label_Result::error(
					sprintf(
						/* translators: 1: BOX NOW locker ID, 2: number of lockers on the test environment. */
						__( 'Locker ID %1$s does not exist in the BOX NOW test environment. The checkout map is the real BOX NOW map (they do not provide a test map), while the test API knows only %2$d lockers. For a test shipment label, select a locker from the “Locker” field in this order; for real orders, switch the environment to “Production”.', 'bg-commerce-suite' ),
						$destination_id,
						count( $valid_destination_ids )
					)
				);
				return $preflight->reject( $error, 'boxnow_environment_destination' );
			}

			$error = Label_Result::error(
				sprintf(
					/* translators: 1: BOX NOW locker ID, 2: compartment size. */
					__( 'Locker ID %1$s is no longer valid for this BOX NOW account or for size %2$d. Select a current locker and save the order again.', 'bg-commerce-suite' ),
					$destination_id,
					$default_size
				)
			);
			return $preflight->reject( $error, 'boxnow_destination' );
		}

		// Resolved for the warehouse this parcel actually leaves from, so the
		// checks below report a gap in the contact that will really be sent.
		$sender           = $this->sender_contact_for_order( $warehouse_id, $wb );
		$sender_name      = (string) $sender['name'];
		$sender_email     = (string) $sender['email'];
		$sender_phone_raw = (string) $sender['phone'];

		if ( '' === $sender_name ) {
			$error = Label_Result::error( __( 'Sender configuration is missing (Name / company).', 'bg-commerce-suite' ) );
			return $preflight->reject( $error, 'boxnow_sender_contact' );
		}
		if ( '' === $sender_email ) {
			$error = Label_Result::error( __( 'Sender configuration is missing (Email).', 'bg-commerce-suite' ) );
			return $preflight->reject( $error, 'boxnow_sender_contact' );
		}
		if ( '' === $sender_phone_raw ) {
			$error = Label_Result::error( __( 'Sender configuration is missing (Phone).', 'bg-commerce-suite' ) );
			return $preflight->reject( $error, 'boxnow_sender_contact' );
		}

		// Ensure telephone format +XXХ123456789
		$sender_phone        = $this->normalize_phone( $sender_phone_raw );
		$recipient_phone_raw = $this->recipient_phone_raw( $order, $wb );
		if ( '' === $recipient_phone_raw ) {
			$error = Label_Result::error( __( 'The order has no recipient phone number. Add a phone number to the order and try again.', 'bg-commerce-suite' ) );
			return $preflight->reject( $error, 'boxnow_recipient' );
		}
		$recipient_phone = $this->normalize_phone( $recipient_phone_raw );
		$recipient_name  = ! empty( $wb['contact_name'] )
			? trim( (string) $wb['contact_name'] )
			: trim( $order->get_formatted_billing_full_name() );
		$recipient_email = ! empty( $wb['email'] )
			? trim( (string) $wb['email'] )
			: trim( (string) $order->get_billing_email() );
		if ( '' === $recipient_name ) {
			$error = Label_Result::error( __( 'BOX NOW requires a recipient name. Add it to the order or the order-specific shipment settings.', 'bg-commerce-suite' ) );
			return $preflight->reject( $error, 'boxnow_recipient' );
		}
		if ( '' === $recipient_email || ! is_email( $recipient_email ) ) {
			$error = Label_Result::error( __( 'BOX NOW requires a valid recipient email address. Add it to the order or the order-specific shipment settings.', 'bg-commerce-suite' ) );
			return $preflight->reject( $error, 'boxnow_recipient' );
		}

		$payload = $this->delivery_request_body(
			$order,
			$wb,
			array(
				'warehouse_id'    => $warehouse_id,
				'destination_id'  => $destination_id,
				'sender_phone'    => $sender_phone,
				'sender_contact'  => array(
					'name'  => $sender_name,
					'email' => $sender_email,
					'phone' => $sender_phone,
				),
				'recipient_phone' => $recipient_phone,
				'parcels'         => $parcels,
				'return_location' => $return_location,
			)
		);

		$preflight
			->section(
				'sender',
				array(
					'account_id'      => (string) Module_Settings::get( self::ID, 'partner_id' ),
					'location_type'   => 'warehouse',
					'location_id'     => $warehouse_id,
					'contact_present' => '' !== $sender_name && '' !== $sender_email && '' !== $sender_phone_raw,
				)
			)
			->section(
				'recipient_payload',
				array(
					'private_person' => '' === trim( (string) $order->get_billing_company() ),
					'locker_id'      => $destination_id,
					'name_present'   => '' !== $recipient_name,
					'phone_present'  => '' !== $recipient_phone_raw,
					'email_present'  => '' !== $recipient_email,
				)
			)
			->section(
				'package_payload',
				array(
					'parcel_count'     => ! empty( $payload['items'] ) && is_array( $payload['items'] ) ? count( $payload['items'] ) : 0,
					'contents_present' => ! empty( $payload['description'] ),
					'declared_value'   => isset( $payload['invoiceValue'] ) ? (float) $payload['invoiceValue'] : 0.0,
				)
			)
			->section(
				'services',
				array(
					'type'                    => ! empty( $payload['typeOfService'] ) ? (string) $payload['typeOfService'] : '',
					'allow_return'            => ! empty( $payload['allowReturn'] ),
					'notify_email'            => array_key_exists( 'notifyOnAccepted', $payload ),
					'notify_sms'              => array_key_exists( 'notifySMSOnAccepted', $payload ),
					'show_recipient_info'     => ! empty( $payload['showRecipientInformation'] ),
					'return_location_present' => ! empty( $payload['returnLocation'] ),
				)
			)
			->section(
				'payer',
				array(
					'courier_service' => '',
					'cod_pmt'         => Cod::resolve_amount( $order, $wb ) > 0 ? 'RECIPIENT' : '',
					'package'         => '',
					'declared_value'  => '',
				)
			)
			->payload_ready( $payload );

		if ( $preflight->is_blocked() ) {
			return $preflight->label_error();
		}

		// Create delivery request in BoxNow.
		$creation = Shipment_Creation::remote_started( $order, $this );
		if ( true !== $creation ) {
			return $creation;
		}
		$response = $this->client()->create_delivery_request( $payload );

		if ( is_wp_error( $response ) || empty( $response['parcels'][0]['id'] ) ) {
			Shipment_Creation::remote_failed( $order, $response );
			$message = is_wp_error( $response )
				? $this->explain_error( $response )
				: __( 'Order registration in BOX NOW failed.', 'bg-commerce-suite' );
			return Label_Result::error( $message );
		}

		// One `items[]` entry becomes one parcel with its OWN id. Keeping only
		// the first (as 1.4.0 did) left every further parcel unlabelled and
		// impossible to cancel from here, while it still existed at BOX NOW.
		$parcel_ids = array();
		foreach ( (array) $response['parcels'] as $parcel ) {
			if ( is_array( $parcel ) && ! empty( $parcel['id'] ) ) {
				$parcel_ids[] = (string) $parcel['id'];
			}
		}

		$parcel_id = $parcel_ids[0];
		$reference = (string) $payload['orderNumber'];
		Shipment_Creation::remote_accepted(
			$order,
			array(
				'shipment_number' => $reference,
				'parcel_ids'      => $parcel_ids,
				'tracking_numbers' => $parcel_ids,
				'label_reference' => $reference,
			)
		);

		$result                  = new Label_Result();
		$result->success         = true;
		$result->courier         = self::ID;
		$result->number          = $parcel_id;
		$result->created_at      = time();
		$result->shipment_number = $reference;
		$result->parcel_ids      = $parcel_ids;
		$result->tracking_numbers = $parcel_ids;
		$result->label_reference = $reference;
		$result->meta            = array(
			'parcel_ids'   => $parcel_ids,
			'order_number' => $reference,
		);
		$read_back = $this->client()->get_parcel_status( $parcel_id );
		$result->meta['read_back_status'] = is_wp_error( $read_back )
			? 'unavailable'
			: ( Shipment_Creation::response_confirms( $read_back, array( 'id', 'parcelId', 'parcelReferenceNumber' ), $parcel_id ) ? 'verified' : 'partial' );

		$pdf_data = $this->label_pdf( $reference, $parcel_id, count( $parcel_ids ) );

		if ( is_wp_error( $pdf_data ) ) {
			$result->meta['provider_warning'] = sprintf( __( 'BOX NOW created the shipment, but the label PDF could not be retrieved: %s', 'bg-commerce-suite' ), $this->explain_error( $pdf_data ) );
			return $result;
		}

		// Store PDF locally
		$pdf_url = Label_Pdf_Store::save( self::ID, $parcel_id . '.pdf', $pdf_data );

		if ( ! $pdf_url ) {
			$result->meta['provider_warning'] = __( 'BOX NOW created the shipment, but the label PDF could not be stored locally.', 'bg-commerce-suite' );
			return $result;
		}
		$result->pdf_url = $pdf_url;

		return $result;
	}

	/**
	 * The printable label(s) for a delivery request.
	 *
	 * With more than one parcel the per-parcel endpoint returns ONE label, so
	 * the merchant would print a single sticker for a multi-parcel shipment.
	 * `/delivery-requests/{orderNumber}/label.pdf` returns every parcel's label
	 * in one document — measured on stage: 63 KB for one parcel against 107 KB
	 * for the same request's three.
	 *
	 * @param string $reference Our own order reference, as sent on create.
	 * @param string $parcel_id First parcel id.
	 * @param int    $count     Number of parcels in the request.
	 * @return string|\WP_Error Binary PDF.
	 */
	private function label_pdf( $reference, $parcel_id, $count ) {
		if ( $count > 1 ) {
			$all = $this->client()->get_delivery_request_pdf( $reference );
			if ( ! is_wp_error( $all ) ) {
				return $all;
			}
			// Fall through: one label is better than none, and the merchant is
			// told which parcels the sheet does not cover.
		}

		return $this->client()->get_parcel_pdf( $parcel_id );
	}

	/**
	 * Enforce BOX NOW's documented physical parcel limits before a destructive
	 * delivery request is created. Explicit multi-parcel rows remain authoritative
	 * for how a multi-item order is split.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return true|Label_Result
	 */
	private function validate_physical_package_for_order( \WC_Order $order, array $wb ) {
		$physical = Package_Dimensions::for_order( $order );
		if ( ! empty( $physical['oversize'] ) ) {
			return Label_Result::error( __( 'BOX NOW cannot create this shipment because at least one product exceeds the maximum locker dimensions (36 × 45 × 60 cm).', 'bg-commerce-suite' ) );
		}

		$rows = isset( $wb['packages'] ) && is_array( $wb['packages'] )
			? array_values( array_filter( $wb['packages'], 'is_array' ) )
			: array();
		if ( empty( $rows ) ) {
			$weight = ( isset( $wb['weight'] ) && '' !== $wb['weight'] ) ? (float) $wb['weight'] : (float) Weight::for_order( self::ID, $order );
			if ( $weight > 20.0001 ) {
				return Label_Result::error( __( 'BOX NOW allows a maximum of 20 kg per parcel. Split the order into multiple package rows before creating the shipment.', 'bg-commerce-suite' ) );
			}
			return true;
		}

		$total_weight = ( isset( $wb['weight'] ) && '' !== $wb['weight'] ) ? (float) $wb['weight'] : (float) Weight::for_order( self::ID, $order );
		$fallback_weight = ! empty( $rows ) ? ( $total_weight / count( $rows ) ) : 0.0;

		// Even when the merchant splits the order manually, at least one selected
		// compartment must be large enough for the largest known individual product.
		// We do not guess which product belongs to which parcel; this is only the
		// provable lower-bound check.
		if ( ! empty( $physical['minimum_compartment_size'] ) ) {
			$largest_selected = 0;
			foreach ( $rows as $row ) {
				$largest_selected = max( $largest_selected, $this->normalize_size( isset( $row['compartment_size'] ) ? (string) $row['compartment_size'] : (string) Module_Settings::get( self::ID, 'default_size' ) ) );
			}
			if ( $largest_selected < (int) $physical['minimum_compartment_size'] ) {
				return Label_Result::error( __( 'BOX NOW package rows use compartments that are too small for at least one product in this order. Increase at least one parcel compartment size before creating the shipment.', 'bg-commerce-suite' ) );
			}
		}

		foreach ( $rows as $index => $row ) {
			$weight = isset( $row['weight'] ) && '' !== (string) $row['weight'] ? (float) $row['weight'] : $fallback_weight;
			if ( $weight > 20.0001 ) {
				return Label_Result::error(
					sprintf(
						/* translators: %d package row number. */
						__( 'BOX NOW package %d exceeds the 20 kg per-parcel limit. Reduce its weight or split it into more package rows.', 'bg-commerce-suite' ),
						(int) $index + 1
					)
				);
			}
		}

		return true;
	}



	/**
	 * Validate explicit BOX NOW parcel values against the order invoice value.
	 * Merchant-entered values are never silently rewritten. Blank parcel values
	 * are allowed and receive the remaining commercial value in {@see parcels()}.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return true|Label_Result
	 */
	private function validate_parcel_values_for_order( \WC_Order $order, array $wb ) {
		$rows = isset( $wb['packages'] ) && is_array( $wb['packages'] ) ? array_values( array_filter( $wb['packages'], 'is_array' ) ) : array();
		if ( empty( $rows ) ) {
			return true;
		}

		$total         = round( (float) $this->invoice_value( $order, $wb ), 2 );
		$explicit_sum  = 0.0;
		$blank_values  = 0;
		foreach ( $rows as $index => $row ) {
			if ( ! isset( $row['value'] ) || '' === trim( (string) $row['value'] ) ) {
				$blank_values++;
				continue;
			}
			if ( ! is_numeric( $row['value'] ) || (float) $row['value'] < 0 ) {
				return Label_Result::error(
					sprintf(
						/* translators: %d parcel row number. */
						__( 'BOX NOW parcel %d has an invalid commercial value.', 'bg-commerce-suite' ),
						$index + 1
					)
				);
			}
			$explicit_sum += round( (float) $row['value'], 2 );
		}

		$explicit_sum = round( $explicit_sum, 2 );
		if ( $explicit_sum > $total + 0.01 ) {
			return Label_Result::error(
				sprintf(
					/* translators: 1: parcel values sum, 2: order invoice value, 3: currency. */
					__( 'BOX NOW parcel values total %1$s %3$s, which is higher than the order invoice value %2$s %3$s. Correct the parcel values before creating the shipment.', 'bg-commerce-suite' ),
					number_format( $explicit_sum, 2, '.', '' ),
					number_format( $total, 2, '.', '' ),
					strtoupper( (string) $order->get_currency() )
				)
			);
		}

		if ( 0 === $blank_values && abs( $explicit_sum - $total ) > 0.01 ) {
			return Label_Result::error(
				sprintf(
					/* translators: 1: parcel values sum, 2: order invoice value, 3: currency. */
					__( 'BOX NOW parcel values total %1$s %3$s, but the order invoice value is %2$s %3$s. Make the explicit parcel values add up to the order value, or leave one parcel value empty so BGCS can assign the remainder.', 'bg-commerce-suite' ),
					number_format( $explicit_sum, 2, '.', '' ),
					number_format( $total, 2, '.', '' ),
					strtoupper( (string) $order->get_currency() )
				)
			);
		}

		return true;
	}

	/**
	 * The parcels of one order, as `{compartment_size, weight, value}` rows.
	 *
	 * Sourced from Core's multi-pack editor (`_bgcs3_wb['packages']`), whose
	 * columns this module declares in `pack_columns()`. An empty editor — or an
	 * order created before the editor existed — collapses to a single parcel
	 * carrying the order's whole weight and value, which is exactly what the
	 * module sent before it had per-parcel data.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return array<int,array<string,mixed>>
	 */
	private function parcels( \WC_Order $order, array $wb ) {
		$default_size = $this->normalize_size( (string) Module_Settings::get( self::ID, 'default_size' ) );
		$total_weight = ( isset( $wb['weight'] ) && '' !== $wb['weight'] )
			? max( Weight::MIN_KG, (float) $wb['weight'] )
			: (float) Weight::for_order( self::ID, $order );
		$total_value  = $this->invoice_value( $order, $wb );

		// Product dimensions are a safe lower bound for compartment size. They do
		// not replace explicit package rows, because multiple products can be packed
		// in different ways. For the legacy/default single-parcel path, however, we
		// must never choose a compartment smaller than an individual product requires.
		$physical = Package_Dimensions::for_order( $order );
		if ( ! empty( $physical['minimum_compartment_size'] ) ) {
			$default_size = max( $default_size, (int) $physical['minimum_compartment_size'] );
		}

		$rows = ( isset( $wb['packages'] ) && is_array( $wb['packages'] ) ) ? $wb['packages'] : array();
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return is_array( $row );
				}
			)
		);

		if ( empty( $rows ) ) {
			return array(
				array(
					'compartment_size' => $default_size,
					'weight'           => round( $total_weight, 3 ),
					'value'            => $total_value,
				),
			);
		}

		// Blank weights are distributed evenly. Blank commercial values are
		// different: preserve every explicit merchant value and put only the
		// remaining order value on the first blank parcel. This guarantees that
		// BOX NOW items[] always add up to invoiceValue without inventing how the
		// merchant split the goods between parcels.
		$count           = count( $rows );
		$fallback_weight = round( $total_weight / $count, 3 );
		$explicit_value  = 0.0;
		$first_blank     = null;
		foreach ( $rows as $index => $row ) {
			if ( isset( $row['value'] ) && '' !== trim( (string) $row['value'] ) && is_numeric( $row['value'] ) ) {
				$explicit_value += round( (float) $row['value'], 2 );
			} elseif ( null === $first_blank ) {
				$first_blank = $index;
			}
		}
		$remaining_value = max( 0.0, round( (float) $total_value - $explicit_value, 2 ) );
		$parcels         = array();

		foreach ( $rows as $index => $row ) {
			$size   = isset( $row['compartment_size'] ) ? $this->normalize_size( (string) $row['compartment_size'] ) : $default_size;
			$weight = ( isset( $row['weight'] ) && '' !== $row['weight'] ) ? (float) $row['weight'] : $fallback_weight;
			$value  = ( isset( $row['value'] ) && '' !== trim( (string) $row['value'] ) )
				? number_format( (float) $row['value'], 2, '.', '' )
				: number_format( $index === $first_blank ? $remaining_value : 0.0, 2, '.', '' );

			$parcels[] = array(
				'compartment_size' => $size,
				'weight'           => max( Weight::MIN_KG, round( $weight, 3 ) ),
				'value'            => $value,
			);
		}

		return $parcels;
	}

	/**
	 * A compartment size the API will accept, falling back to the setting.
	 *
	 * @param string $size Raw size.
	 * @return int
	 */
	private function normalize_size( $size ) {
		$sizes = $this->compartment_sizes();

		if ( '' !== $size && isset( $sizes[ $size ] ) ) {
			return (int) $size;
		}

		$configured = (string) Module_Settings::get( self::ID, 'default_size' );

		return isset( $sizes[ $configured ] ) ? (int) $configured : 2;
	}

	/**
	 * The largest compartment any parcel needs — the size a locker must have
	 * free for the whole order, so it is what `/destinations` is asked about.
	 *
	 * @param array<int,array<string,mixed>> $parcels Parcels.
	 * @return int
	 */
	private function largest_compartment( array $parcels ) {
		$largest = 0;

		foreach ( $parcels as $parcel ) {
			$largest = max( $largest, (int) $parcel['compartment_size'] );
		}

		return $largest > 0 ? $largest : 2;
	}

	/**
	 * Recipient phone: the admin override first, then the order.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return string
	 */
	private function recipient_phone_raw( \WC_Order $order, array $wb ) {
		if ( ! empty( $wb['phone'] ) ) {
			return trim( (string) $wb['phone'] );
		}

		$phone = trim( (string) $order->get_billing_phone() );
		if ( '' === $phone && method_exists( $order, 'get_shipping_phone' ) ) {
			$phone = trim( (string) $order->get_shipping_phone() );
		}

		return $phone;
	}

	/**
	 * `invoiceValue` — the declared value of the order.
	 *
	 * Tri-state admin override (Rule 15): a blank amount NEVER means „no value“,
	 * only an explicit „Изрично без“ does. Left inherited, the historical
	 * behaviour stands: BOX NOW is told the order total for a COD shipment and
	 * 0.00 for a prepaid one.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return string
	 */
	private function invoice_value( \WC_Order $order, array $wb ) {
		unset( $wb );

		// Partner API v1.69: invoiceValue = total commercial value of the order.
		// It is required for BOTH prepaid and COD shipments and is not an
		// insurance/declared-value override. Older BGCS builds incorrectly tied
		// this field to the generic declared-value control, which made prepaid
		// shipments leave with invoiceValue=0.00.
		return number_format( max( 0.0, (float) $order->get_total() ), 2, '.', '' );
	}

	/**
	 * Contents description: the admin override, else the ordered item names.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return string
	 */
	private function contents( \WC_Order $order, array $wb ) {
		if ( ! empty( $wb['contents'] ) ) {
			return trim( (string) $wb['contents'] );
		}

		$names = array();
		foreach ( $order->get_items() as $item ) {
			$names[] = $item->get_name();
		}

		$names = array_filter( array_map( 'trim', $names ) );

		return $names ? implode( ', ', $names ) : sprintf( __( 'Order %s', 'bg-commerce-suite' ), $order->get_order_number() );
	}

	/**
	 * The `POST /api/v1/delivery-requests` body (partner API v1.69).
	 *
	 * Extracted from `create_label()` so the full field mapping can be asserted
	 * without an HTTP call. Optional properties are omitted when the merchant
	 * left them alone — BOX NOW then applies its own contract defaults, which is
	 * not the same as us sending an empty string.
	 *
	 * @param \WC_Order           $order   Order.
	 * @param array<string,mixed> $wb      Waybill overrides.
	 * @param array<string,mixed> $context Resolved ids/phones/parcels.
	 * @return array<string,mixed>
	 */
	public function delivery_request_body( \WC_Order $order, array $wb, array $context ) {
		// Stable reference (Rule 27, BUG-006 fix) — MUST stay the same across a
		// retry of the same creation attempt (e.g. after an HTTP timeout), or
		// BoxNow cannot recognize a retry as the same request and will create a
		// duplicate parcel. A timestamp-based value (the previous implementation)
		// guaranteed the opposite: every retry got a NEW, unrecognizable reference.
		$reference = Shipment_Reference::for_order( $order );

		$cod_amount  = Cod::resolve_amount( $order, $wb );
		$is_cod      = $cod_amount > 0;
		$description = $this->contents( $order, $wb );

		// Who BOX NOW calls for pickup belongs to the warehouse the parcel
		// leaves from — otherwise routing an order out of a second warehouse
		// sends the courier there with the first warehouse's phone number.
		$sender = ! empty( $context['sender_contact'] ) && is_array( $context['sender_contact'] )
			? $context['sender_contact']
			: $this->sender_contact_for_order( (string) $context['warehouse_id'], $wb );

		$contact_name = ! empty( $wb['contact_name'] )
			? trim( (string) $wb['contact_name'] )
			: trim( $order->get_formatted_billing_full_name() );
		$contact_email = ! empty( $wb['email'] ) ? trim( (string) $wb['email'] ) : trim( (string) $order->get_billing_email() );

		$items = array();
		foreach ( (array) $context['parcels'] as $index => $parcel ) {
			$items[] = array(
				// Unique per parcel: BOX NOW rejects a repeated item id inside one
				// delivery request, and the single-parcel case must keep the exact
				// reference it has always sent.
				'id'              => ( 0 === $index ) ? $reference : $reference . '-' . ( $index + 1 ),
				'name'            => $description,
				'value'           => (string) $parcel['value'],
				'weight'          => (float) $parcel['weight'],
				'compartmentSize' => (int) $parcel['compartment_size'],
			);
		}

		$payload = array(
			'orderNumber'         => $reference,
			'invoiceValue'        => $this->invoice_value( $order, $wb ),
			'paymentMode'         => $is_cod ? 'cod' : 'prepaid',
			'amountToBeCollected' => number_format( $is_cod ? $cod_amount : 0, 2, '.', '' ),
			'allowReturn'         => $this->wbx_bool( $wb, 'allow_return', 'allow_returns', 'no' ),
			'description'         => $description,
			'origin'              => array(
				'contactNumber' => isset( $sender['phone'] ) ? (string) $sender['phone'] : (string) $context['sender_phone'],
				'contactEmail'  => (string) $sender['email'],
				'contactName'   => (string) $sender['name'],
				'locationId'    => (string) $context['warehouse_id'],
			),
			'destination'         => array(
				'contactNumber' => (string) $context['recipient_phone'],
				'contactEmail'  => $contact_email,
				'contactName'   => $contact_name,
				'locationId'    => (string) $context['destination_id'],
			),
			'items'               => $items,
		);

		$notify_email = $this->wbx( $wb, 'notify_email' );
		if ( '0' === $notify_email ) {
			$notify_email = '';
		} elseif ( '' === $notify_email ) {
			$notify_email = (string) Module_Settings::get( self::ID, 'voucher_email' );
		}
		if ( '' !== $notify_email ) {
			$payload['notifyOnAccepted'] = $notify_email;
		}

		$notify_sms = $this->wbx( $wb, 'notify_sms' );
		if ( '0' === $notify_sms ) {
			$notify_sms = '';
		} elseif ( '' === $notify_sms ) {
			$notify_sms = (string) Module_Settings::get( self::ID, 'notify_sms' );
		}
		if ( '' !== $notify_sms ) {
			$payload['notifySMSOnAccepted'] = $this->normalize_phone( $notify_sms );
		}

		$service = $this->wbx( $wb, 'type_of_service' );
		if ( '0' === $service ) {
			$service = '';
		} elseif ( '' === $service ) {
			$service = (string) Module_Settings::get( self::ID, 'type_of_service' );
		}
		if ( in_array( $service, array( 'same-day', 'next-day' ), true ) ) {
			$payload['typeOfService'] = $service;
		}

		// Printing the recipient's phone and e-mail on a label left in a public
		// locker is a disclosure the merchant has to ask for, so an untouched
		// panel keeps it off unless the setting says otherwise.
		$show_recipient = $this->wbx( $wb, 'show_recipient_info' );
		if ( '' !== $show_recipient || 'yes' === (string) Module_Settings::get( self::ID, 'show_recipient_info' ) ) {
			$payload['showRecipientInformation'] = $this->wbx_bool( $wb, 'show_recipient_info', 'show_recipient_info', 'no' );
		}

		$additional = $this->wbx( $wb, 'additional_information' );
		if ( '' !== $additional ) {
			$payload['additionalInformation'] = $additional;
		}

		$label_rows = $this->label_rows( $order, $wb );
		if ( ! empty( $label_rows ) ) {
			$payload['overwriteSenderShippingLabelInfo'] = $label_rows;
		}

		if ( ! empty( $context['return_location'] ) && is_array( $context['return_location'] ) ) {
			$payload['returnLocation'] = $context['return_location'];
		}

		/**
		 * Final BOX NOW delivery-request body.
		 *
		 * @param array<string,mixed> $payload Request body.
		 * @param \WC_Order           $order   Order.
		 * @param array<string,mixed> $wb      Waybill overrides.
		 */
		return (array) apply_filters( 'bgcs3_boxnow_delivery_request_payload', $payload, $order, $wb );
	}

	/**
	 * Exact BOX NOW `returnLocation` object documented by Partner API v1.69.
	 * Unknown /origins properties are deliberately discarded.
	 *
	 * @param array<string,mixed> $origin Raw /origins record.
	 * @return array<string,string>
	 */
	private function return_location_payload( array $origin ) {
		$payload = array();
		foreach ( array( 'addressLine1', 'postalCode', 'country', 'title', 'name', 'addressLine2', 'note' ) as $key ) {
			if ( isset( $origin[ $key ] ) && '' !== trim( (string) $origin[ $key ] ) ) {
				$payload[ $key ] = trim( (string) $origin[ $key ] );
			}
		}

		return $payload;
	}

	/**
	 * `overwriteSenderShippingLabelInfo` — up to four 20-character rows printed
	 * in place of the sender block on the label. Per-order rows win; otherwise
	 * the settings' rows are used, with `{order}` replaced by the order number.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return array<string,string>
	 */
	private function label_rows( \WC_Order $order, array $wb ) {
		$rows = array();

		for ( $i = 1; $i <= 4; $i++ ) {
			$value = $this->wbx( $wb, 'label_row' . $i );
			if ( '0' === $value ) {
				$value = '';
			} elseif ( '' === $value ) {
				$value = (string) bgcs3_get_option( self::ID, 'label_row' . $i, '' );
			}

			$value = str_replace( '{order}', (string) $order->get_order_number(), $value );
			$value = trim( $value );

			if ( '' === $value ) {
				continue;
			}

			// The API's own limit; a longer row is rejected for the whole request.
			$rows[ 'row' . $i ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 20 ) : substr( $value, 0, 20 );
		}

		return $rows;
	}

	/**
	 * Cancel a BoxNow shipment label.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $number Parcel id.
	 * @return mixed|\WP_Error
	 */
	protected function cancel_shipment( \WC_Order $order, $number ) {
		// A multi-parcel order has one BOX NOW parcel per `items[]` entry.
		// Cancelling only the number Core knows about would leave the rest live
		// at BOX NOW while the shop believes the shipment is gone.
		$ids = (array) $this->label_meta( $order, 'parcel_ids', array() );
		if ( empty( $ids ) ) {
			$ids = array( $number );
		}

		$failed = array();
		foreach ( $ids as $id ) {
			$response = $this->client()->cancel_parcel( (string) $id );
			if ( is_wp_error( $response ) || false === $response ) {
				$failed[] = (string) $id . ( is_wp_error( $response ) ? ' (' . $response->get_error_message() . ')' : '' );
			}
		}

		if ( ! empty( $failed ) ) {
			return new \WP_Error(
				'bgcs3_boxnow_cancel_partial',
				sprintf(
					/* translators: %s comma-separated parcel ids. */
					__( 'BOX NOW did not cancel all shipments for the order. Still active: %s. Cancel them from the partner portal.', 'bg-commerce-suite' ),
					implode( ', ', $failed )
				)
			);
		}

		return true;
	}

	/**
	 * Retrieve tracking state from the BoxNow API.
	 *
	 * @param string $number Parcel id.
	 * @return array<string,mixed>|\WP_Error Данните за самата пратка.
	 */
	protected function fetch_tracking( $number ) {
		$response = $this->client()->get_parcel_status( $number );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parcel = isset( $response['data'][0] ) && is_array( $response['data'][0] ) ? $response['data'][0] : null;

		if ( ! $parcel ) {
			return new \WP_Error( 'bgcs3_boxnow_parcel_missing', __( 'The shipment was not found in BOX NOW.', 'bg-commerce-suite' ) );
		}

		return $parcel;
	}

	/**
	 * @param Tracking_Result     $result Result.
	 * @param array<string,mixed> $parcel Parcel data.
	 * @return void
	 */
	protected function fill_tracking( Tracking_Result $result, array $parcel ) {
		$result->status = isset( $parcel['state'] ) ? (string) $parcel['state'] : '';

		if ( ! empty( $parcel['events'] ) && is_array( $parcel['events'] ) ) {
			foreach ( $parcel['events'] as $event ) {
				$type             = isset( $event['type'] ) ? (string) $event['type'] : '';
				$result->events[] = array(
					'time' => isset( $event['time'] ) ? (string) $event['time'] : '',
					'code' => $type,
					'text' => $this->translate_event_type( $type ),
				);
			}
		}
	}

	/**
	 * Normalize BoxNow state identifiers to a canonical BGCS shipment state
	 * (Rule 41). Exact match only — never fuzzy substring (Rule 42).
	 *
	 * @param array<string,mixed> $event Tracking/webhook event.
	 * @return string One of Tracking_State::*.
	 */
	public function normalize_status( array $event ) {
		// One vocabulary for both directions — polling and webhook must never
		// disagree about what a state means. Previously this knew five events
		// out of the published twelve, and spelled cancellation the way the
		// v1.69 YAML does (`canceled`) rather than the way the webhook document
		// and the real events do (`cancelled`), so a genuine cancellation was
		// classified as UNKNOWN.
		return Webhook::state( isset( $event['code'] ) ? $event['code'] : '' );
	}

	/**
	 * Handle one signed BOX NOW parcel event.
	 *
	 * A verified, non-stale event is persisted immediately. A provider API refresh
	 * runs afterwards only to enrich the timeline; it is not a prerequisite for
	 * accepting the push event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function handle_webhook( $request ) {
		$parsed = Webhook::parse(
			(string) $request->get_body(),
			(string) Module_Settings::get( self::ID, 'webhook_secret' )
		);

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$order = wc_get_order( $parsed['order_id'] );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'unknown_order', 'unknown_order', 202 );
		}

		// Only for orders that are actually ours — a signed message must still
		// not be able to poke an unrelated order.
		$selection = $order->get_meta( '_bgcs3_selection' );
		if ( ! is_array( $selection ) || 'boxnow' !== ( isset( $selection['courier'] ) ? $selection['courier'] : '' ) ) {
			return new \WP_Error( 'not_our_order', 'not_our_order', 202 );
		}

		// BGCS-AUDIT-007 — idempotency used to be "compare with the last event",
		// and both halves of it switched themselves off when an optional field
		// was missing. It is now "remember what has already been handled", over
		// identifiers that always exist.
		$event_time   = Tracking_Store::timestamp( isset( $parsed['time'] ) ? $parsed['time'] : '' );
		$fingerprints = isset( $parsed['fingerprints'] ) && is_array( $parsed['fingerprints'] ) ? $parsed['fingerprints'] : array();
		$seen         = $this->seen_webhook_fingerprints( $order );

		$last      = $order->get_meta( '_bgcs3_boxnow_webhook_last' );
		$last_time = is_array( $last ) && ! empty( $last['time'] ) ? Tracking_Store::timestamp( $last['time'] ) : 0;

		$duplicate = (bool) array_intersect( $fingerprints, $seen );
		$expired   = $event_time > 0 && ( time() - $event_time ) > self::webhook_max_age();
		$stale     = $event_time > 0 && $last_time > 0 && $event_time < $last_time;

		if ( $duplicate || $expired || $stale ) {
			// `expired` and `stale` are different things and are recorded
			// separately: one is history being replayed long after the fact, the
			// other is an event that arrived after a newer one had been applied.
			$this->record_webhook_history(
				$order,
				$parsed,
				$duplicate ? 'duplicate_ignored' : ( $expired ? 'expired_ignored' : 'stale_ignored' )
			);

			// Deliberately 200, not 202: BOX NOW retries until the receiver
			// answers 200 OK, so anything else asks for the same message again
			// every ten minutes for the next 24 hours. We have decided about
			// this message; there is nothing for them to redeliver.
			return true;
		}

		$last_event = array(
			'message_id'  => isset( $parsed['message_id'] ) ? sanitize_text_field( (string) $parsed['message_id'] ) : '',
			'event'       => isset( $parsed['event'] ) ? sanitize_key( (string) $parsed['event'] ) : '',
			'state'       => isset( $parsed['state'] ) ? Tracking_State::sanitize( $parsed['state'] ) : Tracking_State::UNKNOWN,
			'time'        => isset( $parsed['time'] ) ? sanitize_text_field( (string) $parsed['time'] ) : '',
			'received_at' => time(),
		);
		$order->update_meta_data( '_bgcs3_boxnow_webhook_last', $last_event );
		$order->update_meta_data( self::WEBHOOK_SEEN_META, $this->remember_webhook_fingerprints( $seen, $fingerprints ) );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: BOX NOW event name. */
				__( 'BOX NOW reported a new shipment status: %s', 'bg-commerce-suite' ),
				$parsed['event']
			)
		);

		// Apply the verified push immediately. This is intentionally BEFORE the
		// optional API refresh: a temporary API outage must not discard a valid
		// BOX NOW event. Core's Tracking_Store orders by the event's own time, so
		// a later API event can still supersede it naturally.
		do_action(
			'bgcs3_apply_pushed_tracking_event',
			$parsed['order_id'],
			self::ID,
			array(
				'time'     => ! empty( $parsed['time'] ) ? $parsed['time'] : gmdate( 'c' ),
				'code'     => $parsed['event'],
				'text'     => $this->translate_event_type( $parsed['event'] ),
				'event_id' => ! empty( $parsed['message_id'] ) ? $parsed['message_id'] : '',
			)
		);

		$this->record_webhook_history( $order, $parsed, 'applied' );

		// Secondary enrichment/verification only. The webhook event above has
		// already been persisted and does not depend on this request succeeding.
		do_action( 'bgcs3_update_order_tracking_status', $parsed['order_id'], 'webhook_refresh' );

		/**
		 * Fires after a verified BOX NOW parcel event has been applied.
		 *
		 * @param array     $parsed Parsed event.
		 * @param \WC_Order $order  Order.
		 */
		do_action( 'bgcs3_boxnow_webhook_handled', $parsed, $order );

		return true;
	}

	/**
	 * Fingerprints of the messages already handled for this order.
	 *
	 * Migrates the single `_bgcs3_boxnow_webhook_last` message id into the set on
	 * first use, so an order that is mid-flight when this ships does not accept a
	 * replay of the event it just processed.
	 *
	 * @param \WC_Order $order Order.
	 * @return string[]
	 */
	private function seen_webhook_fingerprints( \WC_Order $order ) {
		$seen = $order->get_meta( self::WEBHOOK_SEEN_META );
		$seen = is_array( $seen ) ? array_values( array_filter( array_map( 'strval', $seen ) ) ) : array();

		if ( empty( $seen ) ) {
			$last = $order->get_meta( '_bgcs3_boxnow_webhook_last' );
			if ( is_array( $last ) && ! empty( $last['message_id'] ) ) {
				$seen[] = 'id:' . (string) $last['message_id'];
			}
		}

		return $seen;
	}

	/**
	 * Add this message's fingerprints to the set, newest first, bounded.
	 *
	 * Bounded on purpose: an unlimited list in order meta is its own defect, and
	 * BOX NOW stops redelivering a message 24 hours after the event, so a window
	 * of the last {@see WEBHOOK_SEEN_LIMIT} events is far more history than any
	 * retry can reach back through.
	 *
	 * @param string[] $seen         Fingerprints already recorded.
	 * @param string[] $fingerprints Fingerprints of the message just handled.
	 * @return string[]
	 */
	private function remember_webhook_fingerprints( array $seen, array $fingerprints ) {
		$merged = array_values( array_unique( array_merge( $fingerprints, $seen ) ) );

		return array_slice( $merged, 0, self::WEBHOOK_SEEN_LIMIT );
	}

	/**
	 * How far back a parcel event may be dated and still be acted on.
	 *
	 * BOX NOW's own retry policy makes its last delivery attempt 24 hours after
	 * the event is created, so seven days is seven times their horizon: generous
	 * enough that no genuine retry is ever refused, tight enough that replaying
	 * months-old signed history cannot move an order.
	 *
	 * @return int Seconds.
	 */
	private static function webhook_max_age() {
		/**
		 * Filter how old a BOX NOW parcel event may be before it is ignored.
		 *
		 * @param int $seconds Maximum age in seconds.
		 */
		$seconds = (int) apply_filters( 'bgcs3_boxnow_webhook_max_age', self::WEBHOOK_MAX_AGE );

		// A zero or negative window would ignore everything, including live
		// events; treat a misconfigured filter as "use the default".
		return $seconds > 0 ? $seconds : self::WEBHOOK_MAX_AGE;
	}

	/**
	 * Keep a small PII-free audit trail for BOX NOW Diagnostics.
	 *
	 * @param \WC_Order           $order  Order.
	 * @param array<string,mixed> $parsed Verified parsed message.
	 * @param string              $action Applied/ignored reason.
	 * @return void
	 */
	private function record_webhook_history( \WC_Order $order, array $parsed, $action ) {
		$history = bgcs3_get_option( self::ID, '_webhook_history', array() );
		$history = is_array( $history ) ? $history : array();

		array_unshift(
			$history,
			array(
				'received_at' => time(),
				'order_id'    => (int) $order->get_id(),
				'event'       => isset( $parsed['event'] ) ? sanitize_key( (string) $parsed['event'] ) : '',
				'state'       => isset( $parsed['state'] ) ? Tracking_State::sanitize( $parsed['state'] ) : Tracking_State::UNKNOWN,
				'event_time'  => isset( $parsed['time'] ) ? sanitize_text_field( (string) $parsed['time'] ) : '',
				'message_id'  => isset( $parsed['message_id'] ) ? sanitize_text_field( (string) $parsed['message_id'] ) : '',
				'action'      => sanitize_key( (string) $action ),
			)
		);

		Options::set( self::ID, '_webhook_history', array_slice( $history, 0, 20 ) );
	}

	/**
	 * Public tracking page (t.boxnow.bg — same pattern as BoxNow's own plugin).
	 *
	 * @param string $number Parcel id.
	 * @return string
	 */
	public function tracking_url( $number ) {
		return 'https://t.boxnow.bg/?track=' . rawurlencode( (string) $number );
	}

	/**
	 * BoxNow-specific readable order fields (own custom fields on the order).
	 *
	 * @param \WC_Order                     $order     Order.
	 * @param \BgCommerce3\Support\Selection $selection Selection.
	 * @return array<string,string>
	 */
	public function order_meta_fields( \WC_Order $order, $selection ) {
		$fields = array(
			'bgcs3_boxnow_locker_id'   => isset( $selection->office['id'] ) ? (string) $selection->office['id'] : '',
			'bgcs3_boxnow_locker_name' => isset( $selection->office['text'] ) ? (string) $selection->office['text'] : '',
			'bgcs3_boxnow_city'        => isset( $selection->city['name'] ) ? (string) $selection->city['name'] : '',
		);

		$total = \BgCommerce3\Shipping\Order_Persistence::courier_shipping_total( $order, self::ID );
		if ( $total > 0 ) {
			$fields['bgcs3_boxnow_price'] = number_format( $total, 2, '.', '' );
		}

		return $fields;
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
				'desc'   => __( 'Environment and OAuth credentials from BOX NOW.', 'bg-commerce-suite' ),
				'icon'   => 'plug',
				'fields' => array( 'env', 'client_id', 'client_secret', 'partner_id', 'webhook_secret' ),
			),
			array(
				'title'  => __( 'Sender', 'bg-commerce-suite' ),
				'desc'   => __( 'Sender warehouse and contact.', 'bg-commerce-suite' ),
				'icon'   => 'user',
				'fields' => array( 'warehouse_id', 'sender_name', 'sender_phone', 'sender_email' ),
			),
			array(
				'title'  => __( 'Shipment', 'bg-commerce-suite' ),
				'desc'   => __( 'Size, weight and options. Pricing is configured in the “Pricing and free shipping” section.', 'bg-commerce-suite' ),
				'icon'   => 'package',
				'fields' => array( 'default_size', 'default_weight', 'type_of_service', 'allow_returns', 'return_location_id', 'show_recipient_info' ),
			),
			array(
				'title'  => __( 'Notifications and label', 'bg-commerce-suite' ),
				'desc'   => __( 'Who receives the voucher and what is printed on the label.', 'bg-commerce-suite' ),
				'icon'   => 'file-text',
				'fields' => array( 'voucher_email', 'notify_sms', 'label_row1', 'label_row2', 'label_row3', 'label_row4' ),
			),
			array(
				'title'  => __( 'Checkout', 'bg-commerce-suite' ),
				'desc'   => __( 'Locker selection in the order.', 'bg-commerce-suite' ),
				'icon'   => 'sliders',
				'fields' => array( 'show_locker', 'widget_gps' ),
			),
		);
	}

	/**
	 * Render the BOX NOW-owned weight pricing table inside the Core settings form.
	 */
	public function render_settings_custom() {
		$this->render_account_custom();
		$this->render_methods_custom();
	}

	/** Account-tab BOX NOW custom UI. */
	public function render_account_custom() {
		$this->render_warehouse_contacts();
	}

	/** Shipping-method-tab BOX NOW weight pricing UI. */
	public function render_methods_custom() {
		$stored = bgcs3_get_option( self::ID, 'weight_price_ranges', array() );
		$ranges = is_array( $stored ) ? Weight_Pricing::sanitize_ranges( $stored ) : array();
		$rows   = empty( $ranges ) ? array( array( 'min' => '', 'max' => '', 'price' => '' ) ) : $ranges;

		echo '<div class="bgcs-boxnow-weight-pricing">';
		echo '<div class="bgcs-boxnow-weight-pricing__intro"><strong>' . esc_html__( 'Weight-based pricing', 'bg-commerce-suite' ) . '</strong>';
		echo '<p class="bgcs-boxnow-weight-pricing__help">' . esc_html__( 'BOX NOW uses only these ranges for paid shipping. Free shipping, when configured, takes priority.', 'bg-commerce-suite' ) . '</p></div>';
		echo '<p id="bgcs-boxnow-weight-help" class="bgcs-boxnow-weight-pricing__help">' . esc_html__( 'Range boundaries may touch: with 0–3 and 3–10 kg, exactly 3 kg uses the second row. Leave the final “To” field empty for a price with no upper limit.', 'bg-commerce-suite' ) . '</p>';
		echo '<div class="bgcs-boxnow-weight-pricing__table-wrap">';
		echo '<table class="bgcs-boxnow-weight-pricing__table">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'From (kg)', 'bg-commerce-suite' ) . '</th><th scope="col">' . esc_html__( 'To (kg)', 'bg-commerce-suite' ) . '</th><th scope="col">' . esc_html__( 'Price', 'bg-commerce-suite' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'bg-commerce-suite' ) . '</span></th></tr></thead>';
		echo '<tbody data-boxnow-weight-rows>';
		foreach ( $rows as $index => $range ) {
			$this->render_weight_price_row( $range, $index );
		}
		echo '</tbody></table></div>';
		echo '<button type="button" class="bgcs-btn bgcs-btn--outline bgcs-btn--sm" data-boxnow-add-weight-row>+ ' . esc_html__( 'Add range', 'bg-commerce-suite' ) . '</button>';
		echo '<template data-boxnow-weight-row-template>';
		$this->render_weight_price_row( array( 'min' => '', 'max' => '', 'price' => '' ), '__index__' );
		echo '</template>';
		echo '</div>';
	}

	/**
	 * Save the BOX NOW-owned weight pricing rows. Core has already verified the
	 * settings nonce and manage_woocommerce capability before calling this method.
	 */
	public function save_settings_custom( $scope = '' ) {
		$scope = sanitize_key( (string) $scope );

		if ( '' === $scope || 'methods' === $scope ) {
			$raw = isset( $_POST['boxnow_weight_ranges'] ) && is_array( $_POST['boxnow_weight_ranges'] )
				? wp_unslash( $_POST['boxnow_weight_ranges'] )
				: array();
			Options::set( self::ID, 'weight_price_ranges', Weight_Pricing::sanitize_ranges( $raw ) );
		}

		if ( '' === $scope || 'account' === $scope ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verified the settings nonce before calling this.
			$warehouses = isset( $_POST['boxnow_warehouses'] ) && is_array( $_POST['boxnow_warehouses'] )
				? wp_unslash( $_POST['boxnow_warehouses'] )
				: array();

			$clean = array();
			foreach ( Warehouses::sanitize_rows( $warehouses ) as $row ) {
				$clean[] = array(
					'id'    => sanitize_text_field( $row['id'] ),
					'name'  => sanitize_text_field( $row['name'] ),
					'phone' => sanitize_text_field( $row['phone'] ),
					'email' => sanitize_email( $row['email'] ),
				);
			}
			Options::set( self::ID, 'warehouse_contacts', $clean );
		}

		// Account credential checks save the new OAuth fields first, then land
		// here with scope=connection. Flush only account-scoped caches before the
		// provider call so the check can never reuse a token/profile from old
		// credentials. A plain account save gets the same protection.
		if ( in_array( $scope, array( '', 'account', 'connection' ), true ) ) {
			Cache::flush_courier( self::ID );

			$current_fingerprint = $this->account_config_fingerprint();
			$checked_fingerprint = (string) bgcs3_get_option( self::ID, '_checked_account_fingerprint', '' );
			if ( '' === $checked_fingerprint || ! hash_equals( $checked_fingerprint, $current_fingerprint ) ) {
				Options::set( self::ID, '_partners', array() );
				Options::set( self::ID, '_account_profile', array() );
				Options::set( self::ID, '_origin_options', array() );
				Options::set( self::ID, '_api_health', array( 'ok' => false, 'at' => time(), 'needs_check' => true ) );
			}

			$partners  = bgcs3_get_option( self::ID, '_partners', array() );
			$partner_id = (string) bgcs3_get_option( self::ID, 'partner_id', '' );
			if ( is_array( $partners ) && '' !== $partner_id && isset( $partners[ $partner_id ] ) && is_array( $partners[ $partner_id ] ) ) {
				Options::set( self::ID, '_account_profile', $partners[ $partner_id ] );
			}
		}
	}

	/**
	 * „Складове и контакти за вземане“ — one pickup contact per warehouse.
	 *
	 * BOX NOW's `/origins` gives a warehouse's id and address but no contact,
	 * and an order can be routed out of any warehouse from its own panel, so
	 * without this the courier is sent to the right building with another
	 * warehouse's phone number. An empty table means every warehouse uses the
	 * shop-wide sender, which is what a single-warehouse shop wants.
	 */
	private function render_warehouse_contacts() {
		$rows = Warehouses::sanitize_rows( bgcs3_get_option( self::ID, 'warehouse_contacts', array() ) );

		// Without credentials there is nothing to ask BOX NOW for, so the rows
		// fall back to a plain id field instead of the shop waiting on an API
		// call that can only fail.
		$origins = bgcs3_get_option( self::ID, '_origin_options', array() );
		$origins = is_array( $origins ) ? $origins : array();

		echo '<section class="bgcs-card bgcs-card--standalone bgcs-boxnow-warehouses">';
		echo '<div class="bgcs-card__head">';
		echo '<span class="bgcs-card__icon">' . Icons::svg( 'package', 20 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="bgcs-card__titles"><span class="bgcs-card__title">' . esc_html__( 'Warehouses and pickup contacts', 'bg-commerce-suite' ) . '</span>';
		echo '<span class="bgcs-card__desc">' . esc_html__( 'Contact person, phone and email for BOX NOW to use for each warehouse separately.', 'bg-commerce-suite' ) . '</span></span>';
		echo '</div>';
		echo '<div class="bgcs-card__body">';

		echo '<p id="bgcs-boxnow-warehouse-help" class="bgcs-boxnow-warehouses__help">' . esc_html__( 'Add a row only for a warehouse with a different contact. An empty field inherits the value from the “Sender” section, and an empty table means all warehouses use those values.', 'bg-commerce-suite' ) . '</p>';

		echo '<div class="bgcs-boxnow-warehouses__table-wrap"><table class="bgcs-boxnow-warehouses__table">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Warehouse', 'bg-commerce-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Contact person', 'bg-commerce-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Phone', 'bg-commerce-suite' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Email', 'bg-commerce-suite' ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'bg-commerce-suite' ) . '</span></th>';
		echo '</tr></thead><tbody data-boxnow-warehouse-rows>';

		foreach ( $rows as $index => $row ) {
			$this->render_warehouse_row( $row, $index, $origins );
		}

		echo '</tbody></table></div>';
		echo '<button type="button" class="bgcs-btn bgcs-btn--outline bgcs-btn--sm" data-boxnow-add-warehouse-row>+ ' . esc_html__( 'Add warehouse', 'bg-commerce-suite' ) . '</button>';

		echo '<template data-boxnow-warehouse-row-template>';
		$this->render_warehouse_row( array(), '__index__', $origins );
		echo '</template>';

		echo '</div></section>';
	}

	/**
	 * One editable warehouse-contact row.
	 *
	 * @param array<string,string> $row     Stored values.
	 * @param int|string           $index   Row index or the template placeholder.
	 * @param array<string,string> $origins Warehouses from `/origins`.
	 */
	private function render_warehouse_row( array $row, $index, array $origins ) {
		$id     = isset( $row['id'] ) ? (string) $row['id'] : '';
		$name   = isset( $row['name'] ) ? (string) $row['name'] : '';
		$phone  = isset( $row['phone'] ) ? (string) $row['phone'] : '';
		$email  = isset( $row['email'] ) ? (string) $row['email'] : '';
		$base   = 'boxnow_warehouses[' . $index . ']';
		$suffix = (string) $index;

		echo '<tr data-boxnow-warehouse-row>';

		echo '<td data-label="' . esc_attr__( 'Warehouse', 'bg-commerce-suite' ) . '">';
		echo '<label class="screen-reader-text" for="bgcs-boxnow-wh-id-' . esc_attr( $suffix ) . '">' . esc_html__( 'Sender warehouse', 'bg-commerce-suite' ) . '</label>';

		if ( ! empty( $origins ) ) {
			// The ids come from BOX NOW, so a dropdown removes a whole class of
			// typo that would otherwise surface only as P401 at label time.
			echo '<select id="bgcs-boxnow-wh-id-' . esc_attr( $suffix ) . '" name="' . esc_attr( $base . '[id]' ) . '" aria-describedby="bgcs-boxnow-warehouse-help">';
			echo '<option value="">' . esc_html__( '— Select a warehouse —', 'bg-commerce-suite' ) . '</option>';
			foreach ( $origins as $origin_id => $label ) {
				echo '<option value="' . esc_attr( $origin_id ) . '"' . selected( (string) $origin_id, $id, false ) . '>' . esc_html( $label ) . '</option>';
			}
			// A warehouse that has since left the account must not disappear
			// from the table silently, taking its contact with it.
			if ( '' !== $id && ! array_key_exists( $id, $origins ) ) {
				/* translators: %s: warehouse id. */
				echo '<option value="' . esc_attr( $id ) . '" selected="selected">' . esc_html( sprintf( __( 'ID %s (no longer in the account)', 'bg-commerce-suite' ), $id ) ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input id="bgcs-boxnow-wh-id-' . esc_attr( $suffix ) . '" type="text" name="' . esc_attr( $base . '[id]' ) . '" value="' . esc_attr( $id ) . '" placeholder="' . esc_attr__( 'Warehouse ID', 'bg-commerce-suite' ) . '" aria-describedby="bgcs-boxnow-warehouse-help" />';
		}
		echo '</td>';

		echo '<td data-label="' . esc_attr__( 'Contact person', 'bg-commerce-suite' ) . '"><label class="screen-reader-text" for="bgcs-boxnow-wh-name-' . esc_attr( $suffix ) . '">' . esc_html__( 'Contact person', 'bg-commerce-suite' ) . '</label><input id="bgcs-boxnow-wh-name-' . esc_attr( $suffix ) . '" type="text" name="' . esc_attr( $base . '[name]' ) . '" value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'same as “Sender”', 'bg-commerce-suite' ) . '" /></td>';

		echo '<td data-label="' . esc_attr__( 'Phone', 'bg-commerce-suite' ) . '"><label class="screen-reader-text" for="bgcs-boxnow-wh-phone-' . esc_attr( $suffix ) . '">' . esc_html__( 'Phone', 'bg-commerce-suite' ) . '</label><input id="bgcs-boxnow-wh-phone-' . esc_attr( $suffix ) . '" type="text" name="' . esc_attr( $base . '[phone]' ) . '" value="' . esc_attr( $phone ) . '" placeholder="+359XXXXXXXXX" /></td>';

		echo '<td data-label="' . esc_attr__( 'Email', 'bg-commerce-suite' ) . '"><label class="screen-reader-text" for="bgcs-boxnow-wh-email-' . esc_attr( $suffix ) . '">' . esc_html__( 'Email', 'bg-commerce-suite' ) . '</label><input id="bgcs-boxnow-wh-email-' . esc_attr( $suffix ) . '" type="email" name="' . esc_attr( $base . '[email]' ) . '" value="' . esc_attr( $email ) . '" /></td>';

		echo '<td class="bgcs-boxnow-warehouses__actions"><button type="button" class="bgcs-btn bgcs-btn--outline bgcs-btn--sm" data-boxnow-remove-warehouse-row>' . Icons::svg( 'trash', 16 ) . '<span>' . esc_html__( 'Remove', 'bg-commerce-suite' ) . '</span></button></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</tr>';
	}

	/**
	 * Render one editable range row.
	 *
	 * @param array<string,mixed> $range Range values.
	 * @param int|string          $index Row index or template placeholder.
	 */
	private function render_weight_price_row( array $range, $index ) {
		$min   = isset( $range['min'] ) ? (string) $range['min'] : '';
		$max   = isset( $range['max'] ) ? (string) $range['max'] : '';
		$price = isset( $range['price'] ) ? (string) $range['price'] : '';
		$base  = 'boxnow_weight_ranges[' . $index . ']';
		$suffix = (string) $index;

		echo '<tr data-boxnow-weight-row>';
		echo '<td data-label="' . esc_attr__( 'From (kg)', 'bg-commerce-suite' ) . '"><label class="screen-reader-text" for="bgcs-boxnow-weight-min-' . esc_attr( $suffix ) . '">' . esc_html__( 'Minimum weight in kg', 'bg-commerce-suite' ) . '</label><input id="bgcs-boxnow-weight-min-' . esc_attr( $suffix ) . '" type="number" min="0" step="0.001" inputmode="decimal" name="' . esc_attr( $base . '[min]' ) . '" value="' . esc_attr( $min ) . '" aria-describedby="bgcs-boxnow-weight-help" /></td>';
		echo '<td data-label="' . esc_attr__( 'To (kg)', 'bg-commerce-suite' ) . '"><label class="screen-reader-text" for="bgcs-boxnow-weight-max-' . esc_attr( $suffix ) . '">' . esc_html__( 'Maximum weight in kg', 'bg-commerce-suite' ) . '</label><input id="bgcs-boxnow-weight-max-' . esc_attr( $suffix ) . '" type="number" min="0.001" step="0.001" inputmode="decimal" name="' . esc_attr( $base . '[max]' ) . '" value="' . esc_attr( $max ) . '" placeholder="∞" aria-describedby="bgcs-boxnow-weight-help" /></td>';
		echo '<td data-label="' . esc_attr__( 'Price', 'bg-commerce-suite' ) . '"><label class="screen-reader-text" for="bgcs-boxnow-weight-price-' . esc_attr( $suffix ) . '">' . esc_html__( 'Range price', 'bg-commerce-suite' ) . '</label><span class="bgcs-boxnow-weight-pricing__price"><input id="bgcs-boxnow-weight-price-' . esc_attr( $suffix ) . '" type="number" min="0.01" step="0.01" inputmode="decimal" name="' . esc_attr( $base . '[price]' ) . '" value="' . esc_attr( $price ) . '" aria-describedby="bgcs-boxnow-weight-help" /><span aria-hidden="true">' . esc_html( get_woocommerce_currency_symbol() ) . '</span></span></td>';
		echo '<td class="bgcs-boxnow-weight-pricing__actions"><button type="button" class="bgcs-btn bgcs-btn--outline bgcs-btn--sm" data-boxnow-remove-weight-row>' . Icons::svg( 'trash', 16 ) . '<span>' . esc_html__( 'Remove', 'bg-commerce-suite' ) . '</span></button></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</tr>';
	}

	/**
	 * Translate BoxNow tracking event type to Bulgarian description.
	 *
	 * @param string $type BoxNow event type code.
	 * @return string Bulgarian description.
	 */
	private function translate_event_type( $type ) {
		$map = array(
			'new'                 => __( 'The shipment was registered in the system.', 'bg-commerce-suite' ),
			'delivered'           => __( 'The shipment was delivered to the recipient.', 'bg-commerce-suite' ),
			'expired'             => __( 'The pickup period has expired and the shipment is being returned.', 'bg-commerce-suite' ),
			'returned'            => __( 'The shipment was returned to the sender.', 'bg-commerce-suite' ),
			'in-depot'            => __( 'The shipment arrived at a BOX NOW depot.', 'bg-commerce-suite' ),
			'final-destination'   => __( 'The shipment is in the destination locker and is waiting for pickup.', 'bg-commerce-suite' ),
			'canceled'            => __( 'The shipment was cancelled by the sender.', 'bg-commerce-suite' ),
			'accepted-for-return' => __( 'The shipment was accepted in a return locker.', 'bg-commerce-suite' ),
			'missing'             => __( 'The shipment has not been collected by the BOX NOW courier.', 'bg-commerce-suite' ),
			'accepted-to-locker'  => __( 'The shipment was placed by the sender in the origin locker.', 'bg-commerce-suite' ),
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : $type;
	}

	/**
	 * Format phone number to international shape +XXХ123456789.
	 *
	 * @param string $phone Raw phone string.
	 * @return string Formatted phone.
	 */
	private function normalize_phone( $phone ) {
		$phone = preg_replace( '/[^0-9+]/', '', $phone );

		if ( strpos( $phone, '+' ) !== 0 ) {
			if ( strpos( $phone, '359' ) === 0 ) {
				$phone = '+' . $phone;
			} elseif ( strpos( $phone, '0' ) === 0 ) {
				$phone = '+359' . substr( $phone, 1 );
			} else {
				$phone = '+359' . $phone;
			}
		}

		return $phone;
	}
}
