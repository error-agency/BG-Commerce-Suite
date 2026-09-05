<?php
/**
 * Regression checks for exact checkout office city filtering.
 *
 * Run: php tests/test-location-city-filter.php
 */

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

	function bgcs3_strtolower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
	}

	function bgcs3_strpos( $haystack, $needle ) {
		return function_exists( 'mb_strpos' ) ? mb_strpos( (string) $haystack, (string) $needle, 0, 'UTF-8' ) : strpos( (string) $haystack, (string) $needle );
	}
}

namespace BgCommerce3\Rest {
	class Controller {}
}

namespace {
	require_once BGCS3_PATH . 'app/Shipping/Location_Search.php';
	require_once BGCS3_PATH . 'app/Rest/Locations_Endpoint.php';

	use BgCommerce3\Rest\Locations_Endpoint;

	$failures = 0;

	function check_city_filter( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			++$failures;
		}
	}

	echo "Location city filtering contract\n";

	check_city_filter(
		Locations_Endpoint::office_matches_city(
			array( 'city' => 'Русе', 'text' => 'Русе Център' ),
			'РУСЕ'
		),
		'City matching is case-insensitive'
	);
	check_city_filter(
		! Locations_Endpoint::office_matches_city(
			array( 'city' => 'Бяла, Русенско', 'text' => 'Бяла, Русенско' ),
			'Русе'
		),
		'Econt regional text cannot pass as the requested city'
	);
	check_city_filter(
		! Locations_Endpoint::office_matches_city(
			array( 'city' => 'БЯЛА (РУСЕ)', 'text' => 'гр. БЯЛА [7100]' ),
			'РУСЕ'
		),
		'Speedy parent-region notation cannot pass as the requested city'
	);
	check_city_filter(
		Locations_Endpoint::office_matches_city(
			array( 'city' => '', 'address' => 'гр. Русе, бул. Липник 1' ),
			'Русе'
		),
		'Legacy rows without a city retain a whole-word address fallback'
	);
	check_city_filter(
		! Locations_Endpoint::office_matches_city(
			array( 'city' => '', 'address' => 'област Русенско' ),
			'Русе'
		),
		'Legacy fallback rejects partial regional-name matches'
	);
	check_city_filter(
		Locations_Endpoint::office_matches_city(
			array( 'city' => 'Бяла', 'city_id' => '101' ),
			'Бяла',
			'101'
		),
		'Exact provider city ID accepts the selected city'
	);
	check_city_filter(
		! Locations_Endpoint::office_matches_city(
			array( 'city' => 'Бяла', 'city_id' => '202' ),
			'Бяла',
			'101'
		),
		'Same-name city with another provider ID is rejected'
	);

	$endpoint = ( new \ReflectionClass( Locations_Endpoint::class ) )->newInstanceWithoutConstructor();
	$limit_results = new \ReflectionMethod( Locations_Endpoint::class, 'limit_results' );
	$limit_results->setAccessible( true );
	$unique = $limit_results->invoke(
		$endpoint,
		array(
			array( 'id' => '10', 'name' => 'First' ),
			array( 'id' => '10', 'name' => 'Duplicate' ),
			array( 'id' => '11', 'name' => 'Second' ),
		),
		50
	);
	check_city_filter( 2 === count( $unique ) && 'First' === $unique[0]['name'], 'Duplicate provider IDs are collapsed deterministically' );

	echo PHP_EOL;
	if ( $failures ) {
		echo "FAILED: {$failures} check(s)\n";
		exit( 1 );
	}

	echo "OK - location city filtering checks passed\n";
}
