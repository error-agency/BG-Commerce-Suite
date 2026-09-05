<?php
/**
 * The one reusable Core tracking-history renderer (Rule 241). Every courier
 * hands Core a Tracking_Result of raw events; nothing else in the plugin
 * builds its own timeline markup.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Admin\Order;

use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Shipping\Tracking_State;
use BgCommerce3\Shipping\Tracking_Store;

defined( 'ABSPATH' ) || exit;

final class Tracking_Timeline {

	/**
	 * @param Courier_Interface|null         $courier Courier module (used for per-event normalize_status()); null renders raw text only.
	 * @param array<int,array<string,mixed>> $events  Raw tracking events (Rule 242 — courier + occurred_at + raw code/description; missing fields are nullable).
	 */
	public static function render( $courier, array $events ) {
		if ( empty( $events ) ) {
			echo '<p class="bgcs-empty">' . esc_html__( 'There are no tracking events yet.', 'bg-commerce-suite' ) . '</p>';
			return;
		}

		echo '<ul class="bgcs-tracking">';
		// Rule 251 — actual occurred_at, newest first, never provider array order.
		foreach ( Tracking_Store::sort_by_time( $events, true ) as $event ) {
			$raw_text = isset( $event['text'] ) && '' !== $event['text'] ? (string) $event['text'] : '';
			$raw_time = isset( $event['time'] ) ? (string) $event['time'] : '';

			$state = ( $courier instanceof Courier_Interface )
				? Tracking_State::sanitize( $courier->normalize_status( (array) $event ) )
				: Tracking_State::UNKNOWN;
			$state_label = Tracking_State::label( $state );

			echo '<li>';
			echo '<time>' . esc_html( $raw_time ) . '</time>';
			// Rule 243 — raw provider text and the normalized state are shown
			// as two distinct pieces, never collapsed into one another: the
			// raw description is for the merchant/support, the normalized
			// state is what automation actually acted (or didn't act) on.
			echo '<span class="bgcs-tracking__raw">' . esc_html( '' !== $raw_text ? $raw_text : __( '(no description from the courier)', 'bg-commerce-suite' ) ) . '</span>';
			if ( '' !== $state_label ) {
				echo '<span class="bgcs-tracking__state bgcs-tone--' . esc_attr( Tracking_State::tone( $state ) ) . '">' . esc_html( $state_label ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}
}
