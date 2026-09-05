<?php
/**
 * TASK-K1 — the three static settings guards.
 *
 * The audit found four defects of one class by hand, and noted that a single
 * pair of passes over the source would have caught all of them:
 *
 *   1. COVERAGE      every declared setting has at least one runtime reader.
 *                    Catches BGCS-AUDIT-002 (three Econt fields the merchant can
 *                    set that change nothing).
 *   2. NO GHOST KEYS every settings read names a key the module establishes.
 *                    Catches BGCS-AUDIT-004 (`payment_side`, read for years,
 *                    declared nowhere, silently returning its default).
 *   3. ONE DEFAULT   no reader repeats a default the field already declares.
 *                    Catches BGCS-AUDIT-003, -005 and -016.
 *
 * These are cheap — pure static analysis, no WordPress bootstrap — and they
 * close the class, not just the four instances.
 *
 * Run: php tests/test-settings-guards.php
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/lib/settings-scanner.php';

$failures = 0;
function check_guard( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

$root    = dirname( __DIR__ );
$fields  = bgcs_declared_fields( $root );
$readers = bgcs_setting_readers( $root );
$consts  = bgcs_constant_keys( $root );

/**
 * Settings whose key is built at runtime, so no literal read exists to find.
 * Each entry names the site that builds it, because an unexplained exception is
 * indistinguishable from a dead setting.
 */
$dynamic_key_families = array(
	// Label_Builder.php:401-414 iterates $pack_service_map, reading each key
	// through wbx_value( $wb, $setting_key, $setting_key, '0' ).
	'econt'  => array( 'econt_pack', 'econt_refrigerated_pack' ),
	// BoxNow.php:1877,1881 read 'label_row' . $i.
	'boxnow' => array( 'label_row' ),
	// Pigeon generates service_* from the synced account catalogue (settings
	// audit section 9.1); they are not declared fields, listed for completeness.
	'pigeon' => array( 'service_' ),
);

/**
 * Declared settings with no runtime effect that are waiting on a product
 * decision. Empty, and it must stay that way: this is not a place to park new
 * dead settings.
 *
 * BGCS-AUDIT-002 / TASK-F1 held the only three entries this list has ever had.
 * `shipping_to_style` and `hide_quarter_fields` were removed; `local_storage`
 * became Core's `checkout.remember_selection` and now has a real effect.
 */
$open_findings = array();

/**
 * @param string   $key      Setting key.
 * @param string[] $prefixes Dynamic key prefixes for this group.
 * @return bool
 */
function is_dynamic_key( $key, array $prefixes ) {
	foreach ( $prefixes as $prefix ) {
		if ( 0 === strpos( $key, $prefix ) ) {
			return true;
		}
	}
	return false;
}

echo "--- Guard 1: every declared setting has a runtime reader (BGCS-AUDIT-002) ---\n";

$uncovered = array();
foreach ( bgcs_module_dirs() as $group => $dir ) {
	$written = bgcs_keys_written_by( $root, $dir );
	$dynamic = isset( $dynamic_key_families[ $group ] ) ? $dynamic_key_families[ $group ] : array();

	foreach ( $fields[ $group ] as $key => $attributes ) {
		// A `note` field is read-only prose; there is no value to consume.
		if ( 'note' === $attributes['type'] ) {
			continue;
		}
		if ( is_dynamic_key( $key, $dynamic ) ) {
			continue;
		}
		if ( isset( $readers[ $key ] ) || in_array( $key, $consts, true ) || in_array( $key, $written, true ) ) {
			continue;
		}
		if ( isset( $open_findings[ $group ][ $key ] ) ) {
			continue;
		}
		$uncovered[] = $group . '.' . $key . ' [' . ( $attributes['type'] ? $attributes['type'] : '?' ) . '] declared in ' . $attributes['file'];
	}
}

check_guard(
	array() === $uncovered,
	'No setting is rendered to the merchant without a runtime consumer'
		. ( $uncovered ? ":\n      " . implode( "\n      ", $uncovered ) : '' )
);

// The exception list must not outlive the finding it documents.
foreach ( $open_findings as $group => $keys ) {
	foreach ( $keys as $key => $finding ) {
		$still_declared = isset( $fields[ $group ][ $key ] );
		$still_dead     = $still_declared && ! isset( $readers[ $key ] ) && ! in_array( $key, $consts, true );
		check_guard(
			$still_dead,
			"{$group}.{$key} is still the known-dead setting from {$finding}"
				. ( $still_dead ? '' : ' — it now has a reader or is gone, so delete this exception' )
		);
	}
}

echo "--- Guard 2: no read of a key the module never establishes (BGCS-AUDIT-004) ---\n";

$declared_keys = bgcs_declared_keys( $root );
$ghosts        = array();

foreach ( bgcs_module_dirs() as $group => $dir ) {
	$known = array_merge(
		isset( $declared_keys[ $group ] ) ? $declared_keys[ $group ] : array(),
		isset( $declared_keys['*'] ) ? $declared_keys['*'] : array(),
		bgcs_keys_written_by( $root, $dir )
	);

	$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir ) );
	foreach ( $rii as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$rel = str_replace( array( $root . '/', $root . '\\', '\\' ), array( '', '', '/' ), $file->getPathname() );

		foreach ( file( $file->getPathname() ) as $index => $line ) {
			$pattern = "/(?:bgcs3_get_option|Module_Settings::get)\(\s*(?:self::ID|[A-Za-z_]+::ID|'{$group}')\s*,\s*'([a-z0-9_]+)'\s*(.)/i";
			if ( ! preg_match_all( $pattern, $line, $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$key = $match[1];
				// `'label_row' . $i` builds the key at runtime; the literal
				// fragment is not itself a settings key.
				if ( '.' === $match[2] ) {
					continue;
				}
				// Underscore-prefixed keys are internal caches the module owns.
				if ( '_' === substr( $key, 0, 1 ) ) {
					continue;
				}
				// Keys from a documented dynamic family are not declared fields.
				if ( is_dynamic_key( $key, isset( $dynamic_key_families[ $group ] ) ? $dynamic_key_families[ $group ] : array() ) ) {
					continue;
				}
				if ( ! in_array( $key, $known, true ) ) {
					$ghosts[] = $rel . ':' . ( $index + 1 ) . '  ' . $group . '.' . $key;
				}
			}
		}
	}
}

check_guard(
	array() === $ghosts,
	'Every settings key read is declared by its module or written by it'
		. ( $ghosts ? ":\n      " . implode( "\n      ", $ghosts ) : '' )
);
check_guard(
	! in_array( 'payment_side', $declared_keys['econt'], true ),
	'payment_side is still not an Econt setting — which is what makes reading it fail the guard above'
);

echo "--- Guard 3: the declared default is the only copy (BGCS-AUDIT-003/-005/-016) ---\n";

$declared_defaults = bgcs_declared_defaults( $root );
$repeats           = bgcs_reads_repeating_a_declared_default( $root, $declared_defaults );

check_guard( ! empty( $declared_defaults ), 'The scanner found declared defaults: ' . count( $declared_defaults ) . ' keys' );
check_guard(
	array() === $repeats,
	'No bgcs3_get_option() read repeats a default its field already declares'
		. ( $repeats ? ":\n      " . implode( "\n      ", $repeats ) : '' )
);

echo "--- The scanner sees the forms that hid these findings ---\n";
// BGCS-AUDIT-016 escaped the audit's own first-pass detector twice over:
// `default_weight` is declared by assignment inside `Pricing::fields_for()`
// rather than as an array literal, and the Econt read passed a class CONSTANT
// rather than a quoted string. A scanner blind to either reports a clean sweep.
check_guard( isset( $fields['*']['default_weight'] ), "It finds \$fields['default_weight'] = array(…) declared by assignment" );
check_guard( '1' === $fields['*']['default_weight']['default'], 'and reads its declared default as 1' );
check_guard( bgcs_scanner_matches_constant_defaults(), 'It recognises a constant default such as Weight::MIN_KG' );

// The precise extractor must not drift back into counting field ATTRIBUTES as
// settings — that is what made an earlier pass report 43 Speedy settings.
foreach ( array( 'type', 'label', 'default', 'options', 'description', 'show_if', 'label_key' ) as $attribute ) {
	check_guard( ! isset( $fields['speedy'][ $attribute ] ), "'{$attribute}' is read as a field attribute, not as a setting" );
}

// Counts, as an independent cross-check against the audit's manual tally.
// The audit counted speedy 35. TASK-S1 added `handling_fee` and
// `surcharges_on_free_shipping` and retired `cod_pmt_on_free_shipping`: 36.
// Speedy 3.0.55 separates sender handover from the contract object: 37.
// The full-locker merchant policy and its editable shipment note add two: 39.
$expected_counts = array( 'speedy' => 39, 'boxnow' => 22, 'pigeon' => 18 );
foreach ( $expected_counts as $group => $expected ) {
	check_guard(
		count( $fields[ $group ] ) === $expected,
		sprintf( '%s declares %d settings, matching the audit tally', $group, count( $fields[ $group ] ) )
	);
}

echo "--- The readers scanner covers every form this codebase uses ---\n";
// A guard blind to the wbx_* resolvers would report most of Econt's shipment
// services as dead settings.
foreach ( array(
	'delivery_receipt'       => 'wbx_bool() third argument',
	'cd_pay_options'         => 'wbx_value() third argument',
	'sender_office_code'     => 'a direct read',
) as $key => $form ) {
	check_guard( isset( $readers[ $key ] ), "It sees a setting read through {$form} ({$key})" );
}

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all settings guards passed' . PHP_EOL;
