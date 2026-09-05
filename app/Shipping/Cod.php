<?php
/**
 * Наложен платеж — единно разпознаване за всички модули.
 *
 * Списъкът с платежни методи, които се броят за наложен платеж, се разширява от
 * add-on-ите през филтъра `bgcs3_cod_payment_methods` (всеки куриер със собствен
 * gateway добавя своя идентификатор там).
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Cod {

	/**
	 * Идентификаторите на платежните методи, които се третират като наложен платеж.
	 *
	 * @return string[]
	 */
	public static function methods() {
		/**
		 * Payment-method ids treated as cash-on-delivery.
		 *
		 * @param string[] $methods Payment method ids.
		 */
		$methods = (array) apply_filters( 'bgcs3_cod_payment_methods', array( 'cod' ) );

		return array_values( array_unique( array_map( 'strval', $methods ) ) );
	}

	/**
	 * @param string $method_id Payment method id.
	 * @return bool
	 */
	public static function is_method( $method_id ) {
		return in_array( (string) $method_id, self::methods(), true );
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_order( \WC_Order $order ) {
		return self::is_method( $order->get_payment_method() );
	}

	/**
	 * Дали клиентът в момента е избрал наложен платеж на checkout.
	 *
	 * Подаденото поле бие сесията — при изпращане на поръчката сесията още може
	 * да пази предишния избор.
	 *
	 * @return bool
	 */
	public static function is_chosen() {
		return self::is_method( self::chosen_method() );
	}

	/**
	 * Каноничният payment-method id от текущия checkout request/session.
	 *
	 * @return string
	 */
	public static function chosen_method() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only проверка на текущия избор; nonce се проверява от WooCommerce.
		$chosen = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
		if ( '' === $chosen && isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
			$posted = array();
			// BGCS-AUDIT-011 — WordPress slashes the whole $_POST superglobal, so
			// the value must be unslashed BEFORE parsing; sanitizing the extracted
			// value below stays as it is (wp_unslash → sanitize, never the reverse).
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verifies the checkout nonce; the extracted payment method is sanitized below.
			parse_str( wp_unslash( $_POST['post_data'] ), $posted );
			if ( ! empty( $posted['payment_method'] ) && is_string( $posted['payment_method'] ) ) {
				$chosen = sanitize_text_field( $posted['payment_method'] );
			}
		}

		if ( '' === $chosen && function_exists( 'WC' ) && WC()->session ) {
			$chosen = (string) WC()->session->get( 'chosen_payment_method' );
		}

		return (string) $chosen;
	}

	/**
	 * Сумата за събиране от куриера — целият остатък по поръчката при наложен
	 * платеж, иначе нула.
	 *
	 * @param \WC_Order $order Order.
	 * @return float
	 */
	public static function amount( \WC_Order $order ) {
		return self::is_order( $order ) ? self::normalize_amount( $order->get_total() ) : 0.0;
	}

	/**
	 * Разрешава сумата за наложен платеж, взимайки предвид admin override-а в
	 * waybill панела (tri-state: INHERIT/CUSTOM/DISABLED — виж {@see Overrides}).
	 *
	 * Празно поле в панела НЕ означава изключен НП — само изричен `cod_mode`
	 * `disabled` го прави. Curier add-on-ите трябва да викат този метод вместо
	 * да проверяват `array_key_exists( 'cod_amount', $wb )` сами.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string,mixed>  $wb    Waybill override данни (_bgcs3_wb).
	 * @return float
	 */
	public static function resolve_amount( \WC_Order $order, array $wb ) {
		return self::normalize_amount( Overrides::resolve( $wb, 'cod_mode', 'cod_amount', self::amount( $order ) ) );
	}

	/**
	 * Normalize a collectable amount once, at the shared financial boundary.
	 *
	 * Courier modules must never disagree about negative manual values or carry
	 * more precision than WooCommerce can charge in the order currency.
	 *
	 * @param mixed $amount Raw amount.
	 * @return float
	 */
	private static function normalize_amount( $amount ) {
		$precision = function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;

		return round( max( 0.0, (float) $amount ), $precision );
	}
}
