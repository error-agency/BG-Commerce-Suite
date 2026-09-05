<?php
/**
 * Verify the remote product-catalog contract without booting WordPress.
 *
 * Run: php tests/test-remote-catalog.php
 */

define( 'ABSPATH', __DIR__ );
define( 'WP_PLUGIN_DIR', __DIR__ );
define( 'BGCS3_VERSION', '4.1.3' );
define( 'DAY_IN_SECONDS_FOR_CATALOG_TEST', 86400 );

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

$GLOBALS['bgcs_catalog_options']  = array();
$GLOBALS['bgcs_catalog_response'] = null;
$GLOBALS['bgcs_catalog_request']  = array();
$GLOBALS['bgcs_catalog_locale']   = 'bg_BG';
$GLOBALS['bgcs_catalog_plugins']  = array();
$GLOBALS['bgcs_catalog_scheduled'] = false;
$GLOBALS['bgcs_catalog_schedule_calls'] = array();
$GLOBALS['bgcs_catalog_unschedule_calls'] = 0;

function __( $text ) {
	return $text;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
}
function esc_url_raw( $value ) {
	return trim( (string) $value );
}
function wp_http_validate_url( $value ) {
	return false !== filter_var( $value, FILTER_VALIDATE_URL );
}
function wp_parse_url( $value, $component = -1 ) {
	return parse_url( $value, $component );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function get_user_locale() {
	return $GLOBALS['bgcs_catalog_locale'];
}
function wp_normalize_path( $value ) {
	return str_replace( '\\', '/', (string) $value );
}
function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}
function get_plugins() {
	return $GLOBALS['bgcs_catalog_plugins'];
}
function as_has_scheduled_action() {
	return $GLOBALS['bgcs_catalog_scheduled'];
}
function as_schedule_recurring_action( $timestamp, $interval, $hook, $args, $group ) {
	$GLOBALS['bgcs_catalog_scheduled'] = true;
	$GLOBALS['bgcs_catalog_schedule_calls'][] = compact( 'timestamp', 'interval', 'hook', 'args', 'group' );
}
function as_unschedule_all_actions() {
	$GLOBALS['bgcs_catalog_scheduled'] = false;
	++$GLOBALS['bgcs_catalog_unschedule_calls'];
}
function wp_rand( $min, $max ) {
	return $min;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['bgcs_catalog_options'] ) ? $GLOBALS['bgcs_catalog_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['bgcs_catalog_options'][ $name ] = $value;
	return true;
}
function wp_remote_get( $url, $args ) {
	$GLOBALS['bgcs_catalog_request'] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['bgcs_catalog_response'];
}
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['code'] ) ? $response['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}
function wp_remote_retrieve_header( $response, $name ) {
	return isset( $response['headers'][ strtolower( $name ) ] ) ? $response['headers'][ strtolower( $name ) ] : '';
}

require_once dirname( __DIR__ ) . '/app/Addon/Remote_Catalog.php';
require_once dirname( __DIR__ ) . '/app/Addon/Installed_Product.php';
require_once dirname( __DIR__ ) . '/app/Addon/Catalog.php';

use BgCommerce3\Addon\Catalog;
use BgCommerce3\Addon\Installed_Product;
use BgCommerce3\Addon\Remote_Catalog;

$failures = 0;
function check_catalog( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		++$failures;
	}
}

function catalog_fixture() {
	return array(
		'schema_version' => 1,
		'revision'       => '2026-09-01-0001',
		'generated_at'   => '2026-09-01T08:00:00Z',
		'expires_at'     => '2026-09-03T08:00:00Z',
		'products'       => array(
			array(
				'id'             => 'new-product',
				'type'           => 'standalone-plugin',
				'name'           => array( 'bg_BG' => 'Нов продукт', 'en_US' => 'New product' ),
				'category'       => array( 'bg_BG' => 'Продажби' ),
				'description'    => array( 'bg_BG' => '<b>Полезен</b> продукт' ),
				'latest_version' => '1.2.3-beta.1',
				'price'          => array( 'bg_BG' => '49 лв.' ),
				'product_url'    => 'https://error.bg/products/new-product/',
				'plugin_file'    => 'new-product/new-product.php',
				'requires_core'  => '>=4.0.0',
				'requires_api'   => '',
				'icon'           => 'package',
				'status'         => 'available',
				'status_label'   => array( 'bg_BG' => 'Наличен' ),
				'featured'       => false,
				'sort_order'     => 20,
				'ignored_code'   => '<?php echo "never";',
			),
			array(
				'id'             => 'bgcs-flow',
				'type'           => 'bgcs-addon',
				'name'           => array( 'bg_BG' => 'BGCS Flow Plus' ),
				'latest_version' => '1.0.0',
				'product_url'    => 'https://error.bg/products/bgcs-flow/',
				'plugin_file'    => 'remote-claim/remote-claim.php',
				'status'         => 'available',
				'sort_order'     => 10,
			),
		),
		'campaigns'      => array(
			array(
				'id'         => 'low-priority',
				'product_id' => 'new-product',
				'label'      => array( 'bg_BG' => 'Стара оферта' ),
				'price'      => array( 'bg_BG' => '39 лв.' ),
				'cta_label'  => array( 'bg_BG' => 'Виж' ),
				'cta_url'    => 'https://error.bg/offers/low/',
				'starts_at'  => '2026-08-01T00:00:00Z',
				'ends_at'    => '2026-10-01T00:00:00Z',
				'priority'   => 5,
			),
			array(
				'id'         => 'high-priority',
				'product_id' => 'new-product',
				'label'      => array( 'bg_BG' => 'Основна оферта' ),
				'price'      => array( 'bg_BG' => '29 лв.' ),
				'cta_label'  => array( 'bg_BG' => 'Купи' ),
				'cta_url'    => 'https://shop.error.bg/new-product/',
				'starts_at'  => '2026-08-01T00:00:00Z',
				'ends_at'    => '2026-10-01T00:00:00Z',
				'priority'   => 10,
			),
		),
	);
}

echo "--- Feed validation and presentation ---\n";
$normalized = Remote_Catalog::normalize_payload( catalog_fixture() );
check_catalog( ! is_wp_error( $normalized ), 'A valid schema v1 feed is accepted' );
check_catalog( 'bgcs-flow' === $normalized['products'][0]['id'], 'Products are sorted deterministically' );
check_catalog( ! isset( $normalized['products'][1]['ignored_code'] ), 'Unknown executable-looking fields are discarded' );
check_catalog( 'Полезен продукт' === $normalized['products'][1]['description']['bg_BG'], 'Localized copy is reduced to plain text' );

$items = Remote_Catalog::catalog_items_from_payload( $normalized, strtotime( '2026-09-01T12:00:00Z' ) );
check_catalog( 'Нов продукт' === $items['new-product']['name'], 'The current user locale is selected' );
check_catalog( 'Основна оферта' === $items['new-product']['promotion_label'], 'The highest-priority active campaign wins' );
check_catalog( '29 лв.' === $items['new-product']['price'], 'An active campaign may override display-only price text' );
check_catalog( '49 лв.' === $items['new-product']['regular_price'], 'The normal price remains available beside a promotion' );
check_catalog( 'https://shop.error.bg/new-product/' === $items['new-product']['url'], 'An approved subdomain CTA is accepted' );

$GLOBALS['bgcs_catalog_locale'] = 'de_DE';
$items = Remote_Catalog::catalog_items_from_payload( $normalized, strtotime( '2026-09-01T12:00:00Z' ) );
check_catalog( 'New product' === $items['new-product']['name'], 'A missing exact locale falls back directly to en_US' );
$GLOBALS['bgcs_catalog_locale'] = 'bg_BG';

$items = Remote_Catalog::catalog_items_from_payload( $normalized, strtotime( '2026-07-01T12:00:00Z' ) );
check_catalog( ! isset( $items['new-product']['promotion_label'] ), 'A future campaign is inactive' );
$items = Remote_Catalog::catalog_items_from_payload( $normalized, strtotime( '2026-10-01T00:00:00Z' ) );
check_catalog( ! isset( $items['new-product']['promotion_label'] ), 'A campaign is inactive at its exact ends_at instant' );

$tied = catalog_fixture();
$tied['campaigns'][0]['id']       = 'a-campaign';
$tied['campaigns'][0]['priority'] = 10;
$tied['campaigns'][1]['id']       = 'z-campaign';
$tied['campaigns'][1]['priority'] = 10;
$tied = Remote_Catalog::normalize_payload( $tied );
$items = Remote_Catalog::catalog_items_from_payload( $tied, strtotime( '2026-09-01T12:00:00Z' ) );
check_catalog( 'Стара оферта' === $items['new-product']['promotion_label'], 'Campaign id ascending breaks an equal-priority tie' );

$invalid = catalog_fixture();
$invalid['products'][0]['product_url'] = 'https://example.com/untrusted';
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'A CTA outside the approved hosts rejects the full feed' );

$invalid = catalog_fixture();
$invalid['products'][0]['latest_version'] = 'latest';
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'Latest version must follow SemVer' );

$invalid = catalog_fixture();
$invalid['schema_version'] = 2;
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'An unknown schema version is rejected' );

$invalid = catalog_fixture();
$invalid['schema_version'] = '1';
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'Schema version must be an integer, not a coerced string' );

$invalid = catalog_fixture();
unset( $invalid['products'] );
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'A missing required top-level field is rejected' );

$invalid = catalog_fixture();
$invalid['products'][0]['status'] = 'unknown';
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'An unknown product status is rejected' );

$invalid = catalog_fixture();
$invalid['products'][0]['status'] = array( 'available' );
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'Product scalar fields reject array type confusion' );

$invalid = catalog_fixture();
$invalid['products'][0]['sort_order'] = '20';
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'Product sort_order must be an integer' );

$invalid = catalog_fixture();
$invalid['campaigns'][0]['priority'] = '5';
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'Campaign priority must be an integer' );

$invalid = catalog_fixture();
$invalid['products'][0]['product_url'] = 'http://error.bg/insecure';
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'A non-HTTPS product URL is rejected' );

$invalid = catalog_fixture();
$invalid['generated_at'] = 'next Friday';
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'Feed dates must use the RFC 3339 contract' );

$invalid = catalog_fixture();
$invalid['generated_at'] = '2026-02-31T08:00:00Z';
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'Calendar-invalid UTC dates are rejected instead of normalized' );

$invalid = catalog_fixture();
$invalid['campaigns'][0]['product_id'] = 'missing-product';
check_catalog( is_wp_error( Remote_Catalog::normalize_payload( $invalid ) ), 'A campaign cannot reference an unknown product' );

$html = catalog_fixture();
$html['products'][0]['description']['bg_BG'] = '<script>alert(1)</script><style>body{display:none}</style>Безопасно';
$html = Remote_Catalog::normalize_payload( $html );
check_catalog( false === strpos( $html['products'][1]['description']['bg_BG'], '<' ), 'Script and style markup never survives normalization' );

echo "--- Refresh cache and last-known-good behavior ---\n";
$GLOBALS['bgcs_catalog_response'] = array(
	'code'    => 200,
	'body'    => wp_json_encode_for_catalog_test( catalog_fixture() ),
	'headers' => array( 'etag' => '"feed-1"', 'last-modified' => 'Tue, 01 Sep 2026 08:00:00 GMT' ),
);
$result = Remote_Catalog::refresh();
check_catalog( ! is_wp_error( $result ) && 'updated' === $result['status'], 'A valid HTTP response replaces the cached feed' );
check_catalog( 0 === $GLOBALS['bgcs_catalog_request']['args']['redirection'], 'The client refuses cross-host HTTP redirects' );
check_catalog( Remote_Catalog::MAX_BYTES + 1 === $GLOBALS['bgcs_catalog_request']['args']['limit_response_size'], 'The HTTP layer caps the response before validation' );
check_catalog( 5 === $GLOBALS['bgcs_catalog_request']['args']['timeout'], 'The catalog request uses the five-second timeout contract' );
check_catalog( 'BG-Commerce-Suite-Catalog/1' === $GLOBALS['bgcs_catalog_request']['args']['headers']['User-Agent'], 'The request sends no site URL or installed-product inventory' );
check_catalog( Remote_Catalog::FEED_URL === $GLOBALS['bgcs_catalog_request']['url'], 'The request uses the exact public endpoint without identifying query parameters' );
check_catalog( array( 'Accept', 'User-Agent' ) === array_keys( $GLOBALS['bgcs_catalog_request']['args']['headers'] ), 'The first request adds no identifying catalog headers' );
check_catalog( '"feed-1"' === get_option( Remote_Catalog::OPTION )['etag'], 'ETag is stored for conditional requests' );

$stored = get_option( Remote_Catalog::OPTION );
$GLOBALS['bgcs_catalog_response'] = array( 'code' => 304, 'body' => '', 'headers' => array() );
$result = Remote_Catalog::refresh();
check_catalog( 'unchanged' === $result['status'], 'HTTP 304 keeps the existing catalog' );
check_catalog( '"feed-1"' === $GLOBALS['bgcs_catalog_request']['args']['headers']['If-None-Match'], 'Conditional refresh sends the stored ETag' );

$GLOBALS['bgcs_catalog_response'] = array( 'code' => 200, 'body' => '{bad json', 'headers' => array() );
$result = Remote_Catalog::refresh();
$after  = get_option( Remote_Catalog::OPTION );
check_catalog( is_wp_error( $result ), 'An invalid HTTP payload fails closed' );
check_catalog( $stored['payload'] === $after['payload'], 'A failed refresh preserves the last known good payload' );
check_catalog( 'catalog_invalid_json' === $after['last_error'], 'Only a safe failure code is persisted' );

$GLOBALS['bgcs_catalog_response'] = new WP_Error( 'timeout', 'Connection timed out' );
$result = Remote_Catalog::refresh();
check_catalog( is_wp_error( $result ) && 'catalog_http_error' === $result->get_error_code(), 'A transport timeout uses the cached catalog' );

$GLOBALS['bgcs_catalog_response'] = array( 'code' => 500, 'body' => 'failure', 'headers' => array() );
$result = Remote_Catalog::refresh();
check_catalog( is_wp_error( $result ) && 'catalog_http_status' === $result->get_error_code(), 'HTTP 500 uses the cached catalog' );

$GLOBALS['bgcs_catalog_response'] = array( 'code' => 200, 'body' => str_repeat( 'x', Remote_Catalog::MAX_BYTES + 1 ), 'headers' => array() );
$result = Remote_Catalog::refresh();
check_catalog( is_wp_error( $result ) && 'catalog_invalid_size' === $result->get_error_code(), 'An oversized body is rejected' );

$GLOBALS['bgcs_catalog_response'] = array( 'code' => 200, 'body' => '', 'headers' => array() );
$result = Remote_Catalog::refresh();
check_catalog( is_wp_error( $result ) && 'catalog_invalid_size' === $result->get_error_code(), 'An empty body is rejected' );

$unknown_schema                   = catalog_fixture();
$unknown_schema['schema_version'] = 2;
$GLOBALS['bgcs_catalog_response'] = array( 'code' => 200, 'body' => wp_json_encode_for_catalog_test( $unknown_schema ), 'headers' => array() );
$result = Remote_Catalog::refresh();
check_catalog( is_wp_error( $result ) && 'catalog_schema_version' === $result->get_error_code(), 'An incompatible remote schema cannot replace the cache' );
check_catalog( $stored['payload'] === get_option( Remote_Catalog::OPTION )['payload'], 'HTTP failures never replace last-known-good data' );

$GLOBALS['bgcs_catalog_response'] = array(
	'code'    => 200,
	'body'    => wp_json_encode_for_catalog_test( catalog_fixture() ),
	'headers' => array( 'etag' => "bad\r\netag" ),
);
$result = Remote_Catalog::refresh();
check_catalog( ! is_wp_error( $result ) && '' === get_option( Remote_Catalog::OPTION )['etag'], 'An invalid ETag is ignored without breaking a valid feed' );

$stale                         = get_option( Remote_Catalog::OPTION );
$stale['last_success_at']      = time() - Remote_Catalog::FRESH_FOR - 1;
$stale['payload']['expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS_FOR_CATALOG_TEST );
update_option( Remote_Catalog::OPTION, $stale, false );
check_catalog( ! empty( Remote_Catalog::items() ), 'An old last-known-good catalog remains available during an outage' );
check_catalog( 'stale' === Remote_Catalog::status()['cache_status'], 'Diagnostics distinguish a stale cache' );

$expired                          = $stale;
$expired['payload']['expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - 1 );
update_option( Remote_Catalog::OPTION, $expired, false );
check_catalog( ! empty( Remote_Catalog::items() ), 'An expired feed remains visible as last-known-good data' );
check_catalog( 'expired' === Remote_Catalog::status()['cache_status'], 'Diagnostics report an expired feed without disabling it' );
$diagnostics = Remote_Catalog::status();
foreach ( array( 'endpoint', 'schema_version', 'revision', 'etag', 'last_success_at', 'last_attempt_at', 'generated_at', 'expires_at', 'products_count', 'campaigns_count', 'cache_status', 'last_error' ) as $key ) {
	check_catalog( array_key_exists( $key, $diagnostics ), 'Diagnostics expose ' . $key );
}

echo "--- Installed product and version states ---\n";
$GLOBALS['bgcs_catalog_plugins'] = array( 'bgcs-flow/bgcs-flow.php' => array( 'Version' => '0.9.8' ) );
$local = Installed_Product::detect( 'bgcs-flow/bgcs-flow.php', '1.0.0', false, $GLOBALS['bgcs_catalog_plugins'] );
check_catalog( Installed_Product::UPDATE_AVAILABLE === $local['state'], 'An older local plugin reports update available' );
$GLOBALS['bgcs_catalog_plugins']['bgcs-flow/bgcs-flow.php']['Version'] = '1.0.0';
check_catalog( Installed_Product::INSTALLED_LATEST === Installed_Product::detect( 'bgcs-flow/bgcs-flow.php', '1.0.0', false, $GLOBALS['bgcs_catalog_plugins'] )['state'], 'An equal local version reports installed latest' );
$GLOBALS['bgcs_catalog_plugins']['bgcs-flow/bgcs-flow.php']['Version'] = '1.1.0';
check_catalog( Installed_Product::LOCAL_NEWER === Installed_Product::detect( 'bgcs-flow/bgcs-flow.php', '1.0.0', false, $GLOBALS['bgcs_catalog_plugins'] )['state'], 'A newer local version is not treated as an error' );
check_catalog( Installed_Product::NOT_INSTALLED === Installed_Product::detect( 'missing/missing.php', '1.0.0', false, $GLOBALS['bgcs_catalog_plugins'] )['state'], 'A missing plugin reports not installed' );
check_catalog( Installed_Product::NOT_INSTALLED === Installed_Product::detect( '../../unsafe.php', '1.0.0', false, $GLOBALS['bgcs_catalog_plugins'] )['state'], 'An unsafe plugin basename is never inspected' );

require_once __DIR__ . '/fixtures/catalog-module.php';
class BGCS_Catalog_Test_Registry {
	private $modules;
	public function __construct( array $modules ) {
		$this->modules = $modules;
	}
	public function all() {
		return $this->modules;
	}
}
$fixture_inventory = array( 'fixtures/catalog-plugin.php' => array( 'Version' => '1.0.0' ) );
$resolved_file     = Installed_Product::resolve_plugin_file( '', 'fixtures', $fixture_inventory );
$fixture_module    = new BGCS_Catalog_Module_Fixture();
$fixture_registry  = new BGCS_Catalog_Test_Registry( array( $fixture_module ) );
check_catalog( 'fixtures/catalog-plugin.php' === $resolved_file, 'A missing feed basename resolves from one exact installed plugin directory' );
check_catalog( $fixture_module === Installed_Product::module_for_plugin( $resolved_file, $fixture_registry ), 'A catalog product matches the module class owned by the same local plugin directory' );
check_catalog( null === Installed_Product::module_for_plugin( 'other/other.php', $fixture_registry ), 'A different plugin cannot claim the registered module' );
check_catalog(
	'' === Installed_Product::resolve_plugin_file( '', 'fixtures', array( 'fixtures/one.php' => array(), 'fixtures/two.php' => array() ) ),
	'An ambiguous plugin directory fails closed'
);

echo "--- Product cards are remote-only ---\n";
$stored['last_success_at'] = time();
update_option( Remote_Catalog::OPTION, $stored, false );
$catalog = Catalog::items();
check_catalog( 'BGCS Flow Plus' === $catalog['bgcs-flow']['name'], 'Flow presentation data comes from the validated feed' );
check_catalog( 'remote-claim/remote-claim.php' === $catalog['bgcs-flow']['plugin_file'], 'Flow installed detection uses the validated feed plugin basename' );
check_catalog( ! isset( $catalog['bgcs-flow']['module_id'] ), 'The remote feed cannot assign a runtime module id' );
check_catalog( isset( $catalog['new-product'] ), 'Validated remote products become presentation cards' );

$empty_catalog = $stored;
$empty_catalog['payload']['products'] = array();
$empty_catalog['payload']['campaigns'] = array();
update_option( Remote_Catalog::OPTION, $empty_catalog, false );
check_catalog( array() === Catalog::items(), 'An empty remote feed produces no invented product cards' );
update_option( Remote_Catalog::OPTION, $stored, false );

$admin_source  = file_get_contents( dirname( __DIR__ ) . '/app/Admin/Addons.php' );
$client_source = file_get_contents( dirname( __DIR__ ) . '/app/Addon/Remote_Catalog.php' );
$installed_source = file_get_contents( dirname( __DIR__ ) . '/app/Addon/Installed_Product.php' );
$catalog_source = file_get_contents( dirname( __DIR__ ) . '/app/Addon/Catalog.php' );
check_catalog( false !== strpos( $client_source, "current_user_can( 'manage_options' )" ), 'Manual refresh requires manage_options' );
check_catalog( false !== strpos( $client_source, 'check_admin_referer( self::REFRESH_NONCE )' ), 'Manual refresh requires its nonce' );
check_catalog( false !== strpos( $client_source, 'as_has_scheduled_action' ), 'The hourly background action is registered only when missing' );
check_catalog( false !== strpos( $admin_source, 'esc_html( $description )' ), 'Remote product descriptions are escaped at render time' );
check_catalog( false !== strpos( $installed_source, 'get_plugins()' ) && false !== strpos( $installed_source, 'version_compare(' ), 'Installed/version detection uses the standard read-only WordPress inventory' );
check_catalog( false !== strpos( $installed_source, 'new \\ReflectionClass( $module )' ), 'Module matching verifies the local class owner instead of trusting a remote module id' );
check_catalog( false === strpos( $catalog_source, 'BGCS Flow' ) && false === strpos( $catalog_source, '0.4.5' ) && false === strpos( $catalog_source, 'checkout_designer' ), 'Core contains no hardcoded Flow product record' );
$integration_source = $client_source . $installed_source . $admin_source;
$forbidden_runtime  = array( 'download_url(', 'activate_plugin(', 'plugins_api(', 'Plugin_Upgrader', 'wp_enqueue_script(', 'wp_enqueue_style(' );
$runtime_hits       = array_filter( $forbidden_runtime, static function ( $needle ) use ( $integration_source ) { return false !== strpos( $integration_source, $needle ); } );
check_catalog( array() === array_values( $runtime_hits ), 'The integration contains no download, activation, update or remote asset path' );

echo "--- Scheduled refresh lifecycle ---\n";
update_option( Remote_Catalog::SCHEDULE_OPTION, 43200, false );
$GLOBALS['bgcs_catalog_scheduled'] = true;
Remote_Catalog::maybe_schedule();
check_catalog( 1 === $GLOBALS['bgcs_catalog_unschedule_calls'], 'An old recurring interval is unscheduled once during upgrade' );
check_catalog( 1 === count( $GLOBALS['bgcs_catalog_schedule_calls'] ) && Remote_Catalog::INTERVAL === $GLOBALS['bgcs_catalog_schedule_calls'][0]['interval'], 'The replacement action uses the hourly interval' );
Remote_Catalog::maybe_schedule();
check_catalog( 1 === count( $GLOBALS['bgcs_catalog_schedule_calls'] ), 'The recurring catalog action is not registered twice' );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} remote catalog check(s)\n";
	exit( 1 );
}

echo "OK - remote catalog is validated, cached and presentation-only\n";

/** Minimal JSON helper kept separate so WordPress is not required. */
function wp_json_encode_for_catalog_test( $value ) {
	return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}
