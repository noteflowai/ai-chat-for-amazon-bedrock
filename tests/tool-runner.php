<?php
/**
 * Standalone tests for the multi-round tool runner.
 *
 * Run: php tests/tool-runner.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_rounds']   = 3;
$GLOBALS['aicfab_replies']  = array();
$GLOBALS['aicfab_payloads'] = array();
$GLOBALS['aicfab_calls']    = array();
$GLOBALS['aicfab_notices']  = array();

// --- WordPress stubs -------------------------------------------------------

function apply_filters( $hook, $value ) {
	$args = func_get_args();
	if ( 'ai_chat_bedrock_process_response' === $hook && isset( $GLOBALS['aicfab_calls'][0] ) ) {
		$value['tool_calls'] = array_shift( $GLOBALS['aicfab_calls'] );
	}
	return $value;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
}
function wp_list_pluck( $list, $field ) {
	return array_map(
		static function ( $item ) use ( $field ) {
			return is_array( $item ) && isset( $item[ $field ] ) ? $item[ $field ] : null;
		},
		(array) $list
	);
}
function __( $text, $domain = null ) {
	return $text;
}

class AI_Chat_Bedrock_Security {
	public static function string_substr( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length ) : substr( (string) $value, $start, $length );
	}
}

class AI_Chat_Bedrock_Tool_Policy {
	public static function max_rounds() {
		return (int) $GLOBALS['aicfab_rounds'];
	}
}

class AI_Chat_Bedrock_Chat_Request {
	public static function tool_followup_messages( $messages, $calls ) {
		$messages[] = array( 'role' => 'assistant', 'content' => 'tool round' );
		return $messages;
	}
}

class Stub_AWS {
	public function handle_chat_message( $payload ) {
		$GLOBALS['aicfab_payloads'][] = $payload;
		$reply                        = array_shift( $GLOBALS['aicfab_replies'] );
		return is_array( $reply ) ? $reply : array( 'success' => true, 'data' => array( 'message' => 'done' ) );
	}
	public function stream_chat_message( $payload, $on_delta ) {
		return $this->handle_chat_message( $payload );
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-tool-runner.php';

class AI_Chat_Bedrock_Tool_Runner_Labels {
	public static function read( $name ) {
		$method = new ReflectionMethod( 'AI_Chat_Bedrock_Tool_Runner', 'readable_tool' );
		$method->setAccessible( true );
		return $method->invoke( null, $name );
	}
}

$failures = array();
function check_runner( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function reset_state( $rounds = 3 ) {
	$GLOBALS['aicfab_rounds']   = $rounds;
	$GLOBALS['aicfab_replies']  = array();
	$GLOBALS['aicfab_payloads'] = array();
	$GLOBALS['aicfab_calls']    = array();
	$GLOBALS['aicfab_notices']  = array();
}

// --- A single successful tool round ---------------------------------------

reset_state( 3 );
$GLOBALS['aicfab_replies'] = array(
	array( 'success' => true, 'data' => array( 'message' => '' ), 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 4 ) ),
	array( 'success' => true, 'data' => array( 'message' => 'Refunds take 21 days.' ), 'usage' => array( 'input_tokens' => 30, 'output_tokens' => 12 ) ),
);
$GLOBALS['aicfab_calls'] = array(
	array(
		array( 'id' => 'a1', 'name' => 'wordpress___search_content', 'parameters' => array( 'query' => 'refund' ), 'result' => 'secret site data' ),
	),
);

$observed = array();
$response = AI_Chat_Bedrock_Tool_Runner::run(
	new Stub_AWS(),
	array( array( 'role' => 'user', 'content' => 'refund policy?' ) ),
	'refund policy?',
	null,
	function ( $round, $count, $names = array(), $labels = array() ) use ( &$observed ) {
		$observed[] = array( 'round' => $round, 'count' => $count, 'names' => $names, 'labels' => $labels );
	}
);

check_runner( ! empty( $response['success'] ), 'A tool round completes successfully.' );
check_runner( 'Refunds take 21 days.' === $response['data']['message'], 'The final answer is returned.' );
check_runner( isset( $response['steps'] ) && 1 === count( $response['steps'] ), 'One step is recorded for one tool call.' );
check_runner( 'wordpress___search_content' === $response['steps'][0]['tool'], 'The step records the tool name.' );
check_runner( 'ok' === $response['steps'][0]['status'], 'A successful call is marked ok.' );
check_runner( 1 === $response['steps'][0]['round'], 'The step records its round.' );
check_runner( '' === $response['steps'][0]['code'], 'A successful call has no error code.' );
check_runner( empty( $response['steps_truncated'] ), 'A run inside the limit is not marked truncated.' );

// Usage is accumulated across rounds.
check_runner( 40 === (int) $response['usage']['input_tokens'], 'Input tokens accumulate across rounds.' );
check_runner( 16 === (int) $response['usage']['output_tokens'], 'Output tokens accumulate across rounds.' );

// The round callback reports names.
check_runner( 1 === count( $observed ), 'The round callback fires once per tool round.' );
check_runner( array( 'wordpress___search_content' ) === $observed[0]['names'], 'The callback receives tool names.' );
check_runner( array( 'wordpress: search content' ) === $observed[0]['labels'], 'The callback receives readable labels.' );
check_runner( 1 === $observed[0]['count'], 'The callback receives the tool count.' );

// Steps must never carry parameter values or tool output.
$encoded = wp_json_encode_compat( $response['steps'] );
check_runner( false === strpos( $encoded, 'secret site data' ), 'Tool output never reaches the step trace.' );
check_runner( false === strpos( $encoded, 'refund' ), 'Parameter values never reach the step trace.' );
check_runner( array( 'round', 'tool', 'label', 'status', 'code' ) === array_keys( $response['steps'][0] ), 'Steps expose only metadata keys.' );
check_runner( 'core/get-site-info' === AI_Chat_Bedrock_Tool_Runner_Labels::read( 'wpability___core__get_site_info' ), 'Ability tools get a readable label.' );
check_runner( 'wpsite: search posts' === AI_Chat_Bedrock_Tool_Runner_Labels::read( 'wpsite___search_posts' ), 'MCP tools get a readable label.' );
check_runner( 'plain' === AI_Chat_Bedrock_Tool_Runner_Labels::read( 'plain' ), 'A name without an owner is left alone.' );

// --- A failing tool call ---------------------------------------------------

reset_state( 3 );
$GLOBALS['aicfab_replies'] = array(
	array( 'success' => true, 'data' => array( 'message' => '' ) ),
	array( 'success' => true, 'data' => array( 'message' => 'I could not check that.' ) ),
);
$GLOBALS['aicfab_calls'] = array(
	array(
		array( 'id' => 'b1', 'name' => 'remote___create_order', 'error' => array( 'code' => 'tool_not_allowed', 'message' => 'Blocked by policy' ) ),
	),
);

$response = AI_Chat_Bedrock_Tool_Runner::run( new Stub_AWS(), array(), 'place an order', null, null );
check_runner( 'error' === $response['steps'][0]['status'], 'A blocked call is marked as an error.' );
check_runner( 'tool_not_allowed' === $response['steps'][0]['code'], 'The error code is preserved.' );
check_runner( false === strpos( wp_json_encode_compat( $response['steps'] ), 'Blocked by policy' ), 'Error messages are not copied into the trace.' );

// --- The round limit ------------------------------------------------------

reset_state( 2 );
$GLOBALS['aicfab_replies'] = array(
	array( 'success' => true, 'data' => array( 'message' => '' ) ),
	array( 'success' => true, 'data' => array( 'message' => '' ) ),
	array( 'success' => true, 'data' => array( 'message' => 'Partial answer.' ) ),
);
$GLOBALS['aicfab_calls'] = array(
	array( array( 'id' => 'c1', 'name' => 'wordpress___search_content' ) ),
	array( array( 'id' => 'c2', 'name' => 'wordpress___get_post' ) ),
);

$response = AI_Chat_Bedrock_Tool_Runner::run( new Stub_AWS(), array(), 'deep question', null, null );
check_runner( 2 === count( $response['steps'] ), 'Every round contributes a step.' );
check_runner( 2 === $response['steps'][1]['round'], 'Later steps record a later round.' );
check_runner( ! empty( $response['steps_truncated'] ), 'Hitting the round limit is reported.' );

$last = end( $GLOBALS['aicfab_payloads'] );
check_runner( ! isset( $last['tools'] ), 'The final round offers no further tools.' );

// --- No tools at all ------------------------------------------------------

reset_state( 3 );
$GLOBALS['aicfab_replies'] = array( array( 'success' => true, 'data' => array( 'message' => 'Simple answer.' ) ) );
$response                  = AI_Chat_Bedrock_Tool_Runner::run( new Stub_AWS(), array(), 'hello', null, null );
check_runner( ! isset( $response['steps'] ), 'A plain answer carries no step trace.' );
check_runner( 'Simple answer.' === $response['data']['message'], 'A plain answer is returned unchanged.' );

// --- A failed request -----------------------------------------------------

reset_state( 3 );
$GLOBALS['aicfab_replies'] = array( array( 'success' => false, 'data' => array( 'message' => 'Bedrock refused', 'code' => 'aicfab_error' ) ) );
$response                  = AI_Chat_Bedrock_Tool_Runner::run( new Stub_AWS(), array(), 'hello', null, null );
check_runner( empty( $response['success'] ), 'A failed request stays failed.' );
check_runner( ! isset( $response['steps'] ), 'A failed request has no steps.' );

function wp_json_encode_compat( $value ) {
	return (string) json_encode( $value );
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: tool runner step trace and round limit checks passed\n";
