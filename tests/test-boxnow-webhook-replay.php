<?php
/**
 * TASK-D1 — BGCS-AUDIT-007: replay protection for the BOX NOW webhook.
 *
 * The signature check was and is correct; this is about what happens to a
 * validly signed message that has already been handled. Two weaknesses:
 *
 *   1. "Duplicate" meant "same message id as the LAST event", so replaying a
 *      message from two events ago was not a duplicate at all.
 *   2. Both that check and the ordering check switched themselves off when an
 *      optional field was missing. With neither `id` nor `data.time` present, a
 *      signed message could be submitted indefinitely and applied every time.
 *
 * Live evidence from the audit: three `delivered` events applied to order 8214,
 * and a `new` event applied 25 seconds AFTER `delivered` on order 8232.
 *
 * This matters because duplicates are ORDINARY here, not an attack: BOX NOW's
 * own manual states it retries a message until the receiver answers 200 OK,
 * roughly every ten minutes, with the last attempt 24 hours after the event.
 *
 * Run: php tests/test-boxnow-webhook-replay.php
 */

namespace BgCommerce3\Modules\Shipping {
	abstract class Abstract_Courier {}
}

namespace BgCommerce3\Container {
	class Container {}
}

namespace BgCommerce3\Admin {
	class Icons {}
}

namespace BgCommerce3\Support {
	class Selection {}
	class Price_Result {}
	class Tracking_Result {}
	class Sync_Result {}
	class Cache {}
	class Shipping_Availability {}
	class Label_Result {}
	class Label_Pdf_Store {}
}

namespace {

	define( 'ABSPATH', __DIR__ );

	/** Stand-in for the WordPress error object `Webhook::fail()` returns. */
	class WP_Error {

		/** @var string */
		private $code;

		/** @var mixed */
		private $data;

		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = (string) $code;
			$this->data = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_data() {
			return $this->data;
		}
	}

	function __( $text, $domain = null ) {
		return $text;
	}
	function esc_html( $text ) {
		return $text;
	}
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
	}
	function absint( $value ) {
		return abs( (int) $value );
	}

	// The real state vocabulary, so the parse path is exercised as it ships.
	require_once dirname( __DIR__ ) . '/app/Shipping/Tracking_State.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Shipping/BoxNow/Webhook.php';

	use BgCommerce3\Modules\Shipping\BoxNow\Webhook;

	$failures = 0;
	function check_hook( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failures++;
		}
	}

	const SECRET = 'shared-secret-from-boxnow';

	/**
	 * Build a message exactly as BOX NOW sends it: CloudEvents envelope, the
	 * signature computed over the raw `data` substring.
	 *
	 * @param array<string,mixed> $data       The `data` object.
	 * @param string|null         $message_id CloudEvents id, or null to omit it.
	 * @return string Raw request body.
	 */
	function boxnow_message( array $data, $message_id = 'msg-1' ) {
		$raw_data  = json_encode( $data );
		$signature = hash_hmac( 'sha256', $raw_data, SECRET );

		$envelope = '{"specversion":"1.0","type":"bg.boxnow.parcel_event_change"'
			. ',"source":"https://boxnow.bg/api/v1/webhooks/1"';
		if ( null !== $message_id ) {
			$envelope .= ',"id":' . json_encode( (string) $message_id );
		}
		$envelope .= ',"datacontenttype":"application/json"'
			. ',"datasignature":' . json_encode( $signature )
			. ',"data":' . $raw_data . '}';

		return $envelope;
	}

	// Frozen once, so two fixtures built during the same run are byte-identical
	// and no fixture silently ages out of the freshness window on a later date.
	$GLOBALS['bgcs_now'] = time();

	/**
	 * @param string     $event  Parcel event.
	 * @param int|false  $offset Seconds relative to the frozen now; false omits
	 *                           `data.time` entirely.
	 * @param string     $order  Shipment reference.
	 * @return array<string,mixed>
	 */
	function parcel_data( $event, $offset = 0, $order = 'abcd1234-8232-1' ) {
		$data = array(
			'parcelId'    => '5321030874',
			'orderNumber' => $order,
			'event'       => $event,
		);
		if ( false !== $offset ) {
			$data['time'] = gmdate( 'Y-m-d\TH:i:s\Z', $GLOBALS['bgcs_now'] + (int) $offset );
		}
		return $data;
	}

	echo "--- The signature check is untouched and still decides first ---\n";
	{
		$body = boxnow_message( parcel_data( 'delivered' ) );

		$ok = Webhook::parse( $body, SECRET );
		check_hook( is_array( $ok ), 'A correctly signed message parses' );

		$wrong = Webhook::parse( $body, 'not-the-secret' );
		check_hook( is_wp_error_like( $wrong, 'bad_signature', 403 ), 'A wrong secret is rejected with 403' );

		$missing = Webhook::parse( $body, '' );
		check_hook( is_wp_error_like( $missing, 'no_secret', 503 ), 'A missing secret answers 503, so real events are retried not lost' );

		$tampered = str_replace( '"delivered"', '"cancelled"', $body );
		check_hook( is_wp_error_like( Webhook::parse( $tampered, SECRET ), 'bad_signature', 403 ), 'Editing the event after signing is rejected' );
	}

	echo "--- Every message yields identifiers, whatever the sender omitted ---\n";
	{
		$with_id = Webhook::parse( boxnow_message( parcel_data( 'delivered' ), 'msg-42' ), SECRET );
		check_hook( in_array( 'id:msg-42', $with_id['fingerprints'], true ), 'The CloudEvents message id is one fingerprint' );
		check_hook( 2 === count( $with_id['fingerprints'] ), 'and the signed payload digest is the other' );

		// The case the finding is about: neither optional field present.
		$bare = Webhook::parse( boxnow_message( parcel_data( 'delivered', false ), null ), SECRET );
		check_hook( '' === $bare['message_id'], 'A message with no id parses' );
		check_hook( '' === $bare['time'], 'and with no data.time' );
		check_hook( 1 === count( $bare['fingerprints'] ), 'It still yields exactly one fingerprint' );
		check_hook( 0 === strpos( $bare['fingerprints'][0], 'body:' ), '…derived from the signed content, not from a field that may be absent' );
	}

	echo "--- Identity is per message, not per event name ---\n";
	{
		$first  = Webhook::parse( boxnow_message( parcel_data( 'in-transit', -3600 ), null ), SECRET );
		$second = Webhook::parse( boxnow_message( parcel_data( 'delivered', 0 ), null ), SECRET );
		check_hook(
			array() === array_intersect( $first['fingerprints'], $second['fingerprints'] ),
			'Two different events never collide'
		);

		$same_event_later = Webhook::parse( boxnow_message( parcel_data( 'delivered', 3600 ), null ), SECRET );
		check_hook(
			array() === array_intersect( $second['fingerprints'], $same_event_later['fingerprints'] ),
			'The same event name at a different time is a different message'
		);

		$byte_identical = Webhook::parse( boxnow_message( parcel_data( 'delivered', 0 ), null ), SECRET );
		check_hook(
			array() !== array_intersect( $second['fingerprints'], $byte_identical['fingerprints'] ),
			'A byte-identical redelivery IS the same message'
		);
	}

	echo "--- A retry that arrives with a fresh message id is still a retry ---\n";
	{
		$data     = parcel_data( 'delivered' );
		$attempt1 = Webhook::parse( boxnow_message( $data, 'msg-a' ), SECRET );
		$attempt2 = Webhook::parse( boxnow_message( $data, 'msg-b' ), SECRET );

		check_hook(
			array() === array_intersect( array( 'id:msg-a' ), $attempt2['fingerprints'] ),
			'The two deliveries carry different message ids'
		);
		check_hook(
			array() !== array_intersect( $attempt1['fingerprints'], $attempt2['fingerprints'] ),
			'…but the signed payload digest recognises them as one event'
		);
	}

	// -----------------------------------------------------------------------
	// The real handler, so the decision under test is the one that ships
	// -----------------------------------------------------------------------

	require_once dirname( __DIR__ ) . '/app/Support/Options.php';
	require_once dirname( __DIR__ ) . '/app/Support/Module_Settings.php';
	require_once dirname( __DIR__ ) . '/app/Shipping/Tracking_Store.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Shipping/BoxNow/BoxNow.php';

	use BgCommerce3\Modules\Shipping\BoxNow\BoxNow;

	$boxnow = new BoxNow();

	/**
	 * Delivers one signed body to the real handler and reports what happened.
	 *
	 * @param BoxNow $boxnow  Module.
	 * @param string $body    Raw request body.
	 * @return array{result:mixed,applied:int,action:string}
	 */
	function deliver( $boxnow, $body ) {
		$before_applied = count( $GLOBALS['bgcs_applied'] );
		$result         = $boxnow->handle_webhook( new Fake_Rest_Request( $body ) );
		$history        = bgcs3_get_option( 'boxnow', '_webhook_history', array() );

		return array(
			'result'  => $result,
			'applied' => count( $GLOBALS['bgcs_applied'] ) - $before_applied,
			'action'  => isset( $history[0]['action'] ) ? $history[0]['action'] : '',
		);
	}

	function reset_world() {
		$GLOBALS['bgcs_applied'] = array();
		$GLOBALS['bgcs_notes']   = array();
		$GLOBALS['bgcs_options'] = array(
			'bgcs3_boxnow' => array( 'webhook_secret' => SECRET ),
		);
		$GLOBALS['bgcs_order']   = new WC_Order( 8232, array(
			'_bgcs3_selection' => array( 'courier' => 'boxnow' ),
		) );
	}

	echo "--- Acceptance criterion 1: the same message, three times, applied once ---\n";
	{
		reset_world();
		$body = boxnow_message( parcel_data( 'delivered' ), 'msg-dup' );

		$first  = deliver( $boxnow, $body );
		$second = deliver( $boxnow, $body );
		$third  = deliver( $boxnow, $body );

		check_hook( 1 === $first['applied'], 'The first delivery is applied' );
		check_hook( 'applied' === $first['action'], 'and recorded as applied' );
		check_hook( 0 === $second['applied'] && 0 === $third['applied'], 'The retries are not applied again' );
		check_hook( 'duplicate_ignored' === $third['action'], 'and are recorded as duplicate_ignored' );
		check_hook( true === $second['result'], 'A retry answers 200, so BOX NOW stops redelivering it' );
		check_hook( 1 === count( $GLOBALS['bgcs_notes'] ), 'The order gets one note, not three' );
	}

	echo "--- The case with neither an id nor an event time ---\n";
	{
		reset_world();
		// This is the exact reproduction from the finding: with both optional
		// fields absent, every check used to switch itself off.
		$body = boxnow_message( parcel_data( 'delivered', false ), null );

		$first  = deliver( $boxnow, $body );
		$second = deliver( $boxnow, $body );
		$third  = deliver( $boxnow, $body );

		check_hook( 1 === $first['applied'], 'The first delivery is applied' );
		check_hook( 0 === $second['applied'] && 0 === $third['applied'], 'Repeats are refused even with no id and no time' );
		check_hook( 'duplicate_ignored' === $second['action'], 'and recorded as duplicate_ignored' );
	}

	echo "--- Acceptance criterion 2: a replay from several events ago ---\n";
	{
		reset_world();
		$first_body = boxnow_message( parcel_data( 'new', -6 * 3600 ), 'msg-0' );
		deliver( $boxnow, $first_body );

		// Five newer events in between, so the replayed one is nowhere near
		// "the last event" the old check compared against.
		for ( $i = 1; $i <= 5; $i++ ) {
			deliver( $boxnow, boxnow_message( parcel_data( 'in-transit', -3600 * ( 6 - $i ) ), 'msg-' . $i ) );
		}

		$replay = deliver( $boxnow, $first_body );
		check_hook( 0 === $replay['applied'], 'Replaying the first message is refused six events later' );
		check_hook( 'duplicate_ignored' === $replay['action'], 'and recorded as duplicate_ignored' );
	}

	echo "--- A retry redelivered under a new message id ---\n";
	{
		reset_world();
		$data = parcel_data( 'delivered' );

		$first = deliver( $boxnow, boxnow_message( $data, 'attempt-1' ) );
		$again = deliver( $boxnow, boxnow_message( $data, 'attempt-2' ) );

		check_hook( 1 === $first['applied'], 'The first attempt is applied' );
		check_hook( 0 === $again['applied'], 'The same event under a different id is still recognised' );
	}

	echo "--- Acceptance criterion 3: outside the freshness window ---\n";
	{
		reset_world();
		$out = deliver( $boxnow, boxnow_message( parcel_data( 'delivered', -30 * 86400 ), 'msg-old' ) );

		check_hook( 0 === $out['applied'], 'A month-old signed event is not applied' );
		check_hook( 'expired_ignored' === $out['action'], 'and is recorded as expired_ignored' );

		reset_world();
		$in     = deliver( $boxnow, boxnow_message( parcel_data( 'delivered', -23 * 3600 ), 'msg-retry' ) );
		check_hook( 1 === $in['applied'], "A 23-hour-old retry is still applied — inside BOX NOW's own retry horizon" );
	}

	echo "--- Ordering: the live case from the audit ---\n";
	{
		reset_world();

		check_hook( 1 === deliver( $boxnow, boxnow_message( parcel_data( 'delivered', -120 ), 'm1' ) )['applied'], 'delivered is applied' );

		// `new` was created 25 seconds earlier but arrives afterwards.
		$late = deliver( $boxnow, boxnow_message( parcel_data( 'new', -145 ), 'm2' ) );
		check_hook( 0 === $late['applied'], 'A `new` event dated before it is not applied afterwards' );
		check_hook( 'stale_ignored' === $late['action'], 'and is recorded as stale_ignored, distinct from expired' );
	}

	echo "--- A signed message about somebody else's order is not acted on ---\n";
	{
		reset_world();
		$GLOBALS['bgcs_order'] = new WC_Order( 8232, array( '_bgcs3_selection' => array( 'courier' => 'speedy' ) ) );
		$other = deliver( $boxnow, boxnow_message( parcel_data( 'delivered' ), 'msg-other' ) );
		check_hook( 0 === $other['applied'], 'An order that is not a BOX NOW order is left alone' );
	}

	// -----------------------------------------------------------------------
	// Static guards
	// -----------------------------------------------------------------------

	echo "--- Static guards ---\n";
	{
		$root   = dirname( __DIR__ );
		$boxnow = php_strip_whitespace( $root . '/app/Modules/Shipping/BoxNow/BoxNow.php' );

		check_hook( false !== strpos( $boxnow, 'WEBHOOK_SEEN_LIMIT' ), 'The remembered set is bounded by a named limit' );
		check_hook( false !== strpos( $boxnow, "'expired_ignored'" ), 'An out-of-window event is recorded distinctly from a stale one' );
		check_hook(
			false === strpos( $boxnow, "! empty( \$parsed['message_id'] ) && ! empty( \$last['message_id'] )" ),
			'Duplicate detection no longer depends on both sides carrying a message id'
		);

		$webhook = php_strip_whitespace( $root . '/app/Modules/Shipping/BoxNow/Webhook.php' );
		check_hook( false !== strpos( $webhook, 'hash_equals' ), 'The signature is still compared in constant time' );
		check_hook( false !== strpos( $webhook, "hash_hmac( 'sha256'" ), 'and still with HMAC-SHA256' );

		$legend = php_strip_whitespace( $root . '/app/Admin/Settings/Settings_Page.php' );
		foreach ( array( 'applied', 'duplicate_ignored', 'expired_ignored', 'stale_ignored' ) as $action ) {
			check_hook( false !== strpos( $legend, $action ), "Diagnostics explains '{$action}'" );
		}
	}

	echo PHP_EOL;
	if ( $failures > 0 ) {
		echo "FAILED: {$failures} check(s)" . PHP_EOL;
		exit( 1 );
	}
	echo 'OK — all BOX NOW webhook replay checks passed' . PHP_EOL;

	/* ------------------------------------------------------------------ */
	/* Doubles                                                             */
	/* ------------------------------------------------------------------ */

	class Fake_Rest_Request {
		/** @var string */
		private $body;
		public function __construct( $body ) {
			$this->body = (string) $body;
		}
		public function get_body() {
			return $this->body;
		}
	}

	class WC_Order {
		/** @var int */
		private $id;
		/** @var array<string,mixed> */
		private $meta;
		public function __construct( $id, array $meta = array() ) {
			$this->id   = (int) $id;
			$this->meta = $meta;
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
		public function save() {
			return $this->id;
		}
		public function add_order_note( $note ) {
			$GLOBALS['bgcs_notes'][] = (string) $note;
		}
	}

	function wc_get_order( $id ) {
		return isset( $GLOBALS['bgcs_order'] ) && (int) $id === $GLOBALS['bgcs_order']->get_id()
			? $GLOBALS['bgcs_order']
			: false;
	}

	function get_option( $name, $default = false ) {
		return isset( $GLOBALS['bgcs_options'][ $name ] ) ? $GLOBALS['bgcs_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['bgcs_options'][ $name ] = $value;
		return true;
	}
	function bgcs3_get_option( $group, $key = null, $default = null ) {
		return \BgCommerce3\Support\Options::get( $group, $key, $default );
	}
	function apply_filters( $hook, $value = null ) {
		return $value;
	}
	function do_action( $hook ) {
		$args = func_get_args();
		if ( 'bgcs3_apply_pushed_tracking_event' === $hook ) {
			$GLOBALS['bgcs_applied'][] = isset( $args[3] ) ? $args[3] : array();
		}
	}
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	function is_wp_error_like( $value, $code, $status ) {
		return $value instanceof WP_Error
			&& $code === $value->get_error_code()
			&& $status === (int) $value->get_error_data();
	}
}
