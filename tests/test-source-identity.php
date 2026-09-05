<?php
/**
 * Verify that shipped source uses the BG Commerce Suite identity contract.
 *
 * Carrier names and official carrier endpoints are part of the product domain
 * and are intentionally allowed. This test validates ownership positively: PHP
 * namespaces, translation domain and external hosts must all be declared here.
 *
 * Run: php tests/test-source-identity.php
 *
 * @package BgCommerce3
 */

$root     = dirname( __DIR__ );
$failures = 0;

function check_source_identity( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		++$failures;
	}
}

$php_files         = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/app' ) );
$namespace_errors  = array();
$text_domain_errors = array();
$translation_calls = 0;

foreach ( $php_files as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$path = $file->getPathname();
	$rel  = str_replace( array( $root . '/', $root . '\\', '\\' ), array( '', '', '/' ), $path );
	$text = file_get_contents( $path );

	if ( preg_match_all( '/^\s*namespace\s+([^;]+);/m', $text, $matches ) ) {
		foreach ( $matches[1] as $namespace ) {
			if ( 'BgCommerce3' !== $namespace && 0 !== strpos( $namespace, 'BgCommerce3\\' ) ) {
				$namespace_errors[] = $rel . ': ' . $namespace;
			}
		}
	}

	$pattern = '/\b(?:__|_e|_x|_ex|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\s*\(\s*[\'\"][^\'\"]*[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]/s';
	if ( preg_match_all( $pattern, $text, $matches ) ) {
		foreach ( $matches[1] as $domain ) {
			++$translation_calls;
			if ( ! in_array( $domain, array( 'bg-commerce-suite', 'woocommerce' ), true ) ) {
				$text_domain_errors[] = $rel . ': ' . $domain;
			}
		}
	}
}

check_source_identity( array() === $namespace_errors, 'All application namespaces are owned by BgCommerce3' . ( $namespace_errors ? ': ' . implode( ', ', $namespace_errors ) : '' ) );
check_source_identity( $translation_calls > 100, 'Translation calls were found and inspected: ' . $translation_calls );
check_source_identity( array() === $text_domain_errors, 'All inspected translation calls use the product or WooCommerce text domain' . ( $text_domain_errors ? ': ' . implode( ', ', $text_domain_errors ) : '' ) );

$bootstrap = file_get_contents( $root . '/bg-commerce-suite.php' );
check_source_identity( false !== strpos( $bootstrap, 'Text Domain:       bg-commerce-suite' ), 'The plugin header declares the BG Commerce Suite text domain' );
check_source_identity( false !== strpos( $bootstrap, "define( 'BGCS3_VERSION'" ), 'The runtime version constant uses the BGCS3 prefix' );
check_source_identity( false === strpos( $bootstrap, "'cod_gateways'" ), 'Bundled courier manifests do not preload payment gateway ids' );

$courier_registration_errors = array();
$courier_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/app/Modules/Shipping' ) );
foreach ( $courier_files as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	if ( false !== strpos( file_get_contents( $file->getPathname() ), "add_filter( 'bgcs3_cod_payment_methods'" ) ) {
		$rel = str_replace( array( $root . '/', $root . '\\', '\\' ), array( '', '', '/' ), $file->getPathname() );
		if ( 'app/Modules/Shipping/BoxNow/BoxNow.php' !== $rel ) {
			$courier_registration_errors[] = $rel;
		}
	}
}
check_source_identity( array() === $courier_registration_errors, 'Courier payment registration stays within the owned BOX NOW integration' . ( $courier_registration_errors ? ': ' . implode( ', ', $courier_registration_errors ) : '' ) );

$allowed_hosts = array(
	'api-demo.pigeonexpress.com',
	'api-production.boxnow.bg',
	'api-stage.boxnow.bg',
	'api.pigeonexpress.com',
	'api.speedy.bg',
	'bugs.chromium.org',
	'bugzilla.mozilla.org',
	'demo.econt.com',
	'ee.econt.com',
	'error.bg',
	'github.com',
	'leafletjs.com',
	'locationapi-production.boxnow.bg',
	'locationapi-stage.boxnow.bg',
	'map.boxnow.bg',
	't.boxnow.bg',
	'tile.openstreetmap.org',
	'widget-v5.boxnow.bg',
	'www.econt.com',
	'www.gnu.org',
	'www.openstreetmap.org',
	'www.speedy.bg',
	'www.w3.org',
);

$scan_dirs  = array( 'app', 'assets', 'languages', 'templates' );
$scan_files = array( 'bg-commerce-suite.php', 'CHANGELOG.md', 'readme.txt', 'uninstall.php' );
$targets    = array();

foreach ( $scan_dirs as $dir ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && preg_match( '/\.(?:php|js|css|json|txt|po|pot|svg)$/i', $file->getFilename() ) ) {
			$targets[] = $file->getPathname();
		}
	}
}
foreach ( $scan_files as $file ) {
	$targets[] = $root . '/' . $file;
}

$unknown_hosts = array();
foreach ( $targets as $path ) {
	$text = file_get_contents( $path );
	if ( ! preg_match_all( '~https?://([A-Za-z0-9.-]+)~i', $text, $matches ) ) {
		continue;
	}
	foreach ( $matches[1] as $host ) {
		$host = strtolower( $host );
		if ( ! in_array( $host, $allowed_hosts, true ) ) {
			$unknown_hosts[] = str_replace( array( $root . '/', $root . '\\', '\\' ), array( '', '', '/' ), $path ) . ': ' . $host;
		}
	}
}

check_source_identity( array() === $unknown_hosts, 'Every shipped external host is explicitly owned or required by the product' . ( $unknown_hosts ? ': ' . implode( ', ', array_unique( $unknown_hosts ) ) : '' ) );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} source identity check(s)" . PHP_EOL;
	exit( 1 );
}

echo 'OK - shipped source follows the BG Commerce Suite identity contract' . PHP_EOL;
