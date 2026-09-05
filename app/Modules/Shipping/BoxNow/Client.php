<?php
/**
 * BoxNow API client. Authenticates via OAuth2 Client Credentials and caches the
 * bearer token in transients. Interacts with location, delivery-request and label APIs.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\BoxNow;

use BgCommerce3\Modules\Shipping\Abstract_Client;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

class Client extends Abstract_Client {

	const STAGE_API_URL = 'https://api-stage.boxnow.bg/';
	const LIVE_API_URL  = 'https://api-production.boxnow.bg/';

	const STAGE_LOCATION_URL = 'https://locationapi-stage.boxnow.bg/';
	const LIVE_LOCATION_URL  = 'https://locationapi-production.boxnow.bg/';

	/** @var array<string,string> Temporary request headers (e.g. X-PartnerID). */
	protected $temp_headers = array();

	/**
	 * @return string
	 */
	protected function base_url() {
		$env = Module_Settings::get( BoxNow::ID, 'env' );
		return ( 'live' === $env ) ? self::LIVE_API_URL : self::STAGE_API_URL;
	}

	/**
	 * Get locations API base URL.
	 *
	 * @return string
	 */
	public function locations_base_url() {
		$env = Module_Settings::get( BoxNow::ID, 'env' );
		return ( 'live' === $env ) ? self::LIVE_LOCATION_URL : self::STAGE_LOCATION_URL;
	}

	/**
	 * OAuth Bearer token + temp headers for API calls.
	 *
	 * @return array<string,string>
	 */
	protected function auth_headers() {
		$headers = array();
		$token   = $this->get_access_token();

		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		if ( ! empty( $this->temp_headers ) ) {
			$headers = array_merge( $headers, $this->temp_headers );
		}

		return $headers;
	}

	/**
	 * Whether client credentials are configured.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== (string) Module_Settings::get( BoxNow::ID, 'client_id' )
			&& '' !== (string) Module_Settings::get( BoxNow::ID, 'client_secret' );
	}

	/**
	 * Get cached access token or request a new one via OAuth.
	 *
	 * @return string|false Access token, or false on error.
	 */
	public function get_access_token() {
		if ( ! $this->has_credentials() ) {
			return false;
		}

		$client_id     = (string) Module_Settings::get( BoxNow::ID, 'client_id' );
		$client_secret = (string) Module_Settings::get( BoxNow::ID, 'client_secret' );
		$env           = Module_Settings::get( BoxNow::ID, 'env' );

		// Unique cache key based on credentials and environment.
		$transient_key = 'bgcs3_boxnow_token_' . md5( $client_id . '|' . $env . '|' . hash( 'sha256', $client_secret ) );
		$token         = get_transient( $transient_key );

		if ( $token ) {
			return $token;
		}

		// Perform raw authentication POST directly.
		$url  = $this->base_url() . 'api/v1/auth-sessions';
		$args = array(
			'method'  => 'POST',
			'timeout' => $this->timeout,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
				)
			),
		);

		$body = $this->request_json_url( 'POST', $url, $args );
		if ( is_wp_error( $body ) ) {
			return false;
		}

		if ( empty( $body['access_token'] ) ) {
			return false;
		}

		$token      = (string) $body['access_token'];
		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;

		// Cache slightly shorter than expiry (5 minutes safety window).
		set_transient( $transient_key, $token, max( 60, $expires_in - 300 ) );

		return $token;
	}

	/**
	 * Fetch locker locations list from the public static location JSON.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function get_lockboxes() {
		$url      = $this->locations_base_url() . 'v1/apms_bg-BG.json';
		$url      = add_query_arg( 'size', 'medium', $url );
		$data = $this->request_json_url( 'GET', $url );

		// Some environments wrap the list in { "data": [...] }.
		if ( ! is_wp_error( $data ) && is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = $data['data'];
		}

		if ( ! is_wp_error( $data ) && is_array( $data ) && ! empty( $data ) ) {
			$data = array_filter(
				$data,
				static function ( $location ) {
					return is_array( $location )
						&& isset( $location['type'] )
						&& 'apm' === (string) $location['type'];
				}
			);

			if ( ! empty( $data ) ) {
				return array_values( $data );
			}
		}

		// Fallback: on stage the static JSON can be empty — use the authenticated
		// destinations endpoint (the account-valid locker source) instead.
		if ( $this->has_credentials() ) {
			$dest = $this->get_destinations();
			if ( ! is_wp_error( $dest ) && ! empty( $dest ) ) {
				return $dest;
			}
			if ( is_wp_error( $dest ) ) {
				return $dest;
			}
		}

		return array();
	}

	/**
	 * Partners/accounts the current OAuth credentials are allowed to manage.
	 * Official BOX NOW Partner API: GET /api/v1/entrusted-partners.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function get_entrusted_partners() {
		$res = $this->request( 'GET', 'api/v1/entrusted-partners' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$list = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : $res;
		if ( isset( $list['partners'] ) && is_array( $list['partners'] ) ) {
			$list = $list['partners'];
		}
		if ( ! is_array( $list ) ) {
			return array();
		}

		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * Origins (sender warehouses) valid for this account/environment.
	 * Authenticated GET /api/v1/origins → data[].
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function get_origins() {
		$partner_id = (string) bgcs3_get_option( BoxNow::ID, 'partner_id', '' );
		if ( '' !== $partner_id ) {
			$this->temp_headers = array( 'X-PartnerID' => $partner_id );
		}

		$res = $this->request( 'GET', 'api/v1/origins' );

		$this->temp_headers = array();

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return ( isset( $res['data'] ) && is_array( $res['data'] ) ) ? $res['data'] : array();
	}

	/**
	 * Destinations (lockers/APMs) valid for this account/environment — the only
	 * correct source for `destination.locationId`. Authenticated
	 * GET /api/v1/destinations(?requiredSize=) → data[].
	 *
	 * @param string $size Optional required compartment size ('1'|'2'|'3').
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function get_destinations( $size = '' ) {
		$query = array(
			'locationType' => 'apm',
		);
		if ( '' !== $size && 'all' !== $size ) {
			$query['requiredSize'] = $size;
		}

		$partner_id = (string) bgcs3_get_option( BoxNow::ID, 'partner_id', '' );
		if ( '' !== $partner_id ) {
			$this->temp_headers = array( 'X-PartnerID' => $partner_id );
		}

		$res = $this->request( 'GET', 'api/v1/destinations', array(), $query );

		$this->temp_headers = array();

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$list = ( isset( $res['data'] ) && is_array( $res['data'] ) ) ? $res['data'] : array();

		return array_values(
			array_filter(
				$list,
				static function ( $location ) {
					return is_array( $location )
						&& isset( $location['type'] )
						&& 'apm' === (string) $location['type'];
				}
			)
		);
	}

	/**
	 * Create a delivery request.
	 *
	 * @param array<string,mixed> $payload Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function create_delivery_request( array $payload ) {
		$partner_id = (string) bgcs3_get_option( BoxNow::ID, 'partner_id', '' );
		if ( '' !== $partner_id ) {
			$this->temp_headers = array( 'X-PartnerID' => $partner_id );
		}

		$response = $this->request( 'POST', 'api/v1/delivery-requests', $payload );

		$this->temp_headers = array();

		return $response;
	}

	/**
	 * Cancel a parcel delivery request.
	 *
	 * @param string $parcel_id Unique parcel ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cancel_parcel( $parcel_id ) {
		$partner_id = (string) bgcs3_get_option( BoxNow::ID, 'partner_id', '' );
		if ( '' !== $partner_id ) {
			$this->temp_headers = array( 'X-PartnerID' => $partner_id );
		}

		$response = $this->request( 'POST', 'api/v1/parcels/' . rawurlencode( $parcel_id ) . ':cancel' );

		$this->temp_headers = array();

		return $response;
	}

	/**
	 * Fetch raw binary PDF waybill data for a parcel.
	 *
	 * @param string $parcel_id Unique parcel ID.
	 * @return string|\WP_Error Binary PDF data string or WP_Error.
	 */
	public function get_parcel_pdf( $parcel_id ) {
		return $this->label_pdf( 'api/v1/parcels/' . rawurlencode( $parcel_id ) . '/label.pdf' );
	}

	/**
	 * Printable labels for EVERY parcel of one delivery request, in a single
	 * PDF. Needed whenever an order occupies more than one locker compartment —
	 * `/parcels/{id}/label.pdf` returns just that one parcel's label.
	 *
	 * @param string $order_number Our own reference, as sent on create.
	 * @return string|\WP_Error Binary PDF data.
	 */
	public function get_delivery_request_pdf( $order_number ) {
		return $this->label_pdf( 'api/v1/delivery-requests/' . rawurlencode( $order_number ) . '/label.pdf' );
	}

	/**
	 * Shared authenticated PDF fetch.
	 *
	 * @param string $endpoint Endpoint path.
	 * @return string|\WP_Error Binary PDF data.
	 */
	private function label_pdf( $endpoint ) {
		if ( ! $this->has_credentials() ) {
			return new \WP_Error( 'bgcs3_boxnow_no_credentials', __( 'BOX NOW credentials are missing.', 'bg-commerce-suite' ) );
		}

		$token = $this->get_access_token();
		if ( ! $token ) {
			return new \WP_Error( 'bgcs3_boxnow_auth_failed', __( 'BOX NOW authentication failed.', 'bg-commerce-suite' ) );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/pdf',
		);

		$partner_id = (string) bgcs3_get_option( BoxNow::ID, 'partner_id', '' );
		if ( '' !== $partner_id ) {
			$headers['X-PartnerID'] = $partner_id;
		}

		return $this->request_binary_url(
			'GET',
			$this->base_url() . $endpoint,
			array(
				'method'  => 'GET',
				'timeout' => $this->timeout,
				'headers' => $headers,
			)
		);
	}

	/**
	 * Fetch parcel details/events from BoxNow.
	 *
	 * @param string $parcel_id Unique parcel ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_parcel_status( $parcel_id ) {
		$partner_id = (string) bgcs3_get_option( BoxNow::ID, 'partner_id', '' );
		if ( '' !== $partner_id ) {
			$this->temp_headers = array( 'X-PartnerID' => $partner_id );
		}

		$response = $this->request( 'GET', 'api/v1/parcels', array(), array( 'parcelId' => $parcel_id ) );

		$this->temp_headers = array();

		return $response;
	}
}
