<?php
/**
 * Econt API client (modern JSON API). Basic-auth against the demo or live
 * services endpoint depending on configuration.
 *
 * NOTE: request/response shapes follow Econt's public JSON API. Pricing and
 * label payloads (Phase 1 continued) should be verified against a real demo
 * account before production use.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\Econt;

use BgCommerce3\Modules\Shipping\Abstract_Client;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

class Client extends Abstract_Client {

	const LIVE_URL = 'https://ee.econt.com/services/';
	const DEMO_URL = 'https://demo.econt.com/ee/services/';

	// Nomenclatures.
	const CITIES   = 'Nomenclatures/NomenclaturesService.getCities.json';
	const OFFICES  = 'Nomenclatures/NomenclaturesService.getOffices.json';
	const STREETS  = 'Nomenclatures/NomenclaturesService.getStreets.json';
	const QUARTERS = 'Nomenclatures/NomenclaturesService.getQuarters.json';

	// Shipments.
	const LABEL_CREATE    = 'Shipments/LabelService.createLabel.json';
	const LABEL_DELETE    = 'Shipments/LabelService.deleteLabels.json';
	const SHIPMENT_STATUS       = 'Shipments/ShipmentService.getShipmentStatuses.json';
	const REQUEST_COURIER        = 'Shipments/ShipmentService.requestCourier.json';
	const REQUEST_COURIER_STATUS = 'Shipments/ShipmentService.getRequestCourierStatus.json';
	const PAYMENT_REPORT        = 'PaymentReport/PaymentReportService.PaymentReport.json';

	/**
	 * @return string
	 */
	protected function base_url() {
		$env = Module_Settings::get( Econt::ID, 'env' );
		return ( 'live' === $env ) ? self::LIVE_URL : self::DEMO_URL;
	}

	/**
	 * @return array<string,string>
	 */
	protected function auth_headers() {
		$user = (string) Module_Settings::get( Econt::ID, 'user' );
		$pass = (string) Module_Settings::get( Econt::ID, 'password' );

		if ( '' === $user || '' === $pass ) {
			return array();
		}

		return array(
			'Authorization' => 'Basic ' . base64_encode( $user . ':' . $pass ),
		);
	}

	/**
	 * Whether credentials are configured.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== (string) Module_Settings::get( Econt::ID, 'user' )
			&& '' !== (string) Module_Settings::get( Econt::ID, 'password' );
	}

	/**
	 * Generic JSON POST helper.
	 *
	 * @param string              $endpoint Endpoint.
	 * @param array<string,mixed> $body     Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function call( $endpoint, array $body = array() ) {
		if ( ! $this->has_credentials() ) {
			return new \WP_Error( 'bgcs3_econt_no_credentials', __( 'Econt username/password is missing.', 'bg-commerce-suite' ) );
		}

		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * @param string $country_code Econt country code (e.g. BGR).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_cities( $country_code ) {
		return $this->call( self::CITIES, array( 'countryCode' => $country_code ) );
	}

	/**
	 * @param string $country_code Econt country code.
	 * @param string $city_id      City id ('' / 0 = all offices in the country).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_offices( $country_code, $city_id ) {
		$body = array( 'countryCode' => $country_code );
		if ( ! empty( $city_id ) ) {
			$body['cityID'] = $city_id;
		}
		return $this->call( self::OFFICES, $body );
	}

	/**
	 * Client profiles tied to the account: sender data, addresses and the COD
	 * pay options / agreements (used for the settings dropdowns).
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_client_profiles() {
		return $this->call( 'Profile/ProfileService.getClientProfiles.json', array() );
	}

	/**
	 * @param string $city_id City id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_streets( $city_id ) {
		return $this->call( self::STREETS, array( 'cityID' => $city_id ) );
	}

	/**
	 * @param string $city_id City id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_quarters( $city_id ) {
		return $this->call( self::QUARTERS, array( 'cityID' => $city_id ) );
	}

	/**
	 * @param string[] $numbers Shipment numbers.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_shipment_statuses( array $numbers ) {
		return $this->call( self::SHIPMENT_STATUS, array( 'shipmentNumbers' => array_values( $numbers ) ) );
	}

	/**
	 * Download the temporary label URL returned by createLabel. Only Econt's
	 * documented service hosts are allowed so a provider response cannot turn
	 * this authenticated request into SSRF.
	 *
	 * @param string $url Absolute Econt PDF URL.
	 * @return string|\WP_Error
	 */
	public function download_label_pdf( $url ) {
		$parts   = wp_parse_url( (string) $url );
		$host    = is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$scheme  = is_array( $parts ) && ! empty( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$allowed = array( 'ee.econt.com', 'demo.econt.com' );
		$port = is_array( $parts ) && isset( $parts['port'] ) ? (int) $parts['port'] : 443;
		if ( 'https' !== $scheme || 443 !== $port || ! in_array( $host, $allowed, true ) ) {
			return new \WP_Error( 'bgcs3_econt_label_url', __( 'Econt returned an invalid label PDF URL.', 'bg-commerce-suite' ) );
		}
		return $this->request_binary_url(
			'GET',
			(string) $url,
			array(
				'headers'     => array_merge( array( 'Accept' => 'application/pdf' ), $this->auth_headers() ),
				'redirection' => 0,
			)
		);
	}

	/**
	 * Create a standalone courier pickup request.
	 *
	 * @param array<string,mixed> $body Official ShipmentService.requestCourier body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function request_courier( array $body ) {
		return $this->call( self::REQUEST_COURIER, $body );
	}

	/**
	 * Read the status of a standalone courier pickup request.
	 *
	 * @param string $request_id Courier request identifier.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_courier_request_status( $request_id ) {
		return $this->call( self::REQUEST_COURIER_STATUS, array( 'requestCourierIds' => array( (string) $request_id ) ) );
	}

	/**
	 * Paid COD / money-transfer rows by payout date.
	 * Official PaymentReport endpoint.
	 *
	 * @param string $from Start date, Y-m-d.
	 * @param string $to   End date, Y-m-d.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cod_payouts( $from, $to ) {
		return $this->call(
			self::PAYMENT_REPORT,
			array(
				'dateFrom' => (string) $from,
				'dateTo'   => (string) $to,
			)
		);
	}
}
