<?php
/**
 * Global helper functions (prefixed bgcs3_).
 *
 * @package BgCommerce3
 */

use BgCommerce3\Plugin;
use BgCommerce3\Support\Options;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bgcs3' ) ) {
	/**
	 * Main plugin accessor.
	 *
	 * @return Plugin
	 */
	function bgcs3() {
		return Plugin::instance();
	}
}

if ( ! function_exists( 'bgcs3_get_option' ) ) {
	/**
	 * Read a grouped plugin option.
	 *
	 * Options are stored as arrays under `bgcs3_{group}`.
	 *
	 * @param string      $group   Option group (e.g. module id or 'checkout').
	 * @param string|null $key     Key inside the group, or null for the whole group.
	 * @param mixed       $default Default when missing.
	 * @return mixed
	 */
	function bgcs3_get_option( $group, $key = null, $default = null ) {
		return Options::get( $group, $key, $default );
	}
}

if ( ! function_exists( 'bgcs3_set_option' ) ) {
	/**
	 * Write a value into a grouped plugin option.
	 *
	 * @param string $group Option group.
	 * @param string $key   Key inside the group.
	 * @param mixed  $value Value to store.
	 */
	function bgcs3_set_option( $group, $key, $value ) {
		Options::set( $group, $key, $value );
	}
}

if ( ! function_exists( 'bgcs3_substr' ) ) {
	/**
	 * Multibyte-safe string substring with true UTF-8 fallback.
	 *
	 * @param string   $string The input string.
	 * @param int      $start  Start position in characters.
	 * @param int|null $length Length in characters.
	 * @return string
	 */
	function bgcs3_substr( $string, $start, $length = null ) {
		if ( function_exists( 'mb_substr' ) ) {
			return ( null === $length ) ? mb_substr( (string) $string, $start, null, 'UTF-8' ) : mb_substr( (string) $string, $start, $length, 'UTF-8' );
		}
		if ( function_exists( 'iconv_substr' ) ) {
			$res = ( null === $length ) ? @iconv_substr( (string) $string, $start, iconv_strlen( (string) $string, 'UTF-8' ), 'UTF-8' ) : @iconv_substr( (string) $string, $start, $length, 'UTF-8' );
			if ( false !== $res ) {
				return $res;
			}
		}
		// Safe UTF-8 character splitting fallback without multibyte extension
		$chars = preg_split( '//u', (string) $string, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars || empty( $chars ) ) {
			return ( null === $length ) ? substr( (string) $string, $start ) : substr( (string) $string, $start, $length );
		}
		$slice = ( null === $length ) ? array_slice( $chars, $start ) : array_slice( $chars, $start, $length );
		return implode( '', $slice );
	}
}

if ( ! function_exists( 'bgcs3_strlen' ) ) {
	/**
	 * Multibyte-safe string length with true UTF-8 fallback.
	 *
	 * @param string $string The input string.
	 * @return int
	 */
	function bgcs3_strlen( $string ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( (string) $string, 'UTF-8' );
		}
		if ( function_exists( 'iconv_strlen' ) ) {
			$len = @iconv_strlen( (string) $string, 'UTF-8' );
			if ( false !== $len ) {
				return $len;
			}
		}
		$count = preg_match_all( '/./us', (string) $string, $m );
		return false !== $count ? $count : strlen( (string) $string );
	}
}

if ( ! function_exists( 'bgcs3_strtolower' ) ) {
	/**
	 * Multibyte-safe string to lowercase with Cyrillic & UTF-8 fallback.
	 *
	 * @param string $string The input string.
	 * @return string
	 */
	function bgcs3_strtolower( $string ) {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( (string) $string, 'UTF-8' );
		}
		$cyr_upper = array( 'А','Б','В','Г','Д','Е','Ж','З','И','Й','К','Л','М','Н','О','П','Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ь','Ю','Я' );
		$cyr_lower = array( 'а','б','в','г','д','е','ж','з','и','й','к','л','м','н','о','п','р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ь','ю','я' );
		return strtolower( str_replace( $cyr_upper, $cyr_lower, (string) $string ) );
	}
}

if ( ! function_exists( 'bgcs3_strtoupper' ) ) {
	/**
	 * Multibyte-safe string to uppercase with Cyrillic & UTF-8 fallback.
	 *
	 * @param string $string The input string.
	 * @return string
	 */
	function bgcs3_strtoupper( $string ) {
		if ( function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( (string) $string, 'UTF-8' );
		}
		$cyr_upper = array( 'А','Б','В','Г','Д','Е','Ж','З','И','Й','К','Л','М','Н','О','П','Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ь','Ю','Я' );
		$cyr_lower = array( 'а','б','в','г','д','е','ж','з','и','й','к','л','м','н','о','п','р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ь','ю','я' );
		return strtoupper( str_replace( $cyr_lower, $cyr_upper, (string) $string ) );
	}
}

if ( ! function_exists( 'bgcs3_strpos' ) ) {
	/**
	 * Multibyte-safe string position search.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @param int    $offset   Offset.
	 * @return int|false
	 */
	function bgcs3_strpos( $haystack, $needle, $offset = 0 ) {
		if ( function_exists( 'mb_strpos' ) ) {
			return mb_strpos( (string) $haystack, (string) $needle, $offset, 'UTF-8' );
		}
		if ( function_exists( 'iconv_strpos' ) ) {
			return @iconv_strpos( (string) $haystack, (string) $needle, $offset, 'UTF-8' );
		}
		$haystack = (string) $haystack;
		$needle   = (string) $needle;
		if ( '' === $needle ) {
			return false;
		}

		// Pure-PHP UTF-8 fallback. Do not pass a character offset to byte-based
		// strpos(): for Cyrillic that can start in the middle of a code point.
		$hay_chars    = preg_split( '//u', $haystack, -1, PREG_SPLIT_NO_EMPTY );
		$needle_chars = preg_split( '//u', $needle, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $hay_chars || false === $needle_chars || empty( $needle_chars ) ) {
			return strpos( $haystack, $needle, max( 0, (int) $offset ) );
		}

		$hay_count    = count( $hay_chars );
		$needle_count = count( $needle_chars );
		$start        = (int) $offset;
		if ( $start < 0 ) {
			$start = max( 0, $hay_count + $start );
		}

		for ( $i = $start; $i <= $hay_count - $needle_count; $i++ ) {
			if ( $needle_chars === array_slice( $hay_chars, $i, $needle_count ) ) {
				return $i;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'bgcs3_stripos' ) ) {
	/**
	 * Case-insensitive multibyte string position search.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @param int    $offset   Offset.
	 * @return int|false
	 */
	function bgcs3_stripos( $haystack, $needle, $offset = 0 ) {
		if ( function_exists( 'mb_stripos' ) ) {
			return mb_stripos( (string) $haystack, (string) $needle, $offset, 'UTF-8' );
		}
		return bgcs3_strpos( bgcs3_strtolower( $haystack ), bgcs3_strtolower( $needle ), $offset );
	}
}

