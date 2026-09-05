<?php
/**
 * BG Commerce Suite uninstall.
 *
 * Intentionally conservative: legacy BGCS options/files are never touched, and
 * order/shipment history stays available. Only the unified `bgcs3_` option
 * namespace and BGCS-owned scheduled work are removed.
 *
 * BGCS-AUDIT-014: `$wpdb->options` points at the CURRENT site's table, so on a
 * network this used to clean one site and leave every other site's settings
 * behind — which then reappear unexpectedly on a re-install. Sites are walked
 * in batches rather than fetched with `number => 0`, so a very large network
 * does not have to materialise every site id at once.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove BGCS 3 options and transients for the site that is currently active.
 *
 * @return void
 */
function bgcs3_uninstall_site() {
	global $wpdb;

	// Only the unified option namespace. Keep order meta and provider history.
	// `_transient_timeout_bgcs3_*` is matched explicitly: the timeout rows do
	// not start with `_transient_bgcs3_`, so the previous two patterns left them.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall cleanup only.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			  WHERE option_name LIKE %s
			     OR option_name LIKE %s
			     OR option_name LIKE %s",
			$wpdb->esc_like( 'bgcs3_' ) . '%',
			'_transient_' . $wpdb->esc_like( 'bgcs3_' ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( 'bgcs3_' ) . '%'
		)
	);
}

/**
 * Unschedule BGCS-owned Action Scheduler work.
 *
 * `bgcs3_deactivate()` already does this on deactivation, which covers the
 * normal flow — but uninstalling directly (e.g. deleting a plugin that was
 * never deactivated through the UI) does not run that hook.
 *
 * The hook list mirrors `bgcs3_deactivate()` in `bg-commerce-suite.php`; the
 * three background classes all schedule into the single `bgcs3` group.
 *
 * @return void
 */
function bgcs3_uninstall_scheduled_actions() {
	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		return;
	}

	foreach ( array(
		'bgcs3_schedule_tracking_sync',
		'bgcs3_update_order_tracking_status',
		'bgcs3_update_orders_tracking_status',
		'bgcs3_schedule_cod_payout_sync',
		'bgcs3_sync_cod_payouts',
		'bgcs3_sync_locations',
		'bgcs3_sync_product_catalog',
	) as $hook ) {
		as_unschedule_all_actions( $hook, array(), 'bgcs3' );
	}
}

if ( is_multisite() ) {
	$bgcs3_offset = 0;
	do {
		$bgcs3_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $bgcs3_offset,
			)
		);

		foreach ( $bgcs3_site_ids as $bgcs3_site_id ) {
			switch_to_blog( (int) $bgcs3_site_id );
			bgcs3_uninstall_site();
			bgcs3_uninstall_scheduled_actions();
			restore_current_blog();
		}

		$bgcs3_offset += 100;
	} while ( count( $bgcs3_site_ids ) === 100 );
} else {
	bgcs3_uninstall_site();
	bgcs3_uninstall_scheduled_actions();
}
