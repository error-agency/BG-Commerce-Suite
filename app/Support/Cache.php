<?php
/**
 * Tiny transient cache helper with a remember() pattern. Never caches errors.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

class Cache {

	const PREFIX = 'bgcs3_';

	/**
	 * Build a canonical courier cache key without the global cache prefix.
	 *
	 * @param string $courier Courier id.
	 * @param string $suffix  Key suffix.
	 * @return string
	 */
	public static function courier_key( $courier, $suffix ) {
		return sanitize_key( $courier ) . '_' . ltrim( sanitize_key( $suffix ), '_' );
	}

	/**
	 * Return cached value or compute, cache and return it.
	 *
	 * The callback may return a WP_Error to signal failure — in that case the
	 * result is NOT cached and the error is returned to the caller.
	 *
	 * @param string   $key      Cache key (without prefix).
	 * @param int      $ttl      Time-to-live in seconds.
	 * @param callable $callback Producer returning mixed|\WP_Error.
	 * @return mixed|\WP_Error
	 */
	public static function remember( $key, $ttl, callable $callback ) {
		$full   = self::PREFIX . $key;
		$cached = get_transient( $full );

		if ( false !== $cached ) {
			return $cached;
		}

		$value = $callback();

		if ( ! is_wp_error( $value ) ) {
			set_transient( $full, $value, $ttl );
		}

		return $value;
	}

	/**
	 * Read a cached value without invoking a producer. Useful for admin screens:
	 * rendering configuration must never turn into an implicit courier API call.
	 *
	 * @param string $key     Cache key (without prefix).
	 * @param mixed  $default Value returned when the transient is absent.
	 * @return mixed
	 */
	public static function get( $key, $default = false ) {
		$value = get_transient( self::PREFIX . $key );
		return false === $value ? $default : $value;
	}

	/**
	 * @param string $key Cache key (without prefix).
	 */
	public static function forget( $key ) {
		delete_transient( self::PREFIX . $key );
	}

	/**
	 * Delete every cached transient that belongs to a courier (location data,
	 * services, contracts, …). Used by the manual + scheduled sync to force a
	 * fresh fetch from the courier API on the next request.
	 *
	 * @param string $needle Courier id (e.g. 'speedy').
	 * @return int Number of cache entries removed.
	 */
	public static function flush_courier( $needle ) {
		global $wpdb;

		$needle = sanitize_key( $needle );
		if ( '' === $needle ) {
			return 0;
		}

		$canonical = $wpdb->esc_like( self::PREFIX . $needle . '_' ) . '%';
		$legacy    = $wpdb->esc_like( self::PREFIX . self::PREFIX . $needle . '_' ) . '%';

		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . $canonical,
				'_transient_timeout_' . $canonical,
				'_transient_' . $legacy,
				'_transient_timeout_' . $legacy
			)
		);

		$count = 0;
		$keys  = array();
		foreach ( (array) $names as $option_name ) {
			$transient = preg_replace( '/^_transient_(timeout_)?/', '', $option_name );
			$keys[ $transient ] = true;
		}

		foreach ( array_keys( $keys ) as $transient ) {
			if ( delete_transient( $transient ) ) {
				++$count;
			}
		}

		return $count;
	}
}
