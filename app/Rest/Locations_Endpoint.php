<?php
/**
 * Public read endpoints for courier location autocomplete:
 *   GET /bg-commerce-suite/v3/{courier}/cities?query=&country=
 *   GET /bg-commerce-suite/v3/{courier}/offices?city_id=&type=office|locker
 *   GET /bg-commerce-suite/v3/{courier}/streets?city_id=&query=
 *   GET /bg-commerce-suite/v3/{courier}/quarters?city_id=&query=
 *
 * Generic: dispatches to whichever enabled courier matches the {courier} slug.
 * Inputs are sanitised; results are cached by the courier's Locations provider.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Rest;

use BgCommerce3\Module\Module_Registry;
use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Shipping\Location_Search;

defined( 'ABSPATH' ) || exit;

class Locations_Endpoint extends Controller {

	const COURIER_PATTERN = '(?P<courier>[a-z0-9_-]+)';

	public function register_routes() {
		$ns = self::NAMESPACE_V1;

		register_rest_route(
			$ns,
			'/' . self::COURIER_PATTERN . '/cities',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'cities' ),
				'permission_callback' => array( $this, 'locations_permission' ),
				'args'                => array(
					'query'   => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_query' ),
					),
					'country' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_country' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::COURIER_PATTERN . '/offices',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'offices' ),
				'permission_callback' => array( $this, 'locations_permission' ),
				'args'                => array(
					'city_id' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_optional_identifier' ),
					),
					'type'    => array(
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( $this, 'validate_location_type' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::COURIER_PATTERN . '/office-search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'office_search' ),
				'permission_callback' => array( $this, 'locations_permission' ),
				'args'                => array(
					'type'     => array(
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( $this, 'validate_location_type' ),
					),
					'query'    => array(
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_optional_query' ),
					),
					'postcode' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_postcode' ),
					),
					'city'     => array(
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_optional_query' ),
					),
					'city_id'  => array(
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_optional_identifier' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::COURIER_PATTERN . '/streets',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'streets' ),
				'permission_callback' => array( $this, 'locations_permission' ),
				'args'                => array(
					'city_id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_identifier' ),
					),
					'query'   => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_query' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::COURIER_PATTERN . '/quarters',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'quarters' ),
				'permission_callback' => array( $this, 'locations_permission' ),
				'args'                => array(
					'city_id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_identifier' ),
					),
					'query'   => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_query' ),
					),
				),
			)
		);
	}

	/**
	 * Публичен достъп с ограничение по хеширана client identity.
	 *
	 * @param \WP_REST_Request $request REST заявка.
	 * @return true|\WP_Error
	 */
	public function locations_permission( \WP_REST_Request $request ) {
		return Rest_Abuse_Guard::check_request( $request );
	}

	/**
	 * Изисква смислен autocomplete текст и ограничава размера му.
	 *
	 * @param mixed $value Стойност на query.
	 * @return bool
	 */
	public function validate_query( $value ) {
		$value = trim( (string) $value );
		return bgcs3_strlen( $value ) >= 2 && bgcs3_strlen( $value ) <= 80;
	}

	/** Optional search text is bounded even when the endpoint permits empty input. */
	public function validate_optional_query( $value ) {
		return bgcs3_strlen( trim( (string) $value ) ) <= 80;
	}

	/** Provider identifiers are opaque strings, but never unbounded request data. */
	public function validate_identifier( $value ) {
		$length = bgcs3_strlen( trim( (string) $value ) );
		return $length >= 1 && $length <= 80;
	}

	/** Optional variant of {@see validate_identifier()}. */
	public function validate_optional_identifier( $value ) {
		$value = trim( (string) $value );
		return '' === $value || $this->validate_identifier( $value );
	}

	/** Only the two public pickup-point types are accepted. */
	public function validate_location_type( $value ) {
		$value = sanitize_key( (string) $value );
		return '' === $value || in_array( $value, array( 'office', 'locker' ), true );
	}

	/** Country is optional and otherwise must be an ISO-2 code. */
	public function validate_country( $value ) {
		$value = trim( (string) $value );
		return '' === $value || 1 === preg_match( '/^[A-Za-z]{2}$/', $value );
	}

	/** Bulgarian and international postcodes fit comfortably in this bound. */
	public function validate_postcode( $value ) {
		return bgcs3_strlen( trim( (string) $value ) ) <= 20;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cities( \WP_REST_Request $request ) {
		$courier = $this->resolve_courier( $request['courier'] );
		if ( is_wp_error( $courier ) ) {
			return $courier;
		}

		$query   = (string) $request->get_param( 'query' );
		$country = $request->get_param( 'country' ) ? (string) $request->get_param( 'country' ) : 'BG';
		$data    = $this->location_data(
			'cities',
			array( $courier->id(), $query, $country ),
			static function () use ( $courier, $query, $country ) {
				return $courier->locations()->cities( $query, $country );
			}
		);

		return rest_ensure_response( $this->limit_results( $data, 50 ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function offices( \WP_REST_Request $request ) {
		$courier = $this->resolve_courier( $request['courier'] );
		if ( is_wp_error( $courier ) ) {
			return $courier;
		}

		$type = $request->get_param( 'type' ) ? (string) $request->get_param( 'type' ) : 'office';
		$type = in_array( $type, array( 'office', 'locker' ), true ) ? $type : 'office';

		$city_id = (string) $request->get_param( 'city_id' );
		$data    = $this->location_data(
			'offices',
			array( $courier->id(), $city_id, $type ),
			static function () use ( $courier, $city_id, $type ) {
				return $courier->locations()->offices( $city_id, $type );
			}
		);

		return rest_ensure_response( $this->limit_results( $data, 200 ) );
	}

	/**
	 * Search the courier's LOCAL synced pool of offices/lockers (no city needed).
	 * Ranks postcode matches first, then name/city/address substring matches.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function office_search( \WP_REST_Request $request ) {
		$courier = $this->resolve_courier( $request['courier'] );
		if ( is_wp_error( $courier ) ) {
			return $courier;
		}

		$type = $request->get_param( 'type' ) ? (string) $request->get_param( 'type' ) : 'office';
		$type = in_array( $type, array( 'office', 'locker' ), true ) ? $type : 'office';

		$query    = trim( bgcs3_strtolower( (string) $request->get_param( 'query' ) ) );
		$postcode = trim( (string) $request->get_param( 'postcode' ) );
		$city     = trim( bgcs3_strtolower( (string) $request->get_param( 'city' ) ) );
		$city_id  = trim( (string) $request->get_param( 'city_id' ) );

		// Checkout reads the persistent local pool so it never waits for a
		// full-country courier API response. On a cold store, a selected city may
		// still use the courier's smaller city-specific endpoint.
		$pool = \BgCommerce3\Shipping\Office_Store::get( $courier->id(), $type );

		if ( empty( $pool ) ) {
			if ( '' !== $city_id ) {
				$pool = (array) $courier->locations()->offices( $city_id, $type );
			}
		}

		if ( empty( $pool ) ) {
			return new \WP_Error(
				'bgcs3_office_pool_unavailable',
				__( 'The office list has not been synchronized yet. Try again in a moment or contact the store.', 'bg-commerce-suite' ),
				array( 'status' => 503 )
			);
		}

		$limit  = 200;
		$scored = array();

		foreach ( $pool as $office ) {
			$name  = isset( $office['name'] ) ? bgcs3_strtolower( (string) $office['name'] ) : '';
			$text  = isset( $office['text'] ) ? bgcs3_strtolower( (string) $office['text'] ) : '';
			$addr  = isset( $office['address'] ) ? bgcs3_strtolower( (string) $office['address'] ) : '';
			$pc    = isset( $office['post_code'] ) ? (string) $office['post_code'] : '';

			// City filter (drives the map: show ONLY the chosen city's points).
			if ( ( '' !== $city || '' !== $city_id ) && ! self::office_matches_city( $office, $city, $city_id ) ) {
				continue;
			}

			$score = 0;
			if ( '' !== $postcode && $pc === $postcode ) {
				$score += 100; // Same postcode as the checkout address — show first.
			}

			if ( '' !== $query ) {
				$haystack = $name . ' ' . $text . ' ' . $addr . ' ' . bgcs3_strtolower( $pc );
				if ( false === bgcs3_strpos( $haystack, $query ) ) {
					continue; // No textual match — skip.
				}
				$score += 10;
			}

			$scored[] = array( 'score' => $score, 'office' => $office );
		}

		// Stable sort by score desc.
		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$out = array();
		foreach ( array_slice( $scored, 0, $limit ) as $row ) {
			$out[] = $row['office'];
		}

		return rest_ensure_response( $out );
	}

	/**
	 * Require an exact normalized city when the courier provides one. Legacy
	 * rows without a city may fall back to a whole-word address/text match.
	 *
	 * @param array<string,mixed> $office Office row.
	 * @param string              $city    Requested city.
	 * @param string              $city_id Exact provider city ID, when available.
	 * @return bool
	 */
	public static function office_matches_city( array $office, $city, $city_id = '' ) {
		$city_id        = trim( (string) $city_id );
		$office_city_id = isset( $office['city_id'] ) ? trim( (string) $office['city_id'] ) : '';
		if ( '' !== $city_id && '' !== $office_city_id ) {
			return Location_Search::matches_city_id( $office, $city_id );
		}

		$city = self::normalize_city_name( $city );
		if ( '' === $city ) {
			return true;
		}

		$office_city = isset( $office['city'] ) ? self::normalize_city_name( $office['city'] ) : '';
		if ( '' !== $office_city ) {
			return $office_city === $city;
		}

		$address = isset( $office['address'] ) ? bgcs3_strtolower( (string) $office['address'] ) : '';
		$text    = isset( $office['text'] ) ? bgcs3_strtolower( (string) $office['text'] ) : '';
		$pattern = '/(?:^|[^\p{L}\p{N}])' . preg_quote( $city, '/' ) . '(?:$|[^\p{L}\p{N}])/u';

		return 1 === preg_match( $pattern, $address . ' ' . $text );
	}

	/**
	 * @param mixed $city City label.
	 * @return string
	 */
	private static function normalize_city_name( $city ) {
		$city = trim( bgcs3_strtolower( (string) $city ) );
		$city = preg_replace( '/^(?:гр\.?|град)\s+/u', '', $city );
		$city = preg_replace( '/\s+/u', ' ', (string) $city );

		return trim( (string) $city, " \t\n\r\0\x0B," );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function streets( \WP_REST_Request $request ) {
		$courier = $this->resolve_courier( $request['courier'] );
		if ( is_wp_error( $courier ) ) {
			return $courier;
		}

		$city_id = (string) $request->get_param( 'city_id' );
		$query   = (string) $request->get_param( 'query' );
		$data    = $this->location_data(
			'streets',
			array( $courier->id(), $city_id, $query ),
			static function () use ( $courier, $city_id, $query ) {
				return $courier->locations()->streets( $city_id, $query );
			}
		);

		return rest_ensure_response( $this->limit_results( $data, 100 ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function quarters( \WP_REST_Request $request ) {
		$courier = $this->resolve_courier( $request['courier'] );
		if ( is_wp_error( $courier ) ) {
			return $courier;
		}

		$city_id = (string) $request->get_param( 'city_id' );
		$query   = (string) $request->get_param( 'query' );
		$data    = $this->location_data(
			'quarters',
			array( $courier->id(), $city_id, $query ),
			static function () use ( $courier, $city_id, $query ) {
				return $courier->locations()->quarters( $city_id, $query );
			}
		);

		return rest_ensure_response( $this->limit_results( $data, 100 ) );
	}

	/**
	 * Изпълнява provider заявка с кратък кеш за временни грешки.
	 *
	 * @param string   $bucket   Endpoint bucket.
	 * @param array    $params   Нормализирани параметри.
	 * @param callable $callback Provider callback.
	 * @return mixed|\WP_Error
	 */
	private function location_data( $bucket, array $params, callable $callback ) {
		$cached = Rest_Abuse_Guard::get_negative_cache( $bucket, $params );
		if ( is_wp_error( $cached ) ) {
			return $cached;
		}

		$data = call_user_func( $callback );
		if ( is_wp_error( $data ) ) {
			Rest_Abuse_Guard::set_negative_cache( $bucket, $params, $data );
		}
		return $data;
	}

	/**
	 * @param mixed $data  Provider резултат.
	 * @param int   $limit Максимален брой редове.
	 * @return mixed
	 */
	private function limit_results( $data, $limit ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$out  = array();
		$seen = array();
		foreach ( $data as $row ) {
			$id = is_array( $row ) && isset( $row['id'] ) ? trim( (string) $row['id'] ) : '';
			if ( '' !== $id ) {
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
			}
			$out[] = $row;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Resolve an enabled courier module by slug.
	 *
	 * @param string $slug Courier slug.
	 * @return Courier_Interface|\WP_Error
	 */
	private function resolve_courier( $slug ) {
		$slug = sanitize_key( (string) $slug );

		/** @var Module_Registry $registry */
		$registry = $this->container['modules'];
		$module   = $registry->get( $slug );

		if ( ! $module instanceof Courier_Interface ) {
			return new \WP_Error( 'bgcs3_unknown_courier', __( 'Unknown courier.', 'bg-commerce-suite' ), array( 'status' => 404 ) );
		}

		if ( ! $module->is_enabled() ) {
			return new \WP_Error( 'bgcs3_courier_disabled', __( 'The courier is not active.', 'bg-commerce-suite' ), array( 'status' => 403 ) );
		}

		return $module;
	}
}
