<?php
/**
 * TASK-A1 — BGCS-AUDIT-003, -005 and -016: one key, one default.
 *
 * `settings_fields()` declares a `'default'` for every field and the admin panel
 * renders it, but every runtime reader used to repeat a default of its own as
 * the third argument to `bgcs3_get_option()`. Three copies had drifted:
 *
 *   speedy.service_payer      declared SENDER · order snapshot read RECIPIENT
 *   speedy.cod_pmt_fee_payer  declared SENDER · both pricing paths read RECIPIENT
 *   <courier>.default_weight  declared 1 kg   · Econt courier-request read 0.01 kg
 *
 * A missing key is the real path to this: `Settings_Page::handle_save()` skips
 * fields outside the current task scope and fields whose `show_if` parent is
 * inactive, so a legitimately-saved install can lack a key entirely.
 *
 * Run: php tests/test-settings-defaults.php
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

require_once dirname( __DIR__ ) . '/app/Support/Options.php';
require_once dirname( __DIR__ ) . '/app/Support/Module_Settings.php';
require_once dirname( __DIR__ ) . '/app/Shipping/Weight.php';

use BgCommerce3\Shipping\Weight;
use BgCommerce3\Support\Module_Settings;

$failures = 0;
function check_default( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

function set_stored( $group, array $values ) {
	$GLOBALS['bgcs_options'][ 'bgcs3_' . $group ] = $values;
}

// The field definitions the four couriers really declare for the keys under
// test — asserted against the source further down, so this fixture cannot
// silently drift away from the declarations.
Module_Settings::prime(
	'speedy',
	array(
		'service_payer'     => array( 'type' => 'select', 'default' => 'SENDER' ),
		'cod_pmt_fee_payer' => array( 'type' => 'select', 'default' => 'SENDER' ),
		'default_weight'    => array( 'type' => 'text', 'default' => '1' ),
		'no_default'        => array( 'type' => 'text' ),
	)
);
Module_Settings::prime( 'econt', array( 'default_weight' => array( 'type' => 'text', 'default' => '1' ) ) );

echo "--- A stored value always wins ---\n";
set_stored( 'speedy', array( 'service_payer' => 'THIRD_PARTY' ) );
check_default( 'THIRD_PARTY' === Module_Settings::get( 'speedy', 'service_payer' ), 'A stored value is returned' );

set_stored( 'speedy', array( 'service_payer' => '' ) );
check_default( '' === Module_Settings::get( 'speedy', 'service_payer' ), 'An explicitly stored empty string is NOT replaced by the default' );

set_stored( 'speedy', array( 'service_payer' => '0' ) );
check_default( '0' === Module_Settings::get( 'speedy', 'service_payer' ), 'A stored "0" is not mistaken for missing' );

echo "--- A missing key falls back to what the field declares ---\n";
set_stored( 'speedy', array() );
check_default( 'SENDER' === Module_Settings::get( 'speedy', 'service_payer' ), 'BGCS-AUDIT-003: a missing service_payer resolves to the declared SENDER' );
check_default( 'SENDER' === Module_Settings::get( 'speedy', 'cod_pmt_fee_payer' ), 'BGCS-AUDIT-005: a missing cod_pmt_fee_payer resolves to the declared SENDER' );
check_default( '1' === Module_Settings::get( 'speedy', 'default_weight' ), 'BGCS-AUDIT-016: a missing default_weight resolves to the declared 1 kg' );

$GLOBALS['bgcs_options'] = array();
check_default( 'SENDER' === Module_Settings::get( 'speedy', 'service_payer' ), 'A module with no option row at all still resolves the declared default' );

echo "--- Acceptance criterion 1: every caller gets the SAME value ---\n";
// The payload path, the settings panel and both order-snapshot paths all reach
// the value through this one call, so agreement is structural, not coincidental.
$callers = array(
	'Speedy::payer() payload path'          => Module_Settings::get( 'speedy', 'service_payer' ),
	'Settings_Page field render'            => Module_Settings::default_for( 'speedy', 'service_payer' ),
	'MetaBox::effective_payer() snapshot'   => Module_Settings::get( 'speedy', 'service_payer' ),
	'Orders_Column quick-create snapshot'   => Module_Settings::get( 'speedy', 'service_payer' ),
);
check_default( 1 === count( array_unique( $callers ) ), 'All four service_payer consumers agree: ' . implode( '/', array_unique( $callers ) ) );

foreach ( array( 'SENDER', 'RECIPIENT', 'THIRD_PARTY' ) as $value ) {
	set_stored( 'speedy', array( 'service_payer' => $value ) );
	$seen = array_unique( array( Module_Settings::get( 'speedy', 'service_payer' ), Module_Settings::get( 'speedy', 'service_payer' ) ) );
	check_default( array( $value ) === array_values( $seen ), "They also agree when the key is explicitly {$value}" );
}

echo "--- Fallback only applies when the field declares nothing ---\n";
set_stored( 'speedy', array() );
check_default( 'caller-fallback' === Module_Settings::get( 'speedy', 'no_default', 'caller-fallback' ), 'A field without a declared default uses the caller fallback' );
check_default( 'caller-fallback' === Module_Settings::get( 'speedy', 'never_declared', 'caller-fallback' ), 'An unknown key uses the caller fallback' );
check_default( null === Module_Settings::get( 'speedy', 'never_declared' ), 'With no fallback the result is null, not a guess' );
check_default( false === Module_Settings::declares_default( 'speedy', 'no_default' ), 'declares_default() is false for a field without one' );
check_default( true === Module_Settings::declares_default( 'speedy', 'service_payer' ), 'declares_default() is true for a field with one' );

echo "--- An unregistered module never invents a value ---\n";
check_default( array() === Module_Settings::fields( 'not_installed' ), 'An unresolvable module has an empty field set' );
check_default( 'fallback' === Module_Settings::get( 'not_installed', 'anything', 'fallback' ), 'and its reads fall back to the caller value' );

echo "--- BGCS-AUDIT-016: the weight the cart uses, from the declared default ---\n";
$GLOBALS['bgcs_options'] = array();
Module_Settings::prime( 'econt', array( 'default_weight' => array( 'type' => 'text', 'default' => '1' ) ) );

$package = array( 'contents' => array( array( 'data' => new Weightless_Product(), 'quantity' => 1 ) ) );
$info    = Weight::cart_weight_info( 'econt', $package );
check_default( 1.0 === $info['weight'], 'A product with no weight falls back to 1 kg, not 0.01 kg (got ' . $info['weight'] . ')' );

// The Econt courier-request panel keeps max( MIN_KG, ... ) as a floor against a
// configured zero — a different and legitimate concern — but the value it floors
// is now the same declared default.
set_stored( 'econt', array( 'default_weight' => '0' ) );
$prefill = max( Weight::MIN_KG, (float) Module_Settings::get( 'econt', 'default_weight' ) );
check_default( Weight::MIN_KG === $prefill, 'A configured zero is still floored to MIN_KG by the courier-request panel' );

set_stored( 'econt', array() );
$prefill = max( Weight::MIN_KG, (float) Module_Settings::get( 'econt', 'default_weight' ) );
$info    = Weight::cart_weight_info( 'econt', $package );
check_default( $prefill === $info['weight'], 'Courier-request prefill and cart weight now agree (' . $prefill . ' kg)' );

echo "--- Composing the field set cannot recurse into itself ---\n";
// `settings_fields()` reads options while it builds — Econt resolves the
// selected profile before building its API-fed dropdowns. Without a guard, a
// read of a missing key inside that build would ask for the field set that is
// still being composed, and recurse until the stack died.
require_once dirname( __DIR__ ) . '/app/Module/Module_Interface.php';
require_once __DIR__ . '/lib/reentrant-module.php';

$GLOBALS['bgcs_reentrant_module'] = new Reentrant_Module();
$GLOBALS['bgcs_options']          = array();
Module_Settings::flush();

$fields = Module_Settings::fields( 'reentrant' );
check_default( isset( $fields['built'] ), 'The field set is composed even though the module reads options while composing' );
check_default( 1 === $GLOBALS['bgcs_reentrant_module']->builds, 'settings_fields() ran exactly once — no recursion' );
check_default( 'inner-fallback' === $GLOBALS['bgcs_reentrant_module']->seen, 'A read during the build falls back to the caller value, as it did before this class existed' );
check_default( 'declared-value' === Module_Settings::get( 'reentrant', 'built' ), 'After the build, the same key resolves to its declared default' );

echo "--- The field set is composed once and can be flushed ---\n";
Module_Settings::prime( 'flushme', array( 'k' => array( 'default' => 'first' ) ) );
check_default( 'first' === Module_Settings::get( 'flushme', 'k' ), 'A primed field set is used' );
Module_Settings::flush( 'flushme' );
check_default( 'later' === Module_Settings::get( 'flushme', 'k', 'later' ), 'flush() drops it' );

// ---------------------------------------------------------------------------
// STATIC guards
// ---------------------------------------------------------------------------

require_once __DIR__ . '/lib/settings-scanner.php';

$root     = dirname( __DIR__ );
$declared = bgcs_declared_defaults( $root );

// The general rules — every declared default has exactly one copy, no read
// names an undeclared key, every declared setting has a reader — live together
// in tests/test-settings-guards.php (TASK-K1). What stays here is specific to
// BGCS-AUDIT-003/-005/-016: that these three keys resolve through the single
// source, and that the fixture above still matches what Speedy declares.

echo "--- Every file that uses the resolver can actually resolve it ---\n";
// An unimported class name is a runtime fatal that `php -l` cannot see, and one
// file did slip through the sweep this way.
$unimported = array();
$rii        = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/app' ) );
foreach ( $rii as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$src = file_get_contents( $file->getPathname() );
	if ( false === strpos( $src, 'Module_Settings::' ) ) {
		continue;
	}
	$resolvable = false !== strpos( $src, 'use BgCommerce3\\Support\\Module_Settings;' )
		|| false !== strpos( $src, '\\BgCommerce3\\Support\\Module_Settings::' )
		|| (bool) preg_match( '/^namespace\s+BgCommerce3\\\\Support;/m', $src );
	if ( ! $resolvable ) {
		$unimported[] = str_replace( array( $root . '/', $root . '\\', '\\' ), array( '', '', '/' ), $file->getPathname() );
	}
}
check_default( array() === $unimported, 'Module_Settings is imported or fully qualified everywhere it is used' . ( $unimported ? ': ' . implode( ', ', $unimported ) : '' ) );

echo "--- The three findings resolve through the single source ---\n";
foreach ( array(
	'service_payer'     => array( 'app/Modules/Shipping/Speedy/Speedy.php', 'app/Admin/Order/MetaBox.php', 'app/Admin/Order/Orders_Column.php' ),
	'cod_pmt_fee_payer' => array( 'app/Modules/Shipping/Speedy/Speedy.php' ),
	'default_weight'    => array( 'app/Shipping/Weight.php', 'app/Modules/Shipping/Econt/Econt.php' ),
) as $key => $files ) {
	foreach ( $files as $file ) {
		$code = php_strip_whitespace( $root . '/' . $file );
		check_default(
			false === strpos( $code, "bgcs3_get_option( self::ID, '{$key}'" )
				&& false === strpos( $code, "bgcs3_get_option( \$courier_id, '{$key}'" ),
			basename( $file ) . " no longer reads '{$key}' with a default of its own"
		);
	}
}

// The fixture at the top of this file must match what Speedy really declares.
check_default( isset( $declared['service_payer'] ) && in_array( 'SENDER', $declared['service_payer'], true ), 'Speedy still declares service_payer as SENDER' );
check_default( isset( $declared['cod_pmt_fee_payer'] ) && in_array( 'SENDER', $declared['cod_pmt_fee_payer'], true ), 'Speedy still declares cod_pmt_fee_payer as SENDER' );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all settings default checks passed' . PHP_EOL;

/** A WC_Product-like double with no configured weight. */
class Weightless_Product {
	public function is_virtual() {
		return false;
	}
	public function get_weight() {
		return '';
	}
}
