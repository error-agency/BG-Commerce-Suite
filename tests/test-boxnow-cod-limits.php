<?php
/**
 * TASK-B1 — BGCS-AUDIT-012: BOX NOW cash-on-delivery preconditions.
 *
 * Neither precondition for cash on delivery was checked before the customer
 * checked out:
 *
 *   the AMOUNT      BOX NOW accepts `amountToBeCollected` in (0, 5000]. The
 *                   number existed only inside the translation of error P408,
 *                   so it was used to explain a rejection, never to prevent one.
 *   the ENTITLEMENT `PartnerPermission.codPayment` says whether the account may
 *                   collect money at all (error P411). The live audit found the
 *                   cached value empty and concluded it was unavailable — it was
 *                   in fact being discarded by the normalizer, which cast an
 *                   OBJECT of booleans through is_scalar().
 *
 * Both were discovered only when the merchant pressed "create shipment", by
 * which point the order was accepted and undispatchable.
 *
 * Run: php tests/test-boxnow-cod-limits.php
 */

namespace BgCommerce3\Modules\Shipping {
	class Test_Preflight {
		public function is_blocked() { return false; }
		public function label_error() { return \BgCommerce3\Support\Label_Result::error( 'blocked' ); }
		public function reject( $result, $code = '' ) { return $result; }
		public function section( $section, array $data ) { return $this; }
		public function payload_ready( array $payload ) { return $this; }
	}

	abstract class Abstract_Courier {
		/** Real one lives on Abstract_Courier; only create_label()'s use of it matters here. */
		protected function order_selection( \WC_Order $order ) {
			return isset( $GLOBALS['bgcs_selection'] ) ? $GLOBALS['bgcs_selection'] : null;
		}
		public function preflight_shipment( \WC_Order $order ) {
			return new Test_Preflight();
		}
	}
}

namespace BgCommerce3\Container {
	class Container {}
}

namespace BgCommerce3\Admin {
	class Icons {}
}

namespace BgCommerce3\Support {
	class Price_Result {
		public $valid = false;
		public $cost = 0.0;
		public $errors = array();
		public $availability = null;
	}
	class Tracking_Result {}
	class Sync_Result {}
	class Cache {}
	class Label_Pdf_Store {}
}

namespace {

	define( 'ABSPATH', __DIR__ );
	define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

	$GLOBALS['bgcs_options']  = array();
	$GLOBALS['bgcs_cod_method'] = 'cod';
	$GLOBALS['bgcs_cart_total'] = 0.0;

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['bgcs_options'] ) ? $GLOBALS['bgcs_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['bgcs_options'][ $name ] = $value;
		return true;
	}
	function __( $text, $domain = null ) {
		return $text;
	}
	function esc_html( $text ) {
		return $text;
	}
	function sanitize_key( $value ) {
		// Mirrors WordPress: lowercase FIRST, then strip. Stripping first would
		// eat the capital in `codPayment` and produce `codayment`.
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
	function sanitize_email( $value ) {
		return (string) $value;
	}
	function wp_strip_all_tags( $value ) {
		return strip_tags( (string) $value );
	}
	function wc_price( $amount ) {
		return number_format( (float) $amount, 2, '.', ' ' ) . ' лв.';
	}
	function apply_filters( $hook, $value = null ) {
		return $value;
	}
	function wc_get_weight( $value, $to, $from ) {
		return (float) $value;
	}
	function wc_get_dimension( $value, $to, $from ) {
		return (float) $value;
	}
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
	function wc_get_price_decimals() {
		return 2;
	}

	/** WooCommerce cart stand-in — the checkout-time source of the COD amount. */
	class Bgcs_Cart {
		public function get_total( $context = 'view' ) {
			return $GLOBALS['bgcs_cart_total'];
		}
	}
	class Bgcs_WC {
		public $cart;
		public $session = null;
		public function __construct() {
			$this->cart = new Bgcs_Cart();
		}
	}
	function WC() {
		static $wc = null;
		if ( null === $wc ) {
			$wc = new Bgcs_WC();
		}
		return $wc;
	}

	class WC_Order {
		private $meta = array();
		private $payment_method;
		private $total;
		public function __construct( $payment_method = 'cod', $total = 100.0, array $meta = array() ) {
			$this->payment_method = (string) $payment_method;
			$this->total          = (float) $total;
			$this->meta           = $meta;
		}
		public function get_id() {
			return 8351;
		}
		public function get_payment_method() {
			return $this->payment_method;
		}
		public function get_total() {
			return $this->total;
		}
		public function get_currency() {
			return 'BGN';
		}
		public function get_items( $type = 'line_item' ) {
			return array();
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
	require BGCS3_PATH . 'app/Support/Selection.php';
	require BGCS3_PATH . 'app/Support/Shipping_Availability.php';
	require BGCS3_PATH . 'app/Support/Label_Result.php';
	require BGCS3_PATH . 'app/Shipping/Overrides.php';
	require BGCS3_PATH . 'app/Shipping/Cod.php';
	require BGCS3_PATH . 'app/Shipping/Weight.php';
	require BGCS3_PATH . 'app/Shipping/Package_Dimensions.php';
	require BGCS3_PATH . 'app/Modules/Shipping/BoxNow/BoxNow.php';

	use BgCommerce3\Modules\Shipping\BoxNow\BoxNow;
	use BgCommerce3\Support\Label_Result;
	use BgCommerce3\Support\Shipping_Availability;

	function bgcs3_get_option( $group, $key = null, $default = null ) {
		return \BgCommerce3\Support\Options::get( $group, $key, $default );
	}

	$failures = 0;
	function check_cod( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failures++;
		}
	}

	/**
	 * @param bool|null $cod_permitted Account entitlement, null = never synced.
	 */
	function set_account( $cod_permitted ) {
		$profile = array( 'id' => '11232', 'display_name' => 'Test partner' );
		if ( null !== $cod_permitted ) {
			$profile['permission'] = array( 'codpayment' => (bool) $cod_permitted, 'warehouseasorigin' => true );
		}
		$GLOBALS['bgcs_options']['bgcs3_boxnow'] = array( '_account_profile' => $profile );
	}

	/** Puts the customer on cash on delivery, or off it. */
	function choose_payment( $method ) {
		$_POST['payment_method'] = $method;
	}

	$boxnow = new BoxNow();
	$empty_package = array( 'contents' => array() );

	echo "--- Acceptance criterion 1: over the limit, BOX NOW is not selectable ---\n";
	set_account( true );
	choose_payment( 'cod' );

	// The exact boundary the contract states: valid range is (0, 5000].
	foreach ( array(
		'0.01'    => array( 0.01, false ),
		'4999.99' => array( 4999.99, false ),
		'5000.00' => array( 5000.00, false ),
		'5000.01' => array( 5000.01, true ),
		'9999.00' => array( 9999.00, true ),
	) as $label => $case ) {
		list( $total, $should_block ) = $case;
		$GLOBALS['bgcs_cart_total']   = $total;

		$result  = $boxnow->package_availability( $empty_package );
		$blocked = $result instanceof Shipping_Availability;

		check_cod(
			$blocked === $should_block,
			sprintf( 'COD of %s is %s', $label, $should_block ? 'refused' : 'allowed' )
		);

		if ( $blocked && $should_block ) {
			$public = $result->to_public_array();
			check_cod(
				false !== strpos( $public['customer_message'], wc_price( BoxNow::COD_MAX ) ),
				'…and the customer is told what the limit is'
			);
		}
	}

	echo "--- Acceptance criterion 2: the limit applies to COD only ---\n";
	$GLOBALS['bgcs_cart_total'] = 9999.00;
	choose_payment( 'bacs' );
	check_cod( null === $boxnow->package_availability( $empty_package ), 'The same cart prepaid stays selectable' );

	choose_payment( 'cod' );
	check_cod( $boxnow->package_availability( $empty_package ) instanceof Shipping_Availability, 'and is refused again on cash on delivery' );

	echo "--- The account entitlement, which the live audit could not read ---\n";
	$GLOBALS['bgcs_cart_total'] = 50.0;
	choose_payment( 'cod' );

	set_account( false );
	$denied = $boxnow->package_availability( $empty_package );
	check_cod( $denied instanceof Shipping_Availability, 'An account without codPayment cannot offer cash on delivery' );
	check_cod(
		$denied instanceof Shipping_Availability && 'boxnow_cod_not_permitted' === $denied->to_public_array()['code'],
		'…with its own reason code, distinct from the amount limit'
	);

	set_account( true );
	check_cod( null === $boxnow->package_availability( $empty_package ), 'An entitled account is unaffected' );

	// The important conservative case: never block on data we have not fetched.
	set_account( null );
	check_cod( null === $boxnow->package_availability( $empty_package ), 'An unsynced profile does NOT block checkout — unknown is not refusal' );

	$GLOBALS['bgcs_options']['bgcs3_boxnow'] = array();
	check_cod( null === $boxnow->package_availability( $empty_package ), 'Neither does a completely empty profile' );

	echo "--- The normalizer keeps what BOX NOW actually sends ---\n";
	// PartnerPermission is an object of booleans. Casting it through is_scalar()
	// stored '' and made the entitlement invisible — the reason the audit
	// recorded this as "requires confirmation from the provider".
	{
		$reflection = new ReflectionMethod( BoxNow::class, 'normalize_permissions' );
		$reflection->setAccessible( true );

		$flags = $reflection->invoke( null, array( 'codPayment' => true, 'addressAsDestination' => false ) );
		check_cod( is_array( $flags ), 'An object of permissions survives normalization' );
		check_cod( isset( $flags['codpayment'] ) && true === $flags['codpayment'], 'codPayment is kept as a boolean' );
		check_cod( isset( $flags['addressasdestination'] ) && false === $flags['addressasdestination'], 'and so is a false one' );

		check_cod( array() === $reflection->invoke( null, 'yes' ), 'A scalar (the shape the old code assumed) yields no flags' );
		check_cod( array() === $reflection->invoke( null, null ), 'and so does a missing value' );
	}

	echo "--- Acceptance criterion 3: the limit is defined once ---\n";
	check_cod( 5000.0 === BoxNow::COD_MAX, 'BoxNow::COD_MAX carries the contract limit' );
	{
		$source = php_strip_whitespace( BGCS3_PATH . 'app/Modules/Shipping/BoxNow/BoxNow.php' );
		check_cod(
			1 >= substr_count( $source, '5000' ),
			'The literal 5000 appears at most once in the module — in the constant itself'
		);
		check_cod( false !== strpos( $source, 'self::COD_MAX' ), 'and everything else reads the constant' );
	}

	echo "--- Acceptance criterion 4: an existing order over the limit ---\n";
	{
		$selection             = new \BgCommerce3\Support\Selection();
		$selection->courier    = 'boxnow';
		$selection->office     = array( 'id' => 'APM-1' );
		$GLOBALS['bgcs_selection'] = $selection;

		set_account( true );

		$order  = new WC_Order( 'cod', 7500.0 );
		$result = $boxnow->create_label( $order );
		check_cod( $result instanceof Label_Result && ! $result->success, 'Creating the shipment is refused' );
		check_cod(
			$result instanceof Label_Result && false !== strpos( implode( ' ', $result->errors ), 'P408' ),
			'…naming the contract error the merchant would otherwise have hit'
		);

		// Never by trimming the amount: the courier would collect less than due,
		// so the merchant is told the real figure and the ceiling, and decides.
		$message = $result instanceof Label_Result ? implode( ' ', $result->errors ) : '';
		check_cod( false !== strpos( $message, '7 500.00' ), '…stating the amount the order actually asks for' );
		check_cod( false !== strpos( $message, '5 000.00' ), '…and the limit it exceeds' );

		set_account( false );
		$denied = $boxnow->create_label( new WC_Order( 'cod', 50.0 ) );
		check_cod(
			$denied instanceof Label_Result && false !== strpos( implode( ' ', $denied->errors ), 'P411' ),
			'An unentitled account is refused before the request, naming P411'
		);

		set_account( true );
		$guard = new ReflectionMethod( BoxNow::class, 'validate_cod_for_order' );
		$guard->setAccessible( true );
		check_cod(
			null === $guard->invoke( $boxnow, new WC_Order( 'bacs', 7500.0 ), array() ),
			'A prepaid order of the same value passes the COD guard untouched'
		);
		check_cod(
			null === $guard->invoke( $boxnow, new WC_Order( 'cod', 5000.0 ), array() ),
			'and so does a COD order exactly at the limit'
		);
	}

	echo "--- The guard runs before anything is sent ---\n";
	{
		$code  = php_strip_whitespace( BGCS3_PATH . 'app/Modules/Shipping/BoxNow/BoxNow.php' );
		$body  = substr( $code, strpos( $code, 'function create_label' ) );
		$guard = strpos( $body, 'validate_cod_for_order' );
		$send  = strpos( $body, '$this->client()' );

		check_cod( false !== $guard, 'create_label() consults the COD guard' );
		check_cod( false === $send || $guard < $send, 'and does so before touching the client' );
	}

	echo PHP_EOL;
	if ( $failures > 0 ) {
		echo "FAILED: {$failures} check(s)" . PHP_EOL;
		exit( 1 );
	}
	echo 'OK — all BOX NOW cash-on-delivery checks passed' . PHP_EOL;
}
