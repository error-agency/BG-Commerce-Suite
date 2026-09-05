<?php
/**
 * Sender warehouses and the pickup contact for each of them.
 *
 * BOX NOW's `/origins` endpoint returns a warehouse's id, name and address but
 * NO contact details, so who the courier calls on pickup has to come from the
 * merchant. A shop with one warehouse states it once in the settings; a shop
 * that ships from several needs one contact per warehouse, or picking another
 * origin for an order sends the courier to the right building with the wrong
 * person's phone number.
 *
 * @package BgCommerce3\BoxNow
 */

namespace BgCommerce3\Modules\Shipping\BoxNow;

defined( 'ABSPATH' ) || exit;

class Warehouses {

	/**
	 * Normalizes the stored rows: a row without a warehouse id addresses
	 * nothing and is dropped, and the last row for a given id wins so a
	 * duplicated warehouse cannot make the contact depend on array order.
	 *
	 * @param mixed $raw Raw rows from the settings form.
	 * @return array<int,array<string,string>>
	 */
	public static function sanitize_rows( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id = isset( $row['id'] ) ? trim( (string) $row['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}

			$rows[ $id ] = array(
				'id'    => $id,
				'name'  => isset( $row['name'] ) ? trim( (string) $row['name'] ) : '',
				'phone' => isset( $row['phone'] ) ? trim( (string) $row['phone'] ) : '',
				'email' => isset( $row['email'] ) ? trim( (string) $row['email'] ) : '',
			);
		}

		return array_values( $rows );
	}

	/**
	 * The pickup contact for one warehouse.
	 *
	 * Each field falls back to the shop-wide sender on its own, so a row that
	 * only overrides the phone keeps the shop's name and e-mail rather than
	 * blanking them — BOX NOW rejects a delivery request with an empty origin
	 * contact.
	 *
	 * @param string                          $warehouse_id Resolved origin id.
	 * @param array<int,array<string,string>> $rows         Sanitized rows.
	 * @param array<string,string>            $fallback     Shop-wide sender (name/phone/email).
	 * @return array<string,string>
	 */
	public static function contact_for( $warehouse_id, array $rows, array $fallback ) {
		$contact = array(
			'name'  => isset( $fallback['name'] ) ? (string) $fallback['name'] : '',
			'phone' => isset( $fallback['phone'] ) ? (string) $fallback['phone'] : '',
			'email' => isset( $fallback['email'] ) ? (string) $fallback['email'] : '',
		);

		$warehouse_id = trim( (string) $warehouse_id );
		if ( '' === $warehouse_id ) {
			return $contact;
		}

		foreach ( $rows as $row ) {
			if ( ! isset( $row['id'] ) || (string) $row['id'] !== $warehouse_id ) {
				continue;
			}

			foreach ( array( 'name', 'phone', 'email' ) as $field ) {
				if ( isset( $row[ $field ] ) && '' !== trim( (string) $row[ $field ] ) ) {
					$contact[ $field ] = trim( (string) $row[ $field ] );
				}
			}

			break;
		}

		return $contact;
	}
}
