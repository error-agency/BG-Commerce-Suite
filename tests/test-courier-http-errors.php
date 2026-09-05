<?php
/**
 * TASK-I1 — BGCS-AUDIT-018: a provider's internal failure is not the merchant's
 * problem to read.
 *
 * On any non-2xx, BGCS appended the text extracted from the response to the
 * merchant-facing message. For a 4xx that is the most useful thing it can show:
 * the courier is describing what is wrong with the request. For a 5xx it is the
 * provider's infrastructure talking to its own engineers. Observed live against
 * the Econt demo:
 *
 *   „Грешка от API на куриера (HTTP 500). MISCONF Redis is configured to save
 *    RDB snapshots, but it is currently not able to persist on disk…“
 *
 * A Bulgarian shop owner cannot act on that, and it invites them to hunt for a
 * fault on their own side while the courier is simply down.
 *
 * Run: php tests/test-courier-http-errors.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['bgcs_http'] = null;

function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['bgcs_http_seen_url'] = $url;
	return $GLOBALS['bgcs_http'];
}
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags | JSON_UNESCAPED_UNICODE );
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}
function wp_parse_url( $url ) {
	return parse_url( (string) $url );
}
function __( $text, $domain = null ) {
	return $text;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function apply_filters( $hook, $value = null ) {
	return $value;
}
function is_admin() {
	return false;
}
function get_option( $name, $default = false ) {
	return isset( $GLOBALS['bgcs_options'][ $name ] ) ? $GLOBALS['bgcs_options'][ $name ] : $default;
}

class WP_Error {

	/** @var string */
	private $code;

	/** @var string */
	private $message;

	/** @var mixed */
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

// `app/functions.php` carries the bgcs3_* string helpers Log_Redactor uses.
require_once dirname( __DIR__ ) . '/app/functions.php';
require_once dirname( __DIR__ ) . '/app/Support/Log_Redactor.php';
require_once dirname( __DIR__ ) . '/app/Shipping/Courier_Error.php';
require_once dirname( __DIR__ ) . '/app/Modules/Shipping/Abstract_Client.php';

use BgCommerce3\Modules\Shipping\Abstract_Client;

$failures = 0;
function check_http( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

/** A minimal concrete client, so the real shared HTTP path is what runs. */
class Test_Client extends Abstract_Client {

	protected function base_url() {
		return 'https://api.example.test/v1/';
	}

	public function call_it() {
		return $this->request_json_url( 'POST', 'https://api.example.test/v1/thing', array( 'body' => '{}' ) );
	}
}

/**
 * @param int    $code HTTP status.
 * @param string $body Response body.
 */
function respond( $code, $body ) {
	$GLOBALS['bgcs_http'] = array( 'response' => array( 'code' => $code ), 'body' => $body );
}

$client = new Test_Client();

// The exact text the audit observed from the Econt demo environment.
const REDIS = 'MISCONF Redis is configured to save RDB snapshots, but it is currently not able to '
	. 'persist on disk. Commands that may modify the data set are disabled, because this instance is '
	. 'configured to report errors during writes if RDB snapshotting fails (stop-writes-on-bgsave-error '
	. 'option). Please check the Redis logs for details about the RDB error.';

echo "--- Acceptance criterion 1: a 5xx says something the merchant can act on ---\n";
{
	respond( 500, wp_json_encode( array( 'message' => REDIS ) ) );
	$error = $client->call_it();

	check_http( $error instanceof WP_Error, 'A 500 is an error' );

	$message = $error->get_error_message();
	check_http( false === strpos( $message, 'MISCONF' ), 'The provider\'s internal text is not shown to the merchant' );
	check_http( false === strpos( $message, 'Redis' ), '…nor is the name of their infrastructure' );
	check_http( false !== strpos( $message, 'temporarily unavailable' ), 'The message says what actually happened' );
	check_http( false !== strpos( $message, 'Try again' ), '…and what to do about it' );
	check_http( false !== strpos( $message, '500' ), '…while still carrying the status code for support' );
	check_http(
		false !== strpos( $message, 'nothing was sent or changed' ),
		'…and reassures the merchant, because a failed create is the frightening case'
	);
}

echo "--- Acceptance criterion 2: the detail is kept, not destroyed ---\n";
{
	respond( 500, wp_json_encode( array( 'message' => REDIS ) ) );
	$data = $client->call_it()->get_error_data();

	check_http( is_array( $data ) && 500 === $data['status'], 'The status is recorded' );
	check_http( isset( $data['detail'] ) && false !== strpos( $data['detail'], 'MISCONF' ), 'The full provider text is kept in the error data for diagnostics' );
	check_http( isset( $data['raw'] ), 'The raw response is kept too' );
	check_http( isset( $data['body']['message'] ), 'and so is the decoded body' );
}

echo "--- Acceptance criterion 3: 4xx messages are unchanged ---\n";
{
	// A 4xx is the courier describing the request; that text is the whole value.
	respond( 400, wp_json_encode( array( 'message' => 'Invalid postal code for the selected office' ) ) );
	$message = $client->call_it()->get_error_message();

	check_http( false !== strpos( $message, 'Invalid postal code' ), 'A validation message still reaches the merchant' );
	check_http( false !== strpos( $message, '400' ), '…with the status code, as before' );

	respond( 422, wp_json_encode( array( 'errors' => array( array( 'message' => 'weight exceeds 20 kg' ) ) ) ) );
	check_http( false !== strpos( $client->call_it()->get_error_message(), 'weight exceeds 20 kg' ), 'and so does a 422 detail' );

	respond( 404, wp_json_encode( array( 'message' => 'Shipment not found' ) ) );
	check_http( false !== strpos( $client->call_it()->get_error_message(), 'Shipment not found' ), 'and a 404 detail' );
}

echo "--- The whole 5xx range, not just 500 ---\n";
{
	foreach ( array( 500, 502, 503, 504 ) as $code ) {
		respond( $code, 'upstream connect error or disconnect/reset before headers' );
		$message = $client->call_it()->get_error_message();
		check_http(
			false === strpos( $message, 'upstream connect error' ) && false !== strpos( $message, 'temporarily unavailable' ),
			"HTTP {$code} is neutral"
		);
	}

	// Classified by STATUS, never by keywords or length — a short 5xx body is
	// still a 5xx, and a long 4xx body is still the merchant's business.
	respond( 503, 'down' );
	check_http( false === strpos( $client->call_it()->get_error_message(), 'down' ), 'A short 5xx body is hidden all the same' );

	respond( 400, str_repeat( 'The office code you selected is not served on Saturdays. ', 8 ) );
	check_http( false !== strpos( $client->call_it()->get_error_message(), 'not served on Saturdays' ), 'A long 4xx body is still shown' );
}

echo "--- A 5xx stays retryable, a 4xx does not ---\n";
{
	// The classification the audit relies on must survive the message change.
	respond( 500, '{}' );
	$server = $client->call_it();
	respond( 400, '{}' );
	$client_error = $client->call_it();

	check_http( 500 === $server->get_error_data()['status'], 'A 5xx still carries its status for the retry decision' );
	check_http( 400 === $client_error->get_error_data()['status'], 'and so does a 4xx' );
	check_http( $server->get_error_code() !== $client_error->get_error_code(), 'The two are classified differently, as before' );
}

echo "--- Non-JSON bodies do not break the path ---\n";
{
	respond( 500, '<html><body><h1>502 Bad Gateway</h1></body></html>' );
	$error = $client->call_it();
	check_http( $error instanceof WP_Error, 'An HTML error page is still an error' );
	check_http( false === strpos( $error->get_error_message(), '<html>' ), '…and no markup reaches the merchant' );

	respond( 500, '' );
	check_http( $client->call_it() instanceof WP_Error, 'An empty body is still an error' );
}

echo "--- The real Speedy endpoints, as URLs (TASK-S1) ---\n";
{
	// A string match on the endpoint argument cannot tell a real path from a
	// plausible-looking one: `client/contractInfo` satisfied such a check for a
	// whole development cycle while Speedy answered it with a 404 HTML page.
	// So record the URL the real client actually requests, and pin it.
	$GLOBALS['bgcs_options'] = array(
		'bgcs3_speedy' => array( 'username' => 'u', 'password' => 'p', 'language' => 'BG' ),
	);

	require_once dirname( __DIR__ ) . '/app/Support/Options.php';
	require_once dirname( __DIR__ ) . '/app/Support/Module_Settings.php';

	// The Client needs the module's id constant, not the module itself.
	require_once __DIR__ . '/lib/speedy-id-stub.php';
	require_once dirname( __DIR__ ) . '/app/Modules/Shipping/Speedy/Client.php';

	$speedy_client = new BgCommerce3\Modules\Shipping\Speedy\Client();
	check_http( $speedy_client->has_credentials(), 'the probe client is configured' );

	$requested = function ( $call ) use ( $speedy_client ) {
		$GLOBALS['bgcs_http_seen_url'] = null;
		respond( 200, '{}' );
		$call( $speedy_client );
		return (string) $GLOBALS['bgcs_http_seen_url'];
	};

	$url = $requested( function ( $c ) { $c->get_contract_info(); } );
	check_http(
		'https://api.speedy.bg/v1/client/contract/info' === $url,
		'get_contract_info() requests client/contract/info, got: ' . $url
	);

	$url = $requested( function ( $c ) { $c->get_contract_clients(); } );
	check_http(
		'https://api.speedy.bg/v1/client/contract' === $url,
		'get_contract_clients() requests client/contract, got: ' . $url
	);
}

echo "--- Static guard ---\n";
{
	$code = php_strip_whitespace( dirname( __DIR__ ) . '/app/Modules/Shipping/Abstract_Client.php' );
	check_http( false !== strpos( $code, '$code >= 500' ), 'The split is made on the status code' );
	check_http(
		false === strpos( $code, 'stripos( $detail' ) && false === strpos( $code, 'strlen( $detail' ),
		'…and never on keywords or length, which the finding rules out'
	);
}

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all courier HTTP error checks passed' . PHP_EOL;
