<?php
/**
 * The command line contract, against the real class.
 *
 * Nothing loaded AI_Chat_Bedrock_CLI. Most of it prints things for a person, but one part has
 * consumers outside this project: `wp ai-chat-bedrock eval` is documented as exiting nonzero so a
 * run can gate a deployment, and `--json` is documented as something to redirect into a file. Both
 * of those are promises to somebody else's pipeline. An exit code that reports success while cases
 * failed turns a build green over a regression, which is the failure this command exists to catch,
 * and a JSON mode that prints anything besides JSON breaks whatever parses it.
 *
 * The evaluation itself is replaced: a real run costs one Bedrock request per case, and what is
 * under test here is how the command reports a report, not how a report is produced.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride

define( 'ABSPATH', __DIR__ );

$failures = array();
function check_cli( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function __( $text, $domain = null ) {
	return $text;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function get_option( $name, $default_value = false ) {
	return $default_value;
}
function apply_filters( $hook, $value ) {
	return $value;
}

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

/**
 * Ends the command the way WP-CLI does, carrying the exit code so it can be asserted.
 */
class Aicfab_Halt extends Exception {
	public $code;
	public $reason;
	public function __construct( $code, $reason = '' ) {
		parent::__construct( 'halt' );
		$this->code   = $code;
		$this->reason = $reason;
	}
}

/**
 * Records everything the command writes, and to which stream.
 */
class WP_CLI {
	public static function add_command( $name, $class ) {
		$GLOBALS['aicfab_commands'][] = $name;
	}
	public static function line( $message = '' ) {
		$GLOBALS['aicfab_stdout'][] = (string) $message;
	}
	public static function log( $message = '' ) {
		$GLOBALS['aicfab_stdout'][] = (string) $message;
	}
	public static function success( $message = '' ) {
		$GLOBALS['aicfab_stdout'][] = 'Success: ' . $message;
	}
	public static function warning( $message = '' ) {
		$GLOBALS['aicfab_stderr'][] = 'Warning: ' . $message;
	}
	public static function error( $message = '' ) {
		$GLOBALS['aicfab_stderr'][] = 'Error: ' . $message;
		// WP-CLI::error exits nonzero, which is why an error path is also a gate.
		throw new Aicfab_Halt( 1, (string) $message );
	}
	public static function halt( $code ) {
		throw new Aicfab_Halt( (int) $code, 'halt' );
	}
}

/**
 * A report of the shape AI_Chat_Bedrock_Eval::run() returns, with a chosen number of failures.
 */
function aicfab_report( $cases, $failing ) {
	$results = array();
	for ( $i = 0; $i < $cases; $i++ ) {
		$passed    = $i >= $failing;
		$results[] = array(
			'id'     => 'case-' . $i,
			'expect' => 'grounded',
			'passed' => $passed,
			'checks' => array(
				array( 'passed' => $passed, 'category' => 'grounding', 'detail' => 'answer cites a source' ),
				// A check that did not apply records null, never a pass.
				array( 'passed' => null, 'category' => 'tool_call', 'detail' => 'no tool expected' ),
			),
		);
	}
	return array(
		'schema'     => 'aicfab-eval-1',
		'cases'      => $cases,
		'passed'     => $cases - $failing,
		'categories' => array(
			'grounding' => array( 'checked' => $cases, 'passed' => $cases - $failing ),
			'tool_call' => array( 'checked' => 0, 'passed' => 0 ),
		),
		'unchecked'  => array( 'tool_call' ),
		'model'      => 'amazon.nova-lite-v1:0',
		'results'    => $results,
		'method'     => 'Programmatic checks against author-stated expectations. No judge model is used.',
	);
}

/**
 * Stands in for the evaluation. Nothing here calls a model.
 */
class AI_Chat_Bedrock_Eval {
	public static $report   = null;
	public static $runs     = array();
	public static $recorded = array();

	public static function run() {
		return self::$report;
	}
	public static function record_run( $report ) {
		self::$recorded[] = $report;
		return true;
	}
	public static function runs() {
		return self::$runs;
	}
	public static function compare( $before, $after ) {
		return array(
			'schema'     => 'aicfab-eval-comparison-1',
			'categories' => array(
				'grounding' => array(
					'before'     => array( 'checked' => 5, 'passed' => 5 ),
					'after'      => array( 'checked' => 5, 'passed' => 3 ),
					'change'     => -2,
					'comparable' => true,
				),
				'citation'  => array(
					'before'     => array( 'checked' => 2, 'passed' => 2 ),
					'after'      => array( 'checked' => 4, 'passed' => 4 ),
					'change'     => 2,
					'comparable' => false,
				),
			),
			'model'      => array( 'before' => 'a', 'after' => 'b' ),
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-cli.php';

/**
 * Run the subcommand and report what it wrote and how it ended.
 */
function aicfab_run_eval( $flags = array() ) {
	$GLOBALS['aicfab_stdout'] = array();
	$GLOBALS['aicfab_stderr'] = array();
	$cli                      = new AI_Chat_Bedrock_CLI();
	$exit                     = 0;
	$crash                    = '';
	try {
		$cli->eval_( array(), $flags );
	} catch ( Aicfab_Halt $halt ) {
		$exit = $halt->code;
	} catch ( Throwable $thrown ) {
		// A crash is not an exit code. Reported separately so a fault that makes the command
		// die cannot be mistaken for the command refusing on purpose.
		$crash = $thrown->getMessage();
		$exit  = 255;
	}
	return array(
		'exit'   => $exit,
		'crash'  => $crash,
		'stdout' => implode( "\n", $GLOBALS['aicfab_stdout'] ),
		'stderr' => implode( "\n", $GLOBALS['aicfab_stderr'] ),
		'lines'  => $GLOBALS['aicfab_stdout'],
	);
}

// --- The exit code is the promise ----------------------------------------------

AI_Chat_Bedrock_Eval::$report = aicfab_report( 5, 0 );
$aicfab_pass                  = aicfab_run_eval();
check_cli( 0 === $aicfab_pass['exit'], 'A run with every case passing exits zero, got ' . $aicfab_pass['exit'] );

AI_Chat_Bedrock_Eval::$report = aicfab_report( 5, 1 );
$aicfab_one_fail              = aicfab_run_eval();
check_cli( 1 === $aicfab_one_fail['exit'], 'One failing case out of five exits nonzero, got ' . $aicfab_one_fail['exit'] );

AI_Chat_Bedrock_Eval::$report = aicfab_report( 5, 5 );
check_cli( 1 === aicfab_run_eval()['exit'], 'Every case failing exits nonzero.' );

// An empty set must not read as success: nothing was verified.
AI_Chat_Bedrock_Eval::$report = aicfab_report( 0, 0 );
$aicfab_empty                 = aicfab_run_eval();
check_cli(
	0 === $aicfab_empty['exit'],
	'An empty set exits zero, since zero of zero cases failed, got ' . $aicfab_empty['exit']
);
check_cli(
	false !== strpos( $aicfab_empty['stdout'], 'not checked at all' ) || false !== strpos( $aicfab_empty['stdout'], 'No judge model' ),
	'An empty run still says what it did, rather than printing nothing.'
);

// A run that could not happen at all is an error, not a pass.
AI_Chat_Bedrock_Eval::$report = new WP_Error( 'aicfab_eval_failed', 'No cases are configured.' );
$aicfab_error                 = aicfab_run_eval();
check_cli( '' === $aicfab_error['crash'], 'A failed run is reported, not crashed on: ' . $aicfab_error['crash'] );
check_cli( 1 === $aicfab_error['exit'], 'A failed run exits nonzero rather than reporting success, got ' . $aicfab_error['exit'] );
check_cli( false !== strpos( $aicfab_error['stderr'], 'No cases are configured.' ), 'The reason goes to stderr.' );
check_cli( '' === $aicfab_error['stdout'], 'Nothing is written to stdout when the run could not happen.' );

// --- JSON mode emits JSON and nothing else -------------------------------------

/*
 * WP-CLI translates --json into --format json before a command sees it. This command declared a
 * bare --json flag and no format parameter, so the documented `eval --json > eval.json` was
 * rejected with "unknown --format parameter" and had never worked. Both spellings are asserted,
 * and so is the declaration WP-CLI needs in order to accept either.
 */
$aicfab_cli_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-cli.php' );
check_cli(
	false !== strpos( $aicfab_cli_source, '[--format=<format>]' ),
	'The eval command declares a format parameter, which is what WP-CLI maps --json onto.'
);
check_cli(
	(bool) preg_match( '/options:\s*\n\s*\*\s*-\s*table\s*\n\s*\*\s*-\s*json/', $aicfab_cli_source ),
	'Both table and json are declared as accepted formats.'
);
check_cli(
	(bool) preg_match( '/default:\s*table/', $aicfab_cli_source ),
	'The default format is the readable one, so a bare run is not machine output.'
);

AI_Chat_Bedrock_Eval::$report = aicfab_report( 3, 1 );
$aicfab_as_format             = aicfab_run_eval( array( 'format' => 'json' ) );
check_cli( is_array( json_decode( $aicfab_as_format['stdout'], true ) ), '--format=json emits JSON.' );
check_cli( 1 === count( $aicfab_as_format['lines'] ), 'And emits nothing else.' );
check_cli( 1 === $aicfab_as_format['exit'], 'And still fails the build.' );

$aicfab_table = aicfab_run_eval( array( 'format' => 'table' ) );
check_cli( null === json_decode( $aicfab_table['stdout'], true ), '--format=table is not JSON.' );
check_cli( false !== strpos( $aicfab_table['stdout'], 'FAIL' ), 'The table names failures for a person.' );

$aicfab_json = aicfab_run_eval( array( 'json' => true ) );

check_cli( 1 === $aicfab_json['exit'], 'JSON mode still fails the build when a case failed.' );
$aicfab_decoded = json_decode( $aicfab_json['stdout'], true );
check_cli( is_array( $aicfab_decoded ), 'Everything on stdout parses as one JSON document.' );
$aicfab_decoded = is_array( $aicfab_decoded ) ? $aicfab_decoded : array( 'schema' => '', 'cases' => 0, 'passed' => 0, 'results' => array() );
check_cli(
	1 === count( $aicfab_json['lines'] ),
	'JSON mode writes exactly one thing to stdout, got ' . count( $aicfab_json['lines'] ) . ' writes'
);
check_cli( '' === $aicfab_json['stderr'], 'JSON mode writes nothing to stderr on a normal run.' );

// The fields a pipeline would read must be there, named as documented.
foreach ( array( 'schema', 'cases', 'passed', 'categories', 'unchecked', 'results', 'method' ) as $aicfab_field ) {
	check_cli( isset( $aicfab_decoded[ $aicfab_field ] ), 'The JSON report carries ' . $aicfab_field . '.' );
}
check_cli( 'aicfab-eval-1' === $aicfab_decoded['schema'], 'The schema is named, so a consumer can detect a change.' );
check_cli(
	$aicfab_decoded['passed'] !== $aicfab_decoded['cases'],
	'The failing run reports fewer passes than cases, which is what the exit code agreed with.'
);
check_cli(
	( $aicfab_decoded['passed'] !== $aicfab_decoded['cases'] ) === ( 1 === $aicfab_json['exit'] ),
	'The exit code and the numbers in the report tell the same story.'
);

// A check that did not apply stays null in the JSON, rather than becoming a pass.
$aicfab_nulls = 0;
foreach ( $aicfab_decoded['results'] as $aicfab_result ) {
	foreach ( $aicfab_result['checks'] as $aicfab_check ) {
		if ( null === $aicfab_check['passed'] ) {
			++$aicfab_nulls;
		}
	}
}
check_cli( $aicfab_nulls > 0, 'An inapplicable check is null in the JSON, not a pass.' );

// --- The human output names what was not looked at -----------------------------

AI_Chat_Bedrock_Eval::$report = aicfab_report( 2, 1 );
$aicfab_human                 = aicfab_run_eval();
check_cli( false !== strpos( $aicfab_human['stdout'], 'FAIL' ), 'A failing case is named FAIL in the readable output.' );
check_cli( false !== strpos( $aicfab_human['stdout'], 'case-0' ), 'Each case is listed by id.' );
check_cli(
	false !== strpos( $aicfab_human['stdout'], 'not checked at all: tool_call' ),
	'A category nothing exercised is named, so a green run is not read as coverage.'
);
check_cli(
	false !== strpos( $aicfab_human['stdout'], 'No judge model' ),
	'The method is stated, so the numbers are not mistaken for a quality score.'
);
check_cli(
	false === strpos( $aicfab_human['stdout'], 'tool_call   0/0' ),
	'A category with no checks is not printed as a zero-out-of-zero score.'
);

// --- Recording keeps the failures too -----------------------------------------

AI_Chat_Bedrock_Eval::$recorded = array();
AI_Chat_Bedrock_Eval::$report   = aicfab_report( 4, 2 );
$aicfab_recorded                = aicfab_run_eval( array( 'record' => true ) );
check_cli( 1 === $aicfab_recorded['exit'], 'Recording does not swallow the failure.' );
check_cli( 1 === count( AI_Chat_Bedrock_Eval::$recorded ), 'The run is stored exactly once.' );
check_cli(
	isset( AI_Chat_Bedrock_Eval::$recorded[0]['passed'] ) && 2 === AI_Chat_Bedrock_Eval::$recorded[0]['passed'],
	'A failing run is stored as it was, not rounded up.'
);

AI_Chat_Bedrock_Eval::$recorded = array();
AI_Chat_Bedrock_Eval::$report   = aicfab_report( 4, 0 );
aicfab_run_eval();
check_cli( array() === AI_Chat_Bedrock_Eval::$recorded, 'Without --record nothing is stored.' );

// --- Comparing needs two runs, and reports rather than gates -------------------

AI_Chat_Bedrock_Eval::$runs = array();
$aicfab_no_runs             = aicfab_run_eval( array( 'compare' => true ) );
check_cli( 0 !== $aicfab_no_runs['exit'], 'Comparing with no history is an error.' );
check_cli( false !== strpos( $aicfab_no_runs['stderr'], '--record' ), 'The error says how to get history.' );

AI_Chat_Bedrock_Eval::$runs = array( array( 'categories' => array() ) );
check_cli( 0 !== aicfab_run_eval( array( 'compare' => true ) )['exit'], 'One run is still not enough to compare.' );

AI_Chat_Bedrock_Eval::$runs = array( array( 'categories' => array() ), array( 'categories' => array() ) );
$aicfab_compare             = aicfab_run_eval( array( 'compare' => true ) );

/*
 * --compare is documented as printing the difference, and the plain run is the one documented as
 * gating a build. So this exits zero even while reporting a regression. That is the contract, and
 * it is asserted here so nobody changes it by accident, and so the help text can be trusted by
 * anyone deciding which of the two to put in a pipeline.
 */
check_cli( 0 === $aicfab_compare['exit'], 'Comparing reports and exits zero, got ' . $aicfab_compare['exit'] );
check_cli(
	false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-cli.php' ), 'always exits zero' ),
	'The help text says so, so nobody wires --compare in as a gate.'
);
check_cli( false !== strpos( $aicfab_compare['stdout'], 'grounding' ), 'The comparison names each category.' );
check_cli( false !== strpos( $aicfab_compare['stdout'], '-2' ), 'A regression is shown with its size.' );
check_cli(
	false !== strpos( $aicfab_compare['stdout'], 'NOT comparable' ),
	'A category whose number of checks moved is marked not comparable rather than read as progress.'
);
check_cli( false !== strpos( $aicfab_compare['stdout'], 'comparable' ), 'A category that can be compared says so.' );

$aicfab_compare_json = aicfab_run_eval( array( 'compare' => true, 'json' => true ) );
check_cli( is_array( json_decode( $aicfab_compare_json['stdout'], true ) ), 'The comparison is machine readable too.' );
check_cli( 1 === count( $aicfab_compare_json['lines'] ), 'And writes one document, nothing else.' );
$aicfab_cmp = json_decode( $aicfab_compare_json['stdout'], true );
check_cli(
	is_array( $aicfab_cmp ) && isset( $aicfab_cmp['schema'] ) && 'aicfab-eval-comparison-1' === $aicfab_cmp['schema'],
	'The comparison names its own schema.'
);
check_cli(
	isset( $aicfab_cmp['categories']['citation']['comparable'] ) && false === $aicfab_cmp['categories']['citation']['comparable'],
	'The not-comparable flag survives into the JSON, where a script can act on it.'
);

// --- The command is registered under the name the docs use ---------------------

/*
 * Registration is gated on the WP_CLI constant rather than the class, so that loading the plugin
 * in a web request never tries to add a command. Both sides of that are checked: with the
 * constant absent nothing is registered, and with it present the documented name appears.
 */
$GLOBALS['aicfab_commands'] = array();
AI_Chat_Bedrock_CLI::register();
check_cli( array() === $GLOBALS['aicfab_commands'], 'Outside WP-CLI nothing is registered.' );

define( 'WP_CLI', true );
$GLOBALS['aicfab_commands'] = array();
AI_Chat_Bedrock_CLI::register();
check_cli( in_array( 'ai-chat-bedrock', $GLOBALS['aicfab_commands'], true ), 'Under WP-CLI the command is registered as ai-chat-bedrock.' );

/*
 * The subcommand is named by an annotation because eval is a reserved word in PHP, so the method
 * is eval_. If that annotation is lost the command silently becomes "eval-" and every documented
 * example stops working, which no other test would notice.
 */
$aicfab_cli_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-cli.php' );
check_cli(
	(bool) preg_match( '/@subcommand\s+eval\s*$/m', $aicfab_cli_src ),
	'The eval subcommand keeps its annotation exactly. A substring check passed here while the name had been changed to evaluate, because one contains the other.'
);
check_cli( false !== strpos( $aicfab_cli_src, 'function eval_' ), 'The method name the annotation applies to is unchanged.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: command line contract checks passed\n";
