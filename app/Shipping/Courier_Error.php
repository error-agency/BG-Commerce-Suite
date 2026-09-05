<?php
/**
 * Canonical courier API error model (Rule 34) with a queryable
 * retryable/non-retryable classification (Rule 51/132).
 *
 * Extends `\WP_Error` so every existing `is_wp_error( $result )` /
 * `$result->get_error_message()` call site throughout Core and every courier
 * keeps working completely unchanged — this is a strict, backward-compatible
 * addition, not a breaking replacement. Callers that want the richer
 * classification can additionally check `$result instanceof Courier_Error`
 * and call `type()`/`is_retryable()`.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

class Courier_Error extends \WP_Error {

	const VALIDATION     = 'validation';
	const AUTHENTICATION = 'authentication';
	const CONFIGURATION  = 'configuration';
	const API            = 'api';
	const RATE_LIMIT     = 'rate_limit';
	const NETWORK        = 'network';
	const NOT_FOUND      = 'not_found';
	const CONFLICT       = 'conflict';
	const UNKNOWN        = 'unknown';

	/** @var string One of the class constants above. */
	private $error_type = self::UNKNOWN;

	/** @var bool */
	private $retryable = false;

	/**
	 * Invalid request/payload — never worth retrying unchanged (Rule 51:
	 * "validation error" is explicitly permanent).
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string,mixed>  $data    Extra context (e.g. status/body), never secrets.
	 * @return self
	 */
	public static function validation( $message, array $data = array() ) {
		return self::make( self::VALIDATION, 'bgcs3_validation_error', $message, $data, false );
	}

	/**
	 * Invalid/expired credentials (Rule 51: "invalid credentials" -> permanent
	 * until the merchant fixes them, never blind-retry).
	 *
	 * @param string               $message Message.
	 * @param array<string,mixed>  $data    Extra context.
	 * @return self
	 */
	public static function authentication( $message, array $data = array() ) {
		return self::make( self::AUTHENTICATION, 'bgcs3_authentication_error', $message, $data, false );
	}

	/**
	 * Missing/invalid module configuration (e.g. no sender office set) —
	 * a local setup problem, retrying the same request changes nothing.
	 *
	 * @param string               $message Message.
	 * @param array<string,mixed>  $data    Extra context.
	 * @return self
	 */
	public static function configuration( $message, array $data = array() ) {
		return self::make( self::CONFIGURATION, 'bgcs3_configuration_error', $message, $data, false );
	}

	/**
	 * Generic API-level failure not covered by a more specific type.
	 * Retryable only for a 5xx status (Rule 132) — a 4xx here is treated as
	 * permanent since it wasn't specific enough to classify as validation/
	 * auth/not-found/conflict.
	 *
	 * @param string               $message Message.
	 * @param array<string,mixed>  $data    Extra context — pass 'status' to drive retryability.
	 * @return self
	 */
	public static function api( $message, array $data = array() ) {
		$status    = isset( $data['status'] ) ? (int) $data['status'] : 0;
		$retryable = ( $status >= 500 && $status < 600 );
		return self::make( self::API, 'bgcs3_api_error', $message, $data, $retryable );
	}

	/**
	 * HTTP 429 — always retryable, ideally with backoff (Rule 51/132).
	 *
	 * @param string               $message Message.
	 * @param array<string,mixed>  $data    Extra context.
	 * @return self
	 */
	public static function rate_limit( $message, array $data = array() ) {
		return self::make( self::RATE_LIMIT, 'bgcs3_rate_limit_error', $message, $data, true );
	}

	/**
	 * Timeout / DNS / connection failure — transient by nature (Rule 51/132).
	 *
	 * @param string               $message Message.
	 * @param array<string,mixed>  $data    Extra context.
	 * @return self
	 */
	public static function network( $message, array $data = array() ) {
		return self::make( self::NETWORK, 'bgcs3_network_error', $message, $data, true );
	}

	/**
	 * HTTP 404 / confirmed-not-found (Rule 51: permanent, never blind-retry).
	 *
	 * @param string               $message Message.
	 * @param array<string,mixed>  $data    Extra context.
	 * @return self
	 */
	public static function not_found( $message, array $data = array() ) {
		return self::make( self::NOT_FOUND, 'bgcs3_not_found_error', $message, $data, false );
	}

	/**
	 * HTTP 409 / state conflict (e.g. duplicate reference) — not safely
	 * retryable without the caller resolving the conflict first.
	 *
	 * @param string               $message Message.
	 * @param array<string,mixed>  $data    Extra context.
	 * @return self
	 */
	public static function conflict( $message, array $data = array() ) {
		return self::make( self::CONFLICT, 'bgcs3_conflict_error', $message, $data, false );
	}

	/**
	 * Anything that doesn't fit the above (e.g. a 2xx response with an
	 * unparseable body). Defaults to non-retryable — Rule 26/51's
	 * "never retry blindly on uncertainty" applies to unclassified failures
	 * most of all.
	 *
	 * @param string               $message Message.
	 * @param array<string,mixed>  $data    Extra context.
	 * @return self
	 */
	public static function unknown( $message, array $data = array() ) {
		return self::make( self::UNKNOWN, 'bgcs3_unknown_error', $message, $data, false );
	}

	/**
	 * @param string               $type      One of the class constants.
	 * @param string               $wp_code   WP_Error code.
	 * @param string               $message   Message.
	 * @param array<string,mixed>  $data      Extra context.
	 * @param bool                 $retryable Retry classification.
	 * @return self
	 */
	private static function make( $type, $wp_code, $message, array $data, $retryable ) {
		$error             = new self( $wp_code, $message, $data );
		$error->error_type = $type;
		$error->retryable  = (bool) $retryable;
		return $error;
	}

	/**
	 * @return string One of the class constants (VALIDATION, AUTHENTICATION, ...).
	 */
	public function type() {
		return $this->error_type;
	}

	/**
	 * @return bool Whether this failure class is safe to retry (Rule 51/132).
	 *              Callers must still apply backoff and a retry ceiling — this
	 *              flag only says "retrying isn't fundamentally pointless",
	 *              not "retry forever".
	 */
	public function is_retryable() {
		return $this->retryable;
	}
}
