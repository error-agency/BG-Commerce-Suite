<?php
/**
 * Base courier. Fixes the category to 'shipping' and provides safe default
 * (Phase 1 placeholder) implementations so a concrete courier can be added
 * incrementally. Real couriers override the API-backed methods.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping;

use BgCommerce3\Module\Abstract_Module;
use BgCommerce3\Module\Categories;
use BgCommerce3\Support\Cache;
use BgCommerce3\Support\Selection;
use BgCommerce3\Support\Price_Result;
use BgCommerce3\Support\Label_Result;
use BgCommerce3\Support\Tracking_Result;
use BgCommerce3\Support\Sync_Result;
use BgCommerce3\Support\Module_Settings;
use BgCommerce3\Shipping\Shipment_Preflight;
use BgCommerce3\Shipping\Shipment_Mutation;
use BgCommerce3\Shipping\Tracking_State;

defined( 'ABSPATH' ) || exit;

abstract class Abstract_Courier extends Abstract_Module implements Courier_Interface {

	/**
	 * @return string
	 */
	public function category() {
		return Categories::SHIPPING;
	}

	/**
	 * @return string[]
	 */
	public function delivery_types() {
		return array( 'office', 'address' );
	}

	/**
	 * @param string $delivery_type Delivery type.
	 * @return array<string,mixed>
	 */
	public function checkout_schema( $delivery_type ) {
		return array();
	}

	/**
	 * @param Selection $selection Customer selection.
	 * @return true|\WP_Error
	 */
	public function validate( Selection $selection ) {
		if ( ! in_array( $selection->delivery_type, $this->delivery_types(), true ) ) {
			return new \WP_Error( 'bgcs3_delivery_type_unavailable', __( 'This delivery type is not available for the selected courier.', 'bg-commerce-suite' ) );
		}
		if ( ! $selection->is_complete() ) {
			return new \WP_Error( 'bgcs3_selection_incomplete', __( 'Please complete the courier delivery selection.', 'bg-commerce-suite' ) );
		}
		return true;
	}

	/**
	 * Confirm an office/locker id against the courier's last successfully synced
	 * directory. An empty pool is not treated as rejection because first-run/live
	 * API flows can legitimately precede the first directory sync.
	 *
	 * @param Selection $selection Selection.
	 * @return true|\WP_Error
	 */
	protected function validate_synced_pickup_point( Selection $selection ) {
		if ( ! in_array( $selection->delivery_type, array( 'office', 'locker' ), true ) ) {
			return true;
		}

		$id = ! empty( $selection->office['id'] ) ? (string) $selection->office['id'] : '';
		if ( '' === $id ) {
			return true;
		}

		// Prefer the city-scoped provider list. Courier location providers already
		// cache these responses, and this proves that an office from city A did not
		// survive after the shopper switched to city B. Fall back to the last full
		// synced pool when the city lookup is unavailable/offline.
		$rows        = array();
		$city_scoped = false;
		$city_id     = ! empty( $selection->city['id'] ) ? (string) $selection->city['id'] : '';
		$locations   = $this->locations();
		if ( '' !== $city_id && is_object( $locations ) && is_callable( array( $locations, 'offices' ) ) ) {
			$city_rows = (array) $locations->offices( $city_id, $selection->delivery_type );
			if ( ! empty( $city_rows ) ) {
				$rows        = $city_rows;
				$city_scoped = true;
			}
		}
		if ( empty( $rows ) ) {
			$rows = \BgCommerce3\Shipping\Office_Store::get( $this->id(), $selection->delivery_type );
		}
		if ( empty( $rows ) ) {
			return true;
		}

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && $id === (string) $row['id'] ) {
				return true;
			}
		}

		return new \WP_Error(
			'bgcs3_stale_pickup_point',
			$city_scoped
				? __( 'The selected office/locker does not belong to the current city anymore. Please choose it again.', 'bg-commerce-suite' )
				: __( 'The selected office/locker is no longer present in the current courier directory. Please choose it again.', 'bg-commerce-suite' )
		);
	}

	/**
	 * @param array<string,mixed> $package   WC shipping package.
	 * @param Selection           $selection Customer selection.
	 * @return Price_Result
	 */
	public function quote( array $package, Selection $selection ) {
		// Phase 1: real API pricing. Skeleton returns an unpriced result.
		return new Price_Result();
	}

	/**
	 * Default surcharge calculation: empty array (no surcharges). Real couriers
	 * (e.g. Speedy PMT) override to contribute their contractual surcharges.
	 *
	 * @param array<string,mixed> $package   WC shipping package.
	 * @param Selection           $selection Customer selection.
	 * @param float               $base_cost Base shipping cost.
	 * @return array<string,mixed> Surcharge components keyed by identifier.
	 */
	public function calculate_surcharges( array $package, Selection $selection, $base_cost = 0.0 ) {
		unset( $package, $selection, $base_cost );
		return array();
	}


	/**
	 * Optional surcharge calculation when Core has resolved a free-transport rule.
	 * Couriers may override this when contractual non-transport charges (for example
	 * a money-transfer fee) should still be recovered from the customer.
	 *
	 * @param array<string,mixed> $package   WC shipping package.
	 * @param Selection           $selection Customer selection.
	 * @return array<string,mixed>
	 */
	public function calculate_free_shipping_surcharges( array $package, Selection $selection ) {
		unset( $package, $selection );
		return array();
	}

	/**
	 * Optional validation hook for generic static/contract pricing.
	 * Courier add-ons can reject an unsafe static-price configuration without
	 * leaking courier-specific concepts into Core.
	 *
	 * @param Selection $selection Customer selection.
	 * @param float     $base_cost WooCommerce customer-facing static shipping amount.
	 * @return true|\WP_Error
	 */
	public function validate_static_pricing( Selection $selection, $base_cost = 0.0 ) {
		unset( $selection, $base_cost );
		return true;
	}

	/**
	 * Optional package-aware checkout validation that runs before every pricing
	 * mode (free, static or live API). Use this only for constraints that can be
	 * proven from the current WooCommerce package without guessing packing.
	 *
	 * @param array<string,mixed> $package   WooCommerce shipping package.
	 * @param Selection           $selection Customer selection.
	 * @return true|\WP_Error
	 */
	public function validate_package( array $package, Selection $selection ) {
		unset( $package, $selection );
		return true;
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return Label_Result
	 */
	public function create_label( \WC_Order $order ) {
		return Label_Result::error( __( 'Not implemented yet.', 'bg-commerce-suite' ) );
	}

	/**
	 * Non-destructive common validation before a provider create call.
	 *
	 * This is deliberately not added to Courier_Interface: third-party modules
	 * written against an older Core remain compatible. Built-in couriers call it
	 * directly and external modules may opt in through the inherited method.
	 *
	 * @param \WC_Order $order Order.
	 * @return Shipment_Preflight
	 */
	public function preflight_shipment( \WC_Order $order ) {
		return Shipment_Preflight::begin( $order, $this );
	}

	/**
	 * Effective courier environment for the preflight snapshot.
	 *
	 * Couriers with a fixed endpoint override this instead of making Core infer
	 * environments from hostnames.
	 *
	 * @return string
	 */
	public function preflight_environment() {
		return (string) Module_Settings::get( $this->id(), 'env' );
	}

	/**
	 * Сглобява стандартния резултат от синхронизация на справочници.
	 *
	 * Всички куриери имат един и същ поток: изчистване на кеша (прави се от
	 * самия add-on, преди презареждането), обновяване на пуловете с офиси и
	 * автомати, събиране на грешките и връщане на успех или частичен успех.
	 * При грешка последните валидни списъци остават непокътнати.
	 *
	 * @param array<string,int|\WP_Error> $pools   Резултат по тип от `Office_Store::replace_pools()`.
	 * @param array<string,int>           $counts  Броячи за показване (кеш, услуги, офиси…).
	 * @param array<string,mixed>         $updated Настройки, обновени по време на синхронизацията.
	 * @param string[]                    $errors  Вече събрани грешки.
	 * @return Sync_Result
	 */
	protected function sync_result( array $pools, array $counts = array(), array $updated = array(), array $errors = array() ) {
		foreach ( $pools as $type => $stored ) {
			if ( is_wp_error( $stored ) ) {
				$errors[] = $stored->get_error_message();
			} else {
				$counts[ $type ] = $stored;
			}
		}

		if ( $errors ) {
			return Sync_Result::warning(
				sprintf(
					/* translators: %s: име на куриера. */
					__( '%s directories were partially updated.', 'bg-commerce-suite' ),
					$this->name()
				),
				$counts,
				$updated,
				array( __( 'Previous valid lists', 'bg-commerce-suite' ) ),
				$errors
			);
		}

		return Sync_Result::success(
			sprintf(
				/* translators: %s: име на куриера. */
				__( '%s directories were updated.', 'bg-commerce-suite' ),
				$this->name()
			),
			$counts,
			$updated
		);
	}

	/**
	 * Платежната семантика на току-що създадената пратка (BGCS-AUDIT-004/-006).
	 *
	 * Core изгражда снапшота на товарителницата (`Shipping\Label_Snapshot`), но
	 * **не** гадае кой плаща куриерската услуга и колко реално ще бъде събрано —
	 * това знае само модулът. Ако не върнете нищо, платецът остава празен:
	 * „неизвестно“ се записва като неизвестно, а не като предположена стойност.
	 *
	 * Върнете само каквото знаете; всеки ключ е незадължителен:
	 *
	 *   'payer'        => 'SENDER'|'RECIPIENT'|'THIRD_PARTY'|''
	 *                     Празен низ означава „този куриер няма такова понятие“
	 *                     (напр. мрежа от автомати), не „не знам“.
	 *   'cod_amount'   => float — сумата, която куриерът реално ще събере, ако
	 *                     тя се различава от `Cod::resolve_amount()` (напр. при
	 *                     платец получател транспортът се събира отделно).
	 *   'cod_currency' => string
	 *
	 * @param \WC_Order           $order Поръчка.
	 * @param array<string,mixed> $wb    Стойности от панела за товарителница (`_bgcs3_wb`).
	 * @return array<string,mixed>
	 */
	public function label_snapshot_financials( \WC_Order $order, array $wb ) {
		return array();
	}

	/**
	 * Номерът на издадената товарителница за поръчката ('' ако няма).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	protected function label_number( \WC_Order $order ) {
		$label = $order->get_meta( '_bgcs3_label' );

		return ( is_array( $label ) && ! empty( $label['number'] ) ) ? (string) $label['number'] : '';
	}

	/**
	 * Стойност от `meta` блока на записаната товарителница (напр. shipment_id).
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $key     Ключ.
	 * @param mixed     $default Стойност по подразбиране.
	 * @return mixed
	 */
	protected function label_meta( \WC_Order $order, $key, $default = '' ) {
		$label = $order->get_meta( '_bgcs3_label' );

		return ( is_array( $label ) && isset( $label['meta'][ $key ] ) ) ? $label['meta'][ $key ] : $default;
	}

	/**
	 * Отказва товарителницата при куриера и записва provider confirmation.
	 *
	 * Локалният label/history се променя от Core едва след този confirmation.
	 * Куриерът реализира само самото извикване към API-то (`cancel_shipment`).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function delete_label( \WC_Order $order ) {
		$number = $this->label_number( $order );

		if ( '' === $number ) {
			return false;
		}
		if ( ! Shipment_Mutation::remote_started( $order, $this ) ) {
			return false;
		}

		try {
			$response = $this->cancel_shipment( $order, $number );
		} catch ( \Throwable $e ) {
			Shipment_Mutation::finalize_exception( $order );
			throw $e;
		}

		if ( is_wp_error( $response ) || false === $response ) {
			Shipment_Mutation::remote_failed( $order, $response );
			return false;
		}

		Shipment_Mutation::remote_confirmed( $order, 'provider_response' );
		return true;
	}

	/**
	 * Отказ на пратката при куриера. По подразбиране — не се поддържа.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $number Номер на товарителницата.
	 * @return mixed|\WP_Error `WP_Error` или false при неуспех.
	 */
	protected function cancel_shipment( \WC_Order $order, $number ) {
		return new \WP_Error( 'bgcs3_not_implemented', __( 'Not implemented yet.', 'bg-commerce-suite' ) );
	}

	/**
	 * Проследяване по номера на товарителницата.
	 *
	 * Куриерът реализира само заявката (`fetch_tracking`) и разчитането на
	 * събитията (`fill_tracking`) — общият поток е един и същ навсякъде.
	 *
	 * @param \WC_Order $order Order.
	 * @return Tracking_Result
	 */
	public function tracking( \WC_Order $order ) {
		$number = $this->label_number( $order );

		if ( '' === $number ) {
			return Tracking_Result::error( __( 'There is no shipment label to track.', 'bg-commerce-suite' ) );
		}

		$response = $this->fetch_tracking( $number );

		if ( is_wp_error( $response ) ) {
			return Tracking_Result::error( $response->get_error_message() );
		}

		$result          = new Tracking_Result();
		$result->success = true;

		$this->fill_tracking( $result, (array) $response );

		return $result;
	}

	/**
	 * Заявка за проследяване към куриера.
	 *
	 * @param string $number Номер на товарителницата.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function fetch_tracking( $number ) {
		return new \WP_Error( 'bgcs3_not_implemented', __( 'Not implemented yet.', 'bg-commerce-suite' ) );
	}

	/**
	 * Пренася събитията от отговора на куриера в резултата.
	 *
	 * @param Tracking_Result     $result   Резултат за попълване.
	 * @param array<string,mixed> $response Отговор на куриера.
	 * @return void
	 */
	protected function fill_tracking( Tracking_Result $result, array $response ) {
	}

	/**
	 * @param array<string,mixed> $event Tracking event.
	 * @return string One of Tracking_State::*.
	 */
	public function normalize_status( array $event ) {
		unset( $event );
		return Tracking_State::UNKNOWN;
	}

	/**
	 * Public tracking page URL for a waybill number (empty = none).
	 *
	 * @param string $number Waybill / parcel number.
	 * @return string
	 */
	public function tracking_url( $number ) {
		return '';
	}

	/**
	 * Refresh the courier's cached reference data (offices/lockers, services,
	 * contracts…). Default: drop the courier's cache so the next request fetches
	 * fresh data from the API. Couriers may override to also pre-warm the cache.
	 * Triggered manually (settings button) and automatically (daily cron).
	 *
	 * @return array{success:bool,message:string}
	 */
	public function sync_data() {
		$count = Cache::flush_courier( $this->id() );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: number of cache entries, 2: courier name */
				__( 'Synchronized: cleared %1$d cached records for %2$s — offices/lockers will be refreshed from the API on the next load.', 'bg-commerce-suite' ),
				$count,
				$this->name()
			),
		);
	}

	/**
	 * Whether the module can refresh its selected sender object.
	 *
	 * @return bool
	 */
	public function supports_sender_refresh() {
		return false;
	}

	/**
	 * @return string
	 */
	public function sender_refresh_label() {
		return '';
	}

	/**
	 * @return Sync_Result
	 */
	public function refresh_sender_data() {
		return Sync_Result::error( __( 'This courier does not provide sender profile data.', 'bg-commerce-suite' ) );
	}

	/**
	 * Search courier-owned admin nomenclatures.
	 *
	 * @param string              $resource Resource name.
	 * @param array<string,mixed> $args     Search arguments.
	 * @return array<int,array<string,string>>|\WP_Error
	 */
	public function admin_location_search( $resource, array $args ) {
		return new \WP_Error(
			'bgcs3_admin_location_search_unsupported',
			__( 'This courier does not provide this directory.', 'bg-commerce-suite' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Search the persistent office/locker pool for admin controls.
	 *
	 * @param string $type  office|locker.
	 * @param string $query Search text.
	 * @return array<int,array<string,string>>|\WP_Error
	 */
	protected function search_stored_locations( $type, $query ) {
		$rows = \BgCommerce3\Shipping\Office_Store::get( $this->id(), $type );
		if ( empty( $rows ) ) {
			return new \WP_Error(
				'bgcs3_location_pool_unavailable',
				__( 'The directory has not been synchronized yet.', 'bg-commerce-suite' ),
				array( 'status' => 503 )
			);
		}

		$needle = bgcs3_strtolower( trim( (string) $query ) );
		$out    = array();
		foreach ( $rows as $row ) {
			$label = isset( $row['text'] ) && '' !== $row['text']
				? $row['text']
				: trim( ( isset( $row['name'] ) ? $row['name'] : '' ) . ' — ' . ( isset( $row['city'] ) ? $row['city'] : '' ) . ' ' . ( isset( $row['address'] ) ? $row['address'] : '' ) );
			$haystack = bgcs3_strtolower( $label . ' ' . ( isset( $row['post_code'] ) ? $row['post_code'] : '' ) . ' ' . ( isset( $row['id'] ) ? $row['id'] : '' ) );
			if ( '' !== $needle && false === bgcs3_strpos( $haystack, $needle ) ) {
				continue;
			}
			$out[] = array(
				'id'    => isset( $row['id'] ) ? (string) $row['id'] : '',
				'label' => $label,
			);
			if ( count( $out ) >= 50 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Get the saved selection for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return Selection|null
	 */
	protected function order_selection( \WC_Order $order ) {
		$data = $order->get_meta( '_bgcs3_selection' );
		return ( is_array( $data ) && ! empty( $data ) ) ? Selection::from_array( $data ) : null;
	}
}
