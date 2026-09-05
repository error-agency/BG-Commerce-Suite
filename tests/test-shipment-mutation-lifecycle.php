<?php
/** Phase 6: cancellation, historical trace and replacement safety. */

define( 'ABSPATH', __DIR__ );

$failures = 0;
function check_mutation( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

require_once __DIR__ . '/lib/admin-order-harness.php';

use BgCommerce3\Admin\Order\MetaBox;
use BgCommerce3\Container\Container;
use BgCommerce3\Shipping\Courier_Error;
use BgCommerce3\Shipping\Shipment_Creation;
use BgCommerce3\Shipping\Shipment_Mutation;
use BgCommerce3\Shipping\Shipment_Reference;

const MUTATION_ORDER_ID = 8391;

class Mutation_Courier extends Test_Courier {
	public $mode;
	public $cancel_calls = 0;
	public function __construct( $mode ) {
		$this->mode = $mode;
	}
	public function id() {
		return 'speedy';
	}
	public function preflight_environment() {
		return 'production';
	}
	public function create_label( \WC_Order $order ) {
		unset( $order );
		return null;
	}
	public function delete_label( \WC_Order $order ) {
		if ( ! Shipment_Mutation::remote_started( $order, $this ) ) {
			return false;
		}
		$this->cancel_calls++;
		if ( 'network' === $this->mode ) {
			Shipment_Mutation::remote_failed( $order, Courier_Error::network( 'timeout' ) );
			return false;
		}
		if ( 'refused' === $this->mode ) {
			Shipment_Mutation::remote_failed( $order, Courier_Error::validation( 'cannot cancel' ) );
			return false;
		}
		Shipment_Mutation::remote_confirmed( $order, 'provider_response' );
		return true;
	}
}

function seed_mutation() {
	Fake_Order_Store::seed(
		MUTATION_ORDER_ID,
		array(
			'_bgcs3_selection' => array( 'courier' => 'speedy', 'delivery_type' => 'address' ),
			'_bgcs3_label'     => array(
				'number'              => 'TRACK-OLD',
				'courier'             => 'speedy',
				'shipment_number'     => 'SHIP-OLD',
				'parcel_ids'          => array( 'TRACK-OLD' ),
				'tracking_numbers'    => array( 'TRACK-OLD' ),
				'label_reference'     => 'LABEL-OLD',
				'environment'         => 'production',
				'payload_fingerprint' => str_repeat( 'b', 64 ),
				'created_at'          => 1700000000,
				'meta'                => array( 'shipment_reference' => 'site-8391-1' ),
			),
			'_bgcs3_tracking'  => array( 'state' => 'in_transit', 'events' => array( array( 'code' => 'X' ) ) ),
			'_bgcs3_creation'  => array( 'status' => 'created', 'reference' => 'site-8391-1' ),
		)
	);
	Fake_Order_Store::$on_save = null;
	$GLOBALS['wpdb']->rows     = array();
	$GLOBALS['bgcs_cache']     = array();
	$_POST                     = array( 'order_id' => MUTATION_ORDER_ID, 'nonce' => 'x' );
}

function mutation_handler( Mutation_Courier $courier ) {
	$container            = new Container();
	$container['modules'] = new Fake_Modules( $courier );
	return array( new MetaBox( $container ), 'ajax_delete_label' );
}

echo "--- Confirmed cancellation archives before replacement ---\n";
seed_mutation();
$courier = new Mutation_Courier( 'success' );
$result  = run_request( mutation_handler( $courier ) );
$row     = Fake_Order_Store::$rows[ MUTATION_ORDER_ID ];
$state   = $row[ Shipment_Mutation::META_KEY ];
$history = $row[ Shipment_Mutation::HISTORY_KEY ];
check_mutation( true === $result['ok'], 'The explicit cancel action succeeds' );
check_mutation( 1 === $courier->cancel_calls, 'Exactly one provider cancel call was made' );
check_mutation( ! isset( $row['_bgcs3_label'] ), 'The active label is removed only after confirmation' );
check_mutation( ! isset( $row['_bgcs3_tracking'] ), 'Active tracking is cleared with the confirmed shipment' );
check_mutation( ! isset( $row['_bgcs3_creation'] ), 'The old active creation state is no longer presented as current' );
check_mutation( Shipment_Mutation::CANCELLED === $state['status'], 'The local mutation reaches cancelled' );
check_mutation( 2 === $row[ Shipment_Reference::META_EDITION ], 'The replacement receives the next stable edition' );
check_mutation( 1 === count( $history ), 'One immutable history entry is appended' );
check_mutation( 'SHIP-OLD' === $history[0]['identity']['shipment_number'], 'History keeps the old provider identity' );
check_mutation( str_repeat( 'b', 64 ) === $history[0]['payload_fingerprint'], 'History keeps the old payload fingerprint' );
check_mutation( false === strpos( serialize( $history ), 'Тест Клиент' ) && false === strpos( serialize( $history ), 'test@example.com' ), 'History contains no recipient PII' );

echo "--- Definite provider refusal preserves the active shipment ---\n";
seed_mutation();
$courier = new Mutation_Courier( 'refused' );
$result  = run_request( mutation_handler( $courier ) );
$row     = Fake_Order_Store::$rows[ MUTATION_ORDER_ID ];
check_mutation( false === $result['ok'], 'The merchant sees a cancellation failure' );
check_mutation( 'TRACK-OLD' === $row['_bgcs3_label']['number'], 'The active label is preserved' );
check_mutation( isset( $row['_bgcs3_tracking'] ), 'Tracking history is preserved' );
check_mutation( 1 === Shipment_Reference::edition( new WC_Order( MUTATION_ORDER_ID ) ), 'The edition is not advanced' );
check_mutation( Shipment_Mutation::CANCEL_FAILED === $row[ Shipment_Mutation::META_KEY ]['status'], 'The definite refusal is explicit' );
check_mutation( ! isset( $row[ Shipment_Mutation::HISTORY_KEY ] ), 'A failed cancel is not written as history' );

echo "--- Timeout ambiguity blocks a blind second cancellation/replacement ---\n";
seed_mutation();
$courier = new Mutation_Courier( 'network' );
$first   = run_request( mutation_handler( $courier ) );
$second  = run_request( mutation_handler( $courier ) );
$row     = Fake_Order_Store::$rows[ MUTATION_ORDER_ID ];
check_mutation( false === $first['ok'] && false === $second['ok'], 'Both requests fail safely for the merchant' );
check_mutation( 1 === $courier->cancel_calls, 'The ambiguous provider call is never repeated blindly' );
check_mutation( Shipment_Mutation::CANCEL_AMBIGUOUS === $row[ Shipment_Mutation::META_KEY ]['status'], 'Timeout ambiguity is durable' );
check_mutation( isset( $row['_bgcs3_label'] ), 'The shipment remains active in BGCS' );
check_mutation( false !== stripos( $second['payload']['message'], 'uncertain' ), 'The merchant is directed to the courier portal' );
$unsafe = new WC_Order( MUTATION_ORDER_ID );
$unsafe->delete_meta_data( '_bgcs3_label' );
$unsafe->save();
$replacement = Shipment_Creation::start( $unsafe, $courier );
check_mutation( true !== $replacement, 'Replacement stays blocked even if external code removes the active label incorrectly' );

echo "--- Confirmed remote cancel recovers without a second API call ---\n";
seed_mutation();
$order   = new WC_Order( MUTATION_ORDER_ID );
$courier = new Mutation_Courier( 'success' );
Shipment_Mutation::start_cancel( $order, $courier );
Shipment_Mutation::remote_started( $order, $courier );
Shipment_Mutation::remote_confirmed( $order, 'provider_response' );
$result = run_request( mutation_handler( $courier ) );
$row    = Fake_Order_Store::$rows[ MUTATION_ORDER_ID ];
check_mutation( true === $result['ok'], 'Local cleanup resumes from durable confirmation' );
check_mutation( 0 === $courier->cancel_calls, 'Recovery does not send another provider cancel' );
check_mutation( ! isset( $row['_bgcs3_label'] ), 'The recovered action clears the active label' );
check_mutation( 1 === count( $row[ Shipment_Mutation::HISTORY_KEY ] ), 'Recovery appends the history exactly once' );

echo "--- Concurrent cancel requests share the creation mutex ---\n";
seed_mutation();
$courier   = new Mutation_Courier( 'success' );
$concurrent = null;
Fake_Order_Store::$on_save = static function () use ( &$concurrent, $courier ) {
	$concurrent = run_request( mutation_handler( $courier ) );
};
$first = run_request( mutation_handler( $courier ) );
Fake_Order_Store::$on_save = null;
check_mutation( true === $first['ok'], 'The first cancellation completes' );
check_mutation( is_array( $concurrent ) && false === $concurrent['ok'], 'The overlapping cancellation is refused' );
check_mutation( 1 === $courier->cancel_calls, 'Concurrent admin actions produce one provider cancel call' );

echo "--- Static mutation guards ---\n";
$root     = dirname( __DIR__ );
$admin    = file_get_contents( $root . '/app/Admin/Order/MetaBox.php' );
$cancel   = substr( $admin, strpos( $admin, 'public function ajax_delete_label' ) );
$abstract = file_get_contents( $root . '/app/Modules/Shipping/Abstract_Courier.php' );
$econt    = file_get_contents( $root . '/app/Modules/Shipping/Econt/Econt.php' );
$speedy   = file_get_contents( $root . '/app/Modules/Shipping/Speedy/Speedy.php' );
$boxnow   = file_get_contents( $root . '/app/Modules/Shipping/BoxNow/BoxNow.php' );
$pigeon   = file_get_contents( $root . '/app/Modules/Shipping/Pigeon/Pigeon.php' );
check_mutation( false !== strpos( $admin, "'bgcs3_create_lock_'" ), 'Create and cancel share the same per-order lock namespace' );
check_mutation( strpos( $cancel, 'Shipment_Mutation::complete_cancel' ) < strpos( $cancel, 'add_order_note' ), 'History/local cleanup completes before the success note' );
check_mutation( false !== strpos( $abstract, 'Shipment_Mutation::remote_started' ) && false !== strpos( $abstract, 'Shipment_Mutation::remote_confirmed' ), 'Built-in couriers persist the provider cancel boundary' );
check_mutation( false !== strpos( $econt, "result['shipmentNum']" ) && false !== strpos( $econt, "result['error']" ), 'Econt verifies its documented per-shipment delete result' );
check_mutation( false !== strpos( $speedy, 'label_meta( $order, \'shipment_id\'' ), 'Speedy cancels the provider shipment ID rather than the tracking barcode' );
check_mutation( false !== strpos( $boxnow, 'label_meta( $order, \'parcel_ids\'' ) && false !== strpos( $boxnow, 'bgcs3_boxnow_cancel_partial' ), 'BOX NOW requires every parcel cancellation to succeed' );
check_mutation( false !== strpos( $pigeon, 'client()->cancel( $number )' ), 'Pigeon cancels the active provider reference' );
check_mutation( false !== strpos( $admin, "__( 'Active shipment'" ) && false !== strpos( $admin, "__( 'Replacement blocked'" ), 'Order admin explicitly distinguishes the active shipment and blocked replacement' );

echo PHP_EOL;
if ( $failures ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK - all shipment mutation lifecycle checks passed' . PHP_EOL;
