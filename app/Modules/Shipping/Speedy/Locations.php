<?php
/**
 * Speedy location data provider (live /location/* endpoints, transient-cached).
 * Cities → /location/site, offices/APT → /location/office, streets → /location/street.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\Speedy;

use BgCommerce3\Modules\Shipping\Locations_Provider;
use BgCommerce3\Support\Cache;
use BgCommerce3\Shipping\Office_Store;
use BgCommerce3\Shipping\Location_Search;

defined( 'ABSPATH' ) || exit;

class Locations implements Locations_Provider {

	const TTL = HOUR_IN_SECONDS;

	/** @var Client */
	private $client;

	/**
	 * @param Client $client Speedy API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * @param string $query   Partial city name (min 2 chars enforced upstream).
	 * @param string $country ISO-2 country code (only BG supported for now).
	 * @return array<int,array<string,mixed>>
	 */
	public function cities( $query, $country = 'BG' ) {
		$query = trim( (string) $query );
		if ( bgcs3_strlen( $query ) < 2 ) {
			return array();
		}

		$result = Cache::remember(
			Cache::courier_key( Speedy::ID, 'sites_' . md5( bgcs3_strtolower( $query ) ) ),
			self::TTL,
			function () use ( $query ) {
				$raw = $this->client->find_sites( $query );
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$sites = isset( $raw['sites'] ) && is_array( $raw['sites'] ) ? $raw['sites'] : array();
				$out   = array();
				foreach ( $sites as $s ) {
					$name = isset( $s['name'] ) ? $s['name'] : '';
					$type = isset( $s['type'] ) ? $s['type'] : '';
					$pc   = isset( $s['postCode'] ) ? (string) $s['postCode'] : '';
					$out[] = array(
						'id'        => isset( $s['id'] ) ? (string) $s['id'] : '',
						'name'      => $name,
						'name_en'   => $name,
						'post_code' => $pc,
						'region'    => isset( $s['region'] ) ? $s['region'] : '',
						'text'      => trim( ( $type ? $type . ' ' : '' ) . $name . ( $pc ? ' [' . $pc . ']' : '' ) . ( ! empty( $s['region'] ) ? ' — ' . $s['region'] : '' ) ),
					);
				}
				return empty( $out ) ? new \WP_Error( 'bgcs3_speedy_empty_sites', 'empty' ) : $out;
			}
		);

		return is_wp_error( $result ) ? array() : $result;
	}

	/**
	 * @param string $city_id Speedy site id.
	 * @param string $type    'office' | 'locker'.
	 * @return array<int,array<string,mixed>>
	 */
	public function offices( $city_id, $type = 'office' ) {
		$city_id = (string) $city_id;
		if ( '' === $city_id ) {
			return array();
		}

		$all = Cache::remember(
			Cache::courier_key( Speedy::ID, 'offices_' . $city_id ),
			self::TTL,
			function () use ( $city_id ) {
				$raw = $this->client->find_offices( $city_id );
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$offices = isset( $raw['offices'] ) && is_array( $raw['offices'] ) ? $raw['offices'] : array();
				return $this->normalize_offices( $offices );
			}
		);

		if ( is_wp_error( $all ) ) {
			return array();
		}

		$want_locker = ( 'locker' === $type );

		return array_values(
			array_filter(
				$all,
				static function ( $o ) use ( $want_locker, $city_id ) {
					return ! empty( $o['is_locker'] ) === $want_locker && Location_Search::matches_city_id( $o, $city_id );
				}
			)
		);
	}

	/**
	 * @param string $city_id Site id.
	 * @param string $query   Partial street name.
	 * @return array<int,array<string,mixed>>
	 */
	public function streets( $city_id, $query ) {
		$city_id = (string) $city_id;
		if ( '' === $city_id || bgcs3_strlen( trim( (string) $query ) ) < 2 ) {
			return array();
		}

		$raw = $this->client->find_streets( $city_id, $query );
		if ( is_wp_error( $raw ) ) {
			return array();
		}

		$streets = isset( $raw['streets'] ) && is_array( $raw['streets'] ) ? $raw['streets'] : array();
		$out     = array();
		foreach ( $streets as $s ) {
			$name = isset( $s['name'] ) ? $s['name'] : '';
			$out[] = array(
				'id'      => isset( $s['id'] ) ? (string) $s['id'] : '',
				'name'    => $name,
				'name_en' => $name,
				'text'    => trim( ( isset( $s['type'] ) ? $s['type'] . ' ' : '' ) . $name ),
			);
		}
		return $out;
	}

	public function quarters( $city_id, $query ) {
		return array();
	}

	/**
	 * Available Speedy services for the settings dropdown: [ id => name ].
	 *
	 * @return array<string,string>
	 */
	public function services() {
		$list = Cache::remember(
			Cache::courier_key( Speedy::ID, 'services' ),
			DAY_IN_SECONDS,
			function () {
				$raw = $this->client->get_services();
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$services = isset( $raw['services'] ) && is_array( $raw['services'] ) ? $raw['services'] : array();
				return empty( $services ) ? new \WP_Error( 'bgcs3_speedy_no_services', 'empty' ) : $services;
			}
		);

		return is_wp_error( $list ) ? array() : $this->service_options( $list );
	}

	/**
	 * Cached-only service list for screen rendering. Never performs HTTP.
	 *
	 * @return array<string,string>
	 */
	public function cached_services() {
		$list = Cache::get( Cache::courier_key( Speedy::ID, 'services' ), array() );
		return is_array( $list ) ? $this->service_options( $list ) : array();
	}

	/** @param array<int,array<string,mixed>> $list Raw cached services. */
	private function service_options( array $list ) {
		$options = array();
		foreach ( $list as $s ) {
			if ( empty( $s['id'] ) ) {
				continue;
			}
			$name = isset( $s['name'] ) ? $s['name'] : ( isset( $s['nameEn'] ) ? $s['nameEn'] : $s['id'] );
			$options[ (string) $s['id'] ] = $name . ' (#' . $s['id'] . ')';
		}
		return $options;
	}

	/**
	 * Contract clients (the merchant's Speedy agreements) for the sender
	 * dropdown: [ clientId => label ]. Auto-fetched from /client/contract.
	 *
	 * @return array<string,string>
	 */
	public function contracts() {
		$list = Cache::remember(
			Cache::courier_key( Speedy::ID, 'contracts' ),
			DAY_IN_SECONDS,
			function () {
				$raw = $this->client->get_contract_clients();
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$clients = isset( $raw['clients'] ) && is_array( $raw['clients'] ) ? $raw['clients'] : array();
				return empty( $clients ) ? new \WP_Error( 'bgcs3_speedy_no_contracts', 'empty' ) : $clients;
			}
		);

		return is_wp_error( $list ) ? array() : $this->contract_options( $list );
	}

	/**
	 * Persist contract clients already returned by a successful connection check.
	 *
	 * @param array<int,array<string,mixed>> $clients Raw contract clients.
	 * @return array<string,string>
	 */
	public function cache_contract_clients( array $clients ) {
		$key = Cache::courier_key( Speedy::ID, 'contracts' );
		Cache::forget( $key );
		$list = Cache::remember(
			$key,
			DAY_IN_SECONDS,
			static function () use ( $clients ) {
				return $clients;
			}
		);

		return is_array( $list ) ? $this->contract_options( $list ) : array();
	}

	/** Force a fresh contract-client request for explicit merchant validation. */
	public function refresh_contracts() {
		Cache::forget( Cache::courier_key( Speedy::ID, 'contracts' ) );
		return $this->contracts();
	}

	/** Cached-only contract clients for screen rendering. Never performs HTTP. */
	public function cached_contracts() {
		$list = Cache::get( Cache::courier_key( Speedy::ID, 'contracts' ), array() );
		return is_array( $list ) ? $this->contract_options( $list ) : array();
	}

	/** @param array<int,array<string,mixed>> $list Raw cached contract clients. */
	private function contract_options( array $list ) {
		$options = array();
		foreach ( $list as $c ) {
			$id = isset( $c['clientId'] ) ? $c['clientId'] : ( isset( $c['id'] ) ? $c['id'] : '' );
			if ( '' === (string) $id ) {
				continue;
			}
			$name    = isset( $c['clientName'] ) ? $c['clientName'] : '';
			$object  = isset( $c['objectName'] ) ? $c['objectName'] : '';
			$contact = isset( $c['contactName'] ) ? $c['contactName'] : '';
			$email   = isset( $c['email'] ) ? $c['email'] : '';
			$address = isset( $c['address'] ) ? $this->format_address( $c['address'] ) : '';
			$label = '#' . $id
				. ( $name ? ' · ' . $name : '' )
				. ( $object ? ' · ' . $object : '' )
				. ( $address ? ' — ' . $address : '' )
				. ( $contact ? ' (' . $contact . ')' : '' )
				. ( $email ? ' · ' . $email : '' );
			$options[ (string) $id ] = $label;
		}
		return $options;
	}

	/**
	 * Render a Speedy address (string or object) as a short readable line.
	 *
	 * @param mixed $address Address string or array.
	 * @return string
	 */
	private function format_address( $address ) {
		if ( is_string( $address ) ) {
			return trim( $address );
		}
		if ( ! is_array( $address ) ) {
			return '';
		}
		if ( ! empty( $address['fullAddressString'] ) ) {
			return (string) $address['fullAddressString'];
		}

		$parts  = array();
		if ( ! empty( $address['siteName'] ) ) {
			$parts[] = $address['siteName'];
		}
		$street = trim( ( isset( $address['streetName'] ) ? $address['streetName'] : '' ) . ' ' . ( isset( $address['streetNo'] ) ? $address['streetNo'] : '' ) );
		if ( '' !== $street ) {
			$parts[] = $street;
		}

		return implode( ', ', $parts );
	}

	/**
	 * All Speedy offices (no APT) for the sender dropoff-office dropdown:
	 * [ id => "name — city (#id)" ]. Cached for a day.
	 *
	 * @return array<string,string>
	 */
	public function all_offices_options() {
		$list = Cache::remember(
			Cache::courier_key( Speedy::ID, 'all_offices' ),
			DAY_IN_SECONDS,
			function () {
				$raw = $this->client->all_offices();
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$offices = isset( $raw['offices'] ) && is_array( $raw['offices'] ) ? $raw['offices'] : array();
				return empty( $offices ) ? new \WP_Error( 'bgcs3_speedy_no_offices', 'empty' ) : $offices;
			}
		);

		return is_wp_error( $list ) ? array() : $this->office_options( $list );
	}

	/** Force a fresh sender-office request for explicit merchant validation. */
	public function refresh_all_offices_options() {
		Cache::forget( Cache::courier_key( Speedy::ID, 'all_offices' ) );
		return $this->all_offices_options();
	}

	/** Cached-only sending-office list for screen rendering. Never performs HTTP. */
	public function cached_all_offices_options() {
		$list = Cache::get( Cache::courier_key( Speedy::ID, 'all_offices' ), array() );
		return is_array( $list ) ? $this->office_options( $list ) : array();
	}

	/** @param array<int,array<string,mixed>> $list Raw cached offices. */
	private function office_options( array $list ) {
		$options = array();
		foreach ( $list as $o ) {
			$id = isset( $o['id'] ) ? (string) $o['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$type = isset( $o['type'] ) ? strtoupper( (string) $o['type'] ) : '';
			if ( 'APT' === $type || empty( $o['dropOffAllowed'] ) ) {
				continue;
			}
			$name    = isset( $o['name'] ) ? $o['name'] : $id;
			$address = isset( $o['address'] ) ? $this->format_address( $o['address'] ) : '';
			$options[ $id ] = trim( $name . ( $address ? ' — ' . $address : '' ) ) . ' (#' . $id . ')';
		}
		asort( $options );
		return $options;
	}

	/**
	 * Full normalized pool of offices OR lockers, cached for a day (refreshed by
	 * sync_data). Used by the Core checkout office-search (no city needed).
	 *
	 * @param string $type 'office' | 'locker'.
	 * @return array<int,array<string,mixed>>
	 */
	public function all_offices( $type = 'office' ) {
		$all = Cache::remember(
			Cache::courier_key( Speedy::ID, 'all_offices_rows' ),
			DAY_IN_SECONDS,
			function () {
				$raw = $this->client->all_offices();
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$offices = isset( $raw['offices'] ) && is_array( $raw['offices'] ) ? $raw['offices'] : array();
				$rows    = $this->normalize_offices( $offices );
				return empty( $rows ) ? new \WP_Error( 'bgcs3_speedy_no_office_rows', 'empty' ) : $rows;
			}
		);

		if ( is_wp_error( $all ) ) {
			return array();
		}

		$want_locker = ( 'locker' === $type );

		return array_values(
			array_filter(
				$all,
				static function ( $o ) use ( $want_locker ) {
					return ! empty( $o['is_locker'] ) === $want_locker;
				}
			)
		);
	}

	/**
	 * @return array<string,int|\WP_Error>
	 */
	public function replace_if_valid() {
		return Office_Store::replace_pools( Speedy::ID, $this );
	}

	/**
	 * @param array<int,array<string,mixed>> $offices Speedy offices[].
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_offices( $offices ) {
		$out = array();
		foreach ( $offices as $o ) {
			$name    = isset( $o['name'] ) ? $o['name'] : '';
			$address = '';
			if ( isset( $o['address']['fullAddressString'] ) ) {
				$address = $o['address']['fullAddressString'];
			} elseif ( isset( $o['address']['streetName'] ) ) {
				$address = trim( ( $o['address']['streetName'] ?? '' ) . ' ' . ( $o['address']['streetNo'] ?? '' ) );
			}

			// Speedy: APT (automated parcel terminal/locker) vs OFFICE.
			$type      = isset( $o['type'] ) ? strtoupper( (string) $o['type'] ) : '';
			// Official Speedy semantics: Office.type is authoritative (OFFICE|APT).
			// Never infer locker identity from the human-readable office name.
			$is_locker = ( 'APT' === $type );

			$lat = isset( $o['address']['y'] ) ? (float) $o['address']['y'] : ( isset( $o['y'] ) ? (float) $o['y'] : null );
			$lng = isset( $o['address']['x'] ) ? (float) $o['address']['x'] : ( isset( $o['x'] ) ? (float) $o['x'] : null );

			$out[] = array(
				'id'        => isset( $o['id'] ) ? (string) $o['id'] : '',
				'name'      => $name,
				'name_en'   => $name,
				'address'   => $address,
				'city'      => isset( $o['address']['siteName'] ) ? (string) $o['address']['siteName'] : '',
				'city_id'   => isset( $o['address']['siteId'] ) ? (string) $o['address']['siteId'] : ( isset( $o['siteId'] ) ? (string) $o['siteId'] : '' ),
				'post_code' => isset( $o['address']['postCode'] ) ? (string) $o['address']['postCode'] : '',
				'lat'       => $lat,
				'lng'       => $lng,
				'is_locker' => $is_locker,
				'text'      => trim( $name . ( $address ? ' — ' . $address : '' ) ),
			);
		}
		return $out;
	}
}
