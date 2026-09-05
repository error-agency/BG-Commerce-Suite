<?php
/**
 * WooCommerce transactional email sent after BGCS successfully creates a label.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Email;

use BgCommerce3\Shipping\Delivery_Estimate;

defined( 'ABSPATH' ) || exit;

class Shipment_Created_Email extends \WC_Email {

	/** @var string */
	public $courier_name = '';
	/** @var string */
	public $waybill_number = '';
	/** @var string */
	public $tracking_url = '';
	/** @var string */
	public $delivery_estimate = '';

	public function __construct() {
		$this->id             = 'bgcs3_shipment_created';
		$this->customer_email = true;
		$this->email_group    = 'orders';
		$this->title          = __( 'BGCS — Shipment label created', 'bg-commerce-suite' );
		$this->description    = __( 'Sent to the customer after BG Commerce Suite successfully creates a shipment label.', 'bg-commerce-suite' );
		$this->template_html  = 'emails/bgcs-shipment-created.php';
		$this->template_plain = 'emails/plain/bgcs-shipment-created.php';
		$this->template_base  = BGCS3_PATH . 'templates/';

		// Declare BGCS placeholders before the WooCommerce constructor runs so
		// they appear in the native Email settings UI together with WooCommerce's
		// own site/store placeholders. Values are populated for each order in trigger().
		$this->placeholders = array(
			'{order_number}'     => '',
			'{order_date}'       => '',
			'{courier}'          => '',
			'{waybill_number}'   => '',
			'{tracking_number}'  => '',
			'{tracking_url}'     => '',
			'{delivery_estimate}' => '',
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] Shipment for order #{order_number} is ready', 'bg-commerce-suite' );
	}

	public function get_default_heading() {
		return __( 'Your shipment is ready', 'bg-commerce-suite' );
	}

	public function get_default_additional_content() {
		return __( 'Thank you for your order.', 'bg-commerce-suite' );
	}

	/**
	 * @param int    $order_id Order id.
	 * @param string $courier_name Courier display name.
	 * @param string $waybill_number Waybill/tracking number.
	 * @param string $tracking_url Public tracking URL.
	 * @param bool   $force Force manual resend even if disabled.
	 * @return bool
	 */
	public function trigger( $order_id, $courier_name = '', $waybill_number = '', $tracking_url = '', $force = false ) {
		$this->setup_locale();
		$this->object = wc_get_order( $order_id );
		if ( ! $this->object instanceof \WC_Order ) {
			$this->restore_locale();
			return false;
		}

		$this->recipient         = $this->object->get_billing_email();
		$this->courier_name      = (string) $courier_name;
		$this->waybill_number    = (string) $waybill_number;
		$this->tracking_url      = (string) $tracking_url;
		$this->delivery_estimate = Delivery_Estimate::format( Delivery_Estimate::for_order( $this->object ) );
		$this->placeholders['{order_number}']    = $this->object->get_order_number();
		$created = $this->object->get_date_created();
		$this->placeholders['{order_date}']      = $created ? wc_format_datetime( $created ) : '';
		$this->placeholders['{courier}']         = $this->courier_name;
		$this->placeholders['{waybill_number}']  = $this->waybill_number;
		$this->placeholders['{tracking_number}'] = $this->waybill_number;
		$this->placeholders['{tracking_url}']    = $this->tracking_url;
		$this->placeholders['{delivery_estimate}'] = $this->delivery_estimate;

		$sent = false;
		if ( $force ) {
			// WooCommerce 10.9+ exposes the manual-send helper which preserves
			// recipient skip logging. Keep an older-Woo fallback for our WC 8.2 floor.
			if ( method_exists( $this, 'send_if_recipient' ) ) {
				$sent = (bool) $this->send_if_recipient();
			} elseif ( $this->get_recipient() ) {
				$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		} else {
			// Newer WooCommerce centralizes enabled/recipient checks and email
			// observability here; older supported releases use the equivalent legacy path.
			if ( method_exists( $this, 'send_notification' ) ) {
				$sent = (bool) $this->send_notification();
			} elseif ( $this->is_enabled() && $this->get_recipient() ) {
				$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		}
		$this->restore_locale();
		return $sent;
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'courier_name'       => $this->courier_name,
				'waybill_number'     => $this->waybill_number,
				'tracking_url'       => $this->tracking_url,
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'courier_name'       => $this->courier_name,
				'waybill_number'     => $this->waybill_number,
				'tracking_url'       => $this->tracking_url,
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	public function init_form_fields() {
		// Use WooCommerce's native generic email settings so feature-gated fields
		// such as CC/BCC and block-editor preheader are inherited automatically.
		parent::init_form_fields();
		if ( isset( $this->form_fields['enabled'] ) ) {
			$this->form_fields['enabled']['default'] = 'no';
		}
	}

}
