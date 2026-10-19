<?php
/**
 * wp ai-chat-bedrock usage reports failed chat requests by cause.
 *
 * Run: php tests/test_feature_2d89f9484181.php
 *
 * Uses the real AI_Chat_Bedrock_Usage and AI_Chat_Bedrock_CLI classes over synthetic, seeded
 * counters. Nothing here calls Amazon Bedrock.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, Squiz.PHP.Eval, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Universal.Files.SeparateFunctionsFromOO

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_stdout']  = array();
$GLOBALS['aicfab_stderr']  = array();

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default_value;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['aicfab_options'][ $name ] );
	return true;
}
function absint( $value ) {
	return abs( (int) $value );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function __( $text, $domain = null ) {
	return $text;
}

/**
 * Ends the command the way WP-CLI does, carrying the exit status.
 */
class Aicfab_Feature_Halt extends Exception {
	public $status;
	public function __construct( $status ) {
		parent::__construct( 'halt' );
		$this->status = (int) $status;
	}
}

class WP_CLI {
	public static function add_command( $name, $class ) {
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
		throw new Aicfab_Feature_Halt( 1 );
	}
	public static function halt( $code ) {
		throw new Aicfab_Feature_Halt( $code );
	}
}

// WP-CLI's table printer lives in a namespace; each row is recorded as one ROW line.
if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
	eval(
		<<<'AICFAB'
namespace WP_CLI\Utils;
function format_items( $format, $items, $fields ) {
	foreach ( $items as $item ) {
		$GLOBALS['aicfab_stdout'][] = 'ROW ' . implode( ' ', array_map( 'strval', array_values( (array) $item ) ) );
	}
}
AICFAB
	);
}

require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-usage.php';
require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-cli.php';

$failures = array();
function check_feature( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function aicfab_feature_run( $assoc_args ) {
	$GLOBALS['aicfab_stdout'] = array();
	$GLOBALS['aicfab_stderr'] = array();
	$exit                     = 0;
	$crash                    = '';
	try {
		$cli = new AI_Chat_Bedrock_CLI();
		$cli->usage( array(), $assoc_args );
	} catch ( Aicfab_Feature_Halt $halt ) {
		$exit = $halt->status;
	} catch ( Throwable $thrown ) {
		$crash = $thrown->getMessage();
		$exit  = 255;
	}
	return array(
		'exit'   => $exit,
		'crash'  => $crash,
		'lines'  => $GLOBALS['aicfab_stdout'],
		'stderr' => implode( "\n", $GLOBALS['aicfab_stderr'] ),
	);
}

function aicfab_feature_index( $lines, $prefix ) {
	foreach ( $lines as $index => $line ) {
		if ( 0 === strpos( $line, $prefix ) ) {
			return $index;
		}
	}
	return -1;
}

function aicfab_feature_line( $lines, $prefix ) {
	$index = aicfab_feature_index( $lines, $prefix );
	return $index < 0 ? '' : $lines[ $index ];
}

function aicfab_feature_day( $offset, $failures ) {
	return array(
		gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) ) => array(
			'requests'      => 0,
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'models'        => array(),
			'failures'      => $failures,
		),
	);
}

function aicfab_feature_seed( $entries ) {
	$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Usage::OPTION ] = $entries;
}

// Stubs some code paths may reach; harmless when unused.
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function is_wp_error( $thing ) {
	return false;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

// --- Failures recorded through the real recording path ---------------------

AI_Chat_Bedrock_Usage::reset();
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 10, 'output_tokens' => 2 ), 'amazon.nova-lite-v1:0' );
AI_Chat_Bedrock_Usage::record_failure( 'throttled' );
AI_Chat_Bedrock_Usage::record_failure( 'access_denied' );
AI_Chat_Bedrock_Usage::record_failure( 'throttled' );

$aicfab_run   = aicfab_feature_run( array() );
$aicfab_lines = $aicfab_run['lines'];
$aicfab_line  = aicfab_feature_line( $aicfab_lines, 'Failed requests' );
$aicfab_count = count( $aicfab_lines );
$aicfab_fail  = aicfab_feature_index( $aicfab_lines, 'Failed requests' );
$aicfab_total = aicfab_feature_index( $aicfab_lines, 'Total: ' );
$aicfab_row   = aicfab_feature_index( $aicfab_lines, 'ROW ' );

check_feature( '' === $aicfab_run['crash'], 'usage must not crash: ' . $aicfab_run['crash'] );
check_feature( 0 === $aicfab_run['exit'], 'usage with recorded failures exits zero, got ' . $aicfab_run['exit'] );
check_feature( '' === $aicfab_run['stderr'], 'usage writes nothing to stderr, got: ' . $aicfab_run['stderr'] );
check_feature(
	'Failed requests (7 days): 3 after any fallback model: throttled 2, access denied 1.' === $aicfab_line,
	'Recorded failures are counted by cause in category order with zero categories left out, got: ' . $aicfab_line
);
check_feature(
	$aicfab_count >= 2 && 0 === strpos( $aicfab_lines[ $aicfab_count - 1 ], 'Time to first text (streamed, 7 days): ' ),
	'The Time to first text line stays the last line.'
);
check_feature( $aicfab_fail >= 0 && $aicfab_fail === $aicfab_count - 2, 'The failure line comes directly before the latency line.' );
check_feature( $aicfab_row >= 0 && $aicfab_row < $aicfab_total, 'The day table still comes first.' );
check_feature( $aicfab_total >= 0 && $aicfab_total < $aicfab_fail, 'The Total line still precedes the failure line.' );
check_feature(
	'Total: 1 requests, 10 input tokens, 2 output tokens.' === aicfab_feature_line( $aicfab_lines, 'Total: ' ),
	'The Total line is unchanged, got: ' . aicfab_feature_line( $aicfab_lines, 'Total: ' )
);
check_feature(
	false === strpos( $aicfab_line, 'nova' ) && false === strpos( $aicfab_line, 'amazon' ),
	'The failure line holds no model ID.'
);

// --- Nothing recorded -------------------------------------------------------

AI_Chat_Bedrock_Usage::reset();
$aicfab_run = aicfab_feature_run( array() );
check_feature( 0 === $aicfab_run['exit'] && '' === $aicfab_run['crash'], 'An empty window is not an error.' );
check_feature( '' === $aicfab_run['stderr'], 'An empty window writes nothing to stderr.' );
check_feature( aicfab_feature_index( $aicfab_run['lines'], 'Failed requests' ) < 0, 'An empty window prints no Failed requests line.' );
check_feature(
	count( $aicfab_run['lines'] ) - 1 === aicfab_feature_index( $aicfab_run['lines'], 'Time to first text (streamed, 7 days): ' ),
	'An empty window still ends with the latency line.'
);

AI_Chat_Bedrock_Usage::record_failure( 'other' );
$aicfab_run = aicfab_feature_run( array( 'days' => '1' ) );
check_feature(
	'Failed requests (1 day): 1 after any fallback model: other 1.' === aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' ),
	'A one-day window is labelled 1 day, got: ' . aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' )
);

// --- The window -------------------------------------------------------------

aicfab_feature_seed( aicfab_feature_day( 0, array( 'network' => 1 ) ) + aicfab_feature_day( 10, array( 'throttled' => 5 ) ) );

$aicfab_run = aicfab_feature_run( array() );
check_feature(
	'Failed requests (7 days): 1 after any fallback model: network 1.' === aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' ),
	'Failures older than the window are excluded, got: ' . aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' )
);

$aicfab_run = aicfab_feature_run( array( 'days' => '30' ) );
check_feature(
	'Failed requests (30 days): 6 after any fallback model: throttled 5, network 1.' === aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' ),
	'A wider window includes older failures, got: ' . aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' )
);

$aicfab_run = aicfab_feature_run( array( 'days' => '60' ) );
check_feature(
	'Failed requests (30 days): 6 after any fallback model: throttled 5, network 1.' === aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' ),
	'--days=60 is labelled and counted as 30 days, got: ' . aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' )
);
check_feature(
	0 === strpos( aicfab_feature_line( $aicfab_run['lines'], 'Time to first text' ), 'Time to first text (streamed, 30 days): ' ),
	'The latency line keeps its own capped label.'
);

// --- Invalid stored counters ------------------------------------------------

aicfab_feature_seed(
	aicfab_feature_day(
		0,
		array(
			'throttled'     => 'abc',
			'access_denied' => -3,
			'bogus'         => 9,
			'network'       => 4,
		)
	)
);
$aicfab_run = aicfab_feature_run( array() );
check_feature(
	'Failed requests (7 days): 4 after any fallback model: network 4.' === aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' ),
	'Non-numeric, negative and unknown counters are ignored, got: ' . aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' )
);

aicfab_feature_seed(
	aicfab_feature_day(
		0,
		array(
			'throttled' => 'abc',
			'other'     => -1,
			'bogus'     => 9,
		)
	)
);
$aicfab_run = aicfab_feature_run( array() );
check_feature( 0 === $aicfab_run['exit'] && '' === $aicfab_run['crash'], 'All-invalid counters are not an error.' );
check_feature(
	aicfab_feature_index( $aicfab_run['lines'], 'Failed requests' ) < 0,
	'All-invalid counters print no Failed requests line, got: ' . aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' )
);

aicfab_feature_seed( aicfab_feature_day( 0, 'garbage' ) );
$aicfab_run = aicfab_feature_run( array() );
check_feature(
	aicfab_feature_index( $aicfab_run['lines'], 'Failed requests' ) < 0,
	'A non-array failures entry prints no Failed requests line, got: ' . aicfab_feature_line( $aicfab_run['lines'], 'Failed requests' )
);

// --- Per-model mode is unchanged --------------------------------------------

AI_Chat_Bedrock_Usage::reset();
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 10, 'output_tokens' => 2 ), 'amazon.nova-lite-v1:0' );
AI_Chat_Bedrock_Usage::record_failure( 'throttled' );
$aicfab_run = aicfab_feature_run( array( 'by-model' => true ) );
check_feature( 0 === $aicfab_run['exit'] && '' === $aicfab_run['crash'], '--by-model still succeeds.' );
check_feature( aicfab_feature_index( $aicfab_run['lines'], 'Failed requests' ) < 0, '--by-model prints no failure line.' );
check_feature( aicfab_feature_index( $aicfab_run['lines'], 'Time to first text' ) < 0, '--by-model prints no latency line.' );
check_feature( aicfab_feature_index( $aicfab_run['lines'], 'ROW amazon.nova-lite-v1:0' ) >= 0, '--by-model still prints the model table.' );

if ( $failures ) {
	foreach ( $failures as $aicfab_message ) {
		echo 'FAIL: ' . $aicfab_message . PHP_EOL;
	}
	exit( 1 );
}
echo 'PASS: usage reports failed requests by cause.' . PHP_EOL;
exit( 0 );
