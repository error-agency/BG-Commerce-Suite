<?php
/**
 * TASK-F1 — BGCS-AUDIT-002: three Econt settings with no runtime effect.
 *
 * The decision taken, per the audit's own recommendation:
 *
 *   local_storage       IMPLEMENTED, in Core, as `checkout.remember_selection`.
 *                       It carries a real privacy meaning — a merchant who
 *                       switches off remembering the customer's last address
 *                       was still getting it written to localStorage.
 *   shipping_to_style   REMOVED. It promised a checkout control that does not
 *   hide_quarter_fields exist. Stored values are left untouched for rollback.
 *
 * Implementing it in Core rather than in Econt is deliberate: browser
 * persistence of the delivery selection is not courier-specific, and a
 * per-courier copy is exactly the Core/module duplication the architecture
 * forbids.
 *
 * Run: php tests/test-checkout-remember-selection.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['bgcs_options'] = array();

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
function apply_filters( $hook, $value = null ) {
	return $value;
}
function add_action() {}
function add_filter() {}

require_once dirname( __DIR__ ) . '/app/Support/Options.php';

use BgCommerce3\Support\Options;

if ( ! function_exists( 'bgcs3_get_option' ) ) {
	function bgcs3_get_option( $group, $key = null, $default = null ) {
		return Options::get( $group, $key, $default );
	}
}

$failures = 0;
function check_f1( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

$root = dirname( __DIR__ );

// ---------------------------------------------------------------------------
// The two removed fields
// ---------------------------------------------------------------------------

echo "--- Acceptance criterion 3: the removed fields are rendered nowhere ---\n";

require_once __DIR__ . '/lib/settings-scanner.php';
$fields = bgcs_declared_fields( $root );

foreach ( array( 'shipping_to_style', 'hide_quarter_fields', 'local_storage' ) as $key ) {
	check_f1( ! isset( $fields['econt'][ $key ] ), "econt.{$key} is no longer a declared field" );
}

// A field can be gone from the definition and still be listed in one of the two
// places that group fields into cards, which would render an empty slot.
foreach ( array(
	'app/Modules/Shipping/Econt/Econt.php'  => 'the module settings sections',
	'app/Admin/Settings/Settings_Page.php' => 'the courier workspace field map',
) as $file => $what ) {
	$code = php_strip_whitespace( $root . '/' . $file );
	foreach ( array( 'shipping_to_style', 'hide_quarter_fields', 'local_storage' ) as $key ) {
		check_f1( false === strpos( $code, "'{$key}'" ), "{$key} is gone from {$what}" );
	}
}

echo "--- Stored values are left alone (rollback safety) ---\n";
// The audit is explicit: stop rendering them, do not delete what merchants saved.
$econt_code = php_strip_whitespace( $root . '/app/Modules/Shipping/Econt/Econt.php' );
check_f1( false === strpos( $econt_code, 'unset(' ) || false === strpos( $econt_code, 'local_storage' ), 'Nothing deletes the stored Econt values' );

// ---------------------------------------------------------------------------
// The implemented setting
// ---------------------------------------------------------------------------

echo "--- Acceptance criterion 2: the setting reaches the checkout ---\n";

$checkout_code = php_strip_whitespace( $root . '/app/Checkout/Checkout.php' );
check_f1(
	false !== strpos( $checkout_code, "'rememberSelection'" )
		&& false !== strpos( $checkout_code, "'remember_selection'" ),
	'Checkout::frontend_data() exposes the setting to the browser'
);

$settings_code = php_strip_whitespace( $root . '/app/Admin/Settings/Settings_Page.php' );
check_f1( false !== strpos( $settings_code, "checkout[remember_selection]" ), 'The Checkout tab renders the control' );
check_f1( false !== strpos( $settings_code, "Options::set( 'checkout', 'remember_selection'" ), 'and saves it' );

$js = file_get_contents( $root . '/assets/js/bgcs-checkout.js' );
check_f1(
	false === strpos( $js, "stateApi.createSelectionStore(\n\t\twindow.localStorage" ),
	'The checkout script no longer hands localStorage over unconditionally'
);
check_f1( false !== strpos( $js, 'rememberSelection' ), 'It reads the merchant setting instead' );

// ---------------------------------------------------------------------------
// The upgrade path
// ---------------------------------------------------------------------------

echo "--- A merchant's existing choice survives the move to Core ---\n";

require_once dirname( __DIR__ ) . '/app/Checkout/Checkout.php';

use BgCommerce3\Checkout\Checkout;

function reset_options( array $econt = array(), array $checkout = array() ) {
	$GLOBALS['bgcs_options'] = array();
	if ( $econt ) {
		$GLOBALS['bgcs_options']['bgcs3_econt'] = $econt;
	}
	if ( $checkout ) {
		$GLOBALS['bgcs_options']['bgcs3_checkout'] = $checkout;
	}
}

// The case the whole finding is about: a deliberate privacy choice that did
// nothing, and must not be silently reverted now that it works.
reset_options( array( 'local_storage' => 'no' ) );
check_f1( true === Checkout::migrate_remember_selection(), 'An old "no" is carried over' );
check_f1( 'no' === bgcs3_get_option( 'checkout', 'remember_selection' ), '…and lands as checkout.remember_selection = no' );

reset_options( array( 'local_storage' => 'yes' ) );
Checkout::migrate_remember_selection();
check_f1( 'yes' === bgcs3_get_option( 'checkout', 'remember_selection' ), 'An old "yes" is carried over too' );

echo "--- The migration is safe to run repeatedly ---\n";
reset_options( array( 'local_storage' => 'no' ) );
Checkout::migrate_remember_selection();
$GLOBALS['bgcs_options']['bgcs3_checkout']['remember_selection'] = 'yes';
check_f1( false === Checkout::migrate_remember_selection(), 'A second run reports it did nothing' );
check_f1( 'yes' === bgcs3_get_option( 'checkout', 'remember_selection' ), '…and does not overwrite a later merchant choice' );

reset_options( array(), array( 'remember_selection' => 'no' ) );
check_f1( false === Checkout::migrate_remember_selection(), 'An existing Core value is never replaced' );

echo "--- Nothing is invented when there is nothing to carry over ---\n";
reset_options();
check_f1( false === Checkout::migrate_remember_selection(), 'No Econt value: nothing is written' );
check_f1( ! array_key_exists( 'bgcs3_checkout', $GLOBALS['bgcs_options'] ), '…so the Core default applies untouched' );

foreach ( array( '', 'maybe', '1', 0 ) as $garbage ) {
	reset_options( array( 'local_storage' => $garbage ) );
	check_f1( false === Checkout::migrate_remember_selection(), 'A non yes/no value is ignored: ' . var_export( $garbage, true ) );
}

echo "--- The migration runs on the next version bump ---\n";
$plugin_code = php_strip_whitespace( $root . '/app/Plugin.php' );
check_f1(
	false !== strpos( $plugin_code, "version_compare( \$installed, '3.0.49', '<' )" ),
	'Plugin::maybe_upgrade_storage() gates it on 3.0.49, so it fires for every install at or below 3.0.48'
);
check_f1( false !== strpos( $plugin_code, 'Checkout::migrate_remember_selection()' ), 'and calls it' );

// ---------------------------------------------------------------------------
// The behaviour itself, in the real script
// ---------------------------------------------------------------------------

echo "--- The observable effect in the browser ---\n";

$probe = $root . '/tests/lib/checkout-storage-probe.js';
$node  = null;
foreach ( array( 'node', 'node.exe' ) as $candidate ) {
	$check = array();
	$code  = 0;
	@exec( escapeshellarg( $candidate ) . ' --version 2>&1', $check, $code );
	if ( 0 === $code ) {
		$node = $candidate;
		break;
	}
}

if ( null === $node ) {
	echo '  [SKIP] Node is not available — the localStorage behaviour was NOT verified here.' . PHP_EOL;
	echo '         Run it directly once Node is present: node tests/lib/checkout-storage-probe.js' . PHP_EOL;
} else {
	$output = array();
	$status = 0;
	exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $probe ) . ' 2>&1', $output, $status );
	foreach ( $output as $line ) {
		echo '  ' . $line . PHP_EOL;
	}
	check_f1( 0 === $status, 'The real checkout script honours the setting (tests/lib/checkout-storage-probe.js)' );
}

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all remember-selection checks passed' . PHP_EOL;
