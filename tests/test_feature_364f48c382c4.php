<?php
/**
 * Behavioral tests for 1.53.0: final Bedrock chat failures are counted by cause and shown on the dashboard.
 *
 * Every Bedrock answer below is a synthetic fixture queued through a stubbed wp_safe_remote_post();
 * nothing here reaches AWS. The streamed path cannot be driven without cURL, so it is covered through
 * the shared outcome method and a source check.
 *
 * Run: php tests/test_feature_364f48c382c4.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AI_CHAT_BEDROCK_AWS_ACCESS_KEY', 'AKIATESTACCESS' );
define( 'AI_CHAT_BEDROCK_AWS_SECRET_KEY', 'test-secret-value' );
define( 'AI_CHAT_BEDROCK_AWS_SESSION_TOKEN', 'test-session-token' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WPINC', 'wp-includes' );

$GLOBALS['aicfab_base'] = array(
	'aws_region'        => 'us-east-1',
	'model_id'          => 'anthropic.claude-3-haiku-20240307-v1:0',
	'max_tokens'        => 1000,
	'temperature'       => 0.4,
	'fallback_model_id' => '',
	'fallback_model'    => '',
);
$GLOBALS['aicfab_test_options']    = $GLOBALS['aicfab_base'];
$GLOBALS['aicfab_test_store']      = array();
$GLOBALS['aicfab_test_transients'] = array();
$GLOBALS['aicfab_test_posts']      = array();
$GLOBALS['aicfab_test_responses']  = array();
$GLOBALS['aicfab_inject']          = false;
$GLOBALS['aicfab_failures']        = array();

class WP_Error {
	private $code; private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}

// Plugin settings plus a writable store for the usage counters.
function get_option( $name, $default = false ) {
	if ( 'ai_chat_bedrock_settings' === $name ) {
		return $GLOBALS['aicfab_test_options'];
	}
	return array_key_exists( $name, $GLOBALS['aicfab_test_store'] ) ? $GLOBALS['aicfab_test_store'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) { $GLOBALS['aicfab_test_store'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['aicfab_test_store'][ $name ] ); return true; }

// Translation stub; the escaping check makes one label hostile.
function aicfab_t_tr( $text ) {
	$text = (string) $text;
	if ( ! empty( $GLOBALS['aicfab_inject'] ) ) {
		$text = str_ireplace( 'hrottled', 'hrottled <script>', $text );
	}
	return $text;
}
function __( $text, $domain = null ) { return aicfab_t_tr( $text ); }
function _x( $text, $context, $domain = null ) { return aicfab_t_tr( $text ); }
function _n( $single, $plural, $number, $domain = null ) { return aicfab_t_tr( 1 === (int) $number ? $single : $plural ); }
function _e( $text, $domain = null ) { echo aicfab_t_tr( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return esc_html( $url ); }
function esc_url_raw( $url ) { return (string) $url; }
function esc_js( $text ) { return esc_html( $text ); }
function esc_html__( $text, $domain = null ) { return esc_html( __( $text ) ); }
function esc_attr__( $text, $domain = null ) { return esc_attr( __( $text ) ); }
function esc_html_x( $text, $context, $domain = null ) { return esc_html( __( $text ) ); }
function esc_html_e( $text, $domain = null ) { echo esc_html__( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr__( $text ); }
function wp_kses_post( $text ) { return (string) $text; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, $decimals ); }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function get_current_user_id() { return 0; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function home_url( $path = '' ) { return 'https://example.test/' . ltrim( (string) $path, '/' ); }
function get_admin_page_title() { return 'AI Chat Bedrock'; }
function wp_date( $format, $timestamp = null ) { return gmdate( $format, null === $timestamp ? time() : (int) $timestamp ); }
function date_i18n( $format, $timestamp = false ) { return gmdate( $format, false === $timestamp ? time() : (int) $timestamp ); }
function current_time( $type ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); }
function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( (array) $list as $key => $row ) {
		if ( is_array( $row ) ) {
			$out[ $key ] = isset( $row[ $field ] ) ? $row[ $field ] : null;
		}
	}
	return $out;
}
function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { return ''; }
function wp_create_nonce( $action = -1 ) { return 'nonce'; }
function submit_button( $text = null ) { echo '<button type="submit" class="button">' . esc_html( (string) $text ) . '</button>'; }
function selected( $a, $b = true, $echo = true ) { return ''; }
function checked( $a, $b = true, $echo = true ) { return ''; }
function size_format( $bytes ) { return (string) $bytes; }
function human_time_diff( $from, $to = 0 ) { return '1 min'; }
function sanitize_html_class( $class ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class ); }
function is_multisite() { return false; }
function wp_unslash( $value ) { return $value; }

// The same runtime stubs tests/aws-payload.php uses.
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $hook, $value ) { return $value; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function remove_filter( $hook, $callback, $priority = 10 ) { return true; }
function wp_salt() { return 'test-salt'; }
function get_transient( $key ) { return isset( $GLOBALS['aicfab_test_transients'][ $key ] ) ? $GLOBALS['aicfab_test_transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['aicfab_test_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['aicfab_test_transients'][ $key ] ); return true; }
function wp_remote_request( $url, $args = array() ) { return new WP_Error( 'aicfab_test_blocked', 'Metadata requests are blocked during tests.' ); }
function wp_remote_retrieve_response_code( $response ) { return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0; }
function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? (string) $response['body'] : ''; }
function wp_remote_retrieve_header( $response, $name ) { return ''; }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function wp_safe_remote_post( $url, $args = array() ) {
	$GLOBALS['aicfab_test_posts'][] = array( 'url' => $url, 'args' => $args );
	return array_shift( $GLOBALS['aicfab_test_responses'] );
}
function wp_safe_remote_get( $url, $args = array() ) { return new WP_Error( 'aicfab_test_blocked', 'GET requests are blocked during tests.' ); }

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws-credentials.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-event-stream.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws.php';
if ( ! class_exists( 'AI_Chat_Bedrock_Bedrock_Errors', false ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-bedrock-errors.php';
}
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-usage.php';

function aicfab_t_check( $condition, $message ) {
	if ( ! $condition ) {
		$GLOBALS['aicfab_failures'][] = $message;
	}
}
function aicfab_t_reset( $settings = array() ) {
	$GLOBALS['aicfab_test_options']    = array_merge( $GLOBALS['aicfab_base'], $settings );
	$GLOBALS['aicfab_test_store']      = array();
	$GLOBALS['aicfab_test_transients'] = array();
	$GLOBALS['aicfab_test_posts']      = array();
	$GLOBALS['aicfab_test_responses']  = array();
	$GLOBALS['aicfab_inject']          = false;
}
function aicfab_t_response( $code, $body ) {
	return array(
		'headers'  => array(),
		'body'     => json_encode( $body ),
		'response' => array( 'code' => $code, 'message' => '' ),
	);
}
function aicfab_t_error( $code, $message ) { return aicfab_t_response( $code, array( 'message' => $message ) ); }
function aicfab_t_claude_ok() {
	return aicfab_t_response( 200, array( 'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'content' => array( array( 'type' => 'text', 'text' => 'Claude answer beta-5510' ) ), 'stop_reason' => 'end_turn', 'usage' => array( 'input_tokens' => 7, 'output_tokens' => 3 ) ) );
}
function aicfab_t_nova_ok() {
	return aicfab_t_response( 200, array( 'output' => array( 'message' => array( 'role' => 'assistant', 'content' => array( array( 'text' => 'Nova answer gamma-2207' ) ) ) ), 'stopReason' => 'end_turn', 'usage' => array( 'inputTokens' => 7, 'outputTokens' => 3, 'totalTokens' => 10 ) ) );
}
function aicfab_t_chat( $overrides = array(), $text = 'Test question alpha-7731' ) {
	$client = new AI_Chat_Bedrock_AWS( $overrides );
	return $client->handle_chat_message( array( 'messages' => array( array( 'role' => 'user', 'content' => $text ) ) ) );
}
function aicfab_t_today() {
	$all = get_option( AI_Chat_Bedrock_Usage::OPTION, array() );
	$day = gmdate( 'Y-m-d' );
	return isset( $all[ $day ] ) ? $all[ $day ] : array();
}
function aicfab_t_requests() {
	$entry = aicfab_t_today();
	return isset( $entry['requests'] ) ? (int) $entry['requests'] : 0;
}
function aicfab_t_fail( $category = null ) {
	$totals = AI_Chat_Bedrock_Usage::failure_totals( 1 );
	return null === $category ? (int) $totals['total'] : (int) $totals['by_category'][ $category ];
}
function aicfab_t_posts() { return count( $GLOBALS['aicfab_test_posts'] ); }
function aicfab_t_code( $result ) { return isset( $result['data']['code'] ) ? (string) $result['data']['code'] : ''; }
function aicfab_t_day( $offset ) { return gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) ); }
function aicfab_t_render() {
	ob_start();
	set_error_handler( function () { return true; } );
	try {
		include dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-display.php';
	} finally {
		restore_error_handler();
	}
	return (string) ob_get_clean();
}
function aicfab_t_text( $html ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( $html ) ) ); }
function aicfab_t_metric( $html, $count ) {
	return 1 === preg_match( '/<span class="aicfab-metric">' . $count . '<\/span><span class="aicfab-metric-label">failed requests<\/span>/i', $html );
}
function aicfab_t_method( $src, $signature ) {
	$start = strpos( $src, $signature );
	if ( false === $start ) {
		return '';
	}
	$end = strpos( $src, "\n\t}\n", $start );
	return false === $end ? substr( $src, $start ) : substr( $src, $start, $end - $start );
}
// Remove anonymous function bodies so only the method's own returns are inspected.
function aicfab_t_strip_closures( $body ) {
	$offset = 0;
	while ( 1 === preg_match( '/\bfunction\s*\(/', $body, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
		$pos  = (int) $match[0][1];
		$open = strpos( $body, '{', $pos );
		if ( false === $open ) {
			break;
		}
		$len   = strlen( $body );
		$depth = 0;
		$close = $len - 1;
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $body[ $i ] ) {
				$depth++;
			} elseif ( '}' === $body[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					$close = $i;
					break;
				}
			}
		}
		$body   = substr( $body, 0, $pos ) . 'null' . substr( $body, $close + 1 );
		$offset = $pos;
	}
	return $body;
}

$claude        = 'anthropic.claude-3-haiku-20240307-v1:0';
$nova          = 'amazon.nova-lite-v1:0';
$with_fallback = array( 'fallback_model_id' => $nova, 'fallback_model' => $nova );

// Collaborators the dashboard partial needs. The real setup checklist queries posts through $wpdb,
// which this suite does not provide, so a fixed stand-in is used when it is not already loaded.
if ( ! class_exists( 'AI_Chat_Bedrock_Models', false ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-models.php';
}
if ( ! class_exists( 'AI_Chat_Bedrock_Setup_Steps', false ) ) {
	class AI_Chat_Bedrock_Setup_Steps {
		public static function essential( $context ) {
			return array(
				array(
					'done'  => true,
					'label' => 'Connect AWS credentials',
					'help'  => 'Connected.',
					'url'   => 'https://example.test/wp-admin/admin.php?page=ai-chat-for-amazon-bedrock-settings',
				),
			);
		}
		public static function progress( $steps ) {
			return array( 'complete' => true, 'done' => 1, 'total' => 1 );
		}
		public static function next( $context ) {
			return array();
		}
	}
}

function aicfab_u_day( $offset = 0 ) {
	return gmdate( 'Y-m-d', time() - ( (int) $offset * DAY_IN_SECONDS ) );
}
function aicfab_u_entry( $offset = 0 ) {
	$all = get_option( AI_Chat_Bedrock_Usage::OPTION, array() );
	$day = aicfab_u_day( $offset );
	return is_array( $all ) && isset( $all[ $day ] ) && is_array( $all[ $day ] ) ? $all[ $day ] : array();
}
function aicfab_u_requests() {
	$entry = aicfab_u_entry();
	return isset( $entry['requests'] ) ? (int) $entry['requests'] : 0;
}
function aicfab_u_failed( $category = null ) {
	$totals = AI_Chat_Bedrock_Usage::failure_totals( 1 );
	return null === $category ? (int) $totals['total'] : (int) $totals['by_category'][ $category ];
}
function aicfab_u_posts() { return count( $GLOBALS['aicfab_test_posts'] ); }
function aicfab_u_code( $result ) { return isset( $result['data']['code'] ) ? (string) $result['data']['code'] : ''; }
function aicfab_u_seed( $days ) { update_option( AI_Chat_Bedrock_Usage::OPTION, $days ); }
function aicfab_u_keys( $value ) {
	$keys = array();
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $child ) {
			$keys = array_merge( $keys, array( strtolower( (string) $key ) ), aicfab_u_keys( $child ) );
		}
	}
	return $keys;
}

$aicfab_categories = array( 'throttled', 'access_denied', 'validation', 'unavailable', 'network', 'other' );

// 1. classify_failure() rule table.
$aicfab_rules = array(
	array( 429, 'aicfab_http_error', 'throttled' ),
	array( 401, 'aicfab_http_error', 'access_denied' ),
	array( 403, 'aicfab_http_error', 'access_denied' ),
	array( 400, 'aicfab_http_error', 'validation' ),
	array( 500, 'aicfab_http_error', 'unavailable' ),
	array( 503, 'aicfab_http_error', 'unavailable' ),
	array( 0, 'aicfab_unreachable', 'network' ),
	array( 0, 'aicfab_stream_interrupted', 'network' ),
	array( 200, 'aicfab_error', 'other' ),
	array( 0, 'aicfab_error', 'other' ),
);
foreach ( $aicfab_rules as $aicfab_rule ) {
	$aicfab_got = AI_Chat_Bedrock_Usage::classify_failure( $aicfab_rule[0], $aicfab_rule[1] );
	aicfab_t_check( $aicfab_rule[2] === $aicfab_got, sprintf( 'classify_failure(%d, %s) returned %s, expected %s.', $aicfab_rule[0], $aicfab_rule[1], $aicfab_got, $aicfab_rule[2] ) );
}

// 2. An unknown category counts as other and leaves the success counters alone.
aicfab_t_reset();
aicfab_u_seed( array( aicfab_u_day() => array( 'requests' => 3, 'input_tokens' => 30, 'output_tokens' => 9, 'models' => array( $claude => array( 'requests' => 3, 'input_tokens' => 30, 'output_tokens' => 9 ) ) ) ) );
AI_Chat_Bedrock_Usage::record_failure( 'bogus' );
$aicfab_entry = aicfab_u_entry();
aicfab_t_check( isset( $aicfab_entry['failures'] ) && array( 'other' => 1 ) === $aicfab_entry['failures'], 'record_failure(bogus) must store other 1.' );
aicfab_t_check( isset( $aicfab_entry['requests'] ) && 3 === $aicfab_entry['requests'], 'record_failure() changed requests.' );
aicfab_t_check( isset( $aicfab_entry['input_tokens'] ) && 30 === $aicfab_entry['input_tokens'], 'record_failure() changed input tokens.' );
aicfab_t_check( isset( $aicfab_entry['output_tokens'] ) && 9 === $aicfab_entry['output_tokens'], 'record_failure() changed output tokens.' );
aicfab_t_check( isset( $aicfab_entry['models'][ $claude ]['requests'] ) && 3 === $aicfab_entry['models'][ $claude ]['requests'], 'record_failure() changed the per-model counters.' );

// 3. A failure first, then a success on the same day: both kept, no notice or warning from the usage class.
aicfab_t_reset();
$GLOBALS['aicfab_usage_notices'] = array();
set_error_handler(
	function ( $errno, $errstr, $errfile = '' ) {
		if ( false !== strpos( (string) $errfile, 'class-ai-chat-bedrock-usage.php' ) ) {
			$GLOBALS['aicfab_usage_notices'][] = $errstr;
		}
		return false;
	},
	E_NOTICE | E_WARNING
);
AI_Chat_Bedrock_Usage::record_failure( 'throttled' );
$GLOBALS['aicfab_test_responses'] = array( aicfab_t_claude_ok() );
$aicfab_result = aicfab_t_chat();
restore_error_handler();
aicfab_t_check( ! empty( $aicfab_result['success'] ), 'The success after a recorded failure should answer.' );
aicfab_t_check( 1 === aicfab_u_requests(), 'A success after a failure should give requests 1.' );
aicfab_t_check( 1 === aicfab_u_failed( 'throttled' ) && 1 === aicfab_u_failed(), 'A later success must keep the throttled count.' );
aicfab_t_check( array() === $GLOBALS['aicfab_usage_notices'], 'The usage class raised: ' . implode( ' | ', $GLOBALS['aicfab_usage_notices'] ) );

// 4. failure_totals() sums in fixed order and respects the window.
aicfab_t_reset();
aicfab_u_seed(
	array(
		aicfab_u_day( 0 )  => array( 'requests' => 1, 'input_tokens' => 0, 'output_tokens' => 0, 'models' => array(), 'failures' => array( 'throttled' => 2 ) ),
		aicfab_u_day( 3 )  => array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'models' => array(), 'failures' => array( 'other' => 1, 'network' => 1 ) ),
		aicfab_u_day( 10 ) => array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'models' => array(), 'failures' => array( 'throttled' => 5 ) ),
	)
);
$aicfab_week = AI_Chat_Bedrock_Usage::failure_totals( 7 );
aicfab_t_check( $aicfab_categories === array_keys( $aicfab_week['by_category'] ), 'failure_totals() must list every category in fixed order.' );
aicfab_t_check( 4 === $aicfab_week['total'], 'failure_totals(7) total should be 4, got ' . $aicfab_week['total'] . '.' );
aicfab_t_check( 2 === $aicfab_week['by_category']['throttled'] && 1 === $aicfab_week['by_category']['network'] && 1 === $aicfab_week['by_category']['other'] && 0 === $aicfab_week['by_category']['access_denied'], 'failure_totals(7) category sums are wrong.' );
$aicfab_month = AI_Chat_Bedrock_Usage::failure_totals( 30 );
aicfab_t_check( 9 === $aicfab_month['total'], 'failure_totals(30) should include day 10.' );

// 5. prune() keeps in-window positive allowlisted counts only.
aicfab_t_reset();
aicfab_u_seed(
	array(
		aicfab_u_day( 0 )  => array( 'requests' => 2, 'failures' => array( 'throttled' => 1, 'bogus' => 4, 'network' => 0, 'other' => -2, 'prompt' => 'Test question alpha-7731' ) ),
		aicfab_u_day( 2 )  => array( 'requests' => 1, 'failures' => array( 'unavailable' => '2' ) ),
		aicfab_u_day( 40 ) => array( 'requests' => 9, 'failures' => array( 'throttled' => 3 ) ),
	)
);
AI_Chat_Bedrock_Usage::record_failure( 'validation' );
$aicfab_all   = get_option( AI_Chat_Bedrock_Usage::OPTION, array() );
$aicfab_today = isset( $aicfab_all[ aicfab_u_day( 0 ) ] ) ? $aicfab_all[ aicfab_u_day( 0 ) ] : array();
aicfab_t_check( isset( $aicfab_today['failures'] ) && array( 'throttled' => 1, 'validation' => 1 ) === $aicfab_today['failures'], 'prune() must drop unknown and non-positive failure entries.' );
aicfab_t_check( isset( $aicfab_today['requests'], $aicfab_today['input_tokens'], $aicfab_today['output_tokens'], $aicfab_today['models'] ) && 2 === $aicfab_today['requests'], 'prune() must keep the default day keys.' );
aicfab_t_check( isset( $aicfab_all[ aicfab_u_day( 2 ) ]['failures'] ) && array( 'unavailable' => 2 ) === $aicfab_all[ aicfab_u_day( 2 ) ]['failures'], 'prune() must keep in-window failure counts as ints.' );
aicfab_t_check( ! isset( $aicfab_all[ aicfab_u_day( 40 ) ] ), 'prune() must drop expired days.' );

// E2E 1. Throttled with no fallback.
aicfab_t_reset();
$GLOBALS['aicfab_test_responses'] = array( aicfab_t_error( 429, 'Too many requests, please wait.' ) );
$aicfab_result = aicfab_t_chat();
aicfab_t_check( empty( $aicfab_result['success'] ), '429: the call should fail.' );
aicfab_t_check( 1 === aicfab_u_posts(), '429: expected 1 post, got ' . aicfab_u_posts() . '.' );
aicfab_t_check( 1 === aicfab_u_failed( 'throttled' ) && 1 === aicfab_u_failed(), '429: expected throttled 1.' );
aicfab_t_check( 0 === aicfab_u_requests(), '429: requests must stay 0.' );

// E2E 2. A 503 rescued by the fallback is not a failure.
aicfab_t_reset( $with_fallback );
$GLOBALS['aicfab_test_responses'] = array( aicfab_t_error( 503, 'Service unavailable.' ), aicfab_t_nova_ok() );
$aicfab_result = aicfab_t_chat();
aicfab_t_check( ! empty( $aicfab_result['success'] ), '503 then fallback: the call should answer.' );
aicfab_t_check( 2 === aicfab_u_posts(), '503 then fallback: expected 2 posts.' );
aicfab_t_check( 0 === aicfab_u_failed(), '503 then fallback: expected 0 failures.' );
aicfab_t_check( 1 === aicfab_u_requests(), '503 then fallback: expected requests 1.' );

// E2E 3. Primary 429, fallback 500: one failure, classified from the primary.
aicfab_t_reset( $with_fallback );
$GLOBALS['aicfab_test_responses'] = array( aicfab_t_error( 429, 'Too many requests.' ), aicfab_t_error( 500, 'Internal failure.' ) );
$aicfab_result = aicfab_t_chat();
aicfab_t_check( empty( $aicfab_result['success'] ), '429 then 500: the call should fail.' );
aicfab_t_check( 2 === aicfab_u_posts(), '429 then 500: expected 2 posts.' );
aicfab_t_check( 1 === aicfab_u_failed( 'throttled' ) && 1 === aicfab_u_failed() && 0 === aicfab_u_failed( 'unavailable' ), '429 then 500: expected throttled 1 and total 1.' );

// E2E 4. Transport error with no fallback.
aicfab_t_reset();
$GLOBALS['aicfab_test_responses'] = array( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );
$aicfab_result = aicfab_t_chat();
aicfab_t_check( 'aicfab_unreachable' === aicfab_u_code( $aicfab_result ), 'Transport error: expected aicfab_unreachable, got ' . aicfab_u_code( $aicfab_result ) . '.' );
aicfab_t_check( 1 === aicfab_u_posts(), 'Transport error: expected 1 post.' );
aicfab_t_check( 1 === aicfab_u_failed( 'network' ) && 1 === aicfab_u_failed(), 'Transport error: expected network 1.' );

// E2E 5. A Converse refusal repaired by adapt_refused_converse() and then answered is not a failure.
$aicfab_converse = '';
foreach ( array( 'amazon.nova-lite-v1:0', 'meta.llama3-1-8b-instruct-v1:0', 'mistral.mistral-large-2407-v1:0', 'openai.gpt-oss-20b-1:0', 'deepseek.r1-v1:0', 'qwen.qwen3-32b-v1:0' ) as $aicfab_candidate ) {
	aicfab_t_reset( array( 'model_id' => $aicfab_candidate ) );
	$GLOBALS['aicfab_test_responses'] = array( aicfab_t_nova_ok() );
	aicfab_t_chat();
	if ( 1 === aicfab_u_posts() ) {
		$aicfab_path = (string) wp_parse_url( $GLOBALS['aicfab_test_posts'][0]['url'], PHP_URL_PATH );
		if ( '/converse' === substr( $aicfab_path, -9 ) ) {
			$aicfab_converse = $aicfab_candidate;
			break;
		}
	}
}
aicfab_t_check( '' !== $aicfab_converse, 'No candidate model used the Converse API.' );
if ( '' !== $aicfab_converse ) {
	aicfab_t_reset( array( 'model_id' => $aicfab_converse ) );
	$GLOBALS['aicfab_test_responses'] = array(
		aicfab_t_error( 400, 'This model does not support the temperature field.' ),
		aicfab_t_nova_ok(),
	);
	$aicfab_result = aicfab_t_chat();
	aicfab_t_check( ! empty( $aicfab_result['success'] ), 'Converse repair: the call should answer.' );
	aicfab_t_check( 2 === aicfab_u_posts(), 'Converse repair: expected 2 posts, got ' . aicfab_u_posts() . '.' );
	aicfab_t_check( 0 === aicfab_u_failed(), 'Converse repair: expected 0 failures.' );
	aicfab_t_check( 1 === aicfab_u_requests(), 'Converse repair: expected requests 1.' );
}

// E2E 6. An empty conversation sends nothing and counts nothing.
aicfab_t_reset();
$aicfab_client = new AI_Chat_Bedrock_AWS();
$aicfab_result = $aicfab_client->handle_chat_message( array( 'messages' => array() ) );
aicfab_t_check( empty( $aicfab_result['success'] ), 'Empty conversation: the call should fail.' );
aicfab_t_check( 0 === aicfab_u_posts() && 0 === aicfab_u_failed(), 'Empty conversation: expected 0 posts and 0 failures.' );

// Exclusion a. Missing credentials.
aicfab_t_reset();
$aicfab_client  = new AI_Chat_Bedrock_AWS();
$aicfab_reflect = new ReflectionClass( $aicfab_client );
foreach ( $aicfab_reflect->getProperties() as $aicfab_prop ) {
	if ( $aicfab_prop->isStatic() || ! preg_match( '/key|secret|token/i', $aicfab_prop->getName() ) ) {
		continue;
	}
	$aicfab_prop->setAccessible( true );
	if ( is_string( $aicfab_prop->getValue( $aicfab_client ) ) ) {
		$aicfab_prop->setValue( $aicfab_client, '' );
	}
}
aicfab_t_check( ! $aicfab_client->has_credentials(), 'Credentials should be cleared.' );
$GLOBALS['aicfab_test_responses'] = array( aicfab_t_claude_ok() );
$aicfab_result = $aicfab_client->handle_chat_message( array( 'messages' => array( array( 'role' => 'user', 'content' => 'Test question alpha-7731' ) ) ) );
aicfab_t_check( 'aicfab_no_credentials' === aicfab_u_code( $aicfab_result ), 'No credentials: expected aicfab_no_credentials, got ' . aicfab_u_code( $aicfab_result ) . '.' );
aicfab_t_check( 0 === aicfab_u_posts() && 0 === aicfab_u_failed(), 'No credentials: expected 0 posts and 0 failures.' );

// Exclusion b. Invalid region.
aicfab_t_reset();
$GLOBALS['aicfab_test_responses'] = array( aicfab_t_claude_ok() );
$aicfab_result = aicfab_t_chat( array( 'aws_region' => 'bad' ) );
aicfab_t_check( 'aicfab_invalid_region' === aicfab_u_code( $aicfab_result ), 'Bad region: expected aicfab_invalid_region, got ' . aicfab_u_code( $aicfab_result ) . '.' );
aicfab_t_check( 0 === aicfab_u_posts() && 0 === aicfab_u_failed(), 'Bad region: expected 0 posts and 0 failures.' );

// Exclusion c. Daily limit reached.
aicfab_t_reset( array( 'daily_request_limit' => 1 ) );
$GLOBALS['aicfab_test_responses'] = array( aicfab_t_claude_ok(), aicfab_t_claude_ok() );
$aicfab_result = aicfab_t_chat();
aicfab_t_check( ! empty( $aicfab_result['success'] ) && 1 === aicfab_u_requests(), 'Daily limit: the first request should answer.' );
$GLOBALS['aicfab_test_posts'] = array();
$aicfab_result = aicfab_t_chat();
aicfab_t_check( 'aicfab_daily_limit' === aicfab_u_code( $aicfab_result ), 'Daily limit: expected aicfab_daily_limit, got ' . aicfab_u_code( $aicfab_result ) . '.' );
aicfab_t_check( 0 === aicfab_u_posts() && 0 === aicfab_u_failed() && 1 === aicfab_u_requests(), 'Daily limit: expected 0 posts, 0 failures and requests 1.' );

// Stream outcome through the shared method.
aicfab_t_reset();
$aicfab_client  = new AI_Chat_Bedrock_AWS();
$aicfab_outcome = new ReflectionMethod( 'AI_Chat_Bedrock_AWS', 'record_chat_outcome' );
$aicfab_outcome->setAccessible( true );
$aicfab_sent = new ReflectionProperty( 'AI_Chat_Bedrock_AWS', 'request_sent' );
$aicfab_sent->setAccessible( true );
$aicfab_sent->setValue( $aicfab_client, true );
$aicfab_stopped = array( 'success' => true, 'data' => array( 'stopped' => true ) );
aicfab_t_check( $aicfab_stopped === $aicfab_outcome->invoke( $aicfab_client, $aicfab_stopped ), 'record_chat_outcome() must return its input unchanged.' );
aicfab_t_check( 0 === aicfab_u_failed(), 'A stopped stream must not count as a failure.' );
$aicfab_cut = array( 'success' => false, 'data' => array( 'message' => 'The stream was interrupted.', 'code' => 'aicfab_stream_interrupted', 'status' => 0 ) );
$aicfab_outcome->invoke( $aicfab_client, $aicfab_cut );
aicfab_t_check( 1 === aicfab_u_failed( 'network' ) && 1 === aicfab_u_failed(), 'An interrupted stream that sent a request should count network 1.' );
$aicfab_sent->setValue( $aicfab_client, false );
$aicfab_outcome->invoke( $aicfab_client, $aicfab_cut );
aicfab_t_check( 1 === aicfab_u_failed(), 'A failure with no request sent must not count.' );

// Source check: every return after the first prepare_request() goes through record_chat_outcome().
$aicfab_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws.php' );
foreach ( array( 'public function stream_chat_message(', 'public function handle_chat_message(' ) as $aicfab_sig ) {
	$aicfab_body = aicfab_t_method( $aicfab_src, $aicfab_sig );
	$aicfab_body = aicfab_t_strip_closures( $aicfab_body );
	$aicfab_pos  = '' === $aicfab_body ? false : strpos( $aicfab_body, 'prepare_request(' );
	aicfab_t_check( false !== $aicfab_pos, 'Could not find prepare_request() in ' . $aicfab_sig );
	if ( false === $aicfab_pos ) {
		continue;
	}
	preg_match_all( '/\breturn\b([^;]*);/', substr( $aicfab_body, $aicfab_pos ), $aicfab_returns );
	$aicfab_list = $aicfab_returns[1];
	array_shift( $aicfab_list ); // The prepare_request() error return itself.
	aicfab_t_check( count( $aicfab_list ) > 0, 'No outcome returns found in ' . $aicfab_sig );
	foreach ( $aicfab_list as $aicfab_return ) {
		aicfab_t_check( 0 === strpos( ltrim( $aicfab_return ), '$this->record_chat_outcome(' ), $aicfab_sig . ' returns without record_chat_outcome(): return' . $aicfab_return );
	}
}

// Source check: both HTTP paths set the attempt flag before the request leaves the site.
foreach ( array( 'function invoke_model(' => 'self::aws_remote(', 'function stream_once(' => 'curl_exec(' ) as $aicfab_sig => $aicfab_sender ) {
	$aicfab_body = aicfab_t_method( $aicfab_src, $aicfab_sig );
	$aicfab_flag = '' === $aicfab_body ? false : strpos( $aicfab_body, '$this->request_sent = true;' );
	$aicfab_send = '' === $aicfab_body ? false : strpos( $aicfab_body, $aicfab_sender );
	aicfab_t_check( false !== $aicfab_flag && false !== $aicfab_send && $aicfab_flag < $aicfab_send, $aicfab_sig . ' must set request_sent before ' . $aicfab_sender );
}

// Privacy: the counters hold no conversation text, error text or identity.
aicfab_t_reset();
$GLOBALS['aicfab_test_responses'] = array( aicfab_t_claude_ok(), aicfab_t_error( 429, 'Rate exceeded delta-4410' ) );
aicfab_t_chat();
aicfab_t_chat( array(), 'Second question epsilon-9902' );
aicfab_t_check( 1 === aicfab_u_requests() && 1 === aicfab_u_failed( 'throttled' ), 'Privacy: expected one success and one throttled failure.' );
$aicfab_usage = get_option( AI_Chat_Bedrock_Usage::OPTION, array() );
$aicfab_json  = (string) wp_json_encode( $aicfab_usage );
foreach ( array( 'alpha-7731', 'epsilon-9902', 'beta-5510', 'delta-4410', 'Rate exceeded' ) as $aicfab_needle ) {
	aicfab_t_check( false === strpos( $aicfab_json, $aicfab_needle ), 'The usage option contains ' . $aicfab_needle . '.' );
}
$aicfab_keys = aicfab_u_keys( $aicfab_usage );
foreach ( array( 'user', 'user_id', 'ip', 'prompt', 'message', 'question', 'answer' ) as $aicfab_key ) {
	aicfab_t_check( ! in_array( $aicfab_key, $aicfab_keys, true ), 'The usage option has a ' . $aicfab_key . ' key.' );
}

// Render A: requests and failures.
$aicfab_busy = array( aicfab_u_day() => array( 'requests' => 2, 'input_tokens' => 14, 'output_tokens' => 6, 'models' => array( $claude => array( 'requests' => 2, 'input_tokens' => 14, 'output_tokens' => 6 ) ), 'failures' => array( 'throttled' => 3, 'access_denied' => 1 ) ) );
aicfab_t_reset();
aicfab_u_seed( $aicfab_busy );
$aicfab_html = aicfab_t_render();
$aicfab_text = aicfab_t_text( $aicfab_html );
aicfab_t_check( false !== strpos( $aicfab_text, 'Failed chat requests, last 7 days: 4. Throttled 3, access denied 1.' ), 'Render A: missing the seven-day failure line.' );
aicfab_t_check( aicfab_t_metric( $aicfab_html, 4 ), 'Render A: the failed requests metric should read 4.' );
aicfab_t_check( false === strpos( $aicfab_text, 'No Bedrock requests recorded yet.' ), 'Render A: the empty state should not show.' );
$aicfab_para = preg_match( '/<p class="aicfab-card-detail aicfab-usage-failures">.*?<\/p>/s', $aicfab_html, $aicfab_match ) ? $aicfab_match[0] : '';
aicfab_t_check( '' !== $aicfab_para, 'Render A: the failure paragraph is missing.' );
aicfab_t_check( false === stripos( $aicfab_para, 'style=' ), 'Render A: the failure paragraph must not use a style attribute.' );
aicfab_t_check( 1 === preg_match( '/<div><span class="aicfab-metric">[^<]*<\/span><span class="aicfab-metric-label">failed requests<\/span><\/div>/', $aicfab_html ), 'Render A: the metric must reuse the existing markup with no extra attributes.' );

// Render A with a hostile translation: labels are escaped.
aicfab_t_reset();
aicfab_u_seed( $aicfab_busy );
$GLOBALS['aicfab_inject'] = true;
$aicfab_html              = aicfab_t_render();
$GLOBALS['aicfab_inject'] = false;
aicfab_t_check( false === stripos( $aicfab_html, '<script' ), 'Escaping: a raw script tag reached the dashboard.' );
aicfab_t_check( false !== strpos( $aicfab_html, 'hrottled &lt;script&gt;' ), 'Escaping: the hostile label should appear escaped.' );

// Render B: requests, no failures.
aicfab_t_reset();
aicfab_u_seed( array( aicfab_u_day() => array( 'requests' => 2, 'input_tokens' => 14, 'output_tokens' => 6, 'models' => array( $claude => array( 'requests' => 2, 'input_tokens' => 14, 'output_tokens' => 6 ) ) ) ) );
$aicfab_html = aicfab_t_render();
$aicfab_text = aicfab_t_text( $aicfab_html );
aicfab_t_check( false !== strpos( $aicfab_text, 'No failed chat requests recorded in the last 7 days.' ), 'Render B: missing the no-failure line.' );
aicfab_t_check( false === strpos( $aicfab_text, 'Failed chat requests, last 7 days:' ), 'Render B: no failure count should show.' );
aicfab_t_check( aicfab_t_metric( $aicfab_html, 0 ), 'Render B: the failed requests metric should read 0.' );

// Render C: no successful requests, two failures. The empty state and the failure line both show.
aicfab_t_reset();
AI_Chat_Bedrock_Usage::record_failure( 'network' );
AI_Chat_Bedrock_Usage::record_failure( 'network' );
$aicfab_html = aicfab_t_render();
$aicfab_text = aicfab_t_text( $aicfab_html );
aicfab_t_check( false !== strpos( $aicfab_text, 'No Bedrock requests recorded yet.' ), 'Render C: the empty state should still show.' );
aicfab_t_check( false !== strpos( $aicfab_text, 'Failed chat requests, last 7 days: 2. Network 2.' ), 'Render C: missing the failure line inside the empty state.' );
aicfab_t_check( aicfab_t_metric( $aicfab_html, 2 ), 'Render C: the failed requests metric should read 2.' );

if ( ! empty( $GLOBALS['aicfab_failures'] ) ) {
	foreach ( $GLOBALS['aicfab_failures'] as $aicfab_message ) {
		fwrite( STDERR, 'FAIL: ' . $aicfab_message . PHP_EOL );
	}
	fwrite( STDERR, count( $GLOBALS['aicfab_failures'] ) . ' check(s) failed.' . PHP_EOL );
	exit( 1 );
}
echo 'All final-failure counter checks passed.' . PHP_EOL;
exit( 0 );
