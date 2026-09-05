<?php
/**
 * Persistent office/locker store — the synced pool of each courier's
 * offices/lockers, kept in the DB (not a transient) so the checkout picker
 * loads instantly and works offline between syncs.
 *
 * Populated by each courier's sync_data() (manual "Синхронизирай" button +
 * the daily Locations_Sync cron). Read by the checkout office-search endpoint.
 *
 * One option per courier + type, e.g. `bgcs3_offices_speedy_office`, stored with
 * autoload disabled (the list can be large).
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

class Office_Store {

	/**
	 * @param string $courier_id Courier id.
	 * @param string $type       'office' | 'locker'.
	 * @return string
	 */
	private static function option( $courier_id, $type ) {
		return 'bgcs3_offices_' . sanitize_key( $courier_id ) . '_' . sanitize_key( $type );
	}

	/**
	 * @param string $courier_id Courier id.
	 * @param string $type       'office' | 'locker'.
	 * @return string
	 */
	private static function meta_option( $courier_id, $type ) {
		return self::option( $courier_id, $type ) . '_meta';
	}

	/**
	 * Save a courier's full pool for a type.
	 *
	 * @param string                         $courier_id Courier id.
	 * @param string                         $type       'office' | 'locker'.
	 * @param array<int,array<string,mixed>> $rows       Normalized office rows.
	 * @return int Number of rows stored.
	 */
	public static function save( $courier_id, $type, array $rows ) {
		$rows  = array_values( $rows );
		$count = count( $rows );

		// autoload 'no' — this can be a few hundred KB.
		update_option( self::option( $courier_id, $type ), $rows, false );
		update_option(
			self::meta_option( $courier_id, $type ),
			array(
				'time'  => time(),
				'count' => $count,
			),
			false
		);

		return $count;
	}

	/**
	 * Replace a pool only when the response contains structurally valid rows.
	 * The previous pool remains untouched on API errors and untrusted empties.
	 *
	 * @param string                         $courier_id Courier id.
	 * @param string                         $type       Pool type.
	 * @param array<int,array<string,mixed>> $rows       Normalized rows.
	 * @return int|\WP_Error
	 */
	public static function replace_if_valid( $courier_id, $type, array $rows ) {
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return is_array( $row ) && ! empty( $row['id'] ) && ( ! empty( $row['name'] ) || ! empty( $row['text'] ) );
				}
			)
		);
		$unique = array();
		foreach ( $rows as $row ) {
			$id = trim( (string) $row['id'] );
			if ( ! isset( $unique[ $id ] ) ) {
				$unique[ $id ] = $row;
			}
		}
		$rows = array_values( $unique );

		if ( empty( $rows ) ) {
			return new \WP_Error(
				'bgcs3_empty_location_pool',
				__( 'The returned directory is empty. The last valid data was preserved.', 'bg-commerce-suite' )
			);
		}

		return self::save( $courier_id, $type, $rows );
	}

	/**
	 * Презарежда пуловете на един куриер от неговия Locations обект.
	 *
	 * @param string   $courier_id Courier id.
	 * @param object   $locations  Обект с метод `all_offices( $type )`.
	 * @param string[] $types      Кои пулове да се обновят.
	 * @return array<string,int|\WP_Error> Брой записи или грешка, по тип.
	 */
	public static function replace_pools( $courier_id, $locations, array $types = array( 'office', 'locker' ) ) {
		$result = array();

		foreach ( $types as $type ) {
			$result[ $type ] = is_callable( array( $locations, 'all_offices' ) )
				? self::replace_if_valid( $courier_id, $type, (array) $locations->all_offices( $type ) )
				: new \WP_Error( 'bgcs3_no_locations_provider', __( 'The module cannot load the office directory.', 'bg-commerce-suite' ) );
		}

		return $result;
	}

	/**
	 * @param string $courier_id Courier id.
	 * @param string $type       'office' | 'locker'.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get( $courier_id, $type ) {
		$rows = get_option( self::option( $courier_id, $type ), array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param string $courier_id Courier id.
	 * @param string $type       'office' | 'locker'.
	 * @return bool
	 */
	public static function has( $courier_id, $type ) {
		$rows = self::get( $courier_id, $type );
		return ! empty( $rows );
	}

	/**
	 * @param string $courier_id Courier id.
	 * @param string $type       'office' | 'locker'.
	 * @return array{time:int,count:int}
	 */
	public static function meta( $courier_id, $type ) {
		$meta = get_option( self::meta_option( $courier_id, $type ), array() );
		return array(
			'time'  => isset( $meta['time'] ) ? (int) $meta['time'] : 0,
			'count' => isset( $meta['count'] ) ? (int) $meta['count'] : 0,
		);
	}

	/**
	 * Delete a courier's stored pools (both types). Used on add-on uninstall.
	 *
	 * @param string $courier_id Courier id.
	 */
	public static function forget( $courier_id ) {
		foreach ( array( 'office', 'locker' ) as $type ) {
			delete_option( self::option( $courier_id, $type ) );
			delete_option( self::meta_option( $courier_id, $type ) );
		}
	}

	/**
	 * Return the pool for a courier+type, fetching+storing it live on a miss so
	 * the first checkout after install still works before the first sync.
	 *
	 * @param object $locations  Courier locations provider (has all_offices()).
	 * @param string $courier_id Courier id.
	 * @param string $type       'office' | 'locker'.
	 * @return array<int,array<string,mixed>>
	 */
	public static function pool( $locations, $courier_id, $type ) {
		$rows = self::get( $courier_id, $type );
		if ( ! empty( $rows ) ) {
			return $rows;
		}

		if ( is_object( $locations ) && method_exists( $locations, 'all_offices' ) ) {
			$rows = (array) $locations->all_offices( $type );
			if ( ! empty( $rows ) ) {
				self::save( $courier_id, $type, $rows );
			}
			return $rows;
		}

		return array();
	}
}
