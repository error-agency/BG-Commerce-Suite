<?php
/**
 * Safe HTML presenter for non-selectable courier availability cards.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Checkout;

defined( 'ABSPATH' ) || exit;

final class Availability_Presenter {

	/**
	 * @param array<int,array<string,mixed>> $rows Public availability rows.
	 * @return string
	 */
	public static function cards_html( array $rows ) {
		$html = '';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$status  = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
			$message = isset( $row['customer_message'] ) ? trim( (string) $row['customer_message'] ) : '';
			if ( ! in_array( $status, array( 'pending', 'unavailable', 'temporary_error', 'error' ), true ) || '' === $message ) {
				continue;
			}

			$courier = isset( $row['courier'] ) ? sanitize_key( (string) $row['courier'] ) : '';
			$name    = isset( $row['courier_name'] ) ? trim( (string) $row['courier_name'] ) : $courier;
			$code    = isset( $row['code'] ) ? sanitize_key( (string) $row['code'] ) : 'shipping_unavailable';
			$role    = in_array( $status, array( 'temporary_error', 'error' ), true ) ? 'alert' : 'status';

			$html .= '<div class="bgcs-availability-card bgcs-availability-card--' . esc_attr( $status ) . '" role="' . esc_attr( $role ) . '" aria-disabled="true" data-bgcs-availability-card data-courier="' . esc_attr( $courier ) . '" data-status="' . esc_attr( $status ) . '" data-code="' . esc_attr( $code ) . '">';
			$html .= '<div class="bgcs-availability-card__title">' . esc_html( $name ) . '</div>';
			$html .= '<div class="bgcs-availability-card__message">' . esc_html( $message ) . '</div>';
			$html .= '</div>';
		}

		return $html;
	}
}
