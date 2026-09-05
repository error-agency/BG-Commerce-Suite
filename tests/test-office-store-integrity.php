<?php
/**
 * Persistent office/locker directory integrity checks.
 *
 * Run: php tests/test-office-store-integrity.php
 */

namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['bgcs_test_options'] = array();

	function update_option( $key, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['bgcs_test_options'][ $key ] = $value;
		return true;
	}

	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['bgcs_test_options'] ) ? $GLOBALS['bgcs_test_options'][ $key ] : $default;
	}

	function delete_option( $key ) {
		unset( $GLOBALS['bgcs_test_options'][ $key ] );
		return true;
	}

	function __( $text ) {
		return $text;
	}

	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}

	class WP_Error {
		public function __construct( $code = '', $message = '' ) {
			unset( $code, $message );
		}
	}

	require_once dirname( __DIR__ ) . '/app/Shipping/Office_Store.php';

	use BgCommerce3\Shipping\Office_Store;

	$failures = 0;
	function check_office_store( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			++$failures;
		}
	}

	echo "Office store integrity contract\n";
	$count = Office_Store::replace_if_valid(
		'econt',
		'office',
		array(
			array( 'id' => '100', 'name' => 'Canonical', 'city_id' => '1' ),
			array( 'id' => '100', 'name' => 'Duplicate', 'city_id' => '2' ),
			array( 'id' => '200', 'name' => 'Second', 'city_id' => '1' ),
			array( 'id' => '', 'name' => 'Invalid' ),
		)
	);
	$rows = Office_Store::get( 'econt', 'office' );
	check_office_store( 2 === $count && 2 === count( $rows ), 'Exact duplicate IDs are stored once' );
	check_office_store( 'Canonical' === $rows[0]['name'], 'First valid provider row wins deterministically' );
	check_office_store( 2 === Office_Store::meta( 'econt', 'office' )['count'], 'Directory metadata reflects the deduplicated pool' );

	Office_Store::forget( 'econt' );
	check_office_store( ! Office_Store::has( 'econt', 'office' ), 'Invalidated account pool is no longer readable' );

	echo PHP_EOL;
	if ( $failures ) {
		echo "FAILED: {$failures} check(s)\n";
		exit( 1 );
	}

	echo "OK - office store integrity checks passed\n";
}
