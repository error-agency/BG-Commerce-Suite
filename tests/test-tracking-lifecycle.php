<?php
/**
 * Phase 7 canonical tracking lifecycle contract.
 *
 * Run: php tests/test-tracking-lifecycle.php
 */

namespace BgCommerce3\Modules\Shipping {
	abstract class Abstract_Courier {}
	interface Courier_Interface {}
	class Tracking_Test_Courier implements Courier_Interface {
		public function id() {
			return 'speedy';
		}
	}
}

namespace BgCommerce3\Support {
	class Selection {}
	class Price_Result {}
	class Label_Result {}
	class Tracking_Result {}
	class Shipment_Diagnostics {}
	class Cache {}
	class Sync_Result {}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	function __( $text, $domain = null ) {
		return $text;
	}
	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
	}
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
	function wc_get_order_statuses() {
		return array(
			'wc-processing'       => 'Processing',
			'wc-ready-for-export' => 'Ready for export',
		);
	}

	require_once dirname( __DIR__ ) . '/app/Shipping/Tracking_State.php';
	require_once dirname( __DIR__ ) . '/app/Shipping/Tracking_Store.php';
	require_once dirname( __DIR__ ) . '/app/Shipping/Tracking_Status_Catalog.php';
	require_once dirname( __DIR__ ) . '/app/Shipping/Tracking_Status_Policy.php';
	require_once dirname( __DIR__ ) . '/app/Shipping/Pricing.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Shipping/Speedy/Speedy.php';

	use BgCommerce3\Modules\Shipping\Tracking_Test_Courier;
	use BgCommerce3\Shipping\Pricing;
	use BgCommerce3\Modules\Shipping\Speedy\Speedy;
	use BgCommerce3\Shipping\Tracking_State;
	use BgCommerce3\Shipping\Tracking_Status_Policy;
	use BgCommerce3\Shipping\Tracking_Store;

	$failures = 0;
	function check_tracking( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failures++;
		}
	}

	echo "--- Acquisition source contract ---\n";
	$polling = Tracking_Store::with_source(
		array(
			array( 'time' => '2026-08-31T10:00:00+0300', 'code' => '148', 'text' => 'Created' ),
			array( 'time' => '2026-08-31T11:00:00+0300', 'code' => '39', 'text' => 'Accepted', 'source' => 'webhook' ),
		),
		'polling'
	);
	check_tracking( 'polling' === $polling[0]['source'], 'Polling events record their acquisition source' );
	check_tracking( 'webhook' === $polling[1]['source'], 'An existing verified webhook source is preserved' );
	check_tracking( 'cron' === Tracking_Store::with_source( array( array( 'code' => '1' ) ), 'cron' )[0]['source'], 'Scheduled synchronization is distinct from direct polling' );
	check_tracking( 'polling' === Tracking_Store::with_source( array( array( 'code' => '1' ) ), 'invalid' )[0]['source'], 'Unknown source names fail closed to polling' );

	echo "--- Raw status and event ordering contract ---\n";
	$events = array(
		array( 'time' => '2026-08-31T12:00:00+0300', 'code' => '-14', 'text' => 'Delivered' ),
		array( 'time' => '2026-08-31T09:00:00+0300', 'code' => '148', 'text' => 'Created' ),
	);
	check_tracking( '-14' === Tracking_Store::latest_raw_status( $events ), 'Latest raw status comes from occurred-at time, not array order' );
	check_tracking( '' === Tracking_Store::latest_raw_status( array( array( 'time' => '2026-08-31T12:00:00+0300' ) ) ), 'Missing provider code remains explicitly empty' );
	check_tracking( Tracking_State::DELIVERED === Tracking_Status_Policy::latest_state( new Speedy(), $events ), 'An older event cannot move the canonical state backwards' );

	$merged_once = Tracking_Store::merge( array(), $events );
	$merged_twice = Tracking_Store::merge( $merged_once, $events );
	check_tracking( 2 === count( $merged_twice ), 'Repeated full provider history remains deduplicated' );
	check_tracking( $merged_once === $merged_twice, 'Repeated merge is idempotent' );
	$enriched = Tracking_Store::merge(
		array( array( 'time' => '2026-08-31T09:00:00+0300', 'code' => '148', 'text' => 'Created' ) ),
		array( array( 'time' => '2026-08-31T09:00:00+0300', 'code' => '148', 'text' => 'Created', 'source' => 'cron' ) )
	);
	check_tracking( 1 === count( $enriched ), 'A legacy event is still not duplicated when provenance arrives later' );
	check_tracking( 'cron' === $enriched[0]['source'], 'A legacy duplicate is enriched with its verified acquisition source' );

	echo "--- Speedy official operation codes ---\n";
	$speedy = new Speedy();
	$expected = array(
		-14  => Tracking_State::DELIVERED,
		148  => Tracking_State::CREATED,
		39   => Tracking_State::ACCEPTED,
		1    => Tracking_State::IN_TRANSIT,
		2    => Tracking_State::IN_TRANSIT,
		11   => Tracking_State::IN_TRANSIT,
		21   => Tracking_State::IN_TRANSIT,
		38   => Tracking_State::IN_TRANSIT,
		116  => Tracking_State::IN_TRANSIT,
		144  => Tracking_State::IN_TRANSIT,
		152  => Tracking_State::IN_TRANSIT,
		175  => Tracking_State::IN_TRANSIT,
		176  => Tracking_State::IN_TRANSIT,
		217  => Tracking_State::IN_TRANSIT,
		134  => Tracking_State::AVAILABLE_FOR_PICKUP,
		1134 => Tracking_State::AVAILABLE_FOR_PICKUP,
		12   => Tracking_State::OUT_FOR_DELIVERY,
		44   => Tracking_State::DELIVERY_FAILED,
		115  => Tracking_State::REDIRECTED,
		111  => Tracking_State::RETURN_IN_PROGRESS,
		121  => Tracking_State::RETURN_IN_PROGRESS,
		123  => Tracking_State::RETURN_IN_PROGRESS,
		124  => Tracking_State::RETURNED,
		128  => Tracking_State::CANCELLED,
		69   => Tracking_State::EXCEPTION,
		112  => Tracking_State::EXCEPTION,
		114  => Tracking_State::EXCEPTION,
		125  => Tracking_State::EXCEPTION,
		127  => Tracking_State::EXCEPTION,
		129  => Tracking_State::EXCEPTION,
		136  => Tracking_State::EXCEPTION,
		164  => Tracking_State::EXCEPTION,
		169  => Tracking_State::EXCEPTION,
		181  => Tracking_State::EXCEPTION,
		190  => Tracking_State::EXCEPTION,
		195  => Tracking_State::EXCEPTION,
	);
	foreach ( $expected as $code => $state ) {
		check_tracking( $state === $speedy->normalize_status( array( 'code' => (string) $code ) ), "Speedy {$code} maps to {$state}" );
	}
	check_tracking( Tracking_State::UNKNOWN === $speedy->normalize_status( array( 'code' => '999999' ) ), 'Undocumented Speedy codes remain unknown' );

	echo "--- Custom WooCommerce status discovery ---\n";
	$policy_fields = Tracking_Status_Policy::fields_for( new Tracking_Test_Courier() );
	check_tracking( 'Ready for export' === $policy_fields['wc_status_delivered']['options']['ready-for-export'], 'Tracking mappings include registered custom order statuses' );
	$status_options = new \ReflectionMethod( Pricing::class, 'order_status_options' );
	$status_options->setAccessible( true );
	$label_options = $status_options->invoke( null );
	check_tracking( 'Ready for export' === $label_options['ready-for-export'], 'Post-label automation includes registered custom order statuses' );

	echo "--- Static persistence guards ---\n";
	$root = dirname( __DIR__ );
	$auto = php_strip_whitespace( $root . '/app/Background/Auto_Status.php' );
	$meta = php_strip_whitespace( $root . '/app/Admin/Order/MetaBox.php' );
	$box  = php_strip_whitespace( $root . '/app/Modules/Shipping/BoxNow/BoxNow.php' );
	foreach ( array( "['raw_status']", "['normalized_status']", "['source']" ) as $field ) {
		check_tracking( false !== strpos( $auto, $field ), "Background tracking persists {$field}" );
		check_tracking( false !== strpos( $meta, $field ), "Manual tracking persists {$field}" );
	}
	check_tracking( false !== strpos( $box, "'webhook_refresh'" ), 'BOX NOW enrichment identifies its webhook refresh source' );
	check_tracking( false !== strpos( $auto, "'source' => 'cron'" ), 'Single-order Action Scheduler jobs identify the cron source' );
	check_tracking( false !== strpos( $auto, "\$results[ \$number ], 'cron'" ), 'Bulk Action Scheduler jobs identify the cron source' );

	echo PHP_EOL;
	if ( $failures > 0 ) {
		echo "FAILED: {$failures} check(s)" . PHP_EOL;
		exit( 1 );
	}
	echo 'OK - all Phase 7 tracking lifecycle checks passed' . PHP_EOL;
}
