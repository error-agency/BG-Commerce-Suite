<?php
/**
 * Crash-safe state for one real courier shipment creation edition.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Support\Label_Result;

defined( 'ABSPATH' ) || exit;

final class Shipment_Creation {

	const META_KEY = '_bgcs3_creation';

	const PREPARING     = 'preparing';
	const REMOTE_PENDING = 'remote_pending';
	const ACCEPTED      = 'accepted';
	const AMBIGUOUS     = 'ambiguous';
	const FAILED        = 'failed';
	const CREATED       = 'created';

	/**
	 * Start one local creation attempt, refusing unresolved state for the same
	 * shipment edition. The database Creation_Lock must already be held.
	 *
	 * @param \WC_Order $order   Order.
	 * @param object    $courier Courier module.
	 * @return true|Label_Result
	 */
	public static function start( \WC_Order $order, $courier ) {
		$active_label = $order->get_meta( '_bgcs3_label' );
		if ( is_array( $active_label ) && ! empty( $active_label['number'] ) ) {
			return Label_Result::error( __( 'There is already an active shipment label for this order. Use Print/Download/Cancel — do not create a new one until the current shipment is cancelled.', 'bg-commerce-suite' ) );
		}

		$mutation = Shipment_Mutation::state( $order );
		$mutation_status = isset( $mutation['status'] ) ? (string) $mutation['status'] : '';
		if ( in_array( $mutation_status, array( Shipment_Mutation::CANCEL_PREPARING, Shipment_Mutation::CANCEL_PENDING, Shipment_Mutation::CANCEL_CONFIRMED, Shipment_Mutation::CANCEL_FAILED, Shipment_Mutation::CANCEL_AMBIGUOUS ), true ) ) {
			return Label_Result::error( __( 'BGCS cannot create a replacement because cancellation of the previous shipment is not completed. The previous shipment remains active until cancellation is confirmed and archived.', 'bg-commerce-suite' ) );
		}

		$reference = Shipment_Reference::for_order( $order );
		$existing  = self::state( $order );
		$status    = isset( $existing['status'] ) ? (string) $existing['status'] : '';
		$same      = ! empty( $existing['reference'] ) && hash_equals( (string) $existing['reference'], $reference );

		if ( $same && in_array( $status, array( self::REMOTE_PENDING, self::ACCEPTED, self::AMBIGUOUS, self::CREATED ), true ) ) {
			return Label_Result::error(
				__( 'BGCS cannot create another shipment for this order because the previous create attempt may already exist at the courier. Check the courier portal and resolve/cancel that shipment before creating a new one.', 'bg-commerce-suite' )
			);
		}

		$courier_id = is_object( $courier ) && method_exists( $courier, 'id' ) ? sanitize_key( $courier->id() ) : '';
		$environment = is_object( $courier ) && method_exists( $courier, 'preflight_environment' )
			? sanitize_key( $courier->preflight_environment() )
			: '';

		$order->update_meta_data(
			self::META_KEY,
			array(
				'schema'              => 1,
				'status'              => self::PREPARING,
				'courier'             => $courier_id,
				'environment'         => $environment,
				'reference'           => $reference,
				'edition'             => Shipment_Reference::edition( $order ),
				'payload_fingerprint' => '',
				'identity'            => array(),
				'error_type'          => '',
				'error_code'          => '',
				'started_at'          => time(),
				'remote_started_at'   => 0,
				'accepted_at'         => 0,
				'finished_at'         => 0,
			)
		);
		$order->save();
		return true;
	}

	/**
	 * Mark the exact point immediately before a destructive provider request.
	 * Direct create_label() callers that bypass Core's admin handlers still get
	 * the same lifecycle guard when the courier object is supplied.
	 *
	 * @param \WC_Order $order   Order.
	 * @param object    $courier Courier module.
	 * @return true|Label_Result
	 */
	public static function remote_started( \WC_Order $order, $courier = null ) {
		$state                        = self::state( $order );
		$reference                    = Shipment_Reference::for_order( $order );
		if ( empty( $state ) || empty( $state['reference'] ) || ! hash_equals( (string) $state['reference'], $reference ) ) {
			$started = self::start( $order, $courier );
			if ( true !== $started ) {
				return $started;
			}
			$state = self::state( $order );
		}
		if ( in_array( isset( $state['status'] ) ? $state['status'] : '', array( self::REMOTE_PENDING, self::ACCEPTED, self::AMBIGUOUS, self::CREATED ), true ) ) {
			return Label_Result::error( __( 'BGCS cannot create another shipment for this order because the previous create attempt may already exist at the courier. Check the courier portal and resolve/cancel that shipment before creating a new one.', 'bg-commerce-suite' ) );
		}
		$state['status']              = self::REMOTE_PENDING;
		$state['remote_started_at']   = time();
		$state['payload_fingerprint'] = self::preflight_fingerprint( $order );
		self::persist( $order, $state );
		return true;
	}

	/**
	 * Record a provider refusal/transport failure after the destructive boundary.
	 * Permanent, explicit rejections are retryable after correction; transport,
	 * conflict and malformed outcomes stay ambiguous and block blind retry.
	 *
	 * @param \WC_Order $order Order.
	 * @param mixed     $error Provider error or malformed response.
	 * @return void
	 */
	public static function remote_failed( \WC_Order $order, $error ) {
		$state              = self::state( $order );
		$ambiguous          = self::is_ambiguous_error( $error );
		$state['status']     = $ambiguous ? self::AMBIGUOUS : self::FAILED;
		$state['error_type'] = self::error_type( $error, $ambiguous );
		$state['error_code'] = is_wp_error( $error ) ? sanitize_key( $error->get_error_code() ) : 'malformed_response';
		$state['finished_at'] = time();
		self::persist( $order, $state );
	}

	/**
	 * Persist provider identity immediately after acceptance, before PDF fetch,
	 * read-back or any other operation that may fail locally.
	 *
	 * @param \WC_Order          $order    Order.
	 * @param array<string,mixed> $identity Provider identifiers only.
	 * @return void
	 */
	public static function remote_accepted( \WC_Order $order, array $identity ) {
		$state                = self::state( $order );
		$state['status']       = self::ACCEPTED;
		$state['identity']     = self::normalize_identity( $identity );
		$state['accepted_at']  = time();
		$state['finished_at']  = 0;
		self::persist( $order, $state );
	}

	/**
	 * Finalize an unsuccessful courier result without losing an already recorded
	 * ambiguous/accepted remote state.
	 *
	 * @param \WC_Order    $order  Order.
	 * @param Label_Result $result Result.
	 * @return Label_Result
	 */
	public static function finalize_failure( \WC_Order $order, Label_Result $result ) {
		$state  = self::state( $order );
		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		if ( self::REMOTE_PENDING === $status ) {
			$state['status']     = self::AMBIGUOUS;
			$state['error_type'] = Courier_Error::UNKNOWN;
			$state['error_code'] = 'unclassified_remote_result';
		} elseif ( self::PREPARING === $status ) {
			$state['status']     = self::FAILED;
			$state['error_type'] = Courier_Error::VALIDATION;
			$state['error_code'] = 'local_preflight_failure';
		}
		$state['finished_at'] = time();
		self::persist( $order, $state );

		if ( in_array( $state['status'], array( self::AMBIGUOUS, self::ACCEPTED ), true ) ) {
			$result->errors[] = __( 'The courier create result is ambiguous or already accepted. BGCS blocked automatic retry to prevent a duplicate shipment. Check the courier portal before taking another action.', 'bg-commerce-suite' );
		}
		return $result;
	}

	/** Preserve crash/exception ambiguity without persisting exception prose. */
	public static function finalize_exception( \WC_Order $order ) {
		self::finalize_failure( $order, Label_Result::error( 'internal_exception' ) );
	}

	/** Mark the local label snapshot as durably complete. */
	public static function complete( \WC_Order $order, Label_Result $result ) {
		$state                        = self::state( $order );
		$state['status']              = self::CREATED;
		$state['payload_fingerprint'] = '' !== (string) $result->payload_fingerprint
			? (string) $result->payload_fingerprint
			: self::preflight_fingerprint( $order );
		$state['identity']            = self::normalize_identity(
			array(
				'shipment_number' => $result->shipment_number,
				'parcel_ids'      => $result->parcel_ids,
				'tracking_numbers' => $result->tracking_numbers,
				'label_reference' => $result->label_reference,
			)
		);
		$state['finished_at']         = time();
		self::persist( $order, $state );
	}

	/** @return array<string,mixed> */
	public static function state( \WC_Order $order ) {
		$state = $order->get_meta( self::META_KEY );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Structured read-back matcher used by courier modules. It compares only
	 * named response fields and never serializes/searches an arbitrary body.
	 *
	 * @param mixed    $data     Provider response tree.
	 * @param string[] $keys     Allowed field names.
	 * @param string   $expected Expected identifier/reference.
	 * @return bool
	 */
	public static function response_confirms( $data, array $keys, $expected ) {
		if ( ! is_array( $data ) || '' === (string) $expected ) {
			return false;
		}
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, $keys, true ) && is_scalar( $value ) && (string) $value === (string) $expected ) {
				return true;
			}
			if ( is_array( $value ) && self::response_confirms( $value, $keys, $expected ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return bool */
	private static function is_ambiguous_error( $error ) {
		if ( $error instanceof Courier_Error ) {
			return ! in_array(
				$error->type(),
				array( Courier_Error::VALIDATION, Courier_Error::AUTHENTICATION, Courier_Error::CONFIGURATION, Courier_Error::NOT_FOUND ),
				true
			);
		}
		if ( is_wp_error( $error ) ) {
			return ! in_array( $error->get_error_code(), array( 'bgcs3_speedy_error', 'bgcs3_validation_error', 'bgcs3_authentication_error', 'bgcs3_configuration_error', 'bgcs3_not_found_error' ), true );
		}
		return true;
	}

	/** @return string */
	private static function error_type( $error, $ambiguous ) {
		if ( $error instanceof Courier_Error ) {
			return $error->type();
		}
		return $ambiguous ? Courier_Error::UNKNOWN : Courier_Error::VALIDATION;
	}

	/** @return string */
	private static function preflight_fingerprint( \WC_Order $order ) {
		$preflight = $order->get_meta( '_bgcs3_preflight' );
		return is_array( $preflight ) && ! empty( $preflight['payload']['fingerprint'] )
			? sanitize_text_field( (string) $preflight['payload']['fingerprint'] )
			: '';
	}

	/** @return array<string,mixed> */
	private static function normalize_identity( array $identity ) {
		$out = array(
			'shipment_number' => isset( $identity['shipment_number'] ) ? sanitize_text_field( (string) $identity['shipment_number'] ) : '',
			'parcel_ids'      => array(),
			'tracking_numbers' => array(),
			'label_reference' => isset( $identity['label_reference'] ) ? sanitize_text_field( (string) $identity['label_reference'] ) : '',
		);
		foreach ( array( 'parcel_ids', 'tracking_numbers' ) as $key ) {
			foreach ( isset( $identity[ $key ] ) ? (array) $identity[ $key ] : array() as $value ) {
				$value = sanitize_text_field( (string) $value );
				if ( '' !== $value ) {
					$out[ $key ][] = $value;
				}
			}
			$out[ $key ] = array_values( array_unique( $out[ $key ] ) );
		}
		return $out;
	}

	private static function persist( \WC_Order $order, array $state ) {
		$order->update_meta_data( self::META_KEY, $state );
		$order->save();
	}
}
