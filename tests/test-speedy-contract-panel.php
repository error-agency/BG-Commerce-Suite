<?php
/**
 * The Speedy contract panel must not assert what it does not know.
 *
 * `ContractInfo` carries entitlements, and the panel reports them. The trap is
 * that an entitlement Speedy never sent is not an entitlement Speedy refused: a
 * sync that never ran, a read that failed, or a payload shaped differently all
 * produce an absent key. Collapsing that into "not in your contract" tells the
 * merchant their contract lacks a service when the truth is that nobody looked.
 *
 * Two further things are asserted here because both misled a live shop:
 *
 *   - `codPremium` is the COD premium under EITHER processing type. Labelling it
 *     "postal money transfer fee" on a CASH contract showed a fee for a service
 *     the same panel said was not in the contract.
 *   - The administrative fee is a boolean flag, not an amount. Switching it on
 *     against a contract that does not carry it changes no price and raises no
 *     error, which reads as a broken setting unless the panel says so.
 *
 * Run: php tests/test-speedy-contract-panel.php
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

	$GLOBALS['bgcs_options'] = array();

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['bgcs_options'] ) ? $GLOBALS['bgcs_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['bgcs_options'][ $name ] = $value;
		return true;
	}
	function apply_filters( $hook, $value = null ) {
		return $value;
	}
	function __( $text, $domain = null ) {
		return $text;
	}
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_html__( $text, $domain = null ) {
		return esc_html( $text );
	}
	function human_time_diff( $from, $to = 0 ) {
		return '5 minutes';
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
	function get_woocommerce_currency() {
		return 'EUR';
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

	$failures = 0;
	function check( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			++$failures;
		}
	}

	function prime_speedy() {
		Module_Settings::prime(
			'speedy',
			array(
				'cod_processing'     => array( 'default' => 'CASH' ),
				'administrative_fee' => array( 'default' => 'no' ),
			)
		);
	}

	function set_speedy( array $values ) {
		$GLOBALS['bgcs_options']['bgcs3_speedy'] = $values;
		Module_Settings::flush( 'speedy' );
		prime_speedy();
	}

	/** Render the panel and return its HTML. */
	function panel( Speedy $speedy ) {
		ob_start();
		$speedy->render_account_custom();
		return (string) ob_get_clean();
	}

	prime_speedy();
	$speedy = new Speedy();

	// -- 1. Three states, and they are distinguishable ------------------------

	$state = new ReflectionMethod( Speedy::class, 'contract_term_state' );
	$state->setAccessible( true );

	list( $yes_label, $yes_tone ) = $state->invoke( $speedy, true );
	list( $no_label, $no_tone )   = $state->invoke( $speedy, false );
	list( $unk_label, $unk_tone ) = $state->invoke( $speedy, null );

	check( 'success' === $yes_tone, 'An entitlement the contract carries reads as carried' );
	check( 'neutral' === $no_tone, 'An entitlement the contract lacks reads as lacking, not as an error' );
	check( 'warning' === $unk_tone, 'An entitlement Speedy never reported reads as unchecked' );
	check(
		3 === count( array_unique( array( $yes_label, $no_label, $unk_label ) ) ),
		'The three states have three different wordings'
	);

	// -- 2. An absent entitlement is never printed as a refusal ---------------

	set_speedy(
		array(
			'_contract_info' => array(
				'contract_id'            => 4321,
				'money_transfer_allowed' => true,
				// has_cod_annex, cod_fiscal_receipt and administrative_fee_allowed
				// are absent: Speedy never told us.
				'synced_at'              => time() - 300,
			),
		)
	);

	$html = panel( $speedy );

	check(
		0 === substr_count( $html, 'not in your contract' ),
		'A payload with only one known entitlement prints no refusal at all'
	);
	check( 3 === substr_count( $html, 'not checked' ), 'The three absent entitlements each read "not checked"' );
	// ">in your contract<" is the label alone; a bare substring test would also
	// match "not in your contract" and pass on exactly the bug being guarded.
	check( 1 === substr_count( $html, '>in your contract<' ), 'The one known entitlement is reported as carried' );
	check( false !== strpos( $html, '4321' ), 'The contract id is shown' );
	check( false !== strpos( $html, '5 minutes' ), 'The panel says when it last read from Speedy' );

	// -- 3. A genuine refusal still reads as a refusal ------------------------

	set_speedy(
		array(
			'_contract_info' => array(
				'money_transfer_allowed'     => false,
				'has_cod_annex'              => false,
				'cod_fiscal_receipt'         => false,
				'administrative_fee_allowed' => false,
				'synced_at'                  => time() - 300,
			),
		)
	);

	$html = panel( $speedy );
	check( 4 === substr_count( $html, 'not in your contract' ), 'Four explicit refusals are reported as refusals' );
	check( false === strpos( $html, 'not checked' ), 'Nothing reads as unchecked when everything was answered' );

	// -- 4. The COD premium label follows the processing type -----------------

	$fees = array(
		'pmt'       => array( 'amount' => 0.77, 'percent' => 0 ),
		'currency'  => 'EUR',
		'synced_at' => time() - 300,
	);

	set_speedy( array( '_synced_fees' => $fees, 'cod_processing' => 'CASH' ) );
	$html = panel( $speedy );
	check(
		false !== strpos( $html, 'Cash-on-delivery premium' ) && false === strpos( $html, 'Postal money transfer fee' ),
		'On a cash contract the COD premium is not labelled a postal money transfer fee'
	);
	check( false !== strpos( $html, '0.77 EUR' ), 'The observed amount is shown with its currency' );

	set_speedy( array( '_synced_fees' => $fees, 'cod_processing' => 'POSTAL_MONEY_TRANSFER' ) );
	$html = panel( $speedy );
	check(
		false !== strpos( $html, 'Postal money transfer fee' ),
		'On a PMT contract the same component is labelled a postal money transfer fee'
	);

	// -- 5. A setting the contract cannot honour is called out ----------------

	set_speedy(
		array(
			'_contract_info'     => array( 'administrative_fee_allowed' => false, 'synced_at' => time() ),
			'administrative_fee' => 'yes',
		)
	);
	$html = panel( $speedy );
	check(
		false !== strpos( $html, 'Speedy will ignore the flag' ),
		'An administrative fee switched on against a contract without it is called out'
	);

	set_speedy(
		array(
			'_contract_info'     => array( 'administrative_fee_allowed' => true, 'synced_at' => time() ),
			'administrative_fee' => 'yes',
		)
	);
	check(
		false === strpos( panel( $speedy ), 'Speedy will ignore the flag' ),
		'No warning when the contract does carry the administrative fee'
	);

	// Not knowing is not grounds for a warning: an unsynced contract must not
	// accuse a setting that may be perfectly valid.
	set_speedy(
		array(
			'_contract_info'     => array( 'synced_at' => time() ),
			'administrative_fee' => 'yes',
		)
	);
	check(
		false === strpos( panel( $speedy ), 'Speedy will ignore the flag' ),
		'No warning when the entitlement was never read — unknown is not a refusal'
	);

	set_speedy(
		array(
			'_contract_info' => array( 'money_transfer_allowed' => false, 'synced_at' => time() ),
			'cod_processing' => 'POSTAL_MONEY_TRANSFER',
		)
	);
	check(
		false !== strpos( panel( $speedy ), 'does not include it' ),
		'Postal money transfer chosen against a contract without it is still called out'
	);

	// -- 6. Nothing synced at all -------------------------------------------

	set_speedy( array() );
	check(
		false !== strpos( panel( $speedy ), 'Sync with Speedy' ),
		'With nothing stored the panel asks for a sync instead of inventing terms'
	);

	echo PHP_EOL . ( $failures ? "$failures failed" : 'All contract panel checks passed' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}
