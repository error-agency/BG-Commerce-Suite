<?php
/**
 * Courier pricing ladder + the generic per-courier settings Core injects for
 * every shipping module (so add-ons get them for free):
 *
 *  1. Free shipping per delivery type (cart subtotal threshold).
 *  2. Own prices: generic tiered static rules (only when pricing_mode = own).
 *  3. Contract/API price (the courier's quote()) — used only when pricing_mode = api.
 *
 * pricing_mode is an explicit merchant choice ("Цена от куриера" / "Собствени
 * цени", Master Instruction §10). migrate_legacy() converts a pre-existing
 * single static price per delivery type into the rules repeater exactly once;
 * resolve() itself never reads the legacy fields.
 *
 * Plus presentation (method title/description), auto order-status after a
 * waybill and a tracking email to the customer.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

class Pricing {

	const RULES_KEY    = 'pricing_rules';
	const MODE_KEY     = 'pricing_mode';
	const MODE_API     = 'api';
	const MODE_OWN     = 'own';
	const MIGRATED_KEY = '_pricing_migrated';

	/**
	 * Human labels for the delivery types.
	 *
	 * @return array<string,string>
	 */
	public static function type_labels() {
		return array(
			'office'  => __( 'To office', 'bg-commerce-suite' ),
			'locker'  => __( 'To locker (APS)', 'bg-commerce-suite' ),
			'address' => __( 'To address', 'bg-commerce-suite' ),
		);
	}

	/**
	 * Delivery types a courier can support, regardless of which ones are
	 * currently enabled in checkout. Keeping configuration for temporarily
	 * hidden delivery types prevents a toggle from deleting its saved pricing.
	 *
	 * @param Courier_Interface $module Courier module.
	 * @return string[]
	 */
	public static function supported_types( Courier_Interface $module ) {
		$types = method_exists( $module, 'supported_delivery_types' )
			? (array) $module->supported_delivery_types()
			: (array) $module->delivery_types();

		$known = array_keys( self::type_labels() );
		$types = array_values( array_unique( array_map( 'sanitize_key', $types ) ) );

		return array_values( array_intersect( $known, $types ) );
	}

	/**
	 * Sanitize the generic static-pricing repeater used by every courier.
	 *
	 * Rules are intentionally expressed as upper bounds ("up to X kg" / "up
	 * to Y order value") rather than overlapping from/to ranges. This removes
	 * boundary ambiguity: exactly 1.00 kg belongs to the <= 1.00 rule, while
	 * 1.01 kg falls through to the next matching tier.
	 *
	 * @param mixed    $rules          Raw stored/submitted rules.
	 * @param string[] $delivery_types Allowed delivery types. Empty = Core types.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_rules( $rules, array $delivery_types = array() ) {
		if ( ! is_array( $rules ) ) {
			return array();
		}

		if ( empty( $delivery_types ) ) {
			$delivery_types = array_keys( self::type_labels() );
		}
		$delivery_types = array_values( array_unique( array_map( 'sanitize_key', $delivery_types ) ) );

		$out = array();
		foreach ( array_values( $rules ) as $position => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$enabled = ! isset( $rule['enabled'] ) || in_array( (string) $rule['enabled'], array( 'yes', '1', 'true' ), true );
			if ( ! $enabled ) {
				continue;
			}

			$type = isset( $rule['type'] ) ? sanitize_key( (string) $rule['type'] ) : '';
			if ( ! in_array( $type, $delivery_types, true ) ) {
				continue;
			}

			$price_raw = isset( $rule['price'] ) ? str_replace( ',', '.', trim( (string) $rule['price'] ) ) : '';
			if ( '' === $price_raw || ! is_numeric( $price_raw ) || (float) $price_raw < 0 ) {
				continue;
			}

			$weight_raw = isset( $rule['max_weight'] ) ? str_replace( ',', '.', trim( (string) $rule['max_weight'] ) ) : '';
			$total_raw  = isset( $rule['max_order_total'] ) ? str_replace( ',', '.', trim( (string) $rule['max_order_total'] ) ) : '';
			$currency   = isset( $rule['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $rule['currency'] ) ) : '';

			$max_weight = is_numeric( $weight_raw ) ? max( 0.0, (float) $weight_raw ) : 0.0;
			$max_total  = is_numeric( $total_raw ) ? max( 0.0, (float) $total_raw ) : 0.0;
			if ( '' !== $currency && 3 !== strlen( $currency ) ) {
				$currency = '';
			}

			$id = isset( $rule['id'] ) ? sanitize_key( (string) $rule['id'] ) : '';
			if ( '' === $id ) {
				$id = 'rule-' . ( $position + 1 );
			}

			$out[] = array(
				'id'              => $id,
				'enabled'         => 'yes',
				'type'            => $type,
				'max_weight'      => $max_weight,
				'max_order_total' => $max_total,
				'price'           => (float) $price_raw,
				'currency'        => $currency,
				'_position'       => (int) $position,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				$a_weight = $a['max_weight'] > 0 ? (float) $a['max_weight'] : PHP_FLOAT_MAX;
				$b_weight = $b['max_weight'] > 0 ? (float) $b['max_weight'] : PHP_FLOAT_MAX;
				if ( $a_weight !== $b_weight ) {
					return $a_weight < $b_weight ? -1 : 1;
				}

				$a_total = $a['max_order_total'] > 0 ? (float) $a['max_order_total'] : PHP_FLOAT_MAX;
				$b_total = $b['max_order_total'] > 0 ? (float) $b['max_order_total'] : PHP_FLOAT_MAX;
				if ( $a_total !== $b_total ) {
					return $a_total < $b_total ? -1 : 1;
				}

				return (int) $a['_position'] <=> (int) $b['_position'];
			}
		);

		foreach ( $out as &$rule ) {
			unset( $rule['_position'] );
		}
		unset( $rule );

		return $out;
	}

	/**
	 * Validate raw (as-submitted, pre-sanitize) pricing rules and return
	 * Bulgarian error messages for anything the admin must fix before saving
	 * (Master Instruction §39, §40). Unlike sanitize_rules(), which silently
	 * drops malformed rows so runtime pricing stays safe, this is the save-time
	 * gate: invalid own-prices configuration must never be persisted quietly.
	 *
	 * Only rows the admin left enabled are checked — a disabled row is not
	 * live configuration yet.
	 *
	 * @param array<int,array<string,mixed>> $rules Raw submitted rules (e.g. $_POST shape).
	 * @return string[] Bulgarian error messages; empty = valid.
	 */
	public static function validate_rules( array $rules ) {
		$errors = array();
		$seen   = array();

		foreach ( array_values( $rules ) as $position => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$enabled = ! isset( $rule['enabled'] ) || in_array( (string) $rule['enabled'], array( 'yes', '1', 'true' ), true );
			if ( ! $enabled ) {
				continue;
			}

			/* translators: %d: 1-based row number in the pricing-rules repeater. */
			$row_label = sprintf( __( 'Row %d', 'bg-commerce-suite' ), $position + 1 );
			$type      = isset( $rule['type'] ) ? sanitize_key( (string) $rule['type'] ) : '';

			$price_raw = isset( $rule['price'] ) ? str_replace( ',', '.', trim( (string) $rule['price'] ) ) : '';
			if ( '' === $price_raw || ! is_numeric( $price_raw ) ) {
				/* translators: %s: row label, e.g. "Ред 1". */
				$errors[] = sprintf( __( '%s: a valid price is missing.', 'bg-commerce-suite' ), $row_label );
			} elseif ( (float) $price_raw < 0 ) {
				/* translators: %s: row label. */
				$errors[] = sprintf( __( '%s: the price cannot be negative.', 'bg-commerce-suite' ), $row_label );
			}

			$weight_raw = isset( $rule['max_weight'] ) ? str_replace( ',', '.', trim( (string) $rule['max_weight'] ) ) : '';
			if ( '' !== $weight_raw && is_numeric( $weight_raw ) && (float) $weight_raw < 0 ) {
				/* translators: %s: row label. */
				$errors[] = sprintf( __( '%s: the maximum weight cannot be negative.', 'bg-commerce-suite' ), $row_label );
			}

			$total_raw = isset( $rule['max_order_total'] ) ? str_replace( ',', '.', trim( (string) $rule['max_order_total'] ) ) : '';
			if ( '' !== $total_raw && is_numeric( $total_raw ) && (float) $total_raw < 0 ) {
				/* translators: %s: row label. */
				$errors[] = sprintf( __( '%s: the maximum goods value cannot be negative.', 'bg-commerce-suite' ), $row_label );
			}

			$currency = isset( $rule['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', trim( (string) $rule['currency'] ) ) ) : '';
			if ( '' !== $currency && 3 !== strlen( $currency ) ) {
				/* translators: %s: row label. */
				$errors[] = sprintf( __( '%s: the currency code must be 3 letters (for example, EUR).', 'bg-commerce-suite' ), $row_label );
			}

			// Duplicate/overlapping criteria: same type + max weight + max total +
			// currency is genuinely ambiguous — resolve() can never tell them apart.
			$max_weight = is_numeric( $weight_raw ) ? max( 0.0, (float) $weight_raw ) : 0.0;
			$max_total  = is_numeric( $total_raw ) ? max( 0.0, (float) $total_raw ) : 0.0;
			$dup_key    = $type . '|' . number_format( $max_weight, 2, '.', '' ) . '|' . number_format( $max_total, 2, '.', '' ) . '|' . $currency;

			if ( '' !== $type && isset( $seen[ $dup_key ] ) ) {
				$errors[] = sprintf(
					/* translators: 1: row label, 2: 1-based row number of the earlier conflicting rule. */
					__( '%1$s: a rule with the same type, weight, value and currency is already defined on row %2$d.', 'bg-commerce-suite' ),
					$row_label,
					$seen[ $dup_key ] + 1
				);
			} else {
				$seen[ $dup_key ] = $position;
			}
		}

		return $errors;
	}

	/**
	 * Active normalized pricing rules for a courier.
	 *
	 * @param string $courier_id Courier id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rules( $courier_id ) {
		$raw = bgcs3_get_option( $courier_id, self::RULES_KEY, array() );
		// Transitional support in case an early build stored JSON text.
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		return self::sanitize_rules( $raw );
	}

	/**
	 * @param string $courier_id Courier id.
	 * @return bool
	 */
	public static function has_active_rules( $courier_id ) {
		return ! empty( self::rules( $courier_id ) );
	}

	/**
	 * Merchant's explicit pricing mode for a courier: "own prices" vs
	 * "courier API price". Once stored, the merchant's choice is authoritative.
	 * Before any explicit choice is made (e.g. upgrade from a version without
	 * pricing_mode), infer it from whether static rules already exist so
	 * existing static-pricing stores keep behaving the same after upgrade.
	 *
	 * @param string $courier_id Courier id.
	 * @return string self::MODE_API or self::MODE_OWN.
	 */
	public static function mode( $courier_id ) {
		// BOX NOW has one Core contract: free-shipping threshold first, then its
		// own weight-range quote. Ignore any legacy stored own-price mode so an
		// upgrade cannot silently route checkout through the removed duplicate UI.
		if ( 'boxnow' === sanitize_key( (string) $courier_id ) ) {
			return self::MODE_API;
		}

		$stored = (string) bgcs3_get_option( $courier_id, self::MODE_KEY, '' );
		if ( self::MODE_API === $stored || self::MODE_OWN === $stored ) {
			return $stored;
		}

		return self::has_active_rules( $courier_id ) ? self::MODE_OWN : self::MODE_API;
	}

	/**
	 * One-time conversion of the legacy single static-price-per-type fields
	 * (`static_price_office`, `static_price_address`, `static_price_locker`,
	 * `static_price_max_weight`) into the generic pricing-rules repeater, per
	 * Master Instruction §4. Runs at most once per courier: a `_pricing_migrated`
	 * flag prevents re-running even if the merchant later empties the rules.
	 *
	 * Safe no-op when: already migrated, rules already exist (repeater is
	 * already the source of truth), or there is nothing legacy to convert.
	 *
	 * @param string   $courier_id     Courier id.
	 * @param string[] $delivery_types Delivery types the courier supports.
	 * @return bool True when a migration actually happened.
	 */
	public static function migrate_legacy( $courier_id, array $delivery_types = array() ) {
		if ( 'yes' === (string) bgcs3_get_option( $courier_id, self::MIGRATED_KEY, '' ) ) {
			return false;
		}

		// Rules already own pricing -> nothing to migrate, just mark done.
		if ( self::has_active_rules( $courier_id ) ) {
			bgcs3_set_option( $courier_id, self::MIGRATED_KEY, 'yes' );
			return false;
		}

		if ( empty( $delivery_types ) ) {
			$delivery_types = array_keys( self::type_labels() );
		}

		$max_weight_raw = str_replace( ',', '.', trim( (string) bgcs3_get_option( $courier_id, 'static_price_max_weight', '' ) ) );
		$max_weight     = is_numeric( $max_weight_raw ) ? max( 0.0, (float) $max_weight_raw ) : 0.0;
		$currency       = strtoupper( trim( (string) Module_Settings::get( $courier_id, 'contract_currency' ) ) );

		$rules = array();
		foreach ( $delivery_types as $type ) {
			$price_raw = str_replace( ',', '.', trim( (string) bgcs3_get_option( $courier_id, 'static_price_' . $type, '' ) ) );
			if ( ! is_numeric( $price_raw ) || (float) $price_raw <= 0 ) {
				continue;
			}
			$rules[] = array(
				'id'              => 'migrated-' . sanitize_key( $type ),
				'enabled'         => 'yes',
				'type'            => sanitize_key( $type ),
				'max_weight'      => $max_weight,
				'max_order_total' => 0.0,
				'price'           => (float) $price_raw,
				'currency'        => $currency,
			);
		}

		if ( empty( $rules ) ) {
			bgcs3_set_option( $courier_id, self::MIGRATED_KEY, 'yes' );
			return false;
		}

		bgcs3_set_option( $courier_id, self::RULES_KEY, $rules );
		bgcs3_set_option( $courier_id, self::MODE_KEY, self::MODE_OWN );
		bgcs3_set_option( $courier_id, self::MIGRATED_KEY, 'yes' );

		// Legacy fields are no longer read by resolve() after migration; clear
		// them so a stale value can never be confused for an active setting.
		foreach ( $delivery_types as $type ) {
			bgcs3_set_option( $courier_id, 'static_price_' . $type, '' );
		}
		bgcs3_set_option( $courier_id, 'static_price_max_weight', '' );

		return true;
	}

	/**
	 * Settings fields Core injects for a courier module (merged after the
	 * module's own settings_fields()).
	 *
	 * @param Courier_Interface $module Courier.
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields_for( Courier_Interface $module ) {
		$labels = self::type_labels();
		$fields = array();

		// -- Presentation ----------------------------------------------------
		$fields['method_title'] = array(
			'type'        => 'text',
			'label'       => __( 'Method title at checkout', 'bg-commerce-suite' ),
			'default'     => '',
			'description' => __( 'Empty = courier name. It can also be changed at zone level.', 'bg-commerce-suite' ),
		);
		$fields['method_description'] = array(
			'type'        => 'text',
			'label'       => __( 'Short description under the method', 'bg-commerce-suite' ),
			'default'     => '',
			'description' => __( 'Shown below the selected method at checkout (for example, “Delivery in 1–2 business days”).', 'bg-commerce-suite' ),
		);

		$uses_core_pricing_rules = ! method_exists( $module, 'uses_core_pricing_rules' ) || $module->uses_core_pricing_rules();
		if ( $uses_core_pricing_rules ) {
			// -- Начин на изчисляване на доставката -------------------------------
			// The single source of truth for which pricing branch resolve() takes.
			// Default suggested to the merchant mirrors what already governs their
			// store today, so upgrading never silently switches behaviour.
			$suggested_mode = self::has_active_rules( $module->id() ) ? self::MODE_OWN : self::MODE_API;
			$mode_options = array(
				self::MODE_API => __( 'Courier price (API)', 'bg-commerce-suite' ),
				self::MODE_OWN => __( 'Custom prices', 'bg-commerce-suite' ),
			);
			if ( method_exists( $module, 'pricing_mode_options' ) ) {
				$custom_options = (array) $module->pricing_mode_options();
				$custom_options = array_intersect_key( $custom_options, array_flip( array( self::MODE_API, self::MODE_OWN ) ) );
				if ( ! empty( $custom_options ) ) {
					$mode_options = $custom_options;
				}
			}
			$mode_description = __( '“Courier price” queries the courier API for each order. “Custom prices” uses only the rules below and does not make a courier request.', 'bg-commerce-suite' );
			if ( method_exists( $module, 'pricing_mode_description' ) ) {
				$mode_description = (string) $module->pricing_mode_description();
			}
			$mode_label = __( 'Shipping calculation method', 'bg-commerce-suite' );
			if ( method_exists( $module, 'pricing_mode_label' ) ) {
				$mode_label = (string) $module->pricing_mode_label();
			}
			$fields[ self::MODE_KEY ] = array(
				'type'        => 'select',
				'label'       => $mode_label,
				'default'     => $suggested_mode,
				'options'     => $mode_options,
				'description' => $mode_description,
			);

			$rules_description = __( 'Add pricing tiers by delivery type, maximum weight and optionally maximum goods value after discounts and taxes (before shipping and fees). The system selects the first matching rule by the lowest threshold. An empty threshold means no limit. In Custom prices mode, no courier API fallback is used: at least one rule must match the order.', 'bg-commerce-suite' );
			if ( method_exists( $module, 'pricing_rules_description' ) ) {
				$rules_description = (string) $module->pricing_rules_description();
			}
			$fields[ self::RULES_KEY ] = array(
				'type'           => 'pricing_rules',
				'label'          => __( 'Custom pricing rules', 'bg-commerce-suite' ),
				'default'        => array(),
				'delivery_types' => array_intersect_key( self::type_labels(), array_flip( self::supported_types( $module ) ) ),
				'description'    => $rules_description,
				'show_if'        => array( self::MODE_KEY => self::MODE_OWN ),
			);

			$currency_description = __( 'Optional (for example, EUR). A rule with an empty currency inherits this value. If a currency is set and does not match the store currency, the rule is skipped and the courier price is used.', 'bg-commerce-suite' );
			if ( method_exists( $module, 'contract_currency_description' ) ) {
				$currency_description = (string) $module->contract_currency_description();
			}
			$fields['contract_currency'] = array(
				'type'        => 'text',
				'label'       => __( 'Custom pricing currency', 'bg-commerce-suite' ),
				'default'     => '',
				'description' => $currency_description,
				'show_if'     => array( self::MODE_KEY => self::MODE_OWN ),
			);
		}

		foreach ( self::supported_types( $module ) as $type ) {
			$label = isset( $labels[ $type ] ) ? $labels[ $type ] : $type;

			$fields[ 'free_over_' . $type ] = array(
				'type'        => 'text',
				/* translators: %s: delivery type */
				'label'       => sprintf( __( 'Free shipping — %s, above amount', 'bg-commerce-suite' ), $label ),
				'default'     => '',
				'description' => __( 'Empty or 0 = disabled. A number above 0 = cart threshold above which shipping is free.', 'bg-commerce-suite' ),
				'show_if'     => array( 'show_' . $type => 'yes' ),
			);
		}

		// -- Пакет по подразбиране --------------------------------------------
		// Ползва се от `Shipping\Weight`, когато продуктите нямат зададено тегло.
		// Габаритите остават при куриера — етикетите и стойностите се различават.
		$fields['default_weight'] = array(
			'type'        => 'text',
			'label'       => __( 'Default weight (kg)', 'bg-commerce-suite' ),
			'default'     => '1',
			'description' => __( 'Used when products do not have a configured weight.', 'bg-commerce-suite' ),
		);

		// -- Automation after a waybill ---------------------------------------
		$fields['status_after_label'] = array(
			'type'        => 'select',
			'label'       => __( 'Order status after shipment label creation', 'bg-commerce-suite' ),
			'default'     => '',
			'options'     => self::order_status_options(),
			'description' => __( 'Automatically change the order status when a shipment label is created.', 'bg-commerce-suite' ),
		);

		// Shipment email is a native WooCommerce WC_Email (3.0.16+).
		// Legacy courier-specific mail options stay stored for rollback, but are
		// intentionally no longer declared or rendered here.

		return $fields;
	}

	/**
	 * Settings sections for the injected fields (appended after the module's
	 * own settings_sections()).
	 *
	 * @param Courier_Interface $module Courier.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sections_for( Courier_Interface $module ) {
		$pricing_keys = array( self::MODE_KEY, self::RULES_KEY, 'contract_currency' );
		foreach ( self::supported_types( $module ) as $type ) {
			$pricing_keys[] = 'free_over_' . $type;
		}

		$pricing_desc = __( 'Priority: free for the type → custom prices (if selected) → courier price (API).', 'bg-commerce-suite' );
		if ( method_exists( $module, 'pricing_section_description' ) ) {
			$pricing_desc = (string) $module->pricing_section_description();
		}

		return array(
			array(
				'title'  => __( 'Pricing and free shipping', 'bg-commerce-suite' ),
				'desc'   => $pricing_desc,
				'icon'   => 'credit-card',
				'fields' => $pricing_keys,
			),
			array(
				'title'  => __( 'Presentation and automation', 'bg-commerce-suite' ),
				'desc'   => __( 'How the method looks and what happens after a shipment label is created.', 'bg-commerce-suite' ),
				'icon'   => 'settings',
				'fields' => array( 'method_title', 'method_description', 'status_after_label' ),
			),
		);
	}

	/**
	 * Resolve the price BEFORE asking the courier API.
	 *
	 * @param string $courier_id     Courier id.
	 * @param string $type           Delivery type (office|locker|address).
	 * @param float  $cart_total     Cart subtotal (displayed).
	 * @param float  $package_weight Package weight in kg.
	 * @param bool   $weight_known   Whether weight of all physical items is known.
	 * @return array{cost:float,base_cost:float,source:string,mode:string,destination_type:string,weight:float,weight_threshold:float,contract_currency?:string}|null Null = fall through to the API quote.
	 */
	public static function resolve( $courier_id, $type, $cart_total, $package_weight = 0.0, $weight_known = true ) {
		// 1) Free shipping for this delivery type. Empty or 0 = disabled — only a
		// threshold above zero activates the rule ("0 = изключено" holds everywhere,
		// same as the zone instance field).
		$free_over = (float) bgcs3_get_option( $courier_id, 'free_over_' . $type, '' );
		if ( $free_over > 0 && $cart_total >= $free_over ) {
			return array(
				'cost'             => 0.0,
				'base_cost'        => 0.0,
				'source'           => 'free',
				'mode'             => 'free',
				'destination_type' => $type,
				'weight'           => (float) $package_weight,
				'weight_threshold' => 0.0,
			);
		}

		// 2) Own-prices mode: generic tiered static rules are the ONLY static
		// pricing source (legacy per-type fields are migrate-only, never read
		// here — see migrate_legacy()). API mode skips rules entirely, even
		// if some are still stored, and always falls through to the courier API.
		if ( self::MODE_OWN !== self::mode( $courier_id ) ) {
			return null;
		}

		$rules = self::rules( $courier_id );
		if ( empty( $rules ) ) {
			// Own prices selected but nothing configured yet. The shipping method
			// treats this null as a configuration error and does not call the API.
			return null;
		}

		$store_currency    = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'EUR';
		$contract_currency = strtoupper( trim( (string) Module_Settings::get( $courier_id, 'contract_currency' ) ) );
		$package_weight    = (float) $package_weight;
		$cart_total        = (float) $cart_total;

		foreach ( $rules as $rule ) {
			if ( $type !== $rule['type'] ) {
				continue;
			}

			$max_weight = (float) $rule['max_weight'];
			if ( $max_weight > 0 ) {
				if ( ! $weight_known || $package_weight > $max_weight ) {
					continue;
				}
			}

			$max_total = (float) $rule['max_order_total'];
			if ( $max_total > 0 && $cart_total > $max_total ) {
				continue;
			}

			$currency = '' !== $rule['currency'] ? strtoupper( $rule['currency'] ) : $contract_currency;
			if ( '' !== $currency && $currency !== $store_currency ) {
				continue;
			}

			return array(
				'cost'              => (float) $rule['price'],
				'base_cost'         => (float) $rule['price'],
				'source'            => 'static_rule',
				'mode'              => 'static',
				'destination_type'  => $type,
				'weight'            => $package_weight,
				'weight_threshold'  => $max_weight,
				'contract_currency' => $currency,
				'pricing_rule'      => array(
					'id'              => $rule['id'],
					'max_weight'      => $max_weight,
					'max_order_total' => $max_total,
				),
			);
		}

		// No own-price rule matches. The shipping method treats this null as a
		// configuration error and deliberately does not call the courier API.
		return null;
	}

	/**
	 * All order statuses currently registered with WooCommerce, including
	 * statuses added by third-party plugins or custom store code. Values are
	 * normalized to the slug format expected by WC_Order::update_status().
	 *
	 * @return array<string,string>
	 */
	private static function order_status_options() {
		$options = array( '' => __( '— No change —', 'bg-commerce-suite' ) );
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

		foreach ( $statuses as $slug => $label ) {
			$slug = (string) $slug;
			if ( 0 === strpos( $slug, 'wc-' ) ) {
				$slug = substr( $slug, 3 );
			}
			$slug = sanitize_key( $slug );
			if ( '' !== $slug ) {
				$options[ $slug ] = (string) $label;
			}
		}

		return $options;
	}

	/**
	 * Post-waybill actions: auto order status + tracking email to the customer.
	 * Called right after a label is successfully created and stored.
	 *
	 * @param \WC_Order         $order   Order.
	 * @param Courier_Interface $courier Courier module.
	 * @param string            $number  Waybill number.
	 */
	public static function after_label_created( \WC_Order $order, Courier_Interface $courier, $number ) {
		$courier_id = $courier->id();

		// -- Auto status -------------------------------------------------------
		$next               = sanitize_key( (string) Module_Settings::get( $courier_id, 'status_after_label' ) );
		$available_statuses = self::order_status_options();
		if ( '' !== $next && isset( $available_statuses[ $next ] ) && ! $order->has_status( $next ) ) {
			$order->update_status(
				$next,
				sprintf(
					/* translators: %s: waybill number */
					__( 'Automatic status change after shipment label %s.', 'bg-commerce-suite' ),
					$number
				)
			);
		}

		// Native WooCommerce transactional email. The email class owns enable/disable,
		// subject, heading, template, sender, format and compatibility with SMTP/logging
		// plugins through WooCommerce's normal mailer pipeline.
		do_action( 'bgcs3_label_created', $order->get_id(), $courier_id, (string) $number );
	}
}
