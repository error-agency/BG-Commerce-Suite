<?php
/**
 * Converges the canonical BGCS selection with WooCommerce package choices.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

use BgCommerce3\Support\Selection;

defined( 'ABSPATH' ) || exit;

final class Selection_Synchronizer {

	/**
	 * Resolve exact rate instances for the selected courier without disturbing
	 * packages currently owned by a non-BGCS shipping method.
	 *
	 * @param array<int|string,array<string,mixed>> $packages Shipping packages.
	 * @param array<int|string,string>              $chosen   Current choices.
	 * @param string                                $courier  Courier id.
	 * @return array{chosen:array<int|string,string>,changed_package_keys:array<int|string>,relevant_package_keys:array<int|string>}
	 */
	public static function reconcile_chosen( array $packages, array $chosen, $courier ) {
		$courier = sanitize_key( (string) $courier );
		$changed = array();
		$relevant = array();

		foreach ( $packages as $package_key => $package ) {
			$rates   = isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : array();
			$current = isset( $chosen[ $package_key ] ) ? (string) $chosen[ $package_key ] : '';
			$target  = self::rate_id_for_courier( $rates, $courier );
			$is_bgcs = self::is_bgcs_rate_id( $current );

			if ( '' !== $target ) {
				$relevant[] = $package_key;
				if ( ( '' === $current || $is_bgcs ) && $current !== $target ) {
					$chosen[ $package_key ] = $target;
					$changed[] = $package_key;
				}
				continue;
			}

			// A previously selected BGCS rate must not remain active when the new
			// courier produced no usable rate for this package.
			if ( $is_bgcs ) {
				$relevant[] = $package_key;
				if ( '' !== $current ) {
					$chosen[ $package_key ] = '';
					$changed[] = $package_key;
				}
			}
		}

		return array(
			'chosen'                => $chosen,
			'changed_package_keys'  => array_values( array_unique( $changed, SORT_REGULAR ) ),
			'relevant_package_keys' => array_values( array_unique( $relevant, SORT_REGULAR ) ),
		);
	}

	/**
	 * Persist the exact chosen rate, invalidate relevant package caches and
	 * calculate once more from the canonical selection.
	 *
	 * @param Selection $selection Canonical accepted selection.
	 * @return array<string,mixed>
	 */
	public static function synchronize( Selection $selection ) {
		$result = array(
			'chosen'                => array(),
			'changed_package_keys'  => array(),
			'relevant_package_keys' => array(),
		);

		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return $result;
		}

		$packages = self::shipping_packages();
		$chosen   = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$before   = self::reconcile_chosen( $packages, $chosen, $selection->courier );
		self::persist_chosen( $before['chosen'] );

		$cart_packages = (array) WC()->cart->get_shipping_packages();
		$invalidate = $before['relevant_package_keys'];
		if ( empty( $invalidate ) ) {
			$invalidate = array_keys( $cart_packages );
		}

		foreach ( $invalidate as $package_key ) {
			WC()->session->set( 'shipping_for_package_' . $package_key, null );
		}

		WC()->cart->calculate_shipping();

		// The selected courier can disappear after its real quote fails. Resolve
		// against the fresh rates too, so an old pending courier cannot survive.
		$after = self::reconcile_chosen(
			self::shipping_packages(),
			(array) WC()->session->get( 'chosen_shipping_methods', $before['chosen'] ),
			$selection->courier
		);
		self::persist_chosen( $after['chosen'] );
		if ( ! empty( $after['changed_package_keys'] ) ) {
			// When no package snapshot existed before the first calculation, the
			// exact instance is discovered only now. Re-run from cached fresh rates
			// so WC_Cart's selected methods/totals use the newly persisted choice.
			WC()->cart->calculate_shipping();
			$after = self::reconcile_chosen(
				self::shipping_packages(),
				(array) WC()->session->get( 'chosen_shipping_methods', $after['chosen'] ),
				$selection->courier
			);
			self::persist_chosen( $after['chosen'] );
		}

		$result['chosen'] = $after['chosen'];
		$result['changed_package_keys'] = array_values(
			array_unique( array_merge( $before['changed_package_keys'], $after['changed_package_keys'] ), SORT_REGULAR )
		);
		$result['relevant_package_keys'] = array_values(
			array_unique( array_merge( $before['relevant_package_keys'], $after['relevant_package_keys'] ), SORT_REGULAR )
		);

		return $result;
	}

	/**
	 * Whether a rate is owned by the courier and can safely finalize checkout.
	 *
	 * @param mixed  $rate    WC_Shipping_Rate-like object.
	 * @param string $courier Courier id.
	 * @return bool
	 */
	public static function rate_is_settled_for( $rate, $courier ) {
		$meta = self::rate_meta( $rate );
		$owner = isset( $meta['_bgcs3_courier'] ) ? sanitize_key( (string) $meta['_bgcs3_courier'] ) : '';
		$state = isset( $meta['_bgcs3_price_state'] ) ? sanitize_key( (string) $meta['_bgcs3_price_state'] ) : '';
		$validated = ! empty( $meta['_bgcs3_validated'] );

		return sanitize_key( (string) $courier ) === $owner
			&& $validated
			&& in_array( $state, array( 'calculated', 'free' ), true );
	}

	/**
	 * Whether the rate was calculated for the exact canonical destination/revision.
	 *
	 * @param mixed     $rate      WC_Shipping_Rate-like object.
	 * @param Selection $selection Canonical selection.
	 * @return bool
	 */
	public static function rate_selection_matches( $rate, Selection $selection ) {
		$meta = self::rate_meta( $rate );
		return Order_Persistence::selection_matches(
			isset( $meta['_bgcs3_selection'] ) ? $meta['_bgcs3_selection'] : null,
			$selection
		);
	}

	/**
	 * @param array<string,mixed> $rates   Package rates.
	 * @param string              $courier Courier id.
	 * @return string
	 */
	private static function rate_id_for_courier( array $rates, $courier ) {
		foreach ( $rates as $rate_key => $rate ) {
			$meta  = self::rate_meta( $rate );
			$owner = isset( $meta['_bgcs3_courier'] ) ? sanitize_key( (string) $meta['_bgcs3_courier'] ) : '';
			if ( $courier !== $owner ) {
				continue;
			}

			$rate_id = is_object( $rate ) && method_exists( $rate, 'get_id' )
				? (string) $rate->get_id()
				: (string) $rate_key;
			if ( self::is_bgcs_rate_id( $rate_id ) ) {
				return $rate_id;
			}
		}

		return '';
	}

	/**
	 * @param mixed $rate WC_Shipping_Rate-like object.
	 * @return array<string,mixed>
	 */
	private static function rate_meta( $rate ) {
		if ( is_object( $rate ) && method_exists( $rate, 'get_meta_data' ) ) {
			return (array) $rate->get_meta_data();
		}
		if ( is_object( $rate ) && isset( $rate->meta_data ) && is_array( $rate->meta_data ) ) {
			return $rate->meta_data;
		}
		return array();
	}

	/**
	 * @param string $rate_id Rate id.
	 * @return bool
	 */
	private static function is_bgcs_rate_id( $rate_id ) {
		return 0 === strpos( (string) $rate_id, 'bgcs3_' );
	}

	/**
	 * @return array<int|string,array<string,mixed>>
	 */
	private static function shipping_packages() {
		if ( function_exists( 'WC' ) && WC()->shipping() && method_exists( WC()->shipping(), 'get_packages' ) ) {
			return (array) WC()->shipping()->get_packages();
		}
		return array();
	}

	/**
	 * @param array<int|string,string> $chosen Chosen rate ids.
	 */
	private static function persist_chosen( array $chosen ) {
		WC()->session->set( 'chosen_shipping_methods', $chosen );
		if ( method_exists( WC()->session, 'save_data' ) ) {
			WC()->session->save_data();
		}
	}
}
