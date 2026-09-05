<?php
/**
 * Offline contract harness for the shared office/locker checkout map.
 *
 * This test intentionally runs without WordPress, WooCommerce, a browser or a
 * courier account. It protects the PHP/asset wiring that made the map setting
 * inert in Checkout Blocks while leaving live interaction to staging QA.
 */

namespace Automattic\WooCommerce\Blocks\Integrations {
	interface IntegrationInterface {}
}

namespace BgCommerce3\Checkout {
	class Checkout {
		public static function frontend_data() {
			return array( 'showMap' => ! empty( $GLOBALS['bgcs_map_show'] ) );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
	define( 'BGCS3_URL', 'https://example.test/wp-content/plugins/bg-commerce-suite/' );

	$GLOBALS['bgcs_map_show']  = false;
	$GLOBALS['bgcs_map_calls'] = array();

	function wp_register_script( $handle, $src, $dependencies, $version, $in_footer ) {
		$GLOBALS['bgcs_map_calls']['script'] = compact( 'handle', 'src', 'dependencies', 'version', 'in_footer' );
	}

	function wp_localize_script( $handle, $object_name, $data ) {
		$GLOBALS['bgcs_map_calls']['localized'] = compact( 'handle', 'object_name', 'data' );
	}

	function wp_register_style( $handle, $src, $dependencies, $version ) {
		$GLOBALS['bgcs_map_calls']['style'] = compact( 'handle', 'src', 'dependencies', 'version' );
	}

	function wp_style_add_data( $handle, $key, $value ) {
		$GLOBALS['bgcs_map_calls']['style_data'][] = compact( 'handle', 'key', 'value' );
	}

	function wp_enqueue_style( $handle ) {
		$GLOBALS['bgcs_map_calls']['enqueued_styles'][] = $handle;
	}

	function bgcs_map_check( $label, $condition ) {
		static $failures = 0;

		if ( null === $label ) {
			return $failures;
		}

		if ( ! $condition ) {
			++$failures;
		}

		printf( "  [%s] %s\n", $condition ? 'PASS' : 'FAIL', $label );
		return $failures;
	}

	function bgcs_map_source( $relative_path ) {
		$contents = file_get_contents( BGCS3_PATH . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) );
		if ( false === $contents ) {
			throw new \RuntimeException( 'Unable to read ' . $relative_path );
		}
		return $contents;
	}

	function bgcs_map_contains( $source, $needle ) {
		return false !== strpos( $source, $needle );
	}

	require BGCS3_PATH . 'app/Checkout/Blocks_Integration.php';

	echo "--- Blocks integration must enqueue the registered map stylesheet ---\n";
	$integration = new \BgCommerce3\Checkout\Blocks_Integration();
	$integration->initialize();
	$calls = $GLOBALS['bgcs_map_calls'];

	bgcs_map_check( 'Blocks script registered', isset( $calls['script'] ) && 'bgcs-blocks' === $calls['script']['handle'] );
	bgcs_map_check( 'Blocks stylesheet registered', isset( $calls['style'] ) && 'bgcs-blocks' === $calls['style']['handle'] );
	bgcs_map_check( 'Blocks stylesheet enqueued', isset( $calls['enqueued_styles'] ) && in_array( 'bgcs-blocks', $calls['enqueued_styles'], true ) );
	bgcs_map_check( 'Blocks RTL replacement registered', isset( $calls['style_data'][0] ) && 'rtl' === $calls['style_data'][0]['key'] && 'replace' === $calls['style_data'][0]['value'] );
	bgcs_map_check( 'OFF setting exported unchanged', isset( $calls['localized']['data']['showMap'] ) && false === $calls['localized']['data']['showMap'] );
	bgcs_map_check( 'Frontend and editor use the same integration handle', array( 'bgcs-blocks' ) === $integration->get_script_handles() && array( 'bgcs-blocks' ) === $integration->get_editor_script_handles() );

	$GLOBALS['bgcs_map_show']  = true;
	$GLOBALS['bgcs_map_calls'] = array();
	$integration->initialize();
	$on_calls = $GLOBALS['bgcs_map_calls'];
	bgcs_map_check( 'ON setting exported unchanged', isset( $on_calls['localized']['data']['showMap'] ) && true === $on_calls['localized']['data']['showMap'] );

	echo "--- Admin setting must reach both checkout renderers ---\n";
	$settings = bgcs_map_source( 'app/Admin/Settings/Settings_Page.php' );
	$checkout = bgcs_map_source( 'app/Checkout/Checkout.php' );
	$classic  = bgcs_map_source( 'assets/js/bgcs-checkout.js' );
	$blocks   = bgcs_map_source( 'assets/build/blocks.js' );

	bgcs_map_check( 'Admin field posts checkout[show_map]', bgcs_map_contains( $settings, "'checkout[show_map]'" ) );
	bgcs_map_check( 'Admin save normalizes show_map to yes/no', bgcs_map_contains( $settings, "Options::set( 'checkout', 'show_map', \$show_map ? 'yes' : 'no' )" ) );
	bgcs_map_check( 'Frontend config exports a strict showMap boolean', bgcs_map_contains( $checkout, "'showMap'       => 'yes' === bgcs3_get_option( 'checkout', 'show_map', 'yes' )" ) );
	bgcs_map_check( 'Classic Leaflet assets follow the setting', bgcs_map_contains( $checkout, "if ( \$show_map ) {") && bgcs_map_contains( $checkout, "\$checkout_deps[] = 'bgcs-leaflet';" ) );
	bgcs_map_check( 'Classic map renderer refuses OFF mode', bgcs_map_contains( $classic, 'return cfg().showMap !== false && !! window.L;' ) );
	bgcs_map_check( 'Blocks map renderer refuses OFF mode', bgcs_map_contains( $blocks, '!1!==window.bgcsCheckout.showMap' ) );
	bgcs_map_check( 'Blocks uses the current OSM tile endpoint', bgcs_map_contains( $blocks, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ) && ! bgcs_map_contains( $blocks, 'https://{s}.tile.openstreetmap.org' ) );
	bgcs_map_check( 'Blocks keeps visible OSM contributor attribution', bgcs_map_contains( $blocks, 'https://www.openstreetmap.org/copyright' ) && bgcs_map_contains( $blocks, 'OpenStreetMap</a> contributors' ) );
	bgcs_map_check( 'Blocks points to the bundled Leaflet license notice', bgcs_map_contains( $blocks, 'Leaflet 1.9.4 under BSD-2-Clause; see THIRD-PARTY-NOTICES.md' ) );

	echo "--- Location markers must drive the shared selection and price refresh ---\n";
	bgcs_map_check( 'Classic map receives the city-scoped pool', bgcs_map_contains( $classic, 'renderMap( cityOfficePool );' ) );
	bgcs_map_check( 'Classic marker click selects the matching location', bgcs_map_contains( $classic, 'pickOfficeFromMap( o );' ) && bgcs_map_contains( $classic, 'selectOffice( office );' ) );
	bgcs_map_check( 'Classic selection schedules WooCommerce recalculation', bgcs_map_contains( $classic, 'updateScheduler.request();' ) && bgcs_map_contains( $classic, "trigger( 'update_checkout' )" ) );
	bgcs_map_check( 'Classic payment switch recalculates BGCS shipping', bgcs_map_contains( $classic, "input[name=\"payment_method\"]" ) && bgcs_map_contains( $classic, 'selectedShippingRateIds().some( isBgcsRateId )' ) );
	bgcs_map_check( 'Classic courier switch publishes an incomplete selection', bgcs_map_contains( $classic, 'createIncompleteSelection( meta.courier, selectedType() )' ) && bgcs_map_contains( $classic, 'setSelection( resetSelection' ) );
	bgcs_map_check( 'Classic courier switch clears provider city cache', substr_count( $classic, 'cityCache = {};' ) >= 2 );
	bgcs_map_check( 'Blocks marker click uses the selection callback', bgcs_map_contains( $blocks, 'n.on("click",()=>i&&i(t))' ) );
	bgcs_map_check( 'Blocks selection uses Store API cart update', bgcs_map_contains( $blocks, 'extensionCartUpdate' ) && bgcs_map_contains( $blocks, 'namespace:"bg-commerce-suite"' ) );
	$blocks_transition = bgcs_map_source( 'assets/js/bgcs-blocks-selection-state.js' );
	bgcs_map_check( 'Blocks courier switch publishes an incomplete selection', bgcs_map_contains( $blocks_transition, 'createCourierTransitionObserver' ) && bgcs_map_contains( $blocks_transition, "namespace: 'bg-commerce-suite'" ) );
	bgcs_map_check( 'Blocks courier observer scans all packages for the selected BGCS rate', bgcs_map_contains( $blocks_transition, 'selectedBgcsRateContext( packages )' ) );

	foreach ( array( 'Speedy', 'Econt', 'Pigeon', 'BoxNow' ) as $courier ) {
		$locations = bgcs_map_source( 'app/Modules/Shipping/' . $courier . '/Locations.php' );
		bgcs_map_check(
			$courier . ' locations expose latitude and longitude',
			(bool) preg_match( "/'lat'\\s*=>/", $locations ) && (bool) preg_match( "/'lng'\\s*=>/", $locations )
		);
	}

	echo "--- Map containers must have usable responsive dimensions ---\n";
	$classic_css = bgcs_map_source( 'assets/css/bgcs-checkout.css' );
	$blocks_css  = bgcs_map_source( 'assets/build/blocks.css' );
	bgcs_map_check( 'Classic map has explicit height', (bool) preg_match( '/\\.bgcs-map\\s*\\{[^}]*height:\s*320px[^}]*min-height:\s*320px/s', $classic_css ) );
	bgcs_map_check( 'Blocks map has explicit height', (bool) preg_match( '/\\.bgcs-map\\{[^}]*height:320px[^}]*min-height:320px/s', $blocks_css ) );
	bgcs_map_check( 'Classic map has a mobile height', (bool) preg_match( '/@media\s*\\(max-width:\s*480px\\)[^{]*\\{.*?\\.bgcs-map\\s*\\{[^}]*height:\s*280px/s', $classic_css ) );
	bgcs_map_check( 'Blocks map has a mobile height', bgcs_map_contains( $blocks_css, '@media (max-width:480px){.bgcs-map{height:280px;min-height:280px}}' ) );

	$failures = bgcs_map_check( null, true );
	echo "\n" . ( $failures ? "FAILED: {$failures} check(s)\n" : "ALL CHECKS PASSED\n" );
	exit( $failures ? 1 : 0 );
}
