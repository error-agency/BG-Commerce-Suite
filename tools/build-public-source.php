<?php
/**
 * Export the reviewed public source surface without private Git history.
 *
 * Usage: php tools/build-public-source.php
 *
 * @package BgCommerce3
 */

$root     = dirname( __DIR__ );
$manifest = require __DIR__ . '/release-manifest.php';
$include  = isset( $manifest['public_source'] ) ? $manifest['public_source'] : array();
$dist     = $root . '/dist/public-source';
$target   = $dist . '/BG-Commerce-Suite';

if ( ! is_array( $include ) || ! $include ) {
	fwrite( STDERR, "The public source manifest is empty.\n" );
	exit( 1 );
}

$remove_tree = static function ( $path ) use ( &$remove_tree ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		return unlink( $path );
	}
	if ( ! is_dir( $path ) ) {
		return true;
	}
	foreach ( scandir( $path ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		if ( ! $remove_tree( $path . '/' . $entry ) ) {
			return false;
		}
	}
	return rmdir( $path );
};

$copy_tree = static function ( $source, $destination ) use ( &$copy_tree ) {
	if ( is_link( $source ) ) {
		fwrite( STDERR, "Symbolic links are not allowed in the public snapshot: {$source}\n" );
		return false;
	}
	if ( is_file( $source ) ) {
		$parent = dirname( $destination );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0775, true ) ) {
			return false;
		}
		return copy( $source, $destination );
	}
	if ( ! is_dir( $source ) ) {
		return false;
	}
	if ( ! is_dir( $destination ) && ! mkdir( $destination, 0775, true ) ) {
		return false;
	}
	foreach ( scandir( $source ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		if ( ! $copy_tree( $source . '/' . $entry, $destination . '/' . $entry ) ) {
			return false;
		}
	}
	return true;
};

$normalized_dist   = str_replace( '\\', '/', $dist );
$normalized_target = str_replace( '\\', '/', $target );
if ( 0 !== strpos( $normalized_target, rtrim( $normalized_dist, '/' ) . '/' ) ) {
	fwrite( STDERR, "Refusing to clean a target outside dist/public-source.\n" );
	exit( 1 );
}

if ( is_dir( $target ) && ! $remove_tree( $target ) ) {
	fwrite( STDERR, "Cannot replace the previous public snapshot.\n" );
	exit( 1 );
}
if ( ! is_dir( $target ) && ! mkdir( $target, 0775, true ) ) {
	fwrite( STDERR, "Cannot create the public snapshot directory.\n" );
	exit( 1 );
}

$files = 0;
foreach ( $include as $item ) {
	$item = str_replace( '\\', '/', (string) $item );
	if ( '' === $item || false !== strpos( $item, '..' ) || '/' === $item[0] ) {
		fwrite( STDERR, "Unsafe public manifest entry: {$item}\n" );
		exit( 1 );
	}
	$source = $root . '/' . $item;
	if ( ! file_exists( $source ) ) {
		fwrite( STDERR, "Missing public manifest entry: {$item}\n" );
		exit( 1 );
	}
	if ( ! $copy_tree( $source, $target . '/' . $item ) ) {
		fwrite( STDERR, "Cannot export: {$item}\n" );
		exit( 1 );
	}
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	if ( $file->isFile() ) {
		++$files;
	}
}

printf( "exported dist/public-source/BG-Commerce-Suite\n  files: %d\n", $files );
