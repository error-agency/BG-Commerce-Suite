<?php
/**
 * Общ resolver за tri-state финансови override-и (наложен платеж, обявена стойност
 * и др.) — празно поле никога не означава „изключено“; само изричен `disabled`
 * mode го прави.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Overrides {

	const INHERIT  = 'inherit';
	const CUSTOM   = 'custom';
	const DISABLED = 'disabled';

	/**
	 * Валидните mode стойности.
	 *
	 * @return string[]
	 */
	public static function modes() {
		return array( self::INHERIT, self::CUSTOM, self::DISABLED );
	}

	/**
	 * Разпознава валиден mode от waybill override данните, INHERIT по подразбиране.
	 *
	 * @param array<string,mixed> $wb  Waybill override данни (_bgcs3_wb).
	 * @param string              $key Ключ на mode полето, напр. 'cod_mode'.
	 * @return string INHERIT|CUSTOM|DISABLED
	 */
	public static function mode( array $wb, $key ) {
		$mode = isset( $wb[ $key ] ) ? (string) $wb[ $key ] : self::INHERIT;
		return in_array( $mode, self::modes(), true ) ? $mode : self::INHERIT;
	}

	/**
	 * Разрешава финансов override според tri-state семантика:
	 *
	 * - INHERIT  -> $default (canonical стойност, напр. Cod::amount( $order )).
	 * - CUSTOM   -> стойността от $value полето, ако е налична и непразна; иначе $default.
	 * - DISABLED -> 0.0, независимо от съдържанието на value полето.
	 *
	 * Никога: празно value поле да означава DISABLED — само изричният mode го прави.
	 * Стар персистиран `_bgcs3_wb` без mode ключ (легаси записи отпреди tri-state)
	 * винаги се третира като INHERIT, така че вече записан празен override повече
	 * не гаси финансовото намерение мълчаливо.
	 *
	 * @param array<string,mixed> $wb        Waybill override данни (_bgcs3_wb).
	 * @param string              $mode_key  Ключ на mode полето, напр. 'cod_mode'.
	 * @param string              $value_key Ключ на стойността, напр. 'cod_amount'.
	 * @param float               $default   Canonical стойност при INHERIT.
	 * @return float
	 */
	public static function resolve( array $wb, $mode_key, $value_key, $default ) {
		$mode = self::mode( $wb, $mode_key );

		if ( self::DISABLED === $mode ) {
			return 0.0;
		}

		if ( self::CUSTOM === $mode && isset( $wb[ $value_key ] ) && '' !== $wb[ $value_key ] ) {
			return (float) $wb[ $value_key ];
		}

		return (float) $default;
	}
}
