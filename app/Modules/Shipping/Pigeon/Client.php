<?php
/**
 * Pigeon Express API client. REST + JSON, authenticated with X-API-Key /
 * X-API-Secret headers. Live vs sandbox base URL from the settings.
 *
 * @package BgCommerce3\Pigeon
 */

namespace BgCommerce3\Modules\Shipping\Pigeon;

use BgCommerce3\Modules\Shipping\Abstract_Client;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

class Client extends Abstract_Client {

	const URL_LIVE    = 'https://api.pigeonexpress.com/v1/';
	const URL_SANDBOX = 'https://api-demo.pigeonexpress.com/v1/';

	/**
	 * @return string
	 */
	protected function base_url() {
		return ( 'yes' === Module_Settings::get( Pigeon::ID, 'sandbox' ) ) ? self::URL_SANDBOX : self::URL_LIVE;
	}

	/**
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== (string) Module_Settings::get( Pigeon::ID, 'api_key' )
			&& '' !== (string) Module_Settings::get( Pigeon::ID, 'api_secret' );
	}

	/**
	 * @return array<string,string>
	 */
	protected function auth_headers() {
		return array(
			'X-API-Key'    => (string) Module_Settings::get( Pigeon::ID, 'api_key' ),
			'X-API-Secret' => (string) Module_Settings::get( Pigeon::ID, 'api_secret' ),
		);
	}

	/* ---- Locations ---- */

	public function get_cities( $name ) {
		return $this->request( 'GET', 'cities', array(), array_filter( array( 'name' => $name, 'page' => 1 ) ) );
	}

	public function get_streets( $city_id, $name ) {
		return $this->request( 'GET', 'cities/' . (int) $city_id . '/streets', array(), array_filter( array( 'city_id' => (int) $city_id, 'name' => $name, 'page' => 1 ) ) );
	}

	/**
	 * @param int    $city_id City id (0 = all).
	 * @param string $type    'office' | 'locker'.
	 */
	public function get_offices( $city_id, $type = 'office' ) {
		$all = array();

		for ( $page = 1; $page <= 30; $page++ ) {
			$query = array( 'type' => $type, 'page' => $page, 'per_page' => 100 );
			if ( $city_id ) {
				$query['city_id'] = (int) $city_id;
			}
			$raw = $this->request( 'GET', 'offices', array(), $query );

			if ( is_wp_error( $raw ) ) {
				// BGCS-AUDIT-009 — the provider's own message can end up in here,
				// so it goes through the redacting helper like every other log.
				$this->log_provider_error( 'Pigeon', 'get_offices type=' . $type . ' page=' . $page, $raw->get_error_message() );
				return empty( $all ) ? $raw : $all;
			}

			$rows = ( isset( $raw['data'] ) && is_array( $raw['data'] ) ) ? $raw['data'] : array();
			if ( empty( $rows ) ) {
				break;
			}

			$all = array_merge( $all, $rows );

			if ( count( $rows ) < 100 ) {
				break;
			}
		}

		return $all;
	}

	/**
	 * Every office of a type, walking the paginated endpoint (up to a sane cap).
	 *
	 * @param string $type 'office' | 'locker'.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function get_all_offices( $type = 'office' ) {
		$all = array();

		for ( $page = 1; $page <= 30; $page++ ) {
			$raw = $this->request( 'GET', 'offices', array(), array( 'type' => $type, 'page' => $page, 'per_page' => 100 ) );

			if ( is_wp_error( $raw ) ) {
				$this->log_provider_error( 'Pigeon', 'get_all_offices type=' . $type . ' page=' . $page, $raw->get_error_message() );
				return empty( $all ) ? $raw : $all;
			}

			$rows = ( isset( $raw['data'] ) && is_array( $raw['data'] ) ) ? $raw['data'] : array();

			$this->log_provider_debug( 'Pigeon', 'get_all_offices type=' . $type . ' page=' . $page . ' rows=' . count( $rows ) . ' raw_keys=' . implode( ',', array_keys( $raw ) ) );

			if ( empty( $rows ) ) {
				break;
			}

			$all = array_merge( $all, $rows );

			if ( count( $rows ) < 100 ) {
				break; // Short page — no more results.
			}
		}

		if ( 'locker' === $type && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$with_coordinates = 0;
			foreach ( $all as $row ) {
				$lat = isset( $row['latitude'] ) ? $row['latitude'] : ( isset( $row['lat'] ) ? $row['lat'] : null );
				$lng = isset( $row['longitude'] ) ? $row['longitude'] : ( isset( $row['lng'] ) ? $row['lng'] : null );
				if ( is_numeric( $lat ) && is_numeric( $lng ) && 0.0 !== (float) $lat && 0.0 !== (float) $lng ) {
					++$with_coordinates;
				}
			}

			$sample = ( ! empty( $all[0] ) && is_array( $all[0] ) ) ? $all[0] : array();
			$keys   = empty( $sample ) ? '(none)' : implode( ',', array_keys( $sample ) );

			$this->log_provider_debug( 'Pigeon', sprintf( 'raw locker summary: rows=%d with_coordinates=%d without_coordinates=%d keys=%s', count( $all ), $with_coordinates, count( $all ) - $with_coordinates, $keys ) );

			if ( ! empty( $sample ) ) {
				$this->log_provider_debug( 'Pigeon', 'raw locker sample=', $this->debug_safe_location_row( $sample ) );
			}
		}

		return $all;
	}

	/**
	 * Redact possible credentials and contact details from a debug API sample.
	 *
	 * @param array<string,mixed> $row Location row.
	 * @return array<string,mixed>
	 */
	private function debug_safe_location_row( array $row ) {
		$safe = array();

		foreach ( $row as $key => $value ) {
			if ( preg_match( '/(?:api.?key|secret|token|password|phone|email)/i', (string) $key ) ) {
				$safe[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$safe[ $key ] = $this->debug_safe_location_row( $value );
			} else {
				$safe[ $key ] = $value;
			}
		}

		return $safe;
	}

	public function get_services() {
		return $this->request( 'GET', 'additional-services' );
	}

	/* ---- Shipments ---- */

	public function calculate( array $body ) {
		return $this->request( 'POST', 'shipments/calculate', $body );
	}

	public function create_shipment( array $body ) {
		return $this->request( 'POST', 'shipments', $body );
	}

	/**
	 * Paid-out cash-on-delivery amounts for a date range.
	 *
	 * Filtered by the PAYOUT completion date, not the delivery date — the two
	 * are weeks apart, and a reconciliation report is about when the money
	 * arrived. Pigeon require both dates, `to >= from`, and a range no longer
	 * than one calendar month.
	 *
	 * @param string $from Start, `Y-m-d`.
	 * @param string $to   End, `Y-m-d`.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cod_payouts( $from, $to ) {
		return $this->request(
			'GET',
			'payments/completed?' . http_build_query(
				array(
					'from_date' => (string) $from,
					'to_date'   => (string) $to,
				)
			)
		);
	}

	/**
	 * Track up to 100 shipments in one request.
	 *
	 * Always answers HTTP 200 — a reference this account does not own comes back
	 * as `found: false` inside `data`, not as an error. Duplicates are ignored
	 * by Pigeon, but are removed here anyway so the 100 limit counts real work.
	 *
	 * @param string[] $references Reference numbers.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function track_bulk( array $references ) {
		$clean = array();
		foreach ( $references as $reference ) {
			$reference = trim( (string) $reference );
			if ( '' !== $reference ) {
				$clean[ $reference ] = mb_substr( $reference, 0, 50 );
			}
		}

		$clean = array_slice( array_values( $clean ), 0, 100 );

		if ( empty( $clean ) ) {
			return new \WP_Error( 'bgcs3_pigeon_no_references', __( 'No tracking number is available.', 'bg-commerce-suite' ) );
		}

		return $this->request( 'POST', 'shipments/track/bulk', array( 'references' => $clean ) );
	}

	public function track( $reference ) {
		return $this->request( 'GET', 'shipments/' . rawurlencode( (string) $reference ) . '/track' );
	}

	/**
	 * Label PDF binary in a specific format (default | pdf150 | a4).
	 *
	 * @param string $reference Reference number.
	 * @param string $format    Label format.
	 * @return string|\WP_Error PDF bytes.
	 */
	public function get_label_raw( $reference, $format = 'default' ) {
		$url = $this->base_url() . 'shipments/' . rawurlencode( (string) $reference ) . '/label';
		if ( 'default' !== $format ) {
			$url = add_query_arg( 'format', rawurlencode( $format ), $url );
		}

		return $this->request_binary_url(
			'GET',
			$url,
			array(
				'timeout' => $this->timeout,
				'headers' => $this->auth_headers(),
			)
		);
	}

	public function cancel( $reference ) {
		return $this->request( 'POST', 'shipments/' . rawurlencode( (string) $reference ) . '/cancel' );
	}

	/**
	 * Request a courier to collect parcels from the pickup address.
	 *
	 * `shipment_references` attaches already-registered shipments so the courier
	 * can scan them on the spot; two or more turn the request into a groupage.
	 * Pigeon deduplicates identical requests within 30 seconds.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function create_courier_request( array $body ) {
		return $this->request( 'POST', 'courier-requests', $body );
	}

	/**
	 * State of one courier request.
	 *
	 * @param string $number Request number.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_courier_request( $number ) {
		return $this->request( 'GET', 'courier-requests/' . rawurlencode( (string) $number ) );
	}

	/**
	 * Cancel a courier request.
	 *
	 * @param string $number Request number.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cancel_courier_request( $number ) {
		return $this->request( 'POST', 'courier-requests/' . rawurlencode( (string) $number ) . '/cancel' );
	}
}
