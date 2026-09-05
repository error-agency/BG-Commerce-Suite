<?php
/**
 * Base HTTP client for courier APIs. Wraps wp_remote_* with JSON handling and
 * pluggable auth. Concrete clients set the base URL and auth headers.
 *
 * Never bypasses WordPress (no raw mysqli / direct sockets).
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping;

use BgCommerce3\Shipping\Courier_Error;
use BgCommerce3\Support\Log_Redactor;

defined( 'ABSPATH' ) || exit;

abstract class Abstract_Client {

	/** @var int Number of provider HTTP requests issued in this PHP request. */
	private static $request_count = 0;

	/** @var int Request timeout in seconds. */
	protected $timeout = 20;

	/**
	 * Base API URL (with trailing slash), e.g. https://ee.econt.com/services/.
	 *
	 * @return string
	 */
	abstract protected function base_url();

	/**
	 * Auth headers for every request (Basic / Bearer / API key …).
	 *
	 * @return array<string,string>
	 */
	protected function auth_headers() {
		return array();
	}

	/**
	 * Perform a JSON request.
	 *
	 * @param string              $method   HTTP method.
	 * @param string              $endpoint Endpoint appended to the base URL.
	 * @param array<string,mixed> $body     Request body (encoded as JSON for non-GET).
	 * @param array<string,mixed> $query    Query args (for GET).
	 * @return array<string,mixed>|\WP_Error Decoded response or error.
	 */
	protected function request( $method, $endpoint, array $body = array(), array $query = array() ) {
		$url = $this->base_url() . ltrim( $endpoint, '/' );

		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $this->timeout,
			'headers' => array_merge(
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				$this->auth_headers()
			),
		);

		if ( 'GET' !== $args['method'] ) {
			// Always send a JSON body on POST — some APIs (Econt) reject requests
			// without one ("Unable to parse request"); an empty payload is '{}'.
			$args['body'] = wp_json_encode( empty( $body ) ? new \stdClass() : $body );
		}

		return $this->request_json_url( $args['method'], $url, $args );
	}

	/**
	 * JSON transport към абсолютен URL.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $url    Absolute URL.
	 * @param array<string,mixed> $args   WordPress HTTP arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function request_json_url( $method, $url, array $args = array() ) {
		return $this->perform_request( $method, $url, $args, 'json' );
	}

	/**
	 * Binary transport към абсолютен URL.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $url    Absolute URL.
	 * @param array<string,mixed> $args   WordPress HTTP arguments.
	 * @return string|\WP_Error
	 */
	protected function request_binary_url( $method, $url, array $args = array() ) {
		return $this->perform_request( $method, $url, $args, 'binary' );
	}

	/**
	 * Единен WordPress HTTP transport, status validation и response parsing.
	 *
	 * @param string              $method        HTTP method.
	 * @param string              $url           Absolute URL.
	 * @param array<string,mixed> $args          WordPress HTTP arguments.
	 * @param string              $response_type json|binary.
	 * @return array<string,mixed>|string|\WP_Error
	 */
	public static function request_count() {
		return (int) self::$request_count;
	}

	private function perform_request( $method, $url, array $args, $response_type ) {
		// A passive render of the BGCS settings screen must never turn into an
		// implicit courier request. Account checks and nomenclature refreshes use
		// explicit admin-post/REST actions, where this screen guard is false.
		if ( function_exists( 'is_admin' ) && is_admin()
			&& class_exists( '\\BgCommerce3\\Admin\\Admin_Screen' )
			&& \BgCommerce3\Admin\Admin_Screen::is_bgcs3_settings()
			&& ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) ) {
			return new \WP_Error(
				'bgcs3_passive_admin_no_remote',
				__( 'The configuration screen does not make automatic courier requests. Explicitly use “Check connection” or “Sync”.', 'bg-commerce-suite' )
			);
		}

		$args['method']  = strtoupper( (string) $method );
		$args['timeout'] = isset( $args['timeout'] ) ? (int) $args['timeout'] : $this->timeout;

		self::$request_count++;
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			// A wp_remote_request()-level failure (timeout, DNS, connection
			// refused, ...) is always transient by nature (Rule 51/132).
			return Courier_Error::network( $response->get_error_message(), array( 'wp_error_code' => $response->get_error_code() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// URL-ът може да носи credentials в query параметри (някои куриерски
				// клиенти автентикират така) — маскирай стойностите преди логване
				// (Rule 37/86, BUG-016).
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[BGCS] %s %s -> HTTP %d | response=%s',
					$args['method'],
					self::redact_url( $url ),
					$code,
					Log_Redactor::response_excerpt( $raw, 500 )
				) );
			}

			$detail = $this->extract_error_message( $data, $raw );

			// BGCS-AUDIT-018 — a 4xx is the courier telling the integrator what is
			// wrong with the request, and that text is the most useful thing the
			// merchant can be shown. A 5xx is the provider's own infrastructure
			// failing, and its text is written for their engineers: a Bulgarian
			// shop owner was being shown „MISCONF Redis is configured to save RDB
			// snapshots…“ and has no way to act on it, except to start looking for
			// a fault on their own side.
			//
			// Classified by status, never by keywords or length — the audit is
			// explicit about that, and a guess would eventually hide a real 4xx.
			if ( $code >= 500 ) {
				$message = sprintf(
					/* translators: %d HTTP status code */
					__( 'The courier\'s service is temporarily unavailable (HTTP %d). Try again in a few minutes — nothing was sent or changed.', 'bg-commerce-suite' ),
					$code
				);
			} else {
				$message = sprintf(
					/* translators: %d HTTP status code */
					__( 'Courier API error (HTTP %d).', 'bg-commerce-suite' ),
					$code
				);
				if ( '' !== $detail ) {
					$message .= ' ' . $detail;
				}
			}

			// The detail is never discarded, only kept out of the merchant's way:
			// diagnostics and the redacted debug log are where it belongs.
			$error_data = array(
				'status' => $code,
				'body'   => $data,
				'detail' => $detail,
				'raw'    => Log_Redactor::response_excerpt( $raw, 500 ),
			);

			// Rule 34/132 — classify by HTTP status so callers can distinguish
			// permanent problems (fix credentials/input, don't retry) from
			// transient ones (retry with backoff), instead of every failure
			// looking like the same undifferentiated 'bgcs3_http_error'.
			if ( 401 === $code || 403 === $code ) {
				return Courier_Error::authentication( $message, $error_data );
			}
			if ( 400 === $code || 422 === $code ) {
				return Courier_Error::validation( $message, $error_data );
			}
			if ( 404 === $code ) {
				return Courier_Error::not_found( $message, $error_data );
			}
			if ( 409 === $code ) {
				return Courier_Error::conflict( $message, $error_data );
			}
			if ( 429 === $code ) {
				return Courier_Error::rate_limit( $message, $error_data );
			}
			// Any other 4xx/5xx: generic Api_Error. Courier_Error::api()
			// itself decides retryability from the status (5xx = retryable).
			return Courier_Error::api( $message, $error_data );
		}

		if ( 'binary' === $response_type ) {
			return $raw;
		}

		if ( '' === trim( $raw ) ) {
			return array();
		}

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			// A 2xx response with an unparseable body doesn't fit any specific
			// category and isn't confidently retryable — Rule 26/51's "never
			// retry blindly on uncertainty" applies most to unclassified cases.
			return Courier_Error::unknown(
				__( 'The courier API returned an invalid JSON response.', 'bg-commerce-suite' ),
				array( 'status' => $code )
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Маскира query-string стойностите в URL, преди да влезе в debug лог.
	 *
	 * Някои куриерски клиенти автентикират през query параметри, слети в URL
	 * от {@see request()}; суровият URL никога не трябва да стига до
	 * PHP error log-а необработен (Rule 37/86). Имената на параметрите се пазят
	 * за четимост, стойностите се заменят с `[redacted]`.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	/**
	 * The one way a courier client may put provider content into the debug log
	 * (BGCS-AUDIT-009, Rule 32).
	 *
	 * `Abstract_Client` already redacts the bodies of non-2xx responses, but
	 * couriers that report logical errors inside a 200 — Speedy does — never
	 * reach that branch, and the Speedy client was logging the raw error object.
	 * Speedy validation errors routinely quote the value they rejected, so a
	 * recipient's phone, e-mail, name or address went verbatim into
	 * `debug.log`. Redaction cannot be something each client remembers to do:
	 * this helper always redacts, so a new client cannot bypass it by accident.
	 *
	 * Still gated on `WP_DEBUG`, unchanged — the log itself is a real
	 * diagnostic tool, only its contents were the problem.
	 *
	 * @param string $courier  Courier id/label for the log prefix, e.g. 'Speedy'.
	 * @param string $endpoint Endpoint or operation the error belongs to.
	 * @param mixed  $payload  Provider content: array, object or string.
	 * @return void
	 */
	protected function log_provider_error( $courier, $endpoint, $payload ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$raw = is_string( $payload ) ? $payload : wp_json_encode( $payload );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[BGCS][%s] %s error: %s',
			(string) $courier,
			(string) $endpoint,
			Log_Redactor::response_excerpt( (string) $raw, 500 )
		) );
	}

	/**
	 * Same discipline as {@see log_provider_error()} for non-error diagnostics
	 * (row counts, response key names, sample rows). Content that looks
	 * harmless today still passes through the redactor, so no courier client
	 * needs a direct `error_log()` call — which is what lets the static guard
	 * in `tests/test-log-redaction.php` be an absolute rule rather than a list
	 * of exceptions.
	 *
	 * @param string $courier Courier id/label for the log prefix.
	 * @param string $context Short description of what is being reported.
	 * @param mixed  $detail  Detail: array, object or string.
	 * @return void
	 */
	protected function log_provider_debug( $courier, $context, $detail = '' ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$raw = is_string( $detail ) ? $detail : wp_json_encode( $detail );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[BGCS][%s] %s%s',
			(string) $courier,
			(string) $context,
			( '' === (string) $raw ) ? '' : ' ' . Log_Redactor::response_excerpt( (string) $raw, 500 )
		) );
	}

	private static function redact_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['query'] ) ) {
			return (string) $url;
		}

		parse_str( $parts['query'], $params );
		$redacted = array();
		foreach ( array_keys( $params ) as $key ) {
			$redacted[] = $key . '=[redacted]';
		}

		$base = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' )
			. ( isset( $parts['host'] ) ? $parts['host'] : '' )
			. ( isset( $parts['path'] ) ? $parts['path'] : '' );

		return $base . '?' . implode( '&', $redacted );
	}

	/**
	 * Pull a human-readable message out of a courier error response. Handles the
	 * common shapes: {message}, {error}, {detail/title} (RFC7807), {errors:[{message}]},
	 * {message:{field:[...]}} (validation maps). Falls back to the raw body.
	 *
	 * @param mixed  $data Decoded JSON body (or null).
	 * @param string $raw  Raw response body.
	 * @return string
	 */
	protected function extract_error_message( $data, $raw = '' ) {
		$parts = array();

		if ( is_array( $data ) ) {
			foreach ( array( 'message', 'error', 'detail', 'title', 'error_description' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
					$parts[] = $data[ $key ];
				}
			}

			if ( ! empty( $data['error'] ) && is_array( $data['error'] ) && ! empty( $data['error']['message'] ) ) {
				$parts[] = (string) $data['error']['message'];
			}

			// errors: [ { message }, ... ] or [ "string", ... ]
			if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				foreach ( $data['errors'] as $err ) {
					if ( is_string( $err ) ) {
						$parts[] = $err;
					} elseif ( is_array( $err ) ) {
						if ( ! empty( $err['message'] ) ) {
							$parts[] = is_string( $err['message'] ) ? $err['message'] : wp_json_encode( $err['message'] );
						} elseif ( ! empty( $err['detail'] ) ) {
							$parts[] = (string) $err['detail'];
						}
					}
				}
			}

			// message: { field: [ "reason" ] } validation maps.
			if ( empty( $parts ) && ! empty( $data['message'] ) && is_array( $data['message'] ) ) {
				$parts[] = wp_json_encode( $data['message'] );
			}
		}

		if ( empty( $parts ) && is_string( $raw ) && '' !== trim( $raw ) ) {
			$parts[] = bgcs3_substr( wp_strip_all_tags( $raw ), 0, 300 );
		}

		return trim( implode( ' | ', array_unique( $parts ) ) );
	}
}
