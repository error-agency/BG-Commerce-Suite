<?php
/** Plain BGCS shipment-created customer email. */
defined( 'ABSPATH' ) || exit;

echo "==========\n";
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text email output is stripped of all markup.
echo wp_strip_all_tags( $email_heading ) . "\n";
echo "==========\n\n";
/* translators: %s: courier name. */
$bgcs3_shipment_message = sprintf( __( 'A shipment label has been created for your order with %s.', 'bg-commerce-suite' ), $courier_name );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text email output is stripped of all markup.
echo wp_strip_all_tags( $bgcs3_shipment_message );
echo "\n";
/* translators: %s: shipment tracking number. */
$bgcs3_tracking_number_message = sprintf( __( 'Tracking number: %s', 'bg-commerce-suite' ), $waybill_number );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text email output is stripped of all markup.
echo wp_strip_all_tags( $bgcs3_tracking_number_message );
echo "\n";
if ( $tracking_url ) {
	/* translators: %s: shipment tracking URL. */
	$bgcs3_tracking_url_message = sprintf( __( 'Tracking: %s', 'bg-commerce-suite' ), esc_url_raw( $tracking_url ) );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text email output is stripped of all markup.
	echo wp_strip_all_tags( $bgcs3_tracking_url_message );
	echo "\n";
}
echo "\n";
if ( $order instanceof \WC_Order ) {
	do_action( 'woocommerce_email_order_details', $order, false, true, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Required WooCommerce email template hook.
	do_action( 'woocommerce_email_order_meta', $order, false, true, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Required WooCommerce email template hook.
	do_action( 'woocommerce_email_customer_details', $order, false, true, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Required WooCommerce email template hook.
}
if ( $additional_content ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text email output is stripped of all markup.
	echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
