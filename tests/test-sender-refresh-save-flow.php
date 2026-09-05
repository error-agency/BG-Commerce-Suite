<?php
/**
 * Sender refresh is a save-then-validate action across courier integrations.
 *
 * Run: php tests/test-sender-refresh-save-flow.php
 */

$source = file_get_contents( dirname( __DIR__ ) . '/app/Admin/Settings/Settings_Page.php' );
$boxnow_admin = file_get_contents( dirname( __DIR__ ) . '/assets/modules/boxnow/js/boxnow-admin.js' );
$failures = 0;

function sender_refresh_flow_check( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

echo "--- Sender refresh submits the settings form ---\n";
sender_refresh_flow_check(
	false !== strpos( $source, 'name="bgcs_task_action" value="refresh_sender"' ),
	'The sender button posts a refresh_sender task action'
);
sender_refresh_flow_check(
	false !== strpos( $source, 'form="bgcs-settings-form" name="bgcs_task_action" value="refresh_sender"' ),
	'The sender button submits the current settings fields'
);
sender_refresh_flow_check(
	false === strpos( $source, "'-sender-form'" ),
	'Built-in courier sender refresh no longer targets an empty auxiliary form'
);

echo "--- The save handler persists before validating ---\n";
$scope_pos   = strpos( $source, "'refresh_sender' === \$task_action" );
$save_pos    = strpos( $source, "Options::set( \$module->id(), \$key" );
$flush_pos   = strpos( $source, "Module_Settings::flush( \$module->id()", $save_pos );
$refresh_pos = strpos( $source, 'refresh_sender_data()', $flush_pos );

sender_refresh_flow_check(
	false !== $scope_pos && false !== strpos( substr( $source, $scope_pos, 180 ), "\$task_scope = 'account'" ),
	'Sender refresh explicitly saves the Account and Sender scope'
);
sender_refresh_flow_check(
	false !== $save_pos && false !== $flush_pos && false !== $refresh_pos && $save_pos < $flush_pos && $flush_pos < $refresh_pos,
	'Settings are saved and the settings cache is flushed before sender validation'
);
sender_refresh_flow_check(
	false !== strpos( substr( $source, $refresh_pos, 650 ), "'_last_sender_sync_at'" ),
	'A successful post-save sender validation records its timestamp'
);
sender_refresh_flow_check(
	false !== strpos( $boxnow_admin, "action === 'refresh_sender'" ),
	'BOX NOW sender refresh is not blocked by hidden pricing-row validation'
);

if ( $failures > 0 ) {
	echo "\n{$failures} sender refresh/save-flow check(s) failed\n";
	exit( 1 );
}

echo "\nAll sender refresh/save-flow checks passed\n";
