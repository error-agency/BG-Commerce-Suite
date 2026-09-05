<?php
/** BGCS shipment-created customer email. */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( __( 'A shipment label has been created for your order with %s.', 'bg-commerce-suite' ), $courier_name ) ); ?></p>
<p><strong><?php esc_html_e( 'Tracking number:', 'bg-commerce-suite' ); ?></strong> <?php echo esc_html( $waybill_number ); ?></p>
<?php if ( $tracking_url ) : ?>
<p><a href="<?php echo esc_url( $tracking_url ); ?>"><?php esc_html_e( 'Track shipment', 'bg-commerce-suite' ); ?></a></p>
<?php endif; ?>
<?php
if ( $order instanceof \WC_Order ) {
	do_action( 'woocommerce_email_order_details', $order, false, false, $email );
	do_action( 'woocommerce_email_order_meta', $order, false, false, $email );
	do_action( 'woocommerce_email_customer_details', $order, false, false, $email );
}
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
