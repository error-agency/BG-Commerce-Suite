<?php
/**
 * Debug-log redaction helpers. Provider responses may contain credentials or
 * customer PII and must never be copied verbatim to PHP error logs.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

final class Log_Redactor {

	/**
	 * Return a bounded, redacted provider-response excerpt for debug logs.
	 *
	 * @param string $raw       Raw provider response body.
	 * @param int    $max_chars Maximum output length.
	 * @return string
	 */
	public static function response_excerpt( $raw, $max_chars = 500 ) {
		$raw = (string) $raw;
		$decoded = json_decode( $raw, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			$safe = self::redact_value( $decoded );
			$text = wp_json_encode( $safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} else {
			$text = wp_strip_all_tags( $raw );
		}

		$text = self::redact_scalar( (string) $text );
		return bgcs3_substr( $text, 0, max( 0, (int) $max_chars ) );
	}

	/**
	 * @param mixed       $value Value to redact.
	 * @param string|null $key   Parent key.
	 * @return mixed
	 */
	private static function redact_value( $value, $key = null ) {
		if ( null !== $key && self::is_sensitive_key( $key ) ) {
			return '[redacted]';
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $child_key => $child_value ) {
				$out[ $child_key ] = self::redact_value( $child_value, is_string( $child_key ) ? $child_key : null );
			}
			return $out;
		}

		if ( is_string( $value ) ) {
			return self::redact_scalar( $value );
		}

		return $value;
	}

	/**
	 * @param string $key Field name.
	 * @return bool
	 */
	private static function is_sensitive_key( $key ) {
		$key = strtolower( (string) $key );
		return (bool) preg_match(
			'/(?:^|_)(?:password|pass|secret|token|api_?key|authorization|username|email|phone|address|name|client_?id|recipient|receiver|sender|contact)(?:$|_)/',
			$key
		);
	}

	/**
	 * Redact common secrets/PII that may appear inside free-form error messages.
	 *
	 * @param string $value Text value.
	 * @return string
	 */
	private static function redact_scalar( $value ) {
		$value = (string) $value;
		$value = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $value );
		$value = preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/=\-]{8,}/i', 'Bearer [redacted]', $value );
		$value = preg_replace_callback(
			'/\b(api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret|password|client[_-]?secret)\b\s*[:=]\s*["\']?([^\s,"\';}]+)/i',
			static function ( $match ) {
				return $match[1] . '=[redacted]';
			},
			$value
		);
		$value = preg_replace( '/(?<!\d)(?:\+?\d[\s().\-]*){9,15}(?!\d)/', '[redacted-phone]', $value );
		return (string) $value;
	}
}
