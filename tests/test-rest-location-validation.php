<?php
/**
 * Phase 11 bounds for public and administrator location REST input.
 */

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function bgcs3_strlen( $value ) {
	return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
}

require_once dirname( __DIR__ ) . '/app/Rest/Controller.php';
require_once dirname( __DIR__ ) . '/app/Rest/Locations_Endpoint.php';
require_once dirname( __DIR__ ) . '/app/Rest/Admin_Locations_Endpoint.php';

$public = ( new ReflectionClass( '\BgCommerce3\Rest\Locations_Endpoint' ) )->newInstanceWithoutConstructor();
$admin  = ( new ReflectionClass( '\BgCommerce3\Rest\Admin_Locations_Endpoint' ) )->newInstanceWithoutConstructor();
$failures = 0;

function bgcs_rest_bound_check( $condition, $message ) {
	global $failures;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ' - ' . $message . PHP_EOL;
	if ( ! $condition ) {
		++$failures;
	}
}

bgcs_rest_bound_check( $public->validate_query( 'Со' ), 'Public autocomplete accepts a two-character query' );
bgcs_rest_bound_check( ! $public->validate_query( 'S' ), 'Public autocomplete rejects a one-character query' );
bgcs_rest_bound_check( ! $public->validate_optional_query( str_repeat( 'a', 81 ) ), 'Optional public text is capped at 80 characters' );
bgcs_rest_bound_check( $public->validate_identifier( '68134' ), 'Opaque provider identifiers are accepted' );
bgcs_rest_bound_check( ! $public->validate_identifier( str_repeat( '1', 81 ) ), 'Provider identifiers are capped at 80 characters' );
bgcs_rest_bound_check( $public->validate_location_type( 'locker' ) && ! $public->validate_location_type( 'parcel_shop' ), 'Location type is restricted to the public enum' );
bgcs_rest_bound_check( $public->validate_country( 'BG' ) && ! $public->validate_country( 'BGR' ), 'Country is restricted to ISO-2 shape' );
bgcs_rest_bound_check( ! $public->validate_postcode( str_repeat( '1', 21 ) ), 'Postcode input is capped at 20 characters' );

bgcs_rest_bound_check( ! $admin->validate_optional_query( str_repeat( 'x', 81 ) ), 'Admin location query uses the same 80-character bound' );
bgcs_rest_bound_check( ! $admin->validate_optional_identifier( str_repeat( 'x', 81 ) ), 'Admin provider identifiers are bounded' );
bgcs_rest_bound_check( $admin->validate_location_type( 'office' ) && ! $admin->validate_location_type( 'address' ), 'Admin pickup-point type is enum-validated' );

if ( $failures ) {
	fwrite( STDERR, "FAILED: {$failures} REST location validation check(s)." . PHP_EOL );
	exit( 1 );
}

echo 'OK - Phase 11 REST location validation checks passed.' . PHP_EOL;
