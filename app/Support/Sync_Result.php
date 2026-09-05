<?php
/**
 * Normalized result returned by courier synchronization actions.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

final class Sync_Result {

	/** @var string */
	private $level;

	/** @var string */
	private $message;

	/** @var array<string,int> */
	private $counts;

	/** @var string[] */
	private $updated;

	/** @var string[] */
	private $preserved;

	/** @var string[] */
	private $errors;

	private function __construct( $level, $message, array $counts, array $updated, array $preserved, array $errors ) {
		$allowed         = array( 'success', 'warning', 'error' );
		$this->level     = in_array( $level, $allowed, true ) ? $level : 'error';
		$this->message   = sanitize_text_field( (string) $message );
		$this->counts    = array_map( 'absint', $counts );
		$this->updated   = array_values( array_filter( array_map( 'sanitize_text_field', $updated ) ) );
		$this->preserved = array_values( array_filter( array_map( 'sanitize_text_field', $preserved ) ) );
		$this->errors    = array_values( array_filter( array_map( 'sanitize_text_field', $errors ) ) );
	}

	public static function success( $message, array $counts = array(), array $updated = array(), array $preserved = array() ) {
		return new self( 'success', $message, $counts, $updated, $preserved, array() );
	}

	public static function warning( $message, array $counts = array(), array $updated = array(), array $preserved = array(), array $errors = array() ) {
		return new self( 'warning', $message, $counts, $updated, $preserved, $errors );
	}

	public static function error( $message, array $errors = array(), array $preserved = array() ) {
		return new self( 'error', $message, array(), array(), $preserved, $errors );
	}

	public static function from_mixed( $result ) {
		if ( $result instanceof self ) {
			return $result;
		}

		if ( is_wp_error( $result ) ) {
			return self::error( $result->get_error_message(), array( $result->get_error_code() ) );
		}

		if ( is_array( $result ) ) {
			$success = ! empty( $result['success'] );
			$level   = isset( $result['level'] ) ? $result['level'] : ( $success ? 'success' : 'error' );

			return new self(
				$level,
				isset( $result['message'] ) ? $result['message'] : '',
				isset( $result['counts'] ) && is_array( $result['counts'] ) ? $result['counts'] : array(),
				isset( $result['updated'] ) && is_array( $result['updated'] ) ? $result['updated'] : array(),
				isset( $result['preserved'] ) && is_array( $result['preserved'] ) ? $result['preserved'] : array(),
				isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array()
			);
		}

		return self::error( __( 'The courier returned an invalid synchronization result.', 'bg-commerce-suite' ) );
	}

	public function is_success() {
		return 'error' !== $this->level;
	}

	public function to_array() {
		return array(
			'success'   => $this->is_success(),
			'level'     => $this->level,
			'message'   => $this->message,
			'counts'    => $this->counts,
			'updated'   => $this->updated,
			'preserved' => $this->preserved,
			'errors'    => $this->errors,
		);
	}
}
