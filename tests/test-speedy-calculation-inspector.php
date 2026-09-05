<?php
/**
 * Offline regression checks for Speedy calculation-response classification.
 *
 * @package BgCommerce3
 */

define( 'ABSPATH', __DIR__ );
define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

function __( $text, $domain = '' ) {
	unset( $domain );
	return $text;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function bgcs_speedy_inspector_check( $label, $condition ) {
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
$inspector_file    = BGCS3_PATH . 'app/Modules/Shipping/Speedy/Calculation_Inspector.php';
if ( ! is_file( $availability_file ) || ! is_file( $inspector_file ) ) {
	fwrite( STDERR, "FAIL: Speedy structured calculation inspector is missing.\n" );
	exit( 1 );
}

require $availability_file;
require $inspector_file;

use BgCommerce3\Modules\Shipping\Speedy\Calculation_Inspector;
use BgCommerce3\Support\Shipping_Availability;

echo "--- Speedy selects the requested service result ---\n";
$valid = Calculation_Inspector::inspect(
	array(
		'calculations' => array(
			array( 'serviceId' => 504, 'price' => array( 'total' => 9.99, 'currency' => 'EUR' ) ),
			array( 'serviceId' => 505, 'price' => array( 'total' => '12.34', 'currency' => 'eur' ) ),
		),
	),
	505,
	'EUR'
);
bgcs_speedy_inspector_check( 'Requested service is selected instead of blindly using calculations[0]', ! empty( $valid['valid'] ) && 12.34 === $valid['total'] && 505 === $valid['calculation']['serviceId'] );

echo "--- Per-service provider errors become unavailable, not a generic invalid price ---\n";
$rejected = Calculation_Inspector::inspect(
	array(
		'calculations' => array(
			array(
				'serviceId' => 505,
				'error'     => array( 'code' => 'SERVICE_NOT_ALLOWED', 'message' => 'serviceId 505 is not allowed for sender 123' ),
			),
		),
	),
	505,
	'EUR'
);
$rejected_public = $rejected['availability']->to_public_array();
bgcs_speedy_inspector_check( 'Provider rejection is classified as unavailable', empty( $rejected['valid'] ) && Shipping_Availability::UNAVAILABLE === $rejected['availability']->status );
bgcs_speedy_inspector_check( 'Provider rejection has a stable machine code', 'speedy_service_unavailable' === $rejected['availability']->code );
bgcs_speedy_inspector_check( 'Raw service/sender IDs never reach the customer message', false === strpos( $rejected_public['customer_message'], '505' ) && false === strpos( $rejected_public['customer_message'], '123' ) );
bgcs_speedy_inspector_check( 'Technical diagnostics retain the provider reason for logs', false !== strpos( $rejected['availability']->technical_message, 'SERVICE_NOT_ALLOWED' ) );

echo "--- Missing, malformed, zero and wrong-currency prices are distinct ---\n";
$missing = Calculation_Inspector::inspect( array( 'calculations' => array( array( 'serviceId' => 505, 'price' => array( 'currency' => 'EUR' ) ) ) ), 505, 'EUR' );
$null    = Calculation_Inspector::inspect( array( 'calculations' => array( array( 'serviceId' => 505, 'price' => array( 'total' => null, 'currency' => 'EUR' ) ) ) ), 505, 'EUR' );
$zero    = Calculation_Inspector::inspect( array( 'calculations' => array( array( 'serviceId' => 505, 'price' => array( 'total' => 0, 'currency' => 'EUR' ) ) ) ), 505, 'EUR' );
$wrong   = Calculation_Inspector::inspect( array( 'calculations' => array( array( 'serviceId' => 505, 'price' => array( 'total' => 10, 'currency' => 'BGN' ) ) ) ), 505, 'EUR' );
$absent  = Calculation_Inspector::inspect( array( 'calculations' => array( array( 'serviceId' => 504, 'price' => array( 'total' => 10, 'currency' => 'EUR' ) ) ) ), 505, 'EUR' );

bgcs_speedy_inspector_check( 'Missing total has its own diagnostic code', 'speedy_price_missing' === $missing['availability']->code );
bgcs_speedy_inspector_check( 'Null total is malformed rather than silently cast to zero', 'speedy_price_invalid' === $null['availability']->code );
bgcs_speedy_inspector_check( 'Zero total is rejected without registering free delivery', 'speedy_price_non_positive' === $zero['availability']->code );
bgcs_speedy_inspector_check( 'Currency mismatch is explicit', 'speedy_currency_mismatch' === $wrong['availability']->code );
bgcs_speedy_inspector_check( 'Missing requested service is explicit', 'speedy_service_missing' === $absent['availability']->code );

$failures = bgcs_speedy_inspector_check( null, true );
if ( $failures > 0 ) {
	fwrite( STDERR, "Speedy calculation inspector checks failed: {$failures}.\n" );
	exit( 1 );
}

echo "Speedy calculation inspector checks passed.\n";

