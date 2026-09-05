<?php
/**
 * TASK-A2 — BGCS-AUDIT-006 and -004: one snapshot builder, one payer source.
 *
 * The two admin create paths each had their own `populate_label_snapshot()`.
 * `MetaBox` asked the courier module for the payment semantics through
 * `label_snapshot_financials()`; the `Orders_Column` quick-create path did not,
 * and instead read Speedy's `service_payer` setting and applied it to every
 * courier — a key Econt, BOX NOW and Pigeon do not have, so quick-create
 * recorded RECIPIENT for all three regardless of configuration. Which button
 * the merchant pressed decided what the order's financial record said, and COD
 * payout reconciliation reads exactly those fields.
 *
 * Run: php tests/test-label-snapshot.php
 */

define( 'ABSPATH', __DIR__ );

$failures = 0;
function check_snapshot( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

require_once __DIR__ . '/lib/admin-order-harness.php';
require_once __DIR__ . '/lib/settings-scanner.php';

use BgCommerce3\Admin\Order\MetaBox;
use BgCommerce3\Admin\Order\Orders_Column;
use BgCommerce3\Container\Container;
use BgCommerce3\Shipping\Label_Snapshot;
use BgCommerce3\Shipping\Pickup_Request;
use BgCommerce3\Shipping\Shipment_Reference;
use BgCommerce3\Support\Label_Result;

const SNAPSHOT_ORDER_ID = 8271;

/**
 * A courier double whose `label_snapshot_financials()` is configurable, so one
 * test can stand in for each of the four real payment models.
 */
class Snapshot_Courier extends Test_Courier {

	/** @var string */
	private $courier_id;

	/** @var array<string,mixed>|null Null = does not implement the hook at all. */
	private $financials;

	public function __construct( $courier_id, $financials = null ) {
		$this->courier_id = (string) $courier_id;
		$this->financials = $financials;
	}

	public function id() {
		return $this->courier_id;
	}

	public function create_label( \WC_Order $order ) {
		$result             = new Label_Result();
		$result->success    = true;
		$result->courier    = $this->courier_id;
		$result->number     = '1051604239739';
		$result->pdf_url    = 'https://example.test/label.pdf';
		$result->created_at = 1756252800;
		$result->meta       = array( 'courier_request_id' => 'abc-123' );
		return $result;
	}

	public function label_snapshot_financials( \WC_Order $order, array $wb ) {
		return is_array( $this->financials ) ? $this->financials : array();
	}
}

/** A third-party courier that predates the hook and does not implement it. */
class Hookless_Courier extends Test_Courier {

	public function id() {
		return 'legacy_addon';
	}

	public function create_label( \WC_Order $order ) {
		$result             = new Label_Result();
		$result->success    = true;
		$result->courier    = 'legacy_addon';
		$result->number     = '777';
		$result->created_at = 1756252800;
		return $result;
	}
}

/**
 * Seeds the order and runs one create through the given endpoint, returning the
 * persisted `_bgcs3_label`.
 *
 * @param string              $which   'metabox' or 'column'.
 * @param Test_Courier        $courier Courier double.
 * @param array<string,mixed> $meta    Order meta to seed.
 * @return array<string,mixed>
 */
function create_and_capture( $which, $courier, array $meta ) {
	Fake_Order_Store::seed( SNAPSHOT_ORDER_ID, $meta );
	Fake_Order_Store::$on_save = null;
	$GLOBALS['wpdb']->rows     = array();
	$GLOBALS['bgcs_cache']     = array();
	$_POST                     = array( 'order_id' => SNAPSHOT_ORDER_ID, 'nonce' => 'x' );

	// The order panel deliberately re-saves `_bgcs3_wb` from the submitted form
	// before creating (BUG-043/BUG-037), so a fair comparison has to post the
	// values the panel would really be showing. Without this the panel path
	// starts from a blank waybill and the two paths differ for a reason that has
	// nothing to do with the snapshot builder.
	if ( 'metabox' === $which ) {
		$wb = isset( $meta['_bgcs3_wb'] ) && is_array( $meta['_bgcs3_wb'] ) ? $meta['_bgcs3_wb'] : array();
		foreach ( array(
			'wb_weight'       => 'weight',
			'wb_package_type' => 'package_type',
			'wb_payer'        => 'payer',
			'wb_cod_mode'     => 'cod_mode',
			'wb_cod'          => 'cod_amount',
			'wb_dv_mode'      => 'dv_mode',
			'wb_dv'           => 'declared_value',
		) as $post_key => $wb_key ) {
			if ( isset( $wb[ $wb_key ] ) ) {
				$_POST[ $post_key ] = (string) $wb[ $wb_key ];
			}
		}
	}

	$container            = new Container();
	$container['modules'] = new Fake_Modules( $courier );

	$handler = ( 'metabox' === $which )
		? array( new MetaBox( $container ), 'ajax_create_label' )
		: array( new Orders_Column( $container ), 'ajax_quick_create_label' );

	$response = run_request( $handler );
	if ( true !== $response['ok'] ) {
		return array( '__error' => $response['payload'] );
	}

	return Fake_Order_Store::$rows[ SNAPSHOT_ORDER_ID ]['_bgcs3_label'];
}

/** The four real payment models, as the modules now report them. */
$profiles = array(
	'speedy — recipient pays the courier service, COD reduced accordingly' => array(
		'id'         => 'speedy',
		'financials' => array( 'payer' => 'RECIPIENT', 'cod_amount' => 114.5, 'cod_currency' => 'BGN' ),
	),
	'econt — the sender always pays the courier service'                   => array(
		'id'         => 'econt',
		'financials' => array( 'payer' => 'SENDER', 'cod_amount' => 120.0, 'cod_currency' => 'BGN' ),
	),
	'boxnow — a locker network has no courier-service payer'               => array(
		'id'         => 'boxnow',
		'financials' => array( 'payer' => '', 'cod_amount' => 120.0, 'cod_currency' => 'BGN' ),
	),
	'pigeon — payer follows who_pays'                                      => array(
		'id'         => 'pigeon',
		'financials' => array( 'payer' => 'SENDER', 'cod_amount' => 120.0, 'cod_currency' => 'BGN' ),
	),
	'a third-party courier that does not implement the hook'               => array(
		'id'         => 'legacy_addon',
		'financials' => null,
	),
);

echo "--- Acceptance criterion 2: both create paths write an identical snapshot ---\n";

foreach ( $profiles as $label => $profile ) {
	foreach ( array( 'COD' => 'cod', 'prepaid' => 'bacs' ) as $mode => $payment_method ) {
		$meta = array(
			'_bgcs3_selection' => array( 'courier' => $profile['id'], 'delivery_type' => 'office' ),
			'_bgcs3_wb'        => array( 'weight' => '2.5', 'package_type' => 'BOX' ),
			'_bgcs_payment'    => $payment_method,
		);

		$courier = ( null === $profile['financials'] && 'legacy_addon' === $profile['id'] )
			? new Hookless_Courier()
			: new Snapshot_Courier( $profile['id'], $profile['financials'] );

		$from_panel = create_and_capture( 'metabox', $courier, $meta );
		$from_list  = create_and_capture( 'column', $courier, $meta );

		$identical = ( $from_panel === $from_list );
		check_snapshot( $identical, "{$label} ({$mode})" );

		if ( ! $identical ) {
			foreach ( array_keys( $from_panel + $from_list ) as $field ) {
				$a = isset( $from_panel[ $field ] ) ? $from_panel[ $field ] : null;
				$b = isset( $from_list[ $field ] ) ? $from_list[ $field ] : null;
				if ( $a !== $b ) {
					echo '        ' . $field . ': panel=' . var_export( $a, true ) . '  list=' . var_export( $b, true ) . PHP_EOL;
				}
			}
		}
	}
}

echo "--- The courier hook reaches BOTH paths, not just the order panel ---\n";
// The pre-fix quick-create path ignored the hook entirely and derived the payer
// from Speedy's `service_payer` for every courier.
$meta = array(
	'_bgcs3_selection' => array( 'courier' => 'econt', 'delivery_type' => 'office' ),
	'_bgcs3_wb'        => array(),
);
$courier = new Snapshot_Courier( 'econt', array( 'payer' => 'SENDER', 'cod_amount' => 0.0 ) );

foreach ( array( 'metabox' => 'order panel', 'column' => 'orders list' ) as $which => $name ) {
	$label = create_and_capture( $which, $courier, $meta );
	check_snapshot( 'SENDER' === $label['payer'], "Econt records SENDER from the {$name} (was RECIPIENT from the list)" );
	check_snapshot( 0.0 === $label['cod_amount'], "…and the courier-corrected COD amount from the {$name}" );
	check_snapshot( false === $label['is_cod'], "…so is_cod follows the corrected amount, which COD Reports reads" );
}

echo "--- A courier with no payer concept records no payer ---\n";
$meta    = array(
	'_bgcs3_selection' => array( 'courier' => 'boxnow', 'delivery_type' => 'locker' ),
	// A stale payer left over from a courier switch must not survive.
	'_bgcs3_wb'        => array( 'payer' => 'RECIPIENT' ),
);
$courier = new Snapshot_Courier( 'boxnow', array( 'payer' => '', 'cod_amount' => 120.0 ) );
$label   = create_and_capture( 'metabox', $courier, $meta );
check_snapshot( '' === $label['payer'], 'BOX NOW clears a stale order-level payer instead of recording one it never sent' );

echo "--- An unknown payer is recorded as unknown, never as an assumed value ---\n";
$meta    = array( '_bgcs3_selection' => array( 'courier' => 'legacy_addon' ), '_bgcs3_wb' => array() );
$label   = create_and_capture( 'metabox', new Hookless_Courier(), $meta );
check_snapshot( '' === $label['payer'], 'A courier that says nothing leaves the payer empty (BGCS-AUDIT-004 remediation 2)' );

$meta['_bgcs3_wb'] = array( 'payer' => 'THIRD_PARTY' );
$label             = create_and_capture( 'metabox', new Hookless_Courier(), $meta );
check_snapshot( 'THIRD_PARTY' === $label['payer'], 'but an explicit order-level payer is still honoured' );

echo "--- Label_Snapshot::apply() unit behaviour ---\n";

/** Builds a result + order pair for direct unit calls. */
function snapshot_fixture( array $meta ) {
	Fake_Order_Store::seed( SNAPSHOT_ORDER_ID, $meta );
	$result          = new Label_Result();
	$result->success = true;
	$result->number  = 'N1';
	$result->pdf_url = 'https://example.test/x.pdf';
	$result->meta    = array( 'courier_request_id' => 'keep-me' );
	return array( $result, new WC_Order( SNAPSHOT_ORDER_ID ) );
}

list( $result, $order ) = snapshot_fixture( array( '_bgcs3_selection' => array( 'courier' => 'speedy' ), '_bgcs3_wb' => array() ) );
Label_Snapshot::apply( $result, $order, null );
check_snapshot( 'N1' === $result->number && 'https://example.test/x.pdf' === $result->pdf_url, 'Courier-owned fields survive — the snapshot fills in, it does not rebuild' );
check_snapshot( 'keep-me' === $result->meta['courier_request_id'], 'Courier meta survives alongside the added shipment_reference' );
check_snapshot( 'keep-me' === $order->get_meta( Pickup_Request::META_KEY )['id'], 'Inline provider pickup request is associated with the order' );
check_snapshot( Shipment_Reference::for_order( $order ) === $order->get_meta( Pickup_Request::META_KEY )['shipment_reference'], 'Inline pickup association keeps the stable shipment reference' );
check_snapshot( isset( $result->meta['shipment_reference'] ), 'The stable shipment reference is recorded' );
check_snapshot( '' === $result->payer, 'With no courier at all the payer stays empty' );

list( $result, $order ) = snapshot_fixture( array( '_bgcs3_selection' => array( 'courier' => 'speedy' ), '_bgcs3_wb' => array() ) );
Label_Snapshot::apply( $result, $order, new Snapshot_Courier( 'speedy', 'not-an-array' ) );
check_snapshot( '' === $result->payer, 'A hook returning something that is not an array is ignored safely' );

list( $result, $order ) = snapshot_fixture( array( '_bgcs3_selection' => array( 'courier' => 'speedy' ), '_bgcs3_wb' => array( 'payer' => 'SENDER' ) ) );
Label_Snapshot::apply( $result, $order, new Snapshot_Courier( 'speedy', array( 'cod_amount' => 42.0 ) ) );
check_snapshot( 'SENDER' === $result->payer, 'A hook that states only cod_amount leaves the order-level payer alone' );
check_snapshot( 42.0 === $result->cod_amount, '…and its cod_amount is applied' );
check_snapshot( true === $result->is_cod, '…with is_cod derived from the corrected amount' );

list( $result, $order ) = snapshot_fixture( array( '_bgcs3_selection' => array( 'courier' => 'speedy' ), '_bgcs3_wb' => array() ) );
Label_Snapshot::apply( $result, $order, new Snapshot_Courier( 'speedy', array( 'cod_amount' => -5.0, 'cod_currency' => 'eur' ) ) );
check_snapshot( 0.0 === $result->cod_amount, 'A negative corrected amount is clamped to zero' );
check_snapshot( 'EUR' === $result->cod_currency, 'The corrected currency is upper-cased' );

echo "--- Static guards ---\n";
$root = dirname( __DIR__ );

$builders = array();
$rii      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/app' ) );
foreach ( $rii as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	if ( false !== strpos( php_strip_whitespace( $file->getPathname() ), 'function populate_label_snapshot' ) ) {
		$builders[] = basename( $file->getPathname() );
	}
}
check_snapshot( array() === $builders, 'Acceptance criterion 1: no duplicated snapshot builder remains' . ( $builders ? ': ' . implode( ', ', $builders ) : '' ) );

$metabox_code = php_strip_whitespace( $root . '/app/Admin/Order/MetaBox.php' );
$column_code  = php_strip_whitespace( $root . '/app/Admin/Order/Orders_Column.php' );
check_snapshot( false !== strpos( $metabox_code, 'Label_Snapshot::apply(' ), 'The order panel delegates to Label_Snapshot' );
check_snapshot( false !== strpos( $column_code, 'Label_Snapshot::apply(' ), 'The orders list delegates to Label_Snapshot' );
check_snapshot( false === strpos( $metabox_code, 'effective_payer' ), 'MetaBox::effective_payer() is gone — Core no longer guesses the payer' );

echo "--- Acceptance criterion 3: every courier states its own payment semantics ---\n";
foreach ( array( 'Speedy/Speedy', 'Econt/Econt', 'BoxNow/BoxNow', 'Pigeon/Pigeon' ) as $module ) {
	$code = php_strip_whitespace( $root . '/app/Modules/Shipping/' . $module . '.php' );
	check_snapshot(
		false !== strpos( $code, 'function label_snapshot_financials' ),
		basename( $module ) . ' implements label_snapshot_financials()'
	);
}
$base = php_strip_whitespace( $root . '/app/Modules/Shipping/Abstract_Courier.php' );
check_snapshot( false !== strpos( $base, 'function label_snapshot_financials' ), 'Abstract_Courier documents the contract and provides the no-op default' );

echo "--- Acceptance criterion 4: Core reads no courier setting directly ---\n";
$admin_reads = array();
$rii         = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/app/Admin' ) );
foreach ( $rii as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$rel = str_replace( array( $root . '/', $root . '\\', '\\' ), array( '', '', '/' ), $file->getPathname() );
	foreach ( file( $file->getPathname() ) as $index => $line ) {
		// A settings READ against a courier group. The settings screen's own
		// card layout names fields as data, which is presentation, not a read.
		if ( preg_match( "/(?:bgcs3_get_option|Module_Settings::get)\(\s*\\\$courier_id\s*,\s*'(service_payer|payment_side|payment_type|who_pays|cd_enabled)'/", $line ) ) {
			$admin_reads[] = $rel . ':' . ( $index + 1 ) . '  ' . trim( $line );
		}
	}
}
check_snapshot( array() === $admin_reads, 'No courier payment setting is read from app/Admin/' . ( $admin_reads ? ":\n      " . implode( "\n      ", $admin_reads ) : '' ) );

echo "--- BGCS-AUDIT-004: payment_side stays undeclared ---\n";
// The general ghost-key rule — every settings key read is one the module
// declares or writes — is guard 2 in tests/test-settings-guards.php (TASK-K1).
// What matters here is that the specific key this finding is about has not been
// quietly legitimised by adding a field for it, which the audit warned against.
$declared_keys = bgcs_declared_keys( $root );
check_snapshot( ! in_array( 'payment_side', $declared_keys['econt'], true ), 'payment_side is still not an Econt setting' );
check_snapshot( ! in_array( 'payment_side', bgcs_keys_written_by( $root, 'app/Modules/Shipping/Econt' ), true ), 'and nothing writes it either' );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all label snapshot checks passed' . PHP_EOL;
