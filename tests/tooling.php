<?php
/**
 * Tests for the release tooling itself.
 *
 * The gate found two false passes in its own logic during development: a linter that
 * could not run was reported as clean, and a credential scan never executed in the mode
 * the gate actually called. A gate that always passes is worse than no gate, so the
 * probes that caught those are encoded here.
 *
 * Each case copies the project to a temporary directory, breaks one thing, and asserts
 * that the gate refuses to pass. Nothing here touches the real project.
 *
 * Run: php tests/tooling.php
 *
 * @package AI_Chat_Bedrock
 */

// The gate runs every tests/*.php file. Without this guard, the inner gate run below
// would start this file again and recurse.
if ( getenv( 'AICFAB_GATE_SELFTEST' ) ) {
	echo "SKIP: tooling tests do not run inside the gate they exercise\n";
	exit( 0 );
}

$project = dirname( __DIR__ );
$php     = PHP_BINARY;
$failures = array();

/**
 * Record a failed expectation.
 *
 * @param bool   $condition Result of the check.
 * @param string $message   What was expected.
 */
function check_tooling( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

/**
 * Whether the commands the tooling needs are present.
 *
 * @return string Empty when everything is available, otherwise the missing command.
 */
function missing_command() {
	foreach ( array( 'bash', 'python3', 'cp' ) as $command ) {
		exec( 'command -v ' . escapeshellarg( $command ) . ' 2>/dev/null', $output, $status );
		if ( 0 !== $status ) {
			return $command;
		}
	}
	return '';
}

$missing = missing_command();
if ( '' !== $missing ) {
	printf( "SKIP: %s is required to exercise the release tooling\n", $missing );
	exit( 0 );
}

/**
 * Copy the project into a scratch directory, without the pinned tools or build output.
 *
 * @param string $project Project root.
 * @return string Scratch directory path.
 */
function make_sandbox( $project ) {
	$sandbox = sys_get_temp_dir() . '/aicfab-tooling-' . bin2hex( random_bytes( 6 ) );
	mkdir( $sandbox, 0700, true );

	$command = sprintf(
		'cd %s && tar -cf - --exclude=./.tools --exclude=./dist --exclude=./.git . | tar -xf - -C %s',
		escapeshellarg( $project ),
		escapeshellarg( $sandbox )
	);
	exec( $command . ' 2>/dev/null', $output, $status );

	return 0 === $status ? $sandbox : '';
}

/**
 * Run the gate in a sandbox and return its exit status plus output.
 *
 * PHPCS and Plugin Check are skipped: they need a PHP with extra extensions and a
 * WordPress install, and the cases here are about the gate's own decisions.
 *
 * @param string $sandbox Sandbox path.
 * @param string $php     PHP binary to hand the gate.
 * @return array {status, output}
 */
function run_gate( $sandbox, $php ) {
	$command = sprintf(
		'cd %s && AICFAB_GATE_SELFTEST=1 bash bin/prerelease.sh --php %s --skip-phpcs --skip-plugin-check 2>&1',
		escapeshellarg( $sandbox ),
		escapeshellarg( $php )
	);
	exec( $command, $output, $status );
	return array( 'status' => $status, 'output' => implode( "\n", $output ) );
}

/**
 * Whether the gate reported a failure with this text.
 *
 * The text has to appear on a FAIL line. Matching anywhere in the output would accept a
 * check that still prints its message while no longer failing, which is exactly the
 * mistake this file exists to prevent.
 *
 * @param string $output Gate output.
 * @param string $text   Expected failure text.
 * @return bool
 */
function gate_failed_with( $output, $text ) {
	foreach ( explode( "\n", $output ) as $line ) {
		if ( 1 === preg_match( '/^\s*FAIL\s+/', $line ) && false !== strpos( $line, $text ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Run the packaging script in a sandbox.
 *
 * @param string $sandbox Sandbox path.
 * @param string $args    Extra arguments.
 * @return array {status, output}
 */
function run_package( $sandbox, $args = '--list' ) {
	$command = sprintf( 'cd %s && python3 bin/build-package.py %s 2>&1', escapeshellarg( $sandbox ), $args );
	exec( $command, $output, $status );
	return array( 'status' => $status, 'output' => implode( "\n", $output ) );
}

function remove_sandbox( $sandbox ) {
	if ( '' !== $sandbox && 0 === strpos( $sandbox, sys_get_temp_dir() ) ) {
		exec( 'rm -rf ' . escapeshellarg( $sandbox ) );
	}
}

// --- A clean copy must pass -------------------------------------------------

$sandbox = make_sandbox( $project );
check_tooling( '' !== $sandbox, 'the sandbox copy succeeds' );
if ( '' === $sandbox ) {
	fwrite( STDERR, "FAILED\n- could not create a sandbox\n" );
	exit( 1 );
}

$baseline = run_gate( $sandbox, $php );
check_tooling( 0 === $baseline['status'], 'an untouched copy passes the gate' );
check_tooling( false !== strpos( $baseline['output'], 'Ready to package and publish' ), 'the gate says it is ready' );
check_tooling( false === strpos( $baseline['output'], 'FAIL' ), 'a clean run reports no failures' );

// A check that cannot run must say so rather than claiming success.
check_tooling( false !== strpos( $baseline['output'], 'skip  phpcs skipped by request' ), 'a skipped linter is reported as skipped' );
check_tooling( false !== strpos( $baseline['output'], 'skip  plugin check skipped by request' ), 'a skipped plugin check is reported as skipped' );

$package = run_package( $sandbox );
check_tooling( 0 === $package['status'], 'the packaging script resolves a file list' );
check_tooling( false === strpos( $package['output'], 'tests/' ), 'tests are not in the shipping list' );
check_tooling( false === strpos( $package['output'], 'bin/' ), 'tooling is not in the shipping list' );
check_tooling( false === strpos( $package['output'], 'phpcs.xml.dist' ), 'the ruleset is not in the shipping list' );
check_tooling( false !== strpos( $package['output'], 'readme.txt' ), 'the readme is in the shipping list' );

remove_sandbox( $sandbox );

// --- Each fault must be refused --------------------------------------------

/*
 * Each case pairs a fault with the specific message the responsible check must print.
 * Asserting only the exit status is not enough: several checks notice a version bump,
 * so a disabled check could hide behind a neighbour that happens to fail too.
 */
$cases = array(
	array(
		'label'  => 'a version mismatch between the header and the readme',
		'fail_line' => 'version mismatch between plugin header, runtime constant and readme',
		'break'  => function ( $sandbox ) {
			$readme = $sandbox . '/readme.txt';
			$body   = file_get_contents( $readme );
			file_put_contents( $readme, preg_replace( '/^Stable tag: [0-9.]+$/m', 'Stable tag: 99.9.9', $body, 1 ) );
		},
	),
	array(
		'label'  => 'a changelog with no entry for the version being released',
		'fail_line' => 'readme changelog has no entry for',
		'break'  => function ( $sandbox ) {
			$readme  = $sandbox . '/readme.txt';
			$body    = file_get_contents( $readme );
			$version = '';
			if ( preg_match( '/^Stable tag: ([0-9.]+)$/m', $body, $match ) ) {
				$version = $match[1];
			}
			file_put_contents( $readme, str_replace( "\n= " . $version . " =\n", "\n= 0.0.1 =\n", $body ) );
		},
	),
	array(
		'label'  => 'a readme description over the directory word budget',
		'fail_line' => 'readme limits exceeded',
		'output'    => 'description is over the directory limit',
		'break'  => function ( $sandbox ) {
			$readme = $sandbox . '/readme.txt';
			$body   = file_get_contents( $readme );
			$filler = "\n" . str_repeat( 'padding words to exceed the directory limit. ', 400 ) . "\n";
			file_put_contents( $readme, str_replace( '== Installation ==', $filler . '== Installation ==', $body ) );
		},
	),
	array(
		'label'  => 'a readme with the privacy section removed',
		'fail_line' => 'readme limits exceeded',
		'output'    => 'missing readme section',
		'break'  => function ( $sandbox ) {
			$readme = $sandbox . '/readme.txt';
			$body   = file_get_contents( $readme );
			$cut    = strpos( $body, '== Privacy Policy ==' );
			if ( false !== $cut ) {
				file_put_contents( $readme, substr( $body, 0, $cut ) );
			}
		},
	),
	array(
		'label'  => 'an upgrade notice longer than the directory allows',
		'fail_line' => 'readme limits exceeded',
		'output'    => 'over 300 chars: 0.0.2',
		'break'  => function ( $sandbox ) {
			$readme = $sandbox . '/readme.txt';
			$body   = file_get_contents( $readme );
			$long   = "= 0.0.2 =\n" . str_repeat( 'This notice is far too long for the directory. ', 12 ) . "\n\n";
			file_put_contents( $readme, str_replace( "== Upgrade Notice ==\n\n", "== Upgrade Notice ==\n\n" . $long, $body ) );
		},
	),
	array(
		'label'  => 'a failing test suite',
		'fail_line' => 'zz-probe.php',
		'break'  => function ( $sandbox ) {
			file_put_contents( $sandbox . '/tests/zz-probe.php', "<?php\nfwrite( STDERR, \"probe failure\\n\" );\nexit( 1 );\n" );
		},
	),
	array(
		'label'  => 'a PHP file that does not parse',
		'fail_line' => 'php -l reported errors',
		'break'  => function ( $sandbox ) {
			file_put_contents( $sandbox . '/includes/zz-broken.php', "<?php\nfunction broken( {\n" );
		},
	),
	array(
		'label'  => 'something that looks like an AWS key in shipped code',
		'fail_line' => 'the package would contain something it should not',
		'break'  => function ( $sandbox ) {
			$file = $sandbox . '/includes/class-ai-chat-bedrock-usage.php';
			file_put_contents( $file, file_get_contents( $file ) . "\n// AKIAIOSFODNN7EXAMPLE\n" );
		},
	),
);

foreach ( $cases as $case ) {
	$sandbox = make_sandbox( $project );
	if ( '' === $sandbox ) {
		check_tooling( false, 'sandbox for: ' . $case['label'] );
		continue;
	}

	$case['break']( $sandbox );
	$result = run_gate( $sandbox, $php );

	check_tooling( 0 !== $result['status'], 'the gate refuses to pass with ' . $case['label'] );
	check_tooling(
		gate_failed_with( $result['output'], $case['fail_line'] ),
		sprintf( 'the responsible check reports a failure for "%s", expected a FAIL line containing: %s', $case['label'], $case['fail_line'] )
	);
	if ( isset( $case['output'] ) ) {
		check_tooling(
			false !== strpos( $result['output'], $case['output'] ),
			sprintf( 'the detail for "%s" is explained, expected to see: %s', $case['label'], $case['output'] )
		);
	}

	remove_sandbox( $sandbox );
}

// A hidden file is caught by the packaging rules the gate delegates to.
$sandbox = make_sandbox( $project );
if ( '' !== $sandbox ) {
	file_put_contents( $sandbox . '/includes/.env', "SECRET=value\n" );
	$listed = run_package( $sandbox, '--list' );
	check_tooling( false === strpos( $listed['output'], '.env' ), 'a hidden file is never listed for shipping' );
	remove_sandbox( $sandbox );
}

// --- The packaging script must refuse a credential outright ----------------

$sandbox = make_sandbox( $project );
if ( '' !== $sandbox ) {
	$file = $sandbox . '/includes/class-ai-chat-bedrock-usage.php';
	file_put_contents( $file, file_get_contents( $file ) . "\n// AKIAIOSFODNN7EXAMPLE\n" );

	$listed = run_package( $sandbox, '--list' );
	check_tooling( 0 !== $listed['status'], 'listing the package fails when a credential is present' );
	check_tooling( false !== strpos( $listed['output'], 'AKIA' ), 'the failure names the pattern that matched' );

	$built = run_package( $sandbox, '' );
	check_tooling( 0 !== $built['status'], 'building the package fails when a credential is present' );
	remove_sandbox( $sandbox );
}

// --- .distignore is the only exclusion list -------------------------------

$sandbox = make_sandbox( $project );
if ( '' !== $sandbox ) {
	mkdir( $sandbox . '/scratchpad', 0700 );
	file_put_contents( $sandbox . '/scratchpad/notes.php', "<?php\n// developer scratch space\n" );

	$before = run_package( $sandbox, '--list' );
	check_tooling( false !== strpos( $before['output'], 'scratchpad/notes.php' ), 'an unlisted directory would ship, which is why .distignore matters' );

	file_put_contents( $sandbox . '/.distignore', file_get_contents( $sandbox . '/.distignore' ) . "/scratchpad/\n" );
	$after = run_package( $sandbox, '--list' );
	check_tooling( false === strpos( $after['output'], 'scratchpad/notes.php' ), 'adding it to .distignore is enough to exclude it everywhere' );

	remove_sandbox( $sandbox );
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: release tooling checks passed\n";
