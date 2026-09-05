<?php
/**
 * Core-owned canonical tracking-event helpers (Rule 260): accumulate,
 * deduplicate (Rule 250), and order (Rule 251/252) events across repeated
 * provider syncs. No courier plugin stores or sorts its own history — every
 * courier just hands Core raw events, this class is the single place that
 * decides what survives and in what order.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Shipping;

defined( 'ABSPATH' ) || exit;

final class Tracking_Store {

	/** Allowed acquisition sources stored with canonical tracking events. */
	const SOURCES = array( 'webhook', 'webhook_refresh', 'polling', 'cron', 'manual' );

	/**
	 * Attach the acquisition source to newly received events without changing a
	 * provider/webhook source that is already present.
	 *
	 * @param array<int,array<string,mixed>> $events Events from one fetch.
	 * @param string                         $source Acquisition source.
	 * @return array<int,array<string,mixed>>
	 */
	public static function with_source( array $events, $source ) {
		$source = sanitize_key( (string) $source );
		$source = in_array( $source, self::SOURCES, true ) ? $source : 'polling';

		$out = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$event_source = isset( $event['source'] ) ? sanitize_key( (string) $event['source'] ) : '';
			$event['source'] = in_array( $event_source, self::SOURCES, true ) ? $event_source : $source;
			$out[] = $event;
		}
		return $out;
	}

	/**
	 * Latest raw provider code by occurred-at time.
	 *
	 * @param array<int,array<string,mixed>> $events Canonical event history.
	 * @return string
	 */
	public static function latest_raw_status( array $events ) {
		foreach ( self::sort_by_time( $events, true ) as $event ) {
			if ( is_array( $event ) && isset( $event['code'] ) && '' !== trim( (string) $event['code'] ) ) {
				return sanitize_text_field( (string) $event['code'] );
			}
		}
		return '';
	}

	/**
	 * Merge freshly-fetched events into the already-persisted history.
	 *
	 * A provider may return its full history on every call, or only the
	 * newest entries — Rule 250 requires this to be safe either way, so the
	 * union is deduplicated rather than one side simply replacing the other.
	 *
	 * @param array<int,array<string,mixed>> $existing Already-persisted events.
	 * @param array<int,array<string,mixed>> $incoming Freshly-fetched events (this sync).
	 * @return array<int,array<string,mixed>> Deduplicated union, existing order preserved first.
	 */
	public static function merge( array $existing, array $incoming ) {
		$seen  = array();
		$union = array();

		foreach ( array_merge( $existing, $incoming ) as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$key = self::fingerprint( $event );
			if ( isset( $seen[ $key ] ) ) {
				$index = $seen[ $key ];
				if ( empty( $union[ $index ]['source'] ) && ! empty( $event['source'] ) ) {
					$source = sanitize_key( (string) $event['source'] );
					if ( in_array( $source, self::SOURCES, true ) ) {
						$union[ $index ]['source'] = $source;
					}
				}
				continue;
			}
			$seen[ $key ] = count( $union );
			$union[]      = $event;
		}

		return $union;
	}

	/**
	 * Stable dedup key for one event (Rule 250).
	 *
	 * Rule 11 — none of the four couriers in this codebase populate a
	 * documented, stable provider event id in `fill_tracking()` (verified by
	 * reading all four), so this always uses the canonical fingerprint
	 * fallback the rule describes: courier code + occurred_at + description
	 * (+ location, when a courier does provide one). An `event_id` key is
	 * still honored first, for whenever a courier adds one.
	 *
	 * @param array<string,mixed> $event Tracking event.
	 * @return string
	 */
	public static function fingerprint( array $event ) {
		if ( ! empty( $event['event_id'] ) ) {
			return 'id:' . (string) $event['event_id'];
		}

		$parts = array(
			isset( $event['code'] ) ? (string) $event['code'] : '',
			isset( $event['time'] ) ? (string) $event['time'] : '',
			isset( $event['text'] ) ? (string) $event['text'] : '',
			isset( $event['location'] ) ? (string) $event['location'] : '',
		);

		return 'fp:' . md5( implode( '|', $parts ) );
	}

	/**
	 * Sort events by actual occurred-at time (Rule 251/252) — never by
	 * provider array order or sync time. Shared by the display timeline and
	 * by "current state" resolution so both always agree.
	 *
	 * @param array<int,array<string,mixed>> $events Events.
	 * @param bool                            $desc   True = newest first.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_by_time( array $events, $desc = true ) {
		$with_key = array();
		foreach ( $events as $index => $event ) {
			$time       = ( is_array( $event ) && isset( $event['time'] ) ) ? $event['time'] : '';
			$with_key[] = array( self::timestamp( $time ), $index, $event );
		}

		usort(
			$with_key,
			static function ( $a, $b ) use ( $desc ) {
				if ( $a[0] === $b[0] ) {
					return $desc ? ( $b[1] <=> $a[1] ) : ( $a[1] <=> $b[1] );
				}
				return $desc ? ( $b[0] <=> $a[0] ) : ( $a[0] <=> $b[0] );
			}
		);

		return array_map(
			static function ( $entry ) {
				return $entry[2];
			},
			$with_key
		);
	}

	/**
	 * @param mixed $time Raw event time value (unix seconds, ms epoch, or date string).
	 * @return int Unix timestamp (seconds) — used only for sorting, never displayed.
	 */
	public static function timestamp( $time ) {
		if ( is_numeric( $time ) ) {
			$number = (float) $time;
			// Millisecond epoch (13 digits) vs second epoch (10 digits).
			return (int) ( $number > 100000000000 ? $number / 1000 : $number );
		}
		$parsed = is_string( $time ) ? strtotime( $time ) : false;
		return false !== $parsed ? $parsed : 0;
	}
}
