<?php
/**
 * TASK-M1 — BGCS-AUDIT-015: a document that makes claims about the code must say
 * which code it means.
 *
 * `docs/release-readiness.md` still declared „NOT READY“ with eight CRITICAL
 * findings, ~37 releases after it was written, while the classes it said were
 * missing — `Creation_Lock`, `Log_Redactor`, `Tracking_Status_Catalog`,
 * `Tracking_Status_Policy` — had existed for weeks. Nothing was wrong with the
 * document when it was written. What was missing was any way for a reader to
 * tell it had stopped being true, and §59 forbids treating a historical PASS as
 * evidence precisely because of that.
 *
 * Run: php tests/test-docs-versioning.php
 */

define( 'ABSPATH', __DIR__ );

$failures = 0;
function check_docs( $condition, $message ) {
	global $failures;
	echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

$root = dirname( __DIR__ );

/**
 * Every Markdown document under docs/, except the archive — history is allowed
 * to be history, and re-stamping it would be the rewriting this rule forbids.
 *
 * @param string $dir Directory.
 * @return string[] Repo-relative paths.
 */
function documents( $dir ) {
	$found = array();

	// The two documentation trees. Not the repository root: README and CHANGELOG
	// address a reader rather than a version, and stamping them would say nothing.
	foreach ( array( 'docs', 'audit' ) as $tree ) {
		if ( ! is_dir( $dir . '/' . $tree ) ) {
			continue;
		}
		$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir . '/' . $tree ) );

		foreach ( $rii as $file ) {
			if ( ! $file->isFile() || 'md' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$path = str_replace( array( $dir . '/', $dir . '\\', '\\' ), array( '', '', '/' ), $file->getPathname() );

			// The archive is allowed to be history. Re-stamping it would be the
			// rewriting this whole rule exists to forbid.
			if ( 0 === strpos( $path, 'docs/archive/' ) ) {
				continue;
			}
			$found[] = $path;
		}
	}

	sort( $found );
	return $found;
}

$docs = documents( $root );
check_docs( count( $docs ) > 10, 'Documents were found to check: ' . count( $docs ) );

echo "--- Acceptance criterion 1: every document says which version it applies to ---\n";
{
	$missing = array();
	foreach ( $docs as $doc ) {
		$head = implode( "\n", array_slice( file( $root . '/' . $doc ), 0, 12 ) );
		if ( false === strpos( $head, 'Applies to version:' ) ) {
			$missing[] = $doc;
		}
	}

	check_docs(
		array() === $missing,
		'No document leaves the reader guessing which code it describes'
			. ( $missing ? ":\n      " . implode( "\n      ", $missing ) : '' )
	);
}

echo "--- Acceptance criterion 2: the stale ones say so, and say what replaced them ---\n";
{
	// These were found by the audit to contradict the code. Each must carry the
	// marking AND point somewhere current — a bare „superseded“ leaves the reader
	// with nothing to read instead.
	$stale = array(
		'docs/release-readiness.md',
		'docs/production-audit.md',
		'docs/courier-contract-audit.md',
		'docs/shipment-idempotency.md',
		'docs/project/TEST-STATUS.md',
		'docs/project/RELEASE-READINESS.md',
		'docs/project/KNOWN-ISSUES.md',
	);

	foreach ( $stale as $doc ) {
		$path = $root . '/' . $doc;
		check_docs( is_readable( $path ), basename( $doc ) . ' still exists — history is not deleted' );
		if ( ! is_readable( $path ) ) {
			continue;
		}

		$head = implode( "\n", array_slice( file( $path ), 0, 12 ) );
		check_docs( false !== strpos( $head, 'SUPERSEDED' ), basename( $doc ) . ' is marked superseded' );
		check_docs(
			false !== strpos( $head, 'audit/BGCS-AUDIT-FINDINGS.md' ),
			'…and points at what replaced it'
		);
	}
}

echo "--- The old claims themselves are untouched ---\n";
{
	// The finding is explicit: do not rewrite an old document's findings. The
	// record of what was wrong, and when, is the audit trail.
	$readiness = file_get_contents( $root . '/docs/release-readiness.md' );
	check_docs( false !== strpos( $readiness, 'NOT READY' ), 'release-readiness.md keeps its original verdict' );
	check_docs( false !== strpos( $readiness, '2026-08-11' ), '…and its original date' );

	$idempotency = file_get_contents( $root . '/docs/shipment-idempotency.md' );
	check_docs(
		false !== strpos( $idempotency, 'No layer of the stack implements duplicate-create protection' ),
		'shipment-idempotency.md keeps the claim that is now false, because it was true then'
	);
	check_docs(
		false !== strpos( $idempotency, 'shipment-creation-lifecycle.md' ),
		'...and now points to the current persisted provider-boundary contract'
	);
}

echo "--- The specific contradiction the audit named ---\n";
{
	// The document said these did not exist. They do, which is what made the
	// stale verdict dangerous rather than merely old.
	foreach ( array(
		'app/Shipping/Creation_Lock.php',
		'app/Support/Log_Redactor.php',
		'app/Shipping/Tracking_Status_Catalog.php',
		'app/Shipping/Tracking_Status_Policy.php',
	) as $class ) {
		check_docs( is_readable( $root . '/' . $class ), basename( $class ) . ' exists, contradicting the superseded verdict' );
	}
}

echo "--- Acceptance criterion 3: the partial contract audit says where it was finished ---\n";
{
	$contract = file_get_contents( $root . '/docs/courier-contract-audit.md' );
	check_docs( false !== strpos( $contract, 'not finished' ), 'It still declares itself incomplete' );
	check_docs(
		false !== strpos( $contract, 'BGCS-COURIER-CONTRACT-MATRIX.md' ),
		'…and names where the BOX NOW part was completed'
	);
}

echo "--- The rule is written down where the next agent will look ---\n";
{
	$instructions = file_get_contents( $root . '/docs/BGCS-ADDON-AGENT-INSTRUCTIONS.md' );
	check_docs( false !== strpos( $instructions, 'Applies to version:' ), 'The add-on instructions state the header rule' );
	check_docs( false !== strpos( $instructions, 'Superseding never means rewriting' ), '…and that superseding is not rewriting' );
	check_docs( false !== strpos( $instructions, 'test-docs-versioning.php' ), '…and name the test that enforces it' );
}

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "FAILED: {$failures} check(s)" . PHP_EOL;
	exit( 1 );
}
echo 'OK — all documentation versioning checks passed' . PHP_EOL;
