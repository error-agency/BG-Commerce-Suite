<?php
/**
 * WooCommerce email integration for BGCS.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Email;

use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Courier_Interface;

defined( 'ABSPATH' ) || exit;

final class Emails {

	/** @var Container */
	private $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function init() {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_email' ) );
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ), 20, 2 );
		add_action( 'woocommerce_order_action_bgcs3_resend_shipment_created', array( $this, 'resend_from_order_action' ) );
		add_action( 'bgcs3_label_created', array( $this, 'label_created' ), 10, 3 );
		// WooCommerce block-email editor renders its dynamic order area through
		// this hook. Supplying our shipment summary here keeps the email useful
		// with both the classic and the modern WooCommerce email systems.
		add_action( 'woocommerce_email_general_block_content', array( $this, 'block_email_content' ), 10, 3 );
		add_filter( 'woocommerce_email_preview_dummy_order', array( $this, 'preview_dummy_order' ) );
		add_filter( 'woocommerce_prepare_email_for_preview', array( $this, 'prepare_email_for_preview' ) );
		add_filter( 'woocommerce_email_preview_placeholders', array( $this, 'preview_placeholders' ) );
	}


	/**
	 * Add representative BGCS shipment metadata to WooCommerce's in-memory
	 * preview order. Nothing is persisted; the dummy order exists only for the
	 * native WooCommerce email preview/editor request.
	 *
	 * @param \WC_Order $order WooCommerce preview order.
	 * @return \WC_Order
	 */
	public function preview_dummy_order( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return $order;
		}

		$order->update_meta_data(
			'_bgcs3_selection',
			array(
				'courier'      => 'boxnow',
				'delivery_type' => 'locker',
			)
		);
		$order->update_meta_data(
			'_bgcs3_label',
			array(
				'number'  => '1234567890',
				'courier' => 'boxnow',
			)
		);

		return $order;
	}


	/**
	 * Populate shipment-specific values when WooCommerce renders its built-in
	 * email preview. WooCommerce intentionally uses a dummy order/product in the
	 * preview; these values make the BGCS portion representative as well.
	 *
	 * @param \WC_Email $email Email prepared by WooCommerce preview.
	 * @return \WC_Email
	 */
	public function prepare_email_for_preview( $email ) {
		if ( ! $email instanceof Shipment_Created_Email ) {
			return $email;
		}

		$email->courier_name      = 'BOX NOW';
		$email->waybill_number    = '1234567890';
		$email->tracking_url      = home_url( '/?bgcs_tracking_preview=1234567890' );
		$email->delivery_estimate = '';

		$order = $email->object;
		if ( $order instanceof \WC_Order ) {
			$email->placeholders['{order_number}'] = $order->get_order_number();
			$created = $order->get_date_created();
			$email->placeholders['{order_date}'] = $created ? wc_format_datetime( $created ) : '';
		}
		$email->placeholders['{courier}']         = $email->courier_name;
		$email->placeholders['{waybill_number}']  = $email->waybill_number;
		$email->placeholders['{tracking_number}'] = $email->waybill_number;
		$email->placeholders['{tracking_url}']    = $email->tracking_url;
		$email->placeholders['{delivery_estimate}'] = $email->delivery_estimate;

		return $email;
	}

	/**
	 * Make BGCS placeholders visible to WooCommerce's live preview renderer.
	 *
	 * @param array<string,string> $placeholders Preview placeholders.
	 * @return array<string,string>
	 */
	public function preview_placeholders( $placeholders ) {
		$placeholders['{courier}']         = 'BOX NOW';
		$placeholders['{waybill_number}']  = '1234567890';
		$placeholders['{tracking_number}'] = '1234567890';
		$placeholders['{tracking_url}']    = home_url( '/?bgcs_tracking_preview=1234567890' );
		return $placeholders;
	}

	public function register_email( $emails ) {
		$emails['BGCS3_Shipment_Created_Email'] = new Shipment_Created_Email();
		return $emails;
	}

	public function order_actions( $actions, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			return $actions;
		}
		$label = $order->get_meta( '_bgcs3_label' );
		if ( is_array( $label ) && ! empty( $label['number'] ) ) {
			$actions['bgcs3_resend_shipment_created'] = __( 'BGCS: Resend shipment label email', 'bg-commerce-suite' );
		}
		return $actions;
	}

	public function resend_from_order_action( $order ) {
		if ( $order instanceof \WC_Order ) {
			$sent = self::send_for_order( $order, $this->container, true );
			$order->add_order_note( $sent
				? __( 'BGCS: the shipment label email was resent manually.', 'bg-commerce-suite' )
				: __( 'BGCS: the shipment label email was not sent. Check the email address and WooCommerce email settings.', 'bg-commerce-suite' )
			);
			$order->save();
		}
	}

	/**
	 * Dynamic shipment summary for WooCommerce's block email editor.
	 *
	 * @param bool      $sent_to_admin Admin flag.
	 * @param bool      $plain_text Plain flag.
	 * @param \WC_Email $email Email instance.
	 */
	public function block_email_content( $sent_to_admin, $plain_text, $email ) {
		if ( ! $email instanceof Shipment_Created_Email ) {
			return;
		}
		$courier = '' !== $email->courier_name ? $email->courier_name : __( 'courier', 'bg-commerce-suite' );
		$number  = '' !== $email->waybill_number ? $email->waybill_number : '—';
		if ( $plain_text ) {
			/* translators: 1: courier name, 2: shipment tracking number. */
			echo esc_html( sprintf( __( 'A shipment label has been created with %1$s. Tracking number: %2$s', 'bg-commerce-suite' ), $courier, $number ) ) . "\n";
			if ( '' !== $email->delivery_estimate ) {
				/* translators: %s: formatted expected-delivery date. */
				echo esc_html( sprintf( __( 'Expected delivery: %s', 'bg-commerce-suite' ), $email->delivery_estimate ) ) . "\n";
			}
			if ( $email->tracking_url ) {
				echo esc_url_raw( $email->tracking_url ) . "\n";
			}
			return;
		}
		/* translators: %s: courier name. */
		echo '<p>' . esc_html( sprintf( __( 'A shipment label has been created for your order with %s.', 'bg-commerce-suite' ), $courier ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Tracking number:', 'bg-commerce-suite' ) . '</strong> ' . esc_html( $number ) . '</p>';
		if ( '' !== $email->delivery_estimate ) {
			echo '<p><strong>' . esc_html__( 'Expected delivery:', 'bg-commerce-suite' ) . '</strong> ' . esc_html( $email->delivery_estimate ) . '</p>';
		}
		if ( $email->tracking_url ) {
			echo '<p><a href="' . esc_url( $email->tracking_url ) . '">' . esc_html__( 'Track shipment', 'bg-commerce-suite' ) . '</a></p>';
		}
	}

	public function label_created( $order_id, $courier_id, $number ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			self::send_for_order( $order, $this->container, false, $courier_id, $number );
		}
	}

	/**
	 * @param \WC_Order $order Order.
	 * @param Container $container DI container.
	 * @param bool      $force Force manual send.
	 * @param string    $courier_id Optional courier id.
	 * @param string    $number Optional waybill number.
	 * @return bool
	 */
	public static function send_for_order( \WC_Order $order, Container $container, $force = false, $courier_id = '', $number = '' ) {
		$selection = $order->get_meta( '_bgcs3_selection' );
		$label     = $order->get_meta( '_bgcs3_label' );
		$courier_id = $courier_id ? sanitize_key( $courier_id ) : ( is_array( $selection ) && ! empty( $selection['courier'] ) ? sanitize_key( $selection['courier'] ) : '' );
		$number = $number ? (string) $number : ( is_array( $label ) && ! empty( $label['number'] ) ? (string) $label['number'] : '' );
		if ( '' === $courier_id || '' === $number ) {
			return false;
		}
		$module = $container['modules']->get( $courier_id );
		if ( ! $module instanceof Courier_Interface ) {
			return false;
		}
		$tracking_url = method_exists( $module, 'tracking_url' ) ? (string) $module->tracking_url( $number ) : '';
		$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;
		if ( ! $mailer ) {
			return false;
		}
		foreach ( $mailer->get_emails() as $email ) {
			if ( $email instanceof Shipment_Created_Email ) {
				return $email->trigger( $order->get_id(), $module->name(), $number, $tracking_url, $force );
			}
		}
		return false;
	}
}
