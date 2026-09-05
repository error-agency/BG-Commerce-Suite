<?php
/**
 * Offline regression checks for non-selectable courier availability cards.
 *
 * @package BgCommerce3
 */

define( 'ABSPATH', __DIR__ );
define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }

function bgcs_presenter_check( $label, $condition ) {
	static $failures = 0;
	if ( null === $label ) {
		return $failures;
	}
	if ( ! $condition ) {
		++$failures;
	}
	printf( "  [%s] %s\n", $condition ? 'PASS' : 'FAIL', $label );
	return $condition;
}

$presenter_file = BGCS3_PATH . 'app/Checkout/Availability_Presenter.php';
if ( ! is_file( $presenter_file ) ) {
	fwrite( STDERR, "FAIL: checkout Availability_Presenter is missing.\n" );
	exit( 1 );
}
require $presenter_file;

use BgCommerce3\Checkout\Availability_Presenter;

$html = Availability_Presenter::cards_html(
	array(
		array(
			'courier'          => 'boxnow',
			'courier_name'     => 'BOX NOW',
			'status'           => 'unavailable',
			'code'             => 'boxnow_product_oversize',
			'customer_message' => 'Продуктът „Ergonomic Bronze Lamp“ надвишава максималните размери.',
			'technical_message' => 'internal product=42 normalized=42x48x67',
		),
	)
);

echo "--- Availability card is informative but never a WooCommerce rate ---\n";
bgcs_presenter_check( 'Courier and customer-safe explanation are rendered', false !== strpos( $html, 'BOX NOW' ) && false !== strpos( $html, 'Ergonomic Bronze Lamp' ) );
bgcs_presenter_check( 'Card is explicitly disabled', false !== strpos( $html, 'aria-disabled="true"' ) );
bgcs_presenter_check( 'Card contains no selectable shipping_method control', false === strpos( $html, 'name="shipping_method' ) && false === strpos( $html, '<input' ) );
bgcs_presenter_check( 'Technical diagnostics are not rendered', false === strpos( $html, 'internal product=42' ) );
bgcs_presenter_check( 'Machine state and code are available to Flow/JS', false !== strpos( $html, 'data-status="unavailable"' ) && false !== strpos( $html, 'data-code="boxnow_product_oversize"' ) );

$failures = bgcs_presenter_check( null, true );
if ( $failures > 0 ) {
	fwrite( STDERR, "Availability presenter checks failed: {$failures}.\n" );
	exit( 1 );
}

echo "Availability presenter checks passed.\n";

