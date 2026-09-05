<?php
/**
 * Provider normalization checks for exact city identity.
 *
 * Run: php tests/test-location-provider-identity.php
 */

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );

	function bgcs3_strtolower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
	}
}

namespace BgCommerce3\Modules\Shipping {
	interface Locations_Provider {}
}

namespace BgCommerce3\Support {
	class Cache {}
	class Module_Settings {}
}

namespace BgCommerce3\Shipping {
	class Office_Store {}
}

namespace {
	require_once dirname( __DIR__ ) . '/app/Modules/Shipping/Speedy/Locations.php';
	require_once dirname( __DIR__ ) . '/app/Shipping/Location_Search.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Shipping/Econt/Locations.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Shipping/Pigeon/Locations.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Shipping/BoxNow/Locations.php';

	$failures = 0;
	function check_location_identity( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			++$failures;
		}
	}

	function invoke_location_normalizer( $class, $method, array $args ) {
		$object = ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
		$target = new \ReflectionMethod( $class, $method );
		$target->setAccessible( true );
		return $target->invokeArgs( $object, $args );
	}

	echo "Location provider city identity contract\n";
	check_location_identity( \BgCommerce3\Shipping\Location_Search::matches_city_id( array( 'city_id' => '1' ), '1' ), 'Exact same-city ID is accepted' );
	check_location_identity( ! \BgCommerce3\Shipping\Location_Search::matches_city_id( array( 'city_id' => '2' ), '1' ), 'Nearby row with another city ID is rejected' );
	check_location_identity( 'sofia' === \BgCommerce3\Shipping\Location_Search::fold( 'София' ), 'Cyrillic Sofia folds to the Latin search key' );
	check_location_identity( 'софия' === \BgCommerce3\Shipping\Location_Search::latin_to_cyrillic( 'Sofia' ), 'Latin Sofia has a provider fallback query' );

	$speedy = invoke_location_normalizer(
		\BgCommerce3\Modules\Shipping\Speedy\Locations::class,
		'normalize_offices',
		array( array( array( 'id' => 1, 'name' => 'Office', 'type' => 'OFFICE', 'address' => array( 'siteId' => 101, 'siteName' => 'Бяла' ) ) ) )
	);
	check_location_identity( '101' === $speedy[0]['city_id'], 'Speedy preserves address.siteId' );

	$econt = invoke_location_normalizer(
		\BgCommerce3\Modules\Shipping\Econt\Locations::class,
		'normalize_offices',
		array( array( 'offices' => array( array( 'code' => '1', 'name' => 'Office', 'address' => array( 'city' => array( 'id' => 202, 'name' => 'Бяла' ) ) ) ) ) )
	);
	check_location_identity( '202' === $econt[0]['city_id'], 'Econt preserves address.city.id' );

	$pigeon = invoke_location_normalizer(
		\BgCommerce3\Modules\Shipping\Pigeon\Locations::class,
		'normalize_offices',
		array( array( array( 'external_id' => '1', 'name' => 'Office', 'city' => array( 'id' => 303, 'name' => 'Бяла' ) ) ), 'office' )
	);
	check_location_identity( '303' === $pigeon[0]['city_id'], 'Pigeon preserves city.id' );

	$boxnow = invoke_location_normalizer(
		\BgCommerce3\Modules\Shipping\BoxNow\Locations::class,
		'normalize_apm',
		array( array( 'id' => '1', 'name' => 'Locker', 'addressLine2' => 'София' ) )
	);
	check_location_identity( 'София' === $boxnow['city_id'], 'BOX NOW uses its canonical city-name ID consistently' );

	echo PHP_EOL;
	if ( $failures ) {
		echo "FAILED: {$failures} check(s)\n";
		exit( 1 );
	}

	echo "OK - location provider identity checks passed\n";
}
