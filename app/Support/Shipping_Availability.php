<?php
/**
 * Structured, non-selectable shipping availability information.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

final class Shipping_Availability {

	const AVAILABLE   = 'available';
	const PENDING     = 'pending';
	const UNAVAILABLE = 'unavailable';
	const TEMPORARY_ERROR = 'temporary_error';
	// Backward-compatible constant name for early 3.0.47 integrations.
	const ERROR = self::TEMPORARY_ERROR;

	/** @var string */
	public $status = self::ERROR;

	/** @var string */
	public $code = 'shipping_unavailable';

	/** @var string Safe customer-facing explanation. */
	public $customer_message = '';

	/** @var string Diagnostic explanation; never rendered or returned publicly. */
	public $technical_message = '';

	/** @var array<int,array<string,mixed>> */
	public $affected_products = array();

	/** @var array<string,mixed> */
	public $limits = array();

	/** @var array<string,mixed> */
	public $observed_values = array();

	/** @var int */
	public $package_index = 0;

	/**
	 * @param string              $status            Availability status.
	 * @param string              $code              Stable machine-readable reason.
	 * @param string              $customer_message  Safe customer-facing message.
	 * @param string              $technical_message Private diagnostic message.
	 * @param array<string,mixed> $context           Structured context.
	 */
	public function __construct( $status, $code, $customer_message, $technical_message = '', array $context = array() ) {
		$allowed = array( self::AVAILABLE, self::PENDING, self::UNAVAILABLE, self::TEMPORARY_ERROR );
		$status  = sanitize_key( (string) $status );

		$this->status            = in_array( $status, $allowed, true ) ? $status : self::ERROR;
		$this->code              = sanitize_key( (string) $code );
		$this->customer_message  = trim( (string) $customer_message );
		$this->technical_message = trim( (string) $technical_message );
		$this->package_index     = isset( $context['package_index'] ) ? max( 0, (int) $context['package_index'] ) : 0;
		$this->affected_products = self::normalize_products( isset( $context['affected_products'] ) ? $context['affected_products'] : array() );
		$this->limits            = isset( $context['limits'] ) && is_array( $context['limits'] ) ? $context['limits'] : array();
		$this->observed_values   = isset( $context['observed_values'] ) && is_array( $context['observed_values'] ) ? $context['observed_values'] : array();

		if ( '' === $this->code ) {
			$this->code = 'shipping_unavailable';
		}
	}

	/** @return self */
	public static function available( $code, $customer_message = '', $technical_message = '', array $context = array() ) {
		return new self( self::AVAILABLE, $code, $customer_message, $technical_message, $context );
	}

	/** @return self */
	public static function pending( $code, $customer_message, $technical_message = '', array $context = array() ) {
		return new self( self::PENDING, $code, $customer_message, $technical_message, $context );
	}

	/** @return self */
	public static function unavailable( $code, $customer_message, $technical_message = '', array $context = array() ) {
		return new self( self::UNAVAILABLE, $code, $customer_message, $technical_message, $context );
	}

	/** @return self */
	public static function error( $code, $customer_message, $technical_message = '', array $context = array() ) {
		return new self( self::TEMPORARY_ERROR, $code, $customer_message, $technical_message, $context );
	}

	/**
	 * Full internal representation, including diagnostics for controlled logging.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array_merge(
			$this->to_public_array(),
			array(
				'technical_message' => $this->technical_message,
				'affected_products' => $this->affected_products,
			)
		);
	}

	/**
	 * Public representation safe for HTML, REST and third-party checkout renderers.
	 *
	 * @return array<string,mixed>
	 */
	public function to_public_array() {
		return array(
			'status'            => $this->status,
			'code'              => $this->code,
			'customer_message'  => $this->customer_message,
			'package_index'     => $this->package_index,
			'affected_products' => self::public_products( $this->affected_products ),
			'limits'            => $this->limits,
			'observed_values'   => $this->observed_values,
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function normalize_products( $products ) {
		if ( ! is_array( $products ) ) {
			return array();
		}

		$out = array();
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$row = array(
				'id'        => isset( $product['id'] ) ? (int) $product['id'] : 0,
				'parent_id' => isset( $product['parent_id'] ) ? (int) $product['parent_id'] : 0,
				'name'      => isset( $product['name'] ) ? trim( (string) $product['name'] ) : '',
				'quantity'  => isset( $product['quantity'] ) ? max( 1, (int) $product['quantity'] ) : 1,
			);
			foreach ( array( 'dimensions_cm', 'weight_kg' ) as $key ) {
				if ( isset( $product[ $key ] ) ) {
					$row[ $key ] = $product[ $key ];
				}
			}
			$out[] = $row;
		}

		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	private static function public_products( array $products ) {
		$out = array();
		foreach ( $products as $product ) {
			unset( $product['id'], $product['parent_id'] );
			$out[] = $product;
		}
		return $out;
	}
}
