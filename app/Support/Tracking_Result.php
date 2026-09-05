<?php
/**
 * Result of a tracking query.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

class Tracking_Result {

	/** @var bool */
	public $success = false;

	/** @var string Normalised status label. */
	public $status = '';

	/** @var array<int,array{time:string,code:string,text:string}> Tracking events. */
	public $events = array();

	/** @var string[] Error messages, if any. */
	public $errors = array();

	/**
	 * Provider-returned shipment facts that are useful on the WooCommerce order
	 * but are not tracking events themselves (price, COD payout timestamps,
	 * expected-delivery date, package facts, warnings, etc.). Couriers should
	 * store only non-secret, non-PII scalar values here.
	 *
	 * @var array<string,mixed>
	 */
	public $meta = array();

	/**
	 * @param string $message Error message.
	 * @return Tracking_Result
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
		$data = array(
			'status'     => $this->status,
			'events'     => $this->events,
			'updated_at' => time(),
		);

		if ( ! empty( $this->meta ) ) {
			$data['provider'] = $this->meta;
		}

		return $data;
	}
}
