<?php
/**
 * Pigeon Express location provider (live /cities, /offices, /services;
 * transient-cached). Offices type=office, lockers type=locker (our "locker").
 *
 * @package BgCommerce3\Pigeon
 */

namespace BgCommerce3\Modules\Shipping\Pigeon;

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
	 * @param Client $client API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * @param string $query   Partial city name.
	 * @param string $country ISO-2 (only BG).
	 * @return array<int,array<string,mixed>>
	 */
	public function cities( $query, $country = 'BG' ) {
		$query = trim( (string) $query );
		if ( mb_strlen( $query ) < 2 ) {
			return array();
		}

		$result = Cache::remember(
			Cache::courier_key( Pigeon::ID, 'cities_' . md5( mb_strtolower( $query ) ) ),
			self::TTL,
			function () use ( $query ) {
				$raw = $this->client->get_cities( $query );
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				if ( empty( $this->rows( $raw ) ) ) {
					$cyrillic = Location_Search::latin_to_cyrillic( $query );
					if ( '' !== $cyrillic && $cyrillic !== $query ) {
						$fallback = $this->client->get_cities( $cyrillic );
						if ( ! is_wp_error( $fallback ) ) {
							$raw = $fallback;
						}
					}
				}
				$out = array();
				foreach ( $this->rows( $raw ) as $c ) {
					$id = isset( $c['id'] ) ? (string) $c['id'] : '';
					if ( '' === $id ) {
						continue;
					}
					$name = isset( $c['name'] ) ? $c['name'] : '';
					$pc   = isset( $c['postal_code'] ) ? (string) $c['postal_code'] : ( isset( $c['postcode'] ) ? (string) $c['postcode'] : ( isset( $c['post_code'] ) ? (string) $c['post_code'] : '' ) );
					$out[] = array(
						'id'        => $id,
						'name'      => $name,
						'name_en'   => $name,
						'post_code' => $pc,
						'text'      => trim( $name . ( $pc ? ' [' . $pc . ']' : '' ) ),
					);
				}
				return empty( $out ) ? new \WP_Error( 'bgcs3_pigeon_empty_cities', 'empty' ) : $out;
			}
		);

		return is_wp_error( $result ) ? array() : $result;
	}

	/**
	 * City search that preserves API errors for admin controls.
	 *
	 * @param string $query Query.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function search_cities( $query ) {
		$query = sanitize_text_field( $query );
		$raw = $this->client->get_cities( $query );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		if ( empty( $this->rows( $raw ) ) ) {
			$cyrillic = Location_Search::latin_to_cyrillic( $query );
			if ( '' !== $cyrillic && $cyrillic !== $query ) {
				$fallback = $this->client->get_cities( $cyrillic );
				if ( ! is_wp_error( $fallback ) ) {
					$raw = $fallback;
				}
			}
		}
		$out = array();
		foreach ( $this->rows( $raw ) as $city ) {
			if ( empty( $city['id'] ) ) {
				continue;
			}
			$name = isset( $city['name'] ) ? (string) $city['name'] : '';
			$post = isset( $city['postal_code'] ) ? (string) $city['postal_code'] : ( isset( $city['postcode'] ) ? (string) $city['postcode'] : '' );
			$out[] = array( 'id' => (string) $city['id'], 'label' => trim( $name . ( $post ? ' [' . $post . ']' : '' ) ) );
		}
		return empty( $out ) ? new \WP_Error( 'bgcs3_pigeon_empty_cities', __( 'No cities found.', 'bg-commerce-suite' ), array( 'status' => 503 ) ) : $out;
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

		$api_type = ( 'locker' === $type ) ? 'locker' : 'office';

		$all = Cache::remember(
			Cache::courier_key( Pigeon::ID, 'offices_' . $api_type . '_' . $city_id ),
			self::TTL,
			function () use ( $city_id, $api_type ) {
				$raw = $this->client->get_offices( (int) $city_id, $api_type );
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				return $this->normalize_offices( $this->rows( $raw ), $api_type );
			}
		);

		if ( is_wp_error( $all ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$all,
				static function ( $office ) use ( $city_id ) {
					return Location_Search::matches_city_id( $office, $city_id );
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
		$query   = trim( (string) $query );
		if ( '' === $city_id || mb_strlen( $query ) < 2 ) {
			return array();
		}

		$raw = $this->client->get_streets( (int) $city_id, $query );
		if ( is_wp_error( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $this->rows( $raw ) as $s ) {
			$id = isset( $s['id'] ) ? (string) $s['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$name = isset( $s['name'] ) ? $s['name'] : '';
			$out[] = array(
				'id'      => $id,
				'name'    => $name,
				'name_en' => $name,
				'text'    => $name,
			);
		}
		return $out;
	}

	/**
	 * @param string $city_id City id.
	 * @param string $query   Query.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function search_streets( $city_id, $query ) {
		$raw = $this->client->get_streets( absint( $city_id ), sanitize_text_field( $query ) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$out = array();
		foreach ( $this->rows( $raw ) as $street ) {
			if ( empty( $street['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (string) $street['id'],
				'label' => isset( $street['name'] ) ? (string) $street['name'] : (string) $street['id'],
			);
		}
		return empty( $out ) ? new \WP_Error( 'bgcs3_pigeon_empty_streets', __( 'No streets found.', 'bg-commerce-suite' ), array( 'status' => 503 ) ) : $out;
	}

	public function quarters( $city_id, $query ) {
		return array();
	}

	/**
	 * Best-effort street id for an address delivery (Pigeon wants a street_id).
	 *
	 * @param string $city_id City id.
	 * @param string $name    Street name.
	 * @return int 0 when not resolved.
	 */
	public function resolve_street_id( $city_id, $name ) {
		foreach ( $this->streets( $city_id, $name ) as $street ) {
			if ( ! empty( $street['id'] ) ) {
				return (int) $street['id'];
			}
		}
		return 0;
	}

	/**
	 * Full additional-service contract returned by Pigeon.
	 *
	 * The provider does not expose every service as a boolean. `input_type=text`
	 * expects a numeric/string value and `input_type=select` values sharing an
	 * `option_group` are mutually exclusive. Keeping this metadata is therefore
	 * required for a correct shipment payload; reducing the response to labels
	 * (the historical behaviour) makes text/select services impossible to send
	 * safely.
	 *
	 * @return array<string,array<string,string>> code => normalized definition.
	 */
	public function service_definitions() {
		$list = Cache::remember(
			Cache::courier_key( Pigeon::ID, 'services' ),
			DAY_IN_SECONDS,
			function () {
				$raw = $this->client->get_services();
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$rows = $this->rows( $raw );
				return empty( $rows ) ? new \WP_Error( 'bgcs3_pigeon_no_services', 'empty' ) : $rows;
			}
		);

		if ( is_wp_error( $list ) ) {
			return array();
		}

		$out = array();
		foreach ( $list as $key => $row ) {
			if ( ! is_array( $row ) ) {
				$code = (string) $key;
				if ( '' !== $code ) {
					$out[ $code ] = array(
						'code'         => $code,
						'label'        => (string) $row,
						'description'  => '',
						'input_type'   => 'checkbox',
						'option_group' => '',
					);
				}
				continue;
			}

			$code = isset( $row['code'] ) ? trim( (string) $row['code'] ) : trim( (string) $key );
			if ( '' === $code ) {
				continue;
			}
			$input_type = isset( $row['input_type'] ) ? strtolower( trim( (string) $row['input_type'] ) ) : 'checkbox';
			if ( ! in_array( $input_type, array( 'checkbox', 'text', 'select' ), true ) ) {
				$input_type = 'checkbox';
			}

			$out[ $code ] = array(
				'code'         => $code,
				'label'        => isset( $row['name'] ) ? (string) $row['name'] : ( isset( $row['label'] ) ? (string) $row['label'] : $code ),
				'description'  => isset( $row['description'] ) && is_scalar( $row['description'] ) ? (string) $row['description'] : '',
				'input_type'   => $input_type,
				'option_group' => isset( $row['option_group'] ) && is_scalar( $row['option_group'] ) ? trim( (string) $row['option_group'] ) : '',
			);
		}

		return $out;
	}

	/**
	 * Active service codes for legacy callers/settings labels: [ code => label ].
	 *
	 * @return array<string,string>
	 */
	public function services() {
		$out = array();
		foreach ( $this->service_definitions() as $code => $definition ) {
			$out[ $code ] = isset( $definition['label'] ) ? (string) $definition['label'] : (string) $code;
		}
		return $out;
	}

	/**
	 * Full normalized pool of offices OR lockers, cached for a day (refreshed by
	 * sync_data). Used by the Core checkout office-search (no city needed).
	 *
	 * @param string $type 'office' | 'locker'.
	 * @return array<int,array<string,mixed>>
	 */
	public function all_offices( $type = 'office' ) {
		$api_type = ( 'locker' === $type ) ? 'locker' : 'office';

		$rows = Cache::remember(
			Cache::courier_key( Pigeon::ID, 'all_office_rows_' . $api_type ),
			DAY_IN_SECONDS,
			function () use ( $api_type ) {
				$raw = $this->client->get_all_offices( $api_type );
				if ( is_wp_error( $raw ) ) {
					return $raw;
				}
				$norm = $this->normalize_offices( $raw, $api_type );
				return empty( $norm ) ? new \WP_Error( 'bgcs3_pigeon_no_office_rows', 'empty' ) : $norm;
			}
		);

		return is_wp_error( $rows ) ? array() : $rows;
	}

	/**
	 * @return array<string,int|\WP_Error>
	 */
	public function replace_if_valid() {
		return Office_Store::replace_pools( Pigeon::ID, $this );
	}

	/**
	 * All Pigeon offices (no lockers) for the sender-office settings dropdown:
	 * [ id => "name — city (#id)" ]. Cached for a day.
	 *
	 * @return array<string,string>
	 */
	public function all_offices_options() {
		$list = Cache::remember(
			Cache::courier_key( Pigeon::ID, 'all_offices' ),
			DAY_IN_SECONDS,
			function () {
				$rows = $this->client->get_all_offices( 'office' );
				if ( is_wp_error( $rows ) ) {
					return $rows;
				}
				return empty( $rows ) ? new \WP_Error( 'bgcs3_pigeon_no_offices', 'empty' ) : $rows;
			}
		);

		if ( is_wp_error( $list ) ) {
			return array();
		}

		$options = array();
		foreach ( $list as $o ) {
			$id = isset( $o['id'] ) ? (string) $o['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$name = isset( $o['name'] ) ? (string) $o['name'] : $id;
			$city = isset( $o['city']['name'] ) ? (string) $o['city']['name'] : '';

			$options[ $id ] = trim( $name . ( $city ? ' — ' . $city : '' ) ) . ' (#' . $id . ')';
		}

		asort( $options );

		return $options;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows     Office rows.
	 * @param string                         $api_type office|locker.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_offices( $rows, $api_type ) {
		$is_locker = ( 'locker' === $api_type );
		$out       = array();

		foreach ( $rows as $o ) {
			// delivery_office_id is the office's external id.
			$id = isset( $o['external_id'] ) ? (string) $o['external_id'] : ( isset( $o['id'] ) ? (string) $o['id'] : '' );
			if ( '' === $id ) {
				continue;
			}
			$name    = isset( $o['name'] ) ? $o['name'] : '';
			$address = isset( $o['address'] ) ? $o['address'] : '';
			$city    = isset( $o['city']['name'] ) ? (string) $o['city']['name'] : ( isset( $o['city'] ) && is_string( $o['city'] ) ? (string) $o['city'] : '' );
			$lat     = isset( $o['latitude'] ) ? (float) $o['latitude'] : ( isset( $o['lat'] ) ? (float) $o['lat'] : null );
			$lng     = isset( $o['longitude'] ) ? (float) $o['longitude'] : ( isset( $o['lng'] ) ? (float) $o['lng'] : null );

			$post_code = isset( $o['postal_code'] ) ? (string) $o['postal_code'] : ( isset( $o['postcode'] ) ? (string) $o['postcode'] : '' );
			if ( '' === $post_code && isset( $o['city']['postal_code'] ) ) {
				$post_code = (string) $o['city']['postal_code'];
			}

			$out[] = array(
				'id'        => $id,
				'name'      => $name,
				'name_en'   => $name,
				'address'   => $address,
				'city'      => $city,
				'city_id'   => isset( $o['city']['id'] ) ? (string) $o['city']['id'] : ( isset( $o['city_id'] ) ? (string) $o['city_id'] : '' ),
				'post_code' => $post_code,
				'lat'       => $lat,
				'lng'       => $lng,
				'is_locker' => $is_locker,
				'text'      => trim( $name . ( $address ? ' — ' . $address : '' ) ),
			);
		}
		return $out;
	}

	/**
	 * Pigeon list endpoints wrap results in { data: [...] }.
	 *
	 * @param array<string,mixed> $raw Response.
	 * @return array<int,mixed>
	 */
	private function rows( $raw ) {
		if ( isset( $raw['data'] ) && is_array( $raw['data'] ) ) {
			return $raw['data'];
		}
		return is_array( $raw ) ? $raw : array();
	}
}
