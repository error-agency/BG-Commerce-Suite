<?php
/**
 * Декларативна регистрация на BG Commerce Suite add-on модул.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Addon;

use BgCommerce3\Autoloader;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	/**
	 * Регистрира namespace и module factory от add-on manifest.
	 *
	 * Задължителни ключове: namespace, path и module_class.
	 *
	 * @param array<string,string> $manifest Add-on manifest.
	 * @return bool
	 */
	public static function register( array $manifest ) {
		$namespace    = isset( $manifest['namespace'] ) ? trim( (string) $manifest['namespace'] ) : '';
		$path         = isset( $manifest['path'] ) ? trim( (string) $manifest['path'] ) : '';
		$module_class = isset( $manifest['module_class'] ) ? trim( (string) $manifest['module_class'] ) : '';

		if ( '' === $namespace || '' === $path || '' === $module_class ) {
			return false;
		}

		Autoloader::add_namespace( $namespace, $path );

		add_action(
			'bgcs3_register_modules',
			static function ( $registry ) use ( $module_class ) {
				$registry->add( new $module_class() );
			},
			10,
			1
		);

		return true;
	}

	/**
	 * Пълно закачане на add-on: регистрация на модула плюс стандартните
	 * презентационни и COD куки, които иначе всеки add-on преписва.
	 *
	 * Освен ключовете на `register()` приема:
	 *   slug         — идентификатор на модула (таб id / courier id);
	 *   logo         — URL към логото на куриера;
	 *   icon         — име на икона от `Admin\Icons` за таба в настройките;
	 *   cod_gateways — собствени gateway id-та, които са наложен платеж.
	 *
	 * @param array<string,mixed> $manifest Add-on manifest.
	 * @return bool
	 */
	public static function boot( array $manifest ) {
		if ( ! self::register( $manifest ) ) {
			return false;
		}

		$slug = isset( $manifest['slug'] ) ? (string) $manifest['slug'] : '';
		$logo = isset( $manifest['logo'] ) ? (string) $manifest['logo'] : '';
		$icon = isset( $manifest['icon'] ) ? (string) $manifest['icon'] : '';
		$cod  = isset( $manifest['cod_gateways'] ) ? array_map( 'strval', (array) $manifest['cod_gateways'] ) : array();

		if ( '' !== $slug && '' !== $logo ) {
			add_filter(
				'bgcs3_courier_logo_url',
				static function ( $url, $courier_id ) use ( $slug, $logo ) {
					return ( $slug === $courier_id ) ? $logo : $url;
				},
				10,
				2
			);
		}

		if ( '' !== $slug && '' !== $icon ) {
			add_filter(
				'bgcs3_module_icon',
				static function ( $current, $tab_id ) use ( $slug, $icon ) {
					return ( $slug === $tab_id ) ? $icon : $current;
				},
				10,
				2
			);
		}

		if ( $cod ) {
			add_filter(
				'bgcs3_cod_payment_methods',
				static function ( $methods ) use ( $cod, $slug ) {
					// Presentation metadata may exist for every catalog module, but
					// disabled modules must not alter checkout/runtime behaviour.
					if ( '' === $slug || 'yes' !== bgcs3_get_option( $slug, 'enable', 'no' ) ) {
						return (array) $methods;
					}
					return array_values( array_unique( array_merge( (array) $methods, $cod ) ) );
				}
			);
		}

		return true;
	}
}
