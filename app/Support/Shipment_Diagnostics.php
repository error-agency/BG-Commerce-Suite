<?php
/**
 * Opt-in shipment creation diagnostics.
 *
 * Handoff §15: a real courier "success" is not proof that a requested optional
 * service was applied. To tell WHERE a requested option died, we need the whole
 * chain in one place — what the merchant asked for, what BGCS resolved, what the
 * courier said was allowed for that destination, what validation returned, what
 * we actually sent, what came back, and what the shipment looks like when read
 * back. This records exactly that, and nothing else.
 *
 * Off by default. When off, `begin()` returns an inert collector whose methods
 * cost a function call and do nothing, so call sites stay unconditional.
 *
 * Credentials never reach this class — Speedy's userName/password are added by
 * the client after the body is built — but the redactor strips them anyway, so
 * a future courier that builds credentials into its payload cannot leak them by
 * accident.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

final class Shipment_Diagnostics {

	/** Order meta holding the bounded snapshot ring. */
	const META_KEY = '_bgcs3_diag';

	/** How many create attempts to keep per order. */
	const MAX_ENTRIES = 3;

	/** Hard cap on one serialized entry, so order meta cannot grow unbounded. */
	const MAX_ENTRY_BYTES = 24576;

	/** Free-text values are previewed, never stored whole. */
	const PREVIEW_CHARS = 60;

	/** @var bool */
	private $enabled;

	/** @var string */
	private $courier_id;

	/** @var array<string,mixed> */
	private $stages = array();

	/**
	 * @param bool   $enabled    Whether anything is actually recorded.
	 * @param string $courier_id Courier id.
	 */
	private function __construct( $enabled, $courier_id ) {
		$this->enabled    = (bool) $enabled;
		$this->courier_id = (string) $courier_id;
	}

	/**
	 * Is snapshot recording switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 'yes' === bgcs3_get_option( 'debug', 'shipment_snapshot', 'no' );
	}

	/**
	 * Open a collector for one create attempt.
	 *
	 * @param string $courier_id Courier id.
	 * @return self
	 */
	public static function begin( $courier_id ) {
		return new self( self::enabled(), $courier_id );
	}

	/**
	 * Record one stage of the create chain.
	 *
	 * @param string $stage Stage key, e.g. `effective`, `validation`.
	 * @param mixed  $data  Stage data; redacted before storage.
	 * @return void
	 */
	public function record( $stage, $data ) {
		if ( ! $this->enabled ) {
			return;
		}
		$this->stages[ (string) $stage ] = self::redact( $data );
	}

	/**
	 * Record a provider call outcome, normalising WP_Error into readable data.
	 *
	 * @param string $stage    Stage key.
	 * @param mixed  $response Provider response or WP_Error.
	 * @return void
	 */
	public function record_response( $stage, $response ) {
		if ( ! $this->enabled ) {
			return;
		}

		if ( is_wp_error( $response ) ) {
			$this->stages[ (string) $stage ] = array(
				'error_code'    => $response->get_error_code(),
				// Must go through the same scrubber as everything else: a provider
				// auth failure is the single most likely place for a credential to
				// appear in prose.
				'error_message' => self::scrub_free_text( $response->get_error_message() ),
			);
			return;
		}

		$this->stages[ (string) $stage ] = self::redact( $response );
	}

	/**
	 * Reduce a `/services/destination` response to the allowance matrix of the
	 * one service we are actually using. Storing every service Speedy offers
	 * would bury the answer and blow the size cap.
	 *
	 * @param mixed $response   DestinationServicesResponse or WP_Error.
	 * @param int   $service_id Service the shipment uses.
	 * @return void
	 */
	public function record_destination_services( $response, $service_id ) {
		if ( ! $this->enabled ) {
			return;
		}

		if ( is_wp_error( $response ) ) {
			$this->record_response( 'destination_services', $response );
			return;
		}

		$services = ( is_array( $response ) && isset( $response['services'] ) && is_array( $response['services'] ) )
			? $response['services']
			: array();

		$offered  = array();
		$selected = null;
		foreach ( $services as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				continue;
			}
			$offered[] = (int) $row['id'];
			if ( (int) $row['id'] === (int) $service_id ) {
				$selected = $row;
			}
		}

		$allowances = array();
		if ( is_array( $selected ) && isset( $selected['additionalServices'] ) && is_array( $selected['additionalServices'] ) ) {
			foreach ( $selected['additionalServices'] as $key => $definition ) {
				if ( is_array( $definition ) && isset( $definition['allowance'] ) ) {
					$allowances[ (string) $key ] = (string) $definition['allowance'];
				}
			}
		}

		$this->stages['destination_services'] = array(
			'requested_service_id' => (int) $service_id,
			'service_offered'      => null !== $selected,
			'offered_service_ids'  => array_slice( $offered, 0, 40 ),
			'allowances'           => $allowances,
		);
	}

	/**
	 * Persist the collected chain onto the order, keeping only the newest few
	 * attempts. Uses WC CRUD so HPOS stores this like any other order meta.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function save( \WC_Order $order ) {
		if ( ! $this->enabled || empty( $this->stages ) ) {
			return;
		}

		$entry = array(
			'at'      => time(),
			'courier' => $this->courier_id,
			'user'    => get_current_user_id(),
			'stages'  => $this->stages,
		);

		$encoded = wp_json_encode( $entry );
		if ( is_string( $encoded ) && strlen( $encoded ) > self::MAX_ENTRY_BYTES ) {
			$entry['stages']   = array( 'payload' => isset( $this->stages['payload'] ) ? $this->stages['payload'] : array() );
			$entry['truncated'] = true;
		}

		$existing = $order->get_meta( self::META_KEY );
		$existing = is_array( $existing ) ? $existing : array();
		$existing[] = $entry;

		if ( count( $existing ) > self::MAX_ENTRIES ) {
			$existing = array_slice( $existing, -self::MAX_ENTRIES );
		}

		$order->update_meta_data( self::META_KEY, $existing );

		// The snapshot worth having most is the one from a create that FAILED,
		// and a failing create returns before the AJAX handler ever calls save().
		// Persisting here is the only way that snapshot survives. This runs only
		// when diagnostics is switched on, so production behaviour is untouched.
		$order->save();
	}

	/**
	 * Read the stored snapshots for display.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	public static function stored( \WC_Order $order ) {
		$rows = $order->get_meta( self::META_KEY );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove every stored snapshot from an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public static function clear( \WC_Order $order ) {
		$order->delete_meta_data( self::META_KEY );
	}

	/**
	 * Recursively make a value safe to store and show.
	 *
	 * Structural values — ids, enums, amounts, booleans, flags — are what make a
	 * snapshot useful, so they are kept verbatim. Only two categories are
	 * altered: secrets are dropped outright, and personal free text is reduced to
	 * a length-bearing preview, which is enough to tell "the field was populated"
	 * or "the field arrived empty" without copying customer data around.
	 *
	 * @param mixed $value Value to redact.
	 * @param int   $depth Current recursion depth.
	 * @return mixed
	 */
	private static function redact( $value, $depth = 0 ) {
		if ( $depth > 8 ) {
			return '[depth-limit]';
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $child ) {
				if ( is_string( $key ) && self::is_secret_key( $key ) ) {
					$out[ $key ] = '[redacted]';
					continue;
				}
				if ( is_string( $key ) && self::is_personal_key( $key ) && ( is_string( $child ) || is_numeric( $child ) ) ) {
					$out[ $key ] = self::preview( (string) $child );
					continue;
				}
				$out[ $key ] = self::redact( $child, $depth + 1 );
			}
			return $out;
		}

		if ( is_string( $value ) ) {
			return self::scrub_free_text( $value );
		}

		return $value;
	}

	/**
	 * Free text from a provider is the one place credentials genuinely leak.
	 *
	 * Speedy authenticates with userName/password inside the request body, so an
	 * authentication error can echo either straight back in prose — and prose is
	 * exactly what key-name matching cannot catch. Pattern matching alone proved
	 * insufficient here: a bare username, and a token written with a space rather
	 * than `=`, both survived it.
	 *
	 * So the primary defence is not a pattern at all. We know our own secrets, so
	 * we look them up and strike them literally, whatever wording surrounds them.
	 * The patterns stay as a second layer for secrets we do not hold, such as a
	 * token minted by the provider mid-conversation.
	 *
	 * @param string $value Free text.
	 * @return string
	 */
	private static function scrub_free_text( $value ) {
		$value = Log_Redactor::response_excerpt( (string) $value, 300 );

		foreach ( self::known_secrets() as $secret ) {
			$value = str_ireplace( $secret, '[redacted]', $value );
		}

		// Secrets we do not hold: `token abc123…` as well as `token=abc123…`.
		$value = preg_replace(
			'/\b(api[_-]?key|api[_-]?secret|client[_-]?secret|access[_-]?token|refresh[_-]?token|token|secret|password|passwd|bearer|user[_-]?name)\b[\s:=]+["\']?([^\s,"\';}]{4,})/i',
			'$1 [redacted]',
			$value
		);

		return (string) $value;
	}

	/**
	 * Every credential value this installation holds, across all option groups.
	 *
	 * Courier-agnostic on purpose: it reads the option groups and picks values
	 * whose key looks like a credential, so a courier added later is covered
	 * without anyone remembering to extend a list here.
	 *
	 * @return string[]
	 */
	private static function known_secrets() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$cache  = array();
		$groups = array( 'speedy', 'econt', 'boxnow', 'pigeon' );

		foreach ( $groups as $group ) {
			$data = bgcs3_get_option( $group );
			if ( ! is_array( $data ) ) {
				continue;
			}
			foreach ( $data as $key => $value ) {
				if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
					continue;
				}
				if ( ! self::is_credential_option_key( $key ) ) {
					continue;
				}
				$value = trim( (string) $value );
				// Below four characters, striking the value would corrupt ordinary
				// text far more than it would protect anything.
				if ( strlen( $value ) >= 4 ) {
					$cache[] = $value;
				}
			}
		}

		// Longest first, so a secret containing another is struck whole.
		usort(
			$cache,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return $cache;
	}

	/**
	 * Credentials — never stored in any form.
	 *
	 * @param string $key Field name.
	 * @return bool
	 */
	private static function is_secret_key( $key ) {
		return (bool) preg_match(
			'/^(?:user_?name|pass(?:word)?|secret|client_?secret|api_?key|api_?secret|token|access_?token|refresh_?token|authorization|auth|signature|webhook_?secret)$/i',
			(string) $key
		);
	}

	/**
	 * Settings keys whose stored value is a secret worth striking from free text.
	 *
	 * Deliberately narrower than it could be: `client_id`, `partner_id` and
	 * `dropoff_office_id` are account identifiers, not secrets — they are printed
	 * on waybills — and they are short and numeric, so literal-striking them would
	 * corrupt unrelated numbers in provider messages while protecting nothing.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	private static function is_credential_option_key( $key ) {
		return (bool) preg_match(
			'/^(?:user|user_?name|pass|passwd|password|secret|client_?secret|api_?key|api_?secret|access_?token|refresh_?token|token|webhook_?secret|auth|authorization|signature)$/i',
			(string) $key
		);
	}

	/**
	 * Customer free text — stored as a length-bearing preview, not verbatim.
	 *
	 * Deliberately excludes `contents`: the shipment description is the field
	 * under investigation, and its exact stored value (and length, against the
	 * truncation constant) is the whole point of the snapshot.
	 *
	 * @param string $key Field name.
	 * @return bool
	 */
	private static function is_personal_key( $key ) {
		return (bool) preg_match(
			'/^(?:client_?name|contact_?name|company_?name|first_?name|last_?name|full_?name|email|phone|number|mobile|street_?name|house_?no|entrance_?no|floor_?no|apartment_?no|address_?note|address_?line|note|shipment_?note|instructions?)$/i',
			(string) $key
		);
	}

	/**
	 * A value preview that proves presence and length without copying content.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function preview( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '[empty]';
		}

		$length = function_exists( 'bgcs3_strlen' ) ? bgcs3_strlen( $value ) : strlen( $value );
		$head   = function_exists( 'bgcs3_substr' ) ? bgcs3_substr( $value, 0, 2 ) : substr( $value, 0, 2 );

		return sprintf( '%s… [%d chars]', $head, (int) $length );
	}
}
