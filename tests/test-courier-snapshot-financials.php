<?php
/**
 * TASK-A2 — the four real `label_snapshot_financials()` implementations.
 *
 * BGCS-AUDIT-004: Core used to derive the Econt payer from `payment_side`, an
 * option that has never existed in the plugin, so every Econt shipment was
 * recorded as recipient-paid regardless of configuration. The payment semantics
 * now live in each module, which is the only place that knows them.
 *
 * Run: php tests/test-courier-snapshot-financials.php
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

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['bgcs_options'] ) ? $GLOBALS['bgcs_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['bgcs_options'][ $name ] = $value;
		return true;
	}
	function apply_filters( $hook, $value = null ) {
		return $value;
	}
	function __( $text, $domain = null ) {
		return $text;
	}
	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
	}
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	/** Order double carrying only what the payment resolvers read. */
	class WC_Order {

		/** @var array<string,mixed> */
		private $meta = array();

		/** @var string */
		private $payment_method;

		/** @var float */
		private $total;

		public function __construct( $payment_method = 'cod', $total = 120.0, array $meta = array() ) {
			$this->payment_method = (string) $payment_method;
			$this->total          = (float) $total;
			$this->meta           = $meta;
		}

		public function get_id() {
			return 8271;
		}
		public function get_payment_method() {
			return $this->payment_method;
		}
		public function get_currency() {
			return 'BGN';
		}
		public function get_total() {
			return $this->total;
		}
		public function get_shipping_total() {
			return 5.0;
		}
		public function get_shipping_tax() {
			return 0.0;
		}
		public function get_meta( $key, $single = true, $context = 'view' ) {
			return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
		}
		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}
	}

	require BGCS3_PATH . 'app/Support/Options.php';
	require BGCS3_PATH . 'app/Support/Module_Settings.php';
	require BGCS3_PATH . 'app/Shipping/Overrides.php';
	require BGCS3_PATH . 'app/Shipping/Cod.php';
	require BGCS3_PATH . 'app/Shipping/Financial_Invariants.php';
	require BGCS3_PATH . 'app/Modules/Shipping/Econt/Econt.php';
	require BGCS3_PATH . 'app/Modules/Shipping/BoxNow/BoxNow.php';
	require BGCS3_PATH . 'app/Modules/Shipping/Pigeon/Pigeon.php';

	use BgCommerce3\Modules\Shipping\BoxNow\BoxNow;
	use BgCommerce3\Modules\Shipping\Econt\Econt;
	use BgCommerce3\Modules\Shipping\Pigeon\Pigeon;
	use BgCommerce3\Shipping\Financial_Invariants;
	use BgCommerce3\Support\Module_Settings;

	$failures = 0;
	function check_fin( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failures++;
		}
	}

	function set_settings( $group, array $values ) {
		$GLOBALS['bgcs_options'][ 'bgcs3_' . $group ] = $values;
		Module_Settings::flush( $group );
	}

	// ---------------------------------------------------------------- Econt ---

	echo "--- Econt: the sender always pays the courier service ---\n";
	$econt = new Econt();

	// The payer must not move with `payment_type` — that setting is HOW the
	// sender pays (cash/credit/voucher), not WHO pays. Core used to read a
	// non-existent `payment_side` here and always answered RECIPIENT.
	foreach ( array( 'CASH', 'CREDIT', 'VOUCHER' ) as $payment_type ) {
		foreach ( array( 'office', 'locker', 'address' ) as $delivery_type ) {
			set_settings( 'econt', array( 'payment_type' => $payment_type, 'cd_enabled' => 'yes' ) );
			$order = new WC_Order( 'cod', 120.0, array( '_bgcs3_selection' => array( 'delivery_type' => $delivery_type ) ) );
			$fin   = $econt->label_snapshot_financials( $order, array() );
			check_fin( 'SENDER' === $fin['payer'], "payment_type={$payment_type}, delivery={$delivery_type} → SENDER" );
		}
	}

	set_settings( 'econt', array( 'cd_enabled' => 'yes' ) );
	$fin = $econt->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array( 'payer' => 'RECIPIENT' ) );
	check_fin( 'SENDER' === $fin['payer'], 'A stray order-level payer cannot make Econt bill the receiver' );

	echo "--- Econt: the COD amount matches what Label_Builder actually sends ---\n";
	set_settings( 'econt', array( 'cd_enabled' => 'yes' ) );
	$fin = $econt->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array() );
	check_fin( 120.0 === $fin['cod_amount'], 'COD order with cash on delivery enabled → the order total' );
	check_fin( 'BGN' === $fin['cod_currency'], 'The order currency is recorded' );

	set_settings( 'econt', array( 'cd_enabled' => 'no' ) );
	$fin = $econt->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array() );
	check_fin( 0.0 === $fin['cod_amount'], 'cd_enabled=no → no COD is sent, so none is recorded' );

	set_settings( 'econt', array( 'cd_enabled' => 'yes' ) );
	$fin = $econt->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array( 'cod_mode' => 'disabled' ) );
	check_fin( 0.0 === $fin['cod_amount'], 'An explicit per-order "no COD" wins over the module setting' );

	set_settings( 'econt', array( 'cd_enabled' => 'no' ) );
	$fin = $econt->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array( 'cod_mode' => 'custom', 'cod_amount' => '45.50' ) );
	check_fin( 45.5 === $fin['cod_amount'], 'A manual per-order amount is sent even when the module setting is off' );

	set_settings( 'econt', array( 'cd_enabled' => 'yes' ) );
	$fin = $econt->label_snapshot_financials( new WC_Order( 'bacs', 120.0 ), array() );
	check_fin( 0.0 === $fin['cod_amount'], 'A prepaid order carries no COD' );

	$fin = $econt->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array( 'cod_mode' => 'custom', 'cod_amount' => '-5' ) );
	check_fin( 0.0 === $fin['cod_amount'], 'A negative COD override is normalized to zero by the shared resolver' );

	$fin = $econt->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array( 'cod_mode' => 'custom', 'cod_amount' => '45.678' ) );
	check_fin( 45.68 === $fin['cod_amount'], 'A COD override is normalized once to currency precision' );

	check_fin( 119.20 === Financial_Invariants::order_pmt_base( new WC_Order( 'cod', 120.0 ), 0.80 ), 'Shipment PMT_BASE removes only PMT, retaining the order shipping base' );

	// A fresh install with no Econt settings row at all: cd_enabled declares
	// 'yes', so the snapshot must agree with the payload that will be built.
	set_settings( 'econt', array() );
	Module_Settings::prime( 'econt', array( 'cd_enabled' => array( 'default' => 'yes' ) ) );
	$fin = $econt->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array() );
	check_fin( 120.0 === $fin['cod_amount'], 'With no stored settings the declared cd_enabled default applies' );
	Module_Settings::flush( 'econt' );

	// ---------------------------------------------------------------- BOX NOW ---

	echo "--- BOX NOW: a locker network has no courier-service payer ---\n";
	$boxnow = new BoxNow();

	$fin = $boxnow->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array() );
	check_fin( array_key_exists( 'payer', $fin ), 'The payer key is returned, not omitted' );
	check_fin( '' === $fin['payer'], '…and it is empty: BOX NOW is never told who pays' );
	check_fin( 120.0 === $fin['cod_amount'], 'The COD amount matches delivery_request_body()' );

	$fin = $boxnow->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array( 'payer' => 'RECIPIENT' ) );
	check_fin( '' === $fin['payer'], 'A stale order-level payer is cleared rather than recorded' );

	$fin = $boxnow->label_snapshot_financials( new WC_Order( 'bacs', 120.0 ), array() );
	check_fin( 0.0 === $fin['cod_amount'], 'A prepaid order carries no COD' );

	// ---------------------------------------------------------------- Pigeon ---

	echo "--- Pigeon: the payer follows who_pays, the same resolver the payload uses ---\n";
	$pigeon = new Pigeon();

	set_settings( 'pigeon', array( 'who_pays' => 'sender' ) );
	$fin = $pigeon->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array() );
	check_fin( 'SENDER' === $fin['payer'], 'who_pays=sender → SENDER' );

	set_settings( 'pigeon', array( 'who_pays' => 'receiver' ) );
	$fin = $pigeon->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array() );
	check_fin( 'RECIPIENT' === $fin['payer'], 'who_pays=receiver → RECIPIENT' );

	$fin = $pigeon->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array( 'payer' => 'SENDER' ) );
	check_fin( 'SENDER' === $fin['payer'], 'An order-level payer override is honoured — Pigeon does accept one' );

	// Core's old guess for Pigeon was `COD ? RECIPIENT : SENDER`, which is not
	// what who_pays() does: its fallback is sender either way.
	set_settings( 'pigeon', array() );
	Module_Settings::prime( 'pigeon', array( 'who_pays' => array( 'default' => '' ) ) );
	$fin = $pigeon->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array() );
	check_fin( 'SENDER' === $fin['payer'], 'An unset who_pays falls back to sender even for a COD order' );
	Module_Settings::flush( 'pigeon' );

	set_settings( 'pigeon', array( 'who_pays' => 'sender' ) );
	$fin = $pigeon->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array() );
	check_fin( 120.0 === $fin['cod_amount'], 'The COD amount comes from the shared resolver' );
	check_fin( 'BGN' === $fin['cod_currency'], 'The order currency is recorded' );

	// --------------------------------------------------------------- Contract ---

	echo "--- Every implementation answers the same contract ---\n";
	foreach ( array( 'Econt' => $econt, 'BoxNow' => $boxnow, 'Pigeon' => $pigeon ) as $name => $module ) {
		$fin = $module->label_snapshot_financials( new WC_Order( 'cod', 120.0 ), array() );
		check_fin( is_array( $fin ), "{$name} returns an array" );
		check_fin( array_key_exists( 'payer', $fin ) && is_string( $fin['payer'] ), "{$name} returns a string payer" );
		check_fin( isset( $fin['cod_amount'] ) && is_float( $fin['cod_amount'] ), "{$name} returns a float cod_amount" );
		check_fin(
			in_array( $fin['payer'], array( '', 'SENDER', 'RECIPIENT', 'THIRD_PARTY' ), true ),
			"{$name} returns a payer from the shared vocabulary"
		);
	}

	echo PHP_EOL;
	if ( $failures > 0 ) {
		echo "FAILED: {$failures} check(s)" . PHP_EOL;
		exit( 1 );
	}
	echo 'OK — all courier snapshot financials checks passed' . PHP_EOL;
}
