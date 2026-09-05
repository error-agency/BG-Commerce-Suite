<?php
/**
 * POST /bg-commerce-suite/v3/webhook/{courier}
 *
 * The address a courier is given so it can push parcel events instead of us
 * polling for them. Public by necessity — the caller is another company's
 * server, which has no WordPress session and no nonce. Authentication is
 * therefore the courier's own signature over the payload, verified by the
 * courier module, never WordPress auth.
 *
 * Core owns only the transport: the route, the size guard, and the rule that a
 * reply may never reveal anything about this shop. What a valid signature looks
 * like and what an event means belongs to the courier add-on, reached through
 * the same duck-typed pattern as the rest of the module contract.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Rest;

defined( 'ABSPATH' ) || exit;

class Webhook_Endpoint extends Controller {

	/**
	 * Largest body we will parse. Courier events are a few kilobytes; anything
	 * far beyond that is not a parcel event.
	 */
	const MAX_BYTES = 65536;

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/webhook/(?P<courier>[a-z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Public on purpose. The courier proves itself by signature; see
				// the class docblock. Never relax this into a nonce check — the
				// sender cannot produce one.
				'permission_callback' => '__return_true',
				'args'                => array(
					'courier' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$courier_id = sanitize_key( (string) $request->get_param( 'courier' ) );
		$body       = (string) $request->get_body();

		if ( strlen( $body ) > self::MAX_BYTES ) {
			return $this->reply( 413, 'payload_too_large' );
		}

		/** @var \BgCommerce3\Module\Module_Registry $registry */
		$registry = $this->container['modules'];
		$module   = $registry->get( $courier_id );

		// A wrong or disabled courier id answers exactly like a bad signature,
		// so the endpoint cannot be used to enumerate which couriers this shop
		// runs.
		if ( ! $module || ! method_exists( $module, 'handle_webhook' ) ) {
			return $this->reply( 202, 'ignored' );
		}

		$result = $module->handle_webhook( $request );

		if ( is_wp_error( $result ) ) {
			// The module decides the code; the message stays internal. A
			// courier retrying on 5xx is correct, on 4xx it should stop.
			$status = (int) $result->get_error_data();

			$this->log( $courier_id, $result );

			if ( $status < 200 || $status >= 600 ) {
				$status = 400;
			}

			return $this->reply( $status, $result->get_error_code() );
		}

		return $this->reply( 200, 'ok' );
	}

	/**
	 * A deliberately uninformative reply.
	 *
	 * The body says only what the sender needs in order to decide whether to
	 * retry. It never confirms that an order exists, that a waybill is known, or
	 * that a signature was merely wrong rather than absent — an unauthenticated
	 * caller must not be able to learn anything from the differences.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Machine-readable outcome.
	 * @return \WP_REST_Response
	 */
	private function reply( $status, $code ) {
		return new \WP_REST_Response( array( 'status' => $code ), $status );
	}

	/**
	 * Records a rejected event for the merchant to look at, with no payload —
	 * a courier webhook carries the customer's name, phone and e-mail, and a
	 * rejected one is exactly the case where it must not be written to a log
	 * file that is not treated as personal data.
	 *
	 * @param string    $courier_id Courier.
	 * @param \WP_Error $error      Failure.
	 */
	private function log( $courier_id, \WP_Error $error ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only, no payload.
			sprintf(
				'[BGCS] webhook rejected: courier=%s reason=%s',
				$courier_id,
				$error->get_error_code()
			)
		);
	}
}
