<?php
/**
 * Provider-neutral location identity and Bulgarian search normalization.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Location_Search {

	/** Nearby rows with an explicit different provider city ID are not same-city rows. */
	public static function matches_city_id( array $row, $city_id ) {
		$city_id     = trim( (string) $city_id );
		$row_city_id = isset( $row['city_id'] ) ? trim( (string) $row['city_id'] ) : '';
		return '' === $city_id || '' === $row_city_id || $row_city_id === $city_id;
	}

	/** Comparable Latin key for Bulgarian Cyrillic/Latin location search. */
	public static function fold( $value ) {
		$value = bgcs3_strtolower( trim( (string) $value ) );
		$value = str_replace( 'ия', 'ia', $value );
		$value = strtr(
			$value,
			array(
				'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'zh',
				'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
				'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
				'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sht', 'ъ' => 'a',
				'ь' => 'y', 'ю' => 'yu', 'я' => 'ya',
			)
		);
		return preg_replace( '/\s+/u', ' ', $value );
	}

	/** Best-effort Bulgarian query for providers that search only Cyrillic names. */
	public static function latin_to_cyrillic( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value || preg_match( '/[^a-z\s\-]/', $value ) ) {
			return '';
		}
		$value = strtr(
			$value,
			array(
				'sht' => 'щ', 'zh' => 'ж', 'ch' => 'ч', 'sh' => 'ш', 'ts' => 'ц',
				'yu' => 'ю', 'ya' => 'я', 'ia' => 'ия',
			)
		);
		return strtr(
			$value,
			array(
				'a' => 'а', 'b' => 'б', 'c' => 'к', 'd' => 'д', 'e' => 'е', 'f' => 'ф', 'g' => 'г',
				'h' => 'х', 'i' => 'и', 'j' => 'ж', 'k' => 'к', 'l' => 'л', 'm' => 'м', 'n' => 'н',
				'o' => 'о', 'p' => 'п', 'q' => 'к', 'r' => 'р', 's' => 'с', 't' => 'т', 'u' => 'у',
				'v' => 'в', 'w' => 'в', 'x' => 'кс', 'y' => 'й', 'z' => 'з',
			)
		);
	}
}
