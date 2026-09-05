<?php
/**
 * Background reconciliation of courier-confirmed COD payouts onto WooCommerce
 * orders. Separate from tracking because payout reports change much more slowly.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Background;

use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Shipping\Cod;
use BgCommerce3\Shipping\Cod_Payout;
use BgCommerce3\Shipping\Cod_Payout_Sync_Settings;

defined( 'ABSPATH' ) || exit;

final class Cod_Payout_Sync {

	const GROUP         = 'bgcs3';
	const SCHEDULE_HOOK = 'bgcs3_schedule_cod_payout_sync';
	const SYNC_HOOK     = 'bgcs3_sync_cod_payouts';

	/** Dispatcher frequency. Per-courier due checks keep payout API calls sparse. */
	const DISPATCH_INTERVAL = HOUR_IN_SECONDS;

	/** A broken credential/provider should not be hammered every dispatcher tick. */
	const ERROR_RETRY_INTERVAL = 6 * HOUR_IN_SECONDS;

	/** Re-read two days on every successful continuation to catch delayed reports. */
	const OVERLAP_DAYS = 2;

	/** First-run/retry window. Safe for the one-calendar-month limits in APIs. */
	const MAX_LOOKBACK_DAYS = 29;

	/** @var Container */
	private $container;

	/**
	 * @param Container $container Core container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/** Wire scheduler hooks. */
	public function init() {
		add_action( 'init', array( $this, 'register_schedule' ) );
		add_action( self::SCHEDULE_HOOK, array( $this, 'dispatch' ) );
		add_action( self::SYNC_HOOK, array( $this, 'sync_courier' ), 10, 1 );
	}

	/** Register or retire the recurring dispatcher. */
	public function register_schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$scheduled = as_has_scheduled_action( self::SCHEDULE_HOOK, array(), self::GROUP );
		if ( ! $this->has_supported_courier() ) {
			if ( $scheduled && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::SCHEDULE_HOOK, array(), self::GROUP );
			}
			return;
		}

		if ( ! $scheduled ) {
			as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, self::DISPATCH_INTERVAL, self::SCHEDULE_HOOK, array(), self::GROUP );
		}
	}

	/** Queue only courier payout jobs that are actually due. */
	public function dispatch() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		foreach ( $this->supported_couriers() as $courier ) {
			$id    = $courier->id();
			$state = $this->state( $id );
			$last     = isset( $state['last_success_at'] ) ? (int) $state['last_success_at'] : 0;
			$interval = Cod_Payout_Sync_Settings::interval_minutes( $id ) * MINUTE_IN_SECONDS;
			if ( $last > 0 && ( time() - $last ) < $interval ) {
				continue;
			}
			$attempt = isset( $state['last_attempt_at'] ) ? (int) $state['last_attempt_at'] : 0;
			if ( ! empty( $state['last_error'] ) && $attempt > 0 && ( time() - $attempt ) < self::ERROR_RETRY_INTERVAL ) {
				continue;
			}

			$args = array( 'courier' => $id );
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::SYNC_HOOK, $args, self::GROUP ) ) {
				continue;
			}
			as_enqueue_async_action( self::SYNC_HOOK, $args, self::GROUP );
		}
	}

	/**
	 * Fetch one courier's recent payout report and reconcile exact order matches.
	 *
	 * @param string $courier_id Courier id.
	 */
	public function sync_courier( $courier_id ) {
		$courier_id = sanitize_key( (string) $courier_id );
		$courier    = $this->container['modules']->get( $courier_id );
		if ( ! $this->is_supported( $courier ) ) {
			return;
		}

		$state  = $this->state( $courier_id );
		$window = self::date_window(
			current_time( 'Y-m-d' ),
			isset( $state['last_to'] ) ? (string) $state['last_to'] : ''
		);
		$orders = $this->pending_waybill_map( $courier_id );
		if ( empty( $orders ) ) {
			$this->save_state(
				$courier_id,
				array(
					'last_attempt_at' => time(),
					'last_success_at' => time(),
					'last_to'         => $window['to'],
					'from'            => $window['from'],
					'to'              => $window['to'],
					'last_error'      => '',
					'counts'          => array( 'rows' => 0, 'updated' => 0, 'already_paid' => 0, 'mismatch' => 0, 'unmatched' => 0, 'duplicate' => 0 ),
				)
			);
			return;
		}

		$rows = $courier->cod_payouts( $window['from'], $window['to'] );
		if ( is_wp_error( $rows ) ) {
			$state['last_attempt_at'] = time();
			$state['last_error']      = sanitize_text_field( $rows->get_error_message() );
			$state['from']            = $window['from'];
			$state['to']              = $window['to'];
			$this->save_state( $courier_id, $state );
			return;
		}
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$counts = array(
			'rows'         => count( $rows ),
			'updated'      => 0,
			'already_paid' => 0,
			'mismatch'     => 0,
			'unmatched'    => 0,
			'duplicate'    => 0,
		);
		$seen = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$waybill = isset( $row['waybill'] ) ? Cod_Payout::normalize_waybill( $row['waybill'] ) : '';
			if ( '' === $waybill || ! isset( $orders[ $waybill ] ) ) {
				$counts['unmatched']++;
				continue;
			}
			$row_fingerprint = Cod_Payout::row_fingerprint( $row );
			if ( isset( $seen[ $waybill ] ) && hash_equals( $seen[ $waybill ], $row_fingerprint ) ) {
				$counts['duplicate']++;
				continue;
			}
			$seen[ $waybill ] = $row_fingerprint;

			$result = Cod_Payout::apply_row( $orders[ $waybill ], $row, 'background_api' );
			if ( isset( $counts[ $result ] ) ) {
				$counts[ $result ]++;
			}
		}

		$this->save_state(
			$courier_id,
			array(
				'last_attempt_at' => time(),
				'last_success_at' => time(),
				'last_to'         => $window['to'],
				'from'            => $window['from'],
				'to'              => $window['to'],
				'last_error'      => '',
				'counts'          => $counts,
			)
		);
	}

	/**
	 * Safe recent date window. Public/pure so it can be regression-tested.
	 *
	 * @param string $today   Current date Y-m-d.
	 * @param string $last_to Previous successful upper bound Y-m-d.
	 * @return array{from:string,to:string}
	 */
	public static function date_window( $today, $last_to = '' ) {
		$today_dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $today );
		if ( ! $today_dt ) {
			$today_dt = new \DateTimeImmutable( 'today', new \DateTimeZone( 'UTC' ) );
		}
		$floor = $today_dt->modify( '-' . self::MAX_LOOKBACK_DAYS . ' days' );
		$from  = $floor;

		$last_dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $last_to );
		if ( $last_dt && $last_dt <= $today_dt ) {
			$candidate = $last_dt->modify( '-' . self::OVERLAP_DAYS . ' days' );
			if ( $candidate > $floor ) {
				$from = $candidate;
			}
		}

		return array(
			'from' => $from->format( 'Y-m-d' ),
			'to'   => $today_dt->format( 'Y-m-d' ),
		);
	}

	/** @return Courier_Interface[] */
	private function supported_couriers() {
		$out = array();
		$grouped = $this->container['modules']->by_category();
		$modules = isset( $grouped[ \BgCommerce3\Module\Categories::SHIPPING ] ) ? $grouped[ \BgCommerce3\Module\Categories::SHIPPING ] : array();
		foreach ( $modules as $module ) {
			if ( $this->is_supported( $module ) ) {
				$out[] = $module;
			}
		}
		return $out;
	}

	/** @return bool */
	private function has_supported_courier() {
		return ! empty( $this->supported_couriers() );
	}

	/**
	 * @param mixed $module Module.
	 * @return bool
	 */
	private function is_supported( $module ) {
		return $module instanceof Courier_Interface
			&& $module->is_enabled()
			&& Cod_Payout_Sync_Settings::supports( $module )
			&& Cod_Payout_Sync_Settings::is_enabled( $module->id() );
	}

	/**
	 * Exact non-ambiguous pending COD waybill map for one courier.
	 *
	 * @param string $courier_id Courier.
	 * @return array<string,\WC_Order>
	 */
	private function pending_waybill_map( $courier_id ) {
		$map       = array();
		$ambiguous = array();
		$page      = 1;
		do {
			$result = wc_get_orders(
				array(
					'limit'          => 200,
					'paged'          => $page,
					'paginate'       => true,
					'meta_key'       => '_bgcs3_label', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare'   => 'EXISTS',
				)
			);
			if ( ! is_object( $result ) || ! isset( $result->orders, $result->max_num_pages ) ) {
				break;
			}

			foreach ( $result->orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				if ( 'yes' === (string) $order->get_meta( Cod_Payout::META_PAID ) ) {
					continue;
				}
				$expected = Cod_Payout::expected( $order );
				if ( $courier_id !== $expected['courier'] || empty( $expected['is_cod'] ) || strlen( $expected['waybill'] ) < 4 ) {
					continue;
				}

				$waybill = (string) $expected['waybill'];
				if ( isset( $map[ $waybill ] ) && (int) $map[ $waybill ]->get_id() !== (int) $order->get_id() ) {
					$ambiguous[ $waybill ] = true;
					continue;
				}
				$map[ $waybill ] = $order;
			}
			$page++;
		} while ( $page <= (int) $result->max_num_pages );

		foreach ( array_keys( $ambiguous ) as $waybill ) {
			unset( $map[ $waybill ] );
		}

		return $map;
	}

	/** @return array<string,mixed> */
	private function state( $courier_id ) {
		$state = get_option( $this->state_key( $courier_id ), array() );
		return is_array( $state ) ? $state : array();
	}

	/** @param string $courier_id Courier. @param array<string,mixed> $state State. */
	private function save_state( $courier_id, array $state ) {
		update_option( $this->state_key( $courier_id ), $state, false );
	}

	/** @return string */
	private function state_key( $courier_id ) {
		return 'bgcs3_cod_payout_sync_state_' . sanitize_key( (string) $courier_id );
	}
}
