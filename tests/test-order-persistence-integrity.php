<?php
/**
 * Offline regression checks for quote-to-order persistence integrity.
 *
 * Run: php tests/test-order-persistence-integrity.php
 */

define( 'ABSPATH', __DIR__ );
define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function __( $text, $domain = null ) { return $text; }
function apply_filters( $hook, $value ) { return $value; }
function get_current_user_id() { return 0; }

class WC_Order_Item_Shipping {
	private $method_id;
	private $instance_id;
	private $total;
	private $total_tax;
	private $meta;

	public function __construct( $method_id, $instance_id, $total, array $meta = array(), $total_tax = 0.0 ) {
		$this->method_id   = $method_id;
		$this->instance_id = $instance_id;
		$this->total       = $total;
		$this->total_tax   = $total_tax;
		$this->meta        = $meta;
	}

	public function get_method_id() { return $this->method_id; }
	public function get_instance_id() { return $this->instance_id; }
	public function get_total() { return $this->total; }
	public function get_total_tax() { return $this->total_tax; }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
}

final class BGCS_Order_Meta_Row {
	private $key;
	private $value;
	public function __construct( $key, $value ) { $this->key = $key; $this->value = $value; }
	public function get_data() { return array( 'key' => $this->key, 'value' => $this->value ); }
}

class WC_Order {
	private $meta;
	private $shipping_methods;
	private $address = array();

	public function __construct( array $meta = array(), array $shipping_methods = array() ) {
		$this->meta             = $meta;
		$this->shipping_methods = $shipping_methods;
	}

	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function get_meta_data() {
		$rows = array();
		foreach ( $this->meta as $key => $value ) {
			$rows[] = new BGCS_Order_Meta_Row( $key, $value );
		}
		return $rows;
	}
	public function get_shipping_methods() { return $this->shipping_methods; }
	public function set_shipping_methods( array $shipping_methods ) { $this->shipping_methods = $shipping_methods; }
	public function set_billing_country( $value ) { $this->address['billing_country'] = $value; }
	public function set_shipping_country( $value ) { $this->address['shipping_country'] = $value; }
	public function set_shipping_city( $value ) { $this->address['shipping_city'] = $value; }
	public function set_shipping_postcode( $value ) { $this->address['shipping_postcode'] = $value; }
	public function set_shipping_address_1( $value ) { $this->address['shipping_address_1'] = $value; }
	public function set_shipping_address_2( $value ) { $this->address['shipping_address_2'] = $value; }
	public function set_billing_city( $value ) { $this->address['billing_city'] = $value; }
	public function set_billing_postcode( $value ) { $this->address['billing_postcode'] = $value; }
	public function set_billing_address_1( $value ) { $this->address['billing_address_1'] = $value; }
	public function get_billing_city() { return isset( $this->address['billing_city'] ) ? $this->address['billing_city'] : ''; }
	public function get_billing_postcode() { return isset( $this->address['billing_postcode'] ) ? $this->address['billing_postcode'] : ''; }
	public function get_billing_address_1() { return isset( $this->address['billing_address_1'] ) ? $this->address['billing_address_1'] : ''; }
	public function get_shipping_city() { return isset( $this->address['shipping_city'] ) ? $this->address['shipping_city'] : ''; }
	public function get_shipping_postcode() { return isset( $this->address['shipping_postcode'] ) ? $this->address['shipping_postcode'] : ''; }
	public function get_shipping_address_1() { return isset( $this->address['shipping_address_1'] ) ? $this->address['shipping_address_1'] : ''; }
	public function get_shipping_address_2() { return isset( $this->address['shipping_address_2'] ) ? $this->address['shipping_address_2'] : ''; }
}

final class BGCS_Order_Test_Session {
	public $data = array();
	public function get( $key, $default = null ) { return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $default; }
}

final class BGCS_Order_Test_WC {
	public $session;
	public function __construct() { $this->session = new BGCS_Order_Test_Session(); }
}

$GLOBALS['bgcs_order_wc'] = new BGCS_Order_Test_WC();
function WC() { return $GLOBALS['bgcs_order_wc']; }

require_once BGCS3_PATH . 'app/Support/Selection.php';
require_once BGCS3_PATH . 'app/Support/Selection_Store.php';
require_once BGCS3_PATH . 'app/Shipping/Order_Persistence.php';
require_once BGCS3_PATH . 'app/Shipping/Selection_Synchronizer.php';
require_once BGCS3_PATH . 'app/Checkout/Checkout.php';

use BgCommerce3\Checkout\Checkout;
use BgCommerce3\Shipping\Order_Persistence;
use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Selection_Store;

$failures = 0;
function check_order_persistence( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		++$failures;
	}
}

function bgcs_order_selection( $revision, $office_id = '42' ) {
	return Selection::from_array(
		array(
			'courier'       => 'econt',
			'delivery_type' => 'office',
			'country'       => 'BG',
			'city'          => array( 'id' => '7000', 'name' => 'Ruse', 'post_code' => '7000' ),
			'office'        => array( 'id' => $office_id, 'text' => 'Office ' . $office_id ),
			'extras'        => array(),
			'revision'      => $revision,
		)
	);
}

echo "Order persistence integrity\n";

$selection = bgcs_order_selection( 501 );
check_order_persistence(
	Order_Persistence::selection_matches( $selection->to_array(), $selection ),
	'Rate-owned selection matches the exact canonical selection'
);
check_order_persistence(
	! Order_Persistence::selection_matches( bgcs_order_selection( 500 )->to_array(), $selection ),
	'An older quoted revision cannot match a newer canonical selection'
);
check_order_persistence(
	! Order_Persistence::selection_matches( bgcs_order_selection( 501, '99' )->to_array(), $selection ),
	'A quote for another office cannot match the canonical destination'
);

$order = new WC_Order(
	array(
		'_bgcs3_label'           => array( 'number' => 'KEEP' ),
		'bgcs3_courier'          => 'speedy',
		'bgcs3_office_id'        => 'old-office',
		'bgcs3_speedy_office_id' => 'old-speedy-office',
		'bgcs3_address'          => 'Old address 1',
	)
);
Order_Persistence::replace_readable_meta(
	$order,
	array(
		'bgcs3_courier'      => 'econt',
		'bgcs3_delivery_type' => 'address',
		'bgcs3_address'      => 'New address 2',
	)
);
check_order_persistence( 'econt' === $order->get_meta( 'bgcs3_courier' ), 'Readable courier meta is replaced' );
check_order_persistence( '' === $order->get_meta( 'bgcs3_office_id' ), 'Generic stale office meta is removed' );
check_order_persistence( '' === $order->get_meta( 'bgcs3_speedy_office_id' ), 'Previous courier readable meta is removed' );
check_order_persistence( 'New address 2' === $order->get_meta( 'bgcs3_address' ), 'Only the current destination shape remains readable' );
check_order_persistence( 'KEEP' === $order->get_meta( '_bgcs3_label' )['number'], 'Operational private meta is preserved' );

$mixed_order = new WC_Order(
	array(),
	array(
		new WC_Order_Item_Shipping( 'flat_rate', 8, 7.00 ),
		new WC_Order_Item_Shipping( 'bgcs3_econt', 14, 3.50, array(), 0.70 ),
		new WC_Order_Item_Shipping( 'bgcs3_econt', 27, 2.25 ),
	)
);
check_order_persistence(
	5.75 === Order_Persistence::courier_shipping_total( $mixed_order, 'econt' ),
	'Courier price excludes unrelated shipping lines and sums its own packages'
);

$rate_item = new WC_Order_Item_Shipping(
	'bgcs3_econt',
	14,
	3.50,
	array(
		'_bgcs3_selection'         => $selection->to_array(),
		'_bgcs3_validated'         => true,
		'_bgcs3_price_state'       => 'calculated',
		'_bgcs3_pricing_mode'      => 'api',
		'_bgcs3_pricing_source'    => 'api',
		'_bgcs3_base_cost'         => 3.50,
		'_bgcs3_surcharges'        => array( 'fuel' => 0.35 ),
		'_bgcs3_delivery_estimate' => array( 'value' => '2026-09-05', 'precision' => 'date', 'courier' => 'econt' ),
	),
	0.70
);
Order_Persistence::capture_quote_snapshot( $rate_item, 1, $mixed_order );
$snapshots = $mixed_order->get_meta( Order_Persistence::QUOTE_SNAPSHOTS_META );
check_order_persistence( 1 === count( $snapshots ), 'One package-aware quote snapshot is recorded' );
check_order_persistence( 501 === $snapshots['1']['selection']['revision'], 'Quote snapshot retains the priced selection revision' );
check_order_persistence( 'bgcs3_econt:14' === $snapshots['1']['rate_id'], 'Quote snapshot retains the exact rate instance' );
check_order_persistence( 'bgcs3_econt' === $snapshots['1']['method_id'], 'Quote snapshot retains the shipping method id' );
check_order_persistence( 14 === $snapshots['1']['instance_id'], 'Quote snapshot retains the shipping instance id' );
check_order_persistence( 3.50 === $snapshots['1']['total'], 'Quote snapshot retains the package shipping total' );
check_order_persistence( 3.50 === $snapshots['1']['shipping_total'], 'Quote snapshot names the net package shipping total explicitly' );
check_order_persistence( 0.70 === $snapshots['1']['shipping_tax'], 'Quote snapshot retains package shipping tax' );
check_order_persistence( 4.20 === $snapshots['1']['shipping_total_including_tax'], 'Quote snapshot retains the gross package shipping total' );
check_order_persistence( array( 'fuel' => 0.35 ) === $snapshots['1']['pricing']['surcharges'], 'Quote snapshot retains pricing surcharges' );
check_order_persistence( '2026-09-05' === $snapshots['1']['pricing']['delivery_estimate']['value'], 'Quote snapshot retains the courier delivery estimate' );

$precision_order = new WC_Order();
$precision_item  = new WC_Order_Item_Shipping(
	'bgcs3_pigeon',
	15,
	2.1583,
	array(
		'_bgcs3_selection'   => $selection->to_array(),
		'_bgcs3_validated'   => true,
		'_bgcs3_price_state' => 'calculated',
	),
	0.43
);
Order_Persistence::capture_quote_snapshot( $precision_item, 0, $precision_order );
$precision_snapshot = $precision_order->get_meta( Order_Persistence::QUOTE_SNAPSHOTS_META );
check_order_persistence( 2.5883 === $precision_snapshot['0']['shipping_total_including_tax'], 'Gross shipping total is stored without floating-point serialization noise' );

check_order_persistence( ! Order_Persistence::quote_snapshots_match( $mixed_order, $selection ), 'A missing BGCS package snapshot cannot pass order integrity' );
$second_rate_item = new WC_Order_Item_Shipping(
	'bgcs3_econt',
	27,
	2.25,
	array(
		'_bgcs3_selection'   => $selection->to_array(),
		'_bgcs3_validated'   => true,
		'_bgcs3_price_state' => 'calculated',
	)
);
Order_Persistence::capture_quote_snapshot( $second_rate_item, 2, $mixed_order );
check_order_persistence( Order_Persistence::quote_snapshots_match( $mixed_order, $selection ), 'Every BGCS package snapshot matches the canonical order selection' );
check_order_persistence( ! Order_Persistence::quote_snapshots_match( $mixed_order, bgcs_order_selection( 502 ) ), 'Stored quote snapshot rejects a later unpriced selection revision' );

$wrong_instance_order = new WC_Order(
	array( Order_Persistence::QUOTE_SNAPSHOTS_META => $mixed_order->get_meta( Order_Persistence::QUOTE_SNAPSHOTS_META ) ),
	array(
		new WC_Order_Item_Shipping( 'flat_rate', 8, 7.00 ),
		new WC_Order_Item_Shipping( 'bgcs3_econt', 14, 3.50, array(), 0.70 ),
		new WC_Order_Item_Shipping( 'bgcs3_econt', 28, 2.25 ),
	)
);
check_order_persistence( ! Order_Persistence::quote_snapshots_match( $wrong_instance_order, $selection ), 'A different persisted rate instance cannot reuse the quote snapshot' );

$wrong_total_order = new WC_Order(
	array( Order_Persistence::QUOTE_SNAPSHOTS_META => $mixed_order->get_meta( Order_Persistence::QUOTE_SNAPSHOTS_META ) ),
	array(
		new WC_Order_Item_Shipping( 'flat_rate', 8, 7.00 ),
		new WC_Order_Item_Shipping( 'bgcs3_econt', 14, 3.50, array(), 0.70 ),
		new WC_Order_Item_Shipping( 'bgcs3_econt', 27, 2.50 ),
	)
);
check_order_persistence( ! Order_Persistence::quote_snapshots_match( $wrong_total_order, $selection ), 'A changed persisted package total cannot reuse the quote snapshot' );

$wrong_tax_order = new WC_Order(
	array( Order_Persistence::QUOTE_SNAPSHOTS_META => $mixed_order->get_meta( Order_Persistence::QUOTE_SNAPSHOTS_META ) ),
	array(
		new WC_Order_Item_Shipping( 'flat_rate', 8, 7.00 ),
		new WC_Order_Item_Shipping( 'bgcs3_econt', 14, 3.50, array(), 0.60 ),
		new WC_Order_Item_Shipping( 'bgcs3_econt', 27, 2.25 ),
	)
);
check_order_persistence( ! Order_Persistence::quote_snapshots_match( $wrong_tax_order, $selection ), 'A changed persisted package tax cannot reuse the quote snapshot' );

$rebuilt_order = new WC_Order(
	array( Order_Persistence::QUOTE_SNAPSHOTS_META => $snapshots ),
	array( new WC_Order_Item_Shipping( 'flat_rate', 8, 7.00 ), $rate_item )
);
Order_Persistence::capture_quote_snapshot( new WC_Order_Item_Shipping( 'flat_rate', 8, 7.00 ), 0, $rebuilt_order );
check_order_persistence( '' === $rebuilt_order->get_meta( Order_Persistence::QUOTE_SNAPSHOTS_META ), 'A fresh external package 0 clears prior draft snapshots without adding BGCS meta' );
Order_Persistence::capture_quote_snapshot( $rate_item, 1, $rebuilt_order );
check_order_persistence( Order_Persistence::quote_snapshots_match( $rebuilt_order, $selection ), 'A rebuilt mixed draft retains only its current BGCS package snapshot' );

$checkout = ( new ReflectionClass( Checkout::class ) )->newInstanceWithoutConstructor();
$GLOBALS['bgcs_order_wc']->session->data[ Selection_Store::SESSION_KEY ] = $selection->to_array();
$persist_order = new WC_Order( array(), array( $rate_item ) );
Order_Persistence::capture_quote_snapshot( $rate_item, 0, $persist_order );
$checkout->persist( $persist_order );
check_order_persistence( $selection->to_array() == $persist_order->get_meta( Checkout::META_KEY ), 'Persist stores the exact canonical selection' );
check_order_persistence( '42' === $persist_order->get_meta( 'bgcs3_office_id' ), 'Persist writes the current readable office snapshot' );
check_order_persistence( 'Office 42' === $persist_order->get_shipping_address_1(), 'Persist aligns the shipping address with the selected office' );

$address_selection = Selection::from_array(
	array(
		'courier'       => 'econt',
		'delivery_type' => 'address',
		'country'       => 'BG',
		'city'          => array( 'id' => '7000', 'name' => 'Ruse', 'post_code' => '7000' ),
		'address'       => array( 'street' => 'Borisova', 'num' => '10' ),
		'revision'      => 502,
	)
);
$address_rate_item = new WC_Order_Item_Shipping(
	'bgcs3_econt',
	14,
	4.25,
	array(
		'_bgcs3_selection'   => $address_selection->to_array(),
		'_bgcs3_validated'   => true,
		'_bgcs3_price_state' => 'calculated',
	)
);
$GLOBALS['bgcs_order_wc']->session->data[ Selection_Store::SESSION_KEY ] = $address_selection->to_array();
$persist_order->set_shipping_methods( array( $address_rate_item ) );
Order_Persistence::capture_quote_snapshot( $address_rate_item, 0, $persist_order );
$checkout->persist( $persist_order );
check_order_persistence( '' === $persist_order->get_meta( 'bgcs3_office_id' ), 'Repeated draft persistence removes the previous office field' );
check_order_persistence( 'Borisova 10' === $persist_order->get_meta( 'bgcs3_address' ), 'Repeated draft persistence writes only the new address shape' );
check_order_persistence( 'Borisova 10' === $persist_order->get_shipping_address_1(), 'Repeated draft persistence replaces the shipping destination' );

$stale_after_quote = $address_selection->to_array();
$stale_after_quote['revision'] = 503;
$GLOBALS['bgcs_order_wc']->session->data[ Selection_Store::SESSION_KEY ] = $stale_after_quote;
$blocked = false;
try {
	$checkout->persist( $persist_order );
} catch ( Exception $error ) {
	$blocked = true;
}
check_order_persistence( $blocked, 'Persist aborts when the canonical selection is newer than the captured quote' );

echo PHP_EOL;
if ( $failures ) {
	echo "FAILED: {$failures} check(s)\n";
	exit( 1 );
}

echo "OK - order persistence integrity checks passed\n";
