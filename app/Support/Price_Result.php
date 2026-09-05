<?php
/**
 * Result of a shipping price quote.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

class Price_Result {

	/** @var bool Whether the quote is valid/usable. */
	public $valid = false;

	/** @var float Net shipping cost (including surcharges). */
	public $cost = 0.0;

	/** @var float Base shipping cost before courier surcharges. */
	public $base_cost = 0.0;

	/** @var array<int,float> WC tax array. */
	public $taxes = array();

	/** @var string Currency code. */
	public $currency = '';

	/** @var bool Whether shipping is free for this selection. */
	public $free = false;

	/** @var string Pricing mode ('static', 'api', 'free'). */
	public $mode = '';

	/** @var string Pricing source ('free', 'static', 'configured_tariff', 'api'). */
	public $source = '';

	/** @var string Delivery destination type ('office', 'locker', 'address'). */
	public $destination_type = '';

	/** @var float Package weight in kg. */
	public $weight = 0.0;

	/** @var float Weight threshold in kg (if static contract weight rule applies). */
	public $weight_threshold = 0.0;

	/** @var array<string,mixed> Courier-specific surcharges (e.g. PMT recovery). */
	public $surcharges = array();

	/** @var float Total amount of all surcharges. */
	public $surcharge_total = 0.0;

	/** @var string[] Warning messages that do not invalidate the quote. */
	public $warnings = array();

	/** @var array<string,string> Normalized courier-provided delivery estimate. */
	public $delivery_estimate = array();

	/** @var string[] Error messages, if any. */
	public $errors = array();

	/** @var array<string,mixed> Extra meta to attach to the rate. */
	public $meta = array();

	/** @var Shipping_Availability|null Structured non-selectable state. */
	public $availability = null;

	/**
	 * Add a structured surcharge component to the price result.
	 *
	 * @param string              $name   Surcharge identifier (e.g. 'pmt').
	 * @param float               $amount Surcharge monetary amount.
	 * @param array<string,mixed> $data   Additional metadata (label, payer, calculation basis).
	 * @return self
	 */
	public function add_surcharge( $name, $amount, array $data = array() ) {
		$name   = sanitize_key( $name );
		$amount = (float) $amount;

		if ( '' === $name || $amount <= 0 ) {
			return $this;
		}

		$this->surcharges[ $name ] = array_merge(
			array(
				'name'   => $name,
				'amount' => $amount,
			),
			$data
		);

		$this->surcharge_total = 0.0;
		foreach ( $this->surcharges as $surcharge ) {
			if ( is_array( $surcharge ) && isset( $surcharge['amount'] ) ) {
				$this->surcharge_total += (float) $surcharge['amount'];
			} elseif ( is_numeric( $surcharge ) ) {
				$this->surcharge_total += (float) $surcharge;
			}
		}

		return $this;
	}

	/**
	 * Convert structured price result to an array for storage or logging.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'valid'             => $this->valid,
			'cost'              => $this->cost,
			'base_cost'         => $this->base_cost,
			'mode'              => $this->mode,
			'source'            => $this->source,
			'destination_type'  => $this->destination_type,
			'weight'            => $this->weight,
			'weight_threshold'  => $this->weight_threshold,
			'surcharges'        => $this->surcharges,
			'surcharge_total'   => $this->surcharge_total,
			'currency'          => $this->currency,
			'free'              => $this->free,
			'warnings'          => $this->warnings,
			'delivery_estimate' => $this->delivery_estimate,
			'errors'            => $this->errors,
			'meta'              => $this->meta,
			'availability'      => $this->availability instanceof Shipping_Availability ? $this->availability->to_public_array() : null,
		);
	}

	/**
	 * @param string $message Error message.
	 * @return Price_Result
	 */
	public static function error( $message ) {
		$result         = new self();
		$result->valid  = false;
		$result->errors = array( $message );
		return $result;
	}

	/** @return Price_Result */
	public static function unavailable( $code, $customer_message, $technical_message = '', array $context = array() ) {
		$result               = self::error( $customer_message );
		$result->availability = Shipping_Availability::unavailable( $code, $customer_message, $technical_message, $context );
		return $result;
	}

	/** @return Price_Result */
	public static function temporary_error( $code, $customer_message, $technical_message = '', array $context = array() ) {
		$result               = self::error( $customer_message );
		$result->availability = Shipping_Availability::error( $code, $customer_message, $technical_message, $context );
		return $result;
	}
}
