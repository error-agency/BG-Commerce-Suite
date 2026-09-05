<?php
/**
 * BGCS-AUDIT-001 — the stable shipment reference is load-bearing, so it is
 * pinned here.
 *
 * The live audit established that BGCS's own creation lock was providing no
 * protection at all, and that duplicate shipments were being prevented by the
 * couriers instead: BOX NOW answers a repeated reference with P410 and Pigeon
 * with HTTP 409. Both of those only fire because BGCS sends the SAME reference
 * on a retry. The previous timestamp-based implementation guaranteed a new,
 * unrecognizable reference on every retry — i.e. guaranteed the duplicate.
 *
 * Reverting to any time-, random- or request-derived value would therefore
 * remove the protection that is actually working in production today. These
 * tests exist to make that revert impossible to land silently.
 *
 * Run: php tests/test-shipment-reference.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['bgcs_test_home_url'] = 'https://shop.example.test';

function home_url() {
	return $GLOBALS['bgcs_test_home_url'];
}

/** Minimal order double: only the meta accessors the reference needs. */
class WC_Order {

	/** @var int */
	private $id;

	/** @var array<string,mixed> */
	private $meta = array();

	public function __construct( $id ) {
		$this->id = (int) $id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_meta( $key ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
}

require_once dirname( __DIR__ ) . '/app/Shipping/Shipment_Reference.php';

use BgCommerce3\Shipping\Shipment_Reference;

$failures = 0;
function check_ref( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

echo "--- The reference is stable across repeated calls ---\n";
$order = new WC_Order( 8230 );
$first = Shipment_Reference::for_order( $order );
check_ref( '' !== $first, 'A reference is produced' );
check_ref( $first === Shipment_Reference::for_order( $order ), 'Two calls in the same request agree' );

echo "--- The reference does not move with the clock (this is the whole point) ---\n";
// A retry after an HTTP timeout happens seconds later, in a different request.
// If the reference changed, BOX NOW P410 / Pigeon HTTP 409 would never fire and
// the retry would create a second real parcel.
$before = Shipment_Reference::for_order( $order );
sleep( 1 );
$after = Shipment_Reference::for_order( $order );
check_ref( $before === $after, 'A reference generated one second later is identical' );

$retry_order = new WC_Order( 8230 );
check_ref( $first === Shipment_Reference::for_order( $retry_order ), 'A freshly loaded order object produces the same reference' );

echo "--- Different orders and different editions stay distinguishable ---\n";
check_ref( $first !== Shipment_Reference::for_order( new WC_Order( 8231 ) ), 'A different order gets a different reference' );

check_ref( 1 === Shipment_Reference::edition( $order ), 'The edition starts at 1' );
Shipment_Reference::bump_edition( $order );
check_ref( 2 === Shipment_Reference::edition( $order ), 'bump_edition() advances the edition' );
$recreated = Shipment_Reference::for_order( $order );
check_ref( $first !== $recreated, 'After an explicit Cancel, a recreate is distinguishable from a retry' );
check_ref( $recreated === Shipment_Reference::for_order( $order ), 'The bumped reference is itself stable' );

echo "--- A corrupt/absent edition never produces an unstable reference ---\n";
$odd = new WC_Order( 8232 );
foreach ( array( '', 0, -3, 'nonsense', null ) as $value ) {
	$odd->update_meta_data( '_bgcs3_shipment_edition', $value );
	check_ref( 1 === Shipment_Reference::edition( $odd ), 'Edition falls back to 1 for ' . var_export( $value, true ) );
}

echo "--- Two installs of the same shop never collide ---\n";
$GLOBALS['bgcs_test_home_url'] = 'https://staging.example.test';
check_ref( $first !== Shipment_Reference::for_order( new WC_Order( 8230 ) ), 'The site instance segments the reference' );
$GLOBALS['bgcs_test_home_url'] = 'https://shop.example.test';
check_ref( $first === Shipment_Reference::for_order( new WC_Order( 8230 ) ), 'Returning to the same site URL restores the same reference' );

echo "--- Static guards: no time-, random- or request-derived input ---\n";
$reference_file = dirname( __DIR__ ) . '/app/Shipping/Shipment_Reference.php';
$code           = php_strip_whitespace( $reference_file );
foreach ( array( 'time(', 'microtime(', 'uniqid(', 'rand(', 'wp_generate_uuid4', 'wp_generate_password', 'REQUEST_TIME' ) as $forbidden ) {
	check_ref( false === strpos( $code, $forbidden ), 'Shipment_Reference does not use ' . $forbidden . ' — a retry must reproduce the same value' );
}

// Every built-in create payload carries the same edition-aware reference. BOX
// NOW and Pigeon use it for provider-side duplicate detection; Speedy and Econt
// keep it as the traceable order reference for timeout reconciliation.
foreach ( array(
	'BOX NOW' => '/app/Modules/Shipping/BoxNow/BoxNow.php',
	'Pigeon'  => '/app/Modules/Shipping/Pigeon/Pigeon.php',
	'Speedy'  => '/app/Modules/Shipping/Speedy/Speedy.php',
	'Econt'   => '/app/Modules/Shipping/Econt/Label_Builder.php',
) as $courier => $file ) {
	$source = php_strip_whitespace( dirname( __DIR__ ) . $file );
	check_ref( false !== strpos( $source, 'Shipment_Reference::for_order' ), $courier . ' sends the stable shipment-edition reference' );
}

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all shipment reference checks passed' . PHP_EOL;
