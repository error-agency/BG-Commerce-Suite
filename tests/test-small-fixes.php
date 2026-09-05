<?php
/**
 * TASK-J1 — BGCS-AUDIT-010, -011 and -014.
 *
 *   -010  `Method::add_checkout_error_notice()` was fully implemented and never
 *         called. Every failure path in `calculate_shipping()` reports through
 *         `Availability_Store`, so a second, parallel notice channel could only
 *         ever double up on an availability card the customer already sees.
 *   -011  `Cod::is_chosen()` fed a slashed `$_POST['post_data']` straight to
 *         `parse_str()`.
 *   -014  `uninstall.php` cleaned only the current site's options table, so a
 *         network uninstall left every other site's settings behind.
 *
 * Run: php tests/test-small-fixes.php
 */

define( 'ABSPATH', __DIR__ );

$scenario = isset( $argv[1] ) ? $argv[1] : '';

// ---------------------------------------------------------------------------
// The uninstall scenarios each need a pristine global scope (uninstall.php
// declares functions at file level), so they run as their own PHP processes.
// ---------------------------------------------------------------------------
if ( 'uninstall-multisite' === $scenario || 'uninstall-single' === $scenario ) {
	run_uninstall_scenario( $scenario );
	exit( 0 );
}

$failures = 0;
function check_fix( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

// ---------------------------------------------------------------------------
// BGCS-AUDIT-011 — Cod::is_chosen()
// ---------------------------------------------------------------------------

function sanitize_text_field( $value ) {
	return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : ( is_array( $value ) ? array_map( 'wp_unslash', $value ) : $value );
}
function apply_filters( $hook, $value ) {
	return $value;
}

require_once dirname( __DIR__ ) . '/app/Shipping/Cod.php';

use BgCommerce3\Shipping\Cod;

echo "--- BGCS-AUDIT-011: COD is recognised from a slashed post_data ---\n";

$_POST = array();
check_fix( false === Cod::is_chosen(), 'No payment method posted → not COD' );

$_POST = array( 'payment_method' => 'cod' );
check_fix( true === Cod::is_chosen(), 'An explicit payment_method field is honoured' );

$_POST = array( 'payment_method' => 'bacs' );
check_fix( false === Cod::is_chosen(), 'A non-COD payment method is not COD' );

// WooCommerce posts the serialized checkout form as `post_data` during an AJAX
// refresh, and WordPress has already slashed the whole superglobal by then.
$_POST = array( 'post_data' => 'billing_country=BG&payment_method=cod&order_comments=' );
check_fix( true === Cod::is_chosen(), 'COD is recognised from post_data' );

// The regression the fix is about. WordPress slashes the whole $_POST
// superglobal, so a quote inside post_data reaches PHP as \' — and parse_str()
// does not undo that. The gateway id then no longer matches what
// `Cod::methods()` declares, and COD is silently not applied.
$slashed = "payment_method=cod\\'x";
check_fix( "cod'x" === parsed_payment_method( $slashed ), 'A slashed value round-trips to the real gateway id' );
check_fix( "cod'x" !== parsed_payment_method_unfixed( $slashed ), 'Without wp_unslash() the same input yields the wrong id — the fix is load-bearing' );

function parsed_payment_method( $raw ) {
	$posted = array();
	parse_str( wp_unslash( $raw ), $posted );
	return isset( $posted['payment_method'] ) ? sanitize_text_field( $posted['payment_method'] ) : '';
}

/** The pre-fix behaviour, kept only so the test can show the difference. */
function parsed_payment_method_unfixed( $raw ) {
	$posted = array();
	parse_str( $raw, $posted );
	return isset( $posted['payment_method'] ) ? sanitize_text_field( $posted['payment_method'] ) : '';
}

$cod_code = php_strip_whitespace( dirname( __DIR__ ) . '/app/Shipping/Cod.php' );
check_fix( false !== strpos( $cod_code, 'parse_str( wp_unslash(' ), 'parse_str() receives unslashed input' );
check_fix( false === strpos( $cod_code, "parse_str( \$_POST['post_data'] )" ), 'The raw superglobal is no longer parsed directly' );

// ---------------------------------------------------------------------------
// BGCS-AUDIT-010 — no dead private method, one channel for unavailability
// ---------------------------------------------------------------------------

echo "--- BGCS-AUDIT-010: no uncalled private methods in Method.php ---\n";

$method_source = file_get_contents( dirname( __DIR__ ) . '/app/Shipping/Method.php' );
check_fix( false === strpos( $method_source, 'add_checkout_error_notice' ), 'The dead notice helper is gone' );

// Guard the whole class, so the next orphan is caught too.
preg_match_all( '/private function ([a-z0-9_]+)\s*\(/i', $method_source, $declared );
$orphans = array();
foreach ( $declared[1] as $name ) {
	// One hit is the declaration itself; a live method has at least one more.
	if ( 1 === preg_match_all( '/\b' . preg_quote( $name, '/' ) . '\s*\(/', $method_source ) ) {
		$orphans[] = $name;
	}
}
check_fix( array() === $orphans, 'Every private method in Method.php has a caller: ' . ( $orphans ? implode( ', ', $orphans ) : 'all called' ) );

// Acceptance criterion 2 — exactly one channel tells the customer why a
// delivery method cannot be used.
$code = php_strip_whitespace( dirname( __DIR__ ) . '/app/Shipping/Method.php' );
check_fix( false === strpos( $code, 'wc_add_notice(' ), 'Method.php no longer has a second, parallel notice channel' );
check_fix( false !== strpos( $code, '$availability_store->record(' ), 'Unavailability is reported through Availability_Store' );

// ---------------------------------------------------------------------------
// BGCS-AUDIT-014 — uninstall
// ---------------------------------------------------------------------------

echo "--- BGCS-AUDIT-014: uninstall cleans every site in a network ---\n";

foreach ( array( 'uninstall-multisite', 'uninstall-single' ) as $case ) {
	$output = array();
	$status = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $case ), $output, $status );
	foreach ( $output as $line ) {
		echo $line . PHP_EOL;
		if ( false !== strpos( $line, '[FAIL]' ) ) {
			$failures++;
		}
	}
	if ( 0 !== $status ) {
		echo '  [FAIL] ' . $case . ' scenario exited with status ' . $status . PHP_EOL;
		$failures++;
	}
}

echo "--- BGCS-AUDIT-014: the conservative scope is unchanged ---\n";
$uninstall = php_strip_whitespace( dirname( __DIR__ ) . '/uninstall.php' );
check_fix( false === strpos( $uninstall, 'postmeta' ) && false === strpos( $uninstall, 'wc_orders' ), 'Order meta is never touched' );
check_fix( false === strpos( $uninstall, "'bgcs_'" ), 'Legacy bgcs_ options are never touched' );
check_fix( false !== strpos( $uninstall, 'as_unschedule_all_actions' ), 'Scheduled Action Scheduler work is unscheduled' );
check_fix( false === strpos( $uninstall, "'number' => 0" ), 'Sites are walked in batches, not fetched all at once' );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all small-fix checks passed' . PHP_EOL;

/**
 * Runs uninstall.php against a fake WordPress and reports what it cleaned.
 *
 * @param string $scenario 'uninstall-multisite' or 'uninstall-single'.
 * @return void
 */
function run_uninstall_scenario( $scenario ) {
	$multisite = ( 'uninstall-multisite' === $scenario );

	$GLOBALS['bgcs_current_blog'] = 1;
	$GLOBALS['bgcs_unscheduled']  = array();
	$GLOBALS['bgcs_multisite']    = $multisite;

	// Options per site — one BGCS row, one transient, one timeout row and two
	// rows that must survive.
	$GLOBALS['bgcs_site_options'] = array();
	foreach ( ( $multisite ? array( 1, 2, 3 ) : array( 1 ) ) as $site_id ) {
		$GLOBALS['bgcs_site_options'][ $site_id ] = array(
			'bgcs3_speedy'                     => 'settings',
			'_transient_bgcs3_offices'         => 'cache',
			'_transient_timeout_bgcs3_offices' => '123456',
			'bgcs_legacy_speedy'               => 'legacy — must survive',
			'woocommerce_currency'             => 'BGN — must survive',
		);
	}

	eval( '
		function is_multisite() { return (bool) $GLOBALS["bgcs_multisite"]; }
		function get_sites( $args = array() ) {
			$all    = array( 1, 2, 3 );
			$offset = isset( $args["offset"] ) ? (int) $args["offset"] : 0;
			$number = isset( $args["number"] ) ? (int) $args["number"] : 100;
			return array_slice( $all, $offset, $number );
		}
		function switch_to_blog( $id ) { $GLOBALS["bgcs_current_blog"] = (int) $id; $GLOBALS["wpdb"]->site = (int) $id; }
		function restore_current_blog() { $GLOBALS["bgcs_current_blog"] = 1; $GLOBALS["wpdb"]->site = 1; }
		function as_unschedule_all_actions( $hook, $args = array(), $group = "" ) {
			$GLOBALS["bgcs_unscheduled"][] = $group . ":" . $hook;
		}
	' );

	$GLOBALS['wpdb'] = new Fake_Uninstall_Wpdb();

	define( 'WP_UNINSTALL_PLUGIN', true );
	require dirname( __DIR__ ) . '/uninstall.php';

	$sites   = $multisite ? array( 1, 2, 3 ) : array( 1 );
	$label   = $multisite ? 'multisite' : 'single site';
	$cleaned = true;
	$kept    = true;

	foreach ( $sites as $site_id ) {
		$remaining = $GLOBALS['bgcs_site_options'][ $site_id ];
		foreach ( array( 'bgcs3_speedy', '_transient_bgcs3_offices', '_transient_timeout_bgcs3_offices' ) as $gone ) {
			if ( isset( $remaining[ $gone ] ) ) {
				$cleaned = false;
			}
		}
		foreach ( array( 'bgcs_legacy_speedy', 'woocommerce_currency' ) as $survivor ) {
			if ( ! isset( $remaining[ $survivor ] ) ) {
				$kept = false;
			}
		}
	}

	report( $cleaned, "{$label}: bgcs3_ options and both transient rows removed on every site (" . count( $sites ) . ')' );
	report( $kept, "{$label}: legacy bgcs_ options and unrelated WooCommerce options survive" );
	report( ! empty( $GLOBALS['bgcs_unscheduled'] ), "{$label}: Action Scheduler work is unscheduled" );
	report( 1 === $GLOBALS['bgcs_current_blog'], "{$label}: the original blog context is restored" );

	if ( $multisite ) {
		// Site 3 is only reached if the walk really visits every site.
		report( ! isset( $GLOBALS['bgcs_site_options'][3]['bgcs3_speedy'] ), 'multisite: the last site in the network was reached' );
	}
}

function report( $condition, $message ) {
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
}

/** Options-table double that honours the current "site". */
class Fake_Uninstall_Wpdb {

	/** @var string */
	public $options = 'wp_options';

	/** @var int */
	public $site = 1;

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

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
		preg_match_all( "/LIKE '([^']*)'/", (string) $sql, $matches );
		if ( empty( $matches[1] ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $GLOBALS['bgcs_site_options'][ $this->site ] as $name => $value ) {
			foreach ( $matches[1] as $pattern ) {
				// Translate the SQL LIKE into a prefix test. esc_like() plus
				// prepare()'s addslashes leave escaping backslashes behind; no
				// real option name contains one, so dropping them all is exact.
				$prefix = str_replace( '\\', '', rtrim( $pattern, '%' ) );
				if ( 0 === strpos( $name, $prefix ) ) {
					unset( $GLOBALS['bgcs_site_options'][ $this->site ][ $name ] );
					$deleted++;
					break;
				}
			}
		}

		return $deleted;
	}
}
