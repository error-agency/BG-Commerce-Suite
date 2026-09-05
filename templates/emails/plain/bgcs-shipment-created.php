<?php
/** Plain BGCS shipment-created customer email. */
defined( 'ABSPATH' ) || exit;

echo "==========\n";
echo wp_strip_all_tags( $email_heading ) . "\n";
echo "==========\n\n";
printf( __( 'A shipment label has been created for your order with %s.', 'bg-commerce-suite' ), $courier_name );
echo "\n";
printf( __( 'Tracking number: %s', 'bg-commerce-suite' ), $waybill_number );
echo "\n";
if ( $tracking_url ) {
	printf( __( 'Tracking: %s', 'bg-commerce-suite' ), $tracking_url );
	echo "\n";
}
echo "\n";
if ( $order instanceof \WC_Order ) {
	do_action( 'woocommerce_email_order_details', $order, false, true, $email );
	do_action( 'woocommerce_email_order_meta', $order, false, true, $email );
	do_action( 'woocommerce_email_customer_details', $order, false, true, $email );
}
if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
