<?php
/**
 * Offline checks for the courier ETA normalization and integration contract.
 *
 * @package BgCommerce3
 */

define( 'ABSPATH', __DIR__ );
define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

// Europe/Sofia and d.m.Y / H:i represent a Bulgarian WooCommerce store.
// Without the timezone the midnight-timestamp rule below cannot be reproduced
// offline: the same Econt value lands on 21:00 of the previous day in UTC.
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( 'Europe/Sofia' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

require_once BGCS3_PATH . 'app/Shipping/Delivery_Estimate.php';

use BgCommerce3\Shipping\Delivery_Estimate;

$failures = 0;

function bgcs_eta_check( $name, $condition ) {
	global $failures;
	if ( $condition ) {
		printf( "  [PASS] %s\n", $name );
		return;
	}
	++$failures;
	printf( "  [FAIL] %s\n", $name );
}

echo "Courier delivery estimate contract\n";

$date = Delivery_Estimate::normalize( '2026-09-05', 'econt' );
bgcs_eta_check( 'Date-only ETA remains date-only', 'date' === $date['precision'] && '2026-09-05' === $date['value'] );
bgcs_eta_check( 'Date-only ETA uses the shop display fallback', '05.09.2026' === Delivery_Estimate::format( $date ) );

$deadline = Delivery_Estimate::normalize( '2026-09-05T14:30:00+03:00', 'speedy' );
bgcs_eta_check( 'Timestamp ETA preserves an absolute deadline', 'datetime' === $deadline['precision'] && 'speedy' === $deadline['courier'] );
// Verified against the live shop on 2026-09-04: Econt's expectedDeliveryDate
// arrives as a millisecond timestamp sitting on local midnight. Reporting it as
// a datetime promised the customer "05.09.2026 00:00".
$econt_milliseconds = Delivery_Estimate::normalize( '1788555600000', 'econt' );
bgcs_eta_check(
	'Econt millisecond timestamps are normalized as real dates',
	'2026-09-05' === $econt_milliseconds['value']
);
bgcs_eta_check(
	'A midnight courier timestamp is a day, not an appointment',
	'date' === $econt_milliseconds['precision']
);
bgcs_eta_check(
	'…so no meaningless 00:00 is shown to the customer',
	'05.09.2026' === Delivery_Estimate::format( $econt_milliseconds )
);
bgcs_eta_check(
	'A timestamp with a real time of day stays a datetime',
	'datetime' === Delivery_Estimate::normalize( '1788606000', 'econt' )['precision']
);
bgcs_eta_check(
	'An ISO string that spells out midnight is left as the courier stated it',
	'datetime' === Delivery_Estimate::normalize( '2026-09-05T00:00:00+03:00', 'speedy' )['precision']
);
bgcs_eta_check(
	'A date-precision estimate survives the persist/restore round trip',
	$econt_milliseconds === Delivery_Estimate::sanitize( $econt_milliseconds )
);
bgcs_eta_check( 'Invalid provider values fail closed', array() === Delivery_Estimate::normalize( 'not-a-date', 'speedy' ) );
bgcs_eta_check( 'Relative or tampered persisted values fail closed', '' === Delivery_Estimate::format( array( 'value' => 'tomorrow', 'precision' => 'datetime', 'courier' => 'speedy' ) ) );

$order = new class( $date, $deadline ) {
	private $quote;
	private $label;
	public function __construct( $quote, $label ) {
		$this->quote = $quote;
		$this->label = array( 'meta' => array( 'delivery_estimate' => $label ) );
	}
	public function get_meta( $key ) {
		return '_bgcs3_label' === $key ? $this->label : $this->quote;
	}
};
bgcs_eta_check( 'A newer shipment-label ETA wins over the checkout snapshot', $deadline === Delivery_Estimate::for_order( $order ) );

$speedy = file_get_contents( BGCS3_PATH . 'app/Modules/Shipping/Speedy/Speedy.php' );
$econt  = file_get_contents( BGCS3_PATH . 'app/Modules/Shipping/Econt/Econt.php' );
$boxnow = file_get_contents( BGCS3_PATH . 'app/Modules/Shipping/BoxNow/BoxNow.php' );
$pigeon = file_get_contents( BGCS3_PATH . 'app/Modules/Shipping/Pigeon/Pigeon.php' );

bgcs_eta_check( 'Speedy consumes its documented deliveryDeadline', false !== strpos( $speedy, "\$calc['deliveryDeadline']" ) );
bgcs_eta_check( 'Econt prefers expectedDeliveryDate', false !== strpos( $econt, "\$data['expectedDeliveryDate']" ) );
bgcs_eta_check( 'BOX NOW does not invent an ETA', false === strpos( $boxnow, 'delivery_estimate' ) );
bgcs_eta_check( 'Pigeon does not invent an ETA', false === strpos( $pigeon, 'delivery_estimate' ) );


// --- kind: a deadline and an estimate are different promises ----------------

$speedy_deadline = Delivery_Estimate::normalize( '2026-09-09T19:00:00+0300', 'speedy', 'deadline' );
$econt_estimate  = Delivery_Estimate::normalize( '1788555600000', 'econt', 'estimate' );

bgcs_eta_check( 'A Speedy deadline is recorded as a deadline', 'deadline' === $speedy_deadline['kind'] );
bgcs_eta_check( 'An Econt date is recorded as an estimate', 'estimate' === $econt_estimate['kind'] );
bgcs_eta_check(
	'A deadline is described as one',
	'Delivery by 09.09.2026 19:00' === Delivery_Estimate::describe( $speedy_deadline )
);
bgcs_eta_check(
	'An estimate is described as one',
	'Expected delivery: 05.09.2026' === Delivery_Estimate::describe( $econt_estimate )
);
bgcs_eta_check( 'kind survives the persist/restore round trip', $speedy_deadline === Delivery_Estimate::sanitize( $speedy_deadline ) );

// An order placed on 4.2.0 stored no kind. It must not retroactively gain a
// deadline the courier never promised.
$legacy = array( 'value' => '2026-09-09T19:00:00+03:00', 'precision' => 'datetime', 'courier' => 'speedy' );
bgcs_eta_check( 'A 4.2.0 estimate with no kind defaults to the weaker claim', 'estimate' === Delivery_Estimate::sanitize( $legacy )['kind'] );
bgcs_eta_check(
	'…and is described as an estimate, not a deadline',
	'Expected delivery: 09.09.2026 19:00' === Delivery_Estimate::describe( $legacy )
);

// An unknown kind is not trusted either.
bgcs_eta_check( 'An unrecognized kind falls back to estimate', 'estimate' === Delivery_Estimate::normalize( '2026-09-05', 'econt', 'wishful' )['kind'] );

bgcs_eta_check( 'describe() on an absent estimate says nothing', '' === Delivery_Estimate::describe( array() ) );
bgcs_eta_check( 'describe() on a tampered value says nothing', '' === Delivery_Estimate::describe( array( 'value' => 'tomorrow', 'precision' => 'datetime', 'courier' => 'speedy' ) ) );

// --- The rate name owns no state -------------------------------------------

$method_src   = file_get_contents( BGCS3_PATH . 'app/Shipping/Method.php' );
$checkout_src = file_get_contents( BGCS3_PATH . 'app/Checkout/Checkout.php' );

bgcs_eta_check( 'Method no longer appends anything to the rate label', false === strpos( $method_src, "\$rate['label'] .=" ) );
bgcs_eta_check( 'Method no longer calls label_suffix()', false === strpos( $method_src, 'label_suffix' ) );
bgcs_eta_check( 'Checkout no longer rewrites the shipping line title', false === strpos( $checkout_src, 'set_method_title' ) );
bgcs_eta_check( 'Checkout no longer calls label_suffix()', false === strpos( $checkout_src, 'label_suffix' ) );

// With both callers gone the method itself goes: the rate name owns no state.
$estimate_src = file_get_contents( BGCS3_PATH . 'app/Shipping/Delivery_Estimate.php' );
bgcs_eta_check( 'label_suffix() no longer exists', false === strpos( $estimate_src, 'function label_suffix' ) );

// The estimate still has to reach the order, or the e-mails lose it.
bgcs_eta_check( 'Method still publishes the estimate as rate meta', false !== strpos( $method_src, "_bgcs3_delivery_estimate" ) );
bgcs_eta_check( 'Checkout still transfers the estimate onto the order', false !== strpos( $checkout_src, "'_bgcs3_delivery_estimate'" ) );

// --- Blocks gets the estimate through WooCommerce's own rate property -------

$hooks_src = file_get_contents( BGCS3_PATH . 'app/Shipping/Hooks.php' );

bgcs_eta_check(
	'Core filters the native delivery_time property',
	false !== strpos( $hooks_src, "add_filter( 'woocommerce_shipping_rate_delivery_time'" )
);
bgcs_eta_check( 'The filter is served by rate_delivery_time()', false !== strpos( $hooks_src, 'function rate_delivery_time' ) );
bgcs_eta_check( 'It reads the estimate from rate meta, not the label', false !== strpos( $hooks_src, '_bgcs3_delivery_estimate' ) );
bgcs_eta_check( 'It renders through describe(), so the wording follows the kind', false !== strpos( $hooks_src, 'Delivery_Estimate::describe' ) );
// Scoped to rate_estimate()'s own body: an unrelated method later in the file
// also calls rate_is_bgcs(), so an unanchored match would pass even with the
// guard deleted from the function this assertion names.
$rate_estimate_body = '';
if ( preg_match( '/\n\tprivate static function rate_estimate\(.*?\n\t\}\n/s', $hooks_src, $body_match ) ) {
	$rate_estimate_body = $body_match[0];
}
bgcs_eta_check( 'The estimate lookup was found to inspect', '' !== $rate_estimate_body );
bgcs_eta_check(
	'It only speaks for BGCS rates',
	'' !== $rate_estimate_body && false !== strpos( $rate_estimate_body, 'self::rate_is_bgcs( $rate )' )
);

// --- Classic checkout renders it as its own element -------------------------

$hooks_src = file_get_contents( BGCS3_PATH . 'app/Shipping/Hooks.php' );

bgcs_eta_check(
	'Core hooks the canonical per-rate Classic action',
	false !== strpos( $hooks_src, "add_action( 'woocommerce_after_shipping_rate'" )
);
bgcs_eta_check( 'It is served by render_rate_delivery_time()', false !== strpos( $hooks_src, 'function render_rate_delivery_time' ) );
bgcs_eta_check( 'The element is class bgcs3-rate-eta', false !== strpos( $hooks_src, 'bgcs3-rate-eta' ) );
bgcs_eta_check( 'The rendered text is escaped', false !== strpos( $hooks_src, 'esc_html( $text )' ) );

// A rate with no estimate must print nothing at all — no empty span.
// Scoped to the renderer's own body, and to the guard's own block: an
// unbounded match would be satisfied by the function's second guard even
// with this one's return deleted.
$renderer_body = '';
if ( preg_match( '/\n\tpublic static function render_rate_delivery_time\(.*?\n\t\}\n/s', $hooks_src, $renderer_match ) ) {
	$renderer_body = $renderer_match[0];
}
bgcs_eta_check( 'The Classic renderer was found to inspect', '' !== $renderer_body );
bgcs_eta_check(
	'It returns before echoing when there is no estimate',
	1 === preg_match( '/if \( empty\( \$estimate \) \) \{\s*return;\s*\}/', $renderer_body )
);

$css = file_get_contents( BGCS3_PATH . 'assets/css/bgcs-checkout.css' );
bgcs_eta_check( 'Core styles the element', false !== strpos( $css, '.bgcs3-rate-eta' ) );

// --- Each courier declares what kind of promise it made ---------------------

preg_match_all( '/Delivery_Estimate::normalize\((.*?)\);/s', $speedy, $speedy_calls );
preg_match_all( '/Delivery_Estimate::normalize\((.*?)\);/s', $econt, $econt_calls_kind );

bgcs_eta_check( 'Speedy has two ETA call sites', 2 === count( $speedy_calls[1] ) );
bgcs_eta_check( 'Econt has two ETA call sites', 2 === count( $econt_calls_kind[1] ) );

$speedy_deadlines = array_filter( $speedy_calls[1], function ( $args ) {
	return false !== strpos( $args, 'KIND_DEADLINE' );
} );
$econt_estimates = array_filter( $econt_calls_kind[1], function ( $args ) {
	return false !== strpos( $args, 'KIND_ESTIMATE' );
} );

bgcs_eta_check( 'Speedy declares its deliveryDeadline a deadline', 2 === count( $speedy_deadlines ) );
bgcs_eta_check( 'Econt declares its expectedDeliveryDate an estimate', 2 === count( $econt_estimates ) );

if ( $failures ) {
	printf( "\n%d delivery-estimate check(s) failed.\n", $failures );
	exit( 1 );
}

echo "\nAll delivery-estimate checks passed.\n";
