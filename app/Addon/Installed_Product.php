<?php
/**
 * Read-only installed/version state for a catalog product.
 *
 * The remote plugin basename is only compared with WordPress' local plugin
 * inventory. It is never included, downloaded, activated or updated here.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Addon;

defined( 'ABSPATH' ) || exit;

final class Installed_Product {

	const NOT_INSTALLED    = 'not_installed';
	const INSTALLED_LATEST = 'installed_latest';
	const UPDATE_AVAILABLE = 'update_available';
	const LOCAL_NEWER      = 'local_newer';
	const VERSION_UNKNOWN  = 'version_unknown';

	/**
	 * Resolve the exact local plugin basename for a catalog product.
	 *
	 * The feed basename wins when present. If it is omitted, a catalog id may
	 * match one and only one installed plugin directory with the same slug.
	 * This keeps product discovery generic without storing product-specific
	 * identities in Core.
	 *
	 * @param string     $plugin_file Catalog plugin basename.
	 * @param string     $catalog_id  Validated catalog product id.
	 * @param array|null $plugins     Optional inventory snapshot.
	 * @return string
	 */
	public static function resolve_plugin_file( $plugin_file, $catalog_id, $plugins = null ) {
		$plugin_file = self::plugin_basename( $plugin_file );
		if ( '' !== $plugin_file ) {
			return $plugin_file;
		}

		$catalog_id = strtolower( trim( (string) $catalog_id ) );
		if ( ! preg_match( '/^[a-z0-9._-]{1,64}$/', $catalog_id ) ) {
			return '';
		}

		$plugins    = is_array( $plugins ) ? $plugins : self::inventory();
		$candidates = array();
		foreach ( array_keys( $plugins ) as $candidate ) {
			$candidate = self::plugin_basename( $candidate );
			if ( '' !== $candidate && $catalog_id === dirname( $candidate ) ) {
				$candidates[] = $candidate;
			}
		}

		return 1 === count( $candidates ) ? $candidates[0] : '';
	}

	/**
	 * Find the single registered module whose class is owned by a plugin.
	 *
	 * Matching is based on the local class file path, not product name or remote
	 * module metadata. Ambiguous and symlinked/outside-plugin matches fail closed.
	 *
	 * @param string $plugin_file Exact installed plugin basename.
	 * @param object $registry    BGCS module registry.
	 * @return object|null
	 */
	public static function module_for_plugin( $plugin_file, $registry ) {
		$plugin_file = self::plugin_basename( $plugin_file );
		if ( '' === $plugin_file || ! defined( 'WP_PLUGIN_DIR' ) || ! is_object( $registry ) || ! method_exists( $registry, 'all' ) ) {
			return null;
		}

		$directory = dirname( $plugin_file );
		$root      = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$matches   = array();
		foreach ( (array) $registry->all() as $module ) {
			if ( ! is_object( $module ) ) {
				continue;
			}
			try {
				$class_file = ( new \ReflectionClass( $module ) )->getFileName();
			} catch ( \ReflectionException $e ) {
				continue;
			}
			$class_file = $class_file ? wp_normalize_path( $class_file ) : '';
			if ( '' === $class_file || 0 !== strpos( $class_file, $root ) ) {
				continue;
			}
			$relative = substr( $class_file, strlen( $root ) );
			$parts    = explode( '/', $relative );
			if ( isset( $parts[0] ) && $directory === $parts[0] ) {
				$matches[] = $module;
			}
		}

		return 1 === count( $matches ) ? $matches[0] : null;
	}

	/**
	 * Resolve local installation and version relation through WordPress APIs.
	 *
	 * @param string     $plugin_file    Validated plugin basename from catalog metadata.
	 * @param string     $latest         Advertised catalog version.
	 * @param bool       $installed_hint A locally registered BGCS module is present.
	 * @param array|null $plugins        Optional inventory snapshot for batch detection.
	 * @return array{installed:bool,version:string,state:string}
	 */
	public static function detect( $plugin_file, $latest, $installed_hint = false, $plugins = null ) {
		$plugin_file = self::plugin_basename( $plugin_file );
		$plugins     = is_array( $plugins ) ? $plugins : self::inventory();
		$installed   = '' !== $plugin_file && isset( $plugins[ $plugin_file ] );
		$version     = $installed && isset( $plugins[ $plugin_file ]['Version'] )
			? trim( (string) $plugins[ $plugin_file ]['Version'] )
			: '';

		if ( ! $installed && $installed_hint ) {
			$installed = true;
		}
		if ( ! $installed ) {
			return array(
				'installed' => false,
				'version'   => '',
				'state'     => self::NOT_INSTALLED,
			);
		}
		if ( '' === $version || '' === trim( (string) $latest ) ) {
			$state = self::VERSION_UNKNOWN;
		} elseif ( version_compare( $version, (string) $latest, '<' ) ) {
			$state = self::UPDATE_AVAILABLE;
		} elseif ( version_compare( $version, (string) $latest, '>' ) ) {
			$state = self::LOCAL_NEWER;
		} else {
			$state = self::INSTALLED_LATEST;
		}

		return array(
			'installed' => true,
			'version'   => $version,
			'state'     => $state,
		);
	}

	/** Load one WordPress plugin inventory snapshot for a catalog render. */
	public static function inventory() {
		if ( ! function_exists( 'get_plugins' ) ) {
			$wp_plugin_api_file = trailingslashit( ABSPATH ) . 'wp-admin/includes/plugin.php';
			if ( is_readable( $wp_plugin_api_file ) ) {
				require_once $wp_plugin_api_file;
			}
		}
		$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		return is_array( $plugins ) ? $plugins : array();
	}

	/** Return a safe canonical plugin basename or an empty string. */
	private static function plugin_basename( $value ) {
		$value = function_exists( 'wp_normalize_path' )
			? wp_normalize_path( (string) $value )
			: str_replace( '\\', '/', (string) $value );
		$value = strtolower( ltrim( trim( $value ), '/' ) );
		return preg_match( '~^[a-z0-9._-]+/[a-z0-9._-]+\.php$~', $value ) ? $value : '';
	}
}
