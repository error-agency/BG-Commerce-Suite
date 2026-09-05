<?php
/**
 * Offline regression checks for the structured shipping availability contract.
 *
 * @package BgCommerce3
 */

define( 'ABSPATH', __DIR__ );
define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

function bgcs_availability_check( $label, $condition ) {
	static $failures = 0;
	if ( null === $label ) {
		return $failures;
	}
	if ( ! $condition ) {
		++$failures;
	}
	printf( "  [%s] %s\n", $condition ? 'PASS' : 'FAIL', $label );
	return $condition;
}

$availability_file = BGCS3_PATH . 'app/Support/Shipping_Availability.php';
if ( ! is_file( $availability_file ) ) {
	fwrite( STDERR, "FAIL: structured Shipping_Availability model is missing.\n" );
	exit( 1 );
}

require $availability_file;
require BGCS3_PATH . 'app/Support/Price_Result.php';

use BgCommerce3\Support\Price_Result;
use BgCommerce3\Support\Shipping_Availability;

echo "--- Unavailable state keeps public and technical data separate ---\n";
$availability = Shipping_Availability::unavailable(
	'boxnow_product_oversize',
	'BOX NOW не е наличен за тази поръчка.',
	'Normalized product dimensions exceed compartment 3.',
	array(
		'affected_products' => array(
			array( 'id' => 42, 'name' => 'Ergonomic Bronze Lamp', 'quantity' => 1 ),
		),
		'limits'          => array( 'length_cm' => 60.0, 'width_cm' => 45.0, 'height_cm' => 36.0 ),
		'observed_values' => array( 'length_cm' => 67.0, 'width_cm' => 48.0, 'height_cm' => 42.0 ),
	)
);

$public = $availability->to_public_array();
$full   = $availability->to_array();

bgcs_availability_check( 'State is unavailable', Shipping_Availability::UNAVAILABLE === $availability->status );
bgcs_availability_check( 'Machine-readable code is preserved', 'boxnow_product_oversize' === $availability->code );
bgcs_availability_check( 'Public payload contains the safe customer message', 'BOX NOW не е наличен за тази поръчка.' === $public['customer_message'] );
bgcs_availability_check( 'Public payload excludes technical_message', ! array_key_exists( 'technical_message', $public ) );
bgcs_availability_check( 'Full diagnostic payload keeps technical_message', 'Normalized product dimensions exceed compartment 3.' === $full['technical_message'] );
bgcs_availability_check( 'Public affected product keeps its customer-facing name', 'Ergonomic Bronze Lamp' === $public['affected_products'][0]['name'] );
bgcs_availability_check( 'Public affected product excludes internal database IDs', ! array_key_exists( 'id', $public['affected_products'][0] ) && ! array_key_exists( 'parent_id', $public['affected_products'][0] ) );
bgcs_availability_check( 'Full diagnostic payload retains product identity', 42 === $full['affected_products'][0]['id'] );

echo "--- Price_Result exposes explicit unavailable and temporary-error factories ---\n";
$available   = Shipping_Availability::available( 'speedy_available', 'Speedy is available.' );
$pending     = Shipping_Availability::pending( 'speedy_pending', 'Choose a destination.' );
$unavailable = Price_Result::unavailable( 'service_not_allowed', 'Услугата не е налична.', 'Provider rejected service 505.' );
$temporary   = Price_Result::temporary_error( 'speedy_network', 'В момента не можем да изчислим цената.', 'Connection timed out.' );

bgcs_availability_check( 'Available and pending are explicit states', Shipping_Availability::AVAILABLE === $available->status && Shipping_Availability::PENDING === $pending->status );
bgcs_availability_check( 'Unavailable quote is not valid/selectable', false === $unavailable->valid && 0.0 === $unavailable->cost );
bgcs_availability_check( 'Unavailable quote carries unavailable state', Shipping_Availability::UNAVAILABLE === $unavailable->availability->status );
bgcs_availability_check( 'Temporary quote carries an explicit temporary_error state', 'temporary_error' === $temporary->availability->status && Shipping_Availability::TEMPORARY_ERROR === $temporary->availability->status );
bgcs_availability_check( 'Price result serialization includes the public availability contract', 'speedy_network' === $temporary->to_array()['availability']['code'] );
bgcs_availability_check( 'Price result serialization never exposes technical diagnostics', ! array_key_exists( 'technical_message', $temporary->to_array()['availability'] ) );

$failures = bgcs_availability_check( null, true );
if ( $failures > 0 ) {
	fwrite( STDERR, "Shipping availability contract checks failed: {$failures}.\n" );
	exit( 1 );
}

echo "Shipping availability contract checks passed.\n";
