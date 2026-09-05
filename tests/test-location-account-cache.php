<?php
/**
 * Account/environment location-cache invalidation contract.
 *
 * Run: php tests/test-location-account-cache.php
 */

namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['bgcs_test_options'] = array(
		'econt' => array( 'env' => 'demo', 'user' => 'merchant-a', 'password' => 'secret-a' ),
	);

	function bgcs3_get_option( $group, $key = null, $default = null ) {
		$data = isset( $GLOBALS['bgcs_test_options'][ $group ] ) ? $GLOBALS['bgcs_test_options'][ $group ] : array();
		if ( null === $key ) {
			return $data;
		}
		return array_key_exists( $key, $data ) ? $data[ $key ] : $default;
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	require_once dirname( __DIR__ ) . '/app/Admin/Settings/Settings_Page.php';

	use BgCommerce3\Admin\Settings\Settings_Page;

	$failures = 0;
	function check_account_cache( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			++$failures;
		}
	}

	echo "Location account cache contract\n";
	$page   = ( new \ReflectionClass( Settings_Page::class ) )->newInstanceWithoutConstructor();
	$method = new \ReflectionMethod( Settings_Page::class, 'account_settings_fingerprint' );
	$method->setAccessible( true );
	$fields = array( 'env' => array( 'default' => 'demo' ), 'user' => array(), 'password' => array() );
	$keys   = array( 'env', 'user', 'password' );
	$first  = $method->invoke( $page, 'econt', $keys, $fields );
	$same   = $method->invoke( $page, 'econt', $keys, $fields );
	check_account_cache( hash_equals( $first, $same ), 'Unchanged account settings keep the same cache identity' );

	$GLOBALS['bgcs_test_options']['econt']['user'] = 'merchant-b';
	$changed = $method->invoke( $page, 'econt', $keys, $fields );
	check_account_cache( ! hash_equals( $first, $changed ), 'Credential changes produce a different cache identity' );

	$source = php_strip_whitespace( dirname( __DIR__ ) . '/app/Admin/Settings/Settings_Page.php' );
	check_account_cache( false !== strpos( $source, 'Cache::flush_courier( $module->id() )' ), 'Changed account identity flushes transient courier data' );
	check_account_cache( false !== strpos( $source, 'Office_Store::forget( $module->id() )' ), 'Changed account identity invalidates persistent office/locker pools' );

	echo PHP_EOL;
	if ( $failures ) {
		echo "FAILED: {$failures} check(s)\n";
		exit( 1 );
	}

	echo "OK - location account cache checks passed\n";
}
