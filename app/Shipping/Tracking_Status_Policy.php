<?php
/**
 * Merchant policy layer: canonical BGCS shipment state -> WooCommerce status.
 * Tracking state and order state are deliberately separate concepts.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Modules\Shipping\Courier_Interface;

defined( 'ABSPATH' ) || exit;

final class Tracking_Status_Policy {

	const DECISION_META = '_bgcs3_tracking_status_decision';

	/**
	 * Safe Core defaults. Only successful delivery changes WooCommerce by default.
	 * Legacy global delivered/returned choices remain a fallback until a merchant
	 * saves the per-courier mapping introduced in 3.0.16.
	 *
	 * @return array<string,string|null>
	 */
	private static function default_map() {
		$delivered_status = function_exists( 'bgcs3_get_option' ) ? (string) bgcs3_get_option( 'checkout', 'status_on_delivered', 'completed' ) : 'completed';
		$returned_status  = function_exists( 'bgcs3_get_option' ) ? (string) bgcs3_get_option( 'checkout', 'status_on_returned', '' ) : '';

		return array(
			Tracking_State::CREATED              => null,
			Tracking_State::ACCEPTED             => null,
			Tracking_State::IN_TRANSIT           => null,
			Tracking_State::OUT_FOR_DELIVERY     => null,
			Tracking_State::AVAILABLE_FOR_PICKUP => null,
			Tracking_State::DELIVERED            => '' !== $delivered_status ? $delivered_status : 'completed',
			Tracking_State::DELIVERY_FAILED      => null,
			Tracking_State::REDIRECTED           => null,
			Tracking_State::RETURN_IN_PROGRESS   => null,
			Tracking_State::RETURNED             => '' !== $returned_status ? $returned_status : null,
			Tracking_State::CANCELLED            => null,
			Tracking_State::EXCEPTION            => null,
			Tracking_State::UNKNOWN              => null,
		);
	}

	/**
	 * Per-courier policy fields injected into that courier's Tracking tab.
	 * Only states documented/implemented for that courier are shown.
	 *
	 * @param Courier_Interface $module Courier module.
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields_for( Courier_Interface $module ) {
		$options = array( '' => __( '— No change —', 'bg-commerce-suite' ) );
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array(
			'wc-processing' => __( 'Processing', 'bg-commerce-suite' ),
			'wc-completed'  => __( 'Completed', 'bg-commerce-suite' ),
			'wc-on-hold'    => __( 'On hold', 'bg-commerce-suite' ),
			'wc-failed'     => __( 'Failed', 'bg-commerce-suite' ),
			'wc-cancelled'  => __( 'Cancelled', 'bg-commerce-suite' ),
			'wc-refunded'   => __( 'Refunded', 'bg-commerce-suite' ),
		);
		foreach ( $statuses as $slug => $label ) {
			$options[ str_replace( 'wc-', '', (string) $slug ) ] = (string) $label;
		}

		$defaults = self::default_map();
		$fields = array();
		foreach ( Tracking_Status_Catalog::supported_states( $module->id() ) as $state ) {
			// CREATED is useful for tracking, but changing an existing WC order back
			// to a created state is rarely meaningful and is deliberately not offered.
			if ( Tracking_State::CREATED === $state ) {
				continue;
			}
			$key = self::option_key( $state );
			$default = isset( $defaults[ $state ] ) && null !== $defaults[ $state ] ? (string) $defaults[ $state ] : '';
			$fields[ $key ] = array(
				'type'        => 'select',
				'label'       => sprintf( __( 'When “%s”', 'bg-commerce-suite' ), Tracking_State::label( $state ) ),
				'default'     => $default,
				'options'     => $options,
				'description' => Tracking_Status_Catalog::availability_note( $state ),
			);
		}
		return $fields;
	}

	/** @return string[] */
	public static function field_keys_for( Courier_Interface $module ) {
		return array_keys( self::fields_for( $module ) );
	}

	/**
	 * Text describing provider-only raw states for the current courier.
	 *
	 * @param string $courier_id Courier id.
	 * @return string
	 */
	public static function provider_detail_note( $courier_id ) {
		$all = Tracking_Status_Catalog::provider_details();
		$items = isset( $all[ $courier_id ] ) ? $all[ $courier_id ] : array();
		if ( empty( $items ) ) {
			return '';
		}
		return sprintf(
			__( 'Provider-specific events for %1$s are kept in tracking and normalized to the closest BGCS state: %2$s.', 'bg-commerce-suite' ),
			Tracking_Status_Catalog::courier_name( $courier_id ),
			implode( '; ', $items )
		);
	}

	/**
	 * @param string $state Canonical state.
	 * @return string
	 */
	private static function option_key( $state ) {
		return 'wc_status_' . sanitize_key( str_replace( '-', '_', (string) $state ) );
	}

	/**
	 * Resolve latest non-UNKNOWN normalized state from event history.
	 *
	 * @param Courier_Interface                   $courier Courier.
	 * @param array<int,array<string,mixed>>      $events Events.
	 * @return string
	 */
	public static function latest_state( $courier, array $events ) {
		foreach ( Tracking_Store::sort_by_time( $events, true ) as $event ) {
			$state = Tracking_State::sanitize( $courier->normalize_status( (array) $event ) );
			if ( Tracking_State::UNKNOWN !== $state ) {
				return $state;
			}
		}
		return Tracking_State::UNKNOWN;
	}

	/**
	 * Resolve canonical state to the configured WooCommerce status for one courier.
	 *
	 * @param string $normalized_state Canonical state.
	 * @param string $courier_id Courier id.
	 * @return string|null
	 */
	public static function resolve( $normalized_state, $courier_id = '' ) {
		$state = Tracking_State::sanitize( $normalized_state );
		if ( Tracking_State::UNKNOWN === $state ) {
			return null;
		}

		$map = (array) apply_filters( 'bgcs3_tracking_status_policy_map', self::default_map(), $courier_id );
		$fallback = isset( $map[ $state ] ) ? $map[ $state ] : null;

		$courier_id = sanitize_key( (string) $courier_id );
		if ( '' !== $courier_id && in_array( $state, Tracking_Status_Catalog::supported_states( $courier_id ), true ) ) {
			$key = self::option_key( $state );
			$stored_group = function_exists( 'bgcs3_get_option' ) ? bgcs3_get_option( $courier_id, null, array() ) : array();
			if ( is_array( $stored_group ) && array_key_exists( $key, $stored_group ) ) {
				$value = sanitize_key( (string) $stored_group[ $key ] );
				return '' !== $value ? $value : null;
			}
		}

		return null !== $fallback && '' !== (string) $fallback ? sanitize_key( (string) $fallback ) : null;
	}

	/**
	 * Statuses the automation will not move an order OUT of.
	 *
	 * These are not "final" in WooCommerce's sense — they are states that record
	 * a decision a human already made about money. `completed` is deliberately
	 * absent: it is where the automation normally lands, not something to defend.
	 *
	 * Note what is NOT here. `on-hold` is a working status (awaiting a bank
	 * transfer, awaiting stock), and shops legitimately rely on delivery
	 * completing such orders. A shop where on-hold means "payment unconfirmed"
	 * and must never auto-complete should add it through the filter below.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $courier_id Courier id.
	 * @return bool
	 */
	private static function is_protected_status( \WC_Order $order, $courier_id ) {
		/**
		 * Filter the statuses courier automation may not move an order out of.
		 *
		 * A shop with custom terminal statuses — or one that treats `on-hold` as
		 * "payment not confirmed" — extends this. Returning an empty array
		 * restores the pre-3.0.49 behaviour of always applying the mapping.
		 *
		 * @param string[]  $statuses   Protected statuses, without the `wc-` prefix.
		 * @param \WC_Order $order      Order being evaluated.
		 * @param string    $courier_id Courier reporting the event.
		 */
		$protected = apply_filters(
			'bgcs3_tracking_protected_statuses',
			array( 'refunded', 'cancelled', 'failed' ),
			$order,
			$courier_id
		);

		if ( ! is_array( $protected ) ) {
			return false;
		}

		$protected = array_map(
			static function ( $status ) {
				// Accept both `wc-refunded` and `refunded`, since WooCommerce
				// itself is inconsistent about which form it hands out.
				return sanitize_key( preg_replace( '/^wc-/', '', (string) $status ) );
			},
			$protected
		);

		return in_array( sanitize_key( (string) $order->get_status() ), $protected, true );
	}

	/**
	 * Human-readable name for a WooCommerce status, for order notes.
	 *
	 * @param string $status Status slug, with or without the `wc-` prefix.
	 * @return string
	 */
	private static function status_label( $status ) {
		$status = sanitize_key( preg_replace( '/^wc-/', '', (string) $status ) );

		if ( function_exists( 'wc_get_order_status_name' ) ) {
			return (string) wc_get_order_status_name( $status );
		}

		return $status;
	}

	/** PII-free identity for idempotent automation decisions. */
	private static function decision( \WC_Order $order, $state, $courier_id, $new_status, $action ) {
		$label = $order->get_meta( '_bgcs3_label' );
		$reference = '';
		if ( is_array( $label ) ) {
			$reference = ! empty( $label['meta']['shipment_reference'] )
				? (string) $label['meta']['shipment_reference']
				: ( ! empty( $label['number'] ) ? (string) $label['number'] : '' );
		}

		return array(
			'courier'  => sanitize_key( (string) $courier_id ),
			'reference' => sanitize_text_field( $reference ),
			'state'    => Tracking_State::sanitize( $state ),
			'current'  => sanitize_key( (string) $order->get_status() ),
			'target'   => sanitize_key( (string) $new_status ),
			'action'   => sanitize_key( (string) $action ),
		);
	}

	/**
	 * Apply configured status automation to an order. Tracking persistence is
	 * independent; this method is a no-op unless the global automation switch is on.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $state Canonical state.
	 * @param string    $courier_id Courier id.
	 * @return bool True when the WooCommerce status changed.
	 */
	public static function apply_to_order( \WC_Order $order, $state, $courier_id ) {
		$state = Tracking_State::sanitize( $state );
		if ( Tracking_State::UNKNOWN === $state || 'yes' !== bgcs3_get_option( 'checkout', 'update_order_statuses', 'no' ) ) {
			return false;
		}
		$new_status = self::resolve( $state, $courier_id );
		if ( null === $new_status || '' === $new_status || $order->get_status() === $new_status ) {
			return false;
		}

		// BGCS-AUDIT-008 — a transition, not an assignment. Some statuses record a
		// financial decision the merchant has already made, and a courier event
		// arriving late (or replayed from history) must not undo it: a refunded
		// order silently becoming „completed“ sends the customer a
		// „your order is complete“ email and misstates revenue.
		if ( self::is_protected_status( $order, $courier_id ) ) {
			$decision = self::decision( $order, $state, $courier_id, $new_status, 'protected' );
			if ( $decision === $order->get_meta( self::DECISION_META ) ) {
				return false;
			}
			$order->update_meta_data( self::DECISION_META, $decision );
			$order->add_order_note(
				sprintf(
					/* translators: 1: courier name, 2: shipment state, 3: current order status, 4: the status that was not applied. */
					__( 'BGCS: %1$s reported “%2$s”, but this order is “%3$s” — the status was left unchanged. Move it to “%4$s” yourself if that is correct.', 'bg-commerce-suite' ),
					Tracking_Status_Catalog::courier_name( $courier_id ),
					Tracking_State::label( $state ),
					self::status_label( $order->get_status() ),
					self::status_label( $new_status )
				)
			);
			return false;
		}

		$order->update_meta_data( self::DECISION_META, self::decision( $order, $state, $courier_id, $new_status, 'applied' ) );
		$order->update_status(
			$new_status,
			sprintf(
				__( 'BGCS: the shipment with %1$s is “%2$s”; the order was moved to “%3$s”.', 'bg-commerce-suite' ),
				Tracking_Status_Catalog::courier_name( $courier_id ),
				Tracking_State::label( $state ),
				$new_status
			)
		);
		return true;
	}

}
