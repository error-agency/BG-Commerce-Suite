<?php
/**
 * Централизирано разпознаване на админ екраните за BGCS asset guards.
 *
 * Обединява повтарящите се комбинации от `get_current_screen()`, `$_GET['page']`
 * и `$_GET['tab']`, използвани от Core и add-on-ите, за да решат кога да
 * enqueue-нат своите admin assets. Всеки предикат възпроизвежда точно един
 * съществуващ guard — не разширява и не стеснява кои екрани зареждат assets.
 *
 * @package BgCommerce3\Admin
 */

namespace BgCommerce3\Admin;

defined( 'ABSPATH' ) || exit;

final class Admin_Screen {

	/** Settings page slug (Core settings tab shell). */
	const SETTINGS_SLUG = 'bgcs3-settings';

	/**
	 * Current admin screen id ('' when unavailable).
	 *
	 * @return string
	 */
	private static function screen_id() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen ? (string) $screen->id : '';
	}

	/**
	 * Single order edit screen — classic `shop_order` и HPOS `woocommerce_page_wc-orders`.
	 *
	 * @return bool
	 */
	public static function is_order() {
		return in_array( self::screen_id(), array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	/**
	 * Кой да е order екран — единичен или списък, класически или HPOS.
	 *
	 * @return bool
	 */
	public static function is_any_order() {
		$id = self::screen_id();
		return in_array( $id, array( 'shop_order', 'edit-shop_order' ), true ) || false !== strpos( $id, 'wc-orders' );
	}

	/**
	 * На BGCS settings страницата (по избор — конкретен таб).
	 *
	 * @param string|null $tab         Изисквай този таб, ако е подаден.
	 * @param bool        $allow_empty Приемай и празен таб (settings landing).
	 * @return bool
	 */
	public static function is_bgcs3_settings( $tab = null, $allow_empty = false ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen guard.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SETTINGS_SLUG !== $page ) {
			return false;
		}
		if ( null === $tab ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen guard.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return $current === $tab || ( $allow_empty && '' === $current );
	}
}
