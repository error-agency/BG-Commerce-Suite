<?php
/**
 * BoxNow locations provider.
 *
 * Lockers come from the env-aware Location API JSON
 * (locationapi-{env}.boxnow.bg/v1/apms_bg-BG.json) — the source BoxNow's own
 * implementation guide recommends, whose `id` IS the valid delivery-request
 * `destination.locationId`. (We deliberately do NOT use /api/v1/destinations
 * for the picker: on stage it can return ids that delivery-requests rejects
 * with P402.) Sender warehouses come from the authenticated GET /api/v1/origins.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\BoxNow;

use BgCommerce3\Modules\Shipping\Locations_Provider;
use BgCommerce3\Support\Cache;
use BgCommerce3\Shipping\Office_Store;
use BgCommerce3\Shipping\Location_Search;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

class Locations implements Locations_Provider {

	const TTL_LIST = 3 * HOUR_IN_SECONDS; // BoxNow recommends refreshing every 15min–3h.
	const LIMIT    = 200;

	/** @var Client */
	private $client;

	/** @var \WP_Error|null Last error returned while loading sender origins. */
	private $origins_error;

	/**
	 * @param Client $client BoxNow API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Unique cities derived from the locker list.
	 *
	 * @param string $query   Partial city name.
	 * @param string $country ISO-2 country code.
	 * @return array<int,array<string,mixed>>
	 */
	public function cities( $query, $country = 'BG' ) {
		$apms = $this->get_cached_apms();
		if ( empty( $apms ) ) {
			return array();
		}

		$cities = array();
		foreach ( $apms as $apm ) {
			$city = isset( $apm['addressLine2'] ) ? trim( $apm['addressLine2'] ) : '';
			if ( '' === $city ) {
				continue;
			}
			$key = Location_Search::fold( $city );
			if ( ! isset( $cities[ $key ] ) ) {
				$cities[ $key ] = array(
					'id'        => $city,
					'name'      => $city,
					'name_en'   => $city,
					'post_code' => isset( $apm['postalCode'] ) ? (string) $apm['postalCode'] : '',
					'region'    => '',
					'text'      => $city,
				);
			}
		}

		return $this->filter_items( array_values( $cities ), $query );
	}

	/**
	 * Lockers in a city (BoxNow has only lockers).
	 *
	 * @param string $city_id City name.
	 * @param string $type    'locker'.
	 * @return array<int,array<string,mixed>>
	 */
	public function offices( $city_id, $type = 'office' ) {
		if ( 'locker' !== $type ) {
			return array();
		}

		$apms = $this->get_cached_apms();
		if ( empty( $apms ) ) {
			return array();
		}

		$want = mb_strtolower( trim( $city_id ) );
		$out  = array();

		foreach ( $apms as $apm ) {
			$city = isset( $apm['addressLine2'] ) ? trim( $apm['addressLine2'] ) : '';
			if ( '' !== $want && mb_strtolower( $city ) !== $want ) {
				continue;
			}
			$out[] = $this->normalize_apm( $apm );
		}

		return $out;
	}

	public function streets( $city_id, $query ) {
		return array();
	}

	public function quarters( $city_id, $query ) {
		return array();
	}

	/**
	 * Full pool for the Core checkout office-search (no city needed). BoxNow has
	 * only lockers, so 'office' is always empty.
	 *
	 * @param string $type 'office' | 'locker'.
	 * @return array<int,array<string,mixed>>
	 */
	public function all_offices( $type = 'office' ) {
		return ( 'locker' === $type ) ? $this->offices( '', 'locker' ) : array();
	}

	/**
	 * Sender warehouses for the settings dropdown: [ id => label ].
	 * Authenticated /api/v1/origins (env-aware).
	 *
	 * @return array<string,string>
	 */
	public function origins() {
		$this->origins_error = null;
		$list = $this->cached_origins();

		if ( is_wp_error( $list ) ) {
			$this->origins_error = $list;
			return array();
		}

		$options = array();
		foreach ( $list as $origin ) {
			if ( empty( $origin['id'] ) ) {
				continue;
			}
			$name    = isset( $origin['name'] ) ? $origin['name'] : '';
			$address = isset( $origin['addressLine1'] ) ? $origin['addressLine1'] : '';
			$options[ (string) $origin['id'] ] = trim( $name . ( $address ? ' — ' . $address : '' ) );
		}

		return $options;
	}

	/**
	 * Raw authenticated origin record. Used when BOX NOW expects a complete
	 * returnLocation address object rather than an origin id.
	 *
	 * @param string $id Origin id.
	 * @return array<string,mixed>|null
	 */
	public function origin( $id ) {
		$this->origins_error = null;
		$id   = trim( (string) $id );
		$list = $this->cached_origins();

		if ( is_wp_error( $list ) ) {
			$this->origins_error = $list;
			return null;
		}

		foreach ( $list as $origin ) {
			if ( is_array( $origin ) && isset( $origin['id'] ) && $id === (string) $origin['id'] ) {
				return $origin;
			}
		}

		return null;
	}

	/**
	 * Cached /origins response for the active environment/account.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function cached_origins() {
		$env        = (string) Module_Settings::get( BoxNow::ID, 'env' );
		$client_id  = (string) Module_Settings::get( BoxNow::ID, 'client_id' );
		$partner_id = (string) bgcs3_get_option( BoxNow::ID, 'partner_id', '' );

		return Cache::remember(
			Cache::courier_key( BoxNow::ID, 'origins_' . md5( $env . '|' . $client_id . '|' . $partner_id ) ),
			self::TTL_LIST,
			function () {
				$raw = $this->client->get_origins();
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				return empty( $raw ) ? new \WP_Error( 'bgcs3_boxnow_empty_origins', 'empty' ) : $raw;
			}
		);
	}

	/**
	 * Last error encountered while loading account-valid sender origins.
	 *
	 * @return \WP_Error|null
	 */
	public function origins_error() {
		return $this->origins_error;
	}

	/**
	 * Persist the checkout locker pool without erasing valid previous data.
	 *
	 * @return int|\WP_Error
	 */
	public function replace_if_valid() {
		// BOX NOW има само автомати — извикващият код очаква една стойност.
		$pools = Office_Store::replace_pools( BoxNow::ID, $this, array( 'locker' ) );

		return $pools['locker'];
	}

	/**
	 * Normalise a locker (APM) object to our shape.
	 *
	 * @param array<string,mixed> $apm APM object.
	 * @return array<string,mixed>
	 */
	private function normalize_apm( $apm ) {
		$lat = $apm['lat'] ?? ( $apm['latitude'] ?? ( $apm['gps']['lat'] ?? null ) );
		$lng = $apm['lng'] ?? ( $apm['longitude'] ?? ( $apm['gps']['lng'] ?? null ) );
		$lat = ( null !== $lat && '' !== $lat ) ? (float) $lat : null;
		$lng = ( null !== $lng && '' !== $lng ) ? (float) $lng : null;

		$name    = isset( $apm['name'] ) ? trim( $apm['name'] ) : ( isset( $apm['title'] ) ? trim( $apm['title'] ) : '' );
		$address = isset( $apm['addressLine1'] ) ? trim( $apm['addressLine1'] ) : ( isset( $apm['address'] ) ? trim( (string) $apm['address'] ) : '' );

		// City / postcode field names differ between the static JSON and the
		// authenticated destinations endpoint — read either.
		$city = isset( $apm['addressLine2'] ) ? trim( (string) $apm['addressLine2'] )
			: ( isset( $apm['city'] ) ? trim( (string) $apm['city'] ) : ( isset( $apm['region'] ) ? trim( (string) $apm['region'] ) : '' ) );
		$post = isset( $apm['postalCode'] ) ? (string) $apm['postalCode']
			: ( isset( $apm['postcode'] ) ? (string) $apm['postcode'] : ( isset( $apm['zip'] ) ? (string) $apm['zip'] : '' ) );

		$id = isset( $apm['id'] ) ? (string) $apm['id'] : ( isset( $apm['locationId'] ) ? (string) $apm['locationId'] : '' );

		return array(
			'id'        => $id,
			'name'      => $name,
			'name_en'   => $name,
			'address'   => $address,
			'city'      => $city,
			'city_id'   => $city,
			'post_code' => $post,
			'lat'       => $lat,
			'lng'       => $lng,
			'is_locker' => true,
			'text'      => trim( ( $name ? $name : $id ) . ( $address ? ' — ' . $address : '' ) ),
		);
	}

	/**
	 * Cached env-aware locker list from the Location API JSON.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_cached_apms() {
		$env        = (string) Module_Settings::get( BoxNow::ID, 'env' );
		$client_id  = (string) Module_Settings::get( BoxNow::ID, 'client_id' );
		$partner_id = (string) bgcs3_get_option( BoxNow::ID, 'partner_id', '' );

		$list = Cache::remember(
			Cache::courier_key( BoxNow::ID, 'apms_' . md5( $env . '|' . $client_id . '|' . $partner_id ) ),
			self::TTL_LIST,
			function () {
				$raw = $this->client->get_lockboxes();
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				return empty( $raw ) ? new \WP_Error( 'bgcs3_boxnow_empty_apms', 'empty' ) : $raw;
			}
		);

		return is_wp_error( $list ) ? array() : $list;
	}

	/**
	 * Simple name filter, capped to LIMIT.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param string                         $query Query.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_items( array $items, $query ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return array_slice( $items, 0, self::LIMIT );
		}

		$needle = Location_Search::fold( $query );
		$found  = array();
		foreach ( $items as $item ) {
			$name = isset( $item['name'] ) ? Location_Search::fold( $item['name'] ) : '';
			if ( '' !== $name && false !== strpos( $name, $needle ) ) {
				$found[] = $item;
				if ( count( $found ) >= self::LIMIT ) {
					break;
				}
			}
		}
		return $found;
	}
}
