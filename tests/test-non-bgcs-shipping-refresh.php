<?php
/**
 * Phase 12: external WooCommerce methods must refresh checkout totals in Flow.
 *
 * Run: php tests/test-non-bgcs-shipping-refresh.php
 */

$source = file_get_contents( dirname( __DIR__ ) . '/assets/js/bgcs-checkout.js' );
$failures = 0;

function bgcs_phase12_refresh_check( $condition, $message ) {
	global $failures;
	echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

bgcs_phase12_refresh_check(
	false !== strpos( $source, "$( document.body ).on( 'change', 'input[name^=\"shipping_method\"]'" )
		&& false !== strpos( $source, 'ensureExternalShippingRefresh( this );' ),
	'External shipping fallback receives synthetic renderer change events'
);
bgcs_phase12_refresh_check(
	false !== strpos( $source, "classList.contains( 'bgcs-flow-surface' )" )
		&& false !== strpos( $source, "$( document.body ).trigger( 'update_checkout' );" ),
	'Flow emits the canonical checkout refresh without depending on stale scheduler state'
);
bgcs_phase12_refresh_check(
	false !== strpos( $source, "isBgcsRateId( String( input.value || '' ) )" ),
	'BGCS rates remain owned by the canonical selection refresh path'
);
bgcs_phase12_refresh_check(
	false !== strpos( $source, 'observedEpoch !== checkoutUpdateEpoch' )
		&& false !== strpos( $source, 'checkoutUpdateStartedAt < 100' )
		&& false !== strpos( $source, 'checkoutUpdateEpoch++;' ),
	'A renderer-started WooCommerce refresh suppresses the deferred fallback'
);
bgcs_phase12_refresh_check(
	false !== strpos( $source, 'updateScheduler.request();' ),
	'An unhandled external rate change schedules WooCommerce checkout refresh'
);

if ( $failures ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}

echo 'OK - external shipping refresh contract is present' . PHP_EOL;
