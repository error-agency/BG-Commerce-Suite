<?php
/**
 * Session-backed non-selectable shipping availability state.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Support\Shipping_Availability;

defined( 'ABSPATH' ) || exit;

final class Availability_Store {

	const SESSION_KEY = 'bgcs3_shipping_availability';

	/** @var object|null WC_Session-like object. */
	private $session;

	/** @param object|null $session Optional session for tests. */
	public function __construct( $session = null ) {
		if ( null === $session && function_exists( 'WC' ) && WC()->session ) {
			$session = WC()->session;
		}
		$this->session = $session;
	}

	/**
	 * @param string                $courier_id   Courier slug.
	 * @param string                $courier_name Public courier name.
	 * @param array<string,mixed>   $package      Current WC package.
	 * @param Shipping_Availability $availability State to keep.
	 */
	public function record( $courier_id, $courier_name, array $package, Shipping_Availability $availability ) {
		if ( ! $this->session || ! method_exists( $this->session, 'set' ) ) {
			return;
		}
		$courier_id = sanitize_key( (string) $courier_id );
		$signature  = self::package_signature( $package );
		$entries    = $this->entries();
		$key        = $courier_id . ':' . $signature;
		$previous   = isset( $entries[ $key ] ) && is_array( $entries[ $key ] ) ? $entries[ $key ] : array();
		$entries[ $key ] = array(
			'courier'      => $courier_id,
			'courier_name' => trim( wp_strip_all_tags( (string) $courier_name ) ),
			'package'      => $signature,
			'availability' => $availability->to_array(),
		);
		$this->session->set( self::SESSION_KEY, $entries );
		if ( empty( $previous['availability'] ) || $previous['availability'] !== $availability->to_array() ) {
			$this->log( $courier_id, $signature, $availability );
		}
	}

	/** Clear one courier/package state after a successful or pending calculation. */
	public function clear( $courier_id, array $package ) {
		if ( ! $this->session || ! method_exists( $this->session, 'set' ) ) {
			return;
		}
		$entries = $this->entries();
		unset( $entries[ sanitize_key( (string) $courier_id ) . ':' . self::package_signature( $package ) ] );
		$this->session->set( self::SESSION_KEY, $entries );
	}

	/**
	 * Public rows for current packages. Stale session records are intentionally
	 * excluded and diagnostics are stripped by reconstruction of the public map.
	 *
	 * @param array<int,array<string,mixed>>|null $packages Optional current packages.
	 * @return array<int,array<string,mixed>>
	 */
	public function current_public( $packages = null ) {
		if ( null === $packages ) {
			$packages = $this->current_packages();
		}
		if ( ! is_array( $packages ) ) {
			return array();
		}

		$index_by_signature = array();
		foreach ( array_values( $packages ) as $index => $package ) {
			if ( is_array( $package ) ) {
				$index_by_signature[ self::package_signature( $package ) ] = (int) $index;
			}
		}

		$rows = array();
		foreach ( $this->entries() as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['package'] ) || ! isset( $index_by_signature[ $entry['package'] ] ) || empty( $entry['availability'] ) || ! is_array( $entry['availability'] ) ) {
				continue;
			}
			$availability = $entry['availability'];
			unset( $availability['technical_message'] );
			if ( ! empty( $availability['affected_products'] ) && is_array( $availability['affected_products'] ) ) {
				foreach ( $availability['affected_products'] as &$product ) {
					if ( is_array( $product ) ) {
						unset( $product['id'], $product['parent_id'] );
					}
				}
				unset( $product );
			}
			$availability['package_index'] = $index_by_signature[ $entry['package'] ];
			$availability['courier']       = isset( $entry['courier'] ) ? sanitize_key( (string) $entry['courier'] ) : '';
			$availability['courier_name']  = isset( $entry['courier_name'] ) ? trim( (string) $entry['courier_name'] ) : '';
			$rows[] = $availability;
		}

		return array_values( $rows );
	}

	/** @return array<string,array<string,mixed>> */
	private function entries() {
		if ( ! $this->session || ! method_exists( $this->session, 'get' ) ) {
			return array();
		}
		$value = $this->session->get( self::SESSION_KEY, array() );
		return is_array( $value ) ? $value : array();
	}

	/** @return array<int,array<string,mixed>> */
	private function current_packages() {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}
		if ( WC()->cart && method_exists( WC()->cart, 'get_shipping_packages' ) ) {
			return (array) WC()->cart->get_shipping_packages();
		}
		if ( WC()->shipping() && method_exists( WC()->shipping(), 'get_packages' ) ) {
			return (array) WC()->shipping()->get_packages();
		}
		return array();
	}

	/** @return string Opaque hash; product/destination data never leaves it. */
	public static function package_signature( array $package ) {
		$items = array();
		foreach ( isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$variation  = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			if ( ! $product_id && isset( $item['data'] ) && is_object( $item['data'] ) && method_exists( $item['data'], 'get_id' ) ) {
				$product_id = (int) $item['data']->get_id();
			}
			$items[] = array( $product_id, $variation, isset( $item['quantity'] ) ? (int) $item['quantity'] : 1 );
		}
		sort( $items );
		$destination = array();
		foreach ( array( 'country', 'state', 'postcode', 'city', 'address', 'address_1', 'address_2' ) as $key ) {
			$destination[ $key ] = isset( $package['destination'][ $key ] ) ? (string) $package['destination'][ $key ] : '';
		}
		$json = wp_json_encode( array( 'items' => $items, 'destination' => $destination ) );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	private function log( $courier_id, $signature, Shipping_Availability $availability ) {
		if ( '' === $availability->technical_message || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( ! is_object( $logger ) || ! method_exists( $logger, 'warning' ) ) {
			return;
		}
		$logger->warning(
			$availability->technical_message,
			array(
				'source'       => 'bg-commerce-suite',
				'courier'      => $courier_id,
				'status'       => $availability->status,
				'code'         => $availability->code,
				'package_hash' => substr( $signature, 0, 12 ),
			)
		);
	}
}
