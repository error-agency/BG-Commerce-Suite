<?php
/**
 * BGCS-AUDIT-009 — no courier client may put raw provider content in the log.
 *
 * `Abstract_Client` already redacts non-2xx response bodies, but Speedy reports
 * logical errors inside an HTTP 200, so its error branch never reached that
 * code — and it logged the error object verbatim. Speedy validation errors
 * routinely quote the value they rejected, so a recipient's phone, e-mail, name
 * or address went straight into `debug.log`.
 *
 * Run: php tests/test-log-redaction.php
 */

define( 'ABSPATH', __DIR__ );
define( 'WP_DEBUG', true );

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

require_once dirname( __DIR__ ) . '/app/functions.php';
require_once dirname( __DIR__ ) . '/app/Support/Log_Redactor.php';

use BgCommerce3\Support\Log_Redactor;

$failures = 0;
function check_log( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

/**
 * A Speedy logical error in the shape the client actually receives: HTTP 200
 * with an `error` object whose message quotes the rejected value.
 */
$speedy_error = array(
	'id'      => 'a2f0c1e8',
	'code'    => 'ESRV',
	'message' => 'Invalid phone number +359888123456 for recipient Иван Петров (ivan.petrov@example.com), address ул. Витоша 15, София',
	'context' => 'recipient.phone1.number',
	'recipient' => array(
		'clientName'   => 'Иван Петров',
		'email'        => 'ivan.petrov@example.com',
		'phone1'       => array( 'number' => '+359888123456' ),
		'addressNote'  => 'ул. Витоша 15',
	),
);

echo "--- The redactor removes PII from a representative Speedy error ---\n";
$redacted = Log_Redactor::response_excerpt( wp_json_encode( $speedy_error ), 2000 );

check_log( false === strpos( $redacted, '+359888123456' ), 'The rejected phone number is gone' );
check_log( false === strpos( $redacted, 'ivan.petrov@example.com' ), 'The e-mail address is gone' );
check_log( '[redacted]' === ( json_decode( $redacted, true )['recipient'] ?? null ), 'The whole recipient structure — name, e-mail, phone, address — is redacted' );
check_log( false !== strpos( $redacted, 'ESRV' ), 'The diagnostic error code survives — the log stays useful' );
check_log( false !== strpos( $redacted, 'recipient.phone1.number' ), 'The field path survives, so the merchant still learns what was wrong' );

echo "--- Free-form messages are redacted even without a JSON structure ---\n";
$plain = Log_Redactor::response_excerpt( 'Speedy rejected +359 888 123 456 / ivan.petrov@example.com', 2000 );
check_log( false === strpos( $plain, '359 888 123 456' ), 'A spaced phone number in plain text is redacted' );
check_log( false === strpos( $plain, 'ivan.petrov@example.com' ), 'An e-mail in plain text is redacted' );

echo "--- Credentials never reach the log ---\n";
$creds = Log_Redactor::response_excerpt( wp_json_encode( array( 'userName' => 'bgcs', 'password' => 's3cr3t', 'token' => 'abcdef123456' ) ), 2000 );
check_log( false === strpos( $creds, 's3cr3t' ), 'A password value is redacted' );
check_log( false === strpos( $creds, 'abcdef123456' ), 'A token value is redacted' );

echo "--- The excerpt stays bounded ---\n";
$long = Log_Redactor::response_excerpt( str_repeat( 'x', 5000 ), 500 );
check_log( 500 >= strlen( $long ), 'A long provider body is truncated to the requested length' );

echo "--- Acceptance criterion 3: no courier client calls error_log() directly ---\n";
// Redaction must be structurally impossible to bypass, not a habit each new
// client is expected to remember.
$offenders = array();
$dir       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/app/Modules' ) );
foreach ( $dir as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$path = str_replace( '\\', '/', $file->getPathname() );
	// Abstract_Client is where the redacting helpers live — it is the one place
	// allowed to touch error_log().
	if ( false !== strpos( $path, '/Modules/Shipping/Abstract_Client.php' ) ) {
		continue;
	}
	if ( false !== strpos( php_strip_whitespace( $file->getPathname() ), 'error_log(' ) ) {
		$offenders[] = basename( dirname( $path ) ) . '/' . basename( $path );
	}
}
check_log( array() === $offenders, 'No direct error_log() outside Abstract_Client: ' . ( $offenders ? implode( ', ', $offenders ) : 'none' ) );

echo "--- The shared helpers exist and always redact ---\n";
$abstract = php_strip_whitespace( dirname( __DIR__ ) . '/app/Modules/Shipping/Abstract_Client.php' );
foreach ( array( 'log_provider_error', 'log_provider_debug' ) as $helper ) {
	check_log( false !== strpos( $abstract, 'function ' . $helper ), "Abstract_Client::{$helper}() exists" );
}
// Every error_log() in Abstract_Client must have Log_Redactor applied to its content.
preg_match_all( '/error_log\(.*?\n?.*?\);/s', $abstract, $calls );
$unredacted = 0;
foreach ( preg_split( '/error_log\(/', $abstract ) as $index => $chunk ) {
	if ( 0 === $index ) {
		continue;
	}
	$call = substr( $chunk, 0, strpos( $chunk, ');' ) !== false ? strpos( $chunk, ');' ) : strlen( $chunk ) );
	if ( false === strpos( $call, 'Log_Redactor::' ) ) {
		$unredacted++;
	}
}
check_log( 0 === $unredacted, 'Every error_log() call in Abstract_Client passes its content through Log_Redactor' );

$speedy = php_strip_whitespace( dirname( __DIR__ ) . '/app/Modules/Shipping/Speedy/Client.php' );
check_log( false !== strpos( $speedy, 'log_provider_error(' ), 'The Speedy logical-error branch uses the shared helper' );
check_log( false === strpos( $speedy, "wp_json_encode( \$err )" ), 'The Speedy client no longer encodes the raw error object for the log' );

echo PHP_EOL;
echo 'KNOWN RESIDUAL — Log_Redactor removes PII from a free-form message only where it can' . PHP_EOL;
echo '  pattern-match it: e-mail addresses, phone numbers, bearer tokens and key=value secrets.' . PHP_EOL;
echo '  A personal NAME or STREET quoted inside a provider message survives, because nothing' . PHP_EOL;
echo '  distinguishes it from ordinary prose. Structured fields are safe — any key matching' . PHP_EOL;
echo '  is_sensitive_key() is replaced wholesale. This is outside BGCS-AUDIT-009, whose' . PHP_EOL;
echo '  acceptance criterion is phone + e-mail; recorded so it is not mistaken for coverage.' . PHP_EOL;
echo PHP_EOL;

if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all log redaction checks passed' . PHP_EOL;
