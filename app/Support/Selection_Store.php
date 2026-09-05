<?php
/**
 * Reads/writes the current Selection in the WooCommerce session. The session
 * is the server-authoritative copy (localStorage on the front end is only a
 * UX convenience).
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

class Selection_Store {

	const SESSION_KEY = 'bgcs3_selection';

	/**
	 * @return Selection|null
	 */
	public function get() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		} elseif ( null === WC()->session ) {
			WC()->initialize_session();
		}

		if ( ! WC()->session ) {
			return null;
		}

		$raw = WC()->session->get( self::SESSION_KEY );

		if ( empty( $raw ) || ! is_array( $raw ) ) {
			return null;
		}

		return Selection::from_array( $raw );
	}

	/**
	 * @param Selection $selection Selection to persist.
	 * @return bool True when accepted, false for unavailable session or stale state.
	 */
	public function set( Selection $selection ) {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		} elseif ( null === WC()->session ) {
			WC()->initialize_session();
		}

		if ( ! WC()->session ) {
			return false;
		}

		$current = WC()->session->get( self::SESSION_KEY );
		$current_revision = is_array( $current ) && isset( $current['revision'] ) && is_numeric( $current['revision'] )
			? max( 0, (int) $current['revision'] )
			: 0;

		if ( $selection->revision <= 0 ) {
			$selection->revision = self::request_revision();
		}

		if ( $current_revision > $selection->revision ) {
			return false;
		}

		WC()->session->set( self::SESSION_KEY, $selection->to_array() );
		return true;
	}

	/**
	 * Use request start time so a slower old request cannot outrank a later one.
	 *
	 * @return int Epoch microseconds.
	 */
	private static function request_revision() {
		$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && is_numeric( $_SERVER['REQUEST_TIME_FLOAT'] )
			? (float) $_SERVER['REQUEST_TIME_FLOAT']
			: microtime( true );
		return max( 1, (int) floor( $started * 1000000 ) );
	}

	public function clear() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		} elseif ( null === WC()->session ) {
			WC()->initialize_session();
		}

		if ( ! WC()->session ) {
			return;
		}

		WC()->session->set( self::SESSION_KEY, null );
	}
}
