<?php
/**
 * Single resolution point for a module setting and its default.
 *
 * BGCS-AUDIT-003 / -005 / -016: `settings_fields()` declares a `'default'` for
 * every field, and the admin panel renders that value — but each runtime reader
 * repeated a default of its own as the third argument to `bgcs3_get_option()`.
 * Three of those copies had drifted:
 *
 *   speedy.service_payer      declared SENDER    · order snapshot read RECIPIENT
 *   speedy.cod_pmt_fee_payer  declared SENDER    · both pricing paths read RECIPIENT
 *   <courier>.default_weight  declared 1 kg      · Econt courier-request read 0.01 kg
 *
 * A missing key is not hypothetical: `Settings_Page::handle_save()` deliberately
 * skips fields outside the current task scope and fields whose `show_if` parent
 * is inactive, so a legitimately-saved install can lack a key entirely. The
 * panel then shows one value and the courier payload uses another.
 *
 * The fix is to stop repeating the default at all. `Module_Settings::get()`
 * returns the stored value when the key exists and otherwise the default the
 * field itself declares — the same array the settings screen renders from, so
 * panel, payload and order snapshot cannot disagree.
 *
 * ## Cost
 *
 * `settings_fields()` is not cheap — Econt and Speedy build API-fed dropdowns
 * from cached nomenclature — so it is never called on the hot path: the stored
 * value is read first, and the field set is only built when a key is actually
 * missing. The result is memoized per request, so a fresh install pays for one
 * build per module, not one per read.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

use BgCommerce3\Module\Module_Interface;
use BgCommerce3\Modules\Shipping\Courier_Interface;

defined( 'ABSPATH' ) || exit;

final class Module_Settings {

	/**
	 * Effective field definitions per module id, memoized for this request.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	private static $fields = array();

	/**
	 * Module ids whose field set is currently being built.
	 *
	 * @var array<string,bool>
	 */
	private static $building = array();

	/**
	 * The effective field set for a module: its own `settings_fields()` plus the
	 * per-courier fields Core injects into every shipping module.
	 *
	 * This is the same composition the settings screen renders and saves from —
	 * `Settings_Page::module_fields()` delegates here so the two can never drift.
	 *
	 * @param string|Module_Interface $module Module id or instance.
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields( $module ) {
		$module_id = ( $module instanceof Module_Interface ) ? (string) $module->id() : (string) $module;

		if ( isset( self::$fields[ $module_id ] ) ) {
			return self::$fields[ $module_id ];
		}

		// Re-entrancy guard. `settings_fields()` reads options itself — Econt
		// resolves the selected profile before building its API-fed dropdowns —
		// so a nested lookup for the module being built must not recurse into it.
		// Returning an empty set makes such a lookup fall back to the caller's
		// own fallback, exactly as it behaved before this class existed.
		if ( isset( self::$building[ $module_id ] ) ) {
			return array();
		}

		self::$building[ $module_id ] = true;
		try {
			self::$fields[ $module_id ] = self::build( $module instanceof Module_Interface ? $module : self::module( $module_id ) );
		} finally {
			unset( self::$building[ $module_id ] );
		}

		return self::$fields[ $module_id ];
	}

	/**
	 * The default a field declares for itself.
	 *
	 * @param string $module_id Module id / option group.
	 * @param string $key       Setting key.
	 * @param mixed  $fallback  Returned when the field is unknown or declares no
	 *                          default (e.g. a module that is not registered on
	 *                          this install).
	 * @return mixed
	 */
	public static function default_for( $module_id, $key, $fallback = null ) {
		$fields = self::fields( $module_id );

		if ( isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) && array_key_exists( 'default', $fields[ $key ] ) ) {
			return $fields[ $key ]['default'];
		}

		return $fallback;
	}

	/**
	 * Read a setting, falling back to the default its field declares.
	 *
	 * Use this instead of `bgcs3_get_option( $module, $key, $literal )` for any
	 * key that has a field definition. An explicitly stored value always wins,
	 * including an empty string — the same `array_key_exists()` semantics
	 * `Options::get()` has always had.
	 *
	 * @param string $module_id Module id / option group.
	 * @param string $key       Setting key.
	 * @param mixed  $fallback  Used only when the key is missing AND the field
	 *                          declares no default.
	 * @return mixed
	 */
	public static function get( $module_id, $key, $fallback = null ) {
		$stored = Options::get( $module_id );

		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}

		return self::default_for( $module_id, $key, $fallback );
	}

	/**
	 * Whether a field for this key exists and declares a default.
	 *
	 * @param string $module_id Module id.
	 * @param string $key       Setting key.
	 * @return bool
	 */
	public static function declares_default( $module_id, $key ) {
		$fields = self::fields( $module_id );
		return isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) && array_key_exists( 'default', $fields[ $key ] );
	}

	/**
	 * Seed the field set for a module without resolving it from the registry.
	 * Used by tests, and by callers that already hold the composed array.
	 *
	 * @param string                                   $module_id Module id.
	 * @param array<string,array<string,mixed>>        $fields    Field definitions.
	 * @return void
	 */
	public static function prime( $module_id, array $fields ) {
		self::$fields[ (string) $module_id ] = $fields;
	}

	/**
	 * Drop memoized field sets. Called after a settings save, because a saved
	 * value can change which fields a module declares (Econt's dropdowns depend
	 * on the selected profile).
	 *
	 * @param string|null $module_id Module id, or null for every module.
	 * @return void
	 */
	public static function flush( $module_id = null ) {
		if ( null === $module_id ) {
			self::$fields = array();
			return;
		}
		unset( self::$fields[ (string) $module_id ] );
	}

	/**
	 * @param Module_Interface|null $module Module instance.
	 * @return array<string,array<string,mixed>>
	 */
	private static function build( $module ) {
		if ( ! $module instanceof Module_Interface ) {
			return array();
		}

		$fields = (array) $module->settings_fields();

		if ( $module instanceof Courier_Interface ) {
			$fields = array_merge(
				$fields,
				\BgCommerce3\Shipping\Pricing::fields_for( $module ),
				\BgCommerce3\Shipping\Tracking_Sync::fields_for( $module ),
				\BgCommerce3\Shipping\Cod_Payout_Sync_Settings::fields_for( $module ),
				\BgCommerce3\Shipping\Tracking_Status_Policy::fields_for( $module )
			);
		}

		return $fields;
	}

	/**
	 * @param string $module_id Module id.
	 * @return Module_Interface|null
	 */
	private static function module( $module_id ) {
		if ( ! function_exists( 'bgcs3' ) ) {
			return null;
		}

		$container = bgcs3()->container();
		if ( ! $container instanceof \ArrayAccess || ! isset( $container['modules'] ) ) {
			return null;
		}

		$module = $container['modules']->get( $module_id );

		return ( $module instanceof Module_Interface ) ? $module : null;
	}
}
