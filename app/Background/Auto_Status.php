<?php
/**
 * Auto Status Background Task: Schedules hourly synchronization of tracking
 * statuses for pending/processing orders using WooCommerce Action Scheduler,
 * and updates order statuses based on tracking events.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Background;

use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Shipping\Cod_Payout;
use BgCommerce3\Shipping\Tracking_State;
use BgCommerce3\Shipping\Tracking_Status_Policy;
use BgCommerce3\Shipping\Tracking_Unmapped_Registry;
use BgCommerce3\Shipping\Tracking_Store;
use BgCommerce3\Shipping\Tracking_Sync;
use BgCommerce3\Support\Tracking_Result;

defined( 'ABSPATH' ) || exit;

class Auto_Status {

	const GROUP = 'bgcs3';

	const SCHEDULE_HOOK = 'bgcs3_schedule_tracking_sync';

	const UPDATE_HOOK = 'bgcs3_update_order_tracking_status';

	/** Verified courier push event (webhook) applied before optional API refresh. */
	const PUSH_EVENT_HOOK = 'bgcs3_apply_pushed_tracking_event';

	/** Batched variant, for couriers that answer about several parcels at once. */
	const BATCH_HOOK = 'bgcs3_update_orders_tracking_status';

	/** Parcels per request when a courier declares no limit of its own. */
	const DEFAULT_BATCH = 10;

	/**
	 * Hard ceiling regardless of what a courier claims. A single failed request
	 * loses a whole chunk until the next scan, so the chunk stays small enough
	 * that losing one is not an outage.
	 */
	const MAX_BATCH = 50;

	/**
	 * Stop polling a shipment this old. Speedy publish six months as the point
	 * past which they hold nothing; the others are not more generous.
	 */
	const MAX_TRACKING_AGE = 15552000; // 180 days.

	/** @var Container */
	private $container;

	/**
	 * @param Container $container Core DI container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Hook Action Scheduler events.
	 *
	 * Rule 245/247/248 — tracking synchronization itself is now ALWAYS wired
	 * (safe default: ON, per-courier opt-out via Tracking_Sync), independent
	 * of the separate `checkout.update_order_statuses` setting, which after
	 * this change ONLY gates whether a resolved state is allowed to change
	 * the WooCommerce order status (see update_order_tracking_status() below).
	 * Previously a single flag controlled both, so a merchant could not get
	 * always-fresh tracking without also opting into automatic status changes.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_schedules' ) );
		add_action( self::SCHEDULE_HOOK, array( $this, 'schedule_tracking_sync' ) );
		add_action( self::UPDATE_HOOK, array( $this, 'update_order_tracking_status' ), 10, 2 );
		add_action( self::PUSH_EVENT_HOOK, array( $this, 'apply_pushed_tracking_event' ), 10, 3 );
		add_action( self::BATCH_HOOK, array( $this, 'update_orders_tracking_status' ), 10, 2 );
	}

	/**
	 * Register the recurring dispatcher. Runs frequently enough to service
	 * the shortest configurable per-courier interval (Rule 246 — 30 minutes);
	 * `is_eligible_for_sync()` below decides, per order, whether THIS tick
	 * actually does anything for it.
	 */
	public function register_schedules() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$scheduled = as_has_scheduled_action( self::SCHEDULE_HOOK, array(), self::GROUP );
		if ( ! $this->has_enabled_courier() ) {
			if ( $scheduled && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::SCHEDULE_HOOK, array(), self::GROUP );
			}
			return;
		}

		if ( ! $scheduled ) {
			as_schedule_recurring_action( time(), 15 * MINUTE_IN_SECONDS, self::SCHEDULE_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Whether at least one courier runtime is enabled.
	 *
	 * @return bool
	 */
	private function has_enabled_courier() {
		$grouped = $this->container['modules']->by_category();
		foreach ( isset( $grouped[ \BgCommerce3\Module\Categories::SHIPPING ] ) ? $grouped[ \BgCommerce3\Module\Categories::SHIPPING ] : array() as $module ) {
			if ( $module->is_enabled() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fetch qualifying orders and schedule async tracking updates for each.
	 */
	public function schedule_tracking_sync() {
		if ( ! $this->has_enabled_courier() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$page     = 1;
		$per_page = 100;
		$eligible = array();

		do {
			// Find orders with active BGCS shipments in batches. Tracking eligibility
			// is determined by waybill existence + non-terminal state, not a narrow status whitelist.
			$args = array(
				'status'   => 'any',
				'limit'    => $per_page,
				'page'     => $page,
				'return'   => 'ids',
				'paginate' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- ограничава фоновия scan до поръчки с активна BGCS товарителница.
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => '_bgcs3_selection',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_bgcs3_label',
						'compare' => 'EXISTS',
					),
				),
			);
			$order_ids = wc_get_orders( $args );

			if ( empty( $order_ids ) ) {
				break;
			}

			foreach ( $order_ids as $order_id ) {
				// Rule 246 (per-courier on/off + interval), Rule 249 (eligible-only
				// scanning), Rule 257 (stop polling confirmed-terminal shipments) —
				// all decided BEFORE an async action is even queued, so a disabled,
				// not-yet-due, or already-terminal order never reaches the courier API.
				if ( ! $this->is_eligible_for_sync( $order_id ) ) {
					continue;
				}

				$courier_id = $this->courier_id_for( $order_id );
				if ( '' === $courier_id ) {
					continue;
				}

				$eligible[ $courier_id ][] = $order_id;
			}

			$page++;
		} while ( count( $order_ids ) === $per_page );

		foreach ( $eligible as $courier_id => $ids ) {
			$this->enqueue_for_courier( $courier_id, $ids );
		}
	}

	/**
	 * Queues the work for one courier's due orders.
	 *
	 * A courier that can ask about several parcels in one request gets its
	 * orders in chunks; one that cannot keeps the original one-action-per-order
	 * behaviour, unchanged. At 400 shipments a day the difference is 400 API
	 * calls against 40 — and Speedy's own guidance is explicit that repeated
	 * single-parcel polling is the wrong way to use their service.
	 *
	 * @param string $courier_id Courier.
	 * @param int[]  $order_ids  Due order ids.
	 */
	private function enqueue_for_courier( $courier_id, array $order_ids ) {
		/** @var \BgCommerce3\Module\Module_Registry $registry */
		$registry = $this->container['modules'];
		$courier  = $registry->get( $courier_id );

		$bulk = $courier
			&& method_exists( $courier, 'supports_bulk_tracking' )
			&& $courier->supports_bulk_tracking()
			&& method_exists( $courier, 'bulk_tracking' );

		if ( ! $bulk ) {
			foreach ( $order_ids as $order_id ) {
				$args = array(
					'order_id' => $order_id,
					'source'   => 'cron',
				);
				if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::UPDATE_HOOK, $args, self::GROUP ) ) {
					as_enqueue_async_action( self::UPDATE_HOOK, $args, self::GROUP );
				}
			}
			return;
		}

		$size = method_exists( $courier, 'tracking_batch_size' ) ? (int) $courier->tracking_batch_size() : self::DEFAULT_BATCH;
		$size = max( 1, min( $size, self::MAX_BATCH ) );

		foreach ( array_chunk( $order_ids, $size ) as $chunk ) {
			$args = array(
				'courier'   => $courier_id,
				'order_ids' => array_values( $chunk ),
			);
			if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::BATCH_HOOK, $args, self::GROUP ) ) {
				as_enqueue_async_action( self::BATCH_HOOK, $args, self::GROUP );
			}
		}
	}

	/**
	 * @param int $order_id Order.
	 * @return string Courier id, or '' when the order carries none.
	 */
	private function courier_id_for( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		$selection = $order->get_meta( '_bgcs3_selection' );

		return is_array( $selection ) && ! empty( $selection['courier'] ) ? (string) $selection['courier'] : '';
	}

	/**
	 * Updates a chunk of orders from ONE courier request.
	 *
	 * A courier that answers about several parcels at once is asked once; each
	 * answer is then applied through the same {@see self::apply_tracking()} the
	 * single-order path uses. An order the courier said nothing about is left
	 * exactly as it was (Rule 256) — silence is not evidence.
	 *
	 * @param string $courier_id Courier.
	 * @param int[]  $order_ids  Orders in this chunk.
	 */
	public function update_orders_tracking_status( $courier_id, $order_ids ) {
		/** @var \BgCommerce3\Module\Module_Registry $registry */
		$registry = $this->container['modules'];
		$courier  = $registry->get( (string) $courier_id );

		if ( ! $courier instanceof Courier_Interface || ! $courier->is_enabled() || ! method_exists( $courier, 'bulk_tracking' ) ) {
			return;
		}

		$orders = array();
		foreach ( (array) $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$label  = $order->get_meta( '_bgcs3_label' );
			$number = ( is_array( $label ) && ! empty( $label['number'] ) ) ? (string) $label['number'] : '';
			if ( '' === $number ) {
				continue;
			}

			$orders[ $number ] = $order;
		}

		if ( empty( $orders ) ) {
			return;
		}

		// Only ask for "the newest operation only" when EVERY order in the chunk
		// already has history to append to. One first-time order in the chunk
		// would otherwise be left holding a single event, with everything before
		// it lost — and a chunk is a courier's convenience, not a reason to
		// degrade an order's record.
		$last_only = true;
		foreach ( $orders as $order ) {
			$stored = $order->get_meta( '_bgcs3_tracking' );
			if ( ! is_array( $stored ) || empty( $stored['events'] ) ) {
				$last_only = false;
				break;
			}
		}

		$results = $courier->bulk_tracking( array_keys( $orders ), $last_only );
		if ( ! is_array( $results ) ) {
			return;
		}

		foreach ( $orders as $number => $order ) {
			if ( isset( $results[ $number ] ) ) {
				$this->apply_tracking( $order, $courier, $results[ $number ], 'cron' );
			}
		}
	}

	/**
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	private function is_eligible_for_sync( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		$selection  = $order->get_meta( '_bgcs3_selection' );
		$courier_id = is_array( $selection ) && ! empty( $selection['courier'] ) ? $selection['courier'] : '';
		if ( '' === $courier_id || ! Tracking_Sync::is_enabled( $courier_id ) ) {
			return false;
		}

		// Couriers do not keep shipment data forever — Speedy state plainly that
		// anything older than six months returns "Shipment data is no longer
		// available", and that such shipments should not be tracked. Asking
		// anyway buys an error per order per cycle, for ever.
		$label   = $order->get_meta( '_bgcs3_label' );
		$created = ( is_array( $label ) && ! empty( $label['created_at'] ) ) ? (int) $label['created_at'] : 0;
		if ( $created > 0 && ( time() - $created ) > self::MAX_TRACKING_AGE ) {
			return false;
		}

		$tracking = $order->get_meta( '_bgcs3_tracking' );

		if ( is_array( $tracking ) && ! empty( $tracking['state'] )
			&& Tracking_State::is_terminal( Tracking_State::sanitize( $tracking['state'] ) )
		) {
			// Rule 257 — a confirmed terminal state stops automatic polling.
			// Manual "Обнови" in the order screen remains available regardless.
			return false;
		}

		$last_synced_at = ( is_array( $tracking ) && ! empty( $tracking['updated_at'] ) ) ? (int) $tracking['updated_at'] : 0;
		return Tracking_Sync::is_due( $last_synced_at, Tracking_Sync::interval_minutes( $courier_id ) );
	}

	/**
	 * Update tracking for a single order and transition its WooCommerce status if mapped.
	 *
	 * @param int    $order_id WooCommerce Order ID.
	 * @param string $source   polling|cron|webhook_refresh.
	 */
	public function update_order_tracking_status( $order_id, $source = 'polling' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$selection = $order->get_meta( '_bgcs3_selection' );
		$courier_id = is_array( $selection ) && ! empty( $selection['courier'] ) ? $selection['courier'] : '';

		/** @var \BgCommerce3\Module\Module_Registry $registry */
		$registry = $this->container['modules'];
		$courier  = $registry->get( $courier_id );

		if ( ! $courier instanceof Courier_Interface || ! $courier->is_enabled() ) {
			return;
		}

		// Query fresh tracking result from courier API. Rule 256 — a failed
		// call must leave the previously-persisted history/state untouched,
		// never wipe or downgrade what is already known.
		$result = $courier->tracking( $order );

		$this->apply_tracking( $order, $courier, $result, $source );
	}

	/**
	 * Apply one already-authenticated push event to the canonical tracking store.
	 *
	 * Courier webhooks are authoritative event notifications once their courier-
	 * specific signature has been verified. Persisting the event first means a
	 * temporary provider API outage cannot make us lose a real state change. A
	 * subsequent API refresh may enrich the history, but does not gate the push.
	 *
	 * @param int                 $order_id   WooCommerce order id.
	 * @param string              $courier_id Courier module id.
	 * @param array<string,mixed> $event      Canonical event: time/code/text.
	 * @return void
	 */
	public function apply_pushed_tracking_event( $order_id, $courier_id, $event ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! is_array( $event ) ) {
			return;
		}

		/** @var \BgCommerce3\Module\Module_Registry $registry */
		$registry = $this->container['modules'];
		$courier  = $registry->get( sanitize_key( (string) $courier_id ) );
		if ( ! $courier instanceof Courier_Interface || ! $courier->is_enabled() ) {
			return;
		}

		$code = isset( $event['code'] ) ? sanitize_key( (string) $event['code'] ) : '';
		if ( '' === $code ) {
			return;
		}

		$event = array(
			'time'     => isset( $event['time'] ) && '' !== trim( (string) $event['time'] ) ? (string) $event['time'] : gmdate( 'c' ),
			'code'     => $code,
			'text'     => isset( $event['text'] ) ? sanitize_text_field( (string) $event['text'] ) : $code,
			'event_id' => isset( $event['event_id'] ) ? sanitize_text_field( (string) $event['event_id'] ) : '',
			'source'   => 'webhook',
		);

		$tracking        = $order->get_meta( '_bgcs3_tracking' );
		$tracking        = is_array( $tracking ) ? $tracking : array();
		$existing_events = ! empty( $tracking['events'] ) && is_array( $tracking['events'] ) ? $tracking['events'] : array();
		$merged_events   = Tracking_Store::merge( $existing_events, array( $event ) );
		Tracking_Unmapped_Registry::record_event( $courier, $event );
		$latest_state    = Tracking_Status_Policy::latest_state( $courier, $merged_events );

		$tracking['events']     = $merged_events;
		$tracking['state']      = $latest_state;
		$tracking['normalized_status'] = $latest_state;
		$tracking['status']     = $code;
		$tracking['raw_status'] = $code;
		$tracking['source']     = 'webhook';
		$tracking['updated_at'] = time();
		$order->update_meta_data( '_bgcs3_tracking', $tracking );

		Tracking_Status_Policy::apply_to_order( $order, $latest_state, $courier->id() );
		$order->save();
	}

	/**
	 * Persist one courier tracking result onto one order.
	 *
	 * Split out so the batched path below and the single-order path above reach
	 * WooCommerce through exactly the same steps. Batching is an optimisation of
	 * how the data is FETCHED; it must never become a second opinion about what
	 * the data MEANS.
	 *
	 * @param \WC_Order         $order   Order.
	 * @param Courier_Interface $courier Courier module.
	 * @param mixed             $result  Tracking_Result.
	 * @param string            $source  Acquisition source.
	 */
	private function apply_tracking( $order, Courier_Interface $courier, $result, $source = 'polling' ) {
		if ( ! $result || ! $result->success ) {
			return;
		}

		// Rule 250 — accumulate + deduplicate rather than overwrite, so a
		// provider that only returns partial/incremental history never loses
		// older events, and one that returns its full history every time
		// (the common case) never produces duplicates.
		$existing_tracking = $order->get_meta( '_bgcs3_tracking' );
		$existing_events    = ( is_array( $existing_tracking ) && ! empty( $existing_tracking['events'] ) ) ? $existing_tracking['events'] : array();
		$incoming_events    = ( ! empty( $result->events ) && is_array( $result->events ) )
			? Tracking_Store::with_source( $result->events, $source )
			: array();
		$merged_events      = Tracking_Store::merge( $existing_events, $incoming_events );
		foreach ( $incoming_events as $incoming_event ) {
			if ( is_array( $incoming_event ) ) {
				Tracking_Unmapped_Registry::record_event( $courier, $incoming_event );
			}
		}

		// Rule 41/252 — normalize once, reuse for both the WC-status decision
		// below and the persisted "current shipment state" used for display
		// (Rule 76/240 order-screen badges), instead of computing it twice.
		$latest_state = Tracking_Status_Policy::latest_state( $courier, $merged_events );
		if ( Tracking_State::UNKNOWN === $latest_state && ! empty( $result->status ) ) {
			$reported_state = Tracking_State::sanitize( $courier->normalize_status( array( 'code' => (string) $result->status, 'status' => (string) $result->status ) ) );
			if ( Tracking_State::UNKNOWN === $reported_state ) {
				Tracking_Unmapped_Registry::record_code( $courier->id(), (string) $result->status );
			}
			if ( Tracking_State::UNKNOWN !== $reported_state ) {
				$latest_state = $reported_state;
			}
		}

		$tracking_data           = $result->to_array();
		$tracking_data['events'] = $merged_events;
		$tracking_data['state']  = $latest_state;
		$tracking_data['normalized_status'] = $latest_state;
		$raw_status = ! empty( $result->status ) ? sanitize_text_field( (string) $result->status ) : Tracking_Store::latest_raw_status( $merged_events );
		$tracking_data['status'] = $raw_status;
		$tracking_data['raw_status'] = $raw_status;
		$tracking_data['source'] = in_array( sanitize_key( (string) $source ), Tracking_Store::SOURCES, true ) ? sanitize_key( (string) $source ) : 'polling';
		$order->update_meta_data( '_bgcs3_tracking', $tracking_data );

		// Some courier status contracts (currently Econt ShipmentStatus) return
		// accounting facts alongside tracking. Reconcile only an explicit PAID
		// amount + currency + timestamp, and only when they match the shipment's
		// stored COD snapshot exactly. Collected is not paid, and a mismatch never
		// changes the order's financial state.
		Cod_Payout::apply_from_tracking(
			$order,
			$courier->id(),
			isset( $tracking_data['provider'] ) && is_array( $tracking_data['provider'] ) ? $tracking_data['provider'] : array()
		);

		Tracking_Status_Policy::apply_to_order( $order, $latest_state, $courier->id() );
		$order->save();
	}

}
