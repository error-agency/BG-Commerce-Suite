<?php
namespace BgCommerce3\Modules\Shipping\Econt;

defined( 'ABSPATH' ) || exit;

/**
 * Explicit map between the two Econt vocabularies that BGCS-AUDIT-017 found
 * being compared as if they were one.
 *
 * They are not the same dictionary:
 *
 *   `label.shipmentType`   — what KIND OF SHIPMENT is being sent. BGCS sends one
 *                            of: document, pack, pallet, cargo, documentpallet,
 *                            big_letter, small_letter (`Label_Builder::shipment_type()`).
 *
 *   `Office.shipmentTypes` — what an office in the nomenclature carries. Measured
 *                            against the live Econt demo nomenclature on
 *                            2026-08-26 across 572 synced offices, the entire
 *                            observed vocabulary is:
 *                              courier (568) · cargo (566) · post (565) · pallet (223)
 *
 * The previous guard did `in_array( $shipment_type, $office_types )` across those
 * two lists. `pack` — the default for ordinary goods — appears in 0 of 572
 * offices, so once a locations sync populated the field, every ordinary office
 * delivery was refused by BGCS before it ever reached Econt.
 *
 * ## What is actually documented
 *
 * `cargo` and `pallet` are the only values that exist verbatim in BOTH
 * vocabularies, and they are the only correspondences this class enforces.
 *
 * `pack` for an office delivery is documented as WORKING, which is why nothing
 * in the ordinary-goods path is validated here any more. Econt's own official
 * Postman collection (`docs/EEcont.postman_collection.json`,
 * Shipments → LabelService.createLabels.json → saved "createLabels example")
 * contains a recorded live call:
 *
 *     request : senderOfficeCode "8800", receiverOfficeCode "7029",
 *               shipmentType "PACK"
 *     response: HTTP 200, shipmentNumber 1051604056183, totalPrice 4.13 EUR
 *
 * i.e. Econt itself created an office→office shipment with shipmentType `pack`.
 *
 * ## What is NOT documented
 *
 * What `courier` and `post` mean on an office, and which `label.shipmentType`
 * values they gate — if any. {@see UNCONFIRMED} records the reading the data
 * suggests, but nothing enforces it: a guard that refuses a combination Econt
 * would have accepted is the defect this class exists to undo. When Econt
 * confirms the semantics, move entries from UNCONFIRMED to CONFIRMED and note
 * the source below.
 *
 * @package BgCommerce3
 */

final class Shipment_Type_Map {

	/**
	 * Enforced correspondences: `label.shipmentType` => office capability that
	 * must be present in `Office.shipmentTypes`.
	 *
	 * Only entries whose correspondence is documented belong here. Today that
	 * means the literal overlap between the two vocabularies.
	 *
	 * @var array<string,string>
	 */
	const CONFIRMED = array(
		'cargo'          => 'cargo',
		'pallet'         => 'pallet',
		'documentpallet' => 'pallet',
	);

	/**
	 * The reading the nomenclature suggests but Econt has not confirmed.
	 * Recorded so the open question stays visible and so switching it on is a
	 * one-line change — NOT consulted when deciding whether to refuse.
	 *
	 * @var array<string,string>
	 */
	const UNCONFIRMED = array(
		'document'     => 'courier',
		'pack'         => 'courier',
		'big_letter'   => 'courier',
		'small_letter' => 'courier',
	);

	/**
	 * The office-side vocabulary this class recognises, as observed in the live
	 * nomenclature. If an office lists none of these, Econt has changed the
	 * vocabulary and no comparison here is meaningful — which is precisely the
	 * situation that produced BGCS-AUDIT-017, so it must resolve to "unknown"
	 * rather than to "refuse".
	 *
	 * @var string[]
	 */
	const KNOWN_OFFICE_CAPABILITIES = array( 'courier', 'cargo', 'post', 'pallet' );

	/**
	 * Decide whether an office accepts a shipment type.
	 *
	 * @param array<int,mixed> $office_types   Raw `shipment_types` from the synced office row.
	 * @param string           $shipment_type  Resolved `label.shipmentType`.
	 * @return bool|null True = accepted, false = refused, null = undecidable
	 *                   (the caller must then let the shipment through and leave
	 *                   the decision to Econt).
	 */
	public static function office_accepts( $office_types, $shipment_type ) {
		$shipment_type = strtolower( trim( (string) $shipment_type ) );

		if ( ! isset( self::CONFIRMED[ $shipment_type ] ) ) {
			// Either an ordinary-goods type, whose office-side counterpart is not
			// documented, or a type this map has not been taught. Not ours to refuse.
			return null;
		}

		$capabilities = self::normalize( $office_types );
		if ( array() === $capabilities ) {
			// Office not synced, or synced before the field existed.
			return null;
		}

		if ( array() === array_intersect( $capabilities, self::KNOWN_OFFICE_CAPABILITIES ) ) {
			// Unrecognised office vocabulary — do not turn that into a refusal.
			return null;
		}

		return in_array( self::CONFIRMED[ $shipment_type ], $capabilities, true );
	}

	/**
	 * @param array<int,mixed> $office_types Raw `shipment_types`.
	 * @return string[] Lower-cased, non-empty capability strings.
	 */
	private static function normalize( $office_types ) {
		if ( ! is_array( $office_types ) ) {
			return array();
		}

		$out = array();
		foreach ( $office_types as $type ) {
			if ( ! is_scalar( $type ) ) {
				continue;
			}
			$type = strtolower( trim( (string) $type ) );
			if ( '' !== $type ) {
				$out[] = $type;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
