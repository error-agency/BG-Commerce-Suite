<?php
/**
 * Crash-safe cancellation and replacement history for courier shipments.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Support\Label_Pdf_Store;
use BgCommerce3\Support\Label_Result;

defined( 'ABSPATH' ) || exit;

final class Shipment_Mutation {

	const META_KEY    = '_bgcs3_mutation';
	const HISTORY_KEY = '_bgcs3_shipment_history';

	const CANCEL_PREPARING = 'cancel_preparing';
	const CANCEL_PENDING   = 'cancel_pending';
	const CANCEL_CONFIRMED = 'cancel_confirmed';
	const CANCEL_FAILED    = 'cancel_failed';
	const CANCEL_AMBIGUOUS = 'cancel_ambiguous';
	const CANCELLED        = 'cancelled';

	/**
	 * Start an explicit cancellation while the per-order creation lock is held.
	 * A previously confirmed remote cancellation is deliberately retained so a
	 * request interrupted before local cleanup can finish without a second API call.
	 *
	 * @param \WC_Order $order   Order.
	 * @param object    $courier Courier module.
	 * @return true|Label_Result
	 */
	public static function start_cancel( \WC_Order $order, $courier ) {
		$label = self::label( $order );
		if ( empty( $label['number'] ) ) {
			return Label_Result::error( __( 'There is no active shipment to cancel.', 'bg-commerce-suite' ) );
		}

		$existing = self::state( $order );
		$status   = isset( $existing['status'] ) ? (string) $existing['status'] : '';
		$same     = self::same_shipment( $existing, $label );

		if ( $same && self::CANCEL_CONFIRMED === $status ) {
			return true;
		}
		if ( $same && in_array( $status, array( self::CANCEL_PENDING, self::CANCEL_AMBIGUOUS ), true ) ) {
			return Label_Result::error( self::blocked_message() );
		}

		$courier_id = is_object( $courier ) && method_exists( $courier, 'id' ) ? sanitize_key( $courier->id() ) : '';
		$environment = is_object( $courier ) && method_exists( $courier, 'preflight_environment' )
			? sanitize_key( $courier->preflight_environment() )
			: ( isset( $label['environment'] ) ? sanitize_key( (string) $label['environment'] ) : '' );

		$order->update_meta_data(
			self::META_KEY,
			array(
				'schema'            => 1,
				'action'            => 'cancel',
				'status'            => self::CANCEL_PREPARING,
				'courier'           => $courier_id,
				'environment'       => $environment,
				'reference'         => self::reference( $order, $label ),
				'edition'           => Shipment_Reference::edition( $order ),
				'identity'          => self::identity( $label ),
				'verification'      => '',
				'error_type'        => '',
				'error_code'        => '',
				'started_at'        => time(),
				'remote_started_at' => 0,
				'confirmed_at'      => 0,
				'finished_at'       => 0,
			)
		);
		$order->save();
		return true;
	}

	/** Mark the exact point immediately before the provider cancel request. */
	public static function remote_started( \WC_Order $order, $courier = null ) {
		$label = self::label( $order );
		$state = self::state( $order );
		if ( empty( $state ) || ! self::same_shipment( $state, $label ) ) {
			$started = self::start_cancel( $order, $courier );
			if ( true !== $started ) {
				return false;
			}
			$state = self::state( $order );
		}

		if ( in_array( isset( $state['status'] ) ? $state['status'] : '', array( self::CANCEL_PENDING, self::CANCEL_CONFIRMED, self::CANCEL_AMBIGUOUS, self::CANCELLED ), true ) ) {
			return false;
		}

		$state['status']            = self::CANCEL_PENDING;
		$state['remote_started_at'] = time();
		$state['error_type']        = '';
		$state['error_code']        = '';
		self::persist( $order, $state );
		return true;
	}

	/** Persist a safe failure classification without provider prose. */
	public static function remote_failed( \WC_Order $order, $error ) {
		$state                = self::state( $order );
		$ambiguous            = self::is_ambiguous_error( $error );
		$state['status']       = $ambiguous ? self::CANCEL_AMBIGUOUS : self::CANCEL_FAILED;
		$state['error_type']   = self::error_type( $error, $ambiguous );
		$state['error_code']   = is_wp_error( $error ) ? sanitize_key( $error->get_error_code() ) : 'unclassified_cancel_result';
		$state['finished_at']  = time();
		self::persist( $order, $state );
	}

	/** Record confirmed provider cancellation before local metadata is changed. */
	public static function remote_confirmed( \WC_Order $order, $verification = 'provider_response' ) {
		$state                   = self::state( $order );
		$state['status']          = self::CANCEL_CONFIRMED;
		$state['verification']    = sanitize_key( (string) $verification );
		$state['confirmed_at']    = time();
		$state['finished_at']     = 0;
		self::persist( $order, $state );
	}

	/** Preserve an unresolved provider boundary if a courier returned only false. */
	public static function finalize_failure( \WC_Order $order ) {
		$state  = self::state( $order );
		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		if ( self::CANCEL_PREPARING === $status ) {
			$state['status']     = self::CANCEL_FAILED;
			$state['error_type'] = Courier_Error::UNKNOWN;
			$state['error_code'] = 'cancel_not_started';
			$state['finished_at'] = time();
			self::persist( $order, $state );
		} elseif ( self::CANCEL_PENDING === $status ) {
			self::remote_failed( $order, null );
		}
	}

	/** A thrown exception after the provider boundary is always ambiguous. */
	public static function finalize_exception( \WC_Order $order ) {
		$state  = self::state( $order );
		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		if ( self::CANCEL_PENDING === $status ) {
			self::remote_failed( $order, null );
		} elseif ( self::CANCEL_PREPARING === $status ) {
			self::finalize_failure( $order );
		}
	}

	/**
	 * Archive the confirmed shipment, remove active metadata and advance the
	 * edition in one WooCommerce CRUD save. Returns false unless remote cancel
	 * confirmation is already durable.
	 */
	public static function complete_cancel( \WC_Order $order ) {
		$state = self::state( $order );
		$label = self::label( $order );
		if ( self::CANCEL_CONFIRMED !== ( isset( $state['status'] ) ? $state['status'] : '' ) || empty( $label['number'] ) ) {
			return false;
		}

		$history = self::history( $order );
		$entry   = array(
			'schema'              => 1,
			'status'              => self::CANCELLED,
			'courier'             => isset( $state['courier'] ) ? (string) $state['courier'] : '',
			'environment'         => isset( $state['environment'] ) ? (string) $state['environment'] : '',
			'reference'           => isset( $state['reference'] ) ? (string) $state['reference'] : self::reference( $order, $label ),
			'edition'             => isset( $state['edition'] ) ? (int) $state['edition'] : Shipment_Reference::edition( $order ),
			'identity'            => self::identity( $label ),
			'payload_fingerprint' => isset( $label['payload_fingerprint'] ) ? sanitize_text_field( (string) $label['payload_fingerprint'] ) : '',
			'label_created_at'    => isset( $label['created_at'] ) ? (int) $label['created_at'] : 0,
			'cancelled_at'        => time(),
			'verification'        => isset( $state['verification'] ) ? sanitize_key( (string) $state['verification'] ) : '',
		);

		if ( empty( $history ) || ! self::history_contains( $history, $entry['reference'] ) ) {
			$history[] = $entry;
			$history   = array_slice( $history, -25 );
		}

		$old_number = (string) $label['number'];
		$courier_id = (string) $entry['courier'];
		Shipment_Reference::bump_edition( $order );
		$state['status']       = self::CANCELLED;
		$state['finished_at']  = time();
		$state['next_edition'] = Shipment_Reference::edition( $order );
		$order->update_meta_data( self::HISTORY_KEY, $history );
		$order->update_meta_data( self::META_KEY, $state );
		$order->delete_meta_data( Shipment_Creation::META_KEY );
		$order->delete_meta_data( '_bgcs3_label' );
		$order->delete_meta_data( '_bgcs3_tracking' );
		$order->save();

		Label_Pdf_Store::delete( $courier_id, $old_number . '.pdf' );
		return true;
	}

	/** @return array<string,mixed> */
	public static function state( \WC_Order $order ) {
		$state = $order->get_meta( self::META_KEY );
		return is_array( $state ) ? $state : array();
	}

	/** @return array<int,array<string,mixed>> */
	public static function history( \WC_Order $order ) {
		$history = $order->get_meta( self::HISTORY_KEY );
		return is_array( $history ) ? array_values( array_filter( $history, 'is_array' ) ) : array();
	}

	/** Merchant-safe status text for the current mutation. */
	public static function status_message( \WC_Order $order ) {
		$state  = self::state( $order );
		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		if ( self::CANCEL_AMBIGUOUS === $status || self::CANCEL_PENDING === $status ) {
			return self::blocked_message();
		}
		if ( self::CANCEL_FAILED === $status ) {
			return __( 'The courier did not confirm the cancellation. The shipment remains active in BGCS and no replacement shipment was created.', 'bg-commerce-suite' );
		}
		return __( 'Cancellation failed. The shipment remains active.', 'bg-commerce-suite' );
	}

	private static function blocked_message() {
		return __( 'The previous cancellation result is uncertain. The shipment remains active in BGCS and replacement is blocked. Check the courier portal before taking another action.', 'bg-commerce-suite' );
	}

	/** @return array<string,mixed> */
	private static function label( \WC_Order $order ) {
		$label = $order->get_meta( '_bgcs3_label' );
		return is_array( $label ) ? $label : array();
	}

	private static function reference( \WC_Order $order, array $label ) {
		return ! empty( $label['meta']['shipment_reference'] )
			? sanitize_text_field( (string) $label['meta']['shipment_reference'] )
			: Shipment_Reference::for_order( $order );
	}

	/** @return array<string,mixed> */
	private static function identity( array $label ) {
		$number = isset( $label['number'] ) ? sanitize_text_field( (string) $label['number'] ) : '';
		return array(
			'shipment_number' => ! empty( $label['shipment_number'] ) ? sanitize_text_field( (string) $label['shipment_number'] ) : $number,
			'parcel_ids'      => self::string_list( isset( $label['parcel_ids'] ) ? $label['parcel_ids'] : ( isset( $label['meta']['parcel_ids'] ) ? $label['meta']['parcel_ids'] : array() ) ),
			'tracking_numbers' => self::string_list( isset( $label['tracking_numbers'] ) ? $label['tracking_numbers'] : array( $number ) ),
			'label_reference' => ! empty( $label['label_reference'] ) ? sanitize_text_field( (string) $label['label_reference'] ) : $number,
		);
	}

	private static function same_shipment( array $state, array $label ) {
		$state_number = isset( $state['identity']['shipment_number'] ) ? (string) $state['identity']['shipment_number'] : '';
		$label_number = ! empty( $label['shipment_number'] ) ? (string) $label['shipment_number'] : ( isset( $label['number'] ) ? (string) $label['number'] : '' );
		return '' !== $state_number && '' !== $label_number && hash_equals( $state_number, $label_number );
	}

	private static function history_contains( array $history, $reference ) {
		foreach ( $history as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['reference'] ) && hash_equals( (string) $entry['reference'], (string) $reference ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] */
	private static function string_list( $values ) {
		$out = array();
		foreach ( (array) $values as $value ) {
			$value = sanitize_text_field( (string) $value );
			if ( '' !== $value ) {
				$out[] = $value;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function is_ambiguous_error( $error ) {
		if ( $error instanceof Courier_Error ) {
			return ! in_array( $error->type(), array( Courier_Error::VALIDATION, Courier_Error::AUTHENTICATION, Courier_Error::CONFIGURATION, Courier_Error::NOT_FOUND ), true );
		}
		return ! is_wp_error( $error );
	}

	private static function error_type( $error, $ambiguous ) {
		if ( $error instanceof Courier_Error ) {
			return $error->type();
		}
		return $ambiguous ? Courier_Error::UNKNOWN : Courier_Error::VALIDATION;
	}

	private static function persist( \WC_Order $order, array $state ) {
		$order->update_meta_data( self::META_KEY, $state );
		$order->save();
	}
}
