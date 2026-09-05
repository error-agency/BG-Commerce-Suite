<?php
/**
 * Apply deterministic policy fixes to the retained Checkout Blocks bundle.
 *
 * The original build inputs are no longer present in this repository. This script keeps the
 * reviewed compiled asset reproducible while changing only the OSM endpoint, attribution and
 * license pointer. It fails closed when the expected bundle text changes.
 *
 * Usage: php tools/normalize-blocks-build.php
 *
 * @package BgCommerce3
 */

$root       = dirname( __DIR__ );
$bundle     = $root . '/assets/build/blocks.js';
$asset_file = $root . '/assets/build/blocks.asset.php';
$source     = (string) file_get_contents( $bundle );

$replacements = array(
	'"https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"' => '"https://tile.openstreetmap.org/{z}/{x}/{y}.png"',
	'attribution:"© OpenStreetMap"' => 'attribution:"&copy; <a href=\\"https://www.openstreetmap.org/copyright\\">OpenStreetMap</a> contributors"',
);

foreach ( $replacements as $old => $new ) {
	$old_count = substr_count( $source, $old );
	$new_count = substr_count( $source, $new );
	if ( 1 === $old_count && 0 === $new_count ) {
		$source = str_replace( $old, $new, $source );
		continue;
	}
	if ( 0 === $old_count && 1 === $new_count ) {
		continue;
	}
	fwrite( STDERR, sprintf( "Unexpected Blocks bundle state for: %s\n", $old ) );
	exit( 1 );
}

$notice = "/*! Includes Leaflet 1.9.4 under BSD-2-Clause; see THIRD-PARTY-NOTICES.md. */\n";
if ( 0 !== strpos( $source, $notice ) ) {
	$source = $notice . $source;
}

if ( false === file_put_contents( $bundle, $source ) ) {
	fwrite( STDERR, "Cannot update the Blocks bundle.\n" );
	exit( 1 );
}

$version = substr( hash( 'sha256', $source ), 0, 20 );
$asset   = "<?php return array('dependencies' => array('react', 'wc-blocks-checkout', 'wp-data', 'wp-element'), 'version' => '" . $version . "');\n";
if ( false === file_put_contents( $asset_file, $asset ) ) {
	fwrite( STDERR, "Cannot update the Blocks asset metadata.\n" );
	exit( 1 );
}

printf( "normalized assets/build/blocks.js\n  version: %s\n", $version );
