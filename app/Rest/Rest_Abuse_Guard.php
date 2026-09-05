<?php
/**
 * Ограничаване на публичните location заявки без login или REST nonce.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Rest;

defined( 'ABSPATH' ) || exit;

final class Rest_Abuse_Guard {

	const REMOTE_LIMIT       = 40;
	const LOCAL_LIMIT        = 120;
	const WINDOW             = 60;
	const DAILY_LIMIT        = 800;
	const NEGATIVE_CACHE_TTL = 30;
	const QUOTE_LIMIT        = 45;
	const SELECTION_LIMIT    = 90;
	const WRITE_DAILY_LIMIT = 2500;

	/**
	 * Проверява краткосрочния и дневния бюджет за клиента.
	 *
	 * @param \WP_REST_Request $request REST заявка.
	 * @return true|\WP_Error
	 */
	public static function check_request( \WP_REST_Request $request ) {
		$route      = (string) $request->get_route();
		$is_local   = false !== strpos( $route, '/office-search' );
		$bucket     = $is_local ? 'local' : 'remote';
		$base_limit = $is_local ? self::LOCAL_LIMIT : self::REMOTE_LIMIT;
		$limit      = (int) apply_filters( 'bgcs3_rest_location_rate_limit', $base_limit, $bucket, $request );
		$daily      = (int) apply_filters( 'bgcs3_rest_location_daily_limit', self::DAILY_LIMIT, $bucket, $request );
		$ip_hash    = self::hash_identifier( self::ip_address() );
		$session    = self::hash_identifier( self::session_identifier() );

		$allowed = self::register_attempt( 'ip_' . $bucket . '_' . $ip_hash, self::WINDOW, max( 1, $limit ) )
			&& self::register_attempt( 'session_' . $bucket . '_' . $session, self::WINDOW, max( 1, $limit ) )
			&& self::register_attempt( 'day_' . $session, DAY_IN_SECONDS, max( 1, $daily ) );

		if ( ! $allowed ) {
			self::increment_counter( 'blocked' );
			return new \WP_Error(
				'bgcs3_location_rate_limit',
				__( 'You are sending too many location and office requests. Wait a moment and try again.', 'bg-commerce-suite' ),
				array(
					'status'      => 429,
					'retry_after' => self::WINDOW,
				)
			);
		}

		self::increment_counter( 'allowed' );
		return true;
	}

	/**
	 * Rate-limit nonce-protected checkout write endpoints too. A valid REST nonce
	 * protects against CSRF, but it does not prevent a broken/malicious browser
	 * from repeatedly triggering provider quote work.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $bucket  quote|selection.
	 * @return true|\WP_Error
	 */
	public static function check_checkout_write( \WP_REST_Request $request, $bucket ) {
		$bucket = sanitize_key( (string) $bucket );
		if ( ! in_array( $bucket, array( 'quote', 'selection' ), true ) ) {
			return new \WP_Error( 'bgcs3_invalid_rate_bucket', __( 'Invalid request type.', 'bg-commerce-suite' ), array( 'status' => 400 ) );
		}

		$base_limit = 'quote' === $bucket ? self::QUOTE_LIMIT : self::SELECTION_LIMIT;
		$limit = (int) apply_filters( 'bgcs3_rest_checkout_rate_limit', $base_limit, $bucket, $request );
		$daily = (int) apply_filters( 'bgcs3_rest_checkout_daily_limit', self::WRITE_DAILY_LIMIT, $bucket, $request );
		$ip_hash = self::hash_identifier( self::ip_address() );
		$session = self::hash_identifier( self::session_identifier() );

		$allowed = self::register_attempt( 'checkout_ip_' . $bucket . '_' . $ip_hash, self::WINDOW, max( 1, $limit ) )
			&& self::register_attempt( 'checkout_session_' . $bucket . '_' . $session, self::WINDOW, max( 1, $limit ) )
			&& self::register_attempt( 'checkout_day_' . $bucket . '_' . $session, DAY_IN_SECONDS, max( 1, $daily ) );

		if ( ! $allowed ) {
			return new \WP_Error(
				'bgcs3_checkout_rate_limit',
				__( 'You are sending too many shipping requests. Wait a moment and try again.', 'bg-commerce-suite' ),
				array( 'status' => 429, 'retry_after' => self::WINDOW )
			);
		}

		return true;
	}

	/**
	 * Връща кеширана временна грешка за еднаква заявка.
	 *
	 * @param string $bucket Endpoint bucket.
	 * @param array  $params Нормализирани параметри.
	 * @return \WP_Error|null
	 */
	public static function get_negative_cache( $bucket, array $params ) {
		$cached = get_transient( self::negative_key( $bucket, $params ) );
		if ( ! is_array( $cached ) || empty( $cached['code'] ) ) {
			return null;
		}

		self::increment_counter( 'negative_cache_hits' );
		return new \WP_Error(
			(string) $cached['code'],
			isset( $cached['message'] ) ? (string) $cached['message'] : '',
			isset( $cached['data'] ) && is_array( $cached['data'] ) ? $cached['data'] : array()
		);
	}

	/**
	 * Кешира временна грешка, за да не повтаря веднага външната заявка.
	 *
	 * @param string    $bucket Endpoint bucket.
	 * @param array     $params Нормализирани параметри.
	 * @param \WP_Error $error  Грешка от location provider.
	 */
	public static function set_negative_cache( $bucket, array $params, \WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 503;

		if ( $status >= 400 && $status < 500 ) {
			return;
		}

		set_transient(
			self::negative_key( $bucket, $params ),
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => is_array( $data ) ? $data : array( 'status' => $status ),
			),
			self::NEGATIVE_CACHE_TTL
		);
	}

	/**
	 * Анонимна месечна статистика за диагностика.
	 *
	 * @return array<string,int>
	 */
	public static function stats() {
		$suffix = wp_date( 'Ym' );
		return array(
			'allowed'             => (int) get_transient( 'bgcs3_location_guard_allowed_' . $suffix ),
			'blocked'             => (int) get_transient( 'bgcs3_location_guard_blocked_' . $suffix ),
			'negative_cache_hits' => (int) get_transient( 'bgcs3_location_guard_negative_cache_hits_' . $suffix ),
		);
	}

	private static function register_attempt( $suffix, $window, $limit ) {
		$key      = 'bgcs3_location_rate_' . $suffix;
		$attempts = (int) get_transient( $key );
		if ( $attempts >= $limit ) {
			return false;
		}
		set_transient( $key, $attempts + 1, $window );
		return true;
	}

	private static function increment_counter( $name ) {
		$key = 'bgcs3_location_guard_' . $name . '_' . wp_date( 'Ym' );
		set_transient( $key, (int) get_transient( $key ) + 1, 40 * DAY_IN_SECONDS );
	}

	private static function negative_key( $bucket, array $params ) {
		ksort( $params );
		return 'bgcs3_location_negative_' . hash( 'sha256', $bucket . '|' . wp_json_encode( $params ) );
	}

	private static function hash_identifier( $value ) {
		return hash( 'sha256', wp_salt( 'nonce' ) . '|' . (string) $value );
	}

	private static function ip_address() {
		if ( is_callable( array( '\WC_Geolocation', 'get_ip_address' ) ) ) {
			return (string) \WC_Geolocation::get_ip_address();
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	private static function session_identifier() {
		if ( function_exists( 'WC' ) && WC()->session && is_callable( array( WC()->session, 'get_customer_id' ) ) ) {
			return (string) WC()->session->get_customer_id();
		}
		return self::ip_address();
	}
}
