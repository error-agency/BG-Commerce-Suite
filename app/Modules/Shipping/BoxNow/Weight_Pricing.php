<?php
/**
 * Normalization and resolution for BOX NOW weight-based price ranges.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\BoxNow;

defined( 'ABSPATH' ) || exit;

final class Weight_Pricing {

	/**
	 * Normalize merchant-entered ranges and discard invalid or overlapping rows.
	 * Adjacent ranges are allowed (for example 0–3 and 3–10 kg). An empty upper
	 * bound is open-ended and therefore must be the final usable row.
	 *
	 * @param array<int,array<string,mixed>> $rows Raw range rows.
	 * @return array<int,array{min:float,max:float|string,price:float}>
	 */
	public static function sanitize_ranges( array $rows ) {
		$normalized = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$min       = self::number( isset( $row['min'] ) ? $row['min'] : '' );
			$max_raw   = isset( $row['max'] ) ? trim( (string) $row['max'] ) : '';
			$max       = '' === $max_raw ? '' : self::number( $max_raw );
			$price     = self::number( isset( $row['price'] ) ? $row['price'] : '' );

			if ( null === $min || $min < 0 || null === $price || $price <= 0 ) {
				continue;
			}

			if ( '' !== $max && ( null === $max || $max <= $min ) ) {
				continue;
			}

			$normalized[] = array(
				'min'   => round( $min, 3 ),
				'max'   => '' === $max ? '' : round( $max, 3 ),
				'price' => round( $price, 2 ),
			);
		}

		usort(
			$normalized,
			static function ( $left, $right ) {
				if ( $left['min'] === $right['min'] ) {
					return 0;
				}
				return ( $left['min'] < $right['min'] ) ? -1 : 1;
			}
		);

		$clean        = array();
		$previous_max = null;

		foreach ( $normalized as $range ) {
			if ( '' === $previous_max ) {
				break;
			}

			if ( null !== $previous_max && $range['min'] < $previous_max ) {
				continue;
			}

			$clean[]      = $range;
			$previous_max = $range['max'];
		}

		return array_values( $clean );
	}

	/**
	 * Resolve a price for a package weight in kilograms.
	 *
	 * Shared boundaries belong to the following range: 0–3 and 3–10 means
	 * exactly 3 kg uses the second row. A closed final range includes its upper
	 * boundary; an empty upper bound has no maximum.
	 *
	 * @param float                                 $weight Weight in kilograms.
	 * @param array<int,array<string,mixed>>        $rows   Raw or normalized rows.
	 * @return float|null Price, or null when no range covers the weight.
	 */
	public static function resolve( $weight, array $rows ) {
		$ranges = self::sanitize_ranges( $rows );
		$count  = count( $ranges );
		$weight = (float) $weight;

		foreach ( $ranges as $index => $range ) {
			if ( $weight < $range['min'] ) {
				continue;
			}

			if ( '' === $range['max'] ) {
				return $range['price'];
			}

			$next_min        = ( $index + 1 < $count ) ? $ranges[ $index + 1 ]['min'] : null;
			$upper_inclusive = null === $next_min || $next_min > $range['max'];

			if ( $weight < $range['max'] || ( $upper_inclusive && $weight <= $range['max'] ) ) {
				return $range['price'];
			}
		}

		return null;
	}

	/**
	 * Parse a localized decimal value.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	private static function number( $value ) {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		return is_numeric( $value ) ? (float) $value : null;
	}
}
