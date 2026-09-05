<?php
/**
 * Provider-neutral courier pickup request lifecycle.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Pickup_Request {

	const META_KEY         = '_bgcs3_pickup_request';
	const META_HISTORY_KEY = '_bgcs3_pickup_history';
	const HISTORY_LIMIT    = 20;

	const PENDING    = 'pending';
	const PROCESSING = 'processing';
	const COLLECTED  = 'collected';
	const REJECTED   = 'rejected';
	const CANCELLED  = 'cancelled';
	const UNKNOWN    = 'unknown';

	/** @var Creation_Lock|null */
	private static $lock;

	/** Acquire the courier-wide mutation lock. @return string|false */
	public static function acquire( $courier_id ) {
		return self::lock()->acquire( self::lock_key( $courier_id ) );
	}

	/** Release the courier-wide mutation lock. */
	public static function release( $courier_id, $owner ) {
		self::lock()->release( self::lock_key( $courier_id ), (string) $owner );
	}

	/** Test seam for the atomic lock. */
	public static function set_lock( Creation_Lock $lock = null ) {
		self::$lock = $lock;
	}

	/** @return array<string,mixed> */
	public static function record( $courier_id, $request_id, $status, $date, $time_from, $time_to, array $shipments, $fingerprint = '', $now = null ) {
		$now = null === $now ? time() : (int) $now;
		return array(
			'id'          => trim( (string) $request_id ),
			'number'      => trim( (string) $request_id ), // Legacy Pigeon reader.
			'courier'     => sanitize_key( (string) $courier_id ),
			'status'      => self::status( $status ),
			'status_code' => self::status( $status ),
			'date'        => trim( (string) $date ),
			'time_from'   => trim( (string) $time_from ),
			'time_to'     => trim( (string) $time_to ),
			'shipments'   => self::shipments( $shipments ),
			'fingerprint' => trim( (string) $fingerprint ),
			'created_at'  => $now,
			'updated_at'  => $now,
		);
	}

	/** Normalize current and legacy option records. @return array<string,mixed> */
	public static function normalize( array $record, $courier_id = '' ) {
		$id = ! empty( $record['id'] ) ? (string) $record['id'] : ( ! empty( $record['number'] ) ? (string) $record['number'] : '' );
		$status = isset( $record['status'] ) ? $record['status'] : ( isset( $record['status_code'] ) ? $record['status_code'] : '' );
		$shipments = isset( $record['shipments'] ) && is_array( $record['shipments'] ) ? $record['shipments'] : array();
		if ( empty( $shipments ) ) {
			$legacy = isset( $record['attachments'] ) ? $record['attachments'] : ( isset( $record['references'] ) ? $record['references'] : array() );
			foreach ( (array) $legacy as $reference ) {
				$shipments[] = array( 'waybill' => (string) $reference );
			}
		}
		$record['id']          = trim( $id );
		$record['number']      = trim( $id );
		$record['courier']     = sanitize_key( ! empty( $record['courier'] ) ? $record['courier'] : $courier_id );
		$record['status']      = self::status( $status );
		$record['status_code'] = $record['status'];
		$record['shipments']   = self::shipments( $shipments );
		$record['updated_at']  = isset( $record['updated_at'] ) ? (int) $record['updated_at'] : ( isset( $record['checked_at'] ) ? (int) $record['checked_at'] : ( isset( $record['created_at'] ) ? (int) $record['created_at'] : 0 ) );
		return $record;
	}

	/** @return bool */
	public static function is_active( array $record ) {
		$record = self::normalize( $record );
		return '' !== $record['id'] && in_array( $record['status'], array( self::PENDING, self::PROCESSING, self::UNKNOWN ), true );
	}

	/** PII-free request identity. */
	public static function fingerprint( $courier_id, array $payload, array $shipments ) {
		$payload = self::sort_recursive( $payload );
		$shipments = self::sort_recursive( self::shipments( $shipments ) );
		return hash( 'sha256', wp_json_encode( array( sanitize_key( (string) $courier_id ), $payload, $shipments ) ) );
	}

	/** Persist canonical association on every attached order. */
	public static function attach_orders( array $record, $legacy_meta_key = '' ) {
		$record = self::normalize( $record );
		foreach ( $record['shipments'] as $shipment ) {
			$order = ! empty( $shipment['order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $shipment['order_id'] ) : false;
			if ( ! $order ) {
				continue;
			}
			self::associate_order( $order, $record, $shipment, $legacy_meta_key );
			$order->save();
		}
	}

	/** Associate one already-loaded order without forcing a save. */
	public static function associate_order( $order, array $record, array $shipment, $legacy_meta_key = '' ) {
		$record   = self::normalize( $record );
		$shipment = self::shipments( array( $shipment ) );
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) || empty( $shipment ) ) {
			return;
		}
		$order->update_meta_data( self::META_KEY, self::order_receipt( $record, $shipment[0] ) );
		if ( '' !== (string) $legacy_meta_key ) {
			$order->update_meta_data( (string) $legacy_meta_key, $record['id'] );
		}
	}

	/** Update status and last-update fields on attached orders. */
	public static function update_orders( array $record ) {
		$record = self::normalize( $record );
		foreach ( $record['shipments'] as $shipment ) {
			$order = ! empty( $shipment['order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $shipment['order_id'] ) : false;
			if ( ! $order ) {
				continue;
			}
			$current = $order->get_meta( self::META_KEY );
			if ( ! is_array( $current ) || (string) ( isset( $current['id'] ) ? $current['id'] : '' ) !== $record['id'] ) {
				continue;
			}
			$current['status']     = $record['status'];
			$current['updated_at'] = $record['updated_at'];
			$order->update_meta_data( self::META_KEY, $current );
			$order->save();
		}
	}

	/** Archive and detach a rejected/cancelled request so the shipment can be requested again. */
	public static function detach_orders( array $record, $legacy_meta_key = '' ) {
		$record = self::normalize( $record );
		foreach ( $record['shipments'] as $shipment ) {
			$order = ! empty( $shipment['order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $shipment['order_id'] ) : false;
			if ( ! $order ) {
				continue;
			}
			$current = $order->get_meta( self::META_KEY );
			if ( is_array( $current ) && (string) ( isset( $current['id'] ) ? $current['id'] : '' ) === $record['id'] ) {
				$history   = $order->get_meta( self::META_HISTORY_KEY );
				$history   = is_array( $history ) ? $history : array();
				$history[] = $current;
				$order->update_meta_data( self::META_HISTORY_KEY, array_slice( $history, -self::HISTORY_LIMIT ) );
				$order->delete_meta_data( self::META_KEY );
			}
			if ( '' !== (string) $legacy_meta_key && (string) $order->get_meta( (string) $legacy_meta_key ) === $record['id'] ) {
				$order->delete_meta_data( (string) $legacy_meta_key );
			}
			$order->save();
		}
	}

	/** @return array<int,array<string,mixed>> */
	public static function shipments( array $shipments ) {
		$out = array();
		foreach ( $shipments as $shipment ) {
			if ( is_scalar( $shipment ) ) {
				$shipment = array( 'waybill' => (string) $shipment );
			}
			if ( ! is_array( $shipment ) ) {
				continue;
			}
			$row = array(
				'order_id'           => isset( $shipment['order_id'] ) ? absint( $shipment['order_id'] ) : 0,
				'waybill'            => trim( (string) ( isset( $shipment['waybill'] ) ? $shipment['waybill'] : '' ) ),
				'shipment_reference' => trim( (string) ( isset( $shipment['shipment_reference'] ) ? $shipment['shipment_reference'] : '' ) ),
			);
			$key = $row['waybill'] . '|' . $row['shipment_reference'];
			if ( '|' === $key || isset( $out[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = $row;
		}
		return array_values( $out );
	}

	public static function status( $status ) {
		$status = sanitize_key( strtolower( trim( (string) $status ) ) );
		$map = array(
			'unprocess' => self::PENDING,
			'created' => self::PENDING,
			'new' => self::PENDING,
			'process' => self::PROCESSING,
			'in_progress' => self::PROCESSING,
			'taken' => self::COLLECTED,
			'completed' => self::COLLECTED,
			'reject' => self::REJECTED,
			'reject_client' => self::REJECTED,
			'canceled' => self::CANCELLED,
		);
		$status = isset( $map[ $status ] ) ? $map[ $status ] : $status;
		return in_array( $status, array( self::PENDING, self::PROCESSING, self::COLLECTED, self::REJECTED, self::CANCELLED ), true ) ? $status : self::UNKNOWN;
	}

	private static function order_receipt( array $record, array $shipment ) {
		return array(
			'id'                 => $record['id'],
			'courier'            => $record['courier'],
			'status'             => $record['status'],
			'date'               => isset( $record['date'] ) ? $record['date'] : '',
			'waybill'            => isset( $shipment['waybill'] ) ? $shipment['waybill'] : '',
			'shipment_reference' => isset( $shipment['shipment_reference'] ) ? $shipment['shipment_reference'] : '',
			'fingerprint'        => isset( $record['fingerprint'] ) ? $record['fingerprint'] : '',
			'updated_at'         => isset( $record['updated_at'] ) ? (int) $record['updated_at'] : 0,
		);
	}

	private static function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_recursive( $item );
		}
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			usort(
				$value,
				static function ( $left, $right ) {
					return strcmp( wp_json_encode( $left ), wp_json_encode( $right ) );
				}
			);
		} else {
			ksort( $value );
		}
		return $value;
	}

	private static function lock() {
		if ( null === self::$lock ) {
			self::$lock = new Creation_Lock();
		}
		return self::$lock;
	}

	private static function lock_key( $courier_id ) {
		return 'bgcs3_pickup_lock_' . sanitize_key( (string) $courier_id );
	}
}
