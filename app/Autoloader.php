<?php
/**
 * Minimal PSR-4 autoloader.
 *
 * Core registers `BgCommerce3\` → /app. DLC add-on plugins register their own
 * namespace roots via add_namespace(), so their classes can live in their own
 * plugin directory while keeping the same namespace.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3;

defined( 'ABSPATH' ) || exit;

class Autoloader {

	const PREFIX = 'BgCommerce3\\';

	/**
	 * Namespace prefix => list of base directories.
	 *
	 * @var array<string,string[]>
	 */
	private static $namespaces = array();

	/**
	 * Register the SPL autoloader and the core namespace root.
	 */
	public static function register() {
		self::add_namespace( self::PREFIX, __DIR__ );
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Register an additional namespace root (used by DLC add-ons).
	 *
	 * Example (in an add-on plugin):
	 *   \BgCommerce3\Autoloader::add_namespace(
	 *       'BgCommerce3\\Modules\\Shipping\\Speedy\\',
	 *       __DIR__ . '/app/Modules/Shipping/Speedy'
	 *   );
	 *
	 * @param string $prefix   Namespace prefix (trailing backslash optional).
	 * @param string $base_dir Directory that maps to the prefix.
	 */
	public static function add_namespace( $prefix, $base_dir ) {
		$prefix   = trim( (string) $prefix, '\\' ) . '\\';
		$base_dir = rtrim( (string) $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;

		if ( ! isset( self::$namespaces[ $prefix ] ) ) {
			self::$namespaces[ $prefix ] = array();
		}
		self::$namespaces[ $prefix ][] = $base_dir;

		// Longest prefix first, so add-on roots win over the generic core root.
		uksort(
			self::$namespaces,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
	}

	/**
	 * Map a fully-qualified class name to a file and require it.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	public static function autoload( $class ) {
		foreach ( self::$namespaces as $prefix => $dirs ) {
			if ( 0 !== strpos( $class, $prefix ) ) {
				continue;
			}

			$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, strlen( $prefix ) ) );

			foreach ( $dirs as $dir ) {
				$file = $dir . $relative . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
					return;
				}
			}
		}
	}
}
