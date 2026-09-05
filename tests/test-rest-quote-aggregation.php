<?php
/**
 * Phase 11 contract checks for the canonical REST quote path.
 */

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wc_get_price_decimals() {
	return 2;
}

require_once dirname( __DIR__ ) . '/app/Rest/Controller.php';
require_once dirname( __DIR__ ) . '/app/Rest/Quote_Endpoint.php';

final class BGCS_Phase11_Rate {
	private $cost;
	private $taxes;
	private $meta;

	public function __construct( $cost, array $taxes, array $meta ) {
		$this->cost  = $cost;
		$this->taxes = $taxes;
		$this->meta  = $meta;
	}

	public function get_cost() {
		return $this->cost;
	}

	public function get_taxes() {
		return $this->taxes;
	}

	public function get_meta_data() {
		return $this->meta;
	}
}

function bgcs_phase11_rate( $courier, $state, $cost, array $taxes = array(), array $warnings = array() ) {
	return new BGCS_Phase11_Rate(
		$cost,
		$taxes,
		array(
			'_bgcs3_courier'    => $courier,
			'_bgcs3_validated'  => true,
			'_bgcs3_price_state' => $state,
			'_bgcs3_warnings'   => $warnings,
		)
	);
}

$failures = 0;
function bgcs_phase11_check( $condition, $message ) {
	global $failures;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ' - ' . $message . PHP_EOL;
	if ( ! $condition ) {
		++$failures;
	}
}

$summary = \BgCommerce3\Rest\Quote_Endpoint::summarize_rates(
	array(
		array( 'rates' => array( bgcs_phase11_rate( 'speedy', 'calculated', 4.00, array( 0.80 ), array( 'First warning' ) ) ) ),
		array( 'rates' => array( bgcs_phase11_rate( 'speedy', 'calculated', 3.00, array( 0.60 ), array( 'First warning', 'Second warning' ) ) ) ),
	),
	'speedy'
);
bgcs_phase11_check( $summary['valid'], 'Every shipping package must have a settled rate for the requested courier' );
bgcs_phase11_check( 8.40 === $summary['cost'], 'Multiple package costs and shipping taxes are aggregated as a gross quote' );
bgcs_phase11_check( array( 'First warning', 'Second warning' ) === $summary['warnings'], 'Warnings are sanitized and deduplicated' );
bgcs_phase11_check( false === $summary['free'], 'Calculated zero/non-zero rates are not mislabeled as free' );

$free = \BgCommerce3\Rest\Quote_Endpoint::summarize_rates(
	array(
		array( 'rates' => array( bgcs_phase11_rate( 'econt', 'free', 0.0 ) ) ),
		array( 'rates' => array( bgcs_phase11_rate( 'econt', 'free', 0.0 ) ) ),
	),
	'econt'
);
bgcs_phase11_check( $free['valid'] && $free['free'] && 0.0 === $free['cost'], 'Free is true only when every package has an explicit free rate' );

$pending = \BgCommerce3\Rest\Quote_Endpoint::summarize_rates(
	array( array( 'rates' => array( bgcs_phase11_rate( 'pigeon', 'pending', 0.0 ) ) ) ),
	'pigeon'
);
bgcs_phase11_check( ! $pending['valid'], 'A pending zero rate is never returned as a valid quote' );

$partial = \BgCommerce3\Rest\Quote_Endpoint::summarize_rates(
	array(
		array( 'rates' => array( bgcs_phase11_rate( 'speedy', 'calculated', 4.0 ) ) ),
		array( 'rates' => array( bgcs_phase11_rate( 'econt', 'calculated', 3.0 ) ) ),
	),
	'speedy'
);
bgcs_phase11_check( ! $partial['valid'], 'A partial multiple-package quote fails closed' );

$source = file_get_contents( dirname( __DIR__ ) . '/app/Rest/Quote_Endpoint.php' );
bgcs_phase11_check( false === strpos( $source, '$module->quote(' ), 'REST quote does not bypass the canonical WC shipping method' );
bgcs_phase11_check( false !== strpos( $source, 'Selection_Synchronizer::synchronize' ), 'REST quote uses the shared Core selection and rate pipeline' );

if ( $failures ) {
	fwrite( STDERR, "FAILED: {$failures} Phase 11 REST quote check(s)." . PHP_EOL );
	exit( 1 );
}

echo 'OK - Phase 11 REST quote aggregation checks passed.' . PHP_EOL;
