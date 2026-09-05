<?php
/**
 * Phase 4 shipment preflight regression contract.
 *
 * Run: php tests/test-shipment-preflight.php
 */

define( 'ABSPATH', __DIR__ );
define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

$GLOBALS['bgcs_preflight_options'] = array(
	'bgcs3_test' => array( 'env' => 'live' ),
);

function __( $text, $domain = null ) { return $text; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function apply_filters( $hook, $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_option( $key, $default = null ) { return isset( $GLOBALS['bgcs_preflight_options'][ $key ] ) ? $GLOBALS['bgcs_preflight_options'][ $key ] : $default; }
function get_current_user_id() { return 7; }

class WC_Order {
	private $meta;
	private $payment;
	private $total;
	private $currency;
	private $recipient;
	public $saves = 0;

	public function __construct( array $meta, $payment = 'cod', array $recipient = array() ) {
		$this->meta      = $meta;
		$this->payment   = $payment;
		$this->total     = 100.0;
		$this->currency  = 'EUR';
		$this->recipient = array_merge(
			array(
				'name'          => 'Test Recipient',
				'shipping_name' => '',
				'phone'         => '+359888123456',
				'email'         => 'recipient@example.test',
				'company'       => '',
			),
			$recipient
		);
	}

	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() { $this->saves++; }
	public function get_items( $type = '' ) { return 'line_item' === $type || '' === $type ? array( new stdClass() ) : array(); }
	public function get_payment_method() { return $this->payment; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_formatted_billing_full_name() { return $this->recipient['name']; }
	public function get_formatted_shipping_full_name() { return $this->recipient['shipping_name']; }
	public function get_billing_phone() { return $this->recipient['phone']; }
	public function get_shipping_phone() { return ''; }
	public function get_billing_email() { return $this->recipient['email']; }
	public function get_billing_company() { return $this->recipient['company']; }
}

require_once BGCS3_PATH . 'app/Container/Container.php';
require_once BGCS3_PATH . 'app/Module/Module_Interface.php';
require_once BGCS3_PATH . 'app/Module/Categories.php';
require_once BGCS3_PATH . 'app/Module/Abstract_Module.php';
require_once BGCS3_PATH . 'app/Modules/Shipping/Courier_Interface.php';
require_once BGCS3_PATH . 'app/Support/Selection.php';
require_once BGCS3_PATH . 'app/Support/Options.php';
require_once BGCS3_PATH . 'app/Support/Module_Settings.php';
require_once BGCS3_PATH . 'app/Support/Label_Result.php';
require_once BGCS3_PATH . 'app/Shipping/Overrides.php';
require_once BGCS3_PATH . 'app/Shipping/Cod.php';
require_once BGCS3_PATH . 'app/Shipping/Weight.php';
require_once BGCS3_PATH . 'app/Shipping/Package_Dimensions.php';
require_once BGCS3_PATH . 'app/Shipping/Shipment_Preflight.php';
require_once BGCS3_PATH . 'app/Modules/Shipping/Abstract_Courier.php';

use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Abstract_Courier;
use BgCommerce3\Shipping\Shipment_Preflight;
use BgCommerce3\Support\Label_Result;

final class Preflight_Client {
	private $ready;
	public function __construct( $ready ) { $this->ready = (bool) $ready; }
	public function has_credentials() { return $this->ready; }
}

final class Preflight_Courier extends Abstract_Courier {
	private $client;
	public function __construct( $credentials = true ) { $this->client = new Preflight_Client( $credentials ); }
	public function id() { return 'test'; }
	public function name() { return 'Test Courier'; }
	public function register( Container $container ) {}
	public function client() { return $this->client; }
	public function locations() { return null; }
	public function delivery_types() { return array( 'office', 'address' ); }
}

$failures = 0;
function check_preflight( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

function valid_preflight_meta() {
	return array(
		'_bgcs3_selection' => array(
			'courier'       => 'test',
			'delivery_type' => 'office',
			'country'       => 'BG',
			'city'          => array( 'id' => '22', 'name' => 'Sofia' ),
			'office'        => array( 'id' => '101', 'name' => 'Office' ),
			'extras'        => array( 'notify_sms' => '+359888123456' ),
		),
		'_bgcs3_wb'        => array(
			'weight'  => '1.5',
			'contents' => 'Books',
			'x'        => array( 'external_customer_id' => '99887766' ),
		),
	);
}

echo "--- Ready intent and safe payload proof ---\n";
$order     = new WC_Order( valid_preflight_meta() );
$courier   = new Preflight_Courier( true );
$preflight = Shipment_Preflight::begin( $order, $courier );
check_preflight( ! $preflight->is_blocked(), 'A complete order passes the common preflight' );
$preflight->section(
	'sender',
	array(
		'account_id'  => 'sender-account-123',
		'location_id' => 'office-456',
		'phone'       => '+359899000222',
	)
);
$preflight->payload_ready(
	array(
		'sender'    => array( 'phone' => '+359899000111', 'password' => 'do-not-store' ),
		'recipient' => array( 'email' => 'recipient@example.test' ),
		'packages'  => array( array( 'weight' => 1.5 ) ),
	)
);
$stored  = $order->get_meta( Shipment_Preflight::META_KEY );
$encoded = json_encode( $stored );
check_preflight( 'ready' === $stored['status'], 'The exact payload shape is marked ready before create' );
check_preflight( array( 'packages', 'recipient', 'sender' ) === $stored['payload']['top_level'], 'Only sorted top-level payload keys are stored' );
check_preflight( 64 === strlen( $stored['payload']['fingerprint'] ), 'The payload has a SHA-256 fingerprint' );
check_preflight( false === strpos( $encoded, '+359' ), 'Phone numbers are not persisted' );
check_preflight( false === strpos( $encoded, 'recipient@example.test' ), 'Email addresses are not persisted' );
check_preflight( false === strpos( $encoded, 'do-not-store' ), 'Credentials are not persisted' );
check_preflight( false === strpos( $encoded, 'sender-account-123' ), 'Courier account identifiers are not persisted' );
check_preflight( false === strpos( $encoded, 'office-456' ), 'Courier location identifiers are not persisted' );
check_preflight( true === $stored['sender']['account_present'] && true === $stored['sender']['location_present'], 'Courier identifiers record presence only' );
check_preflight( true === $stored['extras']['notify_sms'] && true === $stored['extras']['external_customer_id'], 'Courier extras record presence only' );

echo "--- Blocking common errors ---\n";
$invalid = valid_preflight_meta();
$invalid['_bgcs3_selection']['office'] = array();
$invalid['_bgcs3_wb']['packages'] = array( array( 'length' => 10, 'width' => 0, 'height' => 5, 'weight' => 1 ) );
$order = new WC_Order( $invalid, 'cod', array( 'phone' => '' ) );
$blocked = Shipment_Preflight::begin( $order, new Preflight_Courier( false ) );
$codes = array_column( $blocked->snapshot()['blocking_errors'], 'code' );
check_preflight( $blocked->is_blocked(), 'Incomplete shipment intent is blocked' );
check_preflight( in_array( 'destination_missing', $codes, true ), 'Missing office is reported structurally' );
check_preflight( in_array( 'invalid_package_row', $codes, true ), 'Zero package dimension is reported structurally' );
check_preflight( in_array( 'recipient_phone_missing', $codes, true ), 'Missing recipient phone is reported structurally' );
check_preflight( in_array( 'credentials_missing', $codes, true ), 'Missing credentials are reported structurally' );
check_preflight( ! $blocked->label_error()->success, 'A blocked preflight converts to a failed Label_Result' );

echo "--- Courier-owned validation joins the same snapshot ---\n";
$order = new WC_Order( valid_preflight_meta() );
$provider = Shipment_Preflight::begin( $order, new Preflight_Courier( true ) );
$provider->payload_ready( array( 'order' => array( 'id' => 10 ) ) );
$result = $provider->reject( Label_Result::error( 'Provider refused the selected service.' ), 'provider_service' );
$stored = $order->get_meta( Shipment_Preflight::META_KEY );
check_preflight( ! $result->success && 'blocked' === $stored['status'], 'Provider validation changes a ready snapshot to blocked' );
check_preflight( 'provider_service' === $stored['blocking_errors'][0]['code'], 'Provider refusal keeps a stable error code' );
check_preflight( false === strpos( json_encode( $stored ), 'Provider refused' ), 'Provider prose is returned to the administrator but not persisted' );

echo "--- Every built-in destructive create call is behind preflight ---\n";
$guards = array(
	'app/Modules/Shipping/Speedy/Speedy.php'   => 'client()->create_shipment( $body )',
	'app/Modules/Shipping/Econt/Econt.php'     => 'client()->call( Client::LABEL_CREATE, $create_payload )',
	'app/Modules/Shipping/BoxNow/BoxNow.php'   => 'client()->create_delivery_request( $payload )',
	'app/Modules/Shipping/Pigeon/Pigeon.php'   => 'client()->create_shipment( $body )',
);
foreach ( $guards as $file => $create_call ) {
	$code       = file_get_contents( BGCS3_PATH . $file );
	$create_at  = strpos( $code, $create_call );
	$guard_at   = strrpos( substr( $code, 0, $create_at ), 'preflight_shipment( $order )' );
	$payload_at = strrpos( substr( $code, 0, $create_at ), 'payload_ready(' );
	check_preflight( false !== $create_at && false !== $guard_at && $guard_at < $create_at, basename( dirname( $file ) ) . ' runs common preflight before create' );
	check_preflight( false !== $payload_at && $payload_at < $create_at, basename( dirname( $file ) ) . ' fingerprints the payload before create' );
}

$interface = file_get_contents( BGCS3_PATH . 'app/Modules/Shipping/Courier_Interface.php' );
$abstract  = file_get_contents( BGCS3_PATH . 'app/Modules/Shipping/Abstract_Courier.php' );
check_preflight( false === strpos( $interface, 'preflight_shipment' ), 'Courier_Interface remains backward compatible for third-party add-ons' );
check_preflight( false !== strpos( $abstract, 'function preflight_shipment' ), 'Abstract_Courier exposes the optional preflight hook' );

if ( $failures > 0 ) {
	fwrite( STDERR, "FAIL: {$failures} shipment preflight checks failed.\n" );
	exit( 1 );
}

echo "OK — all shipment preflight checks passed\n";
