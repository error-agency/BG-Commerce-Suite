<?php
/**
 * Pigeon Express courier module. Office / locker (APS) / address delivery via
 * the Pigeon Express REST API (X-API-Key / X-API-Secret). Pricing via
 * /shipments/calculate, label via /shipments (base64 PDF), tracking via
 * /shipments/{ref}/track, cancel via /shipments/{ref}/cancel.
 *
 * @package BgCommerce3\Pigeon
 */

namespace BgCommerce3\Modules\Shipping\Pigeon;

use BgCommerce3\Admin\Icons;
use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Abstract_Courier;
use BgCommerce3\Shipping\Cod;
use BgCommerce3\Shipping\Financial_Invariants;
use BgCommerce3\Shipping\Hooks as Shipping_Hooks;
use BgCommerce3\Shipping\Office_Store;
use BgCommerce3\Shipping\Package_Dimensions;
use BgCommerce3\Shipping\Pricing;
use BgCommerce3\Shipping\Pickup_Request;
use BgCommerce3\Shipping\Setup_Status;
use BgCommerce3\Shipping\Shipment_Creation;
use BgCommerce3\Shipping\Shipment_Reference;
use BgCommerce3\Shipping\Overrides;
use BgCommerce3\Shipping\Tracking_State;
use BgCommerce3\Shipping\Weight;
use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Price_Result;
use BgCommerce3\Support\Label_Result;
use BgCommerce3\Support\Tracking_Result;
use BgCommerce3\Support\Label_Pdf_Store;
use BgCommerce3\Support\Cache;
use BgCommerce3\Support\Sync_Result;
use BgCommerce3\Support\Options;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

class Pigeon extends Abstract_Courier {

	const ID = 'pigeon';

	/** @var Client|null */
	private $client;

	/** @var Locations|null */
	private $locations;

	public function id() {
		return self::ID;
	}

	public function name() {
		return __( 'Pigeon Express', 'bg-commerce-suite' );
	}

	/**
	 * Whether Pigeon API credentials are configured.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return $this->client()->has_credentials();
	}

	/**
	 * Explicit read-only connection check for the shared courier workspace.
	 *
	 * @return Sync_Result
	 */
	public function check_connection() {
		if ( ! $this->has_credentials() ) {
			Options::set( self::ID, '_api_health', array( 'ok' => false, 'at' => time() ) );
			return Sync_Result::error( __( 'Pigeon API key and/or API secret is missing.', 'bg-commerce-suite' ) );
		}

		$result = $this->client()->get_services();
		if ( is_wp_error( $result ) ) {
			Options::set( self::ID, '_api_health', array( 'ok' => false, 'at' => time() ) );
			return Sync_Result::error( __( 'Pigeon Express did not confirm API access.', 'bg-commerce-suite' ), array( $result->get_error_message() ) );
		}

		$count = 0;
		if ( is_array( $result ) ) {
			$list  = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
			$count = count( $list );
		}
		Options::set( self::ID, '_api_health', array( 'ok' => true, 'at' => time(), 'services' => $count ) );

		return Sync_Result::success( __( 'The Pigeon Express connection is successful.', 'bg-commerce-suite' ) );
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
				$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_FAIL, __( 'The last Pigeon Express connection check failed.', 'bg-commerce-suite' ) );
			} else {
				$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_WARN, __( 'The API credentials are saved, but the connection has not been checked yet.', 'bg-commerce-suite' ) );
			}
		} else {
			$rows[] = Setup_Status::row( 'api', __( 'API connection', 'bg-commerce-suite' ), Setup_Status::STATE_FAIL, __( 'Enter the API key and API secret.', 'bg-commerce-suite' ) );
		}

		$pickup_type = (string) Module_Settings::get( self::ID, 'pickup_type' );
		if ( 'address' === $pickup_type ) {
			$sender_ok = (int) Module_Settings::get( self::ID, 'sender_city_id' ) > 0;
			$sender_hint = __( 'Select the sender city for address pickup.', 'bg-commerce-suite' );
		} else {
			$sender_ok = (int) bgcs3_get_option( self::ID, 'sender_office_id', 0 ) > 0;
			$sender_hint = __( 'Select a Pigeon sender office.', 'bg-commerce-suite' );
		}
		$rows[] = Setup_Status::row(
			'sender',
			__( 'Sender', 'bg-commerce-suite' ),
			$sender_ok ? Setup_Status::STATE_OK : Setup_Status::STATE_FAIL,
			$sender_ok ? '' : $sender_hint
		);

		$active_types = $this->delivery_types();
		$missing_locations = array();
		if ( in_array( 'office', $active_types, true ) && ! Office_Store::has( self::ID, 'office' ) ) {
			$missing_locations[] = __( 'offices', 'bg-commerce-suite' );
		}
		if ( in_array( 'locker', $active_types, true ) && ! Office_Store::has( self::ID, 'locker' ) ) {
			$missing_locations[] = __( 'lockers', 'bg-commerce-suite' );
		}
		$rows[] = Setup_Status::row(
			'locations',
			__( 'Offices and lockers', 'bg-commerce-suite' ),
			empty( $missing_locations ) ? Setup_Status::STATE_OK : Setup_Status::STATE_WARN,
			/* translators: %s: comma-separated list of missing Pigeon location datasets. */
			empty( $missing_locations ) ? '' : sprintf( __( 'Synchronize the missing locations: %s.', 'bg-commerce-suite' ), implode( ', ', $missing_locations ) )
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
	 * Optional MetaBox capability (duck-typed, not part of Courier_Interface —
	 * no Module API change): `packages()` already sends a genuine array of
	 * `{weight,width,length,height}` entries to `/shipments` — it previously
	 * just split one shared weight/dims across every entry. Real per-pack
	 * values are already accepted by Pigeon's own request shape.
	 *
	 * @return bool
	 */
	public function supports_multi_pack() {
		return true;
	}

	/**
	 * Per-order Pigeon fields, stored by Core under `_bgcs3_wb['x']`.
	 *
	 * Every one of these is a property of `POST /v1/shipments` that until 1.4.0
	 * could only be set shop-wide or not at all: the service type was hardcoded
	 * to `standard`, so express was unreachable, and the add-on services were
	 * all-or-nothing across every order. A blank value means „as in the
	 * settings“, so an untouched panel changes nothing about what is sent.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function waybill_fields() {
		$inherit = array(
			''    => __( 'Use settings', 'bg-commerce-suite' ),
			'yes' => __( 'Yes', 'bg-commerce-suite' ),
			'no'  => __( 'No', 'bg-commerce-suite' ),
		);

		$fields = array(
			'pickup_type'           => array(
				'group'       => 'extra',
				'type'        => 'select',
				'label'       => __( 'Sender pickup type', 'bg-commerce-suite' ),
				'options'     => array(
					''        => __( 'Use settings', 'bg-commerce-suite' ),
					'office'  => __( 'Office', 'bg-commerce-suite' ),
					'address' => __( 'Address', 'bg-commerce-suite' ),
				),
				'description' => __( 'Overrides the Pigeon pickup location for this order only.', 'bg-commerce-suite' ),
			),
			'sender_office_id'      => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender office ID', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings', 'bg-commerce-suite' ),
			),
			'sender_city_id'        => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender city ID', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings', 'bg-commerce-suite' ),
			),
			'sender_street_id'      => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender street ID', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings; 0 = none', 'bg-commerce-suite' ),
			),
			'sender_address'        => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Sender address details', 'bg-commerce-suite' ),
				'placeholder' => __( 'blank = settings', 'bg-commerce-suite' ),
			),
			'service_type'          => array(
				'group'       => 'services',
				'type'        => 'select',
				'label'       => __( 'Service type', 'bg-commerce-suite' ),
				'options'     => array(
					''         => __( 'Use settings', 'bg-commerce-suite' ),
					'standard' => __( 'Standard', 'bg-commerce-suite' ),
					'express'  => __( 'Express', 'bg-commerce-suite' ),
				),
			),
			'return_at_my_expense'  => array(
				'group'       => 'services',
				'type'        => 'select',
				'label'       => __( 'Return is paid by the sender', 'bg-commerce-suite' ),
				'options'     => $inherit,
			),
			'note'                  => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Courier note', 'bg-commerce-suite' ),
				'full'        => true,
				'placeholder' => __( 'up to 1000 characters', 'bg-commerce-suite' ),
			),
			'company_name'          => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Recipient company', 'bg-commerce-suite' ),
				'description' => __( 'If you enter a company, Pigeon also requires company ID, contact person and address — otherwise the shipment is rejected.', 'bg-commerce-suite' ),
			),
			'company_vat'           => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Company ID / BULSTAT', 'bg-commerce-suite' ),
			),
			'company_mol'           => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Contact person', 'bg-commerce-suite' ),
			),
			'company_address'       => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'Company address', 'bg-commerce-suite' ),
			),
			'company_dds'           => array(
				'group'       => 'extra',
				'type'        => 'text',
				'label'       => __( 'VAT number', 'bg-commerce-suite' ),
				'description' => __( 'Optional and independent from the other company fields.', 'bg-commerce-suite' ),
			),
		);

		// Account-specific additional services must follow Pigeon's live contract:
		// checkbox => tri-state yes/no/inherit; text => actual value; select =>
		// exactly one member per option_group. COD/declared value stay on the Core
		// canonical controls so there is only one source of truth for money.
		$groups = array();
		foreach ( $this->locations()->service_definitions() as $code => $definition ) {
			if ( in_array( $code, array( 'cod_amount', 'declared_value' ), true ) ) {
				continue;
			}
			$label       = isset( $definition['label'] ) ? (string) $definition['label'] : (string) $code;
			$description = isset( $definition['description'] ) ? (string) $definition['description'] : '';
			$type        = isset( $definition['input_type'] ) ? (string) $definition['input_type'] : 'checkbox';
			$group       = isset( $definition['option_group'] ) ? trim( (string) $definition['option_group'] ) : '';

			if ( 'select' === $type && '' !== $group ) {
				$groups[ $group ][ $code ] = $label;
				continue;
			}

			$key = 'svc_' . $code;
			if ( 'text' === $type ) {
				$fields[ $key ] = array(
					'group'       => 'services',
					'type'        => 'number',
					'label'       => $label,
					'step'        => '0.01',
					'min'         => '0',
					'placeholder' => __( 'blank = settings; 0 = disable', 'bg-commerce-suite' ),
					'description' => $description,
				);
			} else {
				$fields[ $key ] = array(
					'group'       => 'services',
					'type'        => 'select',
					'label'       => $label,
					'options'     => $inherit,
					'description' => $description,
				);
			}
		}

		foreach ( $groups as $group => $options ) {
			$group_key = $this->service_group_key( $group );
			$fields[ 'svc_group_' . $group_key ] = array(
				'group'       => 'services',
				'type'        => 'select',
				/* translators: %s: Pigeon service-option group name. */
				'label'       => sprintf( __( 'Pigeon option — %s', 'bg-commerce-suite' ), $group ),
				'options'     => array( '' => __( 'Use settings', 'bg-commerce-suite' ), '__none__' => __( 'No', 'bg-commerce-suite' ) ) + $options,
				'description' => __( 'Pigeon allows exactly one option from this group for a shipment.', 'bg-commerce-suite' ),
			);
		}

		return $fields;
	}

	/**
	 * Core waybill fields Pigeon's API has no counterpart for.
	 *
	 * „Чупливо“ is not a Pigeon add-on service, and „Реф. 2“ is covered by
	 * `external_reference`, which this module fills from the order number.
	 *
	 * @return string[]
	 */
	/**
	 * Documented input limits for Core's generic waybill fields.
	 *
	 * The description becomes `inventory_items[].description`, which
	 * `pigeon-openapi.yaml` caps at `maxLength: 200` — twice what Speedy allows
	 * for the same Core field. That difference is exactly why the limit is
	 * declared by the courier rather than assumed once in Core.
	 *
	 * @return array<string,int>
	 */
	public function waybill_field_limits() {
		return array( 'contents' => 200 );
	}

	public function hidden_waybill_fields() {
		// Pigeon exposes review/test and fragile as account-specific service
		// codes. Core's generic controls would duplicate those switches and,
		// historically, were not part of the Pigeon payload at all.
		return array( 'ref2', 'obp', 'fragile' );
	}

	/**
	 * BGCS-AUDIT-004/-006 — Pigeon payment semantics for the order snapshot.
	 *
	 * Resolved through the same {@see who_pays()} the payload is built from, so
	 * the record cannot disagree with what Pigeon was told. Core used to guess
	 * this from the COD status of the order when the setting was unset, which is
	 * not what `who_pays()` does — its fallback is `sender`.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return array<string,mixed>
	 */
	public function label_snapshot_financials( \WC_Order $order, array $wb ) {
		$who_pays = $this->who_pays( $wb, $this->is_cod_order( $order ) );

		return array(
			'payer'        => ( 'sender' === $who_pays ) ? 'SENDER' : 'RECIPIENT',
			'cod_amount'   => $this->cod_amount( $order, $wb ),
			'cod_currency' => strtoupper( (string) $order->get_currency() ),
		);
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
	 * Tri-state yes/no per-order field resolved against a setting.
	 *
	 * @param array<string,mixed> $wb              Waybill overrides.
	 * @param string              $key             Field key.
	 * @param string              $setting_key     Setting to fall back to.
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
	 * Stable settings/metabox key for a Pigeon option_group.
	 *
	 * @param string $group Provider option group.
	 * @return string
	 */
	private function service_group_key( $group ) {
		$key = preg_replace( '/[^a-zA-Z0-9_-]+/', '_', (string) $group );
		return trim( (string) $key, '_' );
	}

	/**
	 * Resolve one mutually-exclusive Pigeon option group.
	 *
	 * New UI stores one value per group. Legacy per-service yes/no values are
	 * still read so upgrades do not silently lose an existing Review/Test choice.
	 *
	 * @param string                         $group   Provider option group.
	 * @param array<string,string>           $members code => label.
	 * @param array<string,mixed>            $wb      Per-order overrides.
	 * @return string Selected service code or empty string.
	 */
	private function resolve_service_group( $group, array $members, array $wb ) {
		$key      = $this->service_group_key( $group );
		$override = $this->wbx( $wb, 'svc_group_' . $key );
		if ( '__none__' === $override ) {
			return '';
		}
		if ( isset( $members[ $override ] ) ) {
			return $override;
		}

		// Legacy order overrides: an explicit yes selects that member.
		foreach ( $members as $code => $label ) {
			unset( $label );
			if ( 'yes' === $this->wbx( $wb, 'svc_' . $code ) ) {
				return (string) $code;
			}
		}

		$setting = trim( (string) bgcs3_get_option( self::ID, 'service_group_' . $key, '' ) );
		if ( isset( $members[ $setting ] ) ) {
			return $setting;
		}

		// Legacy global checkboxes: choose the first enabled service in provider
		// order. New settings prevent more than one from being selected.
		foreach ( $members as $code => $label ) {
			unset( $label );
			if ( 'yes' === (string) bgcs3_get_option( self::ID, 'service_' . $code, 'no' ) ) {
				return (string) $code;
			}
		}

		return '';
	}

	/**
	 * „Заявка за куриер“ — ask Pigeon to collect the parcels from the shop.
	 *
	 * Rendered inside the settings form, so Core's nonce and capability check
	 * already guard it and no second AJAX surface is introduced.
	 */
	/** Render operational courier-request UI in the shared Methods workspace. */
	public function render_methods_custom() {
		$this->render_settings_custom();
	}

	public function render_settings_custom() {
		$active = $this->stored_courier_request();
		$error  = trim( (string) bgcs3_get_option( self::ID, 'courier_request_error', '' ) );

		echo '<section class="bgcs-card bgcs-card--standalone bgcs-pigeon-courier">';
		echo '<div class="bgcs-card__head">';
		echo '<span class="bgcs-card__icon">' . Icons::svg( 'truck', 20 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="bgcs-card__titles"><span class="bgcs-card__title">' . esc_html__( 'Courier pickup request', 'bg-commerce-suite' ) . '</span>';
		echo '<span class="bgcs-card__desc">' . esc_html__( 'Request a Pigeon courier to collect shipments from your address.', 'bg-commerce-suite' ) . '</span></span>';
		echo '</div>';
		echo '<div class="bgcs-card__body">';
		if ( '' !== $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}

		if ( 'address' !== (string) Module_Settings::get( self::ID, 'pickup_type' ) ) {
			// Requesting a courier only makes sense when the parcels are at your
			// own address; an office drop-off is you going to them.
			echo '<p class="bgcs-pigeon-courier__note">' . esc_html__( 'The current handover method is a Pigeon office. A courier pickup request only makes sense when “Address” is selected — change it in the “Sender” section if you want a courier to collect from you.', 'bg-commerce-suite' ) . '</p>';
			echo '</div></section>';
			return;
		}

		$is_active = Pickup_Request::is_active( $active );
		if ( ! empty( $active['number'] ) ) {
			echo '<p class="bgcs-pigeon-courier__active">';
			printf(
				/* translators: 1: request number, 2: date, 3: status. */
				esc_html__( 'Last request #%1$s for %2$s — status: %3$s.', 'bg-commerce-suite' ),
				esc_html( $active['number'] ),
				esc_html( isset( $active['date'] ) ? $active['date'] : '' ),
				esc_html( self::status_label( isset( $active['status'] ) ? $active['status'] : '' ) )
			);
			echo '</p>';

			if ( ! empty( $active['references'] ) ) {
				echo '<p class="bgcs-pigeon-courier__note">' . esc_html( sprintf( /* translators: %d: number of shipments. */ __( 'Attached shipments: %d.', 'bg-commerce-suite' ), count( (array) $active['references'] ) ) ) . '</p>';
			}
			if ( ! empty( $active['updated_at'] ) ) {
				echo '<p><strong>' . esc_html__( 'Last update', 'bg-commerce-suite' ) . ':</strong> ' . esc_html( wp_date( 'd.m.Y H:i', (int) $active['updated_at'] ) ) . '</p>';
			}

			echo '<button type="submit" class="bgcs-btn bgcs-btn--outline bgcs-btn--sm" name="pigeon_courier_action" value="refresh">' . esc_html__( 'Refresh request status', 'bg-commerce-suite' ) . '</button> ';
			if ( $is_active ) {
				echo '<button type="submit" class="bgcs-btn bgcs-btn--outline bgcs-btn--sm" name="pigeon_courier_action" value="cancel">' . esc_html__( 'Cancel request', 'bg-commerce-suite' ) . '</button>';
				echo '</div></section>';
				return;
			}
		}

		$pending = $this->pending_shipment_references();

		echo '<div class="bgcs-fieldgrid">';
		$this->courier_field( 'date', __( 'Pickup date', 'bg-commerce-suite' ), 'date', gmdate( 'Y-m-d' ) );
		echo '<div class="bgcs-field"><label class="bgcs-field__label" for="bgcs-pigeon-cr-time_type">' . esc_html__( 'Time type', 'bg-commerce-suite' ) . '</label>';
		echo '<select id="bgcs-pigeon-cr-time_type" name="pigeon_courier[time_type]" class="widefat">';
		echo '<option value="interval">' . esc_html__( 'Interval', 'bg-commerce-suite' ) . '</option>';
		echo '<option value="specific_time">' . esc_html__( 'Specific time', 'bg-commerce-suite' ) . '</option>';
		echo '</select></div>';
		$this->courier_field( 'time_from', __( 'From time', 'bg-commerce-suite' ), 'time', '09:00' );
		$this->courier_field( 'time_to', __( 'To time', 'bg-commerce-suite' ), 'time', '17:00' );
		$this->courier_field( 'contact_name', __( 'Contact person', 'bg-commerce-suite' ), 'text', '' );
		$this->courier_field( 'contact_phone', __( 'Phone', 'bg-commerce-suite' ), 'text', '' );
		echo '</div>';

		$this->courier_field( 'additional_info', __( 'Additional address information', 'bg-commerce-suite' ), 'text', '', true );

		echo '<p class="bgcs-pigeon-courier__note">';
		printf(
			/* translators: %d: number of shipments waiting to be collected. */
			esc_html__( 'Prepared Pigeon shipments that will be attached: %d', 'bg-commerce-suite' ),
			count( $pending )
		);
		echo '</p>';

		echo '<p class="bgcs-pigeon-courier__note">' . esc_html__( 'The date must be a business day and before the daily request cutoff, and the hours must be within the office working hours. Pigeon rejects requests outside these limits.', 'bg-commerce-suite' ) . '</p>';

		echo '<button type="submit" class="bgcs-btn bgcs-btn--primary bgcs-btn--sm" name="pigeon_courier_action" value="create">' . esc_html__( 'Request courier', 'bg-commerce-suite' ) . '</button>';

		echo '</div></section>';
	}

	/**
	 * One input of the courier-request form.
	 *
	 * @param string $key   Field key.
	 * @param string $label Label.
	 * @param string $type  Input type.
	 * @param string $value Default value.
	 * @param bool   $full  Span both columns.
	 */
	private function courier_field( $key, $label, $type, $value, $full = false ) {
		$id = 'bgcs-pigeon-cr-' . $key;
		echo '<div class="bgcs-field' . ( $full ? ' bgcs-field--full' : '' ) . '">';
		echo '<label class="bgcs-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<input id="' . esc_attr( $id ) . '" class="widefat" type="' . esc_attr( $type ) . '" name="' . esc_attr( 'pigeon_courier[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '" />';
		echo '</div>';
	}

	/**
	 * Handles „Заяви куриер“ / „Откажи заявката“.
	 *
	 * Core has already verified the settings nonce and the capability before
	 * calling this.
	 */
	public function save_settings_custom( $scope = '' ) {
		$scope = sanitize_key( (string) $scope );
		if ( '' !== $scope && 'methods' !== $scope ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by Core.
		$action = isset( $_POST['pigeon_courier_action'] ) ? sanitize_key( wp_unslash( $_POST['pigeon_courier_action'] ) ) : '';

		if ( 'cancel' === $action ) {
			$this->cancel_courier_request();
			return;
		}
		if ( 'refresh' === $action ) {
			$this->refresh_courier_request();
			return;
		}

		if ( 'create' !== $action ) {
			return;
		}

		$owner = Pickup_Request::acquire( self::ID );
		if ( false === $owner ) {
			Options::set( self::ID, 'courier_request_error', __( 'A courier pickup operation is already running. Wait for it to finish before trying again.', 'bg-commerce-suite' ) );
			return;
		}
		try {
			if ( Pickup_Request::is_active( $this->stored_courier_request() ) ) {
				Options::set( self::ID, 'courier_request_error', __( 'There is already an active Pigeon courier pickup request. Refresh or cancel it before creating another one.', 'bg-commerce-suite' ) );
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by Core.
			$raw  = isset( $_POST['pigeon_courier'] ) && is_array( $_POST['pigeon_courier'] ) ? map_deep( wp_unslash( $_POST['pigeon_courier'] ), 'sanitize_text_field' ) : array();
		$form = array();
		foreach ( $raw as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$form[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
			}
		}

		$orders     = $this->pending_shipment_references();
		$references = array_keys( $orders );
		if ( empty( $references ) ) {
			Options::set( self::ID, 'courier_request_error', __( 'Create at least one Pigeon shipment label before requesting a courier. Every pickup request must be linked to a prepared shipment.', 'bg-commerce-suite' ) );
			return;
		}

		$body = Courier_Request::build( $form, $this->pickup_address_settings(), $references );

		if ( is_wp_error( $body ) ) {
			Options::set( self::ID, 'courier_request_error', $body->get_error_message() );
			return;
		}

		$response = $this->client()->create_courier_request( $body );

		if ( is_wp_error( $response ) ) {
			Options::set( self::ID, 'courier_request_error', $response->get_error_message() );
			return;
		}

		$data   = isset( $response['data'] ) ? (array) $response['data'] : (array) $response;
		$number = '';
		foreach ( array( 'request_number', 'number', 'id' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$number = (string) $data[ $key ];
				break;
			}
		}

		if ( '' === $number ) {
			Options::set( self::ID, 'courier_request_error', __( 'Pigeon accepted the request but did not return a number — check the partner portal.', 'bg-commerce-suite' ) );
			return;
		}

		Options::set( self::ID, 'courier_request_error', '' );
		$shipments = $this->pigeon_pickup_shipments( $orders );
		$stored = Pickup_Request::record(
			self::ID,
			$number,
			isset( $data['status'] ) ? self::status_code( $data['status'] ) : Pickup_Request::PENDING,
			isset( $body['requested_pickup_date'] ) ? $body['requested_pickup_date'] : '',
			isset( $body['requested_pickup_time_from'] ) ? $body['requested_pickup_time_from'] : '',
			isset( $body['requested_pickup_time_to'] ) ? $body['requested_pickup_time_to'] : '',
			$shipments,
			Pickup_Request::fingerprint( self::ID, $body, $shipments )
		);
		$stored['references'] = isset( $body['shipment_references'] ) ? $body['shipment_references'] : array();
		Options::set(
			self::ID,
			'courier_request',
			$stored
		);
		Pickup_Request::attach_orders( $stored, '_bgcs3_pigeon_courier_request' );

		// Mark the attached orders so the same shipment is never sent to a
		// second request — Pigeon rejects the whole request if one already
		// belongs to an active one.
		foreach ( $orders as $reference => $order_id ) {
			if ( ! in_array( (string) $reference, (array) ( isset( $body['shipment_references'] ) ? $body['shipment_references'] : array() ), true ) ) {
				continue;
			}
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( sprintf( /* translators: %s: courier request number. */ __( 'Pigeon: the shipment was included in courier pickup request #%s.', 'bg-commerce-suite' ), $number ) );
				$order->save();
			}
		}
		} finally {
			Pickup_Request::release( self::ID, $owner );
		}
	}

	/**
	 * Cancels the stored courier request and clears it only on success.
	 */
	private function cancel_courier_request() {
		$owner = Pickup_Request::acquire( self::ID );
		if ( false === $owner ) {
			Options::set( self::ID, 'courier_request_error', __( 'A courier pickup operation is already running. Wait for it to finish before trying again.', 'bg-commerce-suite' ) );
			return;
		}
		try {
		$active = $this->stored_courier_request();
		if ( empty( $active['number'] ) ) {
			return;
		}
		if ( ! Pickup_Request::is_active( $active ) ) {
			Options::set( self::ID, 'courier_request_error', __( 'This courier pickup request is no longer active and cannot be cancelled.', 'bg-commerce-suite' ) );
			return;
		}

		$response = $this->client()->cancel_courier_request( $active['number'] );

		if ( is_wp_error( $response ) ) {
			Options::set( self::ID, 'courier_request_error', $response->get_error_message() );
			return;
		}

		Options::set( self::ID, 'courier_request_error', '' );
		$active['status']      = Pickup_Request::CANCELLED;
		$active['status_code'] = Pickup_Request::CANCELLED;
		$active['updated_at']  = time();
		Options::set( self::ID, 'courier_request', $active );
		Pickup_Request::update_orders( $active );
		Pickup_Request::detach_orders( $active, '_bgcs3_pigeon_courier_request' );
		} finally {
			Pickup_Request::release( self::ID, $owner );
		}
	}

	/** Refresh Pigeon's provider status without mutating the request. */
	private function refresh_courier_request() {
		$owner = Pickup_Request::acquire( self::ID );
		if ( false === $owner ) {
			Options::set( self::ID, 'courier_request_error', __( 'A courier pickup operation is already running. Wait for it to finish before trying again.', 'bg-commerce-suite' ) );
			return;
		}
		try {
			$active = $this->stored_courier_request();
			if ( empty( $active['number'] ) ) {
				return;
			}
			$response = $this->client()->get_courier_request( $active['number'] );
			if ( is_wp_error( $response ) ) {
				Options::set( self::ID, 'courier_request_error', $response->get_error_message() );
				return;
			}
			$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : (array) $response;
			$active['provider_status'] = isset( $data['status'] ) ? self::status_label( $data['status'] ) : '';
			$active['status']          = self::status_code( isset( $data['status'] ) ? $data['status'] : '' );
			$active['status_code']     = $active['status'];
			$active['updated_at']      = time();
			Options::set( self::ID, 'courier_request_error', '' );
			Options::set( self::ID, 'courier_request', $active );
			Pickup_Request::update_orders( $active );
			if ( in_array( $active['status'], array( Pickup_Request::REJECTED, Pickup_Request::CANCELLED ), true ) ) {
				Pickup_Request::detach_orders( $active, '_bgcs3_pigeon_courier_request' );
			}
		} finally {
			Pickup_Request::release( self::ID, $owner );
		}
	}

	/**
	 * A readable status out of what Pigeon returns.
	 *
	 * Observed live: a courier request answers with an OBJECT
	 * `{code, name}`, not a string — casting it straight to string would store
	 * the word „Array“ and emit a notice.
	 *
	 * @param mixed $status Raw status.
	 * @return string
	 */
	public static function status_label( $status ) {
		if ( is_array( $status ) ) {
			foreach ( array( 'name', 'label', 'code' ) as $key ) {
				if ( ! empty( $status[ $key ] ) && is_scalar( $status[ $key ] ) ) {
					return (string) $status[ $key ];
				}
			}
			return '';
		}

		$status = is_scalar( $status ) ? (string) $status : '';
		$map = array(
			Pickup_Request::PENDING    => __( 'Pending', 'bg-commerce-suite' ),
			Pickup_Request::PROCESSING => __( 'Processing', 'bg-commerce-suite' ),
			Pickup_Request::COLLECTED  => __( 'Collected', 'bg-commerce-suite' ),
			Pickup_Request::REJECTED   => __( 'Rejected', 'bg-commerce-suite' ),
			Pickup_Request::CANCELLED  => __( 'Cancelled', 'bg-commerce-suite' ),
		);
		$normalized = Pickup_Request::status( $status );
		return isset( $map[ $normalized ] ) ? $map[ $normalized ] : $status;
	}

	/** @return string */
	private static function status_code( $status ) {
		if ( is_array( $status ) ) {
			foreach ( array( 'code', 'status', 'name', 'label' ) as $key ) {
				if ( ! empty( $status[ $key ] ) && is_scalar( $status[ $key ] ) ) {
					return Pickup_Request::status( $status[ $key ] );
				}
			}
			return Pickup_Request::UNKNOWN;
		}
		return Pickup_Request::status( $status );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function stored_courier_request() {
		$stored = bgcs3_get_option( self::ID, 'courier_request', array() );
		return is_array( $stored ) ? Pickup_Request::normalize( $stored, self::ID ) : array();
	}

	/**
	 * Pickup address as the courier-request builder needs it.
	 *
	 * @return array<string,mixed>
	 */
	private function pickup_address_settings() {
		return array(
			'city_id'       => (int) Module_Settings::get( self::ID, 'sender_city_id' ),
			'street_id'     => (int) Module_Settings::get( self::ID, 'sender_street_id' ),
			'street_number' => (string) Module_Settings::get( self::ID, 'sender_address' ),
		);
	}

	/**
	 * Pigeon shipments created here that no courier request covers yet.
	 *
	 * @return array<string,int> reference => order id.
	 */
	private function pending_shipment_references() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 200,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'objects',
				'meta_key'   => '_bgcs3_label', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			)
		);

		$found = array();

		foreach ( (array) $orders as $order ) {
			if ( ! is_a( $order, 'WC_Order' ) ) {
				continue;
			}
			if ( '' !== (string) $order->get_meta( '_bgcs3_pigeon_courier_request' ) || is_array( $order->get_meta( Pickup_Request::META_KEY ) ) ) {
				continue;
			}

			$label = $order->get_meta( '_bgcs3_label' );
			if ( ! is_array( $label ) || self::ID !== ( isset( $label['courier'] ) ? $label['courier'] : '' ) ) {
				continue;
			}
			if ( empty( $label['number'] ) ) {
				continue;
			}

			$found[ (string) $label['number'] ] = $order->get_id();

			if ( count( $found ) >= Courier_Request::MAX_REFERENCES ) {
				break;
			}
		}

		return $found;
	}

	/** @return array<int,array<string,mixed>> */
	private function pigeon_pickup_shipments( array $orders ) {
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
		// Sender office from the Pigeon nomenclature (text until synced).
		$all_offices = $this->client()->has_credentials() ? $this->locations()->all_offices_options() : array();
		if ( ! empty( $all_offices ) ) {
			$sender_office_field = array(
				'type'        => 'select',
				'label'       => __( 'Sender office', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => array( '' => __( '— Select an office —', 'bg-commerce-suite' ) ) + $all_offices,
				'description' => __( 'Loaded from Pigeon Express (/offices). Used when “Send from: Office” is selected.', 'bg-commerce-suite' ),
				'searchable'  => true,
				'label_key'   => 'sender_office_label',
				'show_if'     => array( 'pickup_type' => 'office' ),
			);
		} else {
			$sender_office_field = array(
				'type'        => 'text',
				'label'       => __( 'Sender office (ID)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'External ID of the sender office. Enter the credentials and sync — a list will appear.', 'bg-commerce-suite' ),
				'show_if'     => array( 'pickup_type' => 'office' ),
			);
		}

		$fields = array(
			'api_key'         => array(
				'type'        => 'text',
				'label'       => __( 'API key (X-API-Key)', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => __( 'From your Pigeon Express profile.', 'bg-commerce-suite' ),
			),
			'api_secret'      => array(
				'type'    => 'password',
				'label'   => __( 'API secret key (X-API-Secret)', 'bg-commerce-suite' ),
				'default' => '',
			),
			'sandbox'         => array(
				'type'           => 'checkbox',
				'label'          => __( 'Test environment', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Use sandbox (api-demo.pigeonexpress.com)', 'bg-commerce-suite' ),
				'default'        => 'no',
			),

			// Sender / pickup.
			'pickup_type'     => array(
				'type'    => 'select',
				'label'   => __( 'Send from', 'bg-commerce-suite' ),
				'default' => 'office',
				'options' => array(
					'office'  => __( 'Pigeon office', 'bg-commerce-suite' ),
					'address' => __( 'Address', 'bg-commerce-suite' ),
				),
			),
			'sender_office_id' => $sender_office_field,
			'sender_city_id'  => array(
				'type'         => 'remote_select',
				'label'        => __( 'Sender city', 'bg-commerce-suite' ),
				'default'      => '',
				'resource'     => 'cities',
				'label_key'    => 'sender_city_label',
				'searchable'   => true,
				'show_if'      => array( 'pickup_type' => 'address' ),
				'minimum_input_length' => 2,
			),
			'sender_street_id' => array(
				'type'         => 'remote_select',
				'label'        => __( 'Sender street', 'bg-commerce-suite' ),
				'default'      => '',
				'resource'     => 'streets',
				'depends_on'   => 'sender_city_id',
				'label_key'    => 'sender_street_label',
				'searchable'   => true,
				'show_if'      => array( 'pickup_type' => 'address' ),
				'minimum_input_length' => 2,
			),
			'sender_address'  => array(
				'type'    => 'text',
				'label'   => __( 'Sender address (number, floor…)', 'bg-commerce-suite' ),
				'default' => '',
				'show_if' => array( 'pickup_type' => 'address' ),
			),

			// Checkout delivery visibility.
			'show_office'     => array(
				'type'           => 'checkbox',
				'label'          => __( 'Office delivery', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Show “To office” at checkout', 'bg-commerce-suite' ),
				'default'        => 'yes',
			),
			'show_locker'     => array(
				'type'           => 'checkbox',
				'label'          => __( 'Delivery to locker (APS)', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Show “To locker” at checkout', 'bg-commerce-suite' ),
				'default'        => 'yes',
			),
			'show_address'    => array(
				'type'           => 'checkbox',
				'label'          => __( 'Address delivery', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Show “To address” at checkout', 'bg-commerce-suite' ),
				'default'        => 'yes',
				'description'    => __( 'If you disable all delivery types, Pigeon Express will not appear as a shipping option at checkout.', 'bg-commerce-suite' ),
			),

			// Service.
			'service_type'    => array(
				'type'        => 'select',
				'label'       => __( 'Default service type', 'bg-commerce-suite' ),
				'default'     => 'standard',
				'options'     => array(
					'standard' => __( 'Standard', 'bg-commerce-suite' ),
					'express'  => __( 'Express', 'bg-commerce-suite' ),
				),
				'description' => __( 'Sent as `service_type`. It can be changed for an individual order from the “Shipment label” panel.', 'bg-commerce-suite' ),
			),
			'return_at_my_expense' => array(
				'type'           => 'checkbox',
				'label'          => __( 'Return paid by sender', 'bg-commerce-suite' ),
				'checkbox_label' => __( 'Send `return_at_my_expense` for all shipments', 'bg-commerce-suite' ),
				'default'        => 'no',
				'description'    => __( 'If the shipment is returned, the cost is charged to the store, not the recipient.', 'bg-commerce-suite' ),
			),

			// Payment.
			'who_pays'        => array(
				'type'        => 'select',
				'label'       => __( 'Who pays for shipping', 'bg-commerce-suite' ),
				'default'     => '',
				'options'     => array(
					''         => __( 'Auto (sender)', 'bg-commerce-suite' ),
					'sender'   => __( 'Sender', 'bg-commerce-suite' ),
					'receiver' => __( 'Recipient', 'bg-commerce-suite' ),
				),
			),

			// Content / dimensions.
			'default_width'   => array(
				'type'    => 'text',
				'label'   => __( 'Dimension — width (cm)', 'bg-commerce-suite' ),
				'default' => '40',
			),
			'default_length'  => array(
				'type'    => 'text',
				'label'   => __( 'Dimension — length (cm)', 'bg-commerce-suite' ),
				'default' => '40',
			),
			'default_height'  => array(
				'type'    => 'text',
				'label'   => __( 'Dimension — height (cm)', 'bg-commerce-suite' ),
				'default' => '40',
			),
			'label_format'    => array(
				'type'    => 'select',
				'label'   => __( 'Label format (print)', 'bg-commerce-suite' ),
				'default' => 'default',
				'options' => array(
					'default' => __( 'Thermal label 100×90 mm (standard)', 'bg-commerce-suite' ),
					'pdf150'  => __( 'PDF 100×150 mm', 'bg-commerce-suite' ),
					'a4'      => __( 'A4 (office printer)', 'bg-commerce-suite' ),
				),
			),
		);

		// Dynamic additional services from the authenticated Pigeon account.
		// The endpoint returns an input contract; do not flatten every row into a
		// checkbox because text services require values and select groups are
		// mutually exclusive.
		$service_groups = array();
		foreach ( $this->locations()->service_definitions() as $code => $definition ) {
			if ( in_array( $code, array( 'cod_amount', 'declared_value' ), true ) ) {
				continue;
			}
			$label       = isset( $definition['label'] ) ? (string) $definition['label'] : (string) $code;
			$description = isset( $definition['description'] ) ? (string) $definition['description'] : '';
			$type        = isset( $definition['input_type'] ) ? (string) $definition['input_type'] : 'checkbox';
			$group       = isset( $definition['option_group'] ) ? trim( (string) $definition['option_group'] ) : '';

			if ( 'select' === $type && '' !== $group ) {
				$service_groups[ $group ][ $code ] = $label;
				continue;
			}

			if ( 'text' === $type ) {
				$fields[ 'service_' . $code ] = array(
					'type'        => 'number',
					'label'       => $label,
					'default'     => '',
					'description' => '' !== $description ? $description : __( 'Numeric value sent to Pigeon when this service is used.', 'bg-commerce-suite' ),
				);
			} else {
				$fields[ 'service_' . $code ] = array(
					'type'           => 'checkbox',
					'label'          => $label,
					/* translators: %s: Pigeon service name. */
					'checkbox_label' => sprintf( __( 'Enable service “%s”', 'bg-commerce-suite' ), $label ),
					'default'        => 'no',
					'description'    => $description,
				);
			}
		}

		foreach ( $service_groups as $group => $options ) {
			$key = 'service_group_' . $this->service_group_key( $group );
			$fields[ $key ] = array(
				'type'        => 'select',
				/* translators: %s: Pigeon service-option group name. */
				'label'       => sprintf( __( 'Pigeon option — %s', 'bg-commerce-suite' ), $group ),
				'default'     => '',
				'options'     => array( '' => __( 'No', 'bg-commerce-suite' ) ) + $options,
				'description' => __( 'Pigeon allows exactly one option from this group.', 'bg-commerce-suite' ),
			);
		}

		return $fields;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function settings_sections() {
		$service_keys   = array();
		$service_groups = array();
		foreach ( $this->locations()->service_definitions() as $code => $definition ) {
			if ( in_array( $code, array( 'cod_amount', 'declared_value' ), true ) ) {
				continue;
			}
			$type  = isset( $definition['input_type'] ) ? (string) $definition['input_type'] : 'checkbox';
			$group = isset( $definition['option_group'] ) ? trim( (string) $definition['option_group'] ) : '';
			if ( 'select' === $type && '' !== $group ) {
				$service_groups[ $group ] = true;
			} else {
				$service_keys[] = 'service_' . $code;
			}
		}
		foreach ( array_keys( $service_groups ) as $group ) {
			$service_keys[] = 'service_group_' . $this->service_group_key( $group );
		}

		return array(
			array(
				'title'  => __( 'API access', 'bg-commerce-suite' ),
				'desc'   => __( 'Credentials and environment.', 'bg-commerce-suite' ),
				'icon'   => 'plug',
				'fields' => array( 'api_key', 'api_secret', 'sandbox' ),
			),
			array(
				'title'  => __( 'Sender', 'bg-commerce-suite' ),
				'desc'   => __( 'Where shipments are sent from.', 'bg-commerce-suite' ),
				'icon'   => 'user',
				'fields' => array( 'pickup_type', 'sender_office_id', 'sender_city_id', 'sender_street_id', 'sender_address' ),
			),
			array(
				'title'  => __( 'Delivery types at checkout', 'bg-commerce-suite' ),
				'desc'   => __( 'Choose which delivery options the customer can see.', 'bg-commerce-suite' ),
				'icon'   => 'sliders',
				'fields' => array( 'show_office', 'show_locker', 'show_address' ),
			),
			array(
				'title'  => __( 'Payment', 'bg-commerce-suite' ),
				'desc'   => __( 'Shipping payer.', 'bg-commerce-suite' ),
				'icon'   => 'credit-card',
				'fields' => array( 'who_pays' ),
			),
			array(
				'title'  => __( 'Service', 'bg-commerce-suite' ),
				'desc'   => __( 'Service type and returns. These can also be changed for an individual order.', 'bg-commerce-suite' ),
				'icon'   => 'truck',
				'fields' => array( 'service_type', 'return_at_my_expense' ),
			),
			array(
				'title'  => __( 'Additional services', 'bg-commerce-suite' ),
				'desc'   => __( 'Active services from your profile.', 'bg-commerce-suite' ),
				'icon'   => 'sliders',
				'fields' => $service_keys,
			),
			array(
				'title'  => __( 'Dimensions and weight', 'bg-commerce-suite' ),
				'desc'   => __( 'Default parcel values.', 'bg-commerce-suite' ),
				'icon'   => 'ruler',
				'fields' => array( 'default_weight', 'default_width', 'default_length', 'default_height', 'label_format' ),
			),
		);
	}

	public function register( Container $container ) {
		Shipping_Hooks::init();
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
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
	 * @return array{success:bool,message:string}
	 */
	public function sync_data() {
		$count    = Cache::flush_courier( $this->id() );
		$services = $this->locations()->services();
		$offices  = $this->locations()->all_offices_options();

		// Persist the checkout office/locker pools into the DB store.
		$office_rows = $this->locations()->all_offices( 'office' );
		$locker_rows = $this->locations()->all_offices( 'locker' );

		$pools = $this->locations()->replace_if_valid();

		return $this->sync_result(
			$pools,
			array(
				'cache'          => $count,
				'services'       => count( $services ),
				'sender_offices' => count( $offices ),
			)
		);
	}

	public function supports_sender_refresh() {
		return true;
	}

	public function sender_refresh_label() {
		return __( 'Validate sender details', 'bg-commerce-suite' );
	}

	public function refresh_sender_data() {
		$type = (string) Module_Settings::get( self::ID, 'pickup_type' );
		if ( 'address' === $type ) {
			$city_id   = (string) Module_Settings::get( self::ID, 'sender_city_id' );
			$street_id = (string) Module_Settings::get( self::ID, 'sender_street_id' );
			if ( '' === $city_id || '' === $street_id ) {
				return Sync_Result::error( __( 'Select the sender city and street.', 'bg-commerce-suite' ) );
			}
		} else {
			$office_id = (string) bgcs3_get_option( self::ID, 'sender_office_id', '' );
			$offices   = $this->locations()->all_offices_options();
			if ( '' === $office_id || ! isset( $offices[ $office_id ] ) ) {
				return Sync_Result::error( __( 'Select a valid sender office.', 'bg-commerce-suite' ) );
			}
			Options::set( self::ID, 'sender_office_label', $offices[ $office_id ] );
		}

		// For a normal outbound shipment the authenticated Pigeon API customer is
		// the sender. The current OpenAPI schema reserves sender_name/sender_phone/
		// sender_email for `is_reverse: true`, which BGCS does not create here.
		return Sync_Result::success(
			__( 'The sender pickup location is ready for shipment creation.', 'bg-commerce-suite' ),
			array(),
			array( 'sender_location' )
		);
	}

	public function admin_location_search( $resource, array $args ) {
		if ( 'cities' === $resource ) {
			return $this->locations()->search_cities( isset( $args['query'] ) ? $args['query'] : '' );
		}
		if ( 'streets' === $resource ) {
			return $this->locations()->search_streets( isset( $args['city_id'] ) ? $args['city_id'] : '', isset( $args['query'] ) ? $args['query'] : '' );
		}
		if ( 'offices' === $resource ) {
			return $this->search_stored_locations( isset( $args['type'] ) && 'locker' === $args['type'] ? 'locker' : 'office', isset( $args['query'] ) ? $args['query'] : '' );
		}
		return parent::admin_location_search( $resource, $args );
	}


	/**
	 * A positive static WooCommerce shipping rate and a courier-service payer of
	 * Recipient would charge the same delivery twice. Free shipping is handled by
	 * the generic free-pricing path and may still intentionally use recipient-pay.
	 *
	 * @param Selection $selection Delivery selection.
	 * @param float     $base_cost WooCommerce static shipping amount.
	 * @return true|\WP_Error
	 */
	public function validate_static_pricing( Selection $selection, $base_cost = 0.0 ) {
		unset( $selection );
		if ( (float) $base_cost > 0.0001 && 'receiver' === $this->who_pays( array(), $this->is_cod_chosen() ) ) {
			return new \WP_Error(
				'bgcs3_pigeon_static_payer',
				__( 'Pigeon Express static checkout pricing cannot use Recipient as the courier-service payer because WooCommerce already charges the shipping rate in the order. Set the payer to Sender.', 'bg-commerce-suite' )
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
		if ( ! $this->client()->has_credentials() ) {
			return new Price_Result();
		}

		$pickup = $this->pickup();
		if ( empty( $pickup ) ) {
			return Price_Result::error( __( 'Configure the Pigeon Express sender (office or address).', 'bg-commerce-suite' ) );
		}

		$weight     = $this->package_weight( $package );
		$cart_total = ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->get_total( 'edit' ) : 0.0;
		$is_cod     = $this->is_cod_chosen();
		$who_pays   = $this->who_pays( array(), $is_cod );
		if ( 'receiver' === $who_pays ) {
			return Price_Result::error( __( 'Pigeon Express API-price checkout cannot use Recipient as the courier-service payer because WooCommerce charges the same courier price in the order. Set the payer to Sender.', 'bg-commerce-suite' ) );
		}
		$quote_wb   = array();
		$dimensions = Package_Dimensions::resolve_for_package(
			$package,
			array(),
			array(
				'length' => Module_Settings::get( self::ID, 'default_length' ),
				'width'  => Module_Settings::get( self::ID, 'default_width' ),
				'height' => Module_Settings::get( self::ID, 'default_height' ),
			)
		);
		if ( ! empty( $dimensions ) ) {
			$quote_wb['depth']  = $dimensions['length'];
			$quote_wb['width']  = $dimensions['width'];
			$quote_wb['height'] = $dimensions['height'];
		}

		$body = array_merge(
			$pickup,
			$this->delivery( $selection, false ),
			array(
				'packages'        => $this->packages( $weight, $quote_wb ),
				'inventory_items' => $this->package_inventory_items( $package ),
			),
			$this->services_block( $cart_total, $is_cod, $who_pays, 'yes' === (string) bgcs3_get_option( self::ID, 'service_declared_value', 'no' ) ? $cart_total : 0.0 )
		);

		$response = $this->client()->calculate( $body );

		if ( is_wp_error( $response ) ) {
			return Price_Result::error( $response->get_error_message() );
		}

		$price = isset( $response['data']['total_price'] ) ? (float) $response['data']['total_price'] : 0.0;
		if ( $price <= 0 ) {
			return Price_Result::error( __( 'Pigeon Express did not return a valid shipping price.', 'bg-commerce-suite' ) );
		}

		$result           = new Price_Result();
		$result->valid    = true;
		$result->cost     = $price;
		$result->currency = get_woocommerce_currency();
		$result->meta     = array( 'pigeon_quote' => isset( $response['data'] ) ? $response['data'] : array() );

		return $result;
	}

	/**
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
			return $preflight->reject( $error, 'pigeon_selection' );
		}
		if ( ! $this->client()->has_credentials() ) {
			$error = Label_Result::error( __( 'Pigeon Express API keys are missing.', 'bg-commerce-suite' ) );
			return $preflight->reject( $error, 'pigeon_credentials' );
		}

		$wb = $order->get_meta( '_bgcs3_wb' );
		$wb = is_array( $wb ) ? $wb : array();

		$invalid_package_row = Package_Dimensions::invalid_complete_row( isset( $wb['packages'] ) ? $wb['packages'] : array() );
		if ( $invalid_package_row > 0 ) {
			$error = Label_Result::error(
				sprintf(
					/* translators: %d package row number. */
					__( 'Pigeon Express package %d is incomplete. Enter length, width, height and weight for every package, or remove the package rows and use the total shipment values.', 'bg-commerce-suite' ),
					$invalid_package_row
				)
			);
			return $preflight->reject( $error, 'pigeon_package' );
		}

		if ( empty( $wb['packages'] ) ) {
			$dimensions = Package_Dimensions::resolve_for_order(
				$order,
				array(
					'length' => isset( $wb['depth'] ) ? $wb['depth'] : '',
					'width'  => isset( $wb['width'] ) ? $wb['width'] : '',
					'height' => isset( $wb['height'] ) ? $wb['height'] : '',
				),
				array(
					'length' => Module_Settings::get( self::ID, 'default_length' ),
					'width'  => Module_Settings::get( self::ID, 'default_width' ),
					'height' => Module_Settings::get( self::ID, 'default_height' ),
				)
			);
			if ( ! empty( $dimensions ) ) {
				$wb['depth']  = $dimensions['length'];
				$wb['width']  = $dimensions['width'];
				$wb['height'] = $dimensions['height'];
			}
		}

		$pickup = $this->pickup( $wb );
		if ( empty( $pickup ) ) {
			$error = Label_Result::error( __( 'Configure the Pigeon Express sender (office or address).', 'bg-commerce-suite' ) );
			return $preflight->reject( $error, 'pigeon_sender' );
		}

		$receiver = $this->receiver( $order, $wb );
		if ( is_wp_error( $receiver ) ) {
			$error = Label_Result::error( $receiver->get_error_message() );
			return $preflight->reject( $error, 'pigeon_recipient' );
		}

		$amount = $this->cod_amount( $order, $wb );
		$is_cod = $amount > 0;
		$weight = $this->order_weight( $order, $wb );
		$who_pays = $this->who_pays( $wb, $is_cod );

		$payer_check = Financial_Invariants::validate_no_double_shipping_charge( $order, $who_pays, 'receiver', $this->name() );
		if ( is_wp_error( $payer_check ) ) {
			$error = Label_Result::error( $payer_check->get_error_message() );
			return $preflight->reject( $error, 'pigeon_payer' );
		}

		$body = array_merge(
			$pickup,
			$this->delivery( $selection, true ),
			$receiver,
			array(
				'packages'        => $this->packages( $weight, $wb ),
				'inventory_items' => $this->inventory_items( $order, $wb ),
			),
			$this->services_block( $amount, $is_cod, $who_pays, $this->declared_value( $order, $wb ), $wb ),
			$this->extras_block( $order, $wb )
		);

		/**
		 * Final Pigeon shipment body.
		 *
		 * @param array<string,mixed> $body  Request body.
		 * @param \WC_Order           $order Order.
		 * @param array<string,mixed> $wb    Waybill overrides.
		 */
		$body = (array) apply_filters( 'bgcs3_pigeon_shipment_payload', $body, $order, $wb );
		$preflight
			->section(
				'sender',
				array(
					'account_id'      => '',
					'location_type'   => ! empty( $pickup['pickup_type'] ) ? (string) $pickup['pickup_type'] : '',
					'location_id'     => ! empty( $pickup['pickup_office_id'] ) ? (string) $pickup['pickup_office_id'] : ( ! empty( $pickup['pickup_address']['city_id'] ) ? (string) $pickup['pickup_address']['city_id'] : '' ),
					'contact_source' => 'provider_account',
				)
			)
			->section(
				'recipient_payload',
				array(
					'private_person' => '' === trim( (string) $order->get_billing_company() ),
					'office_id'      => ! empty( $body['delivery_office_id'] ) ? (string) $body['delivery_office_id'] : '',
					'address_ready'  => ! empty( $body['delivery_address'] ),
					'name_present'   => ! empty( $receiver['receiver_name'] ),
					'phone_present'  => ! empty( $receiver['receiver_phone'] ),
				)
			)
			->section(
				'package_payload',
				array(
					'parcel_count'      => ! empty( $body['packages'] ) && is_array( $body['packages'] ) ? count( $body['packages'] ) : 0,
					'contents_present'  => ! empty( $body['inventory_items'] ),
					'declared_value'    => ! empty( $body['declared_value'] ) ? (float) $body['declared_value'] : 0.0,
				)
			)
			->section(
				'services',
				array(
					'type'            => ! empty( $body['service_type'] ) ? (string) $body['service_type'] : '',
					'service_codes'   => ! empty( $body['service_codes'] ) && is_array( $body['service_codes'] ) ? array_values( $body['service_codes'] ) : array(),
				)
			)
			->section(
				'payer',
				array(
					'courier_service' => strtoupper( (string) $who_pays ),
					'cod_pmt'         => $is_cod ? strtoupper( (string) $who_pays ) : '',
					'package'         => strtoupper( (string) $who_pays ),
					'declared_value'  => strtoupper( (string) $who_pays ),
				)
			)
			->payload_ready( $body );

		if ( $preflight->is_blocked() ) {
			return $preflight->label_error();
		}

		// Pigeon's official CalculateShipmentRequest accepts the same routing,
		// package, service type and service_codes contract as CreateShipmentRequest.
		// Use it as a non-destructive preflight so account-specific services/tariffs
		// are rejected BEFORE an actual shipment is registered.
		$calculate_keys = array(
			'pickup_type',
			'pickup_office_id',
			'pickup_address',
			'delivery_type',
			'delivery_office_id',
			'delivery_address',
			'packages',
			'service_type',
			'who_pays',
			'service_codes',
		);
		$calculate_body = array_intersect_key( $body, array_flip( $calculate_keys ) );
		$provider_preflight = $this->client()->calculate( $calculate_body );
		if ( is_wp_error( $provider_preflight ) ) {
			$error = Label_Result::error(
				sprintf(
					/* translators: %s Pigeon API validation error. */
					__( 'Pigeon Express rejected the shipment settings before creation: %s', 'bg-commerce-suite' ),
					$provider_preflight->get_error_message()
				)
			);
			return $preflight->reject( $error, 'pigeon_provider_validation' );
		}

		$creation = Shipment_Creation::remote_started( $order, $this );
		if ( true !== $creation ) {
			return $creation;
		}
		$response = $this->client()->create_shipment( $body );

		if ( is_wp_error( $response ) ) {
			Shipment_Creation::remote_failed( $order, $response );
			return Label_Result::error( $response->get_error_message() );
		}

		$reference = isset( $response['data']['reference_number'] ) ? (string) $response['data']['reference_number'] : '';
		if ( '' === $reference ) {
			Shipment_Creation::remote_failed( $order, $response );
			return Label_Result::error( __( 'Pigeon Express did not return a shipment number.', 'bg-commerce-suite' ) );
		}
		Shipment_Creation::remote_accepted(
			$order,
			array(
				'shipment_number' => $reference,
				'tracking_numbers' => array( $reference ),
				'label_reference' => $reference,
			)
		);

		$result             = new Label_Result();
		$result->success    = true;
		$result->courier    = self::ID;
		$result->number     = $reference;
		$result->created_at = time();
		$result->shipment_number = $reference;
		$result->tracking_numbers = array( $reference );
		$result->label_reference = $reference;
		$result->meta       = array( 'reference' => $reference );
		$read_back = $this->client()->track( $reference );
		$result->meta['read_back_status'] = is_wp_error( $read_back )
			? 'unavailable'
			: ( Shipment_Creation::response_confirms( $read_back, array( 'external_reference' ), Shipment_Reference::for_order( $order ) ) ? 'verified' : 'partial' );

		// The label PDF: the create response embeds the standard thermal label
		// (base64); other formats are fetched from the /label endpoint.
		$format = (string) Module_Settings::get( self::ID, 'label_format' );
		$bytes  = '';

		if ( in_array( $format, array( 'pdf150', 'a4' ), true ) ) {
			$raw = $this->client()->get_label_raw( $reference, $format );
			if ( ! is_wp_error( $raw ) && '' !== $raw ) {
				$bytes = $raw;
			}
		}

		if ( '' === $bytes ) {
			$b64 = isset( $response['data']['label_pdf'] ) ? (string) $response['data']['label_pdf'] : '';
			if ( '' !== $b64 ) {
				$decoded = base64_decode( $b64, true );
				if ( false !== $decoded ) {
					$bytes = $decoded;
				}
			}
		}

		if ( '' !== $bytes ) {
			$url = Label_Pdf_Store::save( self::ID, $reference . '.pdf', $bytes );
			if ( $url ) {
				$result->pdf_url = $url;
			}
		}

		return $result;
	}

	/**
	 * @param \WC_Order $order  Order.
	 * @param string    $number Reference.
	 * @return mixed|\WP_Error
	 */
	protected function cancel_shipment( \WC_Order $order, $number ) {
		return $this->client()->cancel( $number );
	}

	/**
	 * @param string $number Reference.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function fetch_tracking( $number ) {
		return $this->client()->track( $number );
	}

	/**
	 * @param Tracking_Result     $result   Result.
	 * @param array<string,mixed> $response API response.
	 * @return void
	 */
	protected function fill_tracking( Tracking_Result $result, array $response ) {
		$data = isset( $response['data'] ) ? $response['data'] : $response;

		$this->fill_events( $result, is_array( $data ) ? $data : array() );
	}

	/**
	 * Pigeon accept up to 100 references in one `track/bulk` request.
	 *
	 * @return bool
	 */
	public function supports_bulk_tracking() {
		return true;
	}

	/**
	 * Pigeon can report which cash-on-delivery amounts they have paid out.
	 *
	 * Declared duck-typed so the COD report can ask any courier that happens to
	 * offer this, without the accounting add-on knowing Pigeon exists.
	 *
	 * @return bool
	 */
	public function supports_cod_payouts() {
		return true;
	}

	/**
	 * Paid-out COD amounts for a date range.
	 *
	 * @param string $from Start, `Y-m-d`.
	 * @param string $to   End, `Y-m-d`.
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
	 * Well under Pigeon's limit of 100 on purpose: one failed request loses the
	 * whole chunk until the next scan, and a chunk should be small enough that
	 * losing it is not an outage.
	 *
	 * @return int
	 */
	public function tracking_batch_size() {
		return 50;
	}

	/**
	 * One request, many shipments.
	 *
	 * @param string[] $numbers   Reference numbers.
	 * @param bool     $last_only Ignored — Pigeon have no such option.
	 * @return array<string,Tracking_Result> Keyed by reference number.
	 */
	public function bulk_tracking( array $numbers, $last_only = false ) {
		unset( $last_only );

		$response = $this->client()->track_bulk( $numbers );

		if ( is_wp_error( $response ) ) {
			// Rule 256 — a failed call leaves every order in the chunk exactly
			// as it was, rather than marking them all as having no tracking.
			return array();
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$out  = array();

		foreach ( $data as $reference => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			// A reference this account does not own comes back as found:false
			// with an error message. That is an answer about someone else's
			// parcel, not about ours — skip it rather than record an empty
			// history over what the order already knows.
			if ( isset( $node['found'] ) && ! $node['found'] ) {
				continue;
			}

			$result          = new Tracking_Result();
			$result->success = true;
			$this->fill_events( $result, $node );

			$out[ (string) $reference ] = $result;
		}

		return $out;
	}

	/**
	 * Maps Pigeon's status history onto canonical events.
	 *
	 * Shared by the single and the bulk read, so the two cannot describe the
	 * same status differently.
	 *
	 * The `code` must be `status_code` — the machine value. Until 1.6.0 this
	 * stored `status`, which is the HUMAN name („Приета в офис“), and
	 * {@see self::normalize_status()} then compared that against machine codes
	 * it could never match.
	 *
	 * @param Tracking_Result     $result Result.
	 * @param array<string,mixed> $data   One shipment's node.
	 * @return void
	 */
	private function fill_events( Tracking_Result $result, array $data ) {
		$events = array();

		// `tracking` is what both the single and the bulk endpoint return. The
		// rest are kept as a courtesy to older shapes, not because they are
		// documented.
		foreach ( array( 'tracking', 'events', 'statuses', 'history' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$events = $data[ $key ];
				break;
			}
		}

		if ( empty( $events ) && isset( $data[0] ) ) {
			$events = $data;
		}

		foreach ( (array) $events as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}

			$code = '';
			foreach ( array( 'status_code', 'code' ) as $k ) {
				if ( ! empty( $e[ $k ] ) ) {
					$code = (string) $e[ $k ];
					break;
				}
			}

			$time = '';
			foreach ( array( 'created_at', 'timestamp', 'date' ) as $k ) {
				if ( ! empty( $e[ $k ] ) ) {
					$time = (string) $e[ $k ];
					break;
				}
			}

			$text = '';
			foreach ( array( 'status', 'description', 'status_text', 'state' ) as $k ) {
				if ( ! empty( $e[ $k ] ) && is_scalar( $e[ $k ] ) ) {
					$text = (string) $e[ $k ];
					break;
				}
			}

			$result->events[] = array(
				'time' => $time,
				'code' => $code,
				'text' => $text,
			);
		}
	}

	/**
	 * Pigeon status vocabulary currently supported by the integration. Machine
	 * `status_code` values are kept exact so negative outcomes can never be
	 * mistaken for successful delivery through substring matching.
	 *
	 * The two delivery-looking states are deliberately separate:
	 * `shipment_delivered_to_office` means waiting for pickup, while
	 * `shipment_delivered_to_recipient` is the terminal delivered state.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
		// Only this one completes an order under Core's default policy.
		'shipment_delivered_to_recipient' => Tracking_State::DELIVERED,

		'shipment_registered'             => Tracking_State::CREATED,
		'shipment_awaiting_courier_pickup' => Tracking_State::CREATED,

		'shipment_courier_assigned'       => Tracking_State::ACCEPTED,
		'shipment_accepted_by_courier'    => Tracking_State::ACCEPTED,
		'shipment_accepted_in_office'     => Tracking_State::ACCEPTED,

		'shipment_in_sorting_center'      => Tracking_State::IN_TRANSIT,
		'shipment_redirected'             => Tracking_State::REDIRECTED,
		'shipment_redirection_requested'  => Tracking_State::REDIRECTED,
		'shipment_redirection_rejected'   => Tracking_State::IN_TRANSIT,

		'shipment_in_delivery'            => Tracking_State::OUT_FOR_DELIVERY,

		// At the office or locker, waiting to be collected — NOT delivered.
		'shipment_delivered_to_office'    => Tracking_State::AVAILABLE_FOR_PICKUP,
		'shipment_left_in_locker'         => Tracking_State::AVAILABLE_FOR_PICKUP,

		'shipment_returning_to_sender'    => Tracking_State::RETURN_IN_PROGRESS,
		'shipment_returned'               => Tracking_State::RETURNED,
		'shipment_cancelled'              => Tracking_State::CANCELLED,

		// Someone has to look at these.
		'shipment_delivery_attempt_failed' => Tracking_State::DELIVERY_FAILED,
		'shipment_delivery_problem'       => Tracking_State::EXCEPTION,
		'shipment_held_by_sender'         => Tracking_State::EXCEPTION,
		'shipment_untracked'              => Tracking_State::EXCEPTION,
		'shipment_locker_time_expired'    => Tracking_State::EXCEPTION,
		'shipment_storage_expired'        => Tracking_State::EXCEPTION,
		'shipment_abandoned'              => Tracking_State::EXCEPTION,
		'complaint'                       => Tracking_State::EXCEPTION,
		);
	}

	public function normalize_status( array $event ) {
		$code = isset( $event['code'] ) ? strtolower( trim( (string) $event['code'] ) ) : '';
		$map  = self::statuses();

		return isset( $map[ $code ] ) ? $map[ $code ] : Tracking_State::UNKNOWN;
	}

	/**
	 * @param \WC_Order                     $order     Order.
	 * @param \BgCommerce3\Support\Selection $selection Selection.
	 * @return array<string,string>
	 */
	public function order_meta_fields( \WC_Order $order, $selection ) {
		$dt          = $selection->delivery_type;
		$shipping_to = ( 'locker' === $dt ) ? 'APS' : ( ( 'address' === $dt ) ? 'ADDRESS' : 'OFFICE' );

		$fields = array(
			'bgcs3_pigeon_shipping_to' => $shipping_to,
			'bgcs3_pigeon_city'        => isset( $selection->city['name'] ) ? (string) $selection->city['name'] : '',
			'bgcs3_pigeon_post_code'   => isset( $selection->city['post_code'] ) ? (string) $selection->city['post_code'] : '',
		);

		if ( in_array( $dt, array( 'office', 'locker' ), true ) ) {
			$fields['bgcs3_pigeon_office_id']   = isset( $selection->office['id'] ) ? (string) $selection->office['id'] : '';
			$fields['bgcs3_pigeon_office_name'] = isset( $selection->office['text'] ) ? (string) $selection->office['text'] : '';
		} else {
			$fields['bgcs3_pigeon_street'] = isset( $selection->address['street'] ) ? (string) $selection->address['street'] : '';
		}

		$total = \BgCommerce3\Shipping\Order_Persistence::courier_shipping_total( $order, self::ID );
		if ( $total > 0 ) {
			$fields['bgcs3_pigeon_price'] = number_format( $total, 2, '.', '' );
		}

		return $fields;
	}

	/* ------------------------------------------------------------------ */
	/* Body builders                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Pickup block from the merchant settings.
	 *
	 * @return array<string,mixed>
	 */
	private function pickup( array $wb = array() ) {
		$type_override = $this->wbx( $wb, 'pickup_type' );
		$strict_order_type = in_array( $type_override, array( 'office', 'address' ), true );
		$type = in_array( $type_override, array( 'office', 'address' ), true )
			? $type_override
			: (string) Module_Settings::get( self::ID, 'pickup_type' );

		$oid_override = $this->wbx( $wb, 'sender_office_id' );
		$oid = '' !== $oid_override ? (int) $oid_override : (int) bgcs3_get_option( self::ID, 'sender_office_id', 0 );

		if ( 'office' === $type && $oid > 0 ) {
			return array(
				'pickup_type'      => 'office',
				'pickup_office_id' => $oid,
			);
		}
		if ( 'office' === $type && $strict_order_type ) {
			return array();
		}

		$city_override = $this->wbx( $wb, 'sender_city_id' );
		$city = '' !== $city_override ? (int) $city_override : (int) Module_Settings::get( self::ID, 'sender_city_id' );
		if ( $city > 0 ) {
			$address_override = $this->wbx( $wb, 'sender_address' );
			$addr = array(
				'city_id'         => $city,
				'additional_info' => ( '' !== $address_override ? $address_override : (string) Module_Settings::get( self::ID, 'sender_address' ) ) ?: __( 'address', 'bg-commerce-suite' ),
			);
			$sid_override = $this->wbx( $wb, 'sender_street_id' );
			$sid = '' !== $sid_override ? (int) $sid_override : (int) Module_Settings::get( self::ID, 'sender_street_id' );
			if ( $sid > 0 ) {
				$addr['street_id'] = $sid;
			}
			return array(
				'pickup_type'    => 'address',
				'pickup_address' => $addr,
			);
		}
		if ( 'address' === $type && $strict_order_type ) {
			return array();
		}

		if ( $oid > 0 ) {
			return array(
				'pickup_type'      => 'office',
				'pickup_office_id' => $oid,
			);
		}

		return array();
	}

	/**
	 * Delivery block per the selection.
	 *
	 * @param Selection $selection    Selection.
	 * @param bool      $for_shipment Shipment (resolve street id) vs calculate.
	 * @return array<string,mixed>
	 */
	private function delivery( Selection $selection, $for_shipment ) {
		$dt = $selection->delivery_type;

		if ( in_array( $dt, array( 'office', 'locker' ), true ) ) {
			// delivery_type enum: address | office | locker.
			return array(
				'delivery_type'      => ( 'locker' === $dt ) ? 'locker' : 'office',
				'delivery_office_id' => isset( $selection->office['id'] ) ? (int) $selection->office['id'] : 0,
			);
		}

		$address_details = array();
		foreach ( array(
			'block'     => __( 'Block', 'bg-commerce-suite' ),
			'entrance'  => __( 'Entrance', 'bg-commerce-suite' ),
			'floor'     => __( 'Floor', 'bg-commerce-suite' ),
			'apartment' => __( 'Apartment', 'bg-commerce-suite' ),
		) as $address_key => $address_label ) {
			if ( ! empty( $selection->address[ $address_key ] ) ) {
				$address_details[] = $address_label . ': ' . (string) $selection->address[ $address_key ];
			}
		}
		if ( ! empty( $selection->address['note'] ) ) {
			$address_details[] = (string) $selection->address['note'];
		}

		return array(
			'delivery_type'    => 'address',
			'delivery_address' => $this->address_block(
				isset( $selection->city['id'] ) ? (int) $selection->city['id'] : 0,
				isset( $selection->address['street'] ) ? (string) $selection->address['street'] : '',
				isset( $selection->address['num'] ) ? (string) $selection->address['num'] : '',
				implode( ', ', $address_details ),
				$for_shipment
			),
		);
	}

	/**
	 * Address block per Pigeon's Address schema: city_id + (street_id OR
	 * additional_info ≥3 chars), plus street_name / street_number when known.
	 *
	 * @param int    $city_id      City id.
	 * @param string $street       Street name.
	 * @param string $num          House number.
	 * @param string $note         Extra note (floor/apartment…).
	 * @param bool   $for_shipment Resolve a real street id (shipment only).
	 * @return array<string,mixed>
	 */
	private function address_block( $city_id, $street, $num, $note, $for_shipment ) {
		$addr = array( 'city_id' => (int) $city_id );

		if ( '' !== $street ) {
			$addr['street_name'] = $street;
		}
		if ( '' !== $num ) {
			$addr['street_number'] = $num;
		}

		if ( $for_shipment && $city_id > 0 && '' !== $street ) {
			$sid = $this->locations()->resolve_street_id( (string) $city_id, $street );
			if ( $sid > 0 ) {
				$addr['street_id'] = $sid;
			}
		}

		// additional_info is required (min 3 chars) when no street_id is present.
		if ( empty( $addr['street_id'] ) ) {
			$info = trim( $street . ' ' . $num . ( '' !== $note ? ' ' . $note : '' ) );
			if ( mb_strlen( $info ) < 3 ) {
				$info = ( '' !== $street ) ? $street : __( 'address', 'bg-commerce-suite' );
			}
			$addr['additional_info'] = $info;
		} elseif ( '' !== $note ) {
			$addr['additional_info'] = $note;
		}

		return $addr;
	}

	/**
	 * Receiver block from the order + admin overrides.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return array<string,string>|\WP_Error
	 */
	private function receiver( \WC_Order $order, array $wb ) {
		$name  = ! empty( $wb['contact_name'] ) ? (string) $wb['contact_name'] : trim( $order->get_formatted_billing_full_name() );
		$phone = ! empty( $wb['phone'] ) ? (string) $wb['phone'] : trim( (string) $order->get_billing_phone() );
		if ( '' === $phone && method_exists( $order, 'get_shipping_phone' ) ) {
			$phone = trim( (string) $order->get_shipping_phone() );
		}
		$email = ! empty( $wb['email'] ) ? (string) $wb['email'] : (string) $order->get_billing_email();

		if ( '' === $name ) {
			return new \WP_Error( 'bgcs3_pigeon_no_name', __( 'Recipient name is missing.', 'bg-commerce-suite' ) );
		}
		if ( '' === $phone ) {
			return new \WP_Error( 'bgcs3_pigeon_no_phone', __( 'Recipient phone is missing.', 'bg-commerce-suite' ) );
		}

		$receiver = array(
			'receiver_name'  => $name,
			'receiver_phone' => $phone,
		);
		if ( '' !== $email ) {
			$receiver['receiver_email'] = $email;
		}
		return $receiver;
	}

	/**
	 * Service block: service codes, who pays, COD.
	 *
	 * @param float      $amount         COD amount.
	 * @param bool       $is_cod         Cash on delivery.
	 * @param string     $who_pays       sender|receiver.
	 * @param float|null $declared_value Обявена стойност; ако е null, се преизползва
	 *                                   $amount (само за checkout-time quote estimate,
	 *                                   където няма отделен admin override все още).
	 * @return array<string,mixed>
	 */
	private function services_block( $amount, $is_cod, $who_pays, $declared_value = null, array $wb = array() ) {
		if ( null === $declared_value ) {
			$declared_value = $amount;
		}

		$codes       = array();
		$definitions = $this->locations()->service_definitions();
		$groups      = array();

		foreach ( $definitions as $code => $definition ) {
			$type  = isset( $definition['input_type'] ) ? (string) $definition['input_type'] : 'checkbox';
			$group = isset( $definition['option_group'] ) ? trim( (string) $definition['option_group'] ) : '';

			// Money is governed by the canonical Core controls below.
			if ( in_array( $code, array( 'declared_value', 'cod_amount' ), true ) ) {
				continue;
			}

			if ( 'select' === $type && '' !== $group ) {
				$groups[ $group ][ $code ] = isset( $definition['label'] ) ? (string) $definition['label'] : (string) $code;
				continue;
			}

			if ( 'text' === $type ) {
				$value = $this->wbx( $wb, 'svc_' . $code );
				if ( '' === $value ) {
					$value = trim( (string) bgcs3_get_option( self::ID, 'service_' . $code, '' ) );
				}
				if ( '' === $value || '0' === $value ) {
					continue;
				}
				// Both CalculateShipmentRequest and CreateShipmentRequest define
				// service_codes values as boolean|number. Do not send an arbitrary
				// string from a legacy setting into a numeric service slot.
				if ( is_numeric( $value ) ) {
					$codes[ $code ] = (float) $value;
				}
				continue;
			}

			// checkbox and ungrouped select rows are boolean service switches.
			if ( $this->wbx_bool( $wb, 'svc_' . $code, 'service_' . $code, 'no' ) ) {
				$codes[ $code ] = true;
			}
		}

		foreach ( $groups as $group => $members ) {
			$selected = $this->resolve_service_group( $group, $members, $wb );
			if ( '' !== $selected ) {
				$codes[ $selected ] = true;
			}
		}

		// Canonical declared value: a positive Core amount always wins over a
		// dynamic Pigeon setting and is sent as the numeric text-service value.
		if ( (float) $declared_value > 0 ) {
			$codes['declared_value'] = round( (float) $declared_value, 2 );
		}

		if ( $is_cod && $amount > 0 ) {
			$codes['cod_amount'] = round( (float) $amount, 2 );
		}

		$service_type = $this->wbx( $wb, 'service_type' );
		if ( ! in_array( $service_type, array( 'standard', 'express' ), true ) ) {
			$service_type = (string) Module_Settings::get( self::ID, 'service_type' );
		}
		if ( ! in_array( $service_type, array( 'standard', 'express' ), true ) ) {
			$service_type = 'standard';
		}

		return array(
			'service_type'  => $service_type,
			'service_codes' => empty( $codes ) ? new \stdClass() : $codes,
			'who_pays'      => $who_pays,
		);
	}

	/**
	 * The rest of `POST /v1/shipments`: the courier note, our own reference and
	 * the recipient's company block.
	 *
	 * Pigeon treats `receiver_company_name` / `_vat` / `_mol` / `_address` as
	 * ONE block — supply any and all four become required, or the request is
	 * rejected with 422. So an incomplete block is dropped here with an order
	 * note rather than sent and refused; `receiver_company_dds_number` is
	 * independent and always optional.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return array<string,mixed>
	 */
	private function extras_block( \WC_Order $order, array $wb ) {
		$extras = array();

		$note = $this->wbx( $wb, 'note' );
		if ( '' !== $note ) {
			$extras['note'] = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 1000 ) : substr( $note, 0, 1000 );
		}

		// Pigeon echoes this back in the tracking response, which is what makes
		// a shipment traceable to an order without our own lookup table.
		$reference = Shipment_Reference::for_order( $order );
		if ( '' !== $reference ) {
			$extras['external_reference'] = function_exists( 'mb_substr' ) ? mb_substr( $reference, 0, 100 ) : substr( $reference, 0, 100 );
		}

		if ( $this->wbx_bool( $wb, 'return_at_my_expense', 'return_at_my_expense', 'no' ) ) {
			$extras['return_at_my_expense'] = true;
		}

		$company = $this->company_block( $order, $wb );
		if ( ! empty( $company ) ) {
			$extras = array_merge( $extras, $company );
		}

		return $extras;
	}

	/**
	 * The recipient's company fields, or nothing at all.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Waybill overrides.
	 * @return array<string,string>
	 */
	private function company_block( \WC_Order $order, array $wb ) {
		$name = $this->wbx( $wb, 'company_name' );
		if ( '' === $name ) {
			$name = trim( (string) $order->get_billing_company() );
		}

		$block = array(
			'receiver_company_name'    => $name,
			'receiver_company_vat'     => $this->wbx( $wb, 'company_vat' ),
			'receiver_company_mol'     => $this->wbx( $wb, 'company_mol' ),
			'receiver_company_address' => $this->wbx( $wb, 'company_address' ),
		);

		$filled = array_filter( $block, static function ( $value ) {
			return '' !== trim( (string) $value );
		} );

		if ( empty( $filled ) ) {
			return array();
		}

		if ( count( $filled ) < 4 ) {
			// Sending three of four is a guaranteed 422. Failing the whole label
			// over an optional block would be worse, so the shipment goes out
			// for a private person and the order says why.
			$order->add_order_note(
				__( 'Pigeon: the recipient company details were not sent because only some of them were completed. Company, company ID, contact person and address are required together.', 'bg-commerce-suite' )
			);
			return array();
		}

		$dds = $this->wbx( $wb, 'company_dds' );
		if ( '' !== $dds ) {
			$block['receiver_company_dds_number'] = $dds;
		}

		return $block;
	}

	/**
	 * Parcel packages. Real per-pack entries from `_bgcs3_wb['packages']` (the
	 * admin multi-pack editor) take priority when present and complete — any
	 * single incomplete pack discards the whole array, never a partial
	 * payload — otherwise falls back to the legacy behaviour: one shared
	 * weight/dims set, weight split evenly across the requested count.
	 *
	 * @param float               $weight Total weight (kg) — legacy path only.
	 * @param array<string,mixed> $wb     Waybill overrides.
	 * @return array<int,array<string,mixed>>
	 */
	private function packages( $weight, array $wb ) {
		$real = $this->real_packages( ( ! empty( $wb['packages'] ) && is_array( $wb['packages'] ) ) ? $wb['packages'] : array() );
		if ( ! empty( $real ) ) {
			return $real;
		}

		$count = ( isset( $wb['parcels'] ) && (int) $wb['parcels'] > 1 ) ? (int) $wb['parcels'] : 1;
		$each  = max( 0.01, round( $weight / $count, 3 ) );

		$width  = (int) ( ! empty( $wb['width'] ) ? $wb['width'] : Module_Settings::get( self::ID, 'default_width' ) );
		$length = (int) ( ! empty( $wb['depth'] ) ? $wb['depth'] : Module_Settings::get( self::ID, 'default_length' ) );
		$height = (int) ( ! empty( $wb['height'] ) ? $wb['height'] : Module_Settings::get( self::ID, 'default_height' ) );

		$one = array(
			'weight' => $each,
			'width'  => max( 1, $width ),
			'length' => max( 1, $length ),
			'height' => max( 1, $height ),
		);

		return array_fill( 0, $count, $one );
	}

	/**
	 * Converts `_bgcs3_wb['packages']` (our courier-agnostic multi-pack editor
	 * shape: `length`/`width`/`height`/`weight` per pack) into Pigeon's own
	 * `{weight,width,length,height}` package entries.
	 *
	 * @param array<int,array<string,mixed>> $packages Packs from the admin editor.
	 * @return array<int,array<string,mixed>>
	 */
	private function real_packages( array $packages ) {
		if ( empty( $packages ) ) {
			return array();
		}

		$entries = array();
		foreach ( $packages as $pack ) {
			$length = isset( $pack['length'] ) ? (float) $pack['length'] : 0.0;
			$width  = isset( $pack['width'] ) ? (float) $pack['width'] : 0.0;
			$height = isset( $pack['height'] ) ? (float) $pack['height'] : 0.0;
			$weight = isset( $pack['weight'] ) ? (float) $pack['weight'] : 0.0;

			if ( $length <= 0 || $width <= 0 || $height <= 0 || $weight <= 0 ) {
				return array();
			}

			$entries[] = array(
				'weight' => max( 0.01, round( $weight, 3 ) ),
				'width'  => max( 1, (int) ceil( $width ) ),
				'length' => max( 1, (int) ceil( $length ) ),
				'height' => max( 1, (int) ceil( $height ) ),
			);
		}

		return $entries;
	}

	/**
	 * Product descriptions required by the Pigeon calculate endpoint.
	 *
	 * @param array<string,mixed> $package WooCommerce shipping package.
	 * @return array<int,array<string,mixed>>
	 */
	private function package_inventory_items( array $package ) {
		$items = array();

		if ( ! empty( $package['contents'] ) && is_array( $package['contents'] ) ) {
			foreach ( $package['contents'] as $cart_item ) {
				if ( ! is_array( $cart_item ) || empty( $cart_item['data'] ) || ! is_a( $cart_item['data'], 'WC_Product' ) ) {
					continue;
				}

				$name = trim( wp_strip_all_tags( (string) $cart_item['data']->get_name() ) );
				if ( '' === $name ) {
					continue;
				}

				$items[] = array(
					'description' => mb_substr( $name, 0, 200 ),
					'quantity'    => max( 1, isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1 ),
				);
			}
		}

		return ! empty( $items ) ? $items : array( array( 'description' => __( 'Goods', 'bg-commerce-suite' ), 'quantity' => 1 ) );
	}

	/**
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Overrides.
	 * @return array<int,array<string,mixed>>
	 */
	private function inventory_items( \WC_Order $order, array $wb ) {
		if ( ! empty( $wb['contents'] ) ) {
			return array(
				array(
					'description' => mb_substr( (string) $wb['contents'], 0, 200 ),
					'quantity'    => 1,
				),
			);
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'description' => $item->get_name(),
				'quantity'    => (int) $item->get_quantity(),
			);
		}
		return ! empty( $items ) ? $items : array( array( 'description' => __( 'Goods', 'bg-commerce-suite' ), 'quantity' => 1 ) );
	}

	/**
	 * @param array<string,mixed> $wb     Overrides.
	 * @param bool                $is_cod COD.
	 * @return string sender|receiver
	 */
	private function who_pays( array $wb, $is_cod ) {
		if ( ! empty( $wb['payer'] ) ) {
			return ( 'SENDER' === $wb['payer'] ) ? 'sender' : 'receiver';
		}
		$default = (string) Module_Settings::get( self::ID, 'who_pays' );
		if ( in_array( $default, array( 'sender', 'receiver' ), true ) ) {
			return $default;
		}
		return 'sender';
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private function is_cod_chosen() {
		return Cod::is_chosen();
	}

	private function is_cod_order( \WC_Order $order ) {
		return Cod::is_order( $order );
	}	/**
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Ръчни стойности от панела за товарителница.
	 * @return float
	 */
	private function cod_amount( \WC_Order $order, array $wb ) {
		// Tri-state admin override (Overrides::INHERIT/CUSTOM/DISABLED). Празно/
		// липсващо поле НЕ гаси НП само по себе си — само изричен `cod_mode=disabled`
		// го прави (Master Instruction Rule 15 — same regression class as Speedy).
		return Cod::resolve_amount( $order, $wb );
	}

	/**
	 * Обявена стойност — отделен override от НП (не преизползвайте COD сумата тук,
	 * това беше самостоятелен bug: prepaid поръчка с обявена стойност винаги
	 * получаваше 0, защото се подаваше COD сумата, която е 0 за non-COD поръчки).
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Per-order shipment overrides.
	 * @return float
	 */
	private function declared_value( \WC_Order $order, array $wb ) {
		$default = 'yes' === (string) bgcs3_get_option( self::ID, 'service_declared_value', 'no' ) ? (float) $order->get_total() : 0.0;
		return Overrides::resolve( $wb, 'dv_mode', 'declared_value', $default );
	}

	/**
	 * @param array<string,mixed> $package Package.
	 * @return float
	 */
	private function package_weight( array $package ) {
		return Weight::for_package( self::ID, $package );
	}

	/**
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $wb    Overrides.
	 * @return float
	 */
	private function order_weight( \WC_Order $order, array $wb ) {
		// Ръчно въведено тегло в панела на товарителницата бие изчисленото.
		if ( ! empty( $wb['weight'] ) ) {
			return max( Weight::MIN_KG, (float) $wb['weight'] );
		}
		return Weight::for_order( self::ID, $order );
	}
}
