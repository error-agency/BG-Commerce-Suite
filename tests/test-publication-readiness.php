<?php
/**
 * Static contract for the plugin ZIP and clean public-source boundary.
 *
 * @package BgCommerce3
 */

$root     = dirname( __DIR__ );
$manifest = require $root . '/tools/release-manifest.php';
$failures = 0;

$check = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		++$failures;
	}
	printf( "  [%s] %s\n", $condition ? 'PASS' : 'FAIL', $message );
};

$read = static function ( $path ) {
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		throw new RuntimeException( 'Cannot read ' . $path );
	}
	return $contents;
};

echo "--- Canonical release manifests ---\n";
$plugin = isset( $manifest['plugin'] ) ? (array) $manifest['plugin'] : array();
$public = isset( $manifest['public_source'] ) ? (array) $manifest['public_source'] : array();
foreach ( array( 'LICENSE', 'README.md', 'THIRD-PARTY-NOTICES.md' ) as $required ) {
	$check( in_array( $required, $plugin, true ), "Plugin ZIP includes {$required}" );
}
foreach ( array( 'PUBLICATION.md', 'SECURITY.md', 'tests', 'tools' ) as $required ) {
	$check( in_array( $required, $public, true ), "Public source includes {$required}" );
}
foreach ( array( 'audit', 'docs', 'dist', 'boxnow-webhook-test.ps1' ) as $private ) {
	$check( ! in_array( $private, $public, true ), "Public source excludes {$private}" );
}

echo "--- Third-party notices and map policy ---\n";
$notices = $read( $root . '/THIRD-PARTY-NOTICES.md' );
$blocks  = $read( $root . '/assets/build/blocks.js' );
$check( false !== strpos( $notices, 'BSD 2-Clause License' ) && false !== strpos( $notices, 'Volodymyr Agafonkin' ), 'Leaflet BSD notice is complete and attributed' );
$check( false !== strpos( $notices, 'ISC License' ) && false !== strpos( $notices, 'Lucide Icons and Contributors' ), 'Lucide ISC notice is present' );
$check( false !== strpos( $notices, 'Copyright (c) 2013-present Cole Bemis' ), 'Feather-derived icon notice is present' );
$check( false !== strpos( $blocks, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ), 'Blocks uses the current OSM tile endpoint' );
$check( false === strpos( $blocks, 'https://{s}.tile.openstreetmap.org' ), 'Blocks contains no retired OSM subdomain template' );
$check( false !== strpos( $blocks, 'https://www.openstreetmap.org/copyright' ) && false !== strpos( $blocks, 'OpenStreetMap</a> contributors' ), 'Blocks attribution links to the OSM license page and names contributors' );

echo "--- Exported public source ---\n";
$snapshot = $root . '/dist/public-source/BG-Commerce-Suite';
$check( is_dir( $snapshot ), 'Public source snapshot exists' );
$forbidden_paths = array( '/audit/', '/docs/', '/dist/', 'boxnow-webhook-test.ps1', 'docs.zip', '.git/' );
$forbidden_text  = array(
	'solo' . 'byte.net',
	'staging.' . 'solo' . 'byte.net',
	'#83' . '82',
	'#83' . '83',
	'Co-' . 'Authored-By:',
);
$text_extensions = array( 'css', 'html', 'js', 'json', 'md', 'php', 'po', 'pot', 'ps1', 'txt', 'xml', 'yaml', 'yml' );

if ( is_dir( $snapshot ) ) {
	$bad_paths = array();
	$bad_text  = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $snapshot, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $snapshot ) ) );
		foreach ( $forbidden_paths as $needle ) {
			if ( false !== stripos( '/' . ltrim( $relative, '/' ), $needle ) ) {
				$bad_paths[] = $relative;
			}
		}
		if ( $file->isFile() && in_array( strtolower( $file->getExtension() ), $text_extensions, true ) ) {
			$contents = $read( $file->getPathname() );
			foreach ( $forbidden_text as $needle ) {
				if ( false !== stripos( $contents, $needle ) ) {
					$bad_text[] = $needle . ' in ' . $relative;
				}
			}
		}
	}
	$check( ! $bad_paths, 'Public snapshot contains no forbidden internal paths' );
	$check( ! $bad_text, 'Public snapshot contains no private environment/history markers' );
	if ( $bad_paths ) {
		echo '    ' . implode( "\n    ", array_unique( $bad_paths ) ) . "\n";
	}
	if ( $bad_text ) {
		echo '    ' . implode( "\n    ", array_unique( $bad_text ) ) . "\n";
	}
}

echo "--- Plugin ZIP boundary ---\n";
$version = '';
$header  = $read( $root . '/bg-commerce-suite.php' );
if ( preg_match( "/define\(\s*'BGCS3_VERSION',\s*'([^']+)'\s*\)/", $header, $matches ) ) {
	$version = $matches[1];
}
$zip_path = $root . '/dist/bg-commerce-suite-' . $version . '.zip';
$check( '' !== $version && is_file( $zip_path ), 'Current-version plugin ZIP exists' );
if ( is_file( $zip_path ) ) {
	$zip = new ZipArchive();
	$check( true === $zip->open( $zip_path ), 'Plugin ZIP opens successfully' );
	if ( true === $zip->open( $zip_path ) ) {
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$check( in_array( 'bg-commerce-suite/LICENSE', $names, true ), 'Plugin ZIP contains GPL license' );
		$check( in_array( 'bg-commerce-suite/THIRD-PARTY-NOTICES.md', $names, true ), 'Plugin ZIP contains third-party notices' );
		$check( in_array( 'bg-commerce-suite/assets/img/PROVENANCE.md', $names, true ), 'Plugin ZIP contains courier asset provenance' );
		$outside_root = array_filter( $names, static function ( $name ) { return 0 !== strpos( $name, 'bg-commerce-suite/' ); } );
		$check( ! $outside_root, 'Plugin ZIP has one bg-commerce-suite/ root' );
		$mismatches = array();
		foreach ( $names as $name ) {
			if ( '/' === substr( $name, -1 ) ) {
				continue;
			}
			$relative = substr( $name, strlen( 'bg-commerce-suite/' ) );
			$source   = $root . '/' . $relative;
			if ( ! is_file( $source ) || file_get_contents( $source ) !== $zip->getFromName( $name ) ) {
				$mismatches[] = $name;
			}
		}
		$check( ! $mismatches, 'Every ZIP file is byte-identical to the reviewed source' );
		if ( $mismatches ) {
			echo '    ' . implode( "\n    ", $mismatches ) . "\n";
		}
		$zip->close();
	}
}

echo "\n" . ( $failures ? "FAILED: {$failures} check(s)\n" : "OK - publication and packaging boundaries verified\n" );
exit( $failures ? 1 : 0 );
