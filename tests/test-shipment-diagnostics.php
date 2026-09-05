<?php
/**
 * Offline harness: proves the diagnostic redactor keeps structure and drops
 * secrets/PII. No WordPress, no database, no courier account.
 */
define( 'ABSPATH', __DIR__ );

// Minimal WP shims the class touches.
function bgcs3_substr( $s, $start, $len = null ) {
	return null === $len ? mb_substr( (string) $s, $start ) : mb_substr( (string) $s, $start, $len );
}
function bgcs3_strlen( $s ) { return mb_strlen( (string) $s ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_current_user_id() { return 1; }
class WP_Error {
	private $c, $m;
	public function __construct( $c, $m ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}
$GLOBALS['opts'] = array(
	'debug'  => array( 'shipment_snapshot' => 'yes' ),
	'speedy' => array( 'username' => 'REAL_SPEEDY_USER', 'password' => 'REAL_SPEEDY_PASSWORD', 'client_id' => '987654' ),
);
function bgcs3_get_option( $g, $k = null, $d = null ) {
	$group = isset( $GLOBALS['opts'][ $g ] ) && is_array( $GLOBALS['opts'][ $g ] ) ? $GLOBALS['opts'][ $g ] : array();
	if ( null === $k ) {
		return $group; // Options::get() returns the whole group when key is null.
	}
	return array_key_exists( $k, $group ) ? $group[ $k ] : $d;
}

require __DIR__ . '/../app/Support/Log_Redactor.php';
require __DIR__ . '/../app/Support/Shipment_Diagnostics.php';

use BgCommerce3\Support\Shipment_Diagnostics;

// A realistic Speedy CreateShipmentRequest, plus credentials that must never survive.
$body = array(
	'userName' => 'REAL_SPEEDY_USER',
	'password' => 'REAL_SPEEDY_PASSWORD',
	'service'  => array(
		'serviceId'            => 505,
		'autoAdjustPickupDate' => true,
		'saturdayDelivery'     => false,
		'deferredDays'         => 0,
		'additionalServices'   => array(
			'cod'  => array( 'amount' => 149.90, 'processingType' => 'CASH', 'currencyCode' => 'BGN', 'includeShippingPrice' => false ),
			'obpd' => array( 'option' => 'TEST', 'returnShipmentServiceId' => 505, 'returnShipmentPayer' => 'SENDER' ),
			'declaredValue' => array( 'amount' => 149.90, 'fragile' => true ),
		),
	),
	'content' => array(
		'package'      => 'BOX',
		'contents'     => 'Bluetooth headphones, black',
		'parcelsCount' => 1,
		'totalWeight'  => 0.6,
	),
	'payment'   => array( 'courierServicePayer' => 'RECIPIENT', 'declaredValuePayer' => 'SENDER' ),
	'sender'    => array( 'clientId' => 987654 ),
	'recipient' => array(
		'privatePerson'  => true,
		'clientName'     => 'Иван Петров Георгиев',
		'email'          => 'ivan.petrov@example.com',
		'phone1'         => array( 'number' => '+359888123456' ),
		'pickupOfficeId' => 1234,
		'address'        => array( 'countryId' => 100, 'siteId' => 68134, 'postCode' => '1000', 'streetName' => 'бул. Витоша', 'houseNo' => '15А' ),
	),
	'ref1' => '10042',
);

$diag = Shipment_Diagnostics::begin( 'speedy' );
$diag->record( 'payload', $body );
$diag->record_response( 'validation', array( 'valid' => true ) );
$diag->record_response( 'create_error', new WP_Error( 'speedy_http', 'Auth failed for user REAL_SPEEDY_USER with password=hunter2 and token abc123def456ghi' ) );
$diag->record_destination_services(
	array( 'services' => array(
		array( 'id' => 505, 'additionalServices' => array( 'obpd' => array( 'allowance' => 'FORBIDDEN' ), 'cod' => array( 'allowance' => 'ALLOWED' ) ) ),
		array( 'id' => 202 ),
	) ),
	505
);

// Reach the collected stages for assertion.
$ref = new ReflectionProperty( Shipment_Diagnostics::class, 'stages' );
$ref->setAccessible( true );
$stages = $ref->getValue( $diag );
$json   = json_encode( $stages, JSON_UNESCAPED_UNICODE );

$fail = 0;
function check( $label, $ok ) {
	global $fail;
	if ( ! $ok ) { $fail++; }
	printf( "  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label );
}

echo "--- secrets must not survive anywhere ---\n";
foreach ( array( 'REAL_SPEEDY_USER', 'REAL_SPEEDY_PASSWORD', 'hunter2', 'abc123def456ghi' ) as $secret ) {
	check( "absent: $secret", false === strpos( $json, $secret ) );
}

echo "--- customer PII must not survive verbatim ---\n";
foreach ( array( 'Иван Петров Георгиев', 'ivan.petrov@example.com', '+359888123456', 'бул. Витоша' ) as $pii ) {
	check( "absent: $pii", false === strpos( $json, $pii ) );
}

echo "--- diagnostic structure must survive intact ---\n";
$p = $stages['payload'];
check( 'serviceId kept',            505 === $p['service']['serviceId'] );
check( 'obpd.option kept',          'TEST' === $p['service']['additionalServices']['obpd']['option'] );
check( 'obpd.returnShipmentServiceId kept', 505 === $p['service']['additionalServices']['obpd']['returnShipmentServiceId'] );
check( 'obpd.returnShipmentPayer kept',     'SENDER' === $p['service']['additionalServices']['obpd']['returnShipmentPayer'] );
check( 'cod.amount kept',           149.90 === $p['service']['additionalServices']['cod']['amount'] );
check( 'declaredValue.fragile kept', true === $p['service']['additionalServices']['declaredValue']['fragile'] );
check( 'content.contents kept verbatim', 'Bluetooth headphones, black' === $p['content']['contents'] );
check( 'pickupOfficeId kept',       1234 === $p['recipient']['pickupOfficeId'] );
check( 'address.siteId kept',       68134 === $p['recipient']['address']['siteId'] );
check( 'sender.clientId kept',      987654 === $p['sender']['clientId'] );
check( 'credential keys redacted',  '[redacted]' === $p['userName'] && '[redacted]' === $p['password'] );
check( 'clientName previewed',      false !== strpos( (string) $p['recipient']['clientName'], 'chars]' ) );

echo "--- allowance matrix reduced to the used service ---\n";
$d = $stages['destination_services'];
check( 'service found',             true === $d['service_offered'] );
check( 'obpd allowance captured',   'FORBIDDEN' === $d['allowances']['obpd'] );
check( 'other services listed',     array( 505, 202 ) === $d['offered_service_ids'] );

echo "--- disabled collector records nothing ---\n";
$GLOBALS['opts']['debug']['shipment_snapshot'] = 'no';
$off = Shipment_Diagnostics::begin( 'speedy' );
$off->record( 'payload', $body );
check( 'inert when off', array() === $ref->getValue( $off ) );

echo "\n" . ( $fail ? "FAILED: $fail check(s)\n" : "ALL CHECKS PASSED\n" );
exit( $fail ? 1 : 0 );
