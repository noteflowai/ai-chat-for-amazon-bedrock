<?php
/**
 * Behavioral tests for first-token latency counters (WP-03).
 *
 * Run: php tests/test_feature_3733eeb629ad.php
 *
 * All inputs are synthetic; no Bedrock stream is opened.
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['aicfab_options'] = array();

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

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-usage.php';

const FT_OPTION = 'ai_chat_bedrock_usage';

$ft_failures = array();
function ft_check( $condition, $message ) {
	global $ft_failures;
	if ( ! $condition ) {
		$ft_failures[] = $message;
	}
}

function ft_today_map() {
	$stored = get_option( FT_OPTION, array() );
	$today  = gmdate( 'Y-m-d' );
	return isset( $stored[ $today ]['first_token'] ) ? $stored[ $today ]['first_token'] : array();
}

function ft_value( $map, $key ) {
	return isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
}

// 1. Ordering: the first text reaches the consumer before any sample is written.
AI_Chat_Bedrock_Usage::reset();
$received        = array();
$seen_at_first   = null;
$answers         = array( true, true, false );
$inner           = function ( $delta ) use ( &$received, &$seen_at_first, &$answers ) {
	$received[] = $delta;
	if ( 'Hel' === $delta ) {
		$seen_at_first = ft_today_map();
	}
	return array_shift( $answers );
};
$clock           = function () {
	return 101.5;
};
$observer        = AI_Chat_Bedrock_Usage::first_token_observer( $inner, 100.0, $clock );
$results         = array( $observer( '' ), $observer( 'Hel' ), $observer( 'lo' ) );

ft_check( array( '', 'Hel', 'lo' ) === $received, 'all deltas are forwarded in order' );
ft_check( array( true, true, false ) === $results, 'inner results are returned unchanged, including false' );
ft_check( is_array( $seen_at_first ) && 0 === ft_value( $seen_at_first, 'count' ), 'no sample is stored when the first text reaches the consumer' );
$map = ft_today_map();
ft_check( 1 === ft_value( $map, 'count' ), 'exactly one sample after the stream' );
ft_check( 1500 === ft_value( $map, 'sum_ms' ), 'sample is 1500 ms' );
ft_check( 1 === ft_value( $map, '1to2s' ), 'sample is in the 1 to 2 s bucket' );
ft_check( false === strpos( serialize( get_option( FT_OPTION ) ), 'Hel' ), 'no delta text is stored' );
ft_check( false === strpos( serialize( get_option( FT_OPTION ) ), 'lo"' ), 'no later delta text is stored' );

// 2. A consumer that stops on the first text still stops, and the sample is kept once.
AI_Chat_Bedrock_Usage::reset();
$stopper = AI_Chat_Bedrock_Usage::first_token_observer(
	function ( $delta ) {
		return false;
	},
	10.0,
	function () {
		return 10.25;
	}
);
ft_check( false === $stopper( 'Hi' ), 'false from the consumer is passed back' );
$stopper( 'more' );
$map = ft_today_map();
ft_check( 1 === ft_value( $map, 'count' ), 'stopped stream records one sample only' );
ft_check( 250 === ft_value( $map, 'sum_ms' ) && 1 === ft_value( $map, 'lt1s' ), 'stopped stream sample is 250 ms, under 1 s' );

// 3. A non-numeric clock never throws and records 0.
AI_Chat_Bedrock_Usage::reset();
$odd = AI_Chat_Bedrock_Usage::first_token_observer(
	function ( $delta ) {
		return true;
	},
	5.0,
	function () {
		return 'not a time';
	}
);
ft_check( true === $odd( 'x' ), 'non-numeric clock still forwards' );
$map = ft_today_map();
ft_check( 1 === ft_value( $map, 'count' ) && 0 === ft_value( $map, 'sum_ms' ), 'non-numeric clock records 0 ms' );

// 4. Summary over known samples.
AI_Chat_Bedrock_Usage::reset();
foreach ( array( 400, 1500, 3000, 12000 ) as $ms ) {
	AI_Chat_Bedrock_Usage::record_first_token( $ms );
}
$summary = AI_Chat_Bedrock_Usage::first_token_summary( 7 );
ft_check( 4 === $summary['samples'], 'summary counts 4 samples' );
ft_check( 4225 === $summary['average_ms'], 'summary average is 4225 ms' );
ft_check(
	array(
		'lt1s'   => 1,
		'1to2s'  => 1,
		'2to5s'  => 1,
		'5to10s' => 0,
		'gte10s' => 1,
	) === $summary['buckets'],
	'summary buckets are zero-filled in fixed order'
);
ft_check( '1to2s' === $summary['median_bucket'], 'lower median bucket is 1 to 2 s' );
ft_check( 'measured' === $summary['status'], 'status is measured' );

// 5. No samples, or only earlier-format days: unknown, never 0 ms.
AI_Chat_Bedrock_Usage::reset();
$empty = AI_Chat_Bedrock_Usage::first_token_summary( 7 );
ft_check( 0 === $empty['samples'] && null === $empty['average_ms'] && null === $empty['median_bucket'] && 'unknown' === $empty['status'], 'empty summary is unknown' );

update_option(
	FT_OPTION,
	array(
		gmdate( 'Y-m-d' ) => array(
			'requests'      => 3,
			'input_tokens'  => 30,
			'output_tokens' => 60,
			'models'        => array(),
		),
	)
);
$legacy = AI_Chat_Bedrock_Usage::first_token_summary( 7 );
ft_check( 0 === $legacy['samples'] && null === $legacy['average_ms'] && 'unknown' === $legacy['status'], 'earlier-format day reads as unknown' );

// 6. Recording leaves request, token, model and failure counters alone.
$before_totals   = AI_Chat_Bedrock_Usage::totals( 7 );
$before_failures = AI_Chat_Bedrock_Usage::failure_totals( 7 );
$before_models   = AI_Chat_Bedrock_Usage::by_model( 7 );
$before_today    = method_exists( 'AI_Chat_Bedrock_Usage', 'requests_today' ) ? AI_Chat_Bedrock_Usage::requests_today() : null;
AI_Chat_Bedrock_Usage::record_first_token( 900 );
ft_check( $before_totals === AI_Chat_Bedrock_Usage::totals( 7 ), 'totals unchanged by a latency sample' );
ft_check( $before_failures === AI_Chat_Bedrock_Usage::failure_totals( 7 ), 'failure totals unchanged by a latency sample' );
ft_check( $before_models === AI_Chat_Bedrock_Usage::by_model( 7 ), 'per-model counts unchanged by a latency sample' );
if ( null !== $before_today ) {
	ft_check( $before_today === AI_Chat_Bedrock_Usage::requests_today(), 'daily cap count unchanged by a latency sample' );
}

// 7. Clamping.
AI_Chat_Bedrock_Usage::reset();
AI_Chat_Bedrock_Usage::record_first_token( -5 );
$map = ft_today_map();
ft_check( 0 === ft_value( $map, 'sum_ms' ) && 1 === ft_value( $map, 'lt1s' ), '-5 is clamped to 0' );
AI_Chat_Bedrock_Usage::record_first_token( 1000000000 );
$map = ft_today_map();
ft_check( 600000 === ft_value( $map, 'sum_ms' ) && 1 === ft_value( $map, 'gte10s' ), '1000000000 is clamped to 600000' );

// 8. Retention and reset.
$old_day  = gmdate( 'Y-m-d', time() - ( 40 * DAY_IN_SECONDS ) );
$kept_day = gmdate( 'Y-m-d', time() - ( 2 * DAY_IN_SECONDS ) );
$sample   = array(
	'count'  => 2,
	'sum_ms' => 3000,
	'1to2s'  => 2,
);
update_option(
	FT_OPTION,
	array(
		$old_day  => array(
			'requests'    => 1,
			'models'      => array(),
			'first_token' => $sample,
		),
		$kept_day => array(
			'requests'    => 1,
			'models'      => array(),
			'first_token' => $sample,
		),
	)
);
AI_Chat_Bedrock_Usage::record_first_token( 100 );
$stored = get_option( FT_OPTION, array() );
ft_check( ! isset( $stored[ $old_day ] ), 'day older than retention is pruned' );
ft_check( isset( $stored[ $kept_day ]['first_token'] ) && 2 === ft_value( $stored[ $kept_day ]['first_token'], 'count' ), 'retained day keeps its first_token map' );
ft_check( 3 === AI_Chat_Bedrock_Usage::first_token_summary( 7 )['samples'], 'summary spans retained days' );
AI_Chat_Bedrock_Usage::reset();
ft_check( 'unknown' === AI_Chat_Bedrock_Usage::first_token_summary( 30 )['status'], 'reset removes latency samples' );

// 9. Wiring and display, by source inspection (no real Bedrock stream here).
$aws = (string) file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-aws.php' );
$fn  = strpos( $aws, 'public function stream_chat_message(' );
$end = false === $fn ? false : strpos( $aws, 'private function stream_once(', $fn );
$body = ( false === $fn || false === $end ) ? '' : substr( $aws, $fn, $end - $fn );
$wrap = strpos( $body, 'AI_Chat_Bedrock_Usage::first_token_observer( $observer, $started )' );
$time = strpos( $body, '$started  = microtime( true );' );
$call = strpos( $body, '$this->stream_once( $observer, $prepared )' );
ft_check( 1 === substr_count( $body, 'first_token_observer(' ), 'stream_chat_message wraps the observer once' );
ft_check( false !== $time && false !== $wrap && false !== $call && $time < $wrap && $wrap < $call, 'start time and wrap come before the first attempt' );
ft_check( false !== strpos( $body, '$this->stream_once( $observer, $retry )' ), 'fallback attempt uses the same wrapped observer' );

$display = (string) file_get_contents( __DIR__ . '/../admin/partials/ai-chat-bedrock-admin-display.php' );
ft_check( false !== strpos( $display, 'AI_Chat_Bedrock_Usage::first_token_summary( 7 )' ), 'dashboard reads the 7-day summary' );
ft_check( false !== strpos( $display, 'Time to first text' ), 'dashboard prints the time to first text line' );

if ( ! empty( $ft_failures ) ) {
	foreach ( $ft_failures as $message ) {
		echo 'FAIL: ' . $message . PHP_EOL;
	}
	exit( 1 );
}
echo 'First-token latency tests passed.' . PHP_EOL;
