<?php
/**
 * Standalone tests: retrieved-source coverage on the dashboard usage panel.
 *
 * A successful chat answer adds one to a grounded or ungrounded integer counter in today's
 * usage bucket, whether or not conversation logging is on. The dashboard reads the last seven
 * days back as "Answers with site content (7 days): N of M (P%).", or says the figure is
 * unknown when nothing has been counted. No question or answer text is stored.
 *
 * Run: php tests/test_feature_373eb4345750.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_notices'] = array();

set_error_handler(
	function ( $number, $text, $file, $line ) {
		$GLOBALS['aicfab_notices'][] = $text . ' at ' . basename( $file ) . ':' . $line;
		return true;
	}
);

// --- WordPress stubs -------------------------------------------------------

$GLOBALS['aicfab_option_reads'] = array();

function get_option( $name, $default = false ) {
	$GLOBALS['aicfab_option_reads'][] = (string) $name;
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
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text );
}
function number_format_i18n( $number ) {
	return number_format( $number );
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-usage.php';

$failures = array();
function check_coverage( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function finish_coverage() {
	global $failures;
	foreach ( $GLOBALS['aicfab_notices'] as $notice ) {
		$failures[] = 'PHP notice: ' . $notice;
	}
	if ( empty( $failures ) ) {
		echo "Coverage counter tests passed.\n";
		exit( 0 );
	}
	echo 'Coverage counter tests failed (' . count( $failures ) . "):\n";
	foreach ( $failures as $failure ) {
		echo ' - ' . $failure . "\n";
	}
	exit( 1 );
}

if ( ! method_exists( 'AI_Chat_Bedrock_Usage', 'record_coverage' ) || ! method_exists( 'AI_Chat_Bedrock_Usage', 'coverage_summary' ) ) {
	check_coverage( false, 'AI_Chat_Bedrock_Usage records and summarizes answer coverage.' );
	finish_coverage();
}

function stored_usage() {
	return get_option( AI_Chat_Bedrock_Usage::OPTION, array() );
}

function holds_text_values( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			if ( holds_text_values( $item ) ) {
				return true;
			}
		}
		return false;
	}
	return ! is_int( $value );
}

function render_coverage() {
	ob_start();
	require dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-coverage.php';
	return (string) ob_get_clean();
}

$today = gmdate( 'Y-m-d' );

// --- Unknown before anything is counted ------------------------------------

AI_Chat_Bedrock_Usage::reset();
$summary = AI_Chat_Bedrock_Usage::coverage_summary( 7 );
check_coverage( 'unknown' === $summary['status'], 'With nothing counted the coverage is unknown.' );
check_coverage( 0 === $summary['total'] && 0 === $summary['percent'], 'Unknown coverage reports total 0 and percent 0.' );

$html = render_coverage();
check_coverage( false !== strpos( $html, 'Answers with site content (7 days): unknown, no answers counted yet.' ), 'The panel says the figure is unknown before any answer is counted.' );
check_coverage( false === strpos( $html, '0%' ), 'The unknown state does not pretend to be 0%.' );

// --- Counting and the measured share --------------------------------------

AI_Chat_Bedrock_Usage::reset();
AI_Chat_Bedrock_Usage::record_coverage( true );
AI_Chat_Bedrock_Usage::record_coverage( true );
AI_Chat_Bedrock_Usage::record_coverage( false );

$summary = AI_Chat_Bedrock_Usage::coverage_summary( 7 );
check_coverage( 'measured' === $summary['status'], 'Counted answers make the coverage measured.' );
check_coverage( 2 === $summary['grounded'], 'Grounded answers are counted.' );
check_coverage( 1 === $summary['ungrounded'], 'Ungrounded answers are counted.' );
check_coverage( 3 === $summary['total'], 'The total is grounded plus ungrounded.' );
check_coverage( 67 === $summary['percent'], 'The percent is rounded to the nearest integer.' );

$stored = stored_usage();
check_coverage( array( $today ) === array_keys( $stored ), 'Only today\'s day bucket is stored.' );
check_coverage( ! holds_text_values( $stored ), 'The usage option holds integer counters only, no text.' );
check_coverage( isset( $stored[ $today ]['requests'] ) && 0 === $stored[ $today ]['requests'], 'Counting coverage does not count a Bedrock request.' );

// Non-boolean input is cast to bool.
AI_Chat_Bedrock_Usage::record_coverage( 'yes' );
AI_Chat_Bedrock_Usage::record_coverage( 0 );
$summary = AI_Chat_Bedrock_Usage::coverage_summary( 7 );
check_coverage( 3 === $summary['grounded'] && 2 === $summary['ungrounded'], 'Truthy and falsy input count as grounded and ungrounded.' );
check_coverage( ! holds_text_values( stored_usage() ), 'Non-boolean input never reaches storage as text.' );

// The window is clamped rather than failing.
$wide   = AI_Chat_Bedrock_Usage::coverage_summary( 999 );
$narrow = AI_Chat_Bedrock_Usage::coverage_summary( 0 );
check_coverage( 5 === $wide['total'] && 5 === $narrow['total'], 'Out-of-range windows are clamped and still include today.' );

// --- Legacy buckets, pruning and unchanged usage figures ------------------

AI_Chat_Bedrock_Usage::reset();
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 100, 'output_tokens' => 20 ), 'us.anthropic.claude-haiku-4-5-20251001-v1:0' );
AI_Chat_Bedrock_Usage::record( array( 'input_tokens' => 7, 'output_tokens' => 3 ), 'amazon.titan-text-express-v1' );

$legacy = AI_Chat_Bedrock_Usage::coverage_summary( 7 );
check_coverage( 'unknown' === $legacy['status'] && 0 === $legacy['total'] && 0 === $legacy['percent'], 'Day buckets without the new counters read as unknown.' );

$totals_before = AI_Chat_Bedrock_Usage::today_totals();
$models_before = AI_Chat_Bedrock_Usage::by_model( 7 );

$stale                       = gmdate( 'Y-m-d', time() - ( 60 * DAY_IN_SECONDS ) );
$seeded                      = stored_usage();
$seeded[ $stale ]            = array(
	'requests'           => 4,
	'input_tokens'       => 40,
	'output_tokens'      => 4,
	'models'             => array(),
	'grounded_answers'   => 9,
	'ungrounded_answers' => 9,
);
update_option( AI_Chat_Bedrock_Usage::OPTION, $seeded );

AI_Chat_Bedrock_Usage::record_coverage( true );

$stored = stored_usage();
check_coverage( ! isset( $stored[ $stale ] ), 'A day outside the retention window is pruned with its coverage counters.' );
check_coverage( isset( $stored[ $today ]['grounded_answers'] ) && 1 === $stored[ $today ]['grounded_answers'], 'Pruning keeps the coverage counter on a retained day.' );
check_coverage( $totals_before === AI_Chat_Bedrock_Usage::today_totals(), 'Requests and token totals are unchanged by counting coverage.' );
check_coverage( $models_before === AI_Chat_Bedrock_Usage::by_model( 7 ), 'Per-model figures are unchanged by counting coverage.' );

$summary = AI_Chat_Bedrock_Usage::coverage_summary( 7 );
check_coverage( 1 === $summary['total'] && 100 === $summary['percent'], 'The stale day does not count toward the seven-day share.' );

// --- The dashboard line ---------------------------------------------------

AI_Chat_Bedrock_Usage::reset();
update_option(
	AI_Chat_Bedrock_Usage::OPTION,
	array(
		$today => array(
			'requests'           => 1500,
			'input_tokens'       => 0,
			'output_tokens'      => 0,
			'models'             => array(),
			'grounded_answers'   => 1000,
			'ungrounded_answers' => 500,
		),
	)
);

$html = render_coverage();
check_coverage( false !== strpos( $html, 'Answers with site content (7 days): 1,000 of 1,500 (67%).' ), 'The panel shows the measured sentence with localized counts and percent.' );
check_coverage( false !== strpos( $html, 'not whether the answer was correct' ), 'The panel explains what the figure does not measure.' );

$document = new DOMDocument();
$previous = libxml_use_internal_errors( true );
$document->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
libxml_clear_errors();
libxml_use_internal_errors( $previous );
$xpath = new DOMXPath( $document );

$lines = $xpath->query( '//p[contains(concat(" ", normalize-space(@class), " "), " aicfab-card-detail ") and contains(concat(" ", normalize-space(@class), " "), " aicfab-usage-failures ")]' );
check_coverage( 1 === $lines->length, 'The line is one paragraph with the existing card-detail classes.' );
check_coverage( 0 === $xpath->query( '//input|//button|//select|//a|//style|//script|//*[@style]' )->length, 'The line adds no control, style or script.' );

// --- Where the panel shows the line --------------------------------------

$display        = (string) file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-display.php' );
$first_token_at = strpos( $display, 'Time to first text (streamed, 7 days): unknown' );
$coverage_at    = strpos( $display, 'ai-chat-bedrock-admin-coverage.php' );
check_coverage( false !== $first_token_at && false !== $coverage_at && $coverage_at > $first_token_at, 'The coverage line follows the time-to-first-text line.' );
check_coverage( 1 === substr_count( $display, 'ai-chat-bedrock-admin-coverage.php' ), 'The dashboard includes the coverage line once.' );

// --- Chat answer paths ----------------------------------------------------

$call = "AI_Chat_Bedrock_Usage::record_coverage( ! empty( \$built['grounded'] ) )";

$public = (string) file_get_contents( dirname( __DIR__ ) . '/public/class-ai-chat-bedrock-public.php' );
check_coverage( 1 === substr_count( $public, '::record_coverage(' ), 'The non-streaming chat counts coverage exactly once.' );
$public_call    = strpos( $public, $call );
$public_error   = strpos( $public, 'if ( is_wp_error( $built ) )' );
$public_success = strpos( $public, "if ( ! empty( \$response['success'] ) ) {" );
check_coverage( false !== $public_call, 'The non-streaming chat passes the built grounded value.' );
check_coverage( false !== $public_error && false !== $public_success && $public_call > $public_error && $public_call > $public_success, 'The non-streaming chat counts inside the success branch, after the error returns.' );
check_coverage( false !== $public_call && $public_call < strpos( $public, 'AI_Chat_Bedrock_Conversations::record(', $public_success ), 'The non-streaming chat counts before and independently of the conversation log.' );

$stream = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-stream.php' );
check_coverage( 1 === substr_count( $stream, '::record_coverage(' ), 'The streaming chat counts coverage exactly once.' );
$stream_call    = strpos( $stream, $call );
$stream_failure = strpos( $stream, "if ( empty( \$response['success'] ) ) {" );
check_coverage( false !== $stream_call, 'The streaming chat passes the built grounded value.' );
check_coverage( false !== $stream_failure && false !== $stream_call && $stream_call > strpos( $stream, '$this->finish();', $stream_failure ), 'The streaming chat counts only after the failure branch has finished the stream.' );
/**
 * Text of the condition that directly encloses a call: from the nearest 'if (' before it to the call.
 *
 * @param string    $source  File contents.
 * @param int|false $call_at Offset of the call.
 * @return string Empty when the call or an enclosing condition is missing.
 */
function enclosing_guard( $source, $call_at ) {
	if ( false === $call_at ) {
		return '';
	}
	$if_at = strrpos( substr( $source, 0, $call_at ), 'if (' );
	return false === $if_at ? '' : substr( $source, $if_at, $call_at - $if_at );
}

$public_guard = enclosing_guard( $public, $public_call );
$stream_guard = enclosing_guard( $stream, $stream_call );
check_coverage( '' !== $public_guard && false !== strpos( $public_guard, "is_callable( array( 'AI_Chat_Bedrock_Usage', 'record_coverage' ) )" ), 'The non-streaming count sits directly under its availability guard.' );
check_coverage( '' !== $stream_guard && false !== strpos( $stream_guard, "! empty( \$response['success'] ) && is_callable( array( 'AI_Chat_Bedrock_Usage', 'record_coverage' ) )" ), 'The streaming count is guarded by a successful response.' );
foreach ( array( 'non-streaming' => $public_guard, 'streaming' => $stream_guard ) as $path => $guard ) {
	check_coverage( '' !== $guard && false === stripos( $guard, 'log' ) && false === stripos( $guard, 'Conversations' ) && false === stripos( $guard, 'settings' ), 'The ' . $path . ' count does not depend on conversation logging or settings.' );
}

// --- Counting with conversation logging off --------------------------------

AI_Chat_Bedrock_Usage::reset();
// Logging is off by default; an empty settings array is the privacy-preserving configuration.
update_option( 'ai_chat_bedrock_settings', array() );
$GLOBALS['aicfab_option_reads'] = array();
AI_Chat_Bedrock_Usage::record_coverage( true );
AI_Chat_Bedrock_Usage::record_coverage( false );
$stored = stored_usage();
check_coverage( isset( $stored[ $today ]['grounded_answers'] ) && 1 === $stored[ $today ]['grounded_answers'], 'A grounded answer is counted with conversation logging off.' );
check_coverage( isset( $stored[ $today ]['ungrounded_answers'] ) && 1 === $stored[ $today ]['ungrounded_answers'], 'An ungrounded answer is counted with conversation logging off.' );
check_coverage( ! in_array( 'ai_chat_bedrock_settings', $GLOBALS['aicfab_option_reads'], true ), 'Counting coverage never reads the plugin settings, so the logging switch cannot block it.' );
$conversation_reads = array_filter(
	$GLOBALS['aicfab_option_reads'],
	function ( $name ) {
		return false !== stripos( $name, 'conversation' );
	}
);
check_coverage( empty( $conversation_reads ), 'Counting coverage never touches the conversation log.' );
check_coverage( ! isset( $GLOBALS['aicfab_options']['ai_chat_bedrock_conversations'] ), 'No conversation log is written when coverage is counted.' );

finish_coverage();
