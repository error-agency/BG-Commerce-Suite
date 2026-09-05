<?php
/**
 * Per-order creation lock (Rule 25 — double-click protection).
 *
 * BGCS-AUDIT-001: this class used to acquire the lock with `add_option()` and
 * documented that call as atomic. It is not. `add_option()` is read-then-write:
 * it first asks `get_option()`/the `notoptions` cache whether the key exists,
 * and its INSERT ends in `ON DUPLICATE KEY UPDATE`, so the UNIQUE index on
 * `option_name` silently OVERWRITES a competing row instead of refusing it —
 * every concurrent caller gets a truthy result and believes it holds the lock.
 * Live reproduction: 4 of 5 synchronized rounds had more than one acquirer,
 * three of them had all six callers acquire.
 *
 * The lock is therefore taken with `INSERT IGNORE`, where the same UNIQUE index
 * REFUSES the second row, and ownership is decided by `affected_rows === 1` — a
 * database-level decision with no PHP-side existence check to race against.
 * Every read and write here deliberately bypasses the options object cache for
 * the same reason: a persistent object cache widens the window it is asked to
 * close.
 *
 * Unlike a numbering lock, this is a single-attempt UI-level mutex, not a
 * retry-with-backoff queue lock: a second concurrent "Create shipment" click
 * must fail immediately with a clear message, not silently wait and then also
 * proceed.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Creation_Lock {

	/**
	 * How long a held lock may look alive before a later caller may reclaim it.
	 *
	 * Deliberately far longer than any create can legitimately take. A single
	 * courier HTTP call is allowed up to 30s (`Speedy\Client::$timeout`), and a
	 * create is several calls (validate → create → read back), so the previous
	 * 30s threshold could be crossed by a shipment that was still perfectly
	 * alive at the courier — and reclaiming that lock is precisely how a second
	 * request reaches `create_label()` and duplicates a real shipment. The cost
	 * of the longer window is that a lock orphaned by a PHP fatal blocks the
	 * button for this long, which is recoverable; a duplicate shipment is not.
	 */
	const STALE_AFTER_SECONDS = 180;

	/**
	 * Optional injectable storage adapter for tests. Keys, all optional:
	 * `insert_if_absent(key,owner):bool`, `read_owner(key):string`,
	 * `replace_owner(key,expected,new):bool`, `delete_if_owned(key,owner):bool`.
	 *
	 * The seam sits underneath the decision logic rather than over it, so
	 * acquire()'s real behaviour (single attempt, staleness, hand-over) is what
	 * gets tested; a fake only has to reproduce the storage semantics.
	 *
	 * @var array<string,callable>
	 */
	private $adapter;

	/**
	 * @param array<string,callable> $adapter Test seam, see class docblock.
	 */
	public function __construct( array $adapter = array() ) {
		$this->adapter = $adapter;
	}

	/**
	 * Attempt to acquire the lock. Single attempt — never blocks/retries; a
	 * held, non-stale lock means "someone else is already creating this
	 * shipment", which must surface to the admin immediately, not queue.
	 *
	 * @param string $lock_key Unique lock key, e.g. 'bgcs3_create_lock_123'.
	 * @param int    $stale_after_seconds A lock older than this is presumed
	 *               abandoned (e.g. PHP fatal before release) and reclaimed.
	 * @return string|false Owner token on success, false if already held.
	 */
	public function acquire( $lock_key, $stale_after_seconds = self::STALE_AFTER_SECONDS ) {
		$owner               = $this->new_owner();
		$stale_after_seconds = max( 1, (int) $stale_after_seconds );

		if ( $this->insert_if_absent( $lock_key, $owner ) ) {
			return $owner;
		}

		$held_owner = $this->read_owner( $lock_key );
		if ( '' === $held_owner ) {
			// The holder released between our INSERT and this read. Report the
			// lock as held rather than trying again: "fail immediately" is the
			// contract here, and the admin's retry is one click away.
			return false;
		}

		if ( ! $this->is_stale( $held_owner, $stale_after_seconds ) ) {
			return false;
		}

		// Hand a stale lock over with a conditional UPDATE rather than
		// delete-then-insert: the row is never momentarily absent, and only the
		// caller whose UPDATE matched the stale token takes it. Owner tokens are
		// unique, so a matched row always changes value — affected_rows is 1.
		return $this->replace_owner( $lock_key, $held_owner, $owner ) ? $owner : false;
	}

	/**
	 * Release a lock previously acquired with the same owner token. A no-op if
	 * $owner doesn't match the current holder (e.g. it was already reclaimed
	 * as stale) — never releases a lock this caller doesn't actually own.
	 *
	 * @param string $lock_key Lock key.
	 * @param string $owner    Owner token returned by acquire().
	 * @return void
	 */
	public function release( $lock_key, $owner ) {
		$this->delete_if_owned( $lock_key, $owner );
	}

	/**
	 * @param string $held_owner          Owner token currently in the row.
	 * @param int    $stale_after_seconds Threshold in seconds.
	 * @return bool
	 */
	private function is_stale( $held_owner, $stale_after_seconds ) {
		$parts   = explode( ':', $held_owner, 2 );
		$held_at = (int) $parts[0];
		return $held_at > 0 && ( time() - $held_at ) > $stale_after_seconds;
	}

	/**
	 * Create the lock row only if no row with this key exists. The UNIQUE index
	 * on `option_name` does the deciding; IGNORE turns its duplicate-key error
	 * into "0 rows affected" instead of the overwrite `add_option()` performs.
	 *
	 * @param string $lock_key Lock key.
	 * @param string $owner    Owner token.
	 * @return bool True only when this caller created the row.
	 */
	private function insert_if_absent( $lock_key, $owner ) {
		if ( isset( $this->adapter['insert_if_absent'] ) ) {
			return (bool) call_user_func( $this->adapter['insert_if_absent'], $lock_key, $owner );
		}

		global $wpdb;

		// autoload 'no' — excluded from `wp_load_alloptions()` on every WP
		// version, including the 6.6+ 'on'/'off' vocabulary.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$lock_key,
				$owner
			)
		);

		$this->flush_option_cache( $lock_key );

		return 1 === (int) $affected;
	}

	/**
	 * Read the current holder straight from the table. `get_option()` is served
	 * by the object cache, which is exactly what must not be trusted while
	 * deciding whether another request holds this lock.
	 *
	 * @param string $lock_key Lock key.
	 * @return string Owner token, or '' when no row exists.
	 */
	private function read_owner( $lock_key ) {
		if ( isset( $this->adapter['read_owner'] ) ) {
			return (string) call_user_func( $this->adapter['read_owner'], $lock_key );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $lock_key )
		);

		return ( null === $value ) ? '' : (string) $value;
	}

	/**
	 * Conditional hand-over of a stale lock — succeeds for exactly one of any
	 * number of concurrent reclaimers, because only one UPDATE can match the
	 * stale token before it is replaced.
	 *
	 * @param string $lock_key       Lock key.
	 * @param string $expected_owner Token the row must still hold.
	 * @param string $new_owner      Token to install.
	 * @return bool
	 */
	private function replace_owner( $lock_key, $expected_owner, $new_owner ) {
		if ( isset( $this->adapter['replace_owner'] ) ) {
			return (bool) call_user_func( $this->adapter['replace_owner'], $lock_key, $expected_owner, $new_owner );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_owner,
				$lock_key,
				$expected_owner
			)
		);

		$this->flush_option_cache( $lock_key );

		return 1 === (int) $affected;
	}

	/**
	 * @param string $lock_key Lock key.
	 * @param string $owner    Owner token.
	 * @return bool
	 */
	private function delete_if_owned( $lock_key, $owner ) {
		if ( '' === $owner ) {
			return false;
		}
		if ( isset( $this->adapter['delete_if_owned'] ) ) {
			return (bool) call_user_func( $this->adapter['delete_if_owned'], $lock_key, $owner );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => $lock_key, 'option_value' => $owner ), array( '%s', '%s' ) );

		$this->flush_option_cache( $lock_key );

		return (bool) $deleted;
	}

	/**
	 * Keep the options object cache from contradicting the row just written.
	 * The lock row is never autoloaded, so `alloptions` is not involved; the
	 * `notoptions` bucket is, because it remembers "this option does not exist"
	 * and would otherwise outlive the INSERT.
	 *
	 * @param string $lock_key Lock key.
	 * @return void
	 */
	private function flush_option_cache( $lock_key ) {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( $lock_key, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $lock_key ] ) ) {
			unset( $notoptions[ $lock_key ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * @return string "<unix time>:<random>" — the timestamp prefix lets a
	 *                later acquire() attempt detect and reclaim a stale lock.
	 */
	private function new_owner() {
		$random = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
		return time() . ':' . $random;
	}
}
