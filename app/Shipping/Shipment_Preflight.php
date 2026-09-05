<?php
/**
 * Structured, non-destructive shipment preflight shared by all couriers.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Support\Label_Result;
use BgCommerce3\Support\Module_Settings;
use BgCommerce3\Support\Selection;

defined( 'ABSPATH' ) || exit;

final class Shipment_Preflight {

	const META_KEY = '_bgcs3_preflight';

	/** @var \WC_Order */
	private $order;

	/** @var Courier_Interface */
	private $courier;

	/** @var array<string,mixed> */
	private $snapshot = array();

	/** @var bool */
	private $defer_persist = false;

	/**
	 * @param \WC_Order        $order   Order.
	 * @param Courier_Interface $courier Courier module.
	 */
	private function __construct( \WC_Order $order, Courier_Interface $courier ) {
		$this->order   = $order;
		$this->courier = $courier;
	}

	/**
	 * Build and persist the common preflight intent. Provider-specific modules
	 * may add sections before marking the payload ready.
	 *
	 * @param \WC_Order        $order   Order.
	 * @param Courier_Interface $courier Courier module.
	 * @return self
	 */
	public static function begin( \WC_Order $order, Courier_Interface $courier ) {
		$self = new self( $order, $courier );
		$self->capture_common_intent();
		$self->defer_persist = true;
		$self->validate_common_intent();
		$self->defer_persist = false;
		$self->persist();
		return $self;
	}

	/**
	 * Add a PII-free courier-owned section.
	 *
	 * @param string              $section Section key.
	 * @param array<string,mixed> $data    Structural facts only.
	 * @return self
	 */
	public function section( $section, array $data ) {
		$this->snapshot[ sanitize_key( $section ) ] = $this->structural_facts( $data );
		return $this;
	}

	/**
	 * Record the shape and fingerprint of the exact request without persisting
	 * customer data from the request body.
	 *
	 * @param array<string,mixed> $payload Courier request body.
	 * @return self
	 */
	public function payload_ready( array $payload ) {
		$encoded = wp_json_encode( $payload );
		$keys    = array_map( 'strval', array_keys( $payload ) );
		sort( $keys, SORT_STRING );

		$this->snapshot['payload'] = array(
			'ready'        => ! empty( $payload ),
			'top_level'    => $keys,
			'fingerprint'  => is_string( $encoded ) ? hash( 'sha256', $encoded ) : '',
		);

		if ( empty( $payload ) ) {
			$this->block( 'empty_payload', __( 'The courier shipment payload is empty.', 'bg-commerce-suite' ) );
		}

		$this->snapshot['status'] = $this->is_blocked() ? 'blocked' : 'ready';
		$this->persist();
		return $this;
	}

	/**
	 * @param string $code    Stable internal code.
	 * @param string $message Merchant-safe message.
	 * @return self
	 */
	public function block( $code, $message ) {
		$this->snapshot['blocking_errors'][] = array(
			'code'    => sanitize_key( $code ),
			'message' => sanitize_text_field( $message ),
		);
		$this->snapshot['status'] = 'blocked';
		if ( ! $this->defer_persist ) {
			$this->persist();
		}
		return $this;
	}

	/**
	 * @param string $code    Stable internal code.
	 * @param string $message Merchant-safe message.
	 * @return self
	 */
	public function warning( $code, $message ) {
		$this->snapshot['warnings'][] = array(
			'code'    => sanitize_key( $code ),
			'message' => sanitize_text_field( $message ),
		);
		if ( ! $this->defer_persist ) {
			$this->persist();
		}
		return $this;
	}

	/** @return bool */
	public function is_blocked() {
		return ! empty( $this->snapshot['blocking_errors'] );
	}

	/** @return array<string,mixed> */
	public function snapshot() {
		return $this->snapshot;
	}

	/** @return Label_Result */
	public function label_error() {
		$messages = array();
		foreach ( (array) $this->snapshot['blocking_errors'] as $error ) {
			if ( is_array( $error ) && ! empty( $error['message'] ) ) {
				$messages[] = (string) $error['message'];
			}
		}
		return Label_Result::error( implode( ' ', array_unique( $messages ) ) );
	}

	/**
	 * Persist a provider-specific refusal through the same structured contract.
	 *
	 * @param Label_Result $result Existing courier validation error.
	 * @param string       $code   Stable error code.
	 * @return Label_Result
	 */
	public function reject( Label_Result $result, $code = 'courier_validation' ) {
		// The full message is returned to the authorized administrator through the
		// normal Label_Result. The persisted snapshot keeps only a stable category:
		// provider prose can echo names, addresses, credentials or account ids.
		$this->block( $code, __( 'Courier-specific shipment validation failed. Review the create-shipment error shown to the administrator.', 'bg-commerce-suite' ) );
		return $result;
	}

	/** @return void */
	private function capture_common_intent() {
		$courier_id = sanitize_key( $this->courier->id() );
		$raw        = $this->order->get_meta( '_bgcs3_selection' );
		$raw        = is_array( $raw ) ? $raw : array();
		$selection  = Selection::from_array( $raw );
		$wb         = $this->order->get_meta( '_bgcs3_wb' );
		$wb         = is_array( $wb ) ? $wb : array();
		$rows       = isset( $wb['packages'] ) && is_array( $wb['packages'] ) ? array_values( $wb['packages'] ) : array();
		$weight     = isset( $wb['weight'] ) && is_numeric( $wb['weight'] )
			? (float) $wb['weight']
			: Weight::for_order( $courier_id, $this->order );
		$name       = ! empty( $wb['contact_name'] ) ? trim( (string) $wb['contact_name'] ) : '';
		if ( '' === $name && method_exists( $this->order, 'get_formatted_shipping_full_name' ) ) {
			$name = trim( (string) $this->order->get_formatted_shipping_full_name() );
		}
		if ( '' === $name ) {
			$name = trim( (string) $this->order->get_formatted_billing_full_name() );
		}
		$phone      = ! empty( $wb['phone'] ) ? trim( (string) $wb['phone'] ) : trim( (string) $this->order->get_billing_phone() );
		if ( '' === $phone && method_exists( $this->order, 'get_shipping_phone' ) ) {
			$phone = trim( (string) $this->order->get_shipping_phone() );
		}
		$email = ! empty( $wb['email'] ) ? trim( (string) $wb['email'] ) : trim( (string) $this->order->get_billing_email() );

		$this->snapshot = array(
			'schema'          => 1,
			'status'          => 'checking',
			'courier'         => $courier_id,
			'environment'     => method_exists( $this->courier, 'preflight_environment' ) ? (string) $this->courier->preflight_environment() : (string) Module_Settings::get( $courier_id, 'env' ),
			'selection'       => array(
				'courier'       => $selection->courier,
				'delivery_type' => $selection->delivery_type,
				'country'       => $selection->country,
				'city_present'  => ! empty( $selection->city['id'] ),
				'office_present' => ! empty( $selection->office['id'] ),
				'address_ready' => 'address' === $selection->delivery_type && ! empty( $selection->address['street'] ),
			),
			'package'         => array(
				'weight_kg'          => round( $weight, 3 ),
				'parcel_count'       => ! empty( $rows ) ? count( $rows ) : 1,
				'explicit_packages'  => ! empty( $rows ),
				'contents_present'   => '' !== trim( isset( $wb['contents'] ) ? (string) $wb['contents'] : '' ) || count( $this->order->get_items( 'line_item' ) ) > 0,
			),
			'payment'         => array(
				'method'     => (string) $this->order->get_payment_method(),
				'currency'   => (string) $this->order->get_currency(),
				'cod_amount' => Cod::resolve_amount( $this->order, $wb ),
			),
			'payer'           => array(
				'courier_service' => ! empty( $wb['payer'] ) ? strtoupper( (string) $wb['payer'] ) : '',
				'cod_pmt'         => '',
				'package'         => '',
				'declared_value'  => '',
			),
			'recipient'       => array(
				'type'          => '' !== trim( (string) $this->order->get_billing_company() ) ? 'company' : 'private',
				'name_present'  => '' !== $name,
				'phone_present' => '' !== $phone,
				'email_present' => '' !== $email,
			),
			'extras'          => $this->extras_presence( $selection, $wb ),
			'warnings'        => array(),
			'blocking_errors' => array(),
			'payload'         => array( 'ready' => false, 'top_level' => array(), 'fingerprint' => '' ),
			'checked_at'      => time(),
		);
	}

	/** @return void */
	private function validate_common_intent() {
		$selection = $this->snapshot['selection'];
		$package   = $this->snapshot['package'];
		$recipient = $this->snapshot['recipient'];

		if ( '' === $selection['courier'] || $selection['courier'] !== $this->snapshot['courier'] ) {
			$this->block( 'selection_courier_mismatch', __( 'The saved courier selection does not match the courier that would create the shipment.', 'bg-commerce-suite' ) );
		}
		if ( ! in_array( $selection['delivery_type'], (array) $this->courier->delivery_types(), true ) ) {
			$this->block( 'delivery_type_unavailable', __( 'The saved delivery type is not supported by this courier.', 'bg-commerce-suite' ) );
		} elseif ( in_array( $selection['delivery_type'], array( 'office', 'locker' ), true ) && empty( $selection['office_present'] ) ) {
			$this->block( 'destination_missing', __( 'The order has no saved destination office or locker.', 'bg-commerce-suite' ) );
		} elseif ( 'address' === $selection['delivery_type'] && ( empty( $selection['city_present'] ) || ! $selection['address_ready'] ) ) {
			$this->block( 'destination_missing', __( 'The order has no complete saved delivery address.', 'bg-commerce-suite' ) );
		}
		if ( $package['weight_kg'] <= 0 ) {
			$this->block( 'invalid_weight', __( 'Shipment weight must be greater than zero.', 'bg-commerce-suite' ) );
		}
		$wb = $this->order->get_meta( '_bgcs3_wb' );
		$wb = is_array( $wb ) ? $wb : array();
		$invalid_row = Package_Dimensions::invalid_complete_row( isset( $wb['packages'] ) ? $wb['packages'] : array() );
		if ( $invalid_row > 0 ) {
			$this->block( 'invalid_package_row', sprintf( __( 'Shipment package %d has missing or non-positive dimensions/weight.', 'bg-commerce-suite' ), $invalid_row ) );
		}
		if ( empty( $package['contents_present'] ) ) {
			$this->block( 'contents_missing', __( 'Shipment contents are missing.', 'bg-commerce-suite' ) );
		}
		if ( empty( $recipient['name_present'] ) ) {
			$this->block( 'recipient_name_missing', __( 'The shipment recipient name is missing.', 'bg-commerce-suite' ) );
		}
		if ( empty( $recipient['phone_present'] ) ) {
			$this->block( 'recipient_phone_missing', __( 'The shipment recipient phone is missing.', 'bg-commerce-suite' ) );
		}
		$client = $this->courier->client();
		if ( is_object( $client ) && method_exists( $client, 'has_credentials' ) && ! $client->has_credentials() ) {
			$this->block( 'credentials_missing', __( 'The courier API credentials are incomplete.', 'bg-commerce-suite' ) );
		}
	}

	/**
	 * Keep courier sections structural. Identifiers and contact-like values are
	 * reduced to presence so future module changes cannot turn order meta into a
	 * second copy of provider/customer data.
	 *
	 * @param array<string|int,mixed> $data Section facts.
	 * @return array<string|int,mixed>
	 */
	private function structural_facts( array $data ) {
		if ( empty( $data ) ) {
			return array();
		}

		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		$out     = array();
		foreach ( $data as $key => $value ) {
			if ( $is_list ) {
				$out[] = is_array( $value ) ? $this->structural_facts( $value ) : $value;
				continue;
			}

			$safe_key = sanitize_key( (string) $key );
			if ( '' === $safe_key ) {
				continue;
			}
			$is_presence  = (bool) preg_match( '/_(?:present|ready)$/', $safe_key );
			$is_sensitive = (bool) preg_match( '/_id$/', $safe_key )
				|| (bool) preg_match( '/(?:^|_)(?:name|phone|email|address|credential|credentials|password|secret|token)$/', $safe_key );
			if ( ! $is_presence && $is_sensitive ) {
				$presence_key         = preg_replace( '/_id$/', '', $safe_key ) . '_present';
				$out[ $presence_key ] = ! empty( $value );
				continue;
			}
			$out[ $safe_key ] = is_array( $value ) ? $this->structural_facts( $value ) : $value;
		}
		return $out;
	}

	/**
	 * Store only option names and presence, never arbitrary values. Numeric
	 * courier extras can still be phone numbers or external customer ids.
	 *
	 * @param Selection           $selection Selection.
	 * @param array<string,mixed> $wb        Waybill settings.
	 * @return array<string,mixed>
	 */
	private function extras_presence( Selection $selection, array $wb ) {
		$out = array();
		foreach ( array_merge( (array) $selection->extras, isset( $wb['x'] ) && is_array( $wb['x'] ) ? $wb['x'] : array() ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = is_bool( $value ) ? $value : '' !== trim( (string) $value );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** @return void */
	private function persist() {
		$this->order->update_meta_data( self::META_KEY, $this->snapshot );
		$this->order->save();
	}
}
