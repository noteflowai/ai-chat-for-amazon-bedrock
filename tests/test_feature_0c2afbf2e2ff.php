<?php
/**
 * First-token latency: the timer around a stream's deltas, the stored counters, the summary
 * and the line the usage command prints. Runs against the real usage and CLI classes.
 *
 * Run: php tests/test_feature_0c2afbf2e2ff.php
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Squiz.PHP.Eval

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_stdout']  = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
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

class WP_CLI {
	public static function add_command( $name, $class ) {}
	public static function log( $message = '' ) {
		$GLOBALS['aicfab_stdout'][] = (string) $message;
	}
	public static function line( $message = '' ) {
		$GLOBALS['aicfab_stdout'][] = (string) $message;
	}
	public static function success( $message = '' ) {
		$GLOBALS['aicfab_stdout'][] = 'Success: ' . $message;
	}
	public static function warning( $message = '' ) {}
	public static function error( $message = '' ) {
		throw new RuntimeException( (string) $message );
	}
}

eval(
	<<<'PHP'
namespace WP_CLI\Utils;
function format_items( $format, $items, $fields ) {
	$GLOBALS['aicfab_stdout'][] = 'table:' . count( $items );
}
PHP
);

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-usage.php';
require_once __DIR__ . '/../includes/class-ai-chat-bedrock-cli.php';

$failures = array();
function check_ftl( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function ftl_summary( $days = 7 ) {
	return AI_Chat_Bedrock_Usage::first_token_summary( $days );
}

function ftl_run_usage( $assoc ) {
	$GLOBALS['aicfab_stdout'] = array();
	$cli                      = new AI_Chat_Bedrock_CLI();
	$cli->usage( array(), $assoc );
	return $GLOBALS['aicfab_stdout'];
}

$today = gmdate( 'Y-m-d' );

// --- The timer forwards every delta and records once, at the first text -----

AI_Chat_Bedrock_Usage::reset();
$captured = array();
$inner    = function ( $delta ) use ( &$captured ) {
	$captured[] = $delta;
	return true;
};
$timer    = AI_Chat_Bedrock_Usage::first_token_timer( $inner, microtime( true ) );
$timer( '' );
check_ftl( 0 === ftl_summary()['samples'], 'An empty delta is not the first token.' );
$timer( 'Hel' );
check_ftl( 1 === ftl_summary()['samples'], 'The first non-empty delta records one sample.' );
$timer( 'lo' );
check_ftl( array( '', 'Hel', 'lo' ) === $captured, 'Every delta reaches the inner callback unchanged and in order.' );
check_ftl( 1 === ftl_summary()['samples'], 'Later deltas do not add samples.' );

foreach ( array( false, true, null ) as $ret ) {
	AI_Chat_Bedrock_Usage::reset();
	$wrapped = AI_Chat_Bedrock_Usage::first_token_timer(
		function ( $delta ) use ( $ret ) {
			return $ret;
		},
		microtime( true )
	);
	foreach ( array( '', 'first', 'later' ) as $delta ) {
		check_ftl( $ret === $wrapped( $delta ), 'The timer returns the inner value ' . var_export( $ret, true ) . ' for delta "' . $delta . '".' );
	}
}

// The wait is measured from the start time given.
AI_Chat_Bedrock_Usage::reset();
$late = AI_Chat_Bedrock_Usage::first_token_timer(
	function ( $delta ) {
		return true;
	},
	microtime( true ) - 1.2
);
$late( 'text' );
$late_summary = ftl_summary();
check_ftl( $late_summary['mean_ms'] >= 1200 && $late_summary['mean_ms'] < 2000, 'A 1.2 second wait is recorded as about 1200 ms, got ' . var_export( $late_summary['mean_ms'], true ) );
check_ftl( '2000' === $late_summary['median_bucket'], 'A 1.2 second wait lands under 2000 ms.' );

// --- Summary, buckets and validation ---------------------------------------

AI_Chat_Bedrock_Usage::reset();
foreach ( array( 120, 900, 900, 20000 ) as $ms ) {
	AI_Chat_Bedrock_Usage::record_first_token( $ms );
}
$four = ftl_summary( 7 );
check_ftl( 4 === $four['samples'], 'Four samples are counted.' );
check_ftl( 5480 === $four['mean_ms'], 'The mean is 5480 ms, got ' . var_export( $four['mean_ms'], true ) );
check_ftl( '1000' === $four['median_bucket'], 'The median bucket is 1000.' );
$expected = array( '250' => 1, '500' => 0, '1000' => 2, '2000' => 0, '4000' => 0, '8000' => 0, '15000' => 0, 'over' => 1 );
check_ftl( $expected == $four['buckets'] && array_map( 'strval', array_keys( $four['buckets'] ) ) === array_map( 'strval', array_keys( $expected ) ), 'All eight buckets are reported in fixed order.' );

$before_invalid = ftl_summary( 7 );
foreach ( array( -5, 'abc', NAN, INF, 700000 ) as $bad ) {
	AI_Chat_Bedrock_Usage::record_first_token( $bad );
}
check_ftl( $before_invalid === ftl_summary( 7 ), 'Invalid samples leave the summary unchanged.' );

AI_Chat_Bedrock_Usage::reset();
foreach ( array( 249, 250, 15000 ) as $ms ) {
	AI_Chat_Bedrock_Usage::record_first_token( $ms );
}
$edges = ftl_summary( 7 )['buckets'];
check_ftl( 1 === $edges['250'] && 1 === $edges['500'] && 1 === $edges['over'] && 0 === $edges['15000'], 'Bounds are exclusive: 249, 250 and 15000 land in 250, 500 and over.' );

AI_Chat_Bedrock_Usage::reset();
AI_Chat_Bedrock_Usage::record_first_token( 0 );
check_ftl( 1 === ftl_summary()['samples'] && 0 === ftl_summary()['mean_ms'], 'Zero milliseconds is a valid sample.' );

AI_Chat_Bedrock_Usage::reset();
AI_Chat_Bedrock_Usage::record_first_token( 'abc' );
check_ftl( ! array_key_exists( AI_Chat_Bedrock_Usage::OPTION, $GLOBALS['aicfab_options'] ), 'An invalid sample writes nothing.' );

// --- Later usage writes keep the latency counters ---------------------------

AI_Chat_Bedrock_Usage::reset();
$answer = AI_Chat_Bedrock_Usage::first_token_timer(
	function ( $delta ) {
		return true;
	},
	microtime( true )
);
$answer( 'SECRET-ANSWER-TEXT' );
foreach ( array( 120, 900, 20000 ) as $ms ) {
	AI_Chat_Bedrock_Usage::record_first_token( $ms );
}
$kept = ftl_summary( 7 );
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 40, 'output_tokens' => 9 ), 'amazon.nova-lite-v1:0' );
AI_Chat_Bedrock_Usage::record_failure( 'throttled' );
check_ftl( $kept === ftl_summary( 7 ), 'record() and record_failure() keep the latency counters.' );
$today_totals = AI_Chat_Bedrock_Usage::today_totals();
check_ftl( 1 === $today_totals['requests'] && 40 === $today_totals['input_tokens'] && 9 === $today_totals['output_tokens'], 'Latency samples do not count as requests or tokens.' );
$stored = $GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Usage::OPTION ][ $today ];
check_ftl( is_int( $stored['first_token_samples'] ) && is_int( $stored['first_token_ms_total'] ), 'Latency totals are stored as integers.' );
check_ftl( 8 === count( $stored['first_token_buckets'] ) && count( array_filter( $stored['first_token_buckets'], 'is_int' ) ) === 8, 'Exactly eight integer buckets are stored.' );
check_ftl( false === strpos( serialize( $GLOBALS['aicfab_options'] ), 'SECRET-ANSWER-TEXT' ), 'No answer text is stored.' );

// --- Window and empty store ----------------------------------------------

AI_Chat_Bedrock_Usage::reset();
$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Usage::OPTION ] = array(
	gmdate( 'Y-m-d', time() - 31 * DAY_IN_SECONDS ) => array( 'requests' => 0, 'first_token_samples' => 5, 'first_token_ms_total' => 500, 'first_token_buckets' => array( '250' => 5 ) ),
	gmdate( 'Y-m-d', time() - DAY_IN_SECONDS )      => array( 'requests' => 3, 'input_tokens' => 1, 'output_tokens' => 1 ),
);
$window = ftl_summary( 30 );
check_ftl( 0 === $window['samples'] && null === $window['mean_ms'] && null === $window['median_bucket'], 'Older days and days without latency keys count as zero.' );

AI_Chat_Bedrock_Usage::reset();
$empty = ftl_summary( 7 );
check_ftl( 0 === $empty['samples'] && null === $empty['mean_ms'] && null === $empty['median_bucket'], 'An empty store has no samples, mean or median.' );

// --- The usage command ----------------------------------------------------

$lines = ftl_run_usage( array( 'days' => 7 ) );
check_ftl( in_array( 'First token: unknown, no streamed answers measured in the last 7 days.', $lines, true ), 'An empty store prints unknown. Got: ' . implode( ' | ', $lines ) );
check_ftl( count( preg_grep( '/^Total: /', $lines ) ) === 1, 'The Total line is still printed.' );

$lines = ftl_run_usage( array( 'days' => 90 ) );
check_ftl( in_array( 'First token: unknown, no streamed answers measured in the last 30 days.', $lines, true ), 'More than 30 days is reported as 30.' );

AI_Chat_Bedrock_Usage::record_first_token( 120 );
$lines = ftl_run_usage( array( 'days' => 7 ) );
check_ftl( in_array( 'First token: 1 streamed answer, median under 250 ms, mean 120 ms.', $lines, true ), 'One sample prints singular. Got: ' . implode( ' | ', $lines ) );

foreach ( array( 900, 900, 20000 ) as $ms ) {
	AI_Chat_Bedrock_Usage::record_first_token( $ms );
}
$lines = ftl_run_usage( array( 'days' => 7 ) );
check_ftl( in_array( 'First token: 4 streamed answers, median under 1000 ms, mean 5480 ms.', $lines, true ), 'Four samples print plural. Got: ' . implode( ' | ', $lines ) );

AI_Chat_Bedrock_Usage::reset();
AI_Chat_Bedrock_Usage::record_first_token( 30000 );
$lines = ftl_run_usage( array( 'days' => 7 ) );
check_ftl( in_array( 'First token: 1 streamed answer, median 15000 ms or more, mean 30000 ms.', $lines, true ), 'An over median is worded as 15000 ms or more.' );

AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 1, 'output_tokens' => 1 ), 'amazon.nova-lite-v1:0' );
$lines = ftl_run_usage( array( 'days' => 7, 'by-model' => true ) );
check_ftl( 0 === count( preg_grep( '/^First token/', $lines ) ), '--by-model output is unchanged.' );

// --- The stream wraps its delta callback ------------------------------------

$stream_source = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-stream.php' );
check_ftl( false !== strpos( $stream_source, 'AI_Chat_Bedrock_Usage::first_token_timer( $emit, $started )' ), 'The streaming endpoint times its deltas.' );

if ( $failures ) {
	echo 'FAILED ' . count( $failures ) . ":\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}
echo "First-token latency tests passed.\n";
exit( 0 );
