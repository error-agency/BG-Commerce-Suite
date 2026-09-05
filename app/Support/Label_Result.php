<?php
/**
 * Result of creating a courier waybill (label).
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

class Label_Result {

	/** @var bool */
	public $success = false;

	/** @var string Courier id. */
	public $courier = '';

	/** @var string Waybill / tracking number. */
	public $number = '';

	/** @var string URL to the printable label PDF. */
	public $pdf_url = '';

	/** @var int Unix timestamp of creation. */
	public $created_at = 0;

	/** @var string Provider shipment/delivery-request number. */
	public $shipment_number = '';

	/** @var string[] Provider parcel identifiers. */
	public $parcel_ids = array();

	/** @var string[] Public tracking numbers. */
	public $tracking_numbers = array();

	/** @var string Stable provider/local label reference. */
	public $label_reference = '';

	/** @var string Courier environment at creation time. */
	public $environment = '';

	/** @var string SHA-256 of the exact create payload. */
	public $payload_fingerprint = '';

	/** @var string available|remote|missing. */
	public $label_status = 'missing';

	/**
	 * Canonical shipment snapshot fields (Rule 28) — the BGCS financial/business
	 * INTENT at create time (from the same resolvers `Cod::resolve_amount()` /
	 * `Overrides::resolve()` couriers use), not necessarily whatever the
	 * provider echoed back. Populated centrally by
	 * `MetaBox::ajax_create_label()` after a successful create — individual
	 * couriers do not need to set these themselves (BUG-012).
	 */

	/** @var string 'office'|'locker'|'address'. */
	public $delivery_type = '';

	/** @var string WooCommerce payment method id at create time. */
	public $payment_method = '';

	/** @var bool */
	public $is_cod = false;

	/** @var float Resolved COD amount (0.0 when not COD or explicitly disabled). */
	public $cod_amount = 0.0;

	/** @var string Order currency. */
	public $cod_currency = '';

	/** @var float Weight in kg used for this shipment. */
	public $weight = 0.0;

	/** @var string RECIPIENT|SENDER|THIRD_PARTY. */
	public $payer = '';

	/** @var float Resolved declared value (0.0 when none). */
	public $declared_value = 0.0;

	/**
	 * @var array<int,array{weight:float,width:float,depth:float,height:float}>
	 * Per-parcel breakdown actually sent to the courier, when it supports
	 * individual per-parcel dimensions (Rule 6 — only couriers whose real API
	 * accepts this are ever populated; empty means the shipment used the
	 * legacy single `$weight` value above). Never guessed/computed client-side.
	 */
	public $packages = array();

	/** @var string Courier-specific package/shipment type (e.g. Speedy BOX|ENVELOPE). Empty when the courier has no such concept. */
	public $package_type = '';

	/** @var array<string,mixed> Courier-specific extras (e.g. Speedy shipment_id). Never secrets/credentials. */
	public $meta = array();

	/** @var string[] Error messages, if any. */
	public $errors = array();

	/**
	 * @param string $message Error message.
	 * @return Label_Result
	 */
	public static function error( $message ) {
		$result          = new self();
		$result->success = false;
		$result->errors  = array( $message );
		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'courier'        => $this->courier,
			'number'         => $this->number,
			'pdf_url'        => $this->pdf_url,
			'created_at'     => $this->created_at,
			'shipment_number' => $this->shipment_number,
			'parcel_ids'      => $this->parcel_ids,
			'tracking_numbers' => $this->tracking_numbers,
			'label_reference' => $this->label_reference,
			'environment'     => $this->environment,
			'payload_fingerprint' => $this->payload_fingerprint,
			'label_status'    => $this->label_status,
			'delivery_type'  => $this->delivery_type,
			'payment_method' => $this->payment_method,
			'is_cod'         => $this->is_cod,
			'cod_amount'     => $this->cod_amount,
			'cod_currency'   => $this->cod_currency,
			'weight'         => $this->weight,
			'payer'          => $this->payer,
			'declared_value' => $this->declared_value,
			'packages'       => $this->packages,
			'package_type'   => $this->package_type,
			'meta'           => $this->meta,
		);
	}
}
