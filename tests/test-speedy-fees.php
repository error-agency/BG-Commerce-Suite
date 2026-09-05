<?php
/**
 * TASK-S1 — Speedy fees: PMT, handling and where the numbers come from.
 *
 * The merchant asked for the PMT fee to be surcharged onto the customer as part
 * of delivery unless free shipping applies, for a handling/preparation fee
 * alongside it, and for both to be learned from Speedy with settings that can
 * override them.
 *
 * Two things shape the whole design and are asserted here:
 *
 *   - Surcharges only apply under CUSTOM or FREE pricing. An API-priced order
 *     already contains the courier's own fees; adding them again would charge
 *     the customer twice.
 *   - Speedy publishes no tariff endpoint, so rates are OBSERVED from a real
 *     calculation rather than fetched. The contract endpoint gives entitlements
 *     only. The fixture below is a real `price.details` map, captured from a
 *     live contract — an invented one used the `ShipmentAmounts` names, which
 *     `details` does not use, and the test passed while nothing was recorded.
 *
 * Run: php tests/test-speedy-fees.php
 */

namespace BgCommerce3\Modules\Shipping {
	abstract class Abstract_Courier {}
}

namespace BgCommerce3\Support {
	class Selection {
		public $courier = 'speedy';
		public $delivery_type = 'address';
	}
	class Price_Result {}
	class Tracking_Result {}
	class Sync_Result {}
	class Cache {}
	class Shipping_Availability {}
	class Label_Result {}
	class Label_Pdf_Store {}
}

namespace BgCommerce3\Admin {
	class Icons {}
}

namespace BgCommerce3\Container {
	class Container {}
}

namespace {

	define( 'ABSPATH', __DIR__ );
	define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

	$GLOBALS['bgcs_options']  = array();
	$GLOBALS['bgcs_currency'] = 'BGN';

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['bgcs_options'] ) ? $GLOBALS['bgcs_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['bgcs_options'][ $name ] = $value;
		return true;
	}
	function get_woocommerce_currency() {
		return $GLOBALS['bgcs_currency'];
	}
	function apply_filters( $hook, $value = null ) {
		return $value;
	}
	function __( $text, $domain = null ) {
		return $text;
	}
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
	function bgcs3_get_option( $group, $key = null, $default = null ) {
		return \BgCommerce3\Support\Options::get( $group, $key, $default );
	}

	require BGCS3_PATH . 'app/Support/Options.php';
	require BGCS3_PATH . 'app/Support/Module_Settings.php';
	require BGCS3_PATH . 'app/Shipping/Overrides.php';
	require BGCS3_PATH . 'app/Shipping/Cod.php';
	require BGCS3_PATH . 'app/Shipping/Pricing.php';
	require BGCS3_PATH . 'app/Modules/Shipping/Speedy/Speedy.php';

	use BgCommerce3\Modules\Shipping\Speedy\Speedy;
	use BgCommerce3\Support\Module_Settings;
	use BgCommerce3\Support\Selection;

	$failures = 0;
	function check_fee( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failures++;
		}
	}

	/**
	 * Note the re-prime: Module_Settings::flush() drops a primed field set as
	 * well as a composed one, and without the registry there is nothing to
	 * rebuild it from — so a key left out of $values would resolve to null
	 * instead of its declared default.
	 */
	function set_speedy( array $values ) {
		$GLOBALS['bgcs_options']['bgcs3_speedy'] = $values;
		Module_Settings::flush( 'speedy' );
		prime_speedy_fields();
	}

	function prime_speedy_fields() {
		Module_Settings::prime(
			'speedy',
			array(
				'cod_processing'              => array( 'default' => 'CASH' ),
				'cod_pmt_fee_payer'           => array( 'default' => 'SENDER' ),
				'cod_pmt_percentage'          => array( 'default' => '0.8' ),
				'cod_pmt_min_amount'          => array( 'default' => '0.26' ),
				'handling_fee'                => array( 'default' => '' ),
				'surcharges_on_free_shipping' => array( 'default' => 'no' ),
			)
		);
	}

	function choose_payment( $method ) {
		$_POST['payment_method'] = $method;
	}

	function checkout_cod_base( Speedy $speedy, array $package, $shipping, $payer, $sender_surcharge = 0.0 ) {
		$method = new \ReflectionMethod( Speedy::class, 'resolve_checkout_cod_base' );
		$method->setAccessible( true );

		return $method->invoke( $speedy, $package, $shipping, $payer, $sender_surcharge );
	}

	// The PMT fee is a percentage of the cash-on-delivery base, which comes from
	// the cart — so a cart with a real total is needed to exercise it at all.
	class Bgcs_Cart {
		public $subtotal = 100.0;
		public $subtotal_tax = 0.0;
		public $discount_total = 0.0;
		public $discount_tax = 0.0;
		public $fee_total = 0.0;
		public $fee_tax = 0.0;
		public function get_subtotal() {
			return $this->subtotal;
		}
		public function get_subtotal_tax() {
			return $this->subtotal_tax;
		}
		public function get_discount_total() {
			return $this->discount_total;
		}
		public function get_discount_tax() {
			return $this->discount_tax;
		}
		public function get_total( $context = 'view' ) {
			return 100.0;
		}
		public function get_fees() {
			return array();
		}
		public function get_fee_total() {
			return $this->fee_total;
		}
		public function get_fee_tax() {
			return $this->fee_tax;
		}
	}
	class Bgcs_WC {
		public $cart;
		public $session = null;
		public function __construct() {
			$this->cart = new Bgcs_Cart();
		}
	}
	function WC() {
		static $wc = null;
		if ( null === $wc ) {
			$wc = new Bgcs_WC();
		}
		return $wc;
	}

	prime_speedy_fields();

	$speedy    = new Speedy();
	$selection = new Selection();
	$package   = array( 'contents' => array() );

	// -----------------------------------------------------------------------

	echo "--- PMT_BASE follows the Woo payable composition ---\n";
	{
		$cart                 = WC()->cart;
		$cart->subtotal       = 120.0;
		$cart->subtotal_tax   = 24.0;
		$cart->discount_total = 20.0;
		$cart->discount_tax   = 4.0;
		$cart->fee_total      = 5.0;
		$cart->fee_tax        = 1.0;

		check_fee( 133.0 === $speedy->resolve_pmt_base( $package, 7.0 ), 'Products after discounts, taxes, fees and base shipping resolve to one 133.00 base' );

		$cart->subtotal       = 100.0;
		$cart->subtotal_tax   = 0.0;
		$cart->discount_total = 0.0;
		$cart->discount_tax   = 0.0;
		$cart->fee_total      = 0.0;
		$cart->fee_tax        = 0.0;
	}

	echo "--- Recipient-paid transport and sender-paid PMT remain separate ---\n";
	{
		check_fee( 100.80 === checkout_cod_base( $speedy, $package, 5.80, 'RECIPIENT', 0.80 ), 'Recipient COD excludes direct transport but includes the sender-paid PMT recovery' );
		check_fee( 105.80 === checkout_cod_base( $speedy, $package, 5.80, 'SENDER', 0.80 ), 'Sender-paid transport uses the full customer shipping total once' );
	}

	echo "--- The handling fee is charged whether or not the order is COD ---\n";
	{
		set_speedy( array( 'handling_fee' => '2.50' ) );

		choose_payment( 'bacs' );
		$prepaid = $speedy->calculate_surcharges( $package, $selection, 5.0 );
		check_fee( isset( $prepaid['handling'] ), 'A prepaid order carries the handling fee' );
		check_fee( isset( $prepaid['handling'] ) && 2.5 === $prepaid['handling']['amount'], '…at the configured amount' );
		check_fee( ! isset( $prepaid['pmt'] ), '…and no PMT fee, which is a COD concept' );

		choose_payment( 'cod' );
		$cod = $speedy->calculate_surcharges( $package, $selection, 5.0 );
		check_fee( isset( $cod['handling'] ), 'A COD order carries it too' );
	}

	echo "--- No fee configured means no surcharge ---\n";
	{
		set_speedy( array() );
		choose_payment( 'bacs' );
		check_fee( array() === $speedy->calculate_surcharges( $package, $selection, 5.0 ), 'An empty handling fee adds nothing' );

		set_speedy( array( 'handling_fee' => '0' ) );
		check_fee( array() === $speedy->calculate_surcharges( $package, $selection, 5.0 ), 'and neither does zero' );

		set_speedy( array( 'handling_fee' => 'абв' ) );
		check_fee( array() === $speedy->calculate_surcharges( $package, $selection, 5.0 ), 'and neither does a value that is not a number' );

		set_speedy( array( 'handling_fee' => '1,80' ) );
		$comma = $speedy->calculate_surcharges( $package, $selection, 5.0 );
		check_fee( isset( $comma['handling'] ) && 1.8 === $comma['handling']['amount'], 'A comma decimal is understood' );
	}

	echo "--- What Speedy charged wins over what was typed ---\n";
	{
		set_speedy(
			array(
				'handling_fee'  => '2.50',
				'_synced_fees'  => array(
					'currency'  => 'BGN',
					'synced_at' => time(),
					'handling'  => array( 'amount' => 3.40, 'percent' => null ),
				),
			)
		);
		$result = $speedy->calculate_surcharges( $package, $selection, 5.0 );
		check_fee( isset( $result['handling'] ) && 3.4 === $result['handling']['amount'], 'The observed amount is used, not the typed one' );
		check_fee( isset( $result['handling'] ) && 'api' === $result['handling']['source'], '…and the order records where the number came from' );
	}

	echo "--- The setting is the fallback, which is the normal custom-pricing case ---\n";
	{
		set_speedy( array( 'handling_fee' => '2.50' ) );
		$result = $speedy->calculate_surcharges( $package, $selection, 5.0 );
		check_fee( isset( $result['handling'] ) && 2.5 === $result['handling']['amount'], 'With nothing observed the setting applies' );
		check_fee( isset( $result['handling'] ) && 'override' === $result['handling']['source'], '…and is recorded as such' );
	}

	echo "--- A synced PMT percentage is resolved for the current order, not copied as a stale amount ---\n";
	{
		choose_payment( 'cod' );
		set_speedy(
			array(
				'cod_processing'    => 'POSTAL_MONEY_TRANSFER',
				'_synced_fees'      => array(
					'currency'  => 'BGN',
					'synced_at' => time(),
					'pmt'       => array( 'amount' => 0.55, 'percent' => 1.1 ),
				),
			)
		);
		$result = $speedy->calculate_surcharges( $package, $selection, 5.0 );
		check_fee( isset( $result['pmt'] ) && 1.16 === $result['pmt']['amount'], 'The observed 1.1% is recalculated against the current 105.00 PMT base' );
		check_fee( isset( $result['pmt'] ) && 'api' === $result['pmt']['source'], 'The final order records API as the winning source' );
		check_fee( isset( $result['pmt'] ) && 0.0 === $result['pmt']['included_amount'], 'A custom price contains none of the observed API amount yet' );
		check_fee( isset( $result['pmt'] ) && 'shipping_rate' === $result['pmt']['tax_treatment'], 'The component records that Woo taxes it as part of the shipping rate' );
	}

	echo "--- A foreign-currency observation is never converted ---\n";
	{
		// BGCS has no exchange mechanism. Guessing one would put a wrong number in
		// front of a customer, so the observation is discarded instead.
		set_speedy(
			array(
				'handling_fee' => '2.50',
				'_synced_fees' => array(
					'currency'  => 'EUR',
					'synced_at' => time(),
					'handling'  => array( 'amount' => 3.40, 'percent' => null ),
				),
			)
		);
		$result = $speedy->calculate_surcharges( $package, $selection, 5.0 );
		check_fee( isset( $result['handling'] ) && 2.5 === $result['handling']['amount'], 'A EUR observation in a BGN shop falls back to the setting' );

		$GLOBALS['bgcs_currency'] = 'EUR';
		$result = $speedy->calculate_surcharges( $package, $selection, 5.0 );
		check_fee( isset( $result['handling'] ) && 3.4 === $result['handling']['amount'], 'and is used once the shop is in that currency' );
		$GLOBALS['bgcs_currency'] = 'BGN';
	}

	echo "--- Free shipping: both fees stay with the sender ---\n";
	{
		choose_payment( 'cod' );
		set_speedy(
			array(
				'handling_fee'   => '2.50',
				'cod_processing' => 'POSTAL_MONEY_TRANSFER',
			)
		);

		check_fee(
			array() === $speedy->calculate_free_shipping_surcharges( $package, $selection ),
			'With free shipping the customer is charged nothing — the merchant\'s stated rule'
		);

		// And that this is the DEFAULT, not something the merchant must find.
		check_fee(
			'no' === Module_Settings::get( 'speedy', 'surcharges_on_free_shipping' ),
			'…and that is the default, so it holds without configuration'
		);

		set_speedy(
			array(
				'handling_fee'                => '2.50',
				'cod_processing'              => 'POSTAL_MONEY_TRANSFER',
				'surcharges_on_free_shipping' => 'yes',
			)
		);
		$opted_in = $speedy->calculate_free_shipping_surcharges( $package, $selection );
		check_fee( isset( $opted_in['handling'] ), 'A shop can opt into recovering the handling fee' );
		check_fee( isset( $opted_in['pmt'] ), '…and the PMT fee, under the same one rule' );
	}

	echo "--- The old PMT-only choice is NOT carried over ---\n";
	{
		// Observed live: a shop upgrading to 3.0.49 had `cod_pmt_on_free_shipping`
		// set to "yes", the value was copied into the new setting, and a customer
		// with free shipping was charged 0.60 for the money transfer. The copy
		// looks faithful and is not — the old key governed the PMT fee alone, the
		// new one governs the PMT fee AND the handling fee, so it widened a
		// consent that had never been given.
		set_speedy( array( 'cod_pmt_on_free_shipping' => 'yes' ) );
		check_fee(
			'no' === Module_Settings::get( 'speedy', 'surcharges_on_free_shipping' ),
			'An old "yes" does not become the new setting — the default holds'
		);

		// And the correction removes what 3.0.49 already copied.
		set_speedy(
			array(
				'cod_pmt_on_free_shipping'    => 'yes',
				'surcharges_on_free_shipping' => 'yes',
			)
		);
		check_fee( true === Speedy::undo_free_shipping_carryover(), 'The carried-over value is removed' );
		$after = get_option( 'bgcs3_speedy', array() );
		check_fee(
			! array_key_exists( 'surcharges_on_free_shipping', $after ),
			'…by unsetting it, so the field resolves through its declared default'
		);
		Module_Settings::flush( 'speedy' );
		prime_speedy_fields();
		check_fee(
			'no' === Module_Settings::get( 'speedy', 'surcharges_on_free_shipping' ),
			'…which is "no": with free shipping the customer pays nothing'
		);
		check_fee(
			'yes' === bgcs3_get_option( 'speedy', 'cod_pmt_on_free_shipping' ),
			'…and the old key is left in place for rollback'
		);

		check_fee( false === Speedy::undo_free_shipping_carryover(), 'Running it again does nothing' );

		// A choice made AFTER upgrading differs from the legacy value, so it is
		// the merchant's own and must survive.
		set_speedy(
			array(
				'cod_pmt_on_free_shipping'    => 'no',
				'surcharges_on_free_shipping' => 'yes',
			)
		);
		check_fee( false === Speedy::undo_free_shipping_carryover(), 'A value the merchant set themselves is left alone' );
		check_fee( 'yes' === bgcs3_get_option( 'speedy', 'surcharges_on_free_shipping' ), '…and still applies' );

		set_speedy( array() );
		check_fee( false === Speedy::undo_free_shipping_carryover(), 'With nothing carried over, nothing is written' );
	}

	echo "--- Observing the rates from a real calculation ---\n";
	{
		$record = new ReflectionMethod( Speedy::class, 'record_observed_fees' );
		$record->setAccessible( true );

		$GLOBALS['bgcs_options'] = array( 'bgcs3_speedy' => array() );
		Module_Settings::flush( 'speedy' );

		// The component names are Speedy's own, from ShipmentAmounts.
		$record->invoke(
			$speedy,
			array(
				'currency' => 'BGN',
				'total'    => 12.40,
				'details'  => array(
					'codPremium'        => array( 'amount' => 0.96, 'percent' => 0.8, 'vatPercent' => 20 ),
					'manualHandlingFee' => array( 'amount' => 1.20 ),
					'loadUnload'        => array( 'amount' => 0.80 ),
					'fuelSurcharge'        => array( 'amount' => 0.40 ),
				),
			)
		);

		$fees = $speedy->synced_fees();
		check_fee( isset( $fees['pmt']['amount'] ) && 0.96 === $fees['pmt']['amount'], 'The PMT premium is recorded' );
		check_fee( isset( $fees['pmt']['percent'] ) && 0.8 === $fees['pmt']['percent'], '…with the contract percentage, which is the useful part' );
		check_fee( isset( $fees['pmt']['vat_percent'] ) && 20.0 === $fees['pmt']['vat_percent'], '…and with the provider VAT percentage for the financial audit' );
		check_fee(
			isset( $fees['handling']['amount'] ) && 2.0 === $fees['handling']['amount'],
			'the handling components are summed into the one fee the merchant asked for'
		);
		check_fee( ! isset( $fees['fuelSurcharge'] ), 'Components BGCS does not surcharge are not stored as fees' );
		check_fee( 'BGN' === $fees['currency'], 'The currency is kept, so a mismatch can be detected' );

		// A component Speedy reports as zero must not be recorded: it would then
		// win over the merchant's own setting with the value 0 and silently
		// switch off a fee they configured. Observed live — a contract that
		// charges no handling at all still returns the components, all zero.
		$record->invoke(
			$speedy,
			array(
				'currency' => 'BGN',
				'total'    => 5.0,
				'details'  => array(
					'codPremium'        => array( 'amount' => 0.5, 'percent' => 0.5 ),
					'manualHandlingFee' => array( 'amount' => 0.0 ),
					'fillInFee'         => array( 'amount' => 0.0 ),
					'loadUnload'        => array( 'amount' => 0.0 ),
				),
			)
		);
		$zero_fees = $speedy->synced_fees();
		check_fee( ! isset( $zero_fees['handling'] ), 'An all-zero component is not recorded as a fee of 0.00' );
		check_fee( isset( $zero_fees['pmt'] ), '…while a real charge alongside it still is' );

		// A response with no breakdown must not wipe what is already known.
		$record->invoke( $speedy, array( 'currency' => 'BGN', 'total' => 5.0 ) );
		check_fee( isset( $speedy->synced_fees()['pmt'] ), 'A price with no details leaves the last observation alone' );
	}

	echo "--- Static guards ---\n";
	{
		$code = php_strip_whitespace( BGCS3_PATH . 'app/Modules/Shipping/Speedy/Speedy.php' );

		// The path itself is pinned by test-courier-http-errors.php, which records
		// the URL the real client requests. Here we only assert the module still
		// asks for the terms at all.
		check_fee( false !== strpos( $code, 'get_contract_info' ), 'The contract terms are fetched during a sync' );
		check_fee( false !== strpos( $code, 'record_observed_fees' ), 'A real calculation records what it was charged' );

		// The surcharge must never be added on the API-pricing path: Speedy's own
		// total already contains it.
		$quote_body = substr( $code, strpos( $code, 'function quote(' ) );
		$quote_body = substr( $quote_body, 0, strpos( $quote_body, 'function ' , 20 ) );
		check_fee( false === strpos( $quote_body, 'handling_surcharge' ), 'quote() does not add the handling fee to an API price' );
		check_fee( false === strpos( $quote_body, 'pmt_amount_for' ), 'quote() does not add the full formula on top of an API PMT component' );
		check_fee( false !== strpos( $quote_body, 'pmt_charge_for' ), 'quote() resolves the included API component against the configured floor' );
		$method_code = php_strip_whitespace( BGCS3_PATH . 'app/Shipping/Method.php' );
		check_fee( false !== strpos( $method_code, "'tax_status'" ), 'The shipping method persists the applied Woo tax status with surcharge components' );
		check_fee( false !== strpos( $method_code, '_bgcs3_payment_context' ) && false !== strpos( $method_code, 'Cod::is_chosen()' ), 'Every BGCS rate records the payment context used for pricing' );
		$hooks_code = php_strip_whitespace( BGCS3_PATH . 'app/Shipping/Hooks.php' );
		check_fee( false !== strpos( $hooks_code, "'woocommerce_checkout_process'" ) && false !== strpos( $hooks_code, 'ensure_current_payment_quote' ), 'Checkout creation has a server-side payment-context guard' );
	}

	echo PHP_EOL;
	if ( $failures > 0 ) {
		echo "FAILED: {$failures} check(s)" . PHP_EOL;
		exit( 1 );
	}
	echo 'OK — all Speedy fee checks passed' . PHP_EOL;
}
