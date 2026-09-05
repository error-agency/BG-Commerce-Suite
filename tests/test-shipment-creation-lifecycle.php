<?php
/** Phase 5: persisted shipment creation lifecycle and timeout ambiguity. */

define( 'ABSPATH', __DIR__ );

$failures = 0;
function check_lifecycle( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

require_once __DIR__ . '/lib/admin-order-harness.php';

use BgCommerce3\Admin\Order\Orders_Column;
use BgCommerce3\Container\Container;
use BgCommerce3\Shipping\Courier_Error;
use BgCommerce3\Shipping\Shipment_Creation;
use BgCommerce3\Shipping\Shipment_Reference;
use BgCommerce3\Support\Label_Result;

const LIFECYCLE_ORDER_ID = 8390;

class Lifecycle_Courier extends Test_Courier {
	public $mode;
	public $creates = 0;
	public $remote_calls = 0;
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
		$this->creates++;
		$started = Shipment_Creation::remote_started( $order, $this );
		if ( true !== $started ) {
			return $started;
		}
		$this->remote_calls++;

		if ( 'validation' === $this->mode ) {
			$error = Courier_Error::validation( 'invalid' );
			Shipment_Creation::remote_failed( $order, $error );
			return Label_Result::error( 'Fix the shipment data.' );
		}
		if ( 'network' === $this->mode ) {
			$error = Courier_Error::network( 'timeout', array( 'wp_error_code' => 'http_request_failed' ) );
			Shipment_Creation::remote_failed( $order, $error );
			return Label_Result::error( 'The provider did not answer.' );
		}

		Shipment_Creation::remote_accepted(
			$order,
			array(
				'shipment_number' => 'SHIP-1',
				'parcel_ids'      => array( 'PARCEL-1', 'PARCEL-2' ),
				'tracking_numbers' => array( 'TRACK-1', 'TRACK-2' ),
				'label_reference' => 'LABEL-1',
			)
		);
		if ( 'accepted_error' === $this->mode ) {
			return Label_Result::error( 'Local PDF write failed.' );
		}

		$result                      = new Label_Result();
		$result->success             = true;
		$result->courier             = 'speedy';
		$result->number              = 'TRACK-1';
		$result->created_at          = time();
		$result->shipment_number     = 'SHIP-1';
		$result->parcel_ids          = array( 'PARCEL-1', 'PARCEL-2' );
		$result->tracking_numbers    = array( 'TRACK-1', 'TRACK-2' );
		$result->label_reference     = 'LABEL-1';
		return $result;
	}
}

function seed_lifecycle() {
	Fake_Order_Store::seed(
		LIFECYCLE_ORDER_ID,
		array(
			'_bgcs3_selection' => array( 'courier' => 'speedy', 'delivery_type' => 'address' ),
			'_bgcs3_wb'        => array(),
			'_bgcs3_preflight' => array( 'payload' => array( 'fingerprint' => str_repeat( 'a', 64 ) ) ),
		)
	);
	Fake_Order_Store::$on_save = null;
	$GLOBALS['wpdb']->rows     = array();
	$GLOBALS['bgcs_cache']     = array();
	$_POST                     = array( 'order_id' => LIFECYCLE_ORDER_ID, 'nonce' => 'x' );
}

function lifecycle_handler( Lifecycle_Courier $courier ) {
	$container            = new Container();
	$container['modules'] = new Fake_Modules( $courier );
	return array( new Orders_Column( $container ), 'ajax_quick_create_label' );
}

echo "--- Permanent provider rejection may be corrected and retried ---\n";
seed_lifecycle();
$courier = new Lifecycle_Courier( 'validation' );
$first   = run_request( lifecycle_handler( $courier ) );
$state   = Fake_Order_Store::$rows[ LIFECYCLE_ORDER_ID ][ Shipment_Creation::META_KEY ];
check_lifecycle( false === $first['ok'], 'The validation refusal is returned to the merchant' );
check_lifecycle( Shipment_Creation::FAILED === $state['status'], 'A definite validation refusal is recorded as failed' );
$second = run_request( lifecycle_handler( $courier ) );
check_lifecycle( 2 === $courier->creates, 'A corrected/permanent refusal is not mistaken for an existing shipment' );

echo "--- Timeout ambiguity blocks blind retry ---\n";
seed_lifecycle();
$courier = new Lifecycle_Courier( 'network' );
$first   = run_request( lifecycle_handler( $courier ) );
$state   = Fake_Order_Store::$rows[ LIFECYCLE_ORDER_ID ][ Shipment_Creation::META_KEY ];
check_lifecycle( Shipment_Creation::AMBIGUOUS === $state['status'], 'A network failure after remote start is ambiguous' );
check_lifecycle( 'network' === $state['error_type'], 'The safe error class is persisted without provider prose' );
$second = run_request( lifecycle_handler( $courier ) );
check_lifecycle( false === $second['ok'], 'The next admin action is blocked before another create call' );
check_lifecycle( 1 === $courier->creates, 'The ambiguous request reached the courier only once' );
check_lifecycle( false !== stripos( $second['payload']['message'], 'may already exist' ), 'The merchant is told to reconcile the courier state' );

echo "--- Provider acceptance survives a local label failure ---\n";
seed_lifecycle();
$courier = new Lifecycle_Courier( 'accepted_error' );
$first   = run_request( lifecycle_handler( $courier ) );
$state   = Fake_Order_Store::$rows[ LIFECYCLE_ORDER_ID ][ Shipment_Creation::META_KEY ];
check_lifecycle( false === $first['ok'], 'The local post-create error is visible' );
check_lifecycle( Shipment_Creation::ACCEPTED === $state['status'], 'Provider acceptance is retained instead of being downgraded to failure' );
check_lifecycle( 'SHIP-1' === $state['identity']['shipment_number'], 'The remote shipment identity is already durable' );
$second = run_request( lifecycle_handler( $courier ) );
check_lifecycle( 1 === $courier->creates, 'An accepted shipment cannot be created again blindly' );

echo "--- Successful completion persists the full canonical identity ---\n";
seed_lifecycle();
$courier = new Lifecycle_Courier( 'success' );
$result  = run_request( lifecycle_handler( $courier ) );
$row     = Fake_Order_Store::$rows[ LIFECYCLE_ORDER_ID ];
$label   = $row['_bgcs3_label'];
$state   = $row[ Shipment_Creation::META_KEY ];
check_lifecycle( true === $result['ok'], 'The admin action succeeds' );
check_lifecycle( Shipment_Creation::CREATED === $state['status'], 'The lifecycle reaches created only after local completion' );
check_lifecycle( 'SHIP-1' === $label['shipment_number'], 'Shipment number is canonical' );
check_lifecycle( array( 'PARCEL-1', 'PARCEL-2' ) === $label['parcel_ids'], 'All parcel IDs are persisted' );
check_lifecycle( array( 'TRACK-1', 'TRACK-2' ) === $label['tracking_numbers'], 'All tracking numbers are persisted' );
check_lifecycle( 'LABEL-1' === $label['label_reference'], 'Label reference is persisted' );
check_lifecycle( 'production' === $label['environment'], 'Courier environment is persisted' );
check_lifecycle( str_repeat( 'a', 64 ) === $label['payload_fingerprint'], 'Exact payload fingerprint is linked to the label' );
check_lifecycle( 'missing' === $label['label_status'], 'A missing PDF is truthful and does not erase the shipment' );

echo "--- Explicit cancel edition permits one genuinely new shipment ---\n";
$order = new WC_Order( LIFECYCLE_ORDER_ID );
$order->delete_meta_data( '_bgcs3_label' );
Shipment_Reference::bump_edition( $order );
$order->save();
$second = run_request( lifecycle_handler( $courier ) );
check_lifecycle( true === $second['ok'], 'A new edition can create after confirmed cancel policy' );
check_lifecycle( 2 === $courier->creates, 'Exactly one new provider create call was made for the new edition' );
check_lifecycle( 2 === Fake_Order_Store::$rows[ LIFECYCLE_ORDER_ID ][ Shipment_Creation::META_KEY ]['edition'], 'The new attempt is tied to edition 2' );

echo "--- Direct courier calls bootstrap the same persisted guard ---\n";
seed_lifecycle();
$courier = new Lifecycle_Courier( 'success' );
$first   = $courier->create_label( new WC_Order( LIFECYCLE_ORDER_ID ) );
$state   = Fake_Order_Store::$rows[ LIFECYCLE_ORDER_ID ][ Shipment_Creation::META_KEY ];
$second  = $courier->create_label( new WC_Order( LIFECYCLE_ORDER_ID ) );
check_lifecycle( true === $first->success, 'A direct first call can reach the provider' );
check_lifecycle( Shipment_Creation::ACCEPTED === $state['status'], 'The provider identity is durable without the admin orchestration layer' );
check_lifecycle( false === $second->success, 'A repeated direct call is refused while the accepted shipment is unresolved locally' );
check_lifecycle( 1 === $courier->remote_calls, 'Only one destructive provider call is allowed' );

echo "--- Static provider-boundary guards ---\n";
$root = dirname( __DIR__ );
foreach ( array(
	'Speedy' => array( 'app/Modules/Shipping/Speedy/Speedy.php', 'create_shipment' ),
	'Econt'  => array( 'app/Modules/Shipping/Econt/Econt.php', 'Client::LABEL_CREATE, $create_payload' ),
	'BOX NOW' => array( 'app/Modules/Shipping/BoxNow/BoxNow.php', 'create_delivery_request' ),
	'Pigeon' => array( 'app/Modules/Shipping/Pigeon/Pigeon.php', 'create_shipment' ),
) as $name => $spec ) {
	$code       = file_get_contents( $root . '/' . $spec[0] );
	$create_at  = strpos( $code, $spec[1] );
	$started_at = strrpos( substr( $code, 0, $create_at ), 'Shipment_Creation::remote_started' );
	$accepted_at = strpos( $code, 'Shipment_Creation::remote_accepted', $create_at );
	check_lifecycle( false !== $started_at && $started_at < $create_at, $name . ' persists remote_pending before the destructive call' );
	check_lifecycle( false !== $accepted_at && $accepted_at > $create_at, $name . ' persists provider identity after acceptance' );
}

echo PHP_EOL;
if ( $failures ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK - all shipment creation lifecycle checks passed' . PHP_EOL;
