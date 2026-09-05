<?php
/**
 * BOX NOW parcel-event webhook.
 *
 * BOX NOW publishes, the shop subscribes: they POST a CloudEvents-shaped
 * message to an address we register in their profile, every time a parcel
 * changes state. Everything here is pure decision-making over the payload, so
 * it can be asserted without an HTTP request.
 *
 * Contract source: BOX NOW „Webhook description“ (05.11.2024) and the partner
 * API v1.69 schema. The two disagree in one place that matters — see
 * {@see self::STATES}.
 *
 * @package BgCommerce3\BoxNow
 */

namespace BgCommerce3\Modules\Shipping\BoxNow;

use BgCommerce3\Shipping\Tracking_State;

defined( 'ABSPATH' ) || exit;

class Webhook {

	/**
	 * Parcel event => canonical BGCS shipment state.
	 *
	 * The published vocabulary, taken from the webhook document rather than the
	 * v1.69 YAML, because the YAML is wrong here: it spells cancellation
	 * `canceled` (one L) while the webhook document — and the events actually
	 * sent — use `cancelled` and add `cancelled-return`. Both spellings are
	 * accepted, since being lenient about someone else's typo costs nothing and
	 * a missed cancellation costs a parcel.
	 *
	 * Everything not listed resolves to UNKNOWN, which Core treats as "record
	 * it, change no order status" — the safe default (Rule 44/45).
	 *
	 * @var array<string,string>
	 */
	const STATES = array(
		// Terminal, and — by Core's default policy — the only one that moves a
		// WooCommerce order at all (to „completed“).
		'delivered'            => Tracking_State::DELIVERED,

		// On its way.
		'new'                  => Tracking_State::CREATED,
		'accepted-to-locker'   => Tracking_State::ACCEPTED,
		'in-transit'           => Tracking_State::IN_TRANSIT,
		'in-depot'             => Tracking_State::IN_TRANSIT,
		'wait-for-load'        => Tracking_State::IN_TRANSIT,

		// In the locker, waiting for the customer — the state a merchant most
		// often wants to see, and the one polling reported as „unknown“.
		'final-destination'    => Tracking_State::AVAILABLE_FOR_PICKUP,
		'in-final-destination' => Tracking_State::AVAILABLE_FOR_PICKUP,

		// Return lifecycle. The current BOX NOW guide describes expired-return
		// as already returned to sender; older `expired` remains accepted as a
		// non-terminal backwards-compatible event.
		'expired'              => Tracking_State::RETURN_IN_PROGRESS,
		'expired-return'       => Tracking_State::RETURNED,
		'accepted-for-return'  => Tracking_State::RETURN_IN_PROGRESS,
		'returned'             => Tracking_State::RETURNED,

		'cancelled'            => Tracking_State::CANCELLED,
		'canceled'             => Tracking_State::CANCELLED,
		'cancelled-return'     => Tracking_State::RETURNED,

		// Something went wrong; a human has to look.
		'missing'              => Tracking_State::EXCEPTION,
		'lost'                 => Tracking_State::EXCEPTION,
	);

	/**
	 * Verifies the message and extracts what Core needs from it.
	 *
	 * @param string $body   Raw request body, exactly as received.
	 * @param string $secret Webhook secret from the settings.
	 * @return array{order_id:int,event:string,state:string,message_id:string,time:string}|\WP_Error
	 */
	public static function parse( $body, $secret ) {
		$secret = trim( (string) $secret );
		if ( '' === $secret ) {
			// Misconfiguration, not an attack. 503 so BOX NOW retries once the
			// secret is filled in, instead of dropping real events.
			return self::fail( 'no_secret', 503 );
		}

		$message = json_decode( (string) $body, true );
		if ( ! is_array( $message ) || empty( $message['data'] ) || ! is_array( $message['data'] ) ) {
			return self::fail( 'malformed', 400 );
		}

		$signature = isset( $message['datasignature'] ) ? (string) $message['datasignature'] : '';
		if ( ! self::signature_ok( (string) $body, $signature, $secret ) ) {
			return self::fail( 'bad_signature', 403 );
		}

		$data = $message['data'];

		$reference = '';
		foreach ( array( 'orderNumber', 'parcelReferenceNumber' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$reference = (string) $data[ $key ];
				break;
			}
		}

		$order_id = self::order_id_from_reference( $reference );
		if ( $order_id <= 0 ) {
			// Well-formed and correctly signed, but about a parcel this shop
			// does not know — a shared partner id, or an order long deleted.
			// 202 so BOX NOW records delivery and stops retrying.
			return self::fail( 'unknown_order', 202 );
		}

		$event = isset( $data['event'] ) ? strtolower( trim( (string) $data['event'] ) ) : '';
		if ( '' === $event && ! empty( $data['parcelState'] ) ) {
			$event = strtolower( trim( (string) $data['parcelState'] ) );
		}

		$message_id = isset( $message['id'] ) ? (string) $message['id'] : '';

		return array(
			'order_id'     => $order_id,
			'event'        => $event,
			'state'        => self::state( $event ),
			'message_id'   => $message_id,
			// The document is explicit: with several updates close together the
			// receiver must order by `data.time`, not by arrival.
			'time'         => isset( $data['time'] ) ? (string) $data['time'] : ( isset( $message['time'] ) ? (string) $message['time'] : '' ),
			'fingerprints' => self::fingerprints( $message_id, self::raw_data( (string) $body ) ),
		);
	}

	/**
	 * Stable identifiers for one delivered message, used to recognise a replay.
	 *
	 * BOX NOW retries a message until the receiver answers 200 OK, with roughly
	 * ten minutes between attempts and a last attempt 24 hours after the event
	 * was created. Duplicates are therefore ordinary operation, not an attack,
	 * and recognising them cannot be left to chance.
	 *
	 * Two fingerprints are produced, and a match on EITHER means "already seen":
	 *
	 *   id:…    the CloudEvents message id. Their schema follows CloudEvents,
	 *           where `id` is a required attribute, so this is normally present
	 *           — but idempotency must not DEPEND on an optional-looking field,
	 *           which is what BGCS-AUDIT-007 was about.
	 *   body:…  a digest of the exact `data` substring that was signed. This
	 *           catches a retry that arrives with a fresh message id, and keeps
	 *           replay protection working when no id is sent at all.
	 *
	 * Two genuinely distinct events cannot share a body digest: `data.time` is
	 * part of the signed payload, so identical bytes mean the same event.
	 *
	 * @param string $message_id CloudEvents message id ('' when absent).
	 * @param string $raw_data   The `data` value exactly as it arrived.
	 * @return string[]
	 */
	public static function fingerprints( $message_id, $raw_data ) {
		$fingerprints = array();

		$message_id = trim( (string) $message_id );
		if ( '' !== $message_id ) {
			$fingerprints[] = 'id:' . $message_id;
		}

		$raw_data = (string) $raw_data;
		if ( '' !== $raw_data ) {
			$fingerprints[] = 'body:' . hash( 'sha256', $raw_data );
		}

		return $fingerprints;
	}

	/**
	 * `datasignature` is an HMAC-SHA256 digest of the `data` object.
	 *
	 * Computed over the RAW `data` substring as it arrived, never over a
	 * re-encoded array: `json_encode()` would re-order keys, change unicode and
	 * slash escaping, and produce a digest that could never match theirs.
	 *
	 * @param string $body      Raw body.
	 * @param string $signature Claimed signature.
	 * @param string $secret    Shared secret.
	 * @return bool
	 */
	public static function signature_ok( $body, $signature, $secret ) {
		$signature = trim( (string) $signature );
		if ( '' === $signature ) {
			return false;
		}

		$raw = self::raw_data( $body );
		if ( '' === $raw ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $raw, $secret );

		// hash_equals, so a wrong signature cannot be discovered byte by byte
		// from how long the comparison took.
		if ( hash_equals( $expected, strtolower( $signature ) ) ) {
			return true;
		}

		// Some senders base64 the same digest. Accepting both is not a weaker
		// check — it is the same HMAC under the same secret, just encoded
		// differently.
		return hash_equals( base64_encode( hash_hmac( 'sha256', $raw, $secret, true ) ), $signature );
	}

	/**
	 * The `data` value exactly as it appears in the body, braces included.
	 *
	 * @param string $body Raw body.
	 * @return string
	 */
	public static function raw_data( $body ) {
		$body  = (string) $body;
		$start = strpos( $body, '"data"' );
		if ( false === $start ) {
			return '';
		}

		$open = strpos( $body, '{', $start );
		if ( false === $open ) {
			return '';
		}

		// Walk the braces, ignoring any inside strings — a customer's address
		// may legitimately contain one.
		$depth     = 0;
		$in_string = false;
		$escaped   = false;
		$length    = strlen( $body );

		for ( $i = $open; $i < $length; $i++ ) {
			$char = $body[ $i ];

			if ( $escaped ) {
				$escaped = false;
				continue;
			}

			if ( '\\' === $char ) {
				$escaped = true;
				continue;
			}

			if ( '"' === $char ) {
				$in_string = ! $in_string;
				continue;
			}

			if ( $in_string ) {
				continue;
			}

			if ( '{' === $char ) {
				$depth++;
			} elseif ( '}' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $body, $open, $i - $open + 1 );
				}
			}
		}

		return '';
	}

	/**
	 * Canonical state for a parcel event.
	 *
	 * @param string $event Event or parcel state.
	 * @return string
	 */
	public static function state( $event ) {
		$event = strtolower( trim( (string) $event ) );

		return isset( self::STATES[ $event ] ) ? self::STATES[ $event ] : Tracking_State::UNKNOWN;
	}

	/**
	 * The order id inside a shipment reference (`{site}-{order}-{edition}`).
	 *
	 * @param string $reference Reference from the event.
	 * @return int 0 when it is not one of ours.
	 */
	public static function order_id_from_reference( $reference ) {
		$reference = trim( (string) $reference );
		if ( '' === $reference ) {
			return 0;
		}

		if ( preg_match( '/^.+-(\d+)-(\d+)$/', $reference, $match ) ) {
			return (int) $match[1];
		}

		// A bare numeric reference is an order id on its own.
		return ctype_digit( $reference ) ? (int) $reference : 0;
	}

	/**
	 * @param string $code   Machine-readable reason.
	 * @param int    $status HTTP status Core should answer with.
	 * @return \WP_Error
	 */
	private static function fail( $code, $status ) {
		return new \WP_Error( $code, $code, $status );
	}
}
