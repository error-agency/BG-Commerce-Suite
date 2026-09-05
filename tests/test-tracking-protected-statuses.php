<?php
/**
 * TASK-E1 — BGCS-AUDIT-008: courier events must not undo a financial decision.
 *
 * `apply_to_order()` modelled the transition as an assignment: the only check
 * was that the new status differed from the current one. So a `delivered` event
 * arriving late — or replayed from the courier's history — moved a REFUNDED
 * order to `completed`, which sends the customer a „your order is complete“
 * email and misstates revenue.
 *
 * The automation is off by default and only `delivered` has a non-null default
 * mapping, which is why this is Medium rather than High. It does not help the
 * merchants who deliberately switched it on.
 *
 * Run: php tests/test-tracking-protected-statuses.php
 */

namespace BgCommerce3\Modules\Shipping {
	interface Courier_Interface {}
}

namespace {

	define( 'ABSPATH', __DIR__ );

	$GLOBALS['bgcs_options'] = array(
		'bgcs3_checkout' => array( 'update_order_statuses' => 'yes' ),
	);
	$GLOBALS['bgcs_filters'] = array();

	function bgcs3_get_option( $group, $key = null, $default = null ) {
		$data = isset( $GLOBALS['bgcs_options'][ 'bgcs3_' . $group ] ) ? $GLOBALS['bgcs_options'][ 'bgcs3_' . $group ] : array();
		if ( null === $key ) {
			return $data;
		}
		return array_key_exists( $key, $data ) ? $data[ $key ] : $default;
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
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
	function __( $text, $domain = null ) {
		return $text;
	}
	function wc_get_order_status_name( $status ) {
		$names = array(
			'refunded'   => 'Възстановена сума',
			'cancelled'  => 'Отказана',
			'failed'     => 'Неуспешна',
			'completed'  => 'Завършена',
			'processing' => 'Обработва се',
			'on-hold'    => 'Задържана',
		);
		return isset( $names[ $status ] ) ? $names[ $status ] : $status;
	}

	/** Order double that records what the policy did to it. */
	class WC_Order {

		/** @var string */
		private $status;

		/** @var string[] */
		public $notes = array();

		/** @var string[] */
		public $transitions = array();

		/** @var array<string,mixed> */
		private $meta = array();

		public function __construct( $status ) {
			$this->status = (string) $status;
		}
		public function get_id() {
			return 8351;
		}
		public function get_status() {
			return $this->status;
		}
		public function get_meta( $key ) {
			return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
		}
		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}
		public function update_status( $status, $note = '', $manual = false ) {
			$this->transitions[] = (string) $status;
			$this->status        = (string) $status;
			$this->notes[]       = (string) $note;
			return true;
		}
		public function add_order_note( $note ) {
			$this->notes[] = (string) $note;
			return 1;
		}
	}

	require_once dirname( __DIR__ ) . '/app/Shipping/Tracking_State.php';
	require_once dirname( __DIR__ ) . '/app/Shipping/Tracking_Status_Catalog.php';
	require_once dirname( __DIR__ ) . '/app/Shipping/Tracking_Status_Policy.php';

	use BgCommerce3\Shipping\Tracking_State;
	use BgCommerce3\Shipping\Tracking_Status_Policy;

	$failures = 0;
	function check_status( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failures++;
		}
	}

	/**
	 * @param string $from  Order status before the event.
	 * @param string $state Canonical shipment state the courier reported.
	 * @return WC_Order
	 */
	function apply_event( $from, $state ) {
		$order = new WC_Order( $from );
		Tracking_Status_Policy::apply_to_order( $order, $state, 'boxnow' );
		return $order;
	}

	echo "--- Acceptance criteria 1 and 2: a financial decision is not undone ---\n";
	foreach ( array( 'refunded', 'cancelled', 'failed' ) as $protected ) {
		$order = apply_event( $protected, Tracking_State::DELIVERED );

		check_status( $protected === $order->get_status(), "A `delivered` event leaves a {$protected} order alone" );
		check_status( array() === $order->transitions, '…without calling update_status() at all' );
		check_status( 1 === count( $order->notes ), '…and records exactly one explanatory note' );
		check_status(
			! empty( $order->notes ) && false !== strpos( $order->notes[0], wc_get_order_status_name( $protected ) ),
			'…naming the status the merchant is in'
		);
		check_status(
			! empty( $order->notes ) && false !== strpos( $order->notes[0], wc_get_order_status_name( 'completed' ) ),
			'…and the status that was not applied, so nothing is hidden'
		);
	}

	echo "--- Ordinary working statuses are untouched by the guard ---\n";
	// `on-hold` is deliberately NOT protected by default: it is a working status
	// (awaiting a bank transfer, awaiting stock), and shops rely on delivery
	// completing such orders. See the note on is_protected_status().
	foreach ( array( 'processing', 'on-hold' ) as $working ) {
		$order = apply_event( $working, Tracking_State::DELIVERED );
		check_status( 'completed' === $order->get_status(), "A `delivered` event still completes a {$working} order" );
		check_status( array( 'completed' ) === $order->transitions, '…through a real status transition' );
	}

	echo "--- Repeated protected decisions are idempotent ---\n";
	{
		$order = new WC_Order( 'refunded' );
		Tracking_Status_Policy::apply_to_order( $order, Tracking_State::DELIVERED, 'boxnow' );
		Tracking_Status_Policy::apply_to_order( $order, Tracking_State::DELIVERED, 'boxnow' );
		check_status( 1 === count( $order->notes ), 'Repeated polling of the same protected decision writes one note' );
		check_status( array() === $order->transitions, 'Repeated polling still performs no status transition' );
	}

	echo "--- The full matrix the finding asks for ---\n";
	$GLOBALS['bgcs_options']['bgcs3_checkout']['status_on_returned'] = 'cancelled';

	foreach ( array( 'refunded', 'cancelled', 'failed', 'processing', 'on-hold' ) as $from ) {
		foreach ( array(
			'DELIVERED' => Tracking_State::DELIVERED,
			'RETURNED'  => Tracking_State::RETURNED,
			'CANCELLED' => Tracking_State::CANCELLED,
		) as $label => $state ) {
			$order     = apply_event( $from, $state );
			$protected = in_array( $from, array( 'refunded', 'cancelled', 'failed' ), true );

			if ( $protected ) {
				check_status( $from === $order->get_status(), sprintf( '%-10s + %-9s → unchanged', $from, $label ) );
			} else {
				check_status( true, sprintf( '%-10s + %-9s → %s', $from, $label, $order->get_status() ) );
			}
		}
	}
	unset( $GLOBALS['bgcs_options']['bgcs3_checkout']['status_on_returned'] );

	echo "--- Acceptance criterion 3: the protected set is filterable ---\n";
	{
		// A shop where `on-hold` means "bank transfer not confirmed" must be able
		// to stop a delivery event marking the order paid. This is the case the
		// merchant's own BOX NOW test surfaced on order 8351: a BACS order went
		// on-hold → completed purely because the parcel arrived.
		add_filter(
			'bgcs3_tracking_protected_statuses',
			static function ( $statuses ) {
				$statuses[] = 'on-hold';
				return $statuses;
			}
		);

		$order = apply_event( 'on-hold', Tracking_State::DELIVERED );
		check_status( 'on-hold' === $order->get_status(), 'A shop can protect on-hold as well' );
		check_status( 1 === count( $order->notes ), '…and still sees the event as a note' );
		remove_all_filters( 'bgcs3_tracking_protected_statuses' );

		// The `wc-` prefixed form must work too — WooCommerce hands out both.
		add_filter(
			'bgcs3_tracking_protected_statuses',
			static function () {
				return array( 'wc-processing' );
			}
		);
		check_status( 'processing' === apply_event( 'processing', Tracking_State::DELIVERED )->get_status(), 'A wc- prefixed status is understood' );
		remove_all_filters( 'bgcs3_tracking_protected_statuses' );

		// Emptying the set restores the previous behaviour, for a shop that wants it.
		add_filter(
			'bgcs3_tracking_protected_statuses',
			static function () {
				return array();
			}
		);
		check_status( 'completed' === apply_event( 'refunded', Tracking_State::DELIVERED )->get_status(), 'An empty set opts back into the old behaviour' );
		remove_all_filters( 'bgcs3_tracking_protected_statuses' );

		// A filter returning nonsense must not disable the automation entirely.
		add_filter(
			'bgcs3_tracking_protected_statuses',
			static function () {
				return 'refunded';
			}
		);
		check_status( 'completed' === apply_event( 'refunded', Tracking_State::DELIVERED )->get_status(), 'A non-array filter result is ignored rather than fatal' );
		remove_all_filters( 'bgcs3_tracking_protected_statuses' );
	}

	echo "--- The guard cannot switch the automation off by accident ---\n";
	$GLOBALS['bgcs_options']['bgcs3_checkout']['update_order_statuses'] = 'no';
	$order = apply_event( 'processing', Tracking_State::DELIVERED );
	check_status( 'processing' === $order->get_status(), 'With the automation off nothing moves' );
	check_status( array() === $order->notes, '…and no note is written either — the switch means silence' );
	$GLOBALS['bgcs_options']['bgcs3_checkout']['update_order_statuses'] = 'yes';

	$order = apply_event( 'completed', Tracking_State::DELIVERED );
	check_status( array() === $order->notes, 'An order already in the target status produces no note' );

	echo "--- Acceptance criterion 4: tracking is persisted regardless ---\n";
	{
		$root = dirname( __DIR__ );
		// The guard lives inside apply_to_order(), and every caller writes
		// `_bgcs3_tracking` BEFORE calling it — so refusing a status change can
		// never suppress the event itself.
		foreach ( array( 'app/Background/Auto_Status.php' ) as $file ) {
			$code = php_strip_whitespace( $root . '/' . $file );
			$offset = 0;
			$checked = 0;
			while ( false !== ( $call = strpos( $code, 'Tracking_Status_Policy::apply_to_order', $offset ) ) ) {
				$before = substr( $code, 0, $call );
				check_status(
					false !== strrpos( $before, "update_meta_data( '_bgcs3_tracking'" ),
					basename( $file ) . ': the tracking event is stored before the status is considered'
				);
				$offset = $call + 1;
				$checked++;
			}
			check_status( $checked > 0, basename( $file ) . ': the automation call sites were found' );
		}

		$policy = php_strip_whitespace( $root . '/app/Shipping/Tracking_Status_Policy.php' );
		check_status( false !== strpos( $policy, 'bgcs3_tracking_protected_statuses' ), 'The filter name is the one the finding specifies' );
		check_status( false !== strpos( $policy, "'refunded'" ) && false !== strpos( $policy, "'cancelled'" ), 'refunded and cancelled are protected by default' );
	}

	echo PHP_EOL;
	if ( $failures > 0 ) {
		echo "FAILED: {$failures} check(s)" . PHP_EOL;
		exit( 1 );
	}
	echo 'OK — all protected status checks passed' . PHP_EOL;
}
