<?php
/**
 * Версия на плъгина, който доставя даден модул.
 *
 * Модулите не носят собствена версия — истината е в header-а на add-on плъгина.
 * Затова тръгваме от файла на класа, намираме в коя папка на plugins/ живее и
 * четем версията от главния му файл. Така работи за всеки add-on, включително
 * бъдещи и чужди, без те да променят нищо.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Admin;

use BgCommerce3\Module\Module_Interface;

defined( 'ABSPATH' ) || exit;

final class Module_Version {

	/**
	 * Кеш по клас на модула — един админ екран пита многократно.
	 *
	 * @var array<string,string>
	 */
	private static $cache = array();

	/**
	 * @param Module_Interface $module Модул.
	 * @return string Версия или '' ако не може да се определи.
	 */
	public static function get( $module ) {
		$class = get_class( $module );

		if ( isset( self::$cache[ $class ] ) ) {
			return self::$cache[ $class ];
		}

		self::$cache[ $class ] = self::resolve( $class );

		return self::$cache[ $class ];
	}

	/**
	 * @param string $class Клас на модула.
	 * @return string
	 */
	private static function resolve( $class ) {
		$directory = self::plugin_directory( $class );

		if ( '' === $directory ) {
			return '';
		}

		// Модул, доставен от самия Core.
		if ( dirname( plugin_basename( BGCS3_FILE ) ) === $directory ) {
			return (string) BGCS3_VERSION;
		}

		$main_file = self::main_plugin_file( $directory );

		if ( '' === $main_file ) {
			return '';
		}

		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $main_file, false, false );

		return isset( $data['Version'] ) ? (string) $data['Version'] : '';
	}

	/**
	 * Папката в `plugins/`, в която живее класът на модула.
	 *
	 * @param string $class Клас на модула.
	 * @return string
	 */
	private static function plugin_directory( $class ) {
		try {
			$file = ( new \ReflectionClass( $class ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			return '';
		}

		if ( ! $file ) {
			return '';
		}

		$file      = wp_normalize_path( $file );
		$plugins   = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$is_inside = 0 === strpos( $file, $plugins );

		if ( ! $is_inside ) {
			// Напр. must-use плъгин или symlink извън plugins/.
			return '';
		}

		$relative = substr( $file, strlen( $plugins ) );
		$parts    = explode( '/', $relative );

		return isset( $parts[0] ) ? $parts[0] : '';
	}

	/**
	 * Главният файл на плъгина в дадената папка.
	 *
	 * @param string $directory Папка в `plugins/`.
	 * @return string plugin_basename или ''.
	 */
	private static function main_plugin_file( $directory ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( 0 === strpos( $plugin_file, $directory . '/' ) ) {
				return $plugin_file;
			}
		}

		return '';
	}
}
