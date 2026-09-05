<?php
/**
 * BGCS-AUDIT-001 — integration test for the two admin create-label endpoints.
 *
 * Drives the REAL `MetaBox::ajax_create_label()` and
 * `Orders_Column::ajax_quick_create_label()` through the REAL `Creation_Lock`
 * (against an in-memory options table with MySQL semantics) and re-enters the
 * handler from inside the courier call — the exact interleaving the live audit
 * reproduced, where 3 of 3 concurrent callers reached `create_label()`.
 *
 * Two distinct windows are covered:
 *   1. a second request arriving while the first is at the courier;
 *   2. a second request arriving after the courier answered but before
 *      `_bgcs3_label` is persisted — the window that existed because the lock
 *      used to be released the moment `create_label()` returned.
 *
 * Run: php tests/test-label-create-concurrency.php
 */

define( 'ABSPATH', __DIR__ );

$failures = 0;
function check_create( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

require_once __DIR__ . '/lib/admin-order-harness.php';

use BgCommerce3\Admin\Order\MetaBox;
use BgCommerce3\Admin\Order\Orders_Column;
use BgCommerce3\Container\Container;
use BgCommerce3\Support\Label_Result;



/** Courier double that counts real create_label() calls. */
class Counting_Courier extends Test_Courier {

	/** @var int */
	public $creates = 0;

	/** @var callable|null Fires while "at the courier". */
	public $during_create = null;

	public function id() {
		return 'speedy';
	}

	public function create_label( \WC_Order $order ) {
		$this->creates++;
		$number = '99999' . $this->creates;

		$hook = $this->during_create;
		if ( is_callable( $hook ) ) {
			// One-shot: the interleaved request must not recurse forever.
			$this->during_create = null;
			call_user_func( $hook );
		}

		$result             = new Label_Result();
		$result->success    = true;
		$result->courier    = 'speedy';
		$result->number     = $number;
		$result->created_at = time();
		return $result;
	}
}

/** Courier double whose create always fails, e.g. a pre-flight guard refusing. */
class Failing_Courier extends Test_Courier {

	public function id() {
		return 'speedy';
	}

	public function create_label( \WC_Order $order ) {
		return Label_Result::error( 'Куриерът отказа пратката.' );
	}
}


// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

define( 'BGCS_TEST_ORDER_ID', 8264 );
define( 'BGCS_TEST_LOCK_KEY', 'bgcs3_create_lock_' . BGCS_TEST_ORDER_ID );

function reset_world( $courier = null ) {
	Fake_Order_Store::seed(
		BGCS_TEST_ORDER_ID,
		array(
			'_bgcs3_selection' => array( 'courier' => 'speedy', 'delivery_type' => 'address' ),
			'_bgcs3_wb'        => array(),
		)
	);
	Fake_Order_Store::$on_save = null;
	$GLOBALS['wpdb']->rows     = array();
	$GLOBALS['bgcs_cache']     = array();
	$_POST                     = array( 'order_id' => BGCS_TEST_ORDER_ID, 'nonce' => 'x' );

	if ( $courier instanceof Counting_Courier ) {
		$courier->creates       = 0;
		$courier->during_create = null;
	}
}


function lock_is_held() {
	return isset( $GLOBALS['wpdb']->rows[ BGCS_TEST_LOCK_KEY ] );
}

// The module registry is not booted here, so the field set Module_Settings
// would compose is seeded directly — with the default Speedy really declares.
\BgCommerce3\Support\Module_Settings::prime( 'speedy', array( 'service_payer' => array( 'default' => 'SENDER' ) ) );

$courier   = new Counting_Courier();
$container = new Container();

$container['modules'] = new Fake_Modules( $courier );
$metabox              = new MetaBox( $container );
$column               = new Orders_Column( $container );

$failing_container             = new Container();
$failing_container['modules']  = new Fake_Modules( new Failing_Courier() );
$failing_metabox               = new MetaBox( $failing_container );
$failing_column                = new Orders_Column( $failing_container );

$endpoints = array(
	array(
		'label'   => 'MetaBox::ajax_create_label',
		'handler' => array( $metabox, 'ajax_create_label' ),
		'failing' => array( $failing_metabox, 'ajax_create_label' ),
	),
	array(
		'label'   => 'Orders_Column::ajax_quick_create_label',
		'handler' => array( $column, 'ajax_quick_create_label' ),
		'failing' => array( $failing_column, 'ajax_quick_create_label' ),
	),
);

foreach ( $endpoints as $endpoint ) {
	$label   = $endpoint['label'];
	$handler = $endpoint['handler'];

	echo "--- {$label}: a second request arriving while the first is at the courier ---\n";
	reset_world( $courier );
	$second                 = null;
	$courier->during_create = function () use ( $handler, &$second ) {
		$second = run_request( $handler );
	};
	$first = run_request( $handler );

	check_create( true === $first['ok'], 'The first request succeeds' );
	check_create( is_array( $second ) && false === $second['ok'], 'The concurrent request is refused' );
	check_create(
		is_array( $second ) && isset( $second['payload']['message'] )
			&& false !== stripos( (string) $second['payload']['message'], 'already in progress' ),
		'…with the "creation is already in progress" message'
	);
	check_create( 1 === $courier->creates, 'create_label() ran exactly once (was 3 of 3 before the fix)' );
	check_create(
		isset( Fake_Order_Store::$rows[ BGCS_TEST_ORDER_ID ]['_bgcs3_label']['number'] )
			&& '999991' === Fake_Order_Store::$rows[ BGCS_TEST_ORDER_ID ]['_bgcs3_label']['number'],
		'The order carries the one shipment that was actually created'
	);
	check_create(
		isset( Fake_Order_Store::$rows[ BGCS_TEST_ORDER_ID ]['_bgcs3_label']['meta']['shipment_reference'] ),
		'The stable shipment reference is persisted with the label'
	);

	echo "--- {$label}: a second request arriving before the label is persisted ---\n";
	// This is the window the old code left open by releasing the lock as soon as
	// create_label() returned: the courier already has a shipment, but the order
	// does not carry it yet, so the Rule 24 pre-check cannot see it either.
	reset_world( $courier );
	$second                    = null;
	Fake_Order_Store::$on_save = function () use ( $handler, &$second ) {
		$second = run_request( $handler );
	};
	$first = run_request( $handler );

	check_create( true === $first['ok'], 'The first request succeeds' );
	check_create( is_array( $second ) && false === $second['ok'], 'The request landing in the persist window is refused' );
	check_create( 1 === $courier->creates, 'create_label() still ran exactly once' );

	echo "--- {$label}: Rule 24 — an order that already has a shipment ---\n";
	reset_world( $courier );
	$rows                                        = Fake_Order_Store::$rows[ BGCS_TEST_ORDER_ID ];
	$rows['_bgcs3_label']                        = array( 'courier' => 'speedy', 'number' => '1051604239739' );
	Fake_Order_Store::$rows[ BGCS_TEST_ORDER_ID ] = $rows;
	$blocked                                     = run_request( $handler );
	check_create( false === $blocked['ok'], 'A second create on an order with an active shipment is refused' );
	check_create( 0 === $courier->creates, 'The courier API was never called' );

	echo "--- {$label}: the lock does not outlive the request ---\n";
	reset_world( $courier );
	$first = run_request( $handler );
	check_create( true === $first['ok'], 'The create succeeds' );
	check_create( ! lock_is_held(), 'No lock row is left behind after success' );

	reset_world( $courier );
	$failed = run_request( $endpoint['failing'] );
	check_create( false === $failed['ok'], 'A courier error is reported to the merchant' );
	check_create( ! lock_is_held(), 'A failed create does not leave the order locked for the retry' );
}

echo "--- The lock ran against real SQL shapes ---\n";
check_create( array() === $GLOBALS['wpdb']->unrecognized, 'Every statement the lock issued matched a known shape: ' . implode( ' | ', $GLOBALS['wpdb']->unrecognized ) );

echo "--- Static guard: the lock must not be released before the label is written ---\n";
$guarded = array(
	'/Admin/Order/MetaBox.php'       => 'ajax_create_label',
	'/Admin/Order/Orders_Column.php' => 'ajax_quick_create_label',
);
foreach ( $guarded as $file => $method ) {
	$code = php_strip_whitespace( dirname( __DIR__ ) . '/app' . $file );
	$body = substr( $code, strpos( $code, 'function ' . $method ) );
	// From the acquire onwards, so the Rule 24 pre-check's *read* of
	// `_bgcs3_label` is not mistaken for the write.
	$body = substr( $body, strpos( $body, '->acquire(' ) );

	$write_at  = preg_match( '/update_meta_data\(\s*\'_bgcs3_label\'/', $body, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : false;
	$create_at = strpos( $body, 'create_label(' );

	check_create( false !== $write_at, $method . '() writes _bgcs3_label after acquiring the lock' );
	check_create( false !== $create_at && $create_at < $write_at, $method . '() calls the courier before persisting the label' );
	// The pre-fix shape released in a `finally` right after create_label(), so
	// no release survived past the write. The lock must still be held here.
	check_create(
		false !== $write_at && false !== strpos( $body, '->release(', $write_at ),
		$method . '() still holds the lock while _bgcs3_label is persisted'
	);
}

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all create-label concurrency checks passed' . PHP_EOL;
