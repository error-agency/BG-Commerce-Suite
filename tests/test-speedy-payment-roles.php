<?php
/**
 * TASK-G1 / TASK-G2 — BGCS-AUDIT-013 and -019: the Speedy payment block.
 *
 * -013  Speedy models three independent payer roles; BGCS set all three from the
 *       one „Courier service payer“ setting. A merchant who chose RECIPIENT so
 *       the customer pays the delivery at the door was also charging them the
 *       declared-value insurance premium and the packaging fee — neither offered
 *       as a choice, neither in the WooCommerce total, and neither described by
 *       that field's own label. The customer is then asked at the door for more
 *       than the order says.
 *
 * -019  The post-create verification reads back everything requested EXCEPT the
 *       payment block — even though Speedy returns it, and even though §42 names
 *       the payer among the things a HTTP 200 does not prove.
 *
 * Run: php tests/test-speedy-payment-roles.php
 */

namespace BgCommerce3\Modules\Shipping {
	abstract class Abstract_Courier {}
}

namespace BgCommerce3\Support {
	class Selection {}
	class Price_Result {}
	class Tracking_Result {}
	class Sync_Result {}
	class Cache {}
	class Shipping_Availability {}
	class Label_Pdf_Store {}
}

namespace BgCommerce3\Admin {
	class Icons {}
}

namespace BgCommerce3\Container {
	class Container {}
}

namespace {

	define( 'ABSPATH', __DIR__ );
	define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

	$GLOBALS['bgcs_options'] = array();
	$GLOBALS['bgcs_filters'] = array();

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['bgcs_options'] ) ? $GLOBALS['bgcs_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['bgcs_options'][ $name ] = $value;
		return true;
	}
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['bgcs_filters'][ $hook ][] = $callback;
		return true;
	}
	function remove_all_filters( $hook ) {
		unset( $GLOBALS['bgcs_filters'][ $hook ] );
	}
	function apply_filters( $hook, $value = null ) {
		$args = array_slice( func_get_args(), 1 );
		foreach ( isset( $GLOBALS['bgcs_filters'][ $hook ] ) ? $GLOBALS['bgcs_filters'][ $hook ] : array() as $callback ) {
			$args[0] = call_user_func_array( $callback, $args );
		}
		return $args[0];
	}
	function __( $text, $domain = null ) {
		return $text;
	}
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
	function wp_strip_all_tags( $value ) {
		return strip_tags( (string) $value );
	}
	class WP_Error {
		public function get_error_message() {
			return 'error';
		}
	}
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
	function bgcs3_get_option( $group, $key = null, $default = null ) {
		return \BgCommerce3\Support\Options::get( $group, $key, $default );
	}

	require BGCS3_PATH . 'app/Support/Options.php';
	require BGCS3_PATH . 'app/Support/Module_Settings.php';
	// payment_block() consults the pricing mode before applying the contract
	// administrative fee, so the real Pricing class is loaded rather than faked.
	require BGCS3_PATH . 'app/Shipping/Pricing.php';
	require BGCS3_PATH . 'app/Support/Shipment_Diagnostics.php';
	require BGCS3_PATH . 'app/Modules/Shipping/Speedy/Speedy.php';

	use BgCommerce3\Modules\Shipping\Speedy\Speedy;
	use BgCommerce3\Support\Module_Settings;

	$failures = 0;
	function check_pay( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failures++;
		}
	}

	/**
	 * The real module with only the HTTP client replaced, so the verifier under
	 * test is the shipped one — wiring included, not just the comparison unit.
	 */
	class Verifying_Speedy extends Speedy {

		/** @var array<string,mixed>|null What `shipment/info` should answer. */
		public $info = null;

		/** @var int */
		public $info_calls = 0;

		public function client() {
			return new Fake_Speedy_Client( $this );
		}
	}

	class Fake_Speedy_Client {
		private $owner;
		public function __construct( $owner ) {
			$this->owner = $owner;
		}
		public function shipment_info( array $ids ) {
			$this->owner->info_calls++;
			return $this->owner->info;
		}
	}

	$speedy = new Speedy();

	/** payment_block() is private; it is the unit the finding is about. */
	function payment_block( $speedy, $payer, array $wb = array() ) {
		$method = new ReflectionMethod( Speedy::class, 'payment_block' );
		$method->setAccessible( true );
		return $method->invoke( $speedy, $payer, $wb );
	}

	function verify_payment( $speedy, array $sent, array $stored ) {
		$method = new ReflectionMethod( Speedy::class, 'verify_payment_block' );
		$method->setAccessible( true );
		return $method->invoke( $speedy, $sent, $stored );
	}

	function needs_readback( array $payment ) {
		$method = new ReflectionMethod( Speedy::class, 'payment_needs_readback' );
		$method->setAccessible( true );
		return $method->invoke( null, $payment );
	}

	// -----------------------------------------------------------------------
	// BGCS-AUDIT-013
	// -----------------------------------------------------------------------

	echo "--- Acceptance criterion 1: the three roles are decided separately ---\n";
	$GLOBALS['bgcs_options']['bgcs3_speedy'] = array();
	Module_Settings::prime(
		'speedy',
		array(
			'obp_return_payer'      => array( 'default' => '' ),
			'return_voucher_payer'  => array( 'default' => '' ),
			'third_party_client_id' => array( 'default' => '' ),
			'administrative_fee'    => array( 'default' => 'no' ),
		)
	);

	foreach ( array( 'SENDER', 'RECIPIENT', 'THIRD_PARTY' ) as $service_payer ) {
		$payment = payment_block( $speedy, $service_payer );

		check_pay(
			$service_payer === $payment['courierServicePayer'],
			"courierServicePayer follows the setting ({$service_payer})"
		);
		check_pay(
			'SENDER' === $payment['declaredValuePayer'],
			"…while the insurance premium stays with the sender ({$service_payer})"
		);
		check_pay(
			'SENDER' === $payment['packagePayer'],
			"…and so does the packaging fee ({$service_payer})"
		);
	}

	echo "--- The case the finding describes ---\n";
	{
		// service_payer = RECIPIENT + declared value on: the customer used to be
		// charged the premium at the door on top of the order total.
		$payment = payment_block( $speedy, 'RECIPIENT', array( 'dv_mode' => 'custom', 'declared_value' => '250.00' ) );
		check_pay( 'RECIPIENT' === $payment['courierServicePayer'], 'The customer still pays the delivery, as configured' );
		check_pay( 'SENDER' !== $payment['courierServicePayer'] && 'SENDER' === $payment['declaredValuePayer'], 'but not the insurance premium' );
		check_pay( 'SENDER' === $payment['packagePayer'], 'and not the packaging' );
	}

	echo "--- A shop that genuinely wants the old behaviour can have it ---\n";
	{
		add_filter(
			'bgcs3_speedy_ancillary_payer',
			static function ( $payer, $role, $service_payer ) {
				return $service_payer;
			},
			10,
			4
		);

		$payment = payment_block( $speedy, 'RECIPIENT' );
		check_pay( 'RECIPIENT' === $payment['declaredValuePayer'], 'The filter can restore the pre-fix coupling' );
		check_pay( 'RECIPIENT' === $payment['packagePayer'], '…for both ancillary roles' );
		remove_all_filters( 'bgcs3_speedy_ancillary_payer' );
	}

	echo "--- The filter cannot produce a shipment Speedy would refuse ---\n";
	{
		add_filter(
			'bgcs3_speedy_ancillary_payer',
			static function () {
				return 'WHOEVER';
			}
		);
		$payment = payment_block( $speedy, 'SENDER' );
		check_pay( 'SENDER' === $payment['declaredValuePayer'], 'A role Speedy has no name for falls back to SENDER' );
		remove_all_filters( 'bgcs3_speedy_ancillary_payer' );

		// THIRD_PARTY is only valid alongside a contract client id. A filter that
		// sends the premium to a third party must pull that id in with it,
		// otherwise Speedy refuses the whole shipment.
		$GLOBALS['bgcs_options']['bgcs3_speedy'] = array( 'third_party_client_id' => '123456' );
		add_filter(
			'bgcs3_speedy_ancillary_payer',
			static function ( $payer, $role ) {
				return 'declared_value' === $role ? 'THIRD_PARTY' : $payer;
			},
			10,
			4
		);
		$payment = payment_block( $speedy, 'SENDER' );
		check_pay( 'THIRD_PARTY' === $payment['declaredValuePayer'], 'A third-party premium is honoured' );
		check_pay(
			isset( $payment['thirdPartyClientId'] ) && 123456 === $payment['thirdPartyClientId'],
			'…and drags the contract client id along, which Speedy requires'
		);
		remove_all_filters( 'bgcs3_speedy_ancillary_payer' );
		$GLOBALS['bgcs_options']['bgcs3_speedy'] = array();
	}

	echo "--- Acceptance criterion 3: the block matches the Speedy schema ---\n";
	{
		$schema_path = BGCS3_PATH . 'docs/speedy-schema/ShipmentPayment.schema.json';
		check_pay( is_readable( $schema_path ), 'The official ShipmentPayment schema is available' );

		$schema = json_decode( file_get_contents( $schema_path ), true );
		$props  = isset( $schema['properties'] ) ? $schema['properties'] : array();

		check_pay(
			isset( $props['courierServicePayer'], $props['declaredValuePayer'], $props['packagePayer'] ),
			'…and declares the three roles as separate properties, which is the whole finding'
		);

		$payment = payment_block( $speedy, 'RECIPIENT' );
		foreach ( array_keys( $payment ) as $key ) {
			check_pay( isset( $props[ $key ] ), "Everything BGCS sends exists in the contract: {$key}" );
		}

		$roles = json_decode( file_get_contents( BGCS3_PATH . 'docs/speedy-schema/ShipmentRole.schema.json' ), true );
		$enum  = isset( $roles['enum'] ) ? $roles['enum'] : array();
		foreach ( array( 'courierServicePayer', 'declaredValuePayer', 'packagePayer' ) as $key ) {
			check_pay( in_array( $payment[ $key ], $enum, true ), "{$key} carries a value from the ShipmentRole enum" );
		}
	}

	// -----------------------------------------------------------------------
	// BGCS-AUDIT-019
	// -----------------------------------------------------------------------

	echo "--- Acceptance criterion 1: a substituted payer is reported ---\n";
	{
		$sent = array( 'courierServicePayer' => 'RECIPIENT', 'declaredValuePayer' => 'SENDER', 'packagePayer' => 'SENDER' );

		// The scenario the finding names: the account's contract does not permit
		// the requested role, so Speedy applies its own and answers 200.
		$substituted = $sent;
		$substituted['courierServicePayer'] = 'SENDER';

		$result = verify_payment( $speedy, $sent, $substituted );
		check_pay( true !== $result, 'A silently substituted courier-service payer is caught' );
		check_pay( is_string( $result ) && false !== strpos( $result, 'RECIPIENT' ), '…naming what was asked for' );
		check_pay( is_string( $result ) && false !== strpos( $result, 'SENDER' ), '…and what Speedy actually recorded' );

		foreach ( array( 'declaredValuePayer', 'packagePayer' ) as $role ) {
			$moved          = $sent;
			$moved[ $role ] = 'RECIPIENT';
			check_pay( true !== verify_payment( $speedy, $sent, $moved ), "A substituted {$role} is caught too" );
		}
	}

	echo "--- Acceptance criterion 2: agreement is silent ---\n";
	{
		$sent = array( 'courierServicePayer' => 'RECIPIENT', 'declaredValuePayer' => 'SENDER', 'packagePayer' => 'SENDER' );
		check_pay( true === verify_payment( $speedy, $sent, $sent ), 'An exact match produces no warning' );

		$lowercase = array_map( 'strtolower', $sent );
		check_pay( true === verify_payment( $speedy, $sent, $lowercase ), 'Case differences are not a mismatch' );
	}

	echo "--- Acceptance criterion 3: fields the provider omits are skipped ---\n";
	{
		$sent = array( 'courierServicePayer' => 'RECIPIENT', 'declaredValuePayer' => 'SENDER', 'packagePayer' => 'SENDER' );

		check_pay( true === verify_payment( $speedy, $sent, array() ), 'A response with no payment block is not a warning' );
		check_pay( true === verify_payment( $speedy, array(), $sent ), 'Nor is a create that sent no payment block' );
		check_pay(
			true === verify_payment( $speedy, $sent, array( 'courierServicePayer' => 'RECIPIENT' ) ),
			'A partially returned block compares only what came back'
		);
	}

	echo "--- The optional fields, compared only when both sides have them ---\n";
	{
		check_pay(
			true !== verify_payment( $speedy, array( 'administrativeFee' => true ), array( 'administrativeFee' => false ) ),
			'A dropped administrative fee is reported'
		);
		check_pay(
			true === verify_payment( $speedy, array( 'administrativeFee' => true ), array( 'administrativeFee' => true ) ),
			'…and an applied one is not'
		);
		check_pay(
			true === verify_payment( $speedy, array( 'administrativeFee' => true ), array() ),
			'…and neither is a response that omits it'
		);
		check_pay(
			true !== verify_payment( $speedy, array( 'thirdPartyClientId' => 123456 ), array( 'thirdPartyClientId' => 999 ) ),
			'A different third-party account is reported'
		);
	}

	echo "--- The read-back is spent where it can actually tell us something ---\n";
	{
		$plain = array( 'courierServicePayer' => 'SENDER', 'declaredValuePayer' => 'SENDER', 'packagePayer' => 'SENDER' );
		check_pay( false === needs_readback( $plain ), 'A plain sender-paid shipment costs no extra request' );

		check_pay( true === needs_readback( array( 'courierServicePayer' => 'RECIPIENT' ) + $plain ), 'A recipient-paid shipment is read back' );
		check_pay( true === needs_readback( array( 'declaredValuePayer' => 'RECIPIENT' ) + $plain ), 'so is a recipient-paid premium' );
		check_pay( true === needs_readback( $plain + array( 'administrativeFee' => true ) ), 'and so is the contract administrative fee' );
		check_pay( true === needs_readback( $plain + array( 'thirdPartyClientId' => 123456 ) ), 'and a third-party payer' );
	}

	echo "--- The verifier really consults the payment block (wiring, not just the unit) ---\n";
	{
		$module = new Verifying_Speedy();

		$verify = new ReflectionMethod( Speedy::class, 'verify_created_service_options' );
		$verify->setAccessible( true );

		$sent = array(
			'service' => array( 'serviceId' => 505 ),
			'payment' => array(
				'courierServicePayer' => 'RECIPIENT',
				'declaredValuePayer'  => 'SENDER',
				'packagePayer'        => 'SENDER',
			),
		);

		// Speedy answers 200 but records a payer its contract allows instead.
		$module->info = array(
			'shipments' => array(
				array(
					'service' => array( 'serviceId' => 505 ),
					'payment' => array(
						'courierServicePayer' => 'SENDER',
						'declaredValuePayer'  => 'SENDER',
						'packagePayer'        => 'SENDER',
					),
				),
			),
		);

		$diag   = \BgCommerce3\Support\Shipment_Diagnostics::begin( 'speedy' );
		$result = $verify->invoke( $module, '63720845492', $sent, $diag );

		check_pay( 1 === $module->info_calls, 'A recipient-paid shipment triggers the read-back' );
		check_pay( true !== $result, 'and the substituted payer becomes a warning' );
		check_pay( is_string( $result ) && false !== strpos( $result, 'courier service payer' ), 'naming the role that changed' );

		$module->info_calls = 0;
		$module->info       = array( 'shipments' => array( array( 'service' => array( 'serviceId' => 505 ), 'payment' => $sent['payment'] ) ) );
		$diag               = \BgCommerce3\Support\Shipment_Diagnostics::begin( 'speedy' );
		check_pay( true === $verify->invoke( $module, '1', $sent, $diag ), 'A matching payment block passes silently' );

		// Plain sender-paid with nothing else requested: no read-back at all.
		$module->info_calls = 0;
		$plain              = array(
			'service' => array( 'serviceId' => 505 ),
			'payment' => array( 'courierServicePayer' => 'SENDER', 'declaredValuePayer' => 'SENDER', 'packagePayer' => 'SENDER' ),
		);
		$diag = \BgCommerce3\Support\Shipment_Diagnostics::begin( 'speedy' );
		check_pay( true === $verify->invoke( $module, '1', $plain, $diag ), 'A plain shipment needs no verification' );
		check_pay( 0 === $module->info_calls, 'and spends no request on it' );

		// A provider that omits the block must not produce a false alarm.
		$module->info_calls = 0;
		$module->info       = array( 'shipments' => array( array( 'service' => array( 'serviceId' => 505 ) ) ) );
		$diag               = \BgCommerce3\Support\Shipment_Diagnostics::begin( 'speedy' );
		check_pay( true === $verify->invoke( $module, '1', $sent, $diag ), 'A response without a payment block is not a warning' );
	}

	echo "--- Static guards ---\n";
	{
		$code = php_strip_whitespace( BGCS3_PATH . 'app/Modules/Shipping/Speedy/Speedy.php' );

		check_pay(
			false === strpos( $code, "'declaredValuePayer' => \$payer" ),
			'The ancillary roles are no longer assigned from the courier-service payer'
		);
		check_pay( false !== strpos( $code, 'verify_payment_block' ), 'The verifier compares the payment block' );
		check_pay(
			false !== strpos( $code, "__( 'Courier service payer'" ) && false !== strpos( $code, 'billed to the sender' ),
			'Acceptance criterion 2 of -013: the settings screen explains who pays the premium'
		);

		$adr = file_get_contents( BGCS3_PATH . 'docs/project/DECISIONS.md' );
		check_pay( false !== strpos( $adr, 'ADR-008' ), 'Acceptance criterion 4 of -013: the decision is recorded as an ADR' );
	}

	echo PHP_EOL;
	if ( $failures > 0 ) {
		echo "FAILED: {$failures} check(s)" . PHP_EOL;
		exit( 1 );
	}
	echo 'OK — all Speedy payment role checks passed' . PHP_EOL;
}
