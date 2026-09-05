<?php
/**
 * Offline regression checks for BGCS selection -> Woo chosen-rate convergence.
 *
 * Run: php tests/test-courier-selection-synchronization.php
 */

define( 'ABSPATH', __DIR__ );
define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function wp_unslash( $value ) {
	return $value;
}

function __( $text, $domain = null ) {
	return $text;
}

function apply_filters( $hook, $value = null ) {
	return $value;
}

class WC_Shipping_Rate {}

class WC_Order {
	private $shipping_methods;
	private $meta;
	public function __construct( array $shipping_methods = array(), array $meta = array() ) {
		$this->shipping_methods = $shipping_methods;
		$this->meta = $meta;
	}
	public function get_shipping_methods() { return $this->shipping_methods; }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : null; }
}

class WP_Error {
	private $errors = array();
	public function add( $code, $message ) { $this->errors[ $code ][] = $message; }
	public function has_errors() { return ! empty( $this->errors ); }
	public function get_error_codes() { return array_keys( $this->errors ); }
}

final class BGCS_Sync_Test_Rate extends WC_Shipping_Rate {
	private $id;
	private $meta;

	public function __construct( $id, $courier, $state = 'pending', $validated = false, $selection = null, $payment_context = '' ) {
		$this->id   = $id;
		$this->meta = array(
			'_bgcs3_courier'    => $courier,
			'_bgcs3_price_state' => $state,
			'_bgcs3_validated'   => $validated,
		);
		if ( is_object( $selection ) && method_exists( $selection, 'to_array' ) ) {
			$selection = $selection->to_array();
		}
		if ( is_array( $selection ) ) {
			$this->meta['_bgcs3_selection'] = $selection;
		}
		if ( '' !== $payment_context ) {
			$this->meta['_bgcs3_payment_context'] = $payment_context;
		}
	}

	public function get_id() {
		return $this->id;
	}

	public function get_meta_data() {
		return $this->meta;
	}
}

$sync_file = BGCS3_PATH . 'app/Shipping/Selection_Synchronizer.php';
if ( ! file_exists( $sync_file ) ) {
	fwrite( STDERR, "[FAIL] Missing Core selection synchronizer.\n" );
	exit( 1 );
}

require_once BGCS3_PATH . 'app/Support/Selection.php';
require_once BGCS3_PATH . 'app/Support/Selection_Store.php';
require_once BGCS3_PATH . 'app/Shipping/Order_Persistence.php';
require_once BGCS3_PATH . 'app/Shipping/Cod.php';
require_once $sync_file;
require_once BGCS3_PATH . 'app/Shipping/Hooks.php';
require_once BGCS3_PATH . 'app/Checkout/Checkout.php';
require_once BGCS3_PATH . 'app/Modules/Shipping/BoxNow/Weight_Pricing.php';

use BgCommerce3\Shipping\Selection_Synchronizer;
use BgCommerce3\Shipping\Hooks;
use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Selection_Store;
use BgCommerce3\Checkout\Checkout;
use BgCommerce3\Modules\Shipping\BoxNow\Weight_Pricing;

$failures = 0;
function check_sync( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		++$failures;
	}
}

function bgcs_sync_rates( $suffix ) {
	return array(
		'bgcs3_speedy:' . $suffix => new BGCS_Sync_Test_Rate( 'bgcs3_speedy:' . $suffix, 'speedy' ),
		'bgcs3_econt:' . $suffix  => new BGCS_Sync_Test_Rate( 'bgcs3_econt:' . $suffix, 'econt', 'calculated', true ),
		'bgcs3_pigeon:' . $suffix => new BGCS_Sync_Test_Rate( 'bgcs3_pigeon:' . $suffix, 'pigeon', 'calculated', true ),
		'bgcs3_boxnow:' . $suffix => new BGCS_Sync_Test_Rate( 'bgcs3_boxnow:' . $suffix, 'boxnow', 'calculated', true ),
	);
}

echo "Courier selection synchronization contract\n";

$packages = array(
	0 => array( 'rates' => bgcs_sync_rates( 13 ) ),
	1 => array( 'rates' => bgcs_sync_rates( 27 ) ),
	2 => array( 'rates' => array( 'flat_rate:8' => new BGCS_Sync_Test_Rate( 'flat_rate:8', '' ) ) ),
);
$chosen = array( 0 => 'bgcs3_speedy:13', 1 => 'bgcs3_speedy:27', 2 => 'flat_rate:8' );

$resolved = Selection_Synchronizer::reconcile_chosen( $packages, $chosen, 'econt' );
check_sync( 'bgcs3_econt:13' === $resolved['chosen'][0], 'Package 0 resolves the exact Econt zone instance from rate metadata' );
check_sync( 'bgcs3_econt:27' === $resolved['chosen'][1], 'Package 1 resolves its own exact Econt instance' );
check_sync( 'flat_rate:8' === $resolved['chosen'][2], 'A non-BGCS package selection is preserved' );
check_sync( array( 0, 1 ) === $resolved['changed_package_keys'], 'Only changed BGCS packages are reported' );

foreach ( array(
	array( 'speedy', 'econt' ),
	array( 'econt', 'pigeon' ),
	array( 'pigeon', 'boxnow' ),
	array( 'boxnow', 'speedy' ),
) as $transition ) {
	list( $from, $to ) = $transition;
	$transition_result = Selection_Synchronizer::reconcile_chosen(
		array( 0 => array( 'rates' => bgcs_sync_rates( 13 ) ) ),
		array( 0 => 'bgcs3_' . $from . ':13' ),
		$to
	);
	check_sync( 'bgcs3_' . $to . ':13' === $transition_result['chosen'][0], strtoupper( $from . ' -> ' . $to ) . ' converges to the target rate' );
}

$rapid_chosen = array( 0 => 'bgcs3_speedy:13' );
foreach ( array( 'econt', 'pigeon', 'boxnow', 'speedy', 'econt' ) as $target_courier ) {
	$rapid = Selection_Synchronizer::reconcile_chosen(
		array( 0 => array( 'rates' => bgcs_sync_rates( 13 ) ) ),
		$rapid_chosen,
		$target_courier
	);
	$rapid_chosen = $rapid['chosen'];
}
check_sync( 'bgcs3_econt:13' === $rapid_chosen[0], 'Rapid courier changes finish on the newest target' );

$missing_target = Selection_Synchronizer::reconcile_chosen(
	array( 0 => array( 'rates' => array( 'bgcs3_speedy:13' => new BGCS_Sync_Test_Rate( 'bgcs3_speedy:13', 'speedy' ) ) ) ),
	array( 0 => 'bgcs3_speedy:13' ),
	'econt'
);
check_sync( '' === $missing_target['chosen'][0], 'A stale BGCS rate is cleared when the selected courier has no rate in the package' );

$selection = Selection::from_array(
	array(
		'courier'       => 'econt',
		'delivery_type' => 'office',
		'office'        => array( 'id' => '42' ),
		'revision'      => '103',
	)
);
check_sync( 103 === $selection->revision, 'Selection carries a sanitized monotonic revision' );
check_sync( 103 === $selection->to_array()['revision'], 'Revision survives the canonical selection payload' );

$econt_rate = new BGCS_Sync_Test_Rate( 'bgcs3_econt:13', 'econt', 'calculated', true, $selection );
$speedy_rate = new BGCS_Sync_Test_Rate( 'bgcs3_speedy:13', 'speedy', 'calculated', true );
$pending_econt = new BGCS_Sync_Test_Rate( 'bgcs3_econt:13', 'econt', 'pending', false );
check_sync( Selection_Synchronizer::rate_is_settled_for( $econt_rate, 'econt' ), 'Calculated and validated rate owned by the selection is settled' );
check_sync( ! Selection_Synchronizer::rate_is_settled_for( $speedy_rate, 'econt' ), 'Cross-courier rate ownership is rejected' );
check_sync( ! Selection_Synchronizer::rate_is_settled_for( $pending_econt, 'econt' ), 'pending + zero-style placeholder cannot be settled' );

final class BGCS_Sync_Test_Session {
	public $data;
	public $saves = 0;
	public function __construct( array $data ) { $this->data = $data; }
	public function get( $key, $default = null ) { return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default; }
	public function set( $key, $value ) { $this->data[ $key ] = $value; }
	public function save_data() { ++$this->saves; }
}

final class BGCS_Sync_Test_Shipping {
	public $packages;
	public function __construct( array $packages ) { $this->packages = $packages; }
	public function get_packages() { return $this->packages; }
}

final class BGCS_Sync_Test_Cart {
	public $calculations = 0;
	public $total_calculations = 0;
	public $cart_packages;
	public $shipping;
	public $fresh_packages;
	public function __construct( $shipping, array $cart_packages, array $fresh_packages ) {
		$this->shipping = $shipping;
		$this->cart_packages = $cart_packages;
		$this->fresh_packages = $fresh_packages;
	}
	public function get_shipping_packages() { return $this->cart_packages; }
	public function calculate_shipping() {
		++$this->calculations;
		$this->shipping->packages = $this->fresh_packages;
	}
	public function calculate_totals() { ++$this->total_calculations; }
}

final class BGCS_Sync_Test_WC {
	public $session;
	public $cart;
	public $shipping_service;
	public function shipping() { return $this->shipping_service; }
}

function WC() {
	return $GLOBALS['bgcs_sync_wc'];
}

$wc = new BGCS_Sync_Test_WC();
$wc->session = new BGCS_Sync_Test_Session(
	array(
		'chosen_shipping_methods' => array( 0 => 'bgcs3_speedy:13', 1 => 'flat_rate:8' ),
	)
);
$wc->shipping_service = new BGCS_Sync_Test_Shipping( array() );
$wc->cart = new BGCS_Sync_Test_Cart(
	$wc->shipping_service,
	array( 0 => array(), 1 => array() ),
	array(
		0 => array( 'rates' => bgcs_sync_rates( 13 ) ),
		1 => array( 'rates' => array( 'flat_rate:8' => new BGCS_Sync_Test_Rate( 'flat_rate:8', '' ) ) ),
	)
);
$GLOBALS['bgcs_sync_wc'] = $wc;

Selection_Synchronizer::synchronize( $selection );
check_sync( 'bgcs3_econt:13' === $wc->session->data['chosen_shipping_methods'][0], 'Freshly discovered exact rate is persisted to the WC session' );
check_sync( 'flat_rate:8' === $wc->session->data['chosen_shipping_methods'][1], 'Runtime synchronization still preserves an unrelated package' );
check_sync( 2 === $wc->cart->calculations, 'A second cached calculation settles totals when the exact rate appears after the first pass' );
check_sync( null === $wc->session->data['shipping_for_package_0'], 'Relevant package cache is invalidated' );
check_sync( null === $wc->session->data['shipping_for_package_1'], 'Unknown initial package snapshots are conservatively invalidated once' );

// WC_AJAX::update_order_review() reads this top-level field after the BGCS
// action. A stale browser value must not overwrite the synchronized session.
$_POST['shipping_method'] = array( 0 => 'bgcs3_pigeon:15', 1 => 'flat_rate:8' );
$posted_selection = Selection::from_array(
	array(
		'courier'       => 'econt',
		'delivery_type' => 'office',
		'office'        => array( 'id' => '42' ),
		'revision'      => '104',
	)
);
Hooks::sync_and_recalc( 'bgcs3_selection=' . rawurlencode( json_encode( $posted_selection->to_array() ) ) );
check_sync( 'bgcs3_econt:13' === $_POST['shipping_method'][0], 'Late WooCommerce posted method is aligned with the canonical courier rate' );
check_sync( 'flat_rate:8' === $_POST['shipping_method'][1], 'Posted non-BGCS package method is preserved' );

// A single package switching from BGCS to a normal WooCommerce method reaches
// this hook before WC_AJAX has copied the posted choice into the session.
$mixed_rates = bgcs_sync_rates( 13 );
$mixed_rates['flat_rate:8'] = new BGCS_Sync_Test_Rate( 'flat_rate:8', '' );
$wc->shipping_service->packages = array();
$wc->cart->fresh_packages = array( 0 => array( 'rates' => $mixed_rates ) );
$wc->cart->cart_packages = array( 0 => array() );
$wc->session->data['chosen_shipping_methods'] = array( 0 => 'bgcs3_econt:13' );
$_POST['shipping_method'] = array( 0 => 'flat_rate:8' );
$external_post_data = 'bgcs3_selection=' . rawurlencode( json_encode( $posted_selection->to_array() ) );
Hooks::sync_and_recalc( $external_post_data );
check_sync( 'flat_rate:8' === $wc->session->data['chosen_shipping_methods'][0], 'Top-level WooCommerce external rate supersedes a stale BGCS session choice before package snapshots exist' );
check_sync( 'flat_rate:8' === $_POST['shipping_method'][0], 'Canonical BGCS synchronization does not rewrite the external posted rate' );

$wc->session->data['chosen_shipping_methods'] = array( 0 => 'bgcs3_econt:13', 1 => 'flat_rate:8' );
$wc->session->data['shipping_for_package_0'] = array(
	'rates' => array(
		'bgcs3_econt:13' => new BGCS_Sync_Test_Rate( 'bgcs3_econt:13', 'econt', 'calculated', true, $posted_selection, 'prepaid' ),
	),
);
$_POST['payment_method'] = 'bacs';
$before_shipping_calculations = $wc->cart->calculations;
Hooks::ensure_current_payment_quote();
check_sync( $before_shipping_calculations === $wc->cart->calculations, 'Matching prepaid rate context keeps the shipping cache' );

$_POST['payment_method'] = 'cod';
Hooks::ensure_current_payment_quote();
check_sync( $before_shipping_calculations + 1 === $wc->cart->calculations, 'COD selection invalidates a cached prepaid BGCS rate' );
check_sync( 1 === $wc->cart->total_calculations, 'Payment-context mismatch recalculates cart totals before order creation' );
check_sync( 'cod' === $wc->session->data['chosen_payment_method'], 'Final guard synchronizes the posted payment method into the WC session' );
check_sync( null === $wc->session->data['shipping_for_package_0'], 'Final guard clears the mismatched BGCS package cache' );

$wc->session->data[ Selection_Store::SESSION_KEY ] = array( 'courier' => 'econt', 'revision' => 103 );
$stale = Selection::from_array( array( 'courier' => 'speedy', 'revision' => 102 ) );
$newer = Selection::from_array( array( 'courier' => 'boxnow', 'revision' => 104 ) );
$selection_store = new Selection_Store();
check_sync( false === $selection_store->set( $stale ), 'An older server selection revision is rejected' );
check_sync( 'econt' === $wc->session->data[ Selection_Store::SESSION_KEY ]['courier'], 'Rejected stale state cannot overwrite the canonical courier' );
check_sync( true === $selection_store->set( $newer ), 'A newer selection revision is accepted' );
check_sync( 'boxnow' === $wc->session->data[ Selection_Store::SESSION_KEY ]['courier'], 'Accepted revision becomes canonical' );

$checkout = ( new ReflectionClass( Checkout::class ) )->newInstanceWithoutConstructor();
$rate_guard = new ReflectionMethod( Checkout::class, 'chosen_bgcs_rate_validation_error' );
$rate_guard->setAccessible( true );

$wc->shipping_service->packages = array(
	0 => array(
		'rates' => array(
			'bgcs3_speedy:13' => new BGCS_Sync_Test_Rate( 'bgcs3_speedy:13', 'speedy', 'calculated', true ),
			'bgcs3_econt:13'  => new BGCS_Sync_Test_Rate( 'bgcs3_econt:13', 'econt', 'calculated', true, $selection ),
			'bgcs3_econt:14'  => new BGCS_Sync_Test_Rate( 'bgcs3_econt:14', 'econt', 'pending', false ),
		),
	),
);
check_sync(
	'' !== $rate_guard->invoke( $checkout, array( 0 => 'bgcs3_speedy:13' ), $selection ),
	'Checkout guard rejects a calculated rate owned by a different courier'
);
check_sync(
	'' !== $rate_guard->invoke( $checkout, array( 0 => 'bgcs3_econt:14' ), $selection ),
	'Checkout guard rejects a pending rate owned by the selected courier'
);
check_sync(
	'' === $rate_guard->invoke( $checkout, array( 0 => 'bgcs3_econt:13' ), $selection ),
	'Checkout guard accepts only the matching calculated and validated rate'
);

$stale_rate_selection = $selection->to_array();
$stale_rate_selection['revision'] = $selection->revision - 1;
$wc->shipping_service->packages[0]['rates']['bgcs3_econt:15'] = new BGCS_Sync_Test_Rate(
	'bgcs3_econt:15',
	'econt',
	'calculated',
	true,
	$stale_rate_selection
);
check_sync(
	'' !== $rate_guard->invoke( $checkout, array( 0 => 'bgcs3_econt:15' ), $selection ),
	'Checkout guard rejects a calculated rate owned by an older selection revision'
);

$other_office_selection = $selection->to_array();
$other_office_selection['office']['id'] = '99';
$wc->shipping_service->packages[0]['rates']['bgcs3_econt:16'] = new BGCS_Sync_Test_Rate(
	'bgcs3_econt:16',
	'econt',
	'calculated',
	true,
	$other_office_selection
);
check_sync(
	'' !== $rate_guard->invoke( $checkout, array( 0 => 'bgcs3_econt:16' ), $selection ),
	'Checkout guard rejects a calculated rate owned by another destination'
);

$wc->session->data[ Selection_Store::SESSION_KEY ] = $selection->to_array();
$wc->session->data['chosen_shipping_methods'] = array( 0 => 'bgcs3_econt:14', 1 => 'flat_rate:8' );
$store_api_errors = new WP_Error();
$checkout->validate_store_api_cart( $store_api_errors );
check_sync(
	in_array( 'bgcs3_rate_validation', $store_api_errors->get_error_codes(), true ),
	'Store API checkout callback rejects a pending BGCS package in a mixed cart'
);

$classic_errors = new WP_Error();
$checkout->validate( array(), $classic_errors );
check_sync(
	in_array( 'bgcs3_rate_validation', $classic_errors->get_error_codes(), true ),
	'Classic checkout callback rejects a pending BGCS package in a mixed cart'
);

$wc->session->data['chosen_shipping_methods'] = array( 0 => 'bgcs3_econt:13', 1 => 'flat_rate:8' );
$settled_store_api_errors = new WP_Error();
$checkout->validate_store_api_cart( $settled_store_api_errors );
check_sync(
	! $settled_store_api_errors->has_errors(),
	'Store API checkout callback accepts a matching settled BGCS package and preserves the external package'
);

$shipping_item = new class() {
	public function get_method_id() { return 'bgcs3_speedy'; }
};
$order_guard = new ReflectionMethod( Checkout::class, 'order_shipping_selection_error' );
$order_guard->setAccessible( true );
check_sync(
	'' !== $order_guard->invoke( $checkout, new WC_Order( array( $shipping_item ) ), $selection ),
	'Final order guard rejects a shipping line owned by another courier'
);

$econt_shipping_item = new class() {
	public function get_method_id() { return 'bgcs3_econt'; }
};
$mixed_order = new WC_Order(
	array( $econt_shipping_item, new class() {
		public function get_method_id() { return 'flat_rate'; }
	} ),
	array( Checkout::META_KEY => $selection->to_array() )
);
$wc->session->data['chosen_shipping_methods'] = array( 0 => 'bgcs3_econt:14', 1 => 'flat_rate:8' );
$payment_errors = new WP_Error();
$checkout->validate_order_before_payment( $mixed_order, $payment_errors );
check_sync(
	in_array( 'bgcs3_rate_validation', $payment_errors->get_error_codes(), true ),
	'Pre-payment callback rejects a pending BGCS package before order submission'
);

$wc->session->data['chosen_shipping_methods'] = array( 0 => 'bgcs3_econt:13', 1 => 'flat_rate:8' );
$settled_payment_errors = new WP_Error();
$checkout->validate_order_before_payment( $mixed_order, $settled_payment_errors );
check_sync(
	! $settled_payment_errors->has_errors(),
	'Pre-payment callback accepts matching settled BGCS and external shipping lines'
);

$boxnow_code = file_get_contents( BGCS3_PATH . 'app/Modules/Shipping/BoxNow/BoxNow.php' );
check_sync(
	false !== strpos( $boxnow_code, '$result->mode' ) && false !== strpos( $boxnow_code, '$result->source' ),
	'BOX NOW local tariff explicitly declares canonical pricing mode and source'
);
check_sync(
	2.99 === Weight_Pricing::resolve( 2.5, array( array( 'min' => 0, 'max' => 3, 'price' => 2.99 ) ) ),
	'BOX NOW configured tariff resolution keeps the merchant price unchanged'
);

echo PHP_EOL;
if ( $failures ) {
	echo "FAILED: {$failures} check(s)\n";
	exit( 1 );
}

echo "OK - courier selection synchronization checks passed\n";
