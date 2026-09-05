<?php
/**
 * Order admin metabox: shows the customer's delivery selection and lets the
 * merchant create/cancel a waybill and refresh tracking — courier-agnostic
 * (delegates to the courier module resolved from the saved selection).
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Admin\Order;

use BgCommerce3\Admin\Icons;
use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Shipping\Cod;
use BgCommerce3\Shipping\Cod_Payout;
use BgCommerce3\Shipping\Creation_Lock;
use BgCommerce3\Shipping\Label_Snapshot;
use BgCommerce3\Shipping\Overrides;
use BgCommerce3\Shipping\Pickup_Request;
use BgCommerce3\Shipping\Shipment_Creation;
use BgCommerce3\Shipping\Shipment_Mutation;
use BgCommerce3\Shipping\Tracking_State;
use BgCommerce3\Shipping\Tracking_Status_Policy;
use BgCommerce3\Shipping\Tracking_Unmapped_Registry;
use BgCommerce3\Shipping\Tracking_Store;
use BgCommerce3\Support\Shipment_Diagnostics;

defined( 'ABSPATH' ) || exit;

class MetaBox {

	const NONCE = 'bgcs3_order';

	/** @var Container */
	private $container;

	/**
	 * @param Container $container Core DI container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		add_action( 'wp_ajax_bgcs3_create_label', array( $this, 'ajax_create_label' ) );
		add_action( 'wp_ajax_bgcs3_update_label', array( $this, 'ajax_update_label' ) );
		add_action( 'wp_ajax_bgcs3_delete_label', array( $this, 'ajax_delete_label' ) );
		add_action( 'wp_ajax_bgcs3_refresh_tracking', array( $this, 'ajax_refresh_tracking' ) );
		add_action( 'wp_ajax_bgcs3_resend_shipment_email', array( $this, 'ajax_resend_shipment_email' ) );
		add_action( 'wp_ajax_bgcs3_save_selection', array( $this, 'ajax_save_selection' ) );
	}

	public function add() {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box(
				'bgcs3_order',
				__( 'BG Commerce Suite — Shipping', 'bg-commerce-suite' ),
				array( $this, 'render' ),
				$screen,
				'normal',
				'high'
			);
		}
	}

	/**
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		if ( ! \BgCommerce3\Admin\Admin_Screen::is_order() ) {
			return;
		}

		wp_enqueue_style( 'bgcs-admin', BGCS3_URL . 'assets/admin/admin.css', array(), BGCS3_VERSION );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script( 'bgcs-admin-order', BGCS3_URL . 'assets/admin/order.js', array( 'jquery', 'wc-enhanced-select' ), BGCS3_VERSION, true );
		wp_localize_script(
			'bgcs-admin-order',
			'bgcsOrder',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'restUrl' => esc_url_raw( rest_url( 'bg-commerce-suite/v3/admin/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'confirmDelete' => __( 'Cancel this shipment at the courier? BGCS will keep it active until the courier confirms cancellation.', 'bg-commerce-suite' ),
					'working'       => __( 'Please wait…', 'bg-commerce-suite' ),
					'error'         => __( 'Error.', 'bg-commerce-suite' ),
					'loadError'     => __( 'The directory is unavailable. The saved value remains unchanged.', 'bg-commerce-suite' ),
					// %d е буквален placeholder, заменян client-side с номера на пакета.
					'packLabel'     => __( 'Package %d', 'bg-commerce-suite' ),
				),
			)
		);
	}

	/**
	 * @param mixed $post_or_order WP_Post or WC_Order.
	 */
	public function render( $post_or_order ) {
		$order = ( $post_or_order instanceof \WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$selection = $order->get_meta( '_bgcs3_selection' );
		$label     = $order->get_meta( '_bgcs3_label' );
		$tracking  = $order->get_meta( '_bgcs3_tracking' );

		if ( empty( $selection ) ) {
			echo '<div class="bgcs-order-box"><p class="bgcs-empty">' . esc_html__( 'No BG Commerce Suite delivery selection is available.', 'bg-commerce-suite' ) . '</p></div>';
			return;
		}

		$has_label  = ! empty( $label['number'] );
		$courier_id = is_array( $selection ) && ! empty( $selection['courier'] ) ? $selection['courier'] : '';
		$module     = $this->container['modules']->get( $courier_id );
		$courier    = ( $module ) ? $module->name() : $courier_id;
		$price_breakdown = array();
		if ( is_array( $label ) && ! empty( $label['meta']['price_breakdown'] ) && is_array( $label['meta']['price_breakdown'] ) ) {
			$price_breakdown = $label['meta']['price_breakdown'];
		} else {
			$stored_breakdown = $order->get_meta( '_bgcs3_price_breakdown' );
			if ( is_array( $stored_breakdown ) ) {
				$price_breakdown = $stored_breakdown;
			}
		}

		// BGCS courier workspace uses a fixed low-click initial state. The shipment
		// panel is always expanded; the active tab is chosen by shipment state.
		echo '<div class="bgcs-order-box" data-order-id="' . esc_attr( $order->get_id() ) . '">';
		echo '<div class="bgcs-order-panels">';

		$courier_logo = Icons::courier_logo( $courier_id, $courier );

		if ( $has_label ) {
			$this->render_existing_shipment( $order, $selection, $label, $tracking, $module, $courier_id, $courier, $courier_logo, $price_breakdown );
		} else {
			$this->render_pending_shipment( $order, $selection, $module, $courier_id, $courier, $courier_logo, $price_breakdown );
		}

		echo '</div>'; // .bgcs-order-panels
		echo '<p class="bgcs-order-msg" role="status"></p>';
		echo '</div>'; // .bgcs-order-box
	}

	/**
	 * Compact pre-label workspace. It deliberately uses the same shell/tabs as
	 * an existing shipment, so creating a label never swaps the merchant into a
	 * completely different admin UI. The panel is open on first paint and the
	 * Overview tab exposes the few fields/actions needed most often.
	 *
	 * @param \WC_Order $order Order.
	 * @param array<string,mixed> $selection Saved delivery selection.
	 * @param Courier_Interface|null $module Courier module.
	 * @param string $courier_id Courier id.
	 * @param string $courier Courier name.
	 * @param string $courier_logo Trusted courier logo html.
	 * @param array<string,mixed> $price_breakdown Price receipt.
	 * @return void
	 */
	/**
	 * Shared editable delivery fields. Used both before label creation and when
	 * editing an existing shipment, so the exact destination that is visible in
	 * the UI is also what create/update sends to the courier.
	 *
	 * @param array<string,mixed> $selection Saved selection.
	 * @param string              $courier_id Courier id.
	 * @return void
	 */
	private function render_delivery_editor_fields( array $selection, $courier_id ) {
		$dt        = isset( $selection['delivery_type'] ) ? (string) $selection['delivery_type'] : '';
		$city_id   = isset( $selection['city']['id'] ) ? (string) $selection['city']['id'] : '';
		$city      = isset( $selection['city']['name'] ) ? (string) $selection['city']['name'] : '';
		$pc        = isset( $selection['city']['post_code'] ) ? (string) $selection['city']['post_code'] : '';
		$off_id    = isset( $selection['office']['id'] ) ? (string) $selection['office']['id'] : '';
		$off_txt   = isset( $selection['office']['text'] ) ? (string) $selection['office']['text'] : '';
		$street    = isset( $selection['address']['street'] ) ? (string) $selection['address']['street'] : '';
		$street_id = isset( $selection['address']['street_id'] ) ? (string) $selection['address']['street_id'] : '';
		$num       = isset( $selection['address']['num'] ) ? (string) $selection['address']['num'] : '';
		$block     = isset( $selection['address']['block'] ) ? (string) $selection['address']['block'] : '';
		$entrance  = isset( $selection['address']['entrance'] ) ? (string) $selection['address']['entrance'] : '';
		$floor     = isset( $selection['address']['floor'] ) ? (string) $selection['address']['floor'] : '';
		$apartment = isset( $selection['address']['apartment'] ) ? (string) $selection['address']['apartment'] : '';
		$note      = isset( $selection['address']['note'] ) ? (string) $selection['address']['note'] : '';

		$this->f_select( __( 'Type', 'bg-commerce-suite' ), 'bgcs-edit-type', $dt, array( 'office' => __( 'Office', 'bg-commerce-suite' ), 'locker' => __( 'Locker', 'bg-commerce-suite' ), 'address' => __( 'Address', 'bg-commerce-suite' ) ) );
		$this->f_search_select( __( 'City', 'bg-commerce-suite' ), 'bgcs-order-city-search', $city_id, $city, 'cities', $courier_id );
		$this->f_text( __( 'Postcode', 'bg-commerce-suite' ), 'bgcs-edit-pc', $pc );
		$this->f_search_select( __( 'Office/Locker', 'bg-commerce-suite' ), 'bgcs-order-office-search', $off_id, $off_txt, 'offices', $courier_id );
		$street_hint = ( '' === $street_id && '' !== trim( $street ) )
			? sprintf( __( 'Current: %s — saved without a directory ID. Select from the list only if you want to replace it.', 'bg-commerce-suite' ), $street )
			: '';
		$this->f_search_select( __( 'Street (for address)', 'bg-commerce-suite' ), 'bgcs-order-street-search', $street_id, $street, 'streets', $courier_id, 'bgcs-order-city-search', $street_hint );
		$this->f_text( __( 'Number (for address)', 'bg-commerce-suite' ), 'bgcs-edit-num', $num );
		$this->f_text( __( 'Block (for address)', 'bg-commerce-suite' ), 'bgcs-edit-block', $block );
		$this->f_text( __( 'Entrance (for address)', 'bg-commerce-suite' ), 'bgcs-edit-entrance', $entrance );
		$this->f_text( __( 'Floor (for address)', 'bg-commerce-suite' ), 'bgcs-edit-floor', $floor );
		$this->f_text( __( 'Apartment (for address)', 'bg-commerce-suite' ), 'bgcs-edit-apartment', $apartment );
		$this->f_text( __( 'Additional note', 'bg-commerce-suite' ), 'bgcs-edit-note', $note );
	}

	private function render_pending_shipment( \WC_Order $order, array $selection, $module, $courier_id, $courier, $courier_logo, array $price_breakdown ) {
		$dt        = isset( $selection['delivery_type'] ) ? (string) $selection['delivery_type'] : '';
		$city_id   = isset( $selection['city']['id'] ) ? (string) $selection['city']['id'] : '';
		$city      = isset( $selection['city']['name'] ) ? (string) $selection['city']['name'] : '';
		$pc        = isset( $selection['city']['post_code'] ) ? (string) $selection['city']['post_code'] : '';
		$off_id    = isset( $selection['office']['id'] ) ? (string) $selection['office']['id'] : '';
		$off_txt   = isset( $selection['office']['text'] ) ? (string) $selection['office']['text'] : '';
		$street    = isset( $selection['address']['street'] ) ? (string) $selection['address']['street'] : '';
		$street_id = isset( $selection['address']['street_id'] ) ? (string) $selection['address']['street_id'] : '';
		$num       = isset( $selection['address']['num'] ) ? (string) $selection['address']['num'] : '';
		$block     = isset( $selection['address']['block'] ) ? (string) $selection['address']['block'] : '';
		$entrance  = isset( $selection['address']['entrance'] ) ? (string) $selection['address']['entrance'] : '';
		$floor     = isset( $selection['address']['floor'] ) ? (string) $selection['address']['floor'] : '';
		$apartment = isset( $selection['address']['apartment'] ) ? (string) $selection['address']['apartment'] : '';
		$note      = isset( $selection['address']['note'] ) ? (string) $selection['address']['note'] : '';

		$destination = '' !== $off_txt ? $off_txt : trim( $street . ' ' . $num );
		$header_bits = array_filter( array( __( 'No shipment label', 'bg-commerce-suite' ), $this->type_label( $dt ), $city ) );
		$mutation     = Shipment_Mutation::state( $order );
		$mutation_status = isset( $mutation['status'] ) ? (string) $mutation['status'] : '';
		$replacement_blocked = in_array( $mutation_status, array( Shipment_Mutation::CANCEL_PREPARING, Shipment_Mutation::CANCEL_PENDING, Shipment_Mutation::CANCEL_CONFIRMED, Shipment_Mutation::CANCEL_FAILED, Shipment_Mutation::CANCEL_AMBIGUOUS ), true );

		Panel::open(
			'bgcs-order-shipment-' . $order->get_id(),
			'package',
			$courier,
			implode( ' · ', $header_bits ),
			$replacement_blocked ? __( 'Replacement blocked', 'bg-commerce-suite' ) : __( 'Ready to generate', 'bg-commerce-suite' ),
			$replacement_blocked ? 'warning' : 'neutral',
			$courier_logo,
			'bgcs-order-panel--shipment bgcs-order-panel--pending',
			true
		);

		echo '<div class="bgcs-shipment-tabs" data-bgcs-order-tabs>';
		echo '<div class="bgcs-shipment-tabs__nav" role="tablist" aria-label="' . esc_attr__( 'Shipment', 'bg-commerce-suite' ) . '">';
		$this->shipment_tab_button( 'overview', __( 'Overview', 'bg-commerce-suite' ), true );
		$this->shipment_tab_button( 'label', __( 'Shipment label', 'bg-commerce-suite' ) );
		$this->shipment_tab_button( 'details', __( 'Details', 'bg-commerce-suite' ) );
		echo '</div>';

		// Overview: editable delivery + the two high-frequency actions.
		$this->shipment_tab_open( 'overview', true );
		$this->render_mutation_notice( $order, false );
		echo '<div class="bgcs-shipment-overview bgcs-shipment-overview--pending">';
		echo '<div class="bgcs-shipment-card bgcs-shipment-card--editor"><h4>' . esc_html__( 'Shipping', 'bg-commerce-suite' ) . '</h4><div class="bgcs-fieldgrid">';
		$this->render_delivery_editor_fields( $selection, $courier_id );
		echo '</div></div>';

		echo '<div class="bgcs-shipment-card"><h4>' . esc_html__( 'Summary', 'bg-commerce-suite' ) . '</h4><dl class="bgcs-summary">';
		$this->row( __( 'Courier', 'bg-commerce-suite' ), $courier );
		$this->row( __( 'Type', 'bg-commerce-suite' ), $this->type_label( $dt ) );
		if ( '' !== $city ) {
			$this->row( __( 'City', 'bg-commerce-suite' ), $city );
		}
		if ( '' !== $destination ) {
			$this->row( in_array( $dt, array( 'office', 'locker' ), true ) ? __( 'Office/Locker', 'bg-commerce-suite' ) : __( 'Address', 'bg-commerce-suite' ), $destination );
		}
		$this->row( __( 'Recipient', 'bg-commerce-suite' ), trim( $order->get_formatted_billing_full_name() ) );
		if ( $order->get_billing_phone() ) {
			$this->row( __( 'Phone', 'bg-commerce-suite' ), $order->get_billing_phone() );
		}
		echo '</dl></div></div>';
		echo '<div class="bgcs-btngroup bgcs-btngroup--spaced bgcs-shipment-primary-actions">';
		echo '<button type="button" class="bgcs-btn bgcs-btn--primary bgcs-create-label"' . ( $replacement_blocked ? ' disabled aria-disabled="true"' : '' ) . '>' . Icons::svg( 'truck', 16 ) . esc_html__( 'Create shipment label', 'bg-commerce-suite' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="button" class="bgcs-btn bgcs-save-selection">' . Icons::svg( 'check', 16 ) . esc_html__( 'Save changes', 'bg-commerce-suite' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		$this->shipment_tab_close();

		// Label: full creation controls, but no nested accordion maze.
		$this->shipment_tab_open( 'label' );
		echo '<div class="bgcs-shipment-label-empty"><div><strong>' . esc_html__( 'The shipment label has not been generated yet.', 'bg-commerce-suite' ) . '</strong><br><span>' . esc_html__( 'Review the details below or use the default values and create the shipment.', 'bg-commerce-suite' ) . '</span></div></div>';
		$this->render_waybill_editor( $order, $courier_id, $module );
		$this->shipment_tab_close();

		$this->shipment_tab_open( 'details' );
		$this->render_price_breakdown( $price_breakdown );
		$this->render_shipment_history( $order );
		echo '<p class="bgcs-help">' . esc_html__( 'After creation, technical data, price breakdown and additional shipment settings will appear here.', 'bg-commerce-suite' ) . '</p>';
		// A create that FAILED leaves a snapshot but no shipment, so this panel —
		// the one shown when there is no label — is exactly where that evidence
		// has to be readable.
		$this->render_diagnostics( $order );
		$this->shipment_tab_close();

		echo '</div>';
		Panel::close();
	}

	/**
	 * Compact, courier-agnostic post-label workspace. The high-frequency read
	 * path stays visible in Overview while heavy preview/edit controls live in
	 * client-side tabs and do not make the order page infinitely tall.
	 *
	 * @param \WC_Order $order Order.
	 * @param array<string,mixed> $selection Saved delivery selection.
	 * @param array<string,mixed> $label Label snapshot.
	 * @param array<string,mixed> $tracking Tracking snapshot.
	 * @param Courier_Interface|null $module Courier module.
	 * @param string $courier_id Courier id.
	 * @param string $courier Courier name.
	 * @param string $courier_logo Trusted logo html.
	 * @param array<string,mixed> $price_breakdown Price receipt.
	 * @return void
	 */
	private function render_existing_shipment( \WC_Order $order, array $selection, array $label, $tracking, $module, $courier_id, $courier, $courier_logo, array $price_breakdown ) {
		$tracking = is_array( $tracking ) ? $tracking : array();
		$tracking_state = ! empty( $tracking['state'] ) ? Tracking_State::sanitize( $tracking['state'] ) : Tracking_State::UNKNOWN;
		$status_label   = Tracking_State::label( $tracking_state );
		$status_tone    = Tracking_State::tone( $tracking_state );
		$number         = isset( $label['number'] ) ? (string) $label['number'] : '';
		$track_url      = ( $module && method_exists( $module, 'tracking_url' ) ) ? (string) $module->tracking_url( $number ) : '';
		$type           = isset( $selection['delivery_type'] ) ? (string) $selection['delivery_type'] : '';
		$city           = ! empty( $selection['city']['name'] ) ? (string) $selection['city']['name'] : '';
		$destination    = ! empty( $selection['office']['text'] ) ? (string) $selection['office']['text'] : '';
		if ( '' === $destination && ! empty( $selection['address']['street'] ) ) {
			$destination = trim( (string) $selection['address']['street'] . ' ' . ( isset( $selection['address']['num'] ) ? (string) $selection['address']['num'] : '' ) );
		}
		$header_bits = array_filter( array( '#' . $number, $this->type_label( $type ), $city ) );
		$mutation    = Shipment_Mutation::state( $order );
		$mutation_status = isset( $mutation['status'] ) ? (string) $mutation['status'] : '';
		$cancel_blocked = in_array( $mutation_status, array( Shipment_Mutation::CANCEL_PENDING, Shipment_Mutation::CANCEL_AMBIGUOUS ), true );

		Panel::open(
			'bgcs-order-shipment-' . $order->get_id(),
			'package',
			$courier,
			implode( ' · ', $header_bits ),
			$status_label,
			$status_tone,
			$courier_logo,
			'bgcs-order-panel--shipment',
			true
		);

		echo '<div class="bgcs-shipment-tabs" data-bgcs-order-tabs>';
		echo '<div class="bgcs-shipment-tabs__nav" role="tablist" aria-label="' . esc_attr__( 'Shipment', 'bg-commerce-suite' ) . '">';
		$this->shipment_tab_button( 'label', __( 'Shipment label', 'bg-commerce-suite' ), true );
		$this->shipment_tab_button( 'overview', __( 'Overview', 'bg-commerce-suite' ) );
		$this->shipment_tab_button( 'tracking', __( 'Tracking', 'bg-commerce-suite' ) );
		$this->shipment_tab_button( 'details', __( 'Details', 'bg-commerce-suite' ) );
		echo '</div>';

		// Overview -----------------------------------------------------------
		$this->shipment_tab_open( 'overview' );
		$this->render_mutation_notice( $order, true );
		if ( array_key_exists( 'is_cod', $label ) && Cod::is_method( isset( $label['payment_method'] ) ? $label['payment_method'] : '' ) && ! $label['is_cod'] ) {
			echo '<div class="bgcs-alert bgcs-alert--danger">' . Icons::svg( 'alert', 18 ) . '<div><strong>' . esc_html__( 'Shipment data mismatch.', 'bg-commerce-suite' ) . '</strong><br>' . esc_html__( 'The order uses cash on delivery, but the shipment is recorded without COD.', 'bg-commerce-suite' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		$provider_warning = isset( $label['meta']['provider_warning'] ) ? trim( (string) $label['meta']['provider_warning'] ) : '';
		if ( '' !== $provider_warning ) {
			echo '<div class="bgcs-alert bgcs-alert--warning">' . Icons::svg( 'alert', 18 ) . '<div><strong>' . esc_html__( 'Courier warning.', 'bg-commerce-suite' ) . '</strong><br>' . esc_html( $provider_warning ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<div class="bgcs-shipment-overview">';
		echo '<div class="bgcs-shipment-card"><h4>' . esc_html__( 'Shipment', 'bg-commerce-suite' ) . '</h4><dl class="bgcs-summary">';
		$this->row( __( 'Courier', 'bg-commerce-suite' ), $courier );
		$this->row( __( 'Active shipment', 'bg-commerce-suite' ), $number );
		$this->row( __( 'Status', 'bg-commerce-suite' ), '' !== $status_label ? $status_label : __( 'No synchronized status', 'bg-commerce-suite' ) );
		$this->row( __( 'Type', 'bg-commerce-suite' ), $this->type_label( $type ) );
		if ( array_key_exists( 'is_cod', $label ) ) {
			$this->row( __( 'COD', 'bg-commerce-suite' ), $label['is_cod'] ? number_format_i18n( (float) $label['cod_amount'], 2 ) . ' ' . ( isset( $label['cod_currency'] ) ? $label['cod_currency'] : '' ) : __( 'No COD', 'bg-commerce-suite' ) );
		}
		echo '</dl></div>';
		echo '<div class="bgcs-shipment-card"><h4>' . esc_html__( 'Destination', 'bg-commerce-suite' ) . '</h4><dl class="bgcs-summary">';
		if ( '' !== $city ) {
			$this->row( __( 'City', 'bg-commerce-suite' ), $city );
		}
		if ( '' !== $destination ) {
			$this->row( in_array( $type, array( 'office', 'locker' ), true ) ? __( 'Office/Locker', 'bg-commerce-suite' ) : __( 'Address', 'bg-commerce-suite' ), $destination );
		}
		$this->row( __( 'Recipient', 'bg-commerce-suite' ), trim( $order->get_formatted_billing_full_name() ) );
		if ( $order->get_billing_phone() ) {
			$this->row( __( 'Phone', 'bg-commerce-suite' ), $order->get_billing_phone() );
		}
		echo '</dl></div></div>';
		$this->shipment_tab_close();

		// Label --------------------------------------------------------------
		$this->shipment_tab_open( 'label', true );
		echo '<div class="bgcs-btngroup bgcs-btngroup--spaced bgcs-label-actions">';
		if ( ! empty( $label['pdf_url'] ) ) {
			echo '<a class="bgcs-btn bgcs-btn--sm bgcs-btn--primary" href="' . esc_url( $label['pdf_url'] ) . '" target="_blank" rel="noopener">' . Icons::svg( 'printer', 16 ) . esc_html__( 'Print', 'bg-commerce-suite' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<a class="bgcs-btn bgcs-btn--sm" href="' . esc_url( add_query_arg( 'mode', 'download', $label['pdf_url'] ) ) . '">' . Icons::svg( 'external', 16 ) . esc_html__( 'Download PDF', 'bg-commerce-suite' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<a class="bgcs-btn bgcs-btn--sm" href="' . esc_url( $label['pdf_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open in new window', 'bg-commerce-suite' ) . '</a>';
		}
		echo '<button type="button" class="bgcs-btn bgcs-btn--sm bgcs-resend-shipment-email">' . Icons::svg( 'external', 16 ) . esc_html__( 'Resend email', 'bg-commerce-suite' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="button" class="bgcs-btn bgcs-btn--sm bgcs-btn--danger bgcs-delete-label"' . ( $cancel_blocked ? ' disabled aria-disabled="true"' : '' ) . '>' . Icons::svg( 'trash', 16 ) . esc_html__( 'Cancel shipment', 'bg-commerce-suite' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		if ( ! empty( $label['pdf_url'] ) ) {
			echo '<div class="bgcs-label-preview bgcs-label-preview--compact"><iframe src="' . esc_url( $label['pdf_url'] ) . '" title="' . esc_attr__( 'Shipment label preview', 'bg-commerce-suite' ) . '"></iframe></div>';
		} else {
			echo '<p class="bgcs-empty">' . esc_html__( 'No PDF preview is available for this shipment label.', 'bg-commerce-suite' ) . '</p>';
		}

		// Once a shipment exists, the settings fields are gone entirely — for every
		// courier, with no per-courier exceptions. Editable inputs next to a live
		// shipment promise something BGCS deliberately does not do: it never edits
		// a shipment at the courier. Showing them only invites an edit that silently
		// applies to nothing. There are exactly two honest ways to change a shipment
		// that already exists, and this says so instead of offering a third.
		echo '<div class="bgcs-alert bgcs-alert--info">' . Icons::svg( 'info', 18 ) . '<div><strong>' . esc_html__( 'Shipment settings can no longer be changed here.', 'bg-commerce-suite' ) . '</strong><br>' . esc_html__( 'The shipment label has already been generated. Change it in the courier’s own system, or remove the shipment label here and create a new one — the delivery and shipment values saved for this order are kept and will be reused.', 'bg-commerce-suite' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->shipment_tab_close();

		// Tracking -----------------------------------------------------------
		$this->shipment_tab_open( 'tracking' );
		echo '<div class="bgcs-shipment-tracking-head"><dl class="bgcs-summary">';
		$this->row( __( 'BGCS status', 'bg-commerce-suite' ), '' !== $status_label ? $status_label : '—' );
		if ( ! empty( $tracking['status'] ) ) {
			$this->row( __( 'Courier status', 'bg-commerce-suite' ), (string) $tracking['status'] );
		}
		if ( ! empty( $tracking['updated_at'] ) ) {
			$this->row( __( 'Last synchronization', 'bg-commerce-suite' ), wp_date( 'd.m.Y H:i', (int) $tracking['updated_at'] ) );
		}
		$provider = isset( $tracking['provider'] ) && is_array( $tracking['provider'] ) ? $tracking['provider'] : array();
		if ( isset( $provider['total_price'] ) && is_numeric( $provider['total_price'] ) ) {
			$provider_currency = ! empty( $provider['currency'] ) ? strtoupper( (string) $provider['currency'] ) : $order->get_currency();
			$this->row( __( 'Price', 'bg-commerce-suite' ), number_format_i18n( (float) $provider['total_price'], 2 ) . ' ' . $provider_currency );
		}
		if ( isset( $provider['weight'] ) && is_numeric( $provider['weight'] ) ) {
			$this->row( __( 'Weight (kg)', 'bg-commerce-suite' ), number_format_i18n( (float) $provider['weight'], 3 ) );
		}
		$pickup = $order->get_meta( Pickup_Request::META_KEY );
		if ( is_array( $pickup ) && ! empty( $pickup['id'] ) ) {
			$pickup_statuses = array(
				Pickup_Request::PENDING    => __( 'Pending', 'bg-commerce-suite' ),
				Pickup_Request::PROCESSING => __( 'Processing', 'bg-commerce-suite' ),
				Pickup_Request::COLLECTED  => __( 'Collected', 'bg-commerce-suite' ),
				Pickup_Request::REJECTED   => __( 'Rejected', 'bg-commerce-suite' ),
				Pickup_Request::CANCELLED  => __( 'Cancelled', 'bg-commerce-suite' ),
				Pickup_Request::UNKNOWN    => __( 'Unknown', 'bg-commerce-suite' ),
			);
			$pickup_status = Pickup_Request::status( isset( $pickup['status'] ) ? $pickup['status'] : '' );
			$this->row( __( 'Pickup request ID', 'bg-commerce-suite' ), (string) $pickup['id'] );
			$this->row( __( 'Pickup status', 'bg-commerce-suite' ), $pickup_statuses[ $pickup_status ] );
			if ( ! empty( $pickup['waybill'] ) ) {
				$this->row( __( 'Pickup shipment', 'bg-commerce-suite' ), (string) $pickup['waybill'] );
			}
			if ( ! empty( $pickup['date'] ) ) {
				$this->row( __( 'Pickup date', 'bg-commerce-suite' ), (string) $pickup['date'] );
			}
			if ( ! empty( $pickup['updated_at'] ) ) {
				$this->row( __( 'Pickup last update', 'bg-commerce-suite' ), wp_date( 'd.m.Y H:i', (int) $pickup['updated_at'] ) );
			}
		}
		$payout_expected = Cod_Payout::expected( $order );
		$payout_mismatch = $order->get_meta( Cod_Payout::META_MISMATCH );
		$payout_mismatch = is_array( $payout_mismatch ) ? $payout_mismatch : array();
		$payout_paid     = 'yes' === (string) $order->get_meta( Cod_Payout::META_PAID );
		if ( ! empty( $payout_expected['is_cod'] ) ) {
			$payout_status = ! empty( $payout_mismatch['reasons'] )
				? __( 'Requires review', 'bg-commerce-suite' )
				: ( $payout_paid ? __( 'Paid', 'bg-commerce-suite' ) : __( 'Awaiting payout', 'bg-commerce-suite' ) );
			$this->row( __( 'Payout status', 'bg-commerce-suite' ), $payout_status );
			$this->row(
				__( 'Expected COD', 'bg-commerce-suite' ),
				number_format_i18n( (float) $payout_expected['amount'], 2 ) . ' ' . (string) $payout_expected['currency']
			);

			$paid_amount = $payout_paid
				? $order->get_meta( Cod_Payout::META_AMOUNT )
				: ( isset( $payout_mismatch['reported_amount'] ) ? $payout_mismatch['reported_amount'] : null );
			$paid_currency = $payout_paid
				? (string) $order->get_meta( Cod_Payout::META_CURRENCY )
				: ( isset( $payout_mismatch['reported_currency'] ) ? (string) $payout_mismatch['reported_currency'] : '' );
			if ( is_numeric( $paid_amount ) ) {
				$this->row( __( 'Paid COD', 'bg-commerce-suite' ), number_format_i18n( (float) $paid_amount, 2 ) . ( '' !== $paid_currency ? ' ' . $paid_currency : '' ) );
			}

			$difference = $payout_paid
				? $order->get_meta( Cod_Payout::META_DIFFERENCE )
				: ( isset( $payout_mismatch['difference'] ) ? $payout_mismatch['difference'] : null );
			if ( is_numeric( $difference ) ) {
				$this->row( __( 'Difference', 'bg-commerce-suite' ), number_format_i18n( (float) $difference, 2 ) . ' ' . (string) $payout_expected['currency'] );
			}

			$fee = $order->get_meta( Cod_Payout::META_FEE );
			if ( '' !== $fee && is_numeric( $fee ) ) {
				$this->row( __( 'Payout fee', 'bg-commerce-suite' ), number_format_i18n( (float) $fee, 2 ) . ' ' . (string) $payout_expected['currency'] );
			}
			$net = $order->get_meta( Cod_Payout::META_NET );
			if ( $payout_paid && is_numeric( $net ) ) {
				$this->row( __( 'Net payout', 'bg-commerce-suite' ), number_format_i18n( (float) $net, 2 ) . ' ' . (string) $payout_expected['currency'] );
			}

			$paid_date = $payout_paid
				? (string) $order->get_meta( Cod_Payout::META_PAID_DATE )
				: ( isset( $payout_mismatch['paid_date'] ) ? (string) $payout_mismatch['paid_date'] : '' );
			if ( '' !== $paid_date ) {
				$this->row( __( 'Paid date', 'bg-commerce-suite' ), $paid_date );
			}
			$source = $payout_paid
				? (string) $order->get_meta( Cod_Payout::META_SOURCE )
				: ( isset( $payout_mismatch['source'] ) ? (string) $payout_mismatch['source'] : '' );
			$source_labels = array(
				'background_api' => __( 'Automatic courier API', 'bg-commerce-suite' ),
				'manual_api'     => __( 'Manual courier API', 'bg-commerce-suite' ),
				'csv_import'     => __( 'Imported courier report', 'bg-commerce-suite' ),
				'tracking'       => __( 'Courier tracking', 'bg-commerce-suite' ),
			);
			if ( '' !== $source ) {
				$this->row( __( 'Payout source', 'bg-commerce-suite' ), isset( $source_labels[ $source ] ) ? $source_labels[ $source ] : $source );
			}
			$report_reference = $payout_paid
				? (string) $order->get_meta( Cod_Payout::META_REPORT_REF )
				: ( isset( $payout_mismatch['report_reference'] ) ? (string) $payout_mismatch['report_reference'] : '' );
			if ( '' !== $report_reference ) {
				$this->row( __( 'Report reference', 'bg-commerce-suite' ), $report_reference );
			}
		}
		echo '</dl></div>';

		if ( is_array( $payout_mismatch ) && ! empty( $payout_mismatch['reasons'] ) ) {
			$expected_amount   = isset( $payout_mismatch['expected_amount'] ) && is_numeric( $payout_mismatch['expected_amount'] ) ? number_format_i18n( (float) $payout_mismatch['expected_amount'], 2 ) : '—';
			$expected_currency = ! empty( $payout_mismatch['expected_currency'] ) ? strtoupper( (string) $payout_mismatch['expected_currency'] ) : '';
			$reported_amount   = isset( $payout_mismatch['reported_amount'] ) && is_numeric( $payout_mismatch['reported_amount'] ) ? number_format_i18n( (float) $payout_mismatch['reported_amount'], 2 ) : '—';
			$reported_currency = ! empty( $payout_mismatch['reported_currency'] ) ? strtoupper( (string) $payout_mismatch['reported_currency'] ) : '';
			echo '<div class="bgcs-alert bgcs-alert--warning">' . Icons::svg( 'alert', 18 ) . '<div><strong>' . esc_html__( 'COD payout requires review.', 'bg-commerce-suite' ) . '</strong><br>' . esc_html( sprintf( __( 'The courier payout report did not exactly match the shipment snapshot, so BGCS did not mark the COD as paid. Expected: %1$s %2$s. Reported: %3$s %4$s.', 'bg-commerce-suite' ), $expected_amount, $expected_currency, $reported_amount, $reported_currency ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( ! empty( $tracking['events'] ) && is_array( $tracking['events'] ) ) {
			Tracking_Timeline::render( $module, $tracking['events'] );
		} else {
			echo '<p class="bgcs-empty">' . esc_html__( 'There are no tracking events yet. Use “Refresh tracking”.', 'bg-commerce-suite' ) . '</p>';
		}
		echo '<div class="bgcs-shipment-actions bgcs-shipment-actions--tracking">';
		echo '<button type="button" class="bgcs-btn bgcs-btn--primary bgcs-refresh-tracking">' . Icons::svg( 'refresh', 16 ) . esc_html__( 'Refresh tracking', 'bg-commerce-suite' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $track_url ) {
			echo '<a class="bgcs-btn" href="' . esc_url( $track_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Track with courier', 'bg-commerce-suite' ) . '</a>';
		}
		echo '</div>';
		$this->shipment_tab_close();

		// Details ------------------------------------------------------------
		$this->shipment_tab_open( 'details' );
		// Shipment options intentionally do NOT repeat here. The Shipment label
		// tab is the single source of truth for order-specific courier settings;
		// Details is reserved for provider/price diagnostics only.
		$this->render_price_breakdown( $price_breakdown );
		$this->render_shipment_history( $order );
		$this->render_diagnostics( $order );
		$this->shipment_tab_close();

		echo '</div>';
		Panel::close();
	}

	private function shipment_tab_button( $id, $label, $active = false ) {
		echo '<button type="button" class="bgcs-shipment-tab' . ( $active ? ' is-active' : '' ) . '" role="tab" aria-selected="' . ( $active ? 'true' : 'false' ) . '" data-bgcs-order-tab="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</button>';
	}

	private function shipment_tab_open( $id, $active = false ) {
		echo '<div class="bgcs-shipment-tabpanel' . ( $active ? ' is-active' : '' ) . '" role="tabpanel" data-bgcs-order-tabpanel="' . esc_attr( $id ) . '"' . ( $active ? '' : ' hidden' ) . '>';
	}

	private function shipment_tab_close() {
		echo '</div>';
	}

	/** Show the truthful active/cancellation state without provider details. */
	private function render_mutation_notice( \WC_Order $order, $has_active_label ) {
		$state  = Shipment_Mutation::state( $order );
		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		$number = isset( $state['identity']['shipment_number'] ) ? (string) $state['identity']['shipment_number'] : '';

		if ( in_array( $status, array( Shipment_Mutation::CANCEL_PENDING, Shipment_Mutation::CANCEL_AMBIGUOUS ), true ) ) {
			echo '<div class="bgcs-alert bgcs-alert--warning">' . Icons::svg( 'alert', 18 ) . '<div><strong>' . esc_html__( 'Cancellation is not confirmed.', 'bg-commerce-suite' ) . '</strong><br>' . esc_html__( 'This shipment remains active in BGCS. Check the courier portal before retrying or creating a replacement.', 'bg-commerce-suite' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( Shipment_Mutation::CANCEL_FAILED === $status ) {
			echo '<div class="bgcs-alert bgcs-alert--danger">' . Icons::svg( 'alert', 18 ) . '<div><strong>' . esc_html__( 'The courier refused the cancellation.', 'bg-commerce-suite' ) . '</strong><br>' . esc_html__( 'The shipment remains active. No local shipment data was removed and no replacement was created.', 'bg-commerce-suite' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( ! $has_active_label && Shipment_Mutation::CANCELLED === $status ) {
			$message = '' !== $number
				? sprintf( __( 'Shipment %s was cancelled. There is currently no active shipment; review the saved settings before creating its replacement.', 'bg-commerce-suite' ), $number )
				: __( 'The previous shipment was cancelled. There is currently no active shipment; review the saved settings before creating its replacement.', 'bg-commerce-suite' );
			echo '<div class="bgcs-alert bgcs-alert--info">' . Icons::svg( 'info', 18 ) . '<div><strong>' . esc_html__( 'No active shipment.', 'bg-commerce-suite' ) . '</strong><br>' . esc_html( $message ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/** Render the immutable cancelled-shipment trace retained for this order. */
	private function render_shipment_history( \WC_Order $order ) {
		$history = Shipment_Mutation::history( $order );
		if ( empty( $history ) ) {
			return;
		}

		echo '<div class="bgcs-shipment-history"><h4>' . esc_html__( 'Shipment history', 'bg-commerce-suite' ) . '</h4><dl class="bgcs-summary">';
		foreach ( array_reverse( $history ) as $entry ) {
			$number = ! empty( $entry['identity']['shipment_number'] ) ? (string) $entry['identity']['shipment_number'] : '—';
			$when   = ! empty( $entry['cancelled_at'] ) ? wp_date( 'd.m.Y H:i', (int) $entry['cancelled_at'] ) : '—';
			$this->row( __( 'Cancelled shipment', 'bg-commerce-suite' ), sprintf( '%1$s · %2$s', $number, $when ) );
		}
		echo '</dl></div>';
	}

	/**
	 * Render a provider-returned price receipt. Speedy, for example, returns
	 * ShipmentPrice.details with the exact contract net/discount/surcharge lines;
	 * this lets the merchant see the administrative fee amount without BGCS
	 * guessing it from a boolean setting.
	 *
	 * @param array<string,mixed> $breakdown Normalized price breakdown.
	 */
	private function render_price_breakdown( array $breakdown ) {
		$items = isset( $breakdown['items'] ) && is_array( $breakdown['items'] ) ? $breakdown['items'] : array();
		$total = isset( $breakdown['total'] ) ? (float) $breakdown['total'] : 0.0;
		if ( empty( $items ) && 0.0 === $total ) {
			return;
		}

		$currency = ! empty( $breakdown['currency'] ) ? (string) $breakdown['currency'] : $this->order_currency_fallback();
		echo '<div class="bgcs-price-breakdown">';
		echo '<h4>' . esc_html__( 'Courier price breakdown', 'bg-commerce-suite' ) . '</h4>';
		echo '<dl class="bgcs-summary bgcs-summary--price">';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['amount'] ) ) {
				continue;
			}
			$label = ! empty( $item['label'] ) ? (string) $item['label'] : __( 'Component', 'bg-commerce-suite' );
			$value = number_format_i18n( (float) $item['amount'], 2 ) . ' ' . $currency;
			if ( isset( $item['percent'] ) && null !== $item['percent'] ) {
				$value .= ' (' . number_format_i18n( (float) $item['percent'] * 100, 2 ) . '%)';
			}
			$this->row( $label, $value );
		}
		if ( isset( $breakdown['amount'] ) ) {
			$this->row( __( 'Amount excl. VAT', 'bg-commerce-suite' ), number_format_i18n( (float) $breakdown['amount'], 2 ) . ' ' . $currency );
		}
		if ( isset( $breakdown['vat'] ) ) {
			$this->row( __( 'VAT', 'bg-commerce-suite' ), number_format_i18n( (float) $breakdown['vat'], 2 ) . ' ' . $currency );
		}
		if ( isset( $breakdown['total'] ) ) {
			$this->row( __( 'Total from courier', 'bg-commerce-suite' ), number_format_i18n( (float) $breakdown['total'], 2 ) . ' ' . $currency );
		}
		echo '</dl></div>';
	}

	/**
	 * Read-only shipment creation snapshots (handoff §15).
	 *
	 * Renders nothing at all unless snapshots were actually recorded, so an
	 * order from before diagnostics was switched on looks exactly as it did.
	 * Every value is escaped and the payload is shown as pretty-printed JSON:
	 * the point is to compare what BGCS sent against what MySpeedy shows, and
	 * that comparison needs the literal structure, not a prose summary.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function render_diagnostics( \WC_Order $order ) {
		$entries = Shipment_Diagnostics::stored( $order );
		if ( empty( $entries ) ) {
			return;
		}

		echo '<div class="bgcs-diagnostics">';
		echo '<h4>' . esc_html__( 'Shipment creation snapshots', 'bg-commerce-suite' ) . '</h4>';
		echo '<p class="bgcs-help">' . esc_html__( 'Recorded because diagnostics is switched on in General settings. Credentials are never recorded; customer names, phones, emails and addresses appear only as a length preview.', 'bg-commerce-suite' ) . '</p>';

		foreach ( array_reverse( $entries ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$when    = ! empty( $entry['at'] ) ? wp_date( 'd.m.Y H:i:s', (int) $entry['at'] ) : '—';
			$courier = ! empty( $entry['courier'] ) ? (string) $entry['courier'] : '—';
			$stages  = ( isset( $entry['stages'] ) && is_array( $entry['stages'] ) ) ? $entry['stages'] : array();

			// The single most useful line: which step the option died at. When the
			// create succeeded outright there is no blocked_at key.
			$blocked = '';
			if ( ! empty( $stages['blocked_at'] ) ) {
				$blocked = (string) $stages['blocked_at'];
			} elseif ( ! empty( $stages['blocked_before_payload'] ) ) {
				$blocked = 'before_payload';
			}

			echo '<details class="bgcs-diagnostics__entry">';
			echo '<summary>';
			echo esc_html( sprintf( '%1$s · %2$s', $when, $courier ) );
			if ( '' !== $blocked ) {
				echo ' <strong>' . esc_html( sprintf( __( 'stopped at: %s', 'bg-commerce-suite' ), $blocked ) ) . '</strong>';
			} elseif ( ! empty( $stages['unconfirmed_option'] ) ) {
				echo ' <strong>' . esc_html( sprintf( __( 'not confirmed by courier: %s', 'bg-commerce-suite' ), (string) $stages['unconfirmed_option'] ) ) . '</strong>';
			} else {
				echo ' ' . esc_html__( '(created)', 'bg-commerce-suite' );
			}
			echo '</summary>';

			if ( ! empty( $entry['truncated'] ) ) {
				echo '<p class="bgcs-help">' . esc_html__( 'This snapshot exceeded the storage limit; only the sent payload was kept.', 'bg-commerce-suite' ) . '</p>';
			}

			foreach ( $stages as $stage => $data ) {
				echo '<div class="bgcs-diagnostics__stage">';
				echo '<h5>' . esc_html( $stage ) . '</h5>';
				if ( is_scalar( $data ) ) {
					echo '<p>' . esc_html( (string) $data ) . '</p>';
				} else {
					$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
					echo '<pre>' . esc_html( is_string( $json ) ? $json : '' ) . '</pre>';
				}
				echo '</div>';
			}

			echo '</details>';
		}

		echo '</div>';
	}

	/**
	 * Fallback currency for legacy breakdowns that predate an explicit code.
	 *
	 * @return string
	 */
	private function order_currency_fallback() {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
	}

	/**
	 * Получател + Товарителница panels, shared by both the pre-create editable
	 * state and the post-create "Редактирай и обнови" state — same fields,
	 * only the editor button/AJAX action differs. Multi-pack and
	 * package-type sub-fields render only when the resolved courier module
	 * declares support (duck-typed — no Module API/Courier_Interface change,
	 * so this stays a no-op for couriers that don't implement it).
	 *
	 * @param \WC_Order              $order         Order.
	 * @param string                 $courier_id    Courier module id.
	 * @param Courier_Interface|null $module        Resolved courier module.
	 */
	private function render_waybill_editor( \WC_Order $order, $courier_id, $module ) {
		$wb = $order->get_meta( '_bgcs3_wb' );
		$wb = is_array( $wb ) ? $wb : array();
		$pm = (string) $order->get_payment_method();

		// Предварително попълване на полето „Наложен платеж“ — освен познатите
		// gateway id-та приемаме и всяко име, което съдържа „cod“.
		$is_cod_guess = ( false !== stripos( $pm, 'cod' ) ) || Cod::is_method( $pm );

		$wb_weight   = isset( $wb['weight'] ) ? $wb['weight'] : '';
		// Blank dimensions mean "inherit the courier defaults". Do not copy a
		// shop-wide default into `_bgcs3_wb` merely because the merchant saved the
		// order panel; only a value actually entered for this order is an override.
		$wb_width    = isset( $wb['width'] ) ? $wb['width'] : '';
		$wb_depth    = isset( $wb['depth'] ) ? $wb['depth'] : '';
		$wb_height   = isset( $wb['height'] ) ? $wb['height'] : '';
		$wb_cod      = isset( $wb['cod_amount'] ) ? $wb['cod_amount'] : ( $is_cod_guess ? (string) $order->get_total() : '' );
		// Предварително попълнено със стойността на поръчката, за да не се преписва
		// на ръка — включително за вече създадена товарителница, чийто `_bgcs3_wb`
		// съдържа записана празна стойност (затова проверката е за празно, не за
		// `isset`). Само стойност — НЕ се прилага, докато режимът не е „Ръчна
		// стойност“ (Overrides::resolve() чете сумата единствено при CUSTOM), така
		// че попълненото поле само по себе си никога не декларира застраховка.
		$wb_dv       = ( isset( $wb['declared_value'] ) && '' !== $wb['declared_value'] )
			? $wb['declared_value']
			: (string) $order->get_total();
		// Tri-state override mode (Rule 15 — празно поле никога не означава DISABLED,
		// само изричен избор на „Без НП“/„Изрично без“ прави това).
		$wb_cod_mode = Overrides::mode( $wb, 'cod_mode' );
		$wb_dv_mode  = Overrides::mode( $wb, 'dv_mode' );
		$wb_contents = isset( $wb['contents'] ) ? $wb['contents'] : '';
		$wb_ref2     = isset( $wb['ref2'] ) ? $wb['ref2'] : '';
		$wb_payer    = isset( $wb['payer'] ) ? (string) $wb['payer'] : '';
		$wb_obp      = isset( $wb['obp'] ) ? (string) $wb['obp'] : '';
		$wb_contact  = isset( $wb['contact_name'] ) ? $wb['contact_name'] : trim( $order->get_formatted_billing_full_name() );
		$wb_phone    = isset( $wb['phone'] ) ? $wb['phone'] : $order->get_billing_phone();
		$wb_email    = isset( $wb['email'] ) ? $wb['email'] : $order->get_billing_email();
		$wb_parcels  = isset( $wb['parcels'] ) ? $wb['parcels'] : '1';
		// Fragile is tri-state too: blank inherits the courier setting.
		$wb_fragile  = isset( $wb['fragile'] ) ? (string) $wb['fragile'] : '';
		$wb_packages = ( isset( $wb['packages'] ) && is_array( $wb['packages'] ) ) ? $wb['packages'] : array();
		$wb_pkg_type = isset( $wb['package_type'] ) ? (string) $wb['package_type'] : '';

		$id_suffix = '-' . $order->get_id();

		// A courier may declare that some of Core's generic waybill fields have
		// no counterpart in its API. Rendering an input the courier will discard
		// is worse than not rendering it: the merchant fills it in, presses
		// „Създай товарителница“ and the value silently disappears. Duck-typed
		// like every other optional capability — declare nothing, lose nothing.
		$hidden = ( $module && method_exists( $module, 'hidden_waybill_fields' ) )
			? array_flip( array_map( 'strval', (array) $module->hidden_waybill_fields() ) )
			: array();

		// --- Editable: recipient ---
		echo '<section class="bgcs-shipment-editor-section"><h4>' . esc_html__( 'Recipient', 'bg-commerce-suite' ) . '</h4>';
		echo '<div class="bgcs-fieldgrid">';
		$this->f_text( __( 'Contact person', 'bg-commerce-suite' ), 'bgcs-wb-contact', $wb_contact, array( 'full' => true ) );
		$this->f_text( __( 'Phone', 'bg-commerce-suite' ), 'bgcs-wb-phone', $wb_phone );
		$this->f_text( __( 'Email', 'bg-commerce-suite' ), 'bgcs-wb-email', $wb_email, array( 'type' => 'email' ) );
		echo '</div>';
		echo '</section>';

		// --- Editable: waybill data ---
		// This editor only ever renders before a shipment exists, so there is one
		// description and one title, not a pair chosen by state.
		echo '<section class="bgcs-shipment-editor-section"><div class="bgcs-shipment-editor-section__head"><h4>' . esc_html__( 'Shipment label', 'bg-commerce-suite' ) . '</h4><p>' . esc_html__( 'Shipment details before generation.', 'bg-commerce-suite' ) . '</p></div>';

		// Fields are grouped so related ones sit together: with a courier as rich
		// as Speedy this panel holds 20+ controls, and a single flat grid put
		// „Наложен платеж“ ten fields away from „Обработка на наложен платеж“.
		$extra = $this->extra_fields_by_group( $module, $wb );

		$this->group_open( __( 'Packages and contents', 'bg-commerce-suite' ) );

		$multi_pack = $module && method_exists( $module, 'supports_multi_pack' ) && $module->supports_multi_pack();
		if ( $multi_pack ) {
			$this->render_packages_field( $wb_packages, $order->get_id(), $id_suffix, $this->pack_columns( $module ) );
		} else {
			if ( $this->shows( $hidden, 'parcels' ) ) {
				$this->f_text( __( 'Number of packages', 'bg-commerce-suite' ), 'bgcs-wb-parcels', $wb_parcels, array( 'type' => 'number', 'step' => '1', 'min' => '1' ) );
			}
			$this->f_text( __( 'Weight (kg)', 'bg-commerce-suite' ), 'bgcs-wb-weight', $wb_weight, array( 'type' => 'number', 'step' => '0.01', 'min' => '0', 'ph' => __( 'auto from products', 'bg-commerce-suite' ) ) );

			if ( $this->shows( $hidden, 'dimensions' ) ) {
				echo '<div class="bgcs-field bgcs-field--full"><span class="bgcs-field__label">' . esc_html__( 'Dimensions (cm)', 'bg-commerce-suite' ) . '</span><div class="bgcs-inline3">';
				echo '<input type="number" step="1" min="0" class="bgcs-wb-depth widefat" value="' . esc_attr( $wb_depth ) . '" placeholder="' . esc_attr__( 'Length', 'bg-commerce-suite' ) . '" />';
				echo '<input type="number" step="1" min="0" class="bgcs-wb-width widefat" value="' . esc_attr( $wb_width ) . '" placeholder="' . esc_attr__( 'Width', 'bg-commerce-suite' ) . '" />';
				echo '<input type="number" step="1" min="0" class="bgcs-wb-height widefat" value="' . esc_attr( $wb_height ) . '" placeholder="' . esc_attr__( 'Height', 'bg-commerce-suite' ) . '" />';
				echo '</div></div>';
			}
		}

		$package_types = ( $module && method_exists( $module, 'package_types' ) ) ? (array) $module->package_types() : array();
		if ( ! empty( $package_types ) ) {
			$options = array( '' => __( 'Use settings', 'bg-commerce-suite' ) ) + $package_types;
			$this->f_select( __( 'Packaging', 'bg-commerce-suite' ), 'bgcs-wb-package-type', $wb_pkg_type, $options );
		}

		// The courier states its own documented limit; Core does not assume one.
		// Putting it on the field stops the merchant at the real boundary while
		// typing, instead of letting them write a long description and meet a
		// refusal only after pressing Create.
		$field_limits    = ( $module && method_exists( $module, 'waybill_field_limits' ) )
			? (array) $module->waybill_field_limits()
			: array();
		$contents_limit  = isset( $field_limits['contents'] ) ? (int) $field_limits['contents'] : 0;
		$contents_opts   = array( 'full' => true, 'ph' => __( 'auto from order items', 'bg-commerce-suite' ) );
		if ( $contents_limit > 0 ) {
			$contents_opts['maxlength'] = $contents_limit;
			/* translators: %d: maximum number of characters the courier accepts. */
			$contents_opts['ph'] = sprintf( __( 'auto from order items (max %d characters)', 'bg-commerce-suite' ), $contents_limit );
		}
		$this->f_text( __( 'Description', 'bg-commerce-suite' ), 'bgcs-wb-contents', $wb_contents, $contents_opts );
		$this->render_extra_group( $extra, 'packages' );
		$this->group_close();

		$this->group_open( __( 'Payment and value', 'bg-commerce-suite' ) );
		if ( $this->shows( $hidden, 'cod' ) ) {
			$this->f_select(
				__( 'Cash on delivery', 'bg-commerce-suite' ),
				'bgcs-wb-cod-mode',
				$wb_cod_mode,
				array(
					Overrides::INHERIT  => __( 'Automatically from the order', 'bg-commerce-suite' ),
					Overrides::CUSTOM   => __( 'Manual amount', 'bg-commerce-suite' ),
					Overrides::DISABLED => __( 'No COD', 'bg-commerce-suite' ),
				)
			);
			$this->f_text( __( 'Cash-on-delivery amount', 'bg-commerce-suite' ), 'bgcs-wb-cod', $wb_cod, array( 'type' => 'number', 'step' => '0.01', 'min' => '0', 'ph' => __( 'only with “Manual amount”', 'bg-commerce-suite' ) ) );
		}

		if ( $this->shows( $hidden, 'declared_value' ) ) {
			$this->f_select(
				__( 'Declared value', 'bg-commerce-suite' ),
				'bgcs-wb-dv-mode',
				$wb_dv_mode,
				array(
					// „Наследено“ НЕ значи „без стойност“ — то следва настройката на
					// куриера, която може да декларира цялата поръчка. Старият надпис
					// подвеждаше и караше търговеца да въведе сума, която после се
					// игнорираше, защото режимът не беше „Ръчна стойност“.
					// Kept short on purpose: the longer wording overflowed the column
					// and rendered as „Както в настройките на куриер…“.
					Overrides::INHERIT  => __( 'Use settings', 'bg-commerce-suite' ),
					Overrides::CUSTOM   => __( 'Manual value', 'bg-commerce-suite' ),
					Overrides::DISABLED => __( 'Explicitly none', 'bg-commerce-suite' ),
				)
			);
			$this->f_text( __( 'Declared value amount', 'bg-commerce-suite' ), 'bgcs-wb-dv', $wb_dv, array( 'type' => 'number', 'step' => '0.01', 'min' => '0', 'ph' => __( 'applies only with “Manual value”', 'bg-commerce-suite' ) ) );
		}

		if ( $this->shows( $hidden, 'fragile' ) ) {
			$this->f_select( __( 'Fragile', 'bg-commerce-suite' ), 'bgcs-wb-fragile', $wb_fragile, array( '' => __( 'Use settings', 'bg-commerce-suite' ), 'no' => __( 'No', 'bg-commerce-suite' ), 'yes' => __( 'Yes', 'bg-commerce-suite' ) ), false, __( 'Requires a declared value — the courier has no other field in which to store it.', 'bg-commerce-suite' ) );
		}
		if ( $this->shows( $hidden, 'payer' ) ) {
			$payer_options = array(
				''          => __( 'Use courier settings / automatic', 'bg-commerce-suite' ),
				'RECIPIENT' => __( 'Recipient', 'bg-commerce-suite' ),
				'SENDER'    => __( 'Sender', 'bg-commerce-suite' ),
			);
			if ( 'speedy' === (string) $courier_id ) {
				$payer_options['THIRD_PARTY'] = __( 'Third party', 'bg-commerce-suite' );
			}
			$this->f_select( __( 'Shipping is paid by', 'bg-commerce-suite' ), 'bgcs-wb-payer', $wb_payer, $payer_options );
		}
		$this->render_extra_group( $extra, 'payment' );
		$this->group_close();

		$this->group_open( __( 'Delivery services', 'bg-commerce-suite' ) );
		if ( $this->shows( $hidden, 'obp' ) ) {
			$this->f_select(
				__( 'Review and test', 'bg-commerce-suite' ),
				'bgcs-wb-obp',
				$wb_obp,
				array(
					''     => __( 'Use courier settings', 'bg-commerce-suite' ),
					'NO'   => __( 'No', 'bg-commerce-suite' ),
					'OPEN' => __( 'Review', 'bg-commerce-suite' ),
					'TEST' => __( 'Review and test', 'bg-commerce-suite' ),
				)
			);
		}
		$this->render_extra_group( $extra, 'services' );
		$this->group_close();

		$this->group_open( __( 'Additional', 'bg-commerce-suite' ) );
		if ( $this->shows( $hidden, 'ref2' ) ) {
			$this->f_text( __( 'Ref. 2 (optional)', 'bg-commerce-suite' ), 'bgcs-wb-ref2', $wb_ref2 );
		}
		$this->render_extra_group( $extra, 'extra' );
		$this->group_close();

		echo '</section>';

		// --- Shipment editor buttons ---
		echo '<div class="bgcs-btngroup bgcs-btngroup--spaced bgcs-shipment-editor-actions">';
		echo '<button type="button" class="bgcs-btn bgcs-btn--primary bgcs-create-label">' . Icons::svg( 'truck', 16 ) . esc_html__( 'Create shipment label', 'bg-commerce-suite' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		// „Създай товарителница“ now saves both panels itself, so this button
		// only exists for parking edits without generating anything yet.
		echo '<button type="button" class="bgcs-btn bgcs-save-selection">' . Icons::svg( 'check', 16 ) . esc_html__( 'Save without generating', 'bg-commerce-suite' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Opens a labelled subsection inside a panel. Purely structural: the fields
	 * themselves are unchanged, they are just no longer one undifferentiated run.
	 *
	 * @param string $title Subsection title.
	 */
	/** @var string Title of the group currently being buffered. */
	private $group_title = '';

	/**
	 * Groups buffer their contents so a group whose every field is hidden by the
	 * courier (`hidden_waybill_fields()`) prints nothing at all, instead of a
	 * heading over an empty grid.
	 *
	 * @param string $title Group heading.
	 */
	private function group_open( $title ) {
		$this->group_title = (string) $title;
		ob_start();
	}

	private function group_close() {
		$body = (string) ob_get_clean();

		if ( '' === trim( $body ) ) {
			return;
		}

		echo '<div class="bgcs-wb-group">';
		echo '<h4 class="bgcs-wb-group__title">' . esc_html( $this->group_title ) . '</h4>';
		echo '<div class="bgcs-fieldgrid">';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped markup from the field renderers.
		echo '</div></div>';
	}

	/**
	 * Whether a generic waybill field is rendered for this courier.
	 *
	 * @param array<string,int> $hidden Flipped list from `hidden_waybill_fields()`.
	 * @param string            $key    Field key.
	 * @return bool
	 */
	private function shows( array $hidden, $key ) {
		return ! isset( $hidden[ $key ] );
	}

	/**
	 * Buckets the courier's declared fields by their `group`, so each one can be
	 * rendered next to the Core field it belongs with instead of in a block at
	 * the end. An unknown or missing group falls into `extra`.
	 *
	 * @param Courier_Interface|null $module Resolved courier module.
	 * @param array<string,mixed>    $wb     Current `_bgcs3_wb` overrides.
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	private function extra_fields_by_group( $module, array $wb ) {
		$groups = array(
			'packages' => array(),
			'payment'  => array(),
			'services' => array(),
			'extra'    => array(),
		);

		if ( ! $module || ! method_exists( $module, 'waybill_fields' ) ) {
			return $groups;
		}

		$stored = ( isset( $wb['x'] ) && is_array( $wb['x'] ) ) ? $wb['x'] : array();

		foreach ( (array) $module->waybill_fields() as $key => $field ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! is_array( $field ) ) {
				continue;
			}

			$group = ( isset( $field['group'] ) && isset( $groups[ $field['group'] ] ) ) ? $field['group'] : 'extra';

			$field['__value']         = array_key_exists( $key, $stored )
				? $stored[ $key ]
				: ( isset( $field['default'] ) ? $field['default'] : '' );
			$groups[ $group ][ $key ] = $field;
		}

		return $groups;
	}

	/**
	 * @param array<string,array<string,array<string,mixed>>> $groups Bucketed fields.
	 * @param string                                          $group  Group key.
	 */
	private function render_extra_group( array $groups, $group ) {
		if ( empty( $groups[ $group ] ) ) {
			return;
		}

		foreach ( $groups[ $group ] as $key => $field ) {
			$this->render_extra_field( $key, $field, $field['__value'] );
		}
	}	/**
	 * Renders one courier-declared field.
	 *
	 * `show_if` hides a field until the control it depends on has a relevant
	 * value — e.g. Speedy's ОПП return service/payer only matter once „Преглед и
	 * тест“ is switched on. The dependency rides on the wrapper as data
	 * attributes; order.js does the toggling, and the field still posts normally
	 * whenever it is visible.
	 *
	 * @param string              $key   Field key.
	 * @param array<string,mixed> $field Field definition.
	 * @param mixed               $value Current value.
	 */
	private function render_extra_field( $key, array $field, $value ) {
		$label = isset( $field['label'] ) ? $field['label'] : $key;
		$type  = isset( $field['type'] ) ? $field['type'] : 'text';
		$full  = ! empty( $field['full'] );
		$attrs = ' data-bgcs-wb-key="' . esc_attr( $key ) . '"';

		$conditional = '';
		if ( ! empty( $field['show_if']['control'] ) ) {
			$values      = (array) ( isset( $field['show_if']['value'] ) ? $field['show_if']['value'] : array() );
			$conditional = ' data-bgcs-show-if="' . esc_attr( $field['show_if']['control'] ) . '"'
				. ' data-bgcs-show-if-value="' . esc_attr( implode( ',', $values ) ) . '"';
		}

		echo '<div class="bgcs-field' . ( $full ? ' bgcs-field--full' : '' ) . '"' . $conditional . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<label class="bgcs-field__label">' . esc_html( $label ) . '</label>';

		if ( 'select' === $type ) {
			echo '<select class="bgcs-wb-x widefat"' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is escaped above.
			foreach ( (array) ( isset( $field['options'] ) ? $field['options'] : array() ) as $opt_value => $opt_label ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $opt_value ), selected( (string) $value, (string) $opt_value, false ), esc_html( $opt_label ) );
			}
			echo '</select>';
		} else {
			$input_type = ( 'number' === $type ) ? 'number' : 'text';
			$extra      = '';
			foreach ( array( 'step', 'min', 'max' ) as $a ) {
				if ( isset( $field[ $a ] ) ) {
					$extra .= ' ' . $a . '="' . esc_attr( $field[ $a ] ) . '"';
				}
			}
			if ( isset( $field['placeholder'] ) ) {
				$extra .= ' placeholder="' . esc_attr( $field['placeholder'] ) . '"';
			}
			echo '<input type="' . esc_attr( $input_type ) . '" class="bgcs-wb-x widefat" value="' . esc_attr( $value ) . '"' . $attrs . $extra . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- all parts escaped above.
		}

		if ( ! empty( $field['description'] ) ) {
			echo '<span class="bgcs-field__desc">' . esc_html( $field['description'] ) . '</span>';
		}

		echo '</div>';
	}

	/**
	 * Multi-pack editor: one row per parcel (length/width/height/weight),
	 * Add/Remove Pack — mirrors what couriers with real multi-parcel API
	 * support (Rule 6) actually accept, e.g. Speedy `content.parcels[]`.
	 * order.js serializes all rows into a single hidden JSON field
	 * (`wb_packages`) before every AJAX submit; this only renders the
	 * current/prefilled rows plus one hidden template row for JS to clone.
	 *
	 * @param array<int,array<string,mixed>> $packages  Current packages.
	 * @param int                            $order_id  Order id (unique row ids).
	 * @param string                         $id_suffix Panel id suffix (pre/post-create).
	 */
	private function render_packages_field( array $packages, $order_id, $id_suffix, array $columns ) {
		if ( empty( $packages ) ) {
			$packages = array( array_fill_keys( array_keys( $columns ), '' ) );
		}

		echo '<div class="bgcs-field bgcs-field--full bgcs-wb-packages-field">';
		echo '<span class="bgcs-field__label">' . esc_html__( 'Packages', 'bg-commerce-suite' ) . '</span>';

		// A select column carries no placeholder, so named columns get one
		// header strip instead of repeating a label on every row.
		$labelled = array_filter(
			$columns,
			static function ( $column ) {
				return ! empty( $column['label'] );
			}
		);
		if ( ! empty( $labelled ) ) {
			echo '<div class="bgcs-wb-pack-head"><span class="bgcs-wb-pack-row__label"></span><div class="bgcs-inline4">';
			foreach ( $columns as $column ) {
				echo '<span>' . esc_html( isset( $column['label'] ) ? $column['label'] : '' ) . '</span>';
			}
			echo '</div><span class="bgcs-wb-pack-head__spacer"></span></div>';
		}

		echo '<div class="bgcs-wb-packages" data-order-id="' . esc_attr( $order_id ) . '">';

		foreach ( $packages as $i => $pack ) {
			$this->render_pack_row( is_array( $pack ) ? $pack : array(), $i, $columns );
		}

		echo '</div>';
		echo '<template class="bgcs-wb-pack-template">';
		$this->render_pack_row( array(), 0, $columns );
		echo '</template>';
		echo '<button type="button" class="bgcs-btn bgcs-btn--sm bgcs-wb-add-pack">' . Icons::svg( 'plus', 16 ) . esc_html__( 'Add package', 'bg-commerce-suite' ) . '</button>';
		echo '</div>';
	}

	/**
	 * Columns of one pack row when the courier declares none of its own.
	 *
	 * Physical dimensions + weight is what a road courier bills on, so it stays
	 * the default. A locker network bills on a compartment size instead and has
	 * no field for centimetres at all — such a courier declares `pack_columns()`
	 * and gets its own columns rather than four inputs it would discard.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function default_pack_columns() {
		return array(
			'length' => array( 'type' => 'number', 'step' => '1', 'min' => '0', 'placeholder' => __( 'Length, cm', 'bg-commerce-suite' ) ),
			'width'  => array( 'type' => 'number', 'step' => '1', 'min' => '0', 'placeholder' => __( 'Width, cm', 'bg-commerce-suite' ) ),
			'height' => array( 'type' => 'number', 'step' => '1', 'min' => '0', 'placeholder' => __( 'Height, cm', 'bg-commerce-suite' ) ),
			'weight' => array( 'type' => 'number', 'step' => '0.01', 'min' => '0', 'placeholder' => __( 'Weight, kg', 'bg-commerce-suite' ) ),
		);
	}

	/**
	 * Pack-row columns for the resolved courier.
	 *
	 * @param Courier_Interface|null $module Courier module.
	 * @return array<string,array<string,mixed>>
	 */
	private function pack_columns( $module ) {
		if ( $module && method_exists( $module, 'pack_columns' ) ) {
			$columns = (array) $module->pack_columns();
			if ( ! empty( $columns ) ) {
				return $columns;
			}
		}

		return $this->default_pack_columns();
	}

	/**
	 * @param array<string,mixed>                $pack    Pack data, keyed by column key.
	 * @param int                                $index   Row index (display only, JS renumbers on add/remove).
	 * @param array<string,array<string,mixed>>  $columns Column definitions.
	 */
	private function render_pack_row( array $pack, $index, array $columns ) {
		echo '<div class="bgcs-wb-pack-row">';
		echo '<span class="bgcs-wb-pack-row__label">' . esc_html( sprintf( __( 'Package %d', 'bg-commerce-suite' ), (int) $index + 1 ) ) . '</span>';
		echo '<div class="bgcs-inline4">';

		foreach ( $columns as $key => $column ) {
			$value = isset( $pack[ $key ] ) ? (string) $pack[ $key ] : '';
			$this->render_pack_cell( $key, $column, $value );
		}

		echo '</div>';
		echo '<button type="button" class="bgcs-btn bgcs-btn--sm bgcs-btn--danger bgcs-wb-remove-pack">' . Icons::svg( 'trash', 14 ) . '</button>';
		echo '</div>';
	}

	/**
	 * One input/select of a pack row. `data-pack-key` is what the serializer
	 * reads, so a courier can name its columns whatever its API calls them.
	 *
	 * @param string              $key    Column key, stored verbatim in `_bgcs3_wb['packages']`.
	 * @param array<string,mixed> $column Column definition.
	 * @param string              $value  Current value.
	 */
	private function render_pack_cell( $key, array $column, $value ) {
		$type = isset( $column['type'] ) ? (string) $column['type'] : 'number';

		if ( 'select' === $type ) {
			echo '<select class="bgcs-wb-pack-field widefat" data-pack-key="' . esc_attr( $key ) . '">';
			foreach ( (array) ( isset( $column['options'] ) ? $column['options'] : array() ) as $option_value => $label ) {
				echo '<option value="' . esc_attr( $option_value ) . '"' . selected( (string) $option_value, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			return;
		}

		$attributes = '';
		foreach ( array( 'step', 'min', 'max' ) as $attribute ) {
			if ( isset( $column[ $attribute ] ) && '' !== $column[ $attribute ] ) {
				$attributes .= ' ' . $attribute . '="' . esc_attr( $column[ $attribute ] ) . '"';
			}
		}

		echo '<input type="' . esc_attr( 'text' === $type ? 'text' : 'number' ) . '"' . $attributes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			. ' class="bgcs-wb-pack-field widefat" data-pack-key="' . esc_attr( $key ) . '"'
			. ' value="' . esc_attr( $value ) . '"'
			. ' placeholder="' . esc_attr( isset( $column['placeholder'] ) ? $column['placeholder'] : '' ) . '" />';
	}

	/* ----------------------------------------------------------------- */
	/* Reusable render helpers (presentation only)                        */
	/* ----------------------------------------------------------------- */

	private function row( $label, $value ) {
		echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
	}

	private function type_label( $type ) {
		$map = array(
			'office'  => __( 'Office', 'bg-commerce-suite' ),
			'locker'  => __( 'Locker', 'bg-commerce-suite' ),
			'address' => __( 'Address', 'bg-commerce-suite' ),
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : (string) $type;
	}

	/**
	 * @param string $payer RECIPIENT|SENDER|THIRD_PARTY.
	 * @return string
	 */
	private function payer_label( $payer ) {
		$map = array(
			'RECIPIENT'   => __( 'Recipient', 'bg-commerce-suite' ),
			'SENDER'      => __( 'Sender', 'bg-commerce-suite' ),
			'THIRD_PARTY' => __( 'Third party', 'bg-commerce-suite' ),
		);
		return isset( $map[ $payer ] ) ? $map[ $payer ] : (string) $payer;
	}

	/**
	 * @param string               $label Label.
	 * @param string               $class JS hook class (preserved).
	 * @param string               $value Current value.
	 * @param array<string,mixed>  $opts  type|step|min|ph|full.
	 */
	private function f_text( $label, $class, $value, $opts = array() ) {
		$type = isset( $opts['type'] ) ? $opts['type'] : 'text';
		$attr = '';
		foreach ( array( 'step', 'min', 'max', 'maxlength' ) as $a ) {
			if ( isset( $opts[ $a ] ) ) {
				$attr .= ' ' . $a . '="' . esc_attr( $opts[ $a ] ) . '"';
			}
		}
		$ph   = isset( $opts['ph'] ) ? ' placeholder="' . esc_attr( $opts['ph'] ) . '"' : '';
		$full = ! empty( $opts['full'] ) ? ' bgcs-field--full' : '';

		echo '<div class="bgcs-field' . esc_attr( $full ) . '">';
		echo '<label class="bgcs-field__label">' . esc_html( $label ) . '</label>';
		echo '<input type="' . esc_attr( $type ) . '" class="' . esc_attr( $class ) . ' widefat" value="' . esc_attr( $value ) . '"' . $ph . $attr . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * @param string                $label   Label.
	 * @param string                $class   JS hook class (preserved).
	 * @param string                $value   Current value.
	 * @param array<string,string>  $options Options map.
	 * @param bool                  $full    Span full width.
	 */
	private function f_select( $label, $class, $value, array $options, $full = false, $hint = '' ) {
		echo '<div class="bgcs-field' . ( $full ? ' bgcs-field--full' : '' ) . '">';
		echo '<label class="bgcs-field__label">' . esc_html( $label ) . '</label>';
		echo '<select class="' . esc_attr( $class ) . ' widefat">';
		foreach ( $options as $val => $lbl ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( (string) $value, (string) $val, false ), esc_html( $lbl ) );
		}
		echo '</select>';
		if ( '' !== (string) $hint ) {
			echo '<span class="bgcs-field__desc">' . esc_html( $hint ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * @param string $label      Label.
	 * @param string $class      JS hook class (preserved).
	 * @param string $value      Current id.
	 * @param string $text       Current label.
	 * @param string $resource   REST resource.
	 * @param string $courier    Courier id.
	 * @param string $depends_on Class of the select this one depends on.
	 * @param string $hint       Optional note rendered under the field.
	 */
	private function f_search_select( $label, $class, $value, $text, $resource, $courier, $depends_on = '', $hint = '' ) {
		echo '<div class="bgcs-field">';
		echo '<label class="bgcs-field__label">' . esc_html( $label ) . '</label>';
		echo '<select class="' . esc_attr( $class ) . ' widefat" data-resource="' . esc_attr( $resource ) . '" data-courier="' . esc_attr( $courier ) . '" data-depends-on="' . esc_attr( $depends_on ) . '">';
		if ( '' !== (string) $value ) {
			echo '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( $text ? $text : $value ) . '</option>';
		}
		echo '</select>';
		if ( '' !== (string) $hint ) {
			echo '<span class="bgcs-field__desc">' . esc_html( $hint ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Admin edits the delivery data before generating the waybill. Rebuilds the
	 * Selection from the posted fields, keeping the original courier.
	 */
	public function ajax_save_selection() {
		$order = $this->verify_and_get_order();

		$this->save_selection_fields( $order->order );
		$this->save_waybill_fields( $order->order );

		$order->order->add_order_note( __( 'The shipping/shipment label details were edited by an administrator.', 'bg-commerce-suite' ) );
		$order->order->save();

		wp_send_json_success();
	}

	/**
	 * Rebuilds `_bgcs3_selection` from the posted „Доставка“ panel — shared by
	 * `ajax_save_selection()` and `ajax_create_label()` (BUG-043: pressing
	 * „Създай товарителница“ used to discard the panel entirely, so a street
	 * picked from the dropdown never reached the courier's address block).
	 *
	 * A no-op unless `delivery_type` was actually posted. This also protects any
	 * auxiliary action that reuses the order box without rendering the delivery
	 * editor from being interpreted as "the merchant cleared everything".
	 *
	 * @param \WC_Order $order Order (not saved here — caller saves once).
	 */
	private function save_selection_fields( $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_and_get_order().
		if ( ! isset( $_POST['delivery_type'] ) ) {
			return;
		}

		$current = $order->get_meta( '_bgcs3_selection' );
		$current = is_array( $current ) ? $current : array();
		$courier = ! empty( $current['courier'] ) ? $current['courier'] : '';

		$field = static function ( $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_and_get_order().
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};

		$type = $field( 'delivery_type' );
		$type = in_array( $type, array( 'office', 'locker', 'address' ), true ) ? $type : 'office';

		$address = null;
		if ( 'address' === $type ) {
			$street_id    = $field( 'street_id' );
			$street_label = $field( 'street_label' );
			$stored       = isset( $current['address'] ) && is_array( $current['address'] ) ? $current['address'] : array();

			// A street that has a NAME but no nomenclature id renders as an empty
			// select (there is no option to preselect), so an untouched form posts
			// both fields empty. Treat that as "not edited" and keep what is
			// stored — otherwise merely opening the screen and saving would wipe
			// a perfectly good free-text street.
			if ( '' === $street_id && '' === $street_label ) {
				$street_id    = isset( $stored['street_id'] ) ? (string) $stored['street_id'] : '';
				$street_label = isset( $stored['street'] ) ? (string) $stored['street'] : '';
			}

			$block     = isset( $_POST['block'] ) ? $field( 'block' ) : ( isset( $stored['block'] ) ? (string) $stored['block'] : '' );
			$entrance  = isset( $_POST['entrance'] ) ? $field( 'entrance' ) : ( isset( $stored['entrance'] ) ? (string) $stored['entrance'] : '' );
			$floor     = isset( $_POST['floor'] ) ? $field( 'floor' ) : ( isset( $stored['floor'] ) ? (string) $stored['floor'] : '' );
			$apartment = isset( $_POST['apartment'] ) ? $field( 'apartment' ) : ( isset( $stored['apartment'] ) ? (string) $stored['apartment'] : '' );
			$note      = isset( $_POST['note'] ) ? $field( 'note' ) : ( isset( $stored['note'] ) ? (string) $stored['note'] : '' );

			$address = array(
				'street_id' => $street_id,
				'street'    => $street_label,
				'num'       => $field( 'num' ),
				'block'     => $block,
				'entrance'  => $entrance,
				'floor'     => $floor,
				'apartment' => $apartment,
				'note'      => $note,
			);
		}

		$selection = \BgCommerce3\Support\Selection::from_array(
			array(
				'courier'       => $courier,
				'delivery_type' => $type,
				'country'       => 'BG',
				'city'          => array(
					'id'        => $field( 'city_id' ),
					'name'      => $field( 'city_label' ),
					'post_code' => $field( 'post_code' ),
				),
				'office'        => ( 'address' !== $type ) ? array(
					'id'   => $field( 'office_id' ),
					'text' => $field( 'office_label' ),
				) : null,
				'address'       => $address,
			)
		);

		$order->update_meta_data( '_bgcs3_selection', $selection->to_array() );
		\BgCommerce3\Checkout\Checkout::save_readable_meta( $order, $selection );
	}

	/**
	 * Parses and persists the `_bgcs3_wb` waybill overrides from POST — shared
	 * by the pre-create and post-create order-settings save flows. Saving these
	 * values never changes an already-created courier shipment.
	 *
	 * @param \WC_Order $order Order (not saved here — caller saves once).
	 */
	private function save_waybill_fields( \WC_Order $order ) {
		$num_field = static function ( $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_and_get_order().
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};

		$payer    = $num_field( 'wb_payer' );
		$obp      = $num_field( 'wb_obp' );
		$fragile  = $num_field( 'wb_fragile' );
		// Tri-state override mode (Rule 15) — вижте Overrides::resolve(). Невалиден/
		// липсващ POST винаги пада обратно към INHERIT, никога не се извежда от
		// празнотата на самото amount поле.
		$cod_mode = $num_field( 'wb_cod_mode' );
		$dv_mode  = $num_field( 'wb_dv_mode' );
		$wb       = array(
			'contact_name'   => $num_field( 'wb_contact' ),
			'phone'          => $num_field( 'wb_phone' ),
			'email'          => $num_field( 'wb_email' ),
			'parcels'        => $num_field( 'wb_parcels' ),
			'weight'         => $num_field( 'wb_weight' ),
			'width'          => $num_field( 'wb_width' ),
			'depth'          => $num_field( 'wb_depth' ),
			'height'         => $num_field( 'wb_height' ),
			'packages'       => $this->posted_packages(),
			'package_type'   => $num_field( 'wb_package_type' ),
			'cod_mode'       => in_array( $cod_mode, Overrides::modes(), true ) ? $cod_mode : Overrides::INHERIT,
			'cod_amount'     => $num_field( 'wb_cod' ),
			'dv_mode'        => in_array( $dv_mode, Overrides::modes(), true ) ? $dv_mode : Overrides::INHERIT,
			'declared_value' => $num_field( 'wb_dv' ),
			'fragile'        => in_array( $fragile, array( '', 'yes', 'no' ), true ) ? $fragile : '',
			'contents'       => $num_field( 'wb_contents' ),
			'ref2'           => $num_field( 'wb_ref2' ),
			'payer'          => in_array( $payer, array( '', 'RECIPIENT', 'SENDER', 'THIRD_PARTY' ), true ) ? $payer : '',
			'obp'            => in_array( $obp, array( '', 'NO', 'OPEN', 'TEST' ), true ) ? $obp : '',
			'x'              => $this->posted_extra_fields(),
		);
		$order->update_meta_data( '_bgcs3_wb', $wb );
	}

	/**
	 * Collects the courier-declared extra waybill fields (`wb_x_{key}`) into the
	 * namespaced `_bgcs3_wb['x']` bucket. Core does not interpret the values —
	 * validation of what each one means belongs to the courier that declared it
	 * — but the keys are normalized and the values sanitized here, so a courier
	 * never receives raw request data.
	 *
	 * @return array<string,string>
	 */
	private function posted_extra_fields() {
		$extra = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_and_get_order().
		foreach ( (array) $_POST as $post_key => $post_value ) {
			if ( 0 !== strpos( (string) $post_key, 'wb_x_' ) || ! is_scalar( $post_value ) ) {
				continue;
			}

			$key = sanitize_key( substr( (string) $post_key, 5 ) );
			if ( '' === $key ) {
				continue;
			}

			$extra[ $key ] = sanitize_text_field( wp_unslash( (string) $post_value ) );
		}

		return $extra;
	}

	/**
	 * Parses the posted multi-pack JSON (`wb_packages`) into a sanitized
	 * array of `{length,width,height,weight}` packs. Malformed/oversized
	 * input degrades to an empty array (callers fall back to the legacy
	 * single-weight/dims fields), never a fatal error.
	 *
	 * @return array<int,array{length:string,width:string,height:string,weight:string}>
	 */
	private function posted_packages() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_and_get_order().
		$raw = isset( $_POST['wb_packages'] ) ? wp_unslash( $_POST['wb_packages'] ) : '';
		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		// Keys are whatever the courier declared in `pack_columns()` — a locker
		// network posts `compartment_size`/`value` where a road courier posts
		// centimetres — so they are sanitized, not white-listed. Hardcoding the
		// four default keys here would drop the others on save, which is the
		// same silent data loss the panel work exists to remove.
		$packages = array();
		foreach ( array_slice( $decoded, 0, 50 ) as $pack ) {
			if ( ! is_array( $pack ) ) {
				continue;
			}

			$row = array();
			foreach ( array_slice( $pack, 0, 12, true ) as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( '' === $key || ! is_scalar( $value ) ) {
					continue;
				}
				$row[ $key ] = sanitize_text_field( (string) $value );
			}

			$has_value = false;
			foreach ( $row as $value ) {
				if ( '' !== trim( (string) $value ) ) {
					$has_value = true;
					break;
				}
			}

			if ( $has_value ) {
				$packages[] = $row;
			}
		}

		return $packages;
	}

	public function ajax_create_label() {
		$order = $this->verify_and_get_order();

		// Rule 24 — existing active shipment/waybill blocks a second create.
		// Recreate only happens through an explicit Cancel first (ajax_delete_label).
		$existing = $order->order->get_meta( '_bgcs3_label' );
		if ( ! empty( $existing['number'] ) ) {
			wp_send_json_error( array( 'message' => __( 'There is already an active shipment label for this order. Use Print/Download/Cancel — do not create a new one until the current shipment is cancelled.', 'bg-commerce-suite' ) ) );
		}

		// BUG-043 / BUG-037 — persist BOTH just-edited panels before building the
		// shipment from the exact values currently shown in the editor.
		// Without this the merchant's delivery edits (тип/град/улица/номер) and
		// waybill edits (чупливо/обявена стойност/пакети/опаковка) never reach
		// `_bgcs3_selection` / `_bgcs3_wb`, and create_label() silently builds from
		// the stale selection and store defaults.
		$this->save_selection_fields( $order->order );
		$this->save_waybill_fields( $order->order );

		// Rule 25 — double-click / concurrent-request protection (BGCS-AUDIT-001).
		// The lock is held across the courier call AND the `_bgcs3_label` write:
		// releasing it the moment create_label() returns leaves a window in which
		// a second request acquires the lock, still sees no label on the order,
		// and creates a second REAL shipment. `wp_send_json_*` ends the request
		// through wp_die(), which does not run `finally`, so every exit path
		// below releases the lock explicitly instead.
		$lock_key = 'bgcs3_create_lock_' . $order->order->get_id();
		$lock     = new Creation_Lock();
		$owner    = $lock->acquire( $lock_key );
		if ( false === $owner ) {
			wp_send_json_error( array( 'message' => __( 'Shipment label creation is already in progress for this order. Wait a few seconds and refresh the page.', 'bg-commerce-suite' ) ) );
		}
		$creation = Shipment_Creation::start( $order->order, $order->courier );
		if ( true !== $creation ) {
			$lock->release( $lock_key, $owner );
			wp_send_json_error( array( 'message' => implode( ' ', (array) $creation->errors ) ) );
		}

		try {
			$result = $order->courier->create_label( $order->order );

			if ( ! $result->success ) {
				$result = Shipment_Creation::finalize_failure( $order->order, $result );
				$errors = implode( ' ', $result->errors );
				$lock->release( $lock_key, $owner );
				wp_send_json_error( array( 'message' => $errors ) );
			}

			Label_Snapshot::apply( $result, $order->order, $order->courier );
			Shipment_Creation::complete( $order->order, $result );

			$order->order->update_meta_data( '_bgcs3_label', $result->to_array() );
			$order->order->add_order_note( sprintf( __( 'Shipment label created: %s', 'bg-commerce-suite' ), $result->number ) );
			if ( ! empty( $result->meta['provider_warning'] ) ) {
				$order->order->add_order_note( sprintf( __( 'Courier warning: %s', 'bg-commerce-suite' ), (string) $result->meta['provider_warning'] ) );
			}
			$order->order->save();
		} catch ( \Throwable $e ) {
			Shipment_Creation::finalize_exception( $order->order );
			$lock->release( $lock_key, $owner );
			throw $e;
		}

		$lock->release( $lock_key, $owner );

		// Post-label automation: auto order status + customer tracking email.
		\BgCommerce3\Shipping\Pricing::after_label_created( $order->order, $order->courier, $result->number );

		wp_send_json_success( array( 'number' => $result->number ) );
	}

	/**
	 * Legacy post-create endpoint kept fail-safe for stale cached admin assets.
	 *
	 * IMPORTANT: BGCS never edits, cancels or re-creates a courier shipment from
	 * this action. It only stores the current delivery/waybill values as
	 * order-level overrides. The administrator must explicitly cancel the current
	 * shipment and then explicitly create a new one to apply those values.
	 */
	public function ajax_update_label() {
		$order = $this->verify_and_get_order();

		$this->save_selection_fields( $order->order );
		$this->save_waybill_fields( $order->order );
		$order->order->add_order_note(
			__( 'BGCS: order-specific shipment settings were saved. The existing courier shipment was not changed. To apply the new values, cancel the current shipment manually and then create a new shipment label.', 'bg-commerce-suite' )
		);
		$order->order->save();

		wp_send_json_success(
			array(
				'message' => __( 'Order settings saved. The existing shipment was not changed. Cancel it manually and create a new shipment label to apply the changes.', 'bg-commerce-suite' ),
			)
		);
	}


	public function ajax_delete_label() {
		$order = $this->verify_and_get_order();
		$label = $order->order->get_meta( '_bgcs3_label' );
		$number = is_array( $label ) && ! empty( $label['number'] ) ? (string) $label['number'] : '';

		// Creation and cancellation share one mutex. A create cannot enter after
		// local cancel cleanup but before this request has fully completed.
		$lock_key = 'bgcs3_create_lock_' . $order->order->get_id();
		$lock     = new Creation_Lock();
		$owner    = $lock->acquire( $lock_key );
		if ( false === $owner ) {
			wp_send_json_error( array( 'message' => __( 'Another shipment operation is already in progress for this order. Wait a few seconds and refresh the page.', 'bg-commerce-suite' ) ) );
		}

		$started = Shipment_Mutation::start_cancel( $order->order, $order->courier );
		if ( true !== $started ) {
			$lock->release( $lock_key, $owner );
			wp_send_json_error( array( 'message' => implode( ' ', (array) $started->errors ) ) );
		}

		try {
			$state = Shipment_Mutation::state( $order->order );
			if ( Shipment_Mutation::CANCEL_CONFIRMED !== ( isset( $state['status'] ) ? $state['status'] : '' ) ) {
				if ( ! $order->courier->delete_label( $order->order ) ) {
					Shipment_Mutation::finalize_failure( $order->order );
					$message = Shipment_Mutation::status_message( $order->order );
					$lock->release( $lock_key, $owner );
					wp_send_json_error( array( 'message' => $message ) );
				}

				// Third-party modules may implement the legacy bool contract without
				// inheriting Abstract_Courier. A true result is their confirmation.
				$state = Shipment_Mutation::state( $order->order );
				if ( Shipment_Mutation::CANCEL_CONFIRMED !== ( isset( $state['status'] ) ? $state['status'] : '' ) ) {
					Shipment_Mutation::remote_confirmed( $order->order, 'legacy_success' );
				}
			}

			if ( ! Shipment_Mutation::complete_cancel( $order->order ) ) {
				$lock->release( $lock_key, $owner );
				wp_send_json_error( array( 'message' => __( 'The courier confirmed cancellation, but BGCS could not complete the local shipment history. Refresh and try again; no replacement was created.', 'bg-commerce-suite' ) ) );
			}

			$order->order->add_order_note(
				sprintf(
					/* translators: %s shipment number. */
					__( 'Shipment cancelled at the courier and archived by BGCS: %s. No replacement shipment was created automatically.', 'bg-commerce-suite' ),
					$number
				)
			);
			$order->order->save();
		} catch ( \Throwable $e ) {
			Shipment_Mutation::finalize_exception( $order->order );
			$lock->release( $lock_key, $owner );
			throw $e;
		}

		$lock->release( $lock_key, $owner );
		wp_send_json_success( array( 'message' => __( 'The shipment was cancelled and archived. Review the saved settings before explicitly creating a replacement.', 'bg-commerce-suite' ) ) );
	}

	public function ajax_resend_shipment_email() {
		$order = $this->verify_and_get_order();
		$sent = \BgCommerce3\Email\Emails::send_for_order( $order->order, $this->container, true );
		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'The email was not sent. Check the customer address and WooCommerce email settings.', 'bg-commerce-suite' ) ) );
		}
		$order->order->add_order_note( __( 'BGCS: the shipment label email was resent manually.', 'bg-commerce-suite' ) );
		$order->order->save();
		wp_send_json_success( array( 'message' => __( 'The email was sent.', 'bg-commerce-suite' ) ) );
	}

	public function ajax_refresh_tracking() {
		$order  = $this->verify_and_get_order();
		$result = $order->courier->tracking( $order->order );

		if ( ! $result->success ) {
			// Rule 256 — a failed live check must never touch the previously
			// persisted history/state; the merchant sees the error, the last
			// known-good timeline stays exactly as it was.
			wp_send_json_error( array( 'message' => implode( ' ', $result->errors ) ) );
		}

		// Rule 250 — accumulate + deduplicate rather than overwrite (same
		// helper Auto_Status uses — fix once), so a manual refresh can never
		// duplicate events a background sync already stored, or vice versa.
		$existing_tracking = $order->order->get_meta( '_bgcs3_tracking' );
		$existing_events    = ( is_array( $existing_tracking ) && ! empty( $existing_tracking['events'] ) ) ? $existing_tracking['events'] : array();
		$incoming_events    = ( ! empty( $result->events ) && is_array( $result->events ) )
			? Tracking_Store::with_source( $result->events, 'manual' )
			: array();
		$merged_events      = Tracking_Store::merge( $existing_events, $incoming_events );
		foreach ( $incoming_events as $incoming_event ) {
			if ( is_array( $incoming_event ) ) {
				Tracking_Unmapped_Registry::record_event( $order->courier, $incoming_event );
			}
		}

		// Same normalization Auto_Status uses (Rule 3 — fix once) — manual
		// refresh must show the same canonical current state as the automatic
		// sync, not a differently-derived one.
		$tracking_data           = $result->to_array();
		$tracking_data['events'] = $merged_events;
		$tracking_data['state']  = Tracking_Status_Policy::latest_state( $order->courier, $merged_events );

		// Some providers (notably BOX NOW) can return an authoritative current
		// parcel status even when their event list is empty. Keep manual refresh
		// consistent with the background worker by using the provider status as
		// a fallback, never as a replacement for a resolved event state.
		if ( Tracking_State::UNKNOWN === $tracking_data['state'] && ! empty( $result->status ) ) {
			$tracking_data['state'] = Tracking_State::sanitize(
				$order->courier->normalize_status( array( 'code' => (string) $result->status, 'status' => (string) $result->status ) )
			);
			if ( Tracking_State::UNKNOWN === $tracking_data['state'] ) {
				Tracking_Unmapped_Registry::record_code( $order->courier->id(), (string) $result->status );
			}
		}
		$tracking_data['normalized_status'] = $tracking_data['state'];
		$raw_status = ! empty( $result->status ) ? sanitize_text_field( (string) $result->status ) : Tracking_Store::latest_raw_status( $merged_events );
		$tracking_data['status'] = $raw_status;
		$tracking_data['raw_status'] = $raw_status;
		$tracking_data['source'] = 'manual';

		$order->order->update_meta_data( '_bgcs3_tracking', $tracking_data );
		Cod_Payout::apply_from_tracking(
			$order->order,
			$order->courier->id(),
			isset( $tracking_data['provider'] ) && is_array( $tracking_data['provider'] ) ? $tracking_data['provider'] : array()
		);
		Tracking_Status_Policy::apply_to_order( $order->order, $tracking_data['state'], $order->courier->id() );
		$order->order->save();

		wp_send_json_success( array( 'events' => $merged_events, 'state' => $tracking_data['state'] ) );
	}

	/**
	 * Verify nonce + capability, resolve order and its courier. Dies on failure.
	 *
	 * @return object{order:\WC_Order,courier:Courier_Interface}
	 */
	private function verify_and_get_order() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'bg-commerce-suite' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'bg-commerce-suite' ) ) );
		}

		$selection = $order->get_meta( '_bgcs3_selection' );
		$courier_id = is_array( $selection ) && ! empty( $selection['courier'] ) ? $selection['courier'] : '';
		$module    = $this->container['modules']->get( $courier_id );

		if ( ! $module instanceof Courier_Interface ) {
			wp_send_json_error( array( 'message' => __( 'The courier is not available.', 'bg-commerce-suite' ) ) );
		}

		return (object) array(
			'order'   => $order,
			'courier' => $module,
		);
	}
}
