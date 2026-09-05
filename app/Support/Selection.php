<?php
/**
 * Normalised, courier-agnostic representation of what the customer picked at
 * checkout. Single source of truth shared by classic + Blocks checkout, price
 * calculation, validation and order meta.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

class Selection {

	/** @var string Courier/module id, e.g. 'econt'. */
	public $courier = '';

	/** @var string 'office' | 'locker' | 'address'. */
	public $delivery_type = '';

	/** @var string ISO-2 country code. */
	public $country = 'BG';

	/** @var array{id?:string,name?:string,post_code?:string,region?:string} */
	public $city = array();

	/** @var array{id?:string,name?:string,address?:string}|null Office/locker, or null. */
	public $office = null;

	/** @var array<string,string>|null Door address parts, or null. */
	public $address = null;

	/** @var array<string,mixed> Courier-specific extras (sms, city_courier, …). */
	public $extras = array();

	/** @var int Monotonic client/request revision used to reject stale updates. */
	public $revision = 0;

	/**
	 * Build from a (possibly untrusted) array, sanitising scalars.
	 *
	 * @param array<string,mixed> $data Raw data.
	 * @return Selection
	 */
	public static function from_array( array $data ) {
		$self = new self();

		$self->courier       = isset( $data['courier'] ) ? sanitize_key( $data['courier'] ) : '';
		$self->delivery_type = isset( $data['delivery_type'] ) ? sanitize_key( $data['delivery_type'] ) : '';
		$self->country       = isset( $data['country'] ) ? strtoupper( sanitize_text_field( $data['country'] ) ) : 'BG';
		if ( ! preg_match( '/^[A-Z]{2}$/', $self->country ) ) {
			$self->country = 'BG';
		}
		$self->city          = isset( $data['city'] ) && is_array( $data['city'] ) ? self::clean_map( $data['city'] ) : array();
		$self->office        = isset( $data['office'] ) && is_array( $data['office'] ) ? self::clean_map( $data['office'] ) : null;
		$self->address       = isset( $data['address'] ) && is_array( $data['address'] ) ? self::clean_map( $data['address'] ) : null;
		$self->extras        = isset( $data['extras'] ) && is_array( $data['extras'] ) ? self::clean_map( $data['extras'] ) : array();
		$self->revision      = isset( $data['revision'] ) && is_numeric( $data['revision'] ) ? max( 0, (int) $data['revision'] ) : 0;

		// A selection may describe exactly one destination shape. Clearing the
		// incompatible branch prevents stale office/address data from surviving a
		// delivery-type switch and leaking into pricing or order persistence.
		if ( in_array( $self->delivery_type, array( 'office', 'locker' ), true ) ) {
			$self->address = null;
		} elseif ( 'address' === $self->delivery_type ) {
			$self->office = null;
		}

		return $self;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'courier'       => $this->courier,
			'delivery_type' => $this->delivery_type,
			'country'       => $this->country,
			'city'          => $this->city,
			'office'        => $this->office,
			'address'       => $this->address,
			'extras'        => $this->extras,
			'revision'      => $this->revision,
		);
	}

	/**
	 * Whether the customer has made a complete-enough choice to price/ship.
	 *
	 * @return bool
	 */
	public function is_complete() {
		if ( '' === $this->courier || '' === $this->delivery_type ) {
			return false;
		}

		if ( in_array( $this->delivery_type, array( 'office', 'locker' ), true ) ) {
			return ! empty( $this->office['id'] );
		}

		if ( 'address' === $this->delivery_type ) {
			return ! empty( $this->city['id'] )
				&& ! empty( $this->address['street'] );
		}

		return false;
	}

	/**
	 * Sanitise a flat string map.
	 *
	 * @param array<string,mixed> $map Raw map.
	 * @return array<string,string>
	 */
	private static function clean_map( array $map ) {
		$clean = array();
		foreach ( $map as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$clean[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}
}
