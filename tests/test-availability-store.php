<?php
define( 'ABSPATH', __DIR__ );

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}

require_once dirname( __DIR__ ) . '/app/Support/Shipping_Availability.php';
require_once dirname( __DIR__ ) . '/app/Shipping/Availability_Store.php';

use BgCommerce3\Shipping\Availability_Store;
use BgCommerce3\Support\Shipping_Availability;

class Fake_Availability_Session {
	public $data = array();
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}
}

$failures = 0;
function check_store( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

$package_a = array(
	'contents'    => array( array( 'product_id' => 11, 'variation_id' => 12, 'quantity' => 2 ) ),
	'destination' => array( 'country' => 'BG', 'postcode' => '1000', 'city' => 'Sofia' ),
);
$package_b = array(
	'contents'    => array( array( 'product_id' => 21, 'quantity' => 1 ) ),
	'destination' => array( 'country' => 'BG', 'postcode' => '4000', 'city' => 'Plovdiv' ),
);

echo "--- Availability survives AJAX/session refresh but stale packages are filtered ---\n";
$session = new Fake_Availability_Session();
$store   = new Availability_Store( $session );
$state   = Shipping_Availability::unavailable( 'boxnow_oversize', 'Safe message', 'private diagnostic' );
$store->record( 'boxnow', 'BOX NOW', $package_a, $state );

$rows = $store->current_public( array( $package_a ) );
check_store( 1 === count( $rows ), 'Current package exposes one card' );
check_store( 'BOX NOW' === $rows[0]['courier_name'], 'Courier presentation data is retained' );
check_store( 0 === $rows[0]['package_index'], 'Current package index is resolved' );
check_store( ! array_key_exists( 'technical_message', $rows[0] ), 'Public rows never expose technical diagnostics' );
check_store( array() === $store->current_public( array( $package_b ) ), 'Stale package state is not exposed after cart/destination change' );

echo "--- Successful recalculation clears only its courier/package state ---\n";
$store->record( 'speedy', 'Speedy', $package_a, Shipping_Availability::error( 'speedy_timeout', 'Retry', 'timeout' ) );
$store->clear( 'boxnow', $package_a );
$rows = $store->current_public( array( $package_a ) );
check_store( 1 === count( $rows ) && 'speedy' === $rows[0]['courier'], 'Clearing BOX NOW leaves the Speedy state intact' );

exit( $failures > 0 ? 1 : 0 );
