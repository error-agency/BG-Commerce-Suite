<?php
/**
 * BGCS-AUDIT-017 — regression tests for the Econt office / shipment-type guard.
 *
 * The guard compared `label.shipmentType` against `Office.shipmentTypes` as if
 * they were one vocabulary. They are not, and the two lists intersect only on
 * `cargo` and `pallet`. `pack` — the default for ordinary goods — is present in
 * 0 of 572 synced offices, so the first locations sync silently turned every
 * Econt office delivery into a refusal: order #8270 got a waybill to office
 * 7042 on 2026-08-20, and #8230 with identical configuration was refused after
 * the cache was repopulated.
 *
 * Run: php tests/test-econt-shipment-type-map.php
 */

define( 'ABSPATH', __DIR__ );

require_once dirname( __DIR__ ) . '/app/Modules/Shipping/Econt/Shipment_Type_Map.php';

use BgCommerce3\Modules\Shipping\Econt\Shipment_Type_Map;

$failures = 0;
function check_map( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

/**
 * The office-side vocabulary exactly as measured on the live Econt demo
 * nomenclature on 2026-08-26 (572 offices):
 *   courier 568 · cargo 566 · post 565 · pallet 223
 */
const LIVE_OFFICE_VOCABULARY = array( 'courier', 'cargo', 'post', 'pallet' );

/** What a typical synced office looked like — note: no `pack`, ever. */
const TYPICAL_OFFICE = array( 'courier', 'cargo', 'post' );

/** Everything BGCS is able to put in `label.shipmentType`. */
const BGCS_SHIPMENT_TYPES = array( 'document', 'pack', 'pallet', 'cargo', 'documentpallet', 'big_letter', 'small_letter' );

echo "--- The reported regression: an ordinary parcel to a synced office ---\n";
check_map(
	null === Shipment_Type_Map::office_accepts( TYPICAL_OFFICE, 'pack' ),
	'`pack` to a synced office is no longer refused (was: refused at 572 of 572 offices)'
);
check_map(
	null === Shipment_Type_Map::office_accepts( LIVE_OFFICE_VOCABULARY, 'pack' ),
	'`pack` is not refused even by an office listing the full observed vocabulary'
);
foreach ( array( 'document', 'big_letter', 'small_letter' ) as $ordinary ) {
	check_map(
		null === Shipment_Type_Map::office_accepts( LIVE_OFFICE_VOCABULARY, $ordinary ),
		"`{$ordinary}` has no documented office counterpart, so it is left to Econt to decide"
	);
}

echo "--- Acceptance criterion 4: identical before and after a locations sync ---\n";
// The old guard was conditional on the field being populated, which is what
// made the defect appear out of nowhere on a routine daily background job.
foreach ( BGCS_SHIPMENT_TYPES as $type ) {
	$before = Shipment_Type_Map::office_accepts( array(), $type );          // cache not yet synced
	$after  = Shipment_Type_Map::office_accepts( TYPICAL_OFFICE, $type );   // cache synced
	$same   = ( $before === $after );
	if ( in_array( $type, array( 'cargo', 'pallet', 'documentpallet' ), true ) ) {
		// The documented correspondences are data-driven by design; what must
		// never differ is the ordinary-goods path below.
		continue;
	}
	check_map( $same, "`{$type}` behaves identically before and after the sync" );
}

echo "--- The documented correspondences are still enforced ---\n";
check_map( true === Shipment_Type_Map::office_accepts( array( 'courier', 'cargo' ), 'cargo' ), '`cargo` is accepted by a cargo office' );
check_map( false === Shipment_Type_Map::office_accepts( array( 'courier', 'post' ), 'cargo' ), '`cargo` is refused by an office without cargo service' );
check_map( true === Shipment_Type_Map::office_accepts( array( 'courier', 'pallet' ), 'pallet' ), '`pallet` is accepted by a pallet office' );
check_map( false === Shipment_Type_Map::office_accepts( TYPICAL_OFFICE, 'pallet' ), '`pallet` is refused by an office without pallet service' );
check_map( true === Shipment_Type_Map::office_accepts( array( 'pallet' ), 'documentpallet' ), '`documentpallet` maps to the pallet capability' );
check_map( false === Shipment_Type_Map::office_accepts( array( 'courier' ), 'documentpallet' ), '`documentpallet` is refused without pallet service' );

echo "--- An unknown office vocabulary never becomes a refusal ---\n";
// This is the shape of the original defect: Econt's data used words BGCS did
// not understand, and BGCS turned that into "refused" instead of "unknown".
check_map( null === Shipment_Type_Map::office_accepts( array( 'parcel', 'freight' ), 'cargo' ), 'An unrecognised office vocabulary yields "undecidable", not "refused"' );
check_map( null === Shipment_Type_Map::office_accepts( array( '', '  ' ), 'cargo' ), 'Blank capability values yield "undecidable"' );
check_map( null === Shipment_Type_Map::office_accepts( 'not-an-array', 'cargo' ), 'A malformed office row yields "undecidable"' );
check_map( null === Shipment_Type_Map::office_accepts( LIVE_OFFICE_VOCABULARY, 'pp' ), 'A shipment type the map has not been taught yields "undecidable"' );
check_map( null === Shipment_Type_Map::office_accepts( LIVE_OFFICE_VOCABULARY, '' ), 'An empty shipment type yields "undecidable"' );

echo "--- Normalisation ---\n";
// Econt's own Postman collection sends "PACK" upper-case and echoes "pack".
check_map( true === Shipment_Type_Map::office_accepts( array( 'CARGO' ), 'cargo' ), 'Office capabilities are compared case-insensitively' );
check_map( true === Shipment_Type_Map::office_accepts( array( ' cargo ' ), 'CARGO' ), 'Both sides are trimmed and lower-cased' );

echo "--- Static guards ---\n";
$econt_code     = php_strip_whitespace( dirname( __DIR__ ) . '/app/Modules/Shipping/Econt/Econt.php' );
$locations_code = php_strip_whitespace( dirname( __DIR__ ) . '/app/Modules/Shipping/Econt/Locations.php' );

check_map(
	false === strpos( $econt_code, "in_array( \$shipment_type, \$office_types, true )" )
		&& false === strpos( $econt_code, "in_array( \$shipment_type, \$sender_types, true )" ),
	'Econt.php no longer compares the two vocabularies directly'
);
check_map( 2 === substr_count( $econt_code, 'Shipment_Type_Map::office_accepts(' ), 'Both the receiver and the sender guard go through the map' );
check_map( false !== strpos( $locations_code, "'shipmentTypes'" ), 'Locations.php still populates shipment_types — the data stays, only its use changed' );

// Every type BGCS can send must be accounted for, so adding one to
// Label_Builder cannot silently fall through the map unnoticed.
$builder_code = php_strip_whitespace( dirname( __DIR__ ) . '/app/Modules/Shipping/Econt/Label_Builder.php' );
preg_match( "/\\\$allowed = array\( ([^)]*) \);/", $builder_code, $m );
$declared = array();
if ( ! empty( $m[1] ) ) {
	preg_match_all( "/'([a-z_]+)'/", $m[1], $found );
	$declared = $found[1];
}
check_map( BGCS_SHIPMENT_TYPES === $declared, 'Label_Builder still declares exactly the shipment types this test knows about: ' . implode( ', ', $declared ) );

$accounted = array_merge( array_keys( Shipment_Type_Map::CONFIRMED ), array_keys( Shipment_Type_Map::UNCONFIRMED ) );
sort( $accounted );
$expected = BGCS_SHIPMENT_TYPES;
sort( $expected );
check_map( $expected === $accounted, 'Every shipment type BGCS can send appears in the map, confirmed or explicitly unconfirmed' );

check_map(
	array() === array_diff( array_values( Shipment_Type_Map::CONFIRMED ), Shipment_Type_Map::KNOWN_OFFICE_CAPABILITIES ),
	'Every enforced correspondence points at a capability actually observed in the nomenclature'
);

// The measurement that started this: the two vocabularies overlap on exactly
// two words, and those two are the only ones enforced.
$overlap = array_values( array_intersect( BGCS_SHIPMENT_TYPES, LIVE_OFFICE_VOCABULARY ) );
sort( $overlap );
check_map( array( 'cargo', 'pallet' ) === $overlap, 'The literal overlap between the two vocabularies is still exactly cargo + pallet' );
check_map(
	array() === array_diff( $overlap, array_keys( Shipment_Type_Map::CONFIRMED ) ),
	'Nothing in that overlap has been dropped from the enforced map'
);

echo PHP_EOL;
echo "NOTE: acceptance criterion 3 (\"validation fails only for combinations Econt really refuses\")" . PHP_EOL;
echo "      still needs a SANDBOX run of mode=validate. The Econt demo environment returned" . PHP_EOL;
echo "      HTTP 500 (their Redis outage) during the audit, so it could not be executed." . PHP_EOL;
echo PHP_EOL;

if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all Econt shipment type map checks passed' . PHP_EOL;
