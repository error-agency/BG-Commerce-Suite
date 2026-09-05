<?php
/**
 * Contract for a courier's location data (cities, offices/lockers, streets,
 * quarters). Implementations are responsible for caching.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping;

defined( 'ABSPATH' ) || exit;

interface Locations_Provider {

	/**
	 * Autocomplete cities.
	 *
	 * @param string $query   Partial city name.
	 * @param string $country ISO-2 country code.
	 * @return array<int,array<string,mixed>>
	 */
	public function cities( $query, $country = 'BG' );

	/**
	 * Offices or lockers in a city.
	 *
	 * @param string $city_id City identifier.
	 * @param string $type    'office' | 'locker'.
	 * @return array<int,array<string,mixed>>
	 */
	public function offices( $city_id, $type = 'office' );

	/**
	 * Autocomplete streets in a city.
	 *
	 * @param string $city_id City identifier.
	 * @param string $query   Partial street name.
	 * @return array<int,array<string,mixed>>
	 */
	public function streets( $city_id, $query );

	/**
	 * Autocomplete quarters in a city.
	 *
	 * @param string $city_id City identifier.
	 * @param string $query   Partial quarter name.
	 * @return array<int,array<string,mixed>>
	 */
	public function quarters( $city_id, $query );
}
