<?php
/** Phase 9: canonical pickup request lifecycle and provider wiring. */

define( 'ABSPATH', __DIR__ );

$failures = 0;
function check_pickup( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

require_once __DIR__ . '/lib/admin-order-harness.php';
require_once dirname( __DIR__ ) . '/app/Shipping/Pickup_Request.php';
require_once dirname( __DIR__ ) . '/app/Modules/Shipping/Pigeon/Courier_Request.php';

use BgCommerce3\Shipping\Creation_Lock;
use BgCommerce3\Shipping\Pickup_Request;
use BgCommerce3\Modules\Shipping\Pigeon\Courier_Request;

echo "--- Canonical identity and provider status ---\n";
$shipments = array(
	array( 'order_id' => 9101, 'waybill' => 'WB-2', 'shipment_reference' => 'site-9101-1' ),
	array( 'order_id' => 9102, 'waybill' => 'WB-1', 'shipment_reference' => 'site-9102-1' ),
	array( 'order_id' => 9101, 'waybill' => 'WB-2', 'shipment_reference' => 'site-9101-1' ),
);
$record = Pickup_Request::record( 'pigeon', 'REQ-9', 'created', '2026-09-01', '09:00', '12:00', $shipments, 'fp', 1700000000 );
check_pickup( 'REQ-9' === $record['id'] && 'REQ-9' === $record['number'], 'Canonical and legacy request identifiers agree' );
check_pickup( Pickup_Request::PENDING === $record['status'], 'Provider-created state normalizes to pending' );
check_pickup( 2 === count( $record['shipments'] ), 'Repeated shipment references are deduplicated' );
check_pickup( Pickup_Request::is_active( $record ), 'Pending request blocks a duplicate create' );
$record['status'] = Pickup_Request::COLLECTED;
check_pickup( ! Pickup_Request::is_active( $record ), 'Collected request is terminal' );
check_pickup( Pickup_Request::REJECTED === Pickup_Request::status( 'reject_client' ), 'Provider rejection normalizes consistently' );

echo "--- Fingerprint is stable and PII-free ---\n";
$payload_a = array( 'requestTimeTo' => 12, 'senderClient' => array( 'name' => 'Secret Person' ), 'attachShipments' => array( 'WB-2', 'WB-1' ) );
$payload_b = array( 'attachShipments' => array( 'WB-1', 'WB-2' ), 'senderClient' => array( 'name' => 'Secret Person' ), 'requestTimeTo' => 12 );
$fingerprint_a = Pickup_Request::fingerprint( 'econt', $payload_a, $shipments );
$fingerprint_b = Pickup_Request::fingerprint( 'econt', $payload_b, array_reverse( $shipments ) );
check_pickup( $fingerprint_a === $fingerprint_b, 'Equivalent payload and shipment ordering has one identity' );
check_pickup( 64 === strlen( $fingerprint_a ) && false === strpos( $fingerprint_a, 'Secret' ), 'Stored identity is a PII-free SHA-256 digest' );

echo "--- Atomic duplicate protection ---\n";
$lock_rows = array();
$lock = new Creation_Lock(
	array(
		'insert_if_absent' => static function ( $key, $owner ) use ( &$lock_rows ) {
			if ( isset( $lock_rows[ $key ] ) ) return false;
			$lock_rows[ $key ] = $owner;
			return true;
		},
		'read_owner' => static function ( $key ) use ( &$lock_rows ) { return isset( $lock_rows[ $key ] ) ? $lock_rows[ $key ] : ''; },
		'replace_owner' => static function () { return false; },
		'delete_if_owned' => static function ( $key, $owner ) use ( &$lock_rows ) {
			if ( isset( $lock_rows[ $key ] ) && $lock_rows[ $key ] === $owner ) { unset( $lock_rows[ $key ] ); return true; }
			return false;
		},
	)
);
Pickup_Request::set_lock( $lock );
$owner = Pickup_Request::acquire( 'econt' );
check_pickup( is_string( $owner ) && '' !== $owner, 'First pickup mutation acquires the courier lock' );
check_pickup( false === Pickup_Request::acquire( 'econt' ), 'Concurrent duplicate pickup mutation is refused' );
Pickup_Request::release( 'econt', $owner );
check_pickup( false !== Pickup_Request::acquire( 'econt' ), 'Lock is available again after the operation finishes' );
Pickup_Request::set_lock();

echo "--- Order association, status and cancel/reject cleanup ---\n";
Fake_Order_Store::seed( 9101, array() );
Fake_Order_Store::seed( 9102, array() );
$record = Pickup_Request::record( 'pigeon', 'REQ-10', 'pending', '2026-09-02', '10:00', '12:00', $shipments, $fingerprint_a, 1700000100 );
Pickup_Request::attach_orders( $record, '_bgcs3_pigeon_courier_request' );
$first = Fake_Order_Store::$rows[9101];
check_pickup( 'REQ-10' === $first[ Pickup_Request::META_KEY ]['id'], 'Order stores the canonical pickup request ID' );
check_pickup( 'site-9101-1' === $first[ Pickup_Request::META_KEY ]['shipment_reference'], 'Order association keeps the stable shipment reference' );
check_pickup( 'WB-2' === $first[ Pickup_Request::META_KEY ]['waybill'], 'Order association keeps the provider waybill' );
check_pickup( 'REQ-10' === $first['_bgcs3_pigeon_courier_request'], 'Legacy provider marker remains compatible' );
$record['status'] = Pickup_Request::PROCESSING;
$record['updated_at'] = 1700000200;
Pickup_Request::update_orders( $record );
check_pickup( Pickup_Request::PROCESSING === Fake_Order_Store::$rows[9101][ Pickup_Request::META_KEY ]['status'], 'Status refresh reaches every attached order' );
Pickup_Request::detach_orders( $record, '_bgcs3_pigeon_courier_request' );
$first = Fake_Order_Store::$rows[9101];
check_pickup( ! isset( $first[ Pickup_Request::META_KEY ] ) && ! isset( $first['_bgcs3_pigeon_courier_request'] ), 'Cancelled/rejected request releases both canonical and legacy active markers' );
check_pickup( 1 === count( $first[ Pickup_Request::META_HISTORY_KEY ] ), 'Released association is retained in bounded history' );

echo "--- Pigeon scheduling and attachment contract ---\n";
$body = Courier_Request::build(
	array( 'date' => '2026-09-02', 'time_type' => 'interval', 'time_from' => '9:00', 'time_to' => '12:30', 'additional_info' => '' ),
	array( 'city_id' => 1, 'street_id' => 2, 'street_number' => '10' ),
	array( 'WB-1', 'WB-1', 'WB-2' )
);
check_pickup( ! is_wp_error( $body ), 'Valid pickup window builds a provider request' );
check_pickup( array( 'WB-1', 'WB-2' ) === $body['shipment_references'], 'Provider request attaches unique prepared shipments' );
check_pickup( is_wp_error( Courier_Request::build( array( 'date' => '2026-02-31', 'time_from' => '09:00', 'time_to' => '12:00' ), array( 'city_id' => 1, 'street_id' => 2 ) ) ), 'Impossible calendar date is rejected locally' );

echo "--- Static provider capability guards ---\n";
$root   = dirname( __DIR__ );
$econt  = file_get_contents( $root . '/app/Modules/Shipping/Econt/Econt.php' );
$eclient = file_get_contents( $root . '/app/Modules/Shipping/Econt/Client.php' );
$pigeon = file_get_contents( $root . '/app/Modules/Shipping/Pigeon/Pigeon.php' );
$pclient = file_get_contents( $root . '/app/Modules/Shipping/Pigeon/Client.php' );
$metabox = file_get_contents( $root . '/app/Admin/Order/MetaBox.php' );
check_pickup( false !== strpos( $econt, 'Pickup_Request::acquire( self::ID )' ), 'Econt create/status operations share the atomic pickup lock' );
check_pickup( false !== strpos( $econt, 'Pickup_Request::attach_orders' ), 'Econt persists canonical shipment associations' );
check_pickup( false === strpos( $eclient, 'cancel_courier_request' ), 'Econt does not advertise an undocumented pickup cancellation endpoint' );
check_pickup( false !== strpos( $pigeon, 'refresh_courier_request' ) && false !== strpos( $pclient, 'get_courier_request' ), 'Pigeon exposes real provider status refresh' );
check_pickup( false !== strpos( $pigeon, 'Pickup_Request::detach_orders' ), 'Pigeon cancellation releases shipment markers only after provider success' );
check_pickup( false !== strpos( $pigeon, "'Last update'" ) && false !== strpos( $econt, "'Last update'" ), 'Both admin panels expose the last provider update' );
check_pickup( false !== strpos( $econt, 'Every pickup request must be linked to a prepared shipment' ), 'Econt refuses a pickup request without a concrete shipment' );
check_pickup( false !== strpos( $pigeon, 'Every pickup request must be linked to a prepared shipment' ), 'Pigeon refuses a pickup request without a concrete shipment' );
foreach ( array( 'Pickup request ID', 'Pickup status', 'Pickup shipment', 'Pickup date', 'Pickup last update' ) as $label ) {
	check_pickup( false !== strpos( $metabox, "'{$label}'" ), "Order admin exposes {$label}" );
}

if ( $failures ) {
	echo "\n{$failures} pickup lifecycle check(s) failed.\n";
	exit( 1 );
}

echo "\nOK - all Phase 9 pickup lifecycle checks passed\n";
