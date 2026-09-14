<?php
/**
 * Standalone tests for usage accounting.
 *
 * Run: php tests/usage.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['aicfab_options'] = array();

// --- WordPress stubs -------------------------------------------------------

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

$failures = array();
function check_usage( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function seed( $entries ) {
	$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Usage::OPTION ] = $entries;
}

$today     = gmdate( 'Y-m-d' );
$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

// --- Recording with a model ------------------------------------------------

AI_Chat_Bedrock_Usage::reset();
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 100, 'output_tokens' => 20 ), 'us.anthropic.claude-haiku-4-5-20251001-v1:0' );
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 50, 'output_tokens' => 10 ), 'us.anthropic.claude-haiku-4-5-20251001-v1:0' );
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 7, 'output_tokens' => 3 ), 'amazon.titan-text-express-v1' );

$totals = AI_Chat_Bedrock_Usage::today_totals();
check_usage( 3 === $totals['requests'], 'Every invocation is counted.' );
check_usage( 157 === $totals['input_tokens'], 'Input tokens accumulate.' );
check_usage( 33 === $totals['output_tokens'], 'Output tokens accumulate.' );

$rows = AI_Chat_Bedrock_Usage::by_model( 7 );
check_usage( 2 === count( $rows ), 'Each model gets its own row.' );
check_usage( 'us.anthropic.claude-haiku-4-5-20251001-v1:0' === $rows[0]['model'], 'Rows are ordered by request count.' );
check_usage( 2 === $rows[0]['requests'], 'Per-model request counts are correct.' );
check_usage( 150 === $rows[0]['input_tokens'], 'Per-model input tokens are correct.' );
check_usage( 30 === $rows[0]['output_tokens'], 'Per-model output tokens are correct.' );
check_usage( 1 === $rows[1]['requests'] && 7 === $rows[1]['input_tokens'], 'A second model is tracked separately.' );

$per_model_sum = array_sum( wp_list_pluck_compat( $rows, 'requests' ) );
check_usage( $per_model_sum === $totals['requests'], 'Per-model totals reconcile with the day total.' );

// --- Requests without a usable model identifier ---------------------------

AI_Chat_Bedrock_Usage::reset();
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 5, 'output_tokens' => 5 ) );
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 5, 'output_tokens' => 5 ), 'not a model id!' );
check_usage( 2 === AI_Chat_Bedrock_Usage::requests_today(), 'Requests are counted even without a model.' );
check_usage( array() === AI_Chat_Bedrock_Usage::by_model( 7 ), 'An unusable model identifier is not stored.' );

// --- The per-day model list is capped -------------------------------------

AI_Chat_Bedrock_Usage::reset();
for ( $i = 0; $i < AI_Chat_Bedrock_Usage::MAX_MODELS + 5; $i++ ) {
	AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 1, 'output_tokens' => 1 ), 'vendor.model-' . $i . '-v1:0' );
}
$rows = AI_Chat_Bedrock_Usage::by_model( 7 );
check_usage( AI_Chat_Bedrock_Usage::MAX_MODELS === count( $rows ), 'The per-day model list is capped.' );
check_usage( AI_Chat_Bedrock_Usage::MAX_MODELS + 5 === AI_Chat_Bedrock_Usage::requests_today(), 'Capping the model list still counts every request.' );

// --- Pruning must preserve the per-model buckets --------------------------

AI_Chat_Bedrock_Usage::reset();
seed(
	array(
		$yesterday => array(
			'requests'      => 4,
			'input_tokens'  => 40,
			'output_tokens' => 8,
			'models'        => array( 'amazon.nova-lite-v1:0' => array( 'requests' => 4, 'input_tokens' => 40, 'output_tokens' => 8 ) ),
		),
	)
);
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 1, 'output_tokens' => 1 ), 'amazon.nova-lite-v1:0' );

$rows = AI_Chat_Bedrock_Usage::by_model( 7 );
check_usage( 1 === count( $rows ), 'Pruning keeps one row per model.' );
check_usage( 5 === $rows[0]['requests'], 'Pruning preserves per-model counts from earlier days.' );
check_usage( 41 === $rows[0]['input_tokens'], 'Pruning preserves per-model tokens from earlier days.' );

// A stale day beyond retention is dropped entirely.
$stale = gmdate( 'Y-m-d', time() - ( ( AI_Chat_Bedrock_Usage::RETENTION_DAYS + 3 ) * DAY_IN_SECONDS ) );
seed(
	array(
		$stale => array( 'requests' => 99, 'input_tokens' => 999, 'output_tokens' => 999, 'models' => array( 'old.model-v1:0' => array( 'requests' => 99 ) ) ),
	)
);
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 1, 'output_tokens' => 1 ), 'amazon.nova-lite-v1:0' );
$stored = get_option( AI_Chat_Bedrock_Usage::OPTION, array() );
check_usage( ! isset( $stored[ $stale ] ), 'Days beyond retention are pruned.' );
check_usage( 1 === AI_Chat_Bedrock_Usage::requests_today(), 'Pruning does not disturb today.' );

// --- Daily series ---------------------------------------------------------

AI_Chat_Bedrock_Usage::reset();
seed(
	array(
		$today     => array( 'requests' => 3, 'input_tokens' => 30, 'output_tokens' => 6, 'models' => array() ),
		$yesterday => array( 'requests' => 1, 'input_tokens' => 10, 'output_tokens' => 2, 'models' => array() ),
	)
);
$series = AI_Chat_Bedrock_Usage::daily_series( 7 );
check_usage( 7 === count( $series ), 'The series always covers the requested window.' );
check_usage( $today === $series[6]['day'], 'The series ends with today.' );
check_usage( 3 === $series[6]['requests'], 'Today appears last in the series.' );
check_usage( 1 === $series[5]['requests'], 'Yesterday appears before today.' );
check_usage( 0 === $series[0]['requests'], 'Days without activity report zero rather than being skipped.' );

// --- Limits and privacy ---------------------------------------------------

$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'daily_request_limit' => 3 );
check_usage( true === AI_Chat_Bedrock_Usage::daily_limit_reached(), 'The daily cap is enforced from the same counters.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'daily_request_limit' => 0 );
check_usage( false === AI_Chat_Bedrock_Usage::daily_limit_reached(), 'Zero means unlimited.' );

$encoded = json_encode( get_option( AI_Chat_Bedrock_Usage::OPTION, array() ) );
foreach ( array( 'question', 'answer', 'user', 'ip', 'prompt' ) as $forbidden ) {
	check_usage( false === strpos( $encoded, $forbidden ), 'Usage records never contain ' . $forbidden . '.' );
}

function wp_list_pluck_compat( $rows, $field ) {
	return array_map(
		static function ( $row ) use ( $field ) {
			return isset( $row[ $field ] ) ? $row[ $field ] : null;
		},
		(array) $rows
	);
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: usage accounting checks passed\n";
