<?php
/**
 * BOX NOW runtime helpers that were previously owned by the standalone
 * add-on bootstrap. They are intentionally global WordPress callbacks but use
 * the BGCS 3 prefix so they cannot collide with an inactive/legacy stack.
 *
 * @package BgCommerce3
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BGCS3_BOXNOW_MAP_ORIGIN' ) ) {
	define( 'BGCS3_BOXNOW_MAP_ORIGIN', 'https://map.boxnow.bg' );
}
if ( ! defined( 'BGCS3_BOXNOW_MAP_SRC' ) ) {
	define( 'BGCS3_BOXNOW_MAP_SRC', BGCS3_BOXNOW_MAP_ORIGIN . '/iframe.html' );
}

function bgcs3_boxnow_admin_enqueue() {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'bgcs3-settings' !== $page || 'boxnow' !== $tab ) {
		return;
	}
	wp_enqueue_style( 'bgcs3-boxnow-admin', BGCS3_URL . 'assets/modules/boxnow/css/boxnow-admin.css', array( 'bgcs-admin' ), BGCS3_VERSION );
	wp_enqueue_script( 'bgcs3-boxnow-admin', BGCS3_URL . 'assets/modules/boxnow/js/boxnow-admin.js', array( 'bgcs-admin-settings' ), BGCS3_VERSION, true );
	wp_localize_script(
		'bgcs3-boxnow-admin',
		'bgcsBoxNowAdmin',
		array(
			'incomplete'   => __( 'Enter “From” and “Price” for every range used.', 'bg-commerce-suite' ),
			'invalidRange' => __( 'The “To” field must be greater than “From”.', 'bg-commerce-suite' ),
			'overlap'      => __( 'Ranges must not overlap.', 'bg-commerce-suite' ),
			'openEnded'    => __( 'A range without an upper limit must be last.', 'bg-commerce-suite' ),
		)
	);
}

function bgcs3_boxnow_selector_slot() {
	?>
	<div class="bgcs-boxnow-picker" hidden>
		<button type="button" id="bgcs-boxnow-open" class="bgcs-boxnow-picker__open"><?php esc_html_e( 'Select BOX NOW locker', 'bg-commerce-suite' ); ?></button>
		<div id="bgcs-boxnow-selection" class="bgcs-boxnow-selection" role="status" aria-live="polite" hidden></div>
	</div>
	<div id="bgcs-boxnow-dialog" class="bgcs-boxnow-modal" role="dialog" aria-modal="true" aria-labelledby="bgcs-boxnow-dialog-title" hidden>
		<div class="bgcs-boxnow-modal__backdrop" data-bgcs-boxnow-close></div>
		<div class="bgcs-boxnow-modal__panel" role="document" tabindex="-1">
			<div class="bgcs-boxnow-modal__header">
				<h2 id="bgcs-boxnow-dialog-title"><?php esc_html_e( 'Select a BOX NOW locker', 'bg-commerce-suite' ); ?></h2>
				<button type="button" class="bgcs-boxnow-modal__close" data-bgcs-boxnow-close aria-label="<?php esc_attr_e( 'Close map', 'bg-commerce-suite' ); ?>">&times;</button>
			</div>
			<div id="boxnowmap"></div>
		</div>
	</div>
	<?php
}

function bgcs3_boxnow_enqueue() {
	if ( ! wp_script_is( 'bgcs-checkout', 'enqueued' ) ) {
		return;
	}
	wp_enqueue_style( 'bgcs3-boxnow', BGCS3_URL . 'assets/modules/boxnow/css/boxnow-checkout.css', array( 'bgcs-checkout' ), BGCS3_VERSION );
	wp_enqueue_script( 'bgcs3-boxnow', BGCS3_URL . 'assets/modules/boxnow/js/boxnow-checkout.js', array( 'jquery', 'bgcs-checkout' ), BGCS3_VERSION, true );
	wp_localize_script(
		'bgcs3-boxnow',
		'bgcsBoxNow',
		array(
			'partnerId'   => bgcs3_get_option( 'boxnow', 'partner_id', '' ),
			'env'         => \BgCommerce3\Support\Module_Settings::get( 'boxnow', 'env' ),
			'mapSrc'      => BGCS3_BOXNOW_MAP_SRC,
			'mapOrigin'   => BGCS3_BOXNOW_MAP_ORIGIN,
			'countryCode' => apply_filters( 'bgcs3_boxnow_widget_country', 'bg' ),
			'language'    => apply_filters( 'bgcs3_boxnow_widget_language', 'bg' ),
			'gps'         => 'yes' === \BgCommerce3\Support\Module_Settings::get( 'boxnow', 'widget_gps' ) ? 'yes' : 'no',
			'i18n'        => array(
				'noPartner' => __( 'BOX NOW is not configured (Partner ID is missing).', 'bg-commerce-suite' ),
				'failed'    => __( 'The BOX NOW locker map failed to load. Try again or reload the page.', 'bg-commerce-suite' ),
				'mapTitle'  => __( 'BOX NOW locker map', 'bg-commerce-suite' ),
			),
		)
	);
}
