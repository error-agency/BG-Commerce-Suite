<?php
/**
 * Generic "is this courier ready for real orders" status framework
 * (Master Instruction §9). A courier module implements setup_status() and
 * returns a list of Setup_Status::row() entries; Settings_Page renders them
 * as a checklist above the settings cards. Each row must back a real check —
 * this is not a decorative/static checklist.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

class Setup_Status {

	const STATE_OK   = 'ok';
	const STATE_WARN = 'warn';
	const STATE_FAIL = 'fail';

	/**
	 * Build one status row.
	 *
	 * @param string $id    Stable row id (e.g. 'api', 'sender').
	 * @param string $label Merchant-facing label (e.g. „API връзка“).
	 * @param string $state self::STATE_OK|STATE_WARN|STATE_FAIL.
	 * @param string $hint  Optional short explanation shown when not OK.
	 * @return array{id:string,label:string,state:string,hint:string}
	 */
	public static function row( $id, $label, $state, $hint = '' ) {
		if ( ! in_array( $state, array( self::STATE_OK, self::STATE_WARN, self::STATE_FAIL ), true ) ) {
			$state = self::STATE_FAIL;
		}

		return array(
			'id'    => (string) $id,
			'label' => (string) $label,
			'state' => $state,
			'hint'  => (string) $hint,
		);
	}

	/**
	 * A courier is considered ready when no row has failed. A warning row
	 * (e.g. locations not yet synced but a valid old snapshot exists) does not
	 * block readiness on its own.
	 *
	 * @param array<int,array{id:string,label:string,state:string,hint:string}> $rows Rows.
	 * @return bool
	 */
	public static function is_ready( array $rows ) {
		foreach ( $rows as $row ) {
			if ( self::STATE_FAIL === ( isset( $row['state'] ) ? $row['state'] : self::STATE_FAIL ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether every readiness check is fully complete. Unlike is_ready(), a
	 * warning keeps the setup assistant visible so the merchant can finish the
	 * recommended configuration.
	 *
	 * @param array<int,array{id:string,label:string,state:string,hint:string}> $rows Rows.
	 * @return bool
	 */
	public static function all_ok( array $rows ) {
		if ( empty( $rows ) ) {
			return false;
		}

		foreach ( $rows as $row ) {
			if ( self::STATE_OK !== ( isset( $row['state'] ) ? $row['state'] : self::STATE_FAIL ) ) {
				return false;
			}
		}

		return true;
	}
}
