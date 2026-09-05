<?php
/**
 * Offline regression checks for the Speedy PMT percentage/minimum contract.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping {
	abstract class Abstract_Courier {}
}

namespace BgCommerce3\Support {
	class Selection {}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

	$GLOBALS['bgcs_pmt_options'] = array(
		'cod_pmt_percentage' => '0.8',
		'cod_pmt_min_amount' => '0.26',
	);

	function bgcs3_get_option( $group, $key, $default = '' ) {
		unset( $group );
		return array_key_exists( $key, $GLOBALS['bgcs_pmt_options'] )
			? $GLOBALS['bgcs_pmt_options'][ $key ]
			: $default;
	}

	// Speedy resolves these through Module_Settings now (BGCS-AUDIT-005), which
	// reads the stored group and falls back to the field's declared default —
	// so the fixture is exposed the way the real option row would be.
	function get_option( $name, $default = false ) {
		return ( 'bgcs3_speedy' === $name ) ? $GLOBALS['bgcs_pmt_options'] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		return true;
	}

	function bgcs_pmt_check( $label, $expected, $actual ) {
		static $failures = 0;
		if ( null === $label ) {
			return $failures;
		}

		$passed          = abs( (float) $expected - (float) $actual ) < 0.00001;

		if ( ! $passed ) {
			++$failures;
		}

		printf(
			"  [%s] %s (expected %.2f, got %.2f)\n",
			$passed ? 'PASS' : 'FAIL',
			$label,
			$expected,
			$actual
		);

		return $failures;
	}

	require BGCS3_PATH . 'app/Support/Options.php';
	require BGCS3_PATH . 'app/Support/Module_Settings.php';
	require BGCS3_PATH . 'app/Modules/Shipping/Speedy/Speedy.php';

	$speedy = new \BgCommerce3\Modules\Shipping\Speedy\Speedy();

	echo "--- Configured PMT amount is max(percentage, minimum) ---\n";
	bgcs_pmt_check( 'Small shipment uses minimum', 0.26, $speedy->pmt_amount_for( 10.0 ) );
	bgcs_pmt_check( 'Larger shipment uses percentage', 0.80, $speedy->pmt_amount_for( 100.0 ) );
	bgcs_pmt_check( 'Currency result rounds after comparison', 0.27, $speedy->pmt_amount_for( 33.125 ) );
	bgcs_pmt_check( 'Zero COD has no PMT fee', 0.0, $speedy->pmt_amount_for( 0.0 ) );

	echo "--- Speedy sender amount cannot bypass the configured floor ---\n";
	bgcs_pmt_check( 'Reported lower amount is raised to minimum', 0.26, $speedy->sender_pmt_amount_for( 10.0, 0.10 ) );
	bgcs_pmt_check( 'Reported lower amount is raised to percentage', 0.80, $speedy->sender_pmt_amount_for( 100.0, 0.50 ) );
	bgcs_pmt_check( 'Higher reported sender amount is preserved', 1.25, $speedy->sender_pmt_amount_for( 100.0, 1.25 ) );

	echo "--- The financial record distinguishes included API money from a real addition ---\n";
	$minimum = $speedy->pmt_charge_for( 10.0, 0.10, 0.10 );
	bgcs_pmt_check( 'The minimum remains the final PMT amount', 0.26, $minimum['amount'] );
	bgcs_pmt_check( 'Only the missing minimum difference is added', 0.16, $minimum['additional_amount'] );
	bgcs_pmt_check( 'The winning source is the minimum', 1, 'minimum' === $minimum['source'] ? 1 : 0 );

	$formula = $speedy->pmt_charge_for( 100.0, 0.50, 0.50 );
	bgcs_pmt_check( 'The formula remains the final PMT amount', 0.80, $formula['amount'] );
	bgcs_pmt_check( 'Only the formula/API difference is added', 0.30, $formula['additional_amount'] );
	bgcs_pmt_check( 'The winning source is the formula', 1, 'formula' === $formula['source'] ? 1 : 0 );

	$api = $speedy->pmt_charge_for( 100.0, 1.25, 1.25 );
	bgcs_pmt_check( 'A higher API amount remains final', 1.25, $api['amount'] );
	bgcs_pmt_check( 'An API amount already in the quote is not added again', 0.0, $api['additional_amount'] );
	bgcs_pmt_check( 'The winning source is the API', 1, 'api' === $api['source'] ? 1 : 0 );
	$api_equal = $speedy->pmt_charge_for( 100.0, 0.80, 0.80 );
	bgcs_pmt_check( 'An equal observed amount still records the API as its source', 1, 'api' === $api_equal['source'] ? 1 : 0 );

	$GLOBALS['bgcs_pmt_options'] = array(
		'cod_pmt_percentage' => '0,8',
		'cod_pmt_min_amount' => '0,26',
	);
	bgcs_pmt_check( 'Comma decimal settings remain supported', 0.80, $speedy->pmt_amount_for( 100.0 ) );

	$failures = bgcs_pmt_check( null, 0, 0 );
	if ( $failures > 0 ) {
		fwrite( STDERR, "Speedy PMT contract checks failed: {$failures}.\n" );
		exit( 1 );
	}

	echo "Speedy PMT contract checks passed.\n";
}
