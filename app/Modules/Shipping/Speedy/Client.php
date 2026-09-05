<?php
/**
 * Speedy API client. Speedy authenticates with userName/password/language sent
 * in the BODY of every request (no tokens/headers). Base: api.speedy.bg/v1/.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\Speedy;

use BgCommerce3\Modules\Shipping\Abstract_Client;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

class Client extends Abstract_Client {

	const BASE_URL    = 'https://api.speedy.bg/v1/';
	const COUNTRY_BG  = 100;

	/** @var int */
	protected $timeout = 30;

	/**
	 * @return string
	 */
	protected function base_url() {
		return self::BASE_URL;
	}

	/**
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== (string) Module_Settings::get( Speedy::ID, 'username' )
			&& '' !== (string) Module_Settings::get( Speedy::ID, 'password' );
	}

	/**
	 * Credentials block merged into every request body.
	 *
	 * @return array<string,string>
	 */
	private function credentials() {
		return array(
			'userName' => (string) Module_Settings::get( Speedy::ID, 'username' ),
			'password' => (string) Module_Settings::get( Speedy::ID, 'password' ),
			'language' => (string) Module_Settings::get( Speedy::ID, 'language' ),
		);
	}

	/**
	 * POST a JSON request (credentials auto-merged). Speedy returns logical
	 * errors as { error: { code, message, context } } — surfaced as WP_Error.
	 *
	 * @param string              $endpoint Endpoint (e.g. 'location/site').
	 * @param array<string,mixed> $body     Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function call( $endpoint, array $body = array() ) {
		if ( ! $this->has_credentials() ) {
			return new \WP_Error( 'bgcs3_speedy_no_credentials', __( 'Speedy username/password is missing.', 'bg-commerce-suite' ) );
		}

		$payload  = array_merge( $this->credentials(), $body );
		$data = $this->request_json_url(
			'POST',
			self::BASE_URL . ltrim( $endpoint, '/' ),
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Speedy logical error shape.
		if ( is_array( $data ) && ! empty( $data['error'] ) ) {
			$err = $data['error'];
			$msg = is_array( $err ) && isset( $err['message'] ) ? $err['message'] : __( 'Speedy API error.', 'bg-commerce-suite' );

			// BGCS-AUDIT-009 — Speedy reports logical errors inside an HTTP 200,
			// so this never passes through `Abstract_Client`'s redaction of
			// non-2xx bodies. Go through the shared helper, which always redacts.
			$this->log_provider_error( 'Speedy', $endpoint, $err );

			return new \WP_Error( 'bgcs3_speedy_error', $msg, array( 'body' => $data ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Raw POST returning the response body as-is (for binary PDF from /print).
	 *
	 * @param string              $endpoint Endpoint.
	 * @param array<string,mixed> $body     Request body.
	 * @return string|\WP_Error
	 */
	public function call_raw( $endpoint, array $body = array() ) {
		if ( ! $this->has_credentials() ) {
			return new \WP_Error( 'bgcs3_speedy_no_credentials', __( 'Speedy username/password is missing.', 'bg-commerce-suite' ) );
		}

		$payload  = array_merge( $this->credentials(), $body );
		$body_str = $this->request_binary_url(
			'POST',
			self::BASE_URL . ltrim( $endpoint, '/' ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $body_str ) ) {
			return $body_str;
		}

		$maybe    = json_decode( $body_str, true );
		if ( is_array( $maybe ) && ! empty( $maybe['error'] ) ) {
			$msg = isset( $maybe['error']['message'] ) ? $maybe['error']['message'] : __( 'Speedy API error.', 'bg-commerce-suite' );
			return new \WP_Error( 'bgcs3_speedy_error', $msg, array( 'body' => $maybe ) );
		}

		return $body_str;
	}

	/* ---- Convenience wrappers ---- */

	public function find_sites( $name, $country_id = self::COUNTRY_BG ) {
		return $this->call( 'location/site', array( 'countryId' => $country_id, 'name' => $name ) );
	}

	public function find_offices( $site_id, $country_id = self::COUNTRY_BG ) {
		return $this->call( 'location/office', array( 'countryId' => $country_id, 'siteId' => $site_id ) );
	}

	/**
	 * All offices in the country (for the sender dropoff-office dropdown).
	 *
	 * @param int $country_id Country id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function all_offices( $country_id = self::COUNTRY_BG ) {
		return $this->call( 'location/office', array( 'countryId' => $country_id ) );
	}

	public function find_streets( $site_id, $name ) {
		return $this->call( 'location/street', array( 'siteId' => $site_id, 'name' => $name ) );
	}

	/**
	 * Validate a phone number using Speedy's official validation endpoint.
	 *
	 * @param string $number Phone number.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate_phone( $number ) {
		return $this->call( 'validation/phone', array( 'number' => (string) $number ) );
	}

	/**
	 * List of courier services available for the account (for the settings dropdown).
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_services() {
		return $this->call( 'services', array( 'date' => gmdate( 'Y-m-d' ) ) );
	}

	/**
	 * Services available for the exact sender/recipient destination.
	 *
	 * Unlike /services, this endpoint returns ExtendedCourierService rows whose
	 * additionalServices carry FORBIDDEN / ALLOWED / REQUIRED allowances.
	 * This is the authoritative preflight for optional services such as OBPD.
	 *
	 * @param array<string,mixed> $recipient CalculationRecipient.
	 * @param array<string,mixed> $sender    CalculationSender.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_destination_services( array $recipient, array $sender = array() ) {
		$body = array(
			'date'      => gmdate( 'Y-m-d' ),
			'recipient' => $recipient,
		);
		if ( ! empty( $sender ) ) {
			$body['sender'] = $sender;
		}
		return $this->call( 'services/destination', $body );
	}

	/**
	 * Contract clients tied to the account (the merchant's Speedy agreements).
	 * Used to auto-populate the sender (Client ID) dropdown in settings.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_contract_clients() {
		return $this->call( 'client/contract', array() );
	}

	/**
	 * The account's contract terms (`ContractInfo`).
	 *
	 * Booleans only — `cod.moneyTransferAllowed`, `cod.hasCODAnnex`,
	 * `cod.codFiscalReceiptAllowed`, `administrativeFeeAllowed`. Speedy publishes
	 * no tariff endpoint, so the amounts behind those entitlements can only be
	 * observed from a `calculate` response; see docs/speedy-fees-and-surcharges.md.
	 *
	 * The path is `client/contract/info`, not the `client/contractInfo` the
	 * schema's Java class name (`ContractInfoRequest`) suggests: that spelling
	 * answers 404 with an HTML error page, which is how it was found.
	 *
	 * Non-destructive: it reads the agreement, it does not touch a shipment.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_contract_info() {
		return $this->call( 'client/contract/info', array() );
	}

	/**
	 * "Свържи със Speedy" / "Провери връзката" (Master Instruction §6): a single
	 * non-destructive authentication check. `client/contract` doubles as both an
	 * auth probe and the source of the sender objects the settings screen needs
	 * (§7) — no shipment is created, nothing destructive is called.
	 *
	 * Never logs or returns the raw username/password; on success only the
	 * contract objects (id + human label material) are returned.
	 *
	 * @return array{ok:true,clients:array<int,array<string,mixed>>}|\WP_Error
	 */
	public function validate_credentials() {
		if ( ! $this->has_credentials() ) {
			return new \WP_Error( 'bgcs3_speedy_no_credentials', __( 'Speedy username/password is missing.', 'bg-commerce-suite' ) );
		}

		$response = $this->get_contract_clients();
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$clients = isset( $response['clients'] ) && is_array( $response['clients'] ) ? $response['clients'] : array();

		return array(
			'ok'      => true,
			'clients' => $clients,
		);
	}

	public function calculate( array $body ) {
		return $this->call( 'calculate', $body );
	}

	/**
	 * Validate the exact CreateShipmentRequest without creating a shipment.
	 *
	 * @param array<string,mixed> $body Shipment request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate_shipment( array $body ) {
		return $this->call( 'validation/shipment', $body );
	}

	public function create_shipment( array $body ) {
		return $this->call( 'shipment', $body );
	}

	/**
	 * Reads shipments back from Speedy. BGCS uses this to verify the mutable
	 * values that the public ShipmentInfo response exposes after an update.
	 *
	 * @param string[] $shipment_ids Shipment ids.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function shipment_info( array $shipment_ids ) {
		return $this->call( 'shipment/info', array( 'shipmentIds' => array_values( array_map( 'strval', $shipment_ids ) ) ) );
	}

	public function cancel_shipment( $shipment_id, $comment = '' ) {
		return $this->call( 'shipment/cancel', array( 'shipmentId' => $shipment_id, 'comment' => $comment ) );
	}

	/**
	 * Track one or more parcels.
	 *
	 * Speedy accept up to ten parcels per request and say plainly that repeated
	 * single-parcel polling is the wrong way to use the service. `lastOperationOnly`
	 * is their own recommendation for callers that keep the status locally, which
	 * is exactly what we do — but it is only safe once some history is already
	 * stored, because it returns the newest operation and nothing before it.
	 *
	 * @param string|string[] $parcel_ids One id, or up to ten.
	 * @param bool            $last_only  Ask only for the newest operation.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function track( $parcel_ids, $last_only = false ) {
		$parcels = array();
		foreach ( (array) $parcel_ids as $id ) {
			$id = trim( (string) $id );
			if ( '' !== $id ) {
				$parcels[] = array( 'id' => $id );
			}
		}

		if ( empty( $parcels ) ) {
			return new \WP_Error( 'bgcs3_speedy_no_parcels', __( 'No tracking number is available.', 'bg-commerce-suite' ) );
		}

		$body = array( 'parcels' => $parcels );

		if ( $last_only ) {
			$body['lastOperationOnly'] = true;
		}

		return $this->call( 'track', $body );
	}

	/**
	 * Print labels → PDF binary.
	 *
	 * @param string[] $parcel_ids Parcel ids.
	 * @return string|\WP_Error PDF bytes or error.
	 */
	/**
	 * Shipment payout details for a payout-date period.
	 * Official endpoint: POST /v1/payments.
	 *
	 * @param string $from Start date, Y-m-d.
	 * @param string $to   End date, Y-m-d.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cod_payouts( $from, $to ) {
		return $this->call(
			'payments',
			array(
				'fromDate'       => (string) $from . 'T00:00:00Z',
				'toDate'         => (string) $to . 'T23:59:59Z',
				'includeDetails' => true,
			)
		);
	}

	public function print_pdf( array $parcel_ids, $paper_size = 'A6' ) {
		$parcels = array();
		foreach ( $parcel_ids as $id ) {
			$parcels[] = array( 'parcel' => array( 'id' => $id ) );
		}

		$paper_size = in_array( $paper_size, array( 'A6', 'A4', 'A4_4xA6' ), true ) ? $paper_size : 'A6';

		return $this->call_raw( 'print', array( 'parcels' => $parcels, 'paperSize' => $paper_size ) );
	}
}
