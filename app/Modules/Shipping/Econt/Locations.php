<?php
/**
 * Econt location data provider. Fetches full nomenclature lists once, caches
 * them in transients, then filters in PHP for fast autocomplete. Results are
 * normalised to a common shape consumed by the front-end selector:
 *   city   => { id, text, name, name_en, post_code, region }
 *   office => { id, text, name, name_en, address, post_code, lat, lng, type }
 *   street => { id, text, name, name_en }
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\Econt;

use BgCommerce3\Modules\Shipping\Locations_Provider;
use BgCommerce3\Support\Cache;
use BgCommerce3\Shipping\Office_Store;
use BgCommerce3\Shipping\Location_Search;

defined( 'ABSPATH' ) || exit;

class Locations implements Locations_Provider {

	const TTL_CITIES = WEEK_IN_SECONDS;
	const TTL_LIST   = DAY_IN_SECONDS;
	const LIMIT      = 50;

	/** @var Client */
	private $client;

	/** @var array<string,string> WC/ISO-2 → Econt 3-letter country codes. */
	private $country_map = array(
		'BG' => 'BGR',
		'GR' => 'GRC',
		'RO' => 'ROU',
	);

	/**
	 * @param Client $client Econt API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * @param string $query   Partial city name.
	 * @param string $country ISO-2 country code.
	 * @return array<int,array<string,mixed>>
	 */
	public function cities( $query, $country = 'BG' ) {
		$cc = $this->country_code( $country );

		$all = Cache::remember(
			Cache::courier_key( Econt::ID, 'cities_' . $cc ),
			self::TTL_CITIES,
			function () use ( $cc ) {
				$raw = $this->client->get_cities( $cc );
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$normalized = $this->normalize_cities( $raw );
				// Don't cache an empty/failed parse — let it retry next request.
				return empty( $normalized ) ? new \WP_Error( 'bgcs3_empty_cities', 'empty' ) : $normalized;
			}
		);

		if ( is_wp_error( $all ) ) {
			return array();
		}

		return $this->filter( $all, $query );
	}

	/**
	 * @param string $city_id City id.
	 * @param string $type    'office' | 'locker'.
	 * @return array<int,array<string,mixed>>
	 */
	public function offices( $city_id, $type = 'office' ) {
		$city_id = (string) $city_id;
		if ( '' === $city_id ) {
			return array();
		}

		$all = Cache::remember(
			Cache::courier_key( Econt::ID, 'offices_' . $city_id ),
			self::TTL_LIST,
			function () use ( $city_id ) {
				$raw = $this->client->get_offices( 'BGR', $city_id );
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$normalized = $this->normalize_offices( $raw );
				return empty( $normalized ) ? new \WP_Error( 'bgcs3_empty_offices', 'empty' ) : $normalized;
			}
		);

		if ( is_wp_error( $all ) ) {
			return array();
		}

		$want_locker = ( 'locker' === $type );

		return array_values(
			array_filter(
				$all,
				static function ( $office ) use ( $want_locker, $city_id ) {
					return ! empty( $office['is_locker'] ) === $want_locker && Location_Search::matches_city_id( $office, $city_id );
				}
			)
		);
	}

	/**
	 * @param string $city_id City id.
	 * @param string $query   Partial street name.
	 * @return array<int,array<string,mixed>>
	 */
	public function streets( $city_id, $query ) {
		$city_id = (string) $city_id;
		if ( '' === $city_id ) {
			return array();
		}

		$all = Cache::remember(
			Cache::courier_key( Econt::ID, 'streets_' . $city_id ),
			self::TTL_LIST,
			function () use ( $city_id ) {
				$raw = $this->client->get_streets( $city_id );
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				return $this->normalize_named( isset( $raw['streets'] ) ? $raw['streets'] : array() );
			}
		);

		if ( is_wp_error( $all ) ) {
			return array();
		}

		return $this->filter( $all, $query );
	}

	/**
	 * @param string $city_id City id.
	 * @param string $query   Partial quarter name.
	 * @return array<int,array<string,mixed>>
	 */
	public function quarters( $city_id, $query ) {
		$city_id = (string) $city_id;
		if ( '' === $city_id ) {
			return array();
		}

		$all = Cache::remember(
			Cache::courier_key( Econt::ID, 'quarters_' . $city_id ),
			self::TTL_LIST,
			function () use ( $city_id ) {
				$raw = $this->client->get_quarters( $city_id );
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				return $this->normalize_named( isset( $raw['quarters'] ) ? $raw['quarters'] : array() );
			}
		);

		if ( is_wp_error( $all ) ) {
			return array();
		}

		return $this->filter( $all, $query );
	}

	/**
	 * @param string $country ISO-2 code.
	 * @return string Econt 3-letter code.
	 */
	private function country_code( $country ) {
		$country = strtoupper( (string) $country );
		return isset( $this->country_map[ $country ] ) ? $this->country_map[ $country ] : 'BGR';
	}

	/**
	 * @param array<string,mixed> $raw Econt getCities response.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_cities( $raw ) {
		$cities = isset( $raw['cities'] ) && is_array( $raw['cities'] ) ? $raw['cities'] : array();
		$out    = array();

		foreach ( $cities as $c ) {
			$name      = isset( $c['name'] ) ? $c['name'] : '';
			$name_en   = isset( $c['nameEn'] ) ? $c['nameEn'] : '';
			$post_code = isset( $c['postCode'] ) ? $c['postCode'] : '';
			$region    = isset( $c['regionName'] ) ? $c['regionName'] : '';

			$out[] = array(
				'id'        => isset( $c['id'] ) ? (string) $c['id'] : '',
				'name'      => $name,
				'name_en'   => $name_en,
				'post_code' => $post_code,
				'region'    => $region,
				'text'      => trim( $name . ( $post_code ? ' [' . $post_code . ']' : '' ) . ( $region ? ' — ' . $region : '' ) ),
			);
		}

		return $out;
	}

	/**
	 * Full normalized pool of offices OR APS lockers, cached for a day (refreshed
	 * by sync_data). Used by the Core checkout office-search (no city needed).
	 *
	 * @param string $type 'office' | 'locker'.
	 * @return array<int,array<string,mixed>>
	 */
	public function all_offices( $type = 'office' ) {
		$all = Cache::remember(
			Cache::courier_key( Econt::ID, 'all_office_rows' ),
			DAY_IN_SECONDS,
			function () {
				$raw = $this->client->get_offices( 'BGR', '' );
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$rows = $this->normalize_offices( $raw );
				return empty( $rows ) ? new \WP_Error( 'bgcs3_econt_no_office_rows', 'empty' ) : $rows;
			}
		);

		if ( is_wp_error( $all ) ) {
			return array();
		}

		$want_locker = ( 'locker' === $type );

		return array_values(
			array_filter(
				$all,
				static function ( $office ) use ( $want_locker ) {
					return ! empty( $office['is_locker'] ) === $want_locker;
				}
			)
		);
	}

	/**
	 * All Econt offices (no APS) for the sender-office settings dropdown:
	 * [ code => "name — city (code)" ]. Cached for a day.
	 *
	 * @return array<string,string>
	 */
	public function all_offices_options() {
		$list = Cache::remember(
			Cache::courier_key( Econt::ID, 'all_offices' ),
			DAY_IN_SECONDS,
			function () {
				$raw = $this->client->get_offices( 'BGR', '' );
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$offices = isset( $raw['offices'] ) && is_array( $raw['offices'] ) ? $raw['offices'] : array();
				return empty( $offices ) ? new \WP_Error( 'bgcs3_econt_no_offices', 'empty' ) : $offices;
			}
		);

		if ( is_wp_error( $list ) ) {
			return array();
		}

		$options = array();
		foreach ( $list as $o ) {
			// Sender offices only — APS/MPS machines can't accept drop-off.
			if ( ! empty( $o['isAPS'] ) || ! empty( $o['isMPS'] ) ) {
				continue;
			}
			$code = isset( $o['code'] ) ? (string) $o['code'] : '';
			if ( '' === $code ) {
				continue;
			}
			$name = isset( $o['name'] ) ? (string) $o['name'] : $code;
			$city = isset( $o['address']['city']['name'] ) ? (string) $o['address']['city']['name'] : '';

			$options[ $code ] = trim( $name . ( $city ? ' — ' . $city : '' ) ) . ' (' . $code . ')';
		}

		asort( $options );

		return $options;
	}

	/**
	 * The account's client profiles (client, addresses, cdPayOptions). Cached.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function profile() {
		$data = Cache::remember(
			Cache::courier_key( Econt::ID, 'profile' ),
			DAY_IN_SECONDS,
			function () {
				$raw = $this->client->get_client_profiles();
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$profiles = isset( $raw['profiles'] ) && is_array( $raw['profiles'] ) ? $raw['profiles'] : array();
				return empty( $profiles ) ? new \WP_Error( 'bgcs3_econt_no_profile', 'empty' ) : $profiles;
			}
		);

		return is_wp_error( $data ) ? array() : $data;
	}

	/**
	 * Profiles with errors preserved for synchronization.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function profiles_result() {
		$raw = $this->client->get_client_profiles();
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$profiles = isset( $raw['profiles'] ) && is_array( $raw['profiles'] ) ? $raw['profiles'] : array();
		return empty( $profiles ) ? new \WP_Error( 'bgcs3_econt_no_profile', __( 'The profile did not return customer data.', 'bg-commerce-suite' ) ) : $profiles;
	}

	/**
	 * @return array<string,string>
	 */
	public function profile_options() {
		$options = array();
		foreach ( $this->profile() as $index => $profile ) {
			$client = isset( $profile['client'] ) && is_array( $profile['client'] ) ? $profile['client'] : array();
			$id     = isset( $profile['id'] ) ? (string) $profile['id'] : ( isset( $client['id'] ) ? (string) $client['id'] : (string) $index );
			$name   = isset( $client['name'] ) ? (string) $client['name'] : __( 'Customer profile', 'bg-commerce-suite' );
			$mol    = isset( $client['molName'] ) ? (string) $client['molName'] : '';
			$options[ $id ] = trim( $name . ( $mol ? ' — ' . $mol : '' ) . ' (#' . $id . ')' );
		}
		return $options;
	}

	/**
	 * @param string $profile_id Profile id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function profile_by_id( $profile_id ) {
		$profiles = $this->profiles_result();
		if ( is_wp_error( $profiles ) ) {
			return $profiles;
		}
		foreach ( $profiles as $index => $profile ) {
			$client = isset( $profile['client'] ) && is_array( $profile['client'] ) ? $profile['client'] : array();
			$id     = isset( $profile['id'] ) ? (string) $profile['id'] : ( isset( $client['id'] ) ? (string) $client['id'] : (string) $index );
			if ( (string) $profile_id === $id ) {
				return $profile;
			}
		}
		return new \WP_Error( 'bgcs3_econt_profile_missing', __( 'The selected profile is no longer available.', 'bg-commerce-suite' ) );
	}

	/**
	 * Persist both checkout pools without erasing the previous valid data.
	 *
	 * @return array<string,int|\WP_Error>
	 */
	public function replace_if_valid() {
		return Office_Store::replace_pools( Econt::ID, $this );
	}

	/**
	 * COD pay options / agreements across all profiles: [ num => label ].
	 *
	 * @return array<string,string>
	 */
	public function cd_pay_options( $profile_id = '' ) {
		$options = array();

		foreach ( $this->cd_pay_option_details( $profile_id ) as $num => $option ) {
			$parts = array( (string) $num );
			if ( ! empty( $option['moneyTransfer'] ) ) {
				$parts[] = __( 'Postal money transfer (PPP)', 'bg-commerce-suite' );
			}
			if ( ! empty( $option['express'] ) ) {
				$parts[] = __( 'Express payout', 'bg-commerce-suite' );
			}
			if ( ! empty( $option['method'] ) ) {
				$parts[] = (string) $option['method'];
			}
			if ( ! empty( $option['bankCurrency'] ) ) {
				$parts[] = strtoupper( (string) $option['bankCurrency'] );
			}
			if ( ! empty( $option['_client_name'] ) ) {
				$parts[] = '(' . (string) $option['_client_name'] . ')';
			}
			$options[ (string) $num ] = implode( ' · ', $parts );
		}

		return $options;
	}

	/**
	 * Full account-specific COD payout options keyed by template number.
	 * moneyTransfer=true describes how COD is paid out (PPP); it is NOT
	 * ShippingLabelServices.moneyTransferAmount, which is a different shipment
	 * service/type.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function cd_pay_option_details( $profile_id = '' ) {
		$options = array();
		foreach ( $this->profile() as $profile_index => $profile ) {
			$client = isset( $profile['client'] ) && is_array( $profile['client'] ) ? $profile['client'] : array();
			$pid = isset( $profile['id'] ) ? (string) $profile['id'] : ( isset( $client['id'] ) ? (string) $client['id'] : (string) $profile_index );
			if ( '' !== (string) $profile_id && (string) $profile_id !== $pid ) {
				continue;
			}
			if ( empty( $profile['cdPayOptions'] ) || ! is_array( $profile['cdPayOptions'] ) ) {
				continue;
			}
			$client_name = isset( $client['name'] ) ? (string) $client['name'] : '';
			foreach ( $profile['cdPayOptions'] as $option ) {
				if ( ! is_array( $option ) ) {
					continue;
				}
				$num = isset( $option['num'] ) ? trim( (string) $option['num'] ) : '';
				if ( '' === $num ) {
					continue;
				}
				$option['_client_name'] = $client_name;
				$options[ $num ] = $option;
			}
		}
		return $options;
	}

	/**
	 * Instruction templates returned by ProfileService.getClientProfiles().
	 *
	 * @param string $type take|give|return|services. Empty = all.
	 * @return array<string,string>
	 */
	public function instruction_options( $type = '', $profile_id = '' ) {
		$options = array();
		$type = trim( (string) $type );
		foreach ( $this->profile() as $profile_index => $profile ) {
			$client = isset( $profile['client'] ) && is_array( $profile['client'] ) ? $profile['client'] : array();
			$pid = isset( $profile['id'] ) ? (string) $profile['id'] : ( isset( $client['id'] ) ? (string) $client['id'] : (string) $profile_index );
			if ( '' !== (string) $profile_id && (string) $profile_id !== $pid ) {
				continue;
			}
			if ( empty( $profile['instructionTemplates'] ) || ! is_array( $profile['instructionTemplates'] ) ) {
				continue;
			}
			foreach ( $profile['instructionTemplates'] as $instruction ) {
				if ( ! is_array( $instruction ) ) {
					continue;
				}
				$instruction_type = isset( $instruction['type'] ) ? trim( (string) $instruction['type'] ) : '';
				if ( '' !== $type && $type !== $instruction_type ) {
					continue;
				}
				$id = isset( $instruction['id'] ) ? (string) $instruction['id'] : '';
				if ( '' === $id ) {
					continue;
				}
				$title = isset( $instruction['title'] ) ? trim( (string) $instruction['title'] ) : '';
				if ( '' === $title && isset( $instruction['name'] ) ) {
					$title = trim( (string) $instruction['name'] );
				}
				$options[ $id ] = $id . ( '' !== $title ? ' · ' . $title : '' );
			}
		}
		return $options;
	}

	/**
	 * Sender addresses exposed by getClientProfiles(), keyed by profile/address.
	 *
	 * @param string $profile_id Selected profile id; empty = all.
	 * @return array<string,string>
	 */
	public function sender_address_options( $profile_id = '' ) {
		$options = array();
		foreach ( $this->profile() as $profile_index => $profile ) {
			$client = isset( $profile['client'] ) && is_array( $profile['client'] ) ? $profile['client'] : array();
			$pid = isset( $profile['id'] ) ? (string) $profile['id'] : ( isset( $client['id'] ) ? (string) $client['id'] : (string) $profile_index );
			if ( '' !== (string) $profile_id && (string) $profile_id !== $pid ) {
				continue;
			}
			$client_name = isset( $client['name'] ) ? (string) $client['name'] : '';
			foreach ( (array) ( isset( $profile['addresses'] ) ? $profile['addresses'] : array() ) as $address_index => $address ) {
				if ( ! is_array( $address ) ) {
					continue;
				}
				$aid = isset( $address['id'] ) && '' !== (string) $address['id'] ? (string) $address['id'] : 'i' . (string) $address_index;
				$key = $pid . ':' . $aid;
				$full = isset( $address['fullAddress'] ) ? trim( (string) $address['fullAddress'] ) : '';
				if ( '' === $full ) {
					$city = isset( $address['city']['name'] ) ? (string) $address['city']['name'] : '';
					$street = isset( $address['street'] ) ? (string) $address['street'] : '';
					$num = isset( $address['num'] ) ? (string) $address['num'] : '';
					$other = isset( $address['other'] ) ? (string) $address['other'] : '';
					$full = trim( implode( ' ', array_filter( array( $city, $street, $num, $other ) ) ) );
				}
				$options[ $key ] = trim( $full . ( $client_name ? ' (' . $client_name . ')' : '' ) );
			}
		}
		return $options;
	}

	/**
	 * Resolve a sender address selector back to the official Econt Address object.
	 *
	 * @param string $key Selector from sender_address_options().
	 * @return array<string,mixed>|\WP_Error
	 */
	public function sender_address_by_key( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key || false === strpos( $key, ':' ) ) {
			return new \WP_Error( 'bgcs3_econt_sender_address_missing', __( 'Select an Econt sender address.', 'bg-commerce-suite' ) );
		}
		list( $profile_id, $address_id ) = explode( ':', $key, 2 );
		foreach ( $this->profile() as $profile_index => $profile ) {
			$client = isset( $profile['client'] ) && is_array( $profile['client'] ) ? $profile['client'] : array();
			$pid = isset( $profile['id'] ) ? (string) $profile['id'] : ( isset( $client['id'] ) ? (string) $client['id'] : (string) $profile_index );
			if ( $pid !== $profile_id ) {
				continue;
			}
			foreach ( (array) ( isset( $profile['addresses'] ) ? $profile['addresses'] : array() ) as $address_index => $address ) {
				if ( ! is_array( $address ) ) {
					continue;
				}
				$aid = isset( $address['id'] ) && '' !== (string) $address['id'] ? (string) $address['id'] : 'i' . (string) $address_index;
				if ( $aid === $address_id ) {
					return $address;
				}
			}
		}
		return new \WP_Error( 'bgcs3_econt_sender_address_stale', __( 'The selected Econt sender address is no longer available. Synchronize Econt data and select it again.', 'bg-commerce-suite' ) );
	}

	/**
	 * @param array<string,mixed> $raw Econt getOffices response.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_offices( $raw ) {
		$offices = isset( $raw['offices'] ) && is_array( $raw['offices'] ) ? $raw['offices'] : array();
		$out     = array();

		foreach ( $offices as $o ) {
			$address  = isset( $o['address']['fullAddress'] ) ? $o['address']['fullAddress'] : '';
			$loc      = isset( $o['address']['location'] ) && is_array( $o['address']['location'] ) ? $o['address']['location'] : array();

			// Coordinates can live in a few shapes depending on the API version.
			$lat = $loc['latitude'] ?? ( $o['address']['latitude'] ?? ( $o['latitude'] ?? ( $loc['y'] ?? null ) ) );
			$lng = $loc['longitude'] ?? ( $o['address']['longitude'] ?? ( $o['longitude'] ?? ( $loc['x'] ?? null ) ) );
			$lat = ( null !== $lat && '' !== $lat ) ? (float) $lat : null;
			$lng = ( null !== $lng && '' !== $lng ) ? (float) $lng : null;

			$name     = isset( $o['name'] ) ? $o['name'] : '';
			$name_en  = isset( $o['nameEn'] ) ? $o['nameEn'] : '';

			// Office.isAPS / Office.isMPS are the provider's explicit automated-
			// station flags. Do not infer locker semantics from the office name.
			$is_locker = ! empty( $o['isMPS'] ) || ! empty( $o['isAPS'] );
			$shipment_types = array_values( array_filter( array_map( 'strval', isset( $o['shipmentTypes'] ) && is_array( $o['shipmentTypes'] ) ? $o['shipmentTypes'] : array() ) ) );

			$out[] = array(
				'id'        => isset( $o['code'] ) ? (string) $o['code'] : ( isset( $o['id'] ) ? (string) $o['id'] : '' ),
				'name'      => $name,
				'name_en'   => $name_en,
				'address'   => $address,
				'city'      => isset( $o['address']['city']['name'] ) ? (string) $o['address']['city']['name'] : '',
				'city_id'   => isset( $o['address']['city']['id'] ) ? (string) $o['address']['city']['id'] : '',
				'post_code' => isset( $o['address']['zip'] ) ? $o['address']['zip'] : '',
				'lat'       => $lat,
				'lng'       => $lng,
				'is_locker'      => (bool) $is_locker,
				'is_drive'       => ! empty( $o['isDrive'] ),
				'shipment_types' => $shipment_types,
				'text'           => trim( $name . ( $address ? ' — ' . $address : '' ) ),
			);
		}

		return $out;
	}

	/**
	 * Normalise a generic { id, name, nameEn } list (streets/quarters).
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_named( $items ) {
		$out = array();

		foreach ( (array) $items as $i ) {
			$name    = isset( $i['name'] ) ? $i['name'] : '';
			$name_en = isset( $i['nameEn'] ) ? $i['nameEn'] : '';

			$out[] = array(
				'id'      => isset( $i['id'] ) ? (string) $i['id'] : '',
				'name'    => $name,
				'name_en' => $name_en,
				'text'    => $name,
			);
		}

		return $out;
	}

	/**
	 * Case-insensitive substring filter on name / name_en, capped to LIMIT.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param string                         $query Query.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter( $items, $query ) {
		$query = trim( (string) $query );

		if ( '' === $query ) {
			return array_slice( $items, 0, self::LIMIT );
		}

		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
		$found  = array();

		foreach ( $items as $item ) {
			$haystacks = array( $item['name'], isset( $item['name_en'] ) ? $item['name_en'] : '' );

			foreach ( $haystacks as $haystack ) {
				$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );
				if ( '' !== $haystack && false !== strpos( $haystack, $needle ) ) {
					$found[] = $item;
					break;
				}
			}

			if ( count( $found ) >= self::LIMIT ) {
				break;
			}
		}

		return $found;
	}
}
