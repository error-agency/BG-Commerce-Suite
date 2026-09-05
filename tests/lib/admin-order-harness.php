<?php
/**
 * Shared harness for the tests that drive the two admin create-label endpoints
 * (`MetaBox::ajax_create_label()` and `Orders_Column::ajax_quick_create_label()`)
 * without booting WordPress.
 *
 * Extracted rather than copied: the duplication these tests cover
 * (BGCS-AUDIT-006 — two parallel snapshot builders that drifted apart) is
 * exactly the mistake a copied harness would repeat.
 *
 * Provides: WordPress/WooCommerce function doubles, an options table double
 * with the MySQL semantics `Creation_Lock` depends on, an order double backed
 * by a shared "database", `run_request()`, and a no-op courier base.
 *
 * @package BgCommerce3
 */

// ---------------------------------------------------------------------------
// WordPress / WooCommerce doubles
// ---------------------------------------------------------------------------

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

/** Stands in for wp_die() ending the request after wp_send_json_*(). */
class Bgcs_Json_Response extends \Exception {

	/** @var bool */
	public $ok;

	/** @var mixed */
	public $payload;

	public function __construct( $ok, $payload ) {
		parent::__construct( 'json' );
		$this->ok      = (bool) $ok;
		$this->payload = $payload;
	}
}

function wp_send_json_success( $data = null ) {
	throw new Bgcs_Json_Response( true, $data );
}
function wp_send_json_error( $data = null ) {
	throw new Bgcs_Json_Response( false, $data );
}
function check_ajax_referer( $action, $arg = false ) {
	return true;
}
function current_user_can( $cap ) {
	return true;
}
function absint( $value ) {
	return abs( (int) $value );
}
function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
}
function sanitize_text_field( $value ) {
	return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
}
function sanitize_file_name( $value ) {
	return preg_replace( '/[^a-zA-Z0-9._-]/', '', basename( (string) $value ) );
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function __( $text, $domain = null ) {
	return $text;
}
function esc_html( $text ) {
	return $text;
}
function esc_attr( $text ) {
	return $text;
}
function home_url() {
	return 'https://shop.example.test';
}
function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}
function get_option( $name, $default = false ) {
	return $default;
}
function bgcs3_get_option( $courier_id, $key, $default = null ) {
	return $default;
}
function wp_generate_uuid4() {
	return sprintf( '%04x%04x-%04x-%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
}
function wc_get_order( $id ) {
	$id = (int) $id;
	return isset( Fake_Order_Store::$rows[ $id ] ) ? new WC_Order( $id ) : false;
}
function apply_filters( $hook, $value = null ) {
	return $value;
}
function do_action( $hook ) {
}

// Object cache — per-request array, as on an install without a persistent one.
$GLOBALS['bgcs_cache'] = array();
function wp_cache_get( $key, $group = '' ) {
	return isset( $GLOBALS['bgcs_cache'][ $group ][ $key ] ) ? $GLOBALS['bgcs_cache'][ $group ][ $key ] : false;
}
function wp_cache_set( $key, $value, $group = '' ) {
	$GLOBALS['bgcs_cache'][ $group ][ $key ] = $value;
	return true;
}
function wp_cache_delete( $key, $group = '' ) {
	unset( $GLOBALS['bgcs_cache'][ $group ][ $key ] );
	return true;
}

/**
 * Options table double with the MySQL semantics the lock depends on:
 * INSERT IGNORE refuses a duplicate key instead of overwriting it (which is
 * exactly what `add_option()`'s ON DUPLICATE KEY UPDATE did not do), and the
 * conditional UPDATE/DELETE only touch a row whose value still matches.
 */
class Fake_Wpdb {

	/** @var string */
	public $options = 'wp_options';

	/** @var string */
	public $prefix = 'wp_';

	/** @var array<string,string> option_name => option_value */
	public $rows = array();

	/** @var string[] Statements that did not match a known shape. */
	public $unrecognized = array();

	public function prepare( $query, ...$args ) {
		$index = 0;
		return preg_replace_callback(
			'/%[sd]/',
			static function ( $match ) use ( &$index, $args ) {
				$value = array_key_exists( $index, $args ) ? $args[ $index ] : '';
				$index++;
				return ( '%d' === $match[0] ) ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function query( $sql ) {
		$sql = trim( preg_replace( '/\s+/', ' ', (string) $sql ) );

		if ( preg_match( "/^INSERT IGNORE INTO \S+ \(option_name, option_value, autoload\) VALUES \('([^']*)', '([^']*)', 'no'\)$/", $sql, $m ) ) {
			if ( array_key_exists( $m[1], $this->rows ) ) {
				return 0;
			}
			$this->rows[ $m[1] ] = $m[2];
			return 1;
		}

		if ( preg_match( "/^UPDATE \S+ SET option_value = '([^']*)' WHERE option_name = '([^']*)' AND option_value = '([^']*)'$/", $sql, $m ) ) {
			if ( ! isset( $this->rows[ $m[2] ] ) || $this->rows[ $m[2] ] !== $m[3] ) {
				return 0;
			}
			$this->rows[ $m[2] ] = $m[1];
			return 1;
		}

		$this->unrecognized[] = $sql;
		return false;
	}

	public function get_var( $sql ) {
		$sql = trim( preg_replace( '/\s+/', ' ', (string) $sql ) );

		if ( preg_match( "/^SELECT option_value FROM \S+ WHERE option_name = '([^']*)' LIMIT 1$/", $sql, $m ) ) {
			return isset( $this->rows[ $m[1] ] ) ? $this->rows[ $m[1] ] : null;
		}

		$this->unrecognized[] = $sql;
		return null;
	}

	public function delete( $table, array $where, $formats = null ) {
		$name  = isset( $where['option_name'] ) ? $where['option_name'] : '';
		$value = isset( $where['option_value'] ) ? $where['option_value'] : '';
		if ( isset( $this->rows[ $name ] ) && $this->rows[ $name ] === $value ) {
			unset( $this->rows[ $name ] );
			return 1;
		}
		return 0;
	}
}

$GLOBALS['wpdb'] = new Fake_Wpdb();

/** The "database": survives across simulated requests, unlike an order object. */
class Fake_Order_Store {

	/** @var array<int,array<string,mixed>> */
	public static $rows = array();

	/** @var array<int,string[]> */
	public static $notes = array();

	/** @var callable|null Fires inside save(), to interleave a second request. */
	public static $on_save = null;

	public static function seed( $id, array $meta ) {
		self::$rows[ (int) $id ]  = $meta;
		self::$notes[ (int) $id ] = array();
	}
}

/**
 * Order double. Each instance loads its own copy of the committed meta, so a
 * second simulated request sees exactly what a second PHP worker would see:
 * the database, not the first request's unsaved changes.
 */
class WC_Order {

	/** @var int */
	private $id;

	/** @var array<string,mixed> */
	private $meta;

	public function __construct( $id ) {
		$this->id   = (int) $id;
		$this->meta = isset( Fake_Order_Store::$rows[ $this->id ] ) ? Fake_Order_Store::$rows[ $this->id ] : array();
	}

	public function get_id() {
		return $this->id;
	}

	public function get_meta( $key, $single = true, $context = 'view' ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function add_order_note( $note ) {
		Fake_Order_Store::$notes[ $this->id ][] = (string) $note;
	}

	public function save() {
		$hook = Fake_Order_Store::$on_save;
		if ( is_callable( $hook ) ) {
			// One-shot: the interleaved request must not recurse forever.
			Fake_Order_Store::$on_save = null;
			call_user_func( $hook );
		}
		Fake_Order_Store::$rows[ $this->id ] = $this->meta;
		return $this->id;
	}

	public function get_payment_method() {
		return 'cod';
	}

	public function get_currency() {
		return 'BGN';
	}

	public function get_total() {
		return '120.00';
	}

	public function get_items( $type = 'line_item' ) {
		return array();
	}

	public function has_status( $status ) {
		return false;
	}

	public function get_status() {
		return 'processing';
	}

	public function get_formatted_billing_full_name() {
		return 'Тест Клиент';
	}

	public function get_billing_email() {
		return 'test@example.com';
	}
}

// ---------------------------------------------------------------------------
// Plugin classes under test
// ---------------------------------------------------------------------------

$app = dirname( dirname( __DIR__ ) ) . '/app';

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/bgcs-test-content' );
}

require_once $app . '/Container/Container.php';
require_once $app . '/Admin/Icons.php';
require_once $app . '/Support/Selection.php';
require_once $app . '/Support/Label_Result.php';
require_once $app . '/Support/Label_Pdf_Store.php';
require_once $app . '/Support/Options.php';
require_once $app . '/Support/Module_Settings.php';
require_once $app . '/Shipping/Creation_Lock.php';
require_once $app . '/Shipping/Pickup_Request.php';
require_once $app . '/Shipping/Courier_Error.php';
require_once $app . '/Shipping/Label_Snapshot.php';
require_once $app . '/Shipping/Shipment_Reference.php';
require_once $app . '/Shipping/Shipment_Creation.php';
require_once $app . '/Shipping/Shipment_Mutation.php';
require_once $app . '/Shipping/Overrides.php';
require_once $app . '/Shipping/Cod.php';
require_once $app . '/Shipping/Weight.php';
require_once $app . '/Module/Module_Interface.php';
require_once $app . '/Modules/Shipping/Courier_Interface.php';
require_once $app . '/Shipping/Pricing.php';
require_once $app . '/Admin/Order/MetaBox.php';
require_once $app . '/Admin/Order/Orders_Column.php';

use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Support\Selection;

/**
 * Runs one simulated AJAX request and returns ['ok' => bool, 'payload' => mixed].
 * `wp_send_json_*` ends a real request via wp_die(); here it unwinds as an
 * exception, which is what lets a second request be driven inline.
 */
function run_request( $handler ) {
	try {
		call_user_func( $handler );
	} catch ( Bgcs_Json_Response $response ) {
		return array( 'ok' => $response->ok, 'payload' => $response->payload );
	}
	return array( 'ok' => null, 'payload' => null );
}

/** Shared no-op implementations of the parts of the interface this test does not exercise. */
abstract class Test_Courier implements Courier_Interface {

	public function name() {
		return 'Test courier';
	}
	public function category() {
		return 'shipping';
	}
	public function requires_api() {
		return true;
	}
	public function is_enabled() {
		return true;
	}
	public function settings_tab() {
		return array();
	}
	public function settings_fields() {
		return array();
	}
	public function register( Container $container ) {
	}
	public function delivery_types() {
		return array( 'address' );
	}
	public function client() {
		return null;
	}
	public function locations() {
		return null;
	}
	public function checkout_schema( $delivery_type ) {
		return array();
	}
	public function validate( Selection $selection ) {
		return array();
	}
	public function quote( array $package, Selection $selection ) {
		return null;
	}
	public function calculate_surcharges( array $package, Selection $selection, $base_cost = 0.0 ) {
		return array();
	}
	public function delete_label( \WC_Order $order ) {
		return true;
	}
	public function tracking( \WC_Order $order ) {
		return null;
	}
	public function normalize_status( array $event ) {
		return array();
	}
}

/** Module registry double. */
class Fake_Modules {

	/** @var Courier_Interface */
	private $courier;

	public function __construct( $courier ) {
		$this->courier = $courier;
	}

	public function get( $id ) {
		return ( $this->courier && (string) $id === (string) $this->courier->id() ) ? $this->courier : null;
	}
}
