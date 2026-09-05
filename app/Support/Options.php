<?php
/**
 * Grouped option storage. Each group is one wp_option row (`bgcs3_{group}`)
 * holding an associative array, to keep the options table tidy.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

class Options {

	const PREFIX = 'bgcs3_';

	/**
	 * @param string      $group   Group name.
	 * @param string|null $key     Key inside the group, or null for the whole array.
	 * @param mixed       $default Default when missing.
	 * @return mixed
	 */
	public static function get( $group, $key = null, $default = null ) {
		$data = get_option( self::PREFIX . $group, array() );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( null === $key ) {
			return $data;
		}

		return array_key_exists( $key, $data ) ? $data[ $key ] : $default;
	}

	/**
	 * @param string $group Group name.
	 * @param string $key   Key inside the group.
	 * @param mixed  $value Value to store.
	 */
	public static function set( $group, $key, $value ) {
		$data = self::get( $group );
		$data[ $key ] = $value;
		update_option( self::PREFIX . $group, $data, false );
	}

	/**
	 * Replace an entire group at once.
	 *
	 * @param string               $group Group name.
	 * @param array<string,mixed>  $data  Full group payload.
	 */
	public static function set_group( $group, array $data ) {
		update_option( self::PREFIX . $group, $data, false );
	}
}
