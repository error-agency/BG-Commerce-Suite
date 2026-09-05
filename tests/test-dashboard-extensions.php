<?php
/**
 * Dashboard and Extensions stay one admin page without a duplicate module list.
 *
 * Run: php tests/test-dashboard-extensions.php
 */

$root          = dirname( __DIR__ );
$settings      = file_get_contents( $root . '/app/Admin/Settings/Settings_Page.php' );
$addons        = file_get_contents( $root . '/app/Admin/Addons.php' );
$remote        = file_get_contents( $root . '/app/Addon/Remote_Catalog.php' );
$admin_css     = file_get_contents( $root . '/assets/admin/admin.css' );
$failures      = 0;

function dashboard_extensions_check( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		++$failures;
	}
}

echo "--- Dashboard owns the extensions catalog ---\n";
dashboard_extensions_check(
	false === strpos( $settings, "\$tabs['addons']" ),
	'The sidebar does not register a standalone Extensions tab'
);
dashboard_extensions_check(
	1 === substr_count( $settings, '( new \\BgCommerce3\\Admin\\Addons( $this->container ) )->render();' ),
	'The extensions renderer has one owner'
);
$dashboard_method = strpos( $settings, 'private function render_dashboard()' );
$extensions_call  = strpos( $settings, '( new \\BgCommerce3\\Admin\\Addons( $this->container ) )->render();' );
dashboard_extensions_check(
	false !== $dashboard_method && false !== $extensions_call && $dashboard_method < $extensions_call,
	'The Dashboard renders the complete extensions area after its overview'
);
dashboard_extensions_check(
	false !== strpos( $settings, "if ( 'addons' === \$active_tab )" )
		&& false !== strpos( $settings, "\$active_tab = 'dashboard';", strpos( $settings, "if ( 'addons' === \$active_tab )" ) ),
	'Legacy tab=addons links resolve to Dashboard'
);

echo "--- Duplicate module presentation stays removed ---\n";
dashboard_extensions_check(
	false === strpos( $settings, 'Your modules' ) && false === strpos( $addons, 'Your modules' ),
	'The obsolete Your modules card is absent'
);
dashboard_extensions_check(
	false === strpos( $settings, 'bgcs-modlist' ) && false === strpos( $addons, 'bgcs-modlist' ) && false === strpos( $admin_css, 'bgcs-modlist' ),
	'The duplicate module-list markup and styles are absent'
);
dashboard_extensions_check(
	false !== strpos( $addons, "esc_html__( 'Extensions', 'bg-commerce-suite' )" )
		&& false !== strpos( $addons, "esc_html__( 'Built-in modules', 'bg-commerce-suite' )" ),
	'The unified page exposes optional extensions and all built-in modules'
);
dashboard_extensions_check(
	false === strpos( $addons, 'bgcs-addon-hero' ) && false === strpos( $admin_css, 'bgcs-addon-hero' ),
	'The redundant marketing hero is absent'
);

echo "--- Actions return to the unified page ---\n";
dashboard_extensions_check(
	false !== strpos( $addons, "'return_tab' => 'dashboard'" ),
	'Built-in module toggles return to Dashboard'
);
dashboard_extensions_check(
	false !== strpos( $remote, "'tab'             => 'dashboard'" ),
	'Manual catalog refresh returns to Dashboard'
);
dashboard_extensions_check(
	false !== strpos( $admin_css, '.bgcs-addon-section-head' )
		&& false !== strpos( $admin_css, 'flex-direction: column;', strpos( $admin_css, '@media ( max-width: 782px )' ) ),
	'The unified section header stacks safely on mobile'
);
dashboard_extensions_check(
	false !== strpos( $admin_css, ".bgcs-addon-section-actions {\n\tdisplay: grid;" )
		&& false !== strpos( $admin_css, '.bgcs-addon-section-actions__sync' )
		&& false !== strpos( $admin_css, 'text-align: right;' ),
	'Refresh action and update status use separate aligned rows'
);
dashboard_extensions_check(
	false !== strpos( $admin_css, 'grid-template-columns: minmax( 120px, .75fr ) minmax( 0, 1.25fr );' )
		&& false !== strpos( $admin_css, "grid-template-columns: minmax( 0, 1fr );\n\t\tgap: 2px;" ),
	'Product metadata uses readable desktop and mobile label/value rows'
);
dashboard_extensions_check(
	false !== strpos( $admin_css, ".bgcs-addon-product__actions {\n\talign-items: center;" ),
	'The remote module switch and action button share one vertical center line'
);
dashboard_extensions_check(
	false !== strpos( $addons, 'if ( $this->show_catalog_diagnostics() )' )
		&& false !== strpos( $addons, "defined( 'WP_DEBUG' )" )
		&& false !== strpos( $addons, "\$_GET['bgcs_debug']" ),
	'Catalog diagnostics are hidden outside an explicitly requested debug session'
);

if ( $failures > 0 ) {
	echo "\n{$failures} Dashboard/Extensions check(s) failed\n";
	exit( 1 );
}

echo "\nAll Dashboard/Extensions checks passed\n";
