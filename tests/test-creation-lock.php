<?php
/**
 * BGCS-AUDIT-001 — regression tests for the per-order creation lock.
 *
 * The audit proved the previous `add_option()` primitive was not a mutex at
 * all: 4 of 5 synchronized rounds had more than one acquirer, and all three
 * couriers reached `create_label()` from 3 of 3 concurrent callers. These
 * tests pin the replacement primitive and the properties the fix depends on.
 *
 * Run: php tests/test-creation-lock.php
 */

define( 'ABSPATH', __DIR__ );

require_once dirname( __DIR__ ) . '/app/Shipping/Creation_Lock.php';

use BgCommerce3\Shipping\Creation_Lock;

$failures = 0;
function check_lock( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

/**
 * In-memory storage reproducing the MySQL semantics the real implementation
 * relies on — and, crucially, NOT the semantics `add_option()` has:
 *
 *  - INSERT IGNORE  → the UNIQUE index REFUSES a duplicate (returns false),
 *                     it does not overwrite it the way ON DUPLICATE KEY UPDATE
 *                     does.
 *  - UPDATE … WHERE option_value = expected → compare-and-swap.
 *  - DELETE … WHERE option_value = owner    → never deletes someone else's row.
 */
class Fake_Lock_Storage {

	/** @var array<string,string> */
	public $rows = array();

	/** @var int Number of rows this storage actually handed out. */
	public $granted = 0;

	/** @var string|null When set, read_owner() returns this instead of the row. */
	public $frozen_read = null;

	public function adapter() {
		return array(
			'insert_if_absent' => array( $this, 'insert_if_absent' ),
			'read_owner'       => array( $this, 'read_owner' ),
			'replace_owner'    => array( $this, 'replace_owner' ),
			'delete_if_owned'  => array( $this, 'delete_if_owned' ),
		);
	}

	public function insert_if_absent( $key, $owner ) {
		if ( array_key_exists( $key, $this->rows ) ) {
			return false;
		}
		$this->rows[ $key ] = $owner;
		$this->granted++;
		return true;
	}

	public function read_owner( $key ) {
		if ( null !== $this->frozen_read ) {
			return $this->frozen_read;
		}
		return isset( $this->rows[ $key ] ) ? $this->rows[ $key ] : '';
	}

	public function replace_owner( $key, $expected, $new ) {
		if ( ! isset( $this->rows[ $key ] ) || $this->rows[ $key ] !== $expected ) {
			return false;
		}
		$this->rows[ $key ] = $new;
		$this->granted++;
		return true;
	}

	public function delete_if_owned( $key, $owner ) {
		if ( isset( $this->rows[ $key ] ) && $this->rows[ $key ] === $owner ) {
			unset( $this->rows[ $key ] );
			return true;
		}
		return false;
	}
}

/** Owner token shaped like the real one, with a chosen age. */
function owner_aged( $seconds_ago, $suffix = 'x' ) {
	return ( time() - (int) $seconds_ago ) . ':' . $suffix;
}

$key = 'bgcs3_create_lock_8230';

echo "--- A free lock is acquired, a held lock is refused ---\n";
$storage = new Fake_Lock_Storage();
$lock    = new Creation_Lock( $storage->adapter() );

$owner = $lock->acquire( $key );
check_lock( is_string( $owner ) && '' !== $owner, 'First caller receives an owner token' );
check_lock( isset( $storage->rows[ $key ] ) && $storage->rows[ $key ] === $owner, 'The lock row holds that caller token' );
check_lock( false === $lock->acquire( $key ), 'A second acquire on a held, fresh lock returns false' );

echo "--- Acceptance criterion 3: a pre-existing, non-stale row refuses acquire ---\n";
$storage = new Fake_Lock_Storage();
$storage->rows[ $key ] = owner_aged( 5, 'someone-else' );
$lock                  = new Creation_Lock( $storage->adapter() );
check_lock( false === $lock->acquire( $key ), 'acquire() on a pre-created row returns false' );
check_lock( owner_aged( 5, 'someone-else' ) === $storage->rows[ $key ], 'The existing holder was NOT overwritten' );
check_lock( 0 === $storage->granted, 'No ownership was handed out' );

echo "--- Acceptance criterion 1: only one of many concurrent callers acquires ---\n";
$storage  = new Fake_Lock_Storage();
$acquired = array();
for ( $i = 0; $i < 6; $i++ ) {
	// Six independent workers, exactly as the live 6x5 race test ran them.
	$worker = new Creation_Lock( $storage->adapter() );
	$token  = $worker->acquire( $key );
	if ( false !== $token ) {
		$acquired[] = $token;
	}
}
check_lock( 1 === count( $acquired ), 'Exactly 1 of 6 concurrent callers acquires (was 6 of 6 before the fix)' );
check_lock( 1 === $storage->granted, 'The storage layer handed out ownership exactly once' );

echo "--- Acceptance criterion 4: a stale lock is reclaimed, exactly once ---\n";
$storage               = new Fake_Lock_Storage();
$stale                 = owner_aged( Creation_Lock::STALE_AFTER_SECONDS + 60, 'abandoned' );
$storage->rows[ $key ] = $stale;

$first  = ( new Creation_Lock( $storage->adapter() ) )->acquire( $key );
$second = ( new Creation_Lock( $storage->adapter() ) )->acquire( $key );
check_lock( is_string( $first ) && '' !== $first, 'A lock past the stale threshold is reclaimed' );
check_lock( $storage->rows[ $key ] === $first, 'The reclaiming caller owns the row' );
check_lock( false === $second, 'The freshly reclaimed lock is immediately non-stale again — reclaimed exactly once' );

echo "--- A lock just under the stale threshold is NOT reclaimed ---\n";
$storage               = new Fake_Lock_Storage();
$storage->rows[ $key ] = owner_aged( Creation_Lock::STALE_AFTER_SECONDS - 5, 'still-working' );
check_lock( false === ( new Creation_Lock( $storage->adapter() ) )->acquire( $key ), 'A lock younger than the threshold stays held' );

echo "--- Two callers that both saw the same stale token: only one takes it over ---\n";
// Both reclaimers read the row before either wrote — the exact interleaving a
// compare-and-swap has to survive, and the one delete-then-insert would lose.
$storage               = new Fake_Lock_Storage();
$stale                 = owner_aged( Creation_Lock::STALE_AFTER_SECONDS + 10, 'abandoned' );
$storage->rows[ $key ] = $stale;
$storage->frozen_read  = $stale;

$a = ( new Creation_Lock( $storage->adapter() ) )->acquire( $key );
$b = ( new Creation_Lock( $storage->adapter() ) )->acquire( $key );
check_lock( ( false === $a ) !== ( false === $b ), 'Exactly one of two simultaneous stale reclaimers wins' );
check_lock( 1 === $storage->granted, 'The hand-over granted ownership exactly once' );
$storage->frozen_read = null;

echo "--- release() never frees a lock the caller does not own ---\n";
$storage = new Fake_Lock_Storage();
$lock    = new Creation_Lock( $storage->adapter() );
$owner   = $lock->acquire( $key );
$lock->release( $key, 'someone-elses-token' );
check_lock( isset( $storage->rows[ $key ] ), 'A foreign owner token does not delete the row' );
$lock->release( $key, '' );
check_lock( isset( $storage->rows[ $key ] ), 'An empty owner token does not delete the row' );
$lock->release( $key, $owner );
check_lock( ! isset( $storage->rows[ $key ] ), 'The real owner releases the lock' );
check_lock( is_string( ( new Creation_Lock( $storage->adapter() ) )->acquire( $key ) ), 'The key is acquirable again after release' );

echo "--- acquire() makes a single attempt: it never blocks or retries ---\n";
$storage               = new Fake_Lock_Storage();
$storage->rows[ $key ] = owner_aged( 1, 'busy' );
$started               = microtime( true );
check_lock( false === ( new Creation_Lock( $storage->adapter() ) )->acquire( $key ), 'A held lock is refused' );
check_lock( ( microtime( true ) - $started ) < 0.05, 'The refusal is immediate — no sleep/backoff loop' );

echo "--- Per-order isolation ---\n";
$storage = new Fake_Lock_Storage();
$lock    = new Creation_Lock( $storage->adapter() );
check_lock( is_string( $lock->acquire( 'bgcs3_create_lock_8230' ) ), 'Order 8230 acquires' );
check_lock( is_string( $lock->acquire( 'bgcs3_create_lock_8231' ) ), 'Order 8231 acquires independently' );

echo "--- Static guards: the broken primitive must not come back ---\n";
$lock_file = dirname( __DIR__ ) . '/app/Shipping/Creation_Lock.php';
$source    = file_get_contents( $lock_file );
// Comments explain WHY add_option()/ON DUPLICATE KEY UPDATE are wrong, so the
// executable guards run against the source with comments stripped out.
$code = php_strip_whitespace( $lock_file );

check_lock( false === strpos( $code, 'add_option(' ), 'Creation_Lock no longer calls add_option() — it is read-then-write, not a mutex' );
check_lock( false !== strpos( $code, 'INSERT IGNORE' ), 'The lock is taken with INSERT IGNORE, so the UNIQUE index refuses instead of overwriting' );
check_lock( false === strpos( $code, 'ON DUPLICATE KEY' ), 'No ON DUPLICATE KEY UPDATE in the executed SQL' );
check_lock( false === strpos( $code, 'sleep(' ), 'No sleep/backoff — a second click must fail immediately' );
check_lock( false === strpos( $code, 'wp_cache_add(' ), 'The mutex is not built on wp_cache_add(), which is not transactional against the database' );
check_lock( false === strpos( $source, 'UNIQUE index WordPress puts' ), 'The docblock no longer claims add_option() is atomic (acceptance criterion 5)' );

// A single Speedy HTTP call may take 30s and a create is several calls, so a
// lock must not be declared abandoned while the shipment is still in flight —
// reclaiming a live lock is exactly how a second request duplicates a shipment.
check_lock( Creation_Lock::STALE_AFTER_SECONDS >= 120, 'The stale threshold comfortably exceeds a full multi-call create (is ' . Creation_Lock::STALE_AFTER_SECONDS . 's)' );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all creation lock checks passed' . PHP_EOL;
