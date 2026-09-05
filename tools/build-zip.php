<?php
/**
 * Build the distributable plugin ZIP.
 *
 * Ships exactly what WordPress needs and nothing else: no docs, dist, tests,
 * tools, VCS metadata or working notes. The archive root is always
 * `bg-commerce-suite/` so the folder name is stable regardless of how the ZIP
 * was downloaded.
 *
 * Usage:  php tools/build-zip.php
 *
 * @package BgCommerce3
 */

$root     = dirname( __DIR__ );
$manifest = require __DIR__ . '/release-manifest.php';

// The shipped surface. Anything not listed here never reaches a customer site.
$include = isset( $manifest['plugin'] ) ? $manifest['plugin'] : array();
if ( ! is_array( $include ) || ! $include ) {
	fwrite( STDERR, "The plugin release manifest is empty.\n" );
	exit( 1 );
}

// Belt and braces: even inside a shipped directory these never travel.
$exclude_names = array( '.git', '.gitignore', '.DS_Store', 'Thumbs.db', 'desktop.ini', 'node_modules' );
$exclude_ext   = array( 'patch', 'ps1', 'log', 'bak', 'orig', 'rej', 'zip' );

// Normalize entry timestamps so rebuilding the same source produces the same
// archive checksum. CI may override the stable fallback with SOURCE_DATE_EPOCH.
$source_date_epoch = getenv( 'SOURCE_DATE_EPOCH' );
$archive_mtime     = is_string( $source_date_epoch ) && ctype_digit( $source_date_epoch )
	? max( 315532800, (int) $source_date_epoch )
	: 946684800;

$version = '';
$header  = (string) file_get_contents( $root . '/bg-commerce-suite.php' );
if ( preg_match( "/define\(\s*'BGCS3_VERSION',\s*'([^']+)'\s*\)/", $header, $m ) ) {
	$version = $m[1];
}
if ( '' === $version ) {
	fwrite( STDERR, "Could not read BGCS3_VERSION.\n" );
	exit( 1 );
}

// The plugin header and the constant must agree, or the update shipped is not
// the update announced.
if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $header, $hm ) || $hm[1] !== $version ) {
	fwrite( STDERR, sprintf( "Version mismatch: header %s vs BGCS3_VERSION %s.\n", isset( $hm[1] ) ? $hm[1] : '?', $version ) );
	exit( 1 );
}

$readme = (string) file_get_contents( $root . '/readme.txt' );
if ( ! preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $rm ) || $rm[1] !== $version ) {
	fwrite( STDERR, sprintf( "Stable tag mismatch: readme.txt %s vs %s.\n", isset( $rm[1] ) ? $rm[1] : '?', $version ) );
	exit( 1 );
}

$changelog = (string) file_get_contents( $root . '/CHANGELOG.md' );
if ( ! preg_match( '/^##\s+([0-9]+\.[0-9]+\.[0-9]+)\b/m', $changelog, $cm ) || $cm[1] !== $version ) {
	fwrite( STDERR, sprintf( "Changelog version mismatch: CHANGELOG.md %s vs %s.\n", isset( $cm[1] ) ? $cm[1] : '?', $version ) );
	exit( 1 );
}

// README.md carries the version too, and a reader who lands on the repository
// sees that number before any other. Flow's builder already refuses on it; a
// release where the two disagree is a release that misreports itself.
$project_readme = (string) file_get_contents( $root . '/README.md' );
if ( ! preg_match( '/\*\*Current version\*\*\s*\|\s*\[([^\]]+)\]/', $project_readme, $pm ) || $pm[1] !== $version ) {
	fwrite( STDERR, sprintf( "Current version mismatch: README.md %s vs %s.\n", isset( $pm[1] ) ? $pm[1] : '?', $version ) );
	exit( 1 );
}

$target = $root . '/dist/bg-commerce-suite-' . $version . '.zip';
if ( ! is_dir( $root . '/dist' ) && ! mkdir( $root . '/dist', 0775, true ) ) {
	fwrite( STDERR, "Cannot create dist/.\n" );
	exit( 1 );
}
if ( file_exists( $target ) ) {
	unlink( $target );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot create {$target}.\n" );
	exit( 1 );
}

$skip = static function ( $name ) use ( $exclude_names, $exclude_ext ) {
	if ( in_array( $name, $exclude_names, true ) ) {
		return true;
	}
	$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
	return '' !== $ext && in_array( $ext, $exclude_ext, true );
};

$files = 0;

$set_mtime = static function ( $name ) use ( $zip, $archive_mtime ) {
	if ( ! method_exists( $zip, 'setMtimeName' ) || ! $zip->setMtimeName( $name, $archive_mtime ) ) {
		fwrite( STDERR, "Cannot normalize ZIP timestamp for {$name}.\n" );
		exit( 1 );
	}
};

$add_dir = static function ( $abs, $rel ) use ( $zip, $skip, $set_mtime, &$add_dir, &$files ) {
	$zip->addEmptyDir( $rel );
	$set_mtime( rtrim( $rel, '/' ) . '/' );
	foreach ( scandir( $abs ) as $entry ) {
		if ( '.' === $entry || '..' === $entry || $skip( $entry ) ) {
			continue;
		}
		$child_abs = $abs . '/' . $entry;
		$child_rel = $rel . '/' . $entry;
		if ( is_dir( $child_abs ) ) {
			$add_dir( $child_abs, $child_rel );
		} else {
			$zip->addFile( $child_abs, $child_rel );
			$set_mtime( $child_rel );
			$files++;
		}
	}
};

foreach ( $include as $item ) {
	$abs = $root . '/' . $item;
	if ( ! file_exists( $abs ) ) {
		fwrite( STDERR, "Missing: {$item}\n" );
		exit( 1 );
	}
	if ( is_dir( $abs ) ) {
		$add_dir( $abs, 'bg-commerce-suite/' . $item );
	} else {
		$archive_name = 'bg-commerce-suite/' . $item;
		$zip->addFile( $abs, $archive_name );
		$set_mtime( $archive_name );
		$files++;
	}
}

$zip->close();

printf( "built %s\n  files: %d\n  size:  %s KB\n", basename( $target ), $files, number_format( filesize( $target ) / 1024, 0 ) );
printf( "  sha256: %s\n", hash_file( 'sha256', $target ) );
