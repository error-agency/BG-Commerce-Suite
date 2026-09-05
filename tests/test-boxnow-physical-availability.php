<?php
/**
 * Offline regression checks for BOX NOW physical eligibility and explanations.
 *
 * @package BgCommerce3
 */

define( 'ABSPATH', __DIR__ );
define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

$GLOBALS['bgcs_dimension_unit'] = 'cm';
$GLOBALS['bgcs_weight_unit']    = 'kg';

function get_option( $key, $default = '' ) {
	if ( 'woocommerce_dimension_unit' === $key ) {
		return $GLOBALS['bgcs_dimension_unit'];
	}
	if ( 'woocommerce_weight_unit' === $key ) {
		return $GLOBALS['bgcs_weight_unit'];
	}
	return $default;
}

function wc_get_dimension( $value, $to_unit, $from_unit ) {
	unset( $to_unit );
	$value = (float) $value;
	$map   = array( 'cm' => 1.0, 'mm' => 0.1, 'm' => 100.0, 'in' => 2.54, 'yd' => 91.44 );
	return $value * ( isset( $map[ $from_unit ] ) ? $map[ $from_unit ] : 1.0 );
}

function wc_get_weight( $value, $to_unit, $from_unit ) {
	unset( $to_unit );
	$value = (float) $value;
	$map   = array( 'kg' => 1.0, 'g' => 0.001, 'lbs' => 0.45359237, 'oz' => 0.028349523125 );
	return $value * ( isset( $map[ $from_unit ] ) ? $map[ $from_unit ] : 1.0 );
}

final class Bgcs_Test_Product {
	private $id;
	private $parent_id;
	private $name;
	private $dimensions;
	private $weight;

	public function __construct( $id, $name, array $dimensions, $weight = 0.0, $parent_id = 0 ) {
		$this->id         = (int) $id;
		$this->parent_id  = (int) $parent_id;
		$this->name       = (string) $name;
		$this->dimensions = $dimensions;
		$this->weight     = (float) $weight;
	}
	public function is_virtual() { return false; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent_id; }
	public function get_name() { return $this->name; }
	public function get_length() { return isset( $this->dimensions[0] ) ? $this->dimensions[0] : ''; }
	public function get_width() { return isset( $this->dimensions[1] ) ? $this->dimensions[1] : ''; }
	public function get_height() { return isset( $this->dimensions[2] ) ? $this->dimensions[2] : ''; }
	public function get_weight() { return $this->weight; }
}

function bgcs_boxnow_package( array $rows ) {
	$contents = array();
	foreach ( $rows as $index => $row ) {
		$contents[ 'item-' . $index ] = array(
			'data'         => $row[0],
			'quantity'     => isset( $row[1] ) ? $row[1] : 1,
			'product_id'   => $row[0]->get_parent_id() ? $row[0]->get_parent_id() : $row[0]->get_id(),
			'variation_id' => $row[0]->get_parent_id() ? $row[0]->get_id() : 0,
		);
	}
	return array( 'contents' => $contents );
}

function bgcs_boxnow_check( $label, $condition ) {
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

require BGCS3_PATH . 'app/Shipping/Package_Dimensions.php';

use BgCommerce3\Shipping\Package_Dimensions;

echo "--- BOX NOW dimensions use normalized centimetres and any orientation ---\n";
bgcs_boxnow_check( 'Exact 36 × 45 × 60 boundary fits', 3 === Package_Dimensions::boxnow_size_for_dimensions( 36, 45, 60 ) );
bgcs_boxnow_check( 'Rotated 60 × 36 × 45 product fits', 3 === Package_Dimensions::boxnow_size_for_dimensions( 60, 36, 45 ) );
bgcs_boxnow_check( 'One dimension above the normalized envelope is rejected', 0 === Package_Dimensions::boxnow_size_for_dimensions( 60.01, 45, 36 ) );

$GLOBALS['bgcs_dimension_unit'] = 'mm';
$metric = Package_Dimensions::for_package(
	bgcs_boxnow_package( array( array( new Bgcs_Test_Product( 10, 'Metric parcel', array( 600, 450, 360 ) ) ) ) )
);
bgcs_boxnow_check( 'Millimetres are converted before validation', empty( $metric['oversize'] ) && 3 === $metric['minimum_compartment_size'] );
$GLOBALS['bgcs_dimension_unit'] = 'cm';

echo "--- BOX NOW reports the exact offending product/variation ---\n";
$safe      = new Bgcs_Test_Product( 11, 'Safe product', array( 20, 30, 40 ), 2.0 );
$variation = new Bgcs_Test_Product( 202, 'Ergonomic Bronze Lamp — Bronze', array( 67, 48, 42 ), 3.0, 101 );
$profile   = Package_Dimensions::for_package( bgcs_boxnow_package( array( array( $safe, 2 ), array( $variation, 3 ) ) ) );
$oversize_products = isset( $profile['oversize_products'] ) && is_array( $profile['oversize_products'] ) ? $profile['oversize_products'] : array();

bgcs_boxnow_check( 'Oversize variation is detected', ! empty( $profile['oversize'] ) );
bgcs_boxnow_check( 'Only the offending variation is reported', 1 === count( $oversize_products ) && 202 === $oversize_products[0]['id'] );
bgcs_boxnow_check( 'Affected product keeps parent/variation identity and quantity', ! empty( $oversize_products ) && 101 === $oversize_products[0]['parent_id'] && 3 === $oversize_products[0]['quantity'] );
bgcs_boxnow_check( 'Normalized observed dimensions are available for the customer explanation', ! empty( $oversize_products ) && 67.0 === $oversize_products[0]['dimensions_cm']['length'] && 48.0 === $oversize_products[0]['dimensions_cm']['width'] && 42.0 === $oversize_products[0]['dimensions_cm']['height'] );

echo "--- Missing dimensions and the 20 kg boundary stay truthful ---\n";
$unknown = Package_Dimensions::for_package( bgcs_boxnow_package( array( array( new Bgcs_Test_Product( 12, 'Unknown dimensions', array(), 1.0 ) ) ) ) );
bgcs_boxnow_check( 'Missing dimensions do not create a false oversize rejection', empty( $unknown['oversize'] ) && empty( $unknown['dimensions_known'] ) );

$at_limit = Package_Dimensions::for_package( bgcs_boxnow_package( array( array( new Bgcs_Test_Product( 13, 'Exactly 20 kg', array( 10, 20, 30 ), 20.0 ) ) ) ) );
bgcs_boxnow_check( 'Exactly 20 kg is not overweight', empty( $at_limit['overweight'] ) );

$overweight = Package_Dimensions::for_package( bgcs_boxnow_package( array( array( new Bgcs_Test_Product( 14, 'Heavy product', array( 10, 20, 30 ), 20.001 ) ) ) ) );
$overweight_products = isset( $overweight['overweight_products'] ) && is_array( $overweight['overweight_products'] ) ? $overweight['overweight_products'] : array();
bgcs_boxnow_check( 'A unit above 20 kg is rejected', ! empty( $overweight['overweight'] ) );
bgcs_boxnow_check( 'Overweight explanation identifies the product and normalized kg value', ! empty( $overweight_products ) && 14 === $overweight_products[0]['id'] && 20.001 === $overweight_products[0]['weight_kg'] );

$failures = bgcs_boxnow_check( null, true );
if ( $failures > 0 ) {
	fwrite( STDERR, "BOX NOW physical availability checks failed: {$failures}.\n" );
	exit( 1 );
}

echo "BOX NOW physical availability checks passed.\n";
