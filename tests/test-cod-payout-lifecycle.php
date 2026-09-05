<?php
/**
 * Phase 8 COD payout receipt and reconciliation contract.
 *
 * Run: php tests/test-cod-payout-lifecycle.php
 */

namespace BgCommerce3\Modules\Shipping {
	interface Courier_Interface {}

	class Payout_Test_Courier implements Courier_Interface {
		private $supported;
		public function __construct( $supported ) {
			$this->supported = (bool) $supported;
		}
		public function supports_cod_payouts() {
			return $this->supported;
		}
		public function cod_payouts( $from, $to ) {
			return array();
		}
	}

	class No_Payout_Test_Courier implements Courier_Interface {}
}

namespace BgCommerce3\Support {
	class Module_Settings {}
}

namespace BgCommerce3\Shipping {
	class Cod {
		public static function is_order( $order ) {
			return 'cod' === $order->get_payment_method();
		}
		public static function amount( $order ) {
			return (float) $order->get_total();
		}
		public static function methods() {
			return array( 'cod' );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
	function __( $text, $domain = null ) {
		return $text;
	}

	class WP_Error {
		public function __construct( $code = '', $message = '' ) {}
	}

	class WC_Order {
		private $meta;
		public $notes = array();
		public function __construct( array $meta ) {
			$this->meta = $meta;
		}
		public function get_id() {
			return 9001;
		}
		public function get_payment_method() {
			return 'cod';
		}
		public function get_total() {
			return 100.0;
		}
		public function get_currency() {
			return 'BGN';
		}
		public function get_meta( $key ) {
			return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
		}
		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}
		public function delete_meta_data( $key ) {
			unset( $this->meta[ $key ] );
		}
		public function add_order_note( $note ) {
			$this->notes[] = (string) $note;
		}
		public function save() {}
	}

	require_once dirname( __DIR__ ) . '/app/Shipping/Cod_Payout.php';
	require_once dirname( __DIR__ ) . '/app/Shipping/Cod_Payout_Sync_Settings.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Shipping/Speedy/Payouts.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Shipping/Econt/Payouts.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Shipping/Pigeon/Payouts.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Accounting/CodReports/Report_Repository.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Accounting/CodReports/Report_Importer.php';

	use BgCommerce3\Modules\Shipping\Econt\Payouts as Econt_Payouts;
	use BgCommerce3\Modules\Shipping\No_Payout_Test_Courier;
	use BgCommerce3\Modules\Shipping\Payout_Test_Courier;
	use BgCommerce3\Modules\Shipping\Pigeon\Payouts as Pigeon_Payouts;
	use BgCommerce3\Modules\Shipping\Speedy\Payouts as Speedy_Payouts;
	use BgCommerce3\Modules\Accounting\CodReports\Report_Importer;
	use BgCommerce3\Modules\Accounting\CodReports\Report_Repository;
	use BgCommerce3\Shipping\Cod_Payout;
	use BgCommerce3\Shipping\Cod_Payout_Sync_Settings;

	$failures = 0;
	function check_payout( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failures++;
		}
	}

	function payout_order() {
		return new WC_Order(
			array(
				'_bgcs3_selection' => array( 'courier' => 'speedy' ),
				'_bgcs3_label'     => array(
					'number'       => '637 247 949 98',
					'is_cod'       => true,
					'cod_amount'   => 100.0,
					'cod_currency' => 'BGN',
					'meta'         => array( 'shipment_reference' => 'solobyte-9001-1' ),
				),
			)
		);
	}

	class Payout_Test_Repository extends Report_Repository {
		private $order;
		public function __construct( $order ) {
			$this->order = $order;
		}
		public function waybill_map() {
			return array( '63724794998' => $this->order );
		}
		public function is_cod_order( $order ) {
			return $order instanceof WC_Order;
		}
	}

	$exact = array(
		'waybill'            => '63724794998',
		'amount'             => '100.00',
		'currency'           => 'BGN',
		'courier'            => 'speedy',
		'paid_date'          => '2026-08-31',
		'fee'                => '2.50',
		'net'                => '97.50',
		'report_reference'   => '551:7',
		'shipment_reference' => 'solobyte-9001-1',
		'status'             => 'paid',
	);

	echo "--- Canonical payout receipt ---\n";
	$order = payout_order();
	check_payout( 'updated' === Cod_Payout::apply_row( $order, $exact, 'background_api' ), 'An exact payout updates the order' );
	check_payout( 'yes' === $order->get_meta( Cod_Payout::META_PAID ), 'Paid status is persisted' );
	check_payout( 100.0 === $order->get_meta( Cod_Payout::META_EXPECTED_AMOUNT ), 'Expected COD is persisted from the shipment snapshot' );
	check_payout( 100.0 === $order->get_meta( Cod_Payout::META_AMOUNT ), 'Reported paid COD is persisted' );
	check_payout( 2.5 === $order->get_meta( Cod_Payout::META_FEE ), 'Provider fee is persisted when supplied' );
	check_payout( 97.5 === $order->get_meta( Cod_Payout::META_NET ), 'Net payout is persisted' );
	check_payout( 0.0 === $order->get_meta( Cod_Payout::META_DIFFERENCE ), 'Expected-versus-paid difference is persisted' );
	check_payout( '2026-08-31' === $order->get_meta( Cod_Payout::META_PAID_DATE ), 'Provider payout date is persisted' );
	check_payout( '551:7' === $order->get_meta( Cod_Payout::META_REPORT_REF ), 'Provider report reference is persisted' );
	check_payout( 'background_api' === $order->get_meta( Cod_Payout::META_SOURCE ), 'Acquisition source is persisted' );
	check_payout( 64 === strlen( $order->get_meta( Cod_Payout::META_FINGERPRINT ) ), 'A PII-free idempotency fingerprint is persisted' );
	check_payout( 1 === count( $order->notes ), 'The first reconciliation writes one order note' );

	echo "--- Idempotency and conflicting second receipt ---\n";
	check_payout( 'already_paid' === Cod_Payout::apply_row( $order, $exact, 'background_api' ), 'Repeating the same receipt is idempotent' );
	check_payout( 1 === count( $order->notes ), 'Repeating the same receipt writes no duplicate note' );
	$conflicting = $exact;
	$conflicting['report_reference'] = '552:1';
	check_payout( 'mismatch' === Cod_Payout::apply_row( $order, $conflicting, 'background_api' ), 'A different payout identity cannot overwrite the paid receipt' );
	check_payout( '551:7' === $order->get_meta( Cod_Payout::META_REPORT_REF ), 'The original report reference remains immutable' );
	check_payout( in_array( 'payout_identity_conflict', $order->get_meta( Cod_Payout::META_MISMATCH )['reasons'], true ), 'The conflict is actionable' );

	echo "--- Accounting mismatch ---\n";
	$mismatch_order = payout_order();
	$mismatch = $exact;
	$mismatch['amount'] = '90.00';
	check_payout( 'mismatch' === Cod_Payout::apply_row( $mismatch_order, $mismatch, 'manual_api' ), 'A different amount is not marked paid' );
	check_payout( '' === $mismatch_order->get_meta( Cod_Payout::META_PAID ), 'Mismatch preserves unpaid state' );
	check_payout( -10.0 === $mismatch_order->get_meta( Cod_Payout::META_MISMATCH )['difference'], 'Mismatch stores the exact difference' );
	check_payout( 'requires_review' === $mismatch_order->get_meta( Cod_Payout::META_STATUS ), 'Mismatch stores an actionable status' );
	$bad_net = $exact;
	$bad_net['net'] = '99.00';
	check_payout( in_array( 'net_mismatch', Cod_Payout::validate_values( Cod_Payout::expected( payout_order() ), $bad_net ), true ), 'Gross minus fee must equal net payout when all three are supplied' );
	$not_paid = $exact;
	$not_paid['status'] = 'pending';
	check_payout( in_array( 'invalid_payout_status', Cod_Payout::validate_values( Cod_Payout::expected( payout_order() ), $not_paid ), true ), 'A non-paid provider row cannot mark COD as paid' );

	echo "--- Provider row provenance ---\n";
	$speedy_rows = Speedy_Payouts::rows(
		array( 'payouts' => array( array( 'date' => '2026-08-31', 'docId' => 551, 'currency' => 'BGN', 'details' => array( array( 'lineNo' => 7, 'shipmentId' => '63724794998', 'amount' => 100, 'ref1' => 'solobyte-9001-1' ) ) ) ) )
	);
	check_payout( '551:7' === $speedy_rows[0]['report_reference'], 'Speedy uses documented docId and lineNo as report reference' );
	check_payout( 'solobyte-9001-1' === $speedy_rows[0]['shipment_reference'], 'Speedy preserves ref1 for identity cross-check' );

	$econt_rows = Econt_Payouts::rows( array( 'num' => 1051604245815, 'amount' => 100, 'currency' => 'BGN', 'payDate' => '2026-08-31', 'createdTime' => '2026-08-31T09:00:00' ) );
	check_payout( 'paid' === $econt_rows[0]['status'], 'Econt PaymentReport rows are explicitly paid' );
	check_payout( '' !== $econt_rows[0]['report_reference'], 'Econt rows receive a stable report reference from documented fields' );

	$pigeon_rows = Pigeon_Payouts::rows( array( 'data' => array( 'rows' => array( array( 'waybill' => 'PG1', 'external_reference' => 'solobyte-9001-1', 'amount_eur' => '100.00', 'paid_date' => '31-08-2026' ) ) ) ) );
	check_payout( 'EUR' === $pigeon_rows[0]['currency'], 'Pigeon payout currency remains explicit EUR' );
	check_payout( 'solobyte-9001-1' === $pigeon_rows[0]['shipment_reference'], 'Pigeon external reference participates in identity checking' );

	echo "--- Capability visibility ---\n";
	check_payout( Cod_Payout_Sync_Settings::supports( new Payout_Test_Courier( true ) ), 'A courier with an enabled payout API exposes payout controls' );
	check_payout( ! Cod_Payout_Sync_Settings::supports( new Payout_Test_Courier( false ) ), 'A courier can explicitly disable an unavailable payout API' );
	check_payout( ! Cod_Payout_Sync_Settings::supports( new No_Payout_Test_Courier() ), 'A courier without payout methods exposes no payout controls' );

	echo "--- Preview and confirmed import use the canonical receipt ---\n";
	$import_order = payout_order();
	$importer = new Report_Importer( new Payout_Test_Repository( $import_order ) );
	$preview = $importer->preview_courier_payouts( array( $exact ) );
	check_payout( 1 === count( $preview['matches'] ), 'An exact courier API row is ready to reconcile' );
	check_payout( '2026-08-31' === $preview['matches'][0]['paid_date'], 'Preview preserves the row payout date' );
	check_payout( '551:7' === $preview['matches'][0]['report_reference'], 'Preview preserves the report reference' );
	$applied = $importer->apply_preview(
		$preview,
		static function () use ( $import_order ) {
			return $import_order;
		},
		'2026-09-01'
	);
	check_payout( 1 === $applied['updated'], 'Confirmed preview applies through the canonical reconciler' );
	check_payout( 'manual_api' === $import_order->get_meta( Cod_Payout::META_SOURCE ), 'Confirmed API preview records its source' );
	check_payout( '2026-08-31' === $import_order->get_meta( Cod_Payout::META_PAID_DATE ), 'Per-row provider date wins over the confirmation date' );
	check_payout( 97.5 === $import_order->get_meta( Cod_Payout::META_NET ), 'Confirmed preview does not lose net payout' );
	$repeat_preview = $importer->preview_courier_payouts( array( $exact ) );
	check_payout( 1 === $repeat_preview['already_paid'] && empty( $repeat_preview['matches'] ), 'Repeated report import offers no duplicate payout' );

	$unsafe_order = payout_order();
	$unsafe_importer = new Report_Importer( new Payout_Test_Repository( $unsafe_order ) );
	$unsafe = $unsafe_importer->preview_rows(
		array( array( 'waybill' => '63724794998' ) ),
		array( 'waybill' => 'waybill' )
	);
	check_payout( 1 === count( $unsafe['conflicts'] ), 'A waybill-only file is not enough accounting evidence' );
	check_payout( in_array( 'invalid_amount', $unsafe['conflicts'][0]['conflict_reasons'], true ), 'Missing paid amount is actionable' );
	check_payout( in_array( 'invalid_currency', $unsafe['conflicts'][0]['conflict_reasons'], true ), 'Missing currency is actionable' );
	check_payout( in_array( 'invalid_courier', $unsafe['conflicts'][0]['conflict_reasons'], true ), 'Missing courier is actionable' );
	$duplicate_conflict_order = payout_order();
	$duplicate_importer = new Report_Importer( new Payout_Test_Repository( $duplicate_conflict_order ) );
	$different = $exact;
	$different['amount'] = '90.00';
	$different['report_reference'] = '552:1';
	$duplicate_preview = $duplicate_importer->preview_courier_payouts( array( $exact, $different ) );
	check_payout( 1 === count( $duplicate_preview['matches'] ), 'The first exact row remains eligible' );
	check_payout( 1 === count( $duplicate_preview['conflicts'] ), 'A different second payout for the same order is surfaced' );
	check_payout( in_array( 'duplicate_conflict', $duplicate_preview['conflicts'][0]['conflict_reasons'], true ), 'Conflicting duplicate is not silently counted as an ordinary retry' );
	$missing_date = $exact;
	$missing_date['paid_date'] = '';
	$date_preview = $duplicate_importer->preview_courier_payouts( array( $missing_date ) );
	check_payout( empty( $date_preview['matches'] ) && in_array( 'invalid_paid_date', $date_preview['conflicts'][0]['conflict_reasons'], true ), 'Courier API rows require a provider payout date' );
	check_payout( Cod_Payout::row_fingerprint( $exact ) === Cod_Payout::row_fingerprint( array_merge( $exact, array( 'amount' => 100 ) ) ), 'Equivalent numeric formatting has one report-row identity' );

	echo "--- Manual receipt lifecycle ---\n";
	$manual = payout_order();
	check_payout( 'updated' === Cod_Payout::mark_manually( $manual, '2026-08-31' ), 'Manual paid action creates a canonical receipt' );
	check_payout( 'manual' === $manual->get_meta( Cod_Payout::META_SOURCE ), 'Manual assertion is clearly sourced' );
	check_payout( 'manual:9001:2026-08-31' === $manual->get_meta( Cod_Payout::META_REPORT_REF ), 'Manual assertion has a stable local reference' );
	check_payout( 1 === count( $manual->notes ) && false !== strpos( $manual->notes[0], 'manually' ), 'Manual action writes one truthful note' );
	Cod_Payout::reset_to_pending( $manual );
	check_payout( '' === $manual->get_meta( Cod_Payout::META_PAID ), 'Moving to pending clears the paid flag' );
	check_payout( '' === $manual->get_meta( Cod_Payout::META_FINGERPRINT ), 'Moving to pending clears the full receipt identity' );
	check_payout( '' === $manual->get_meta( Cod_Payout::META_REPORT_REF ), 'Moving to pending clears the report reference' );
	check_payout( 2 === count( $manual->notes ), 'Moving to pending writes one audit note' );

	echo "--- Static integration guards ---\n";
	$root = dirname( __DIR__ );
	$meta_box = php_strip_whitespace( $root . '/app/Admin/Order/MetaBox.php' );
	foreach ( array( 'Expected COD', 'Paid COD', 'Difference', 'Paid date', 'Payout source', 'Report reference', 'Net payout' ) as $label ) {
		check_payout( false !== strpos( $meta_box, $label ), "Order admin exposes {$label}" );
	}
	$importer_source = php_strip_whitespace( $root . '/app/Modules/Accounting/CodReports/Report_Importer.php' );
	check_payout( false !== strpos( $importer_source, 'Cod_Payout::apply_row' ), 'Confirmed previews use the canonical reconciler' );
	check_payout( false === strpos( $importer_source, "update_meta_data( Report_Repository::META_PAID" ), 'Importer has no legacy paid-only write path' );
	$controller_source = php_strip_whitespace( $root . '/app/Modules/Accounting/CodReports/Report_Controller.php' );
	check_payout( false !== strpos( $controller_source, 'Cod_Payout::mark_manually' ), 'Manual paid action uses the canonical receipt' );
	check_payout( false !== strpos( $controller_source, 'Cod_Payout::reset_to_pending' ), 'Manual pending action clears the canonical receipt' );
	foreach ( array( 'Speedy/Speedy.php', 'Econt/Econt.php', 'Pigeon/Pigeon.php' ) as $provider_file ) {
		$provider_source = php_strip_whitespace( $root . '/app/Modules/Shipping/' . $provider_file );
		check_payout( false !== strpos( $provider_source, 'supports_cod_payouts' ), $provider_file . ' declares payout capability' );
	}
	$box_source = php_strip_whitespace( $root . '/app/Modules/Shipping/BoxNow/BoxNow.php' );
	check_payout( false === strpos( $box_source, 'supports_cod_payouts' ), 'BOX NOW does not expose unsupported payout controls' );

	echo PHP_EOL;
	if ( $failures ) {
		echo "FAILED: {$failures} check(s)" . PHP_EOL;
		exit( 1 );
	}
	echo 'OK - all Phase 8 COD payout lifecycle checks passed' . PHP_EOL;
}
