<?php
/** Standalone AWS payload/signing tests. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AI_CHAT_BEDROCK_AWS_ACCESS_KEY', 'AKIATESTACCESS' );
define( 'AI_CHAT_BEDROCK_AWS_SECRET_KEY', 'test-secret-value' );
define( 'AI_CHAT_BEDROCK_AWS_SESSION_TOKEN', 'test-session-token' );

$GLOBALS['aicfab_test_options'] = array(
	'aws_region' => 'us-east-1',
	'model_id' => 'anthropic.claude-3-haiku-20240307-v1:0',
	'max_tokens' => 1000,
	'temperature' => 0.4,
);
class WP_Error {
	private $code; private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}
function get_option( $name, $default = false ) { return 'ai_chat_bedrock_settings' === $name ? $GLOBALS['aicfab_test_options'] : $default; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $message ) { return $message; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $hook, $value ) { return $value; }
function wp_salt() { return 'test-salt'; }
$GLOBALS['aicfab_test_transients'] = array();
function get_transient( $key ) { return isset( $GLOBALS['aicfab_test_transients'][ $key ] ) ? $GLOBALS['aicfab_test_transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['aicfab_test_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['aicfab_test_transients'][ $key ] ); return true; }
function wp_remote_request( $url, $args = array() ) { return new WP_Error( 'aicfab_test_blocked', 'Metadata requests are blocked during tests.' ); }
function wp_remote_retrieve_response_code( $response ) { return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0; }
function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? (string) $response['body'] : ''; }
function wp_remote_retrieve_header( $response, $name ) { return ''; }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
// Queued Bedrock answers for requests that reach the runtime; each request is recorded.
$GLOBALS['aicfab_test_posts']     = array();
$GLOBALS['aicfab_test_responses'] = array();
function wp_safe_remote_post( $url, $args = array() ) {
	$GLOBALS['aicfab_test_posts'][] = array( 'url' => $url, 'args' => $args );
	return array_shift( $GLOBALS['aicfab_test_responses'] );
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws-credentials.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-event-stream.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws.php';

$aws = new AI_Chat_Bedrock_AWS();
$format = new ReflectionMethod( $aws, 'format_payload_for_model' );
$format->setAccessible( true );
$parse = new ReflectionMethod( $aws, 'parse_model_response' );
$parse->setAccessible( true );
$sign = new ReflectionMethod( $aws, 'signed_headers' );
$sign->setAccessible( true );
$failures = array();
function check_aws( $condition, $message ) { global $failures; if ( ! $condition ) { $failures[] = $message; } }

$messages = array(
	'messages' => array(
		array( 'role' => 'system', 'content' => 'System instruction.' ),
		array( 'role' => 'user', 'content' => 'Hello' ),
	),
	'tools' => array( array( 'name' => 'server___tool', 'description' => 'Tool', 'input_schema' => array( 'type' => 'object', 'properties' => new stdClass() ) ) ),
);
$claude = $format->invoke( $aws, 'anthropic.claude-3-haiku-20240307-v1:0', $messages, 800, 0.2 );
check_aws( 'System instruction.' === $claude['system'], 'Claude system prompt must use the system field.' );
check_aws( 'Hello' === $claude['messages'][0]['content'], 'Claude system prompt must not be prepended to user content.' );
check_aws( isset( $claude['tools'][0]['name'] ), 'Claude tools must be retained.' );

check_aws( isset( $claude['temperature'] ) && 0.2 === $claude['temperature'], 'Claude 3 must keep the configured temperature.' );

// Observed on Bedrock 2026-09-24: these answer "`temperature` is deprecated for this model."
foreach ( array( 'us.anthropic.claude-sonnet-5', 'us.anthropic.claude-opus-5-5', 'global.anthropic.claude-fable-5-1', 'us.anthropic.claude-opus-4-7', 'anthropic.claude-opus-4-8', 'us.anthropic.claude-some-future-model' ) as $model ) {
	$payload = $format->invoke( $aws, $model, $messages, 800, 0.2 );
	check_aws( is_array( $payload ) && ! array_key_exists( 'temperature', $payload ), $model . ' must not be sent a temperature.' );
	check_aws( is_array( $payload ) && 800 === $payload['max_tokens'] && 'Hello' === $payload['messages'][0]['content'], $model . ' must still get the rest of the Claude payload.' );
}
// And these were observed to accept it, so the setting keeps working on them.
foreach ( array( 'us.anthropic.claude-opus-4-6-v1', 'us.anthropic.claude-sonnet-4-5-20250929-v1:0', 'us.anthropic.claude-haiku-4-5-20251001-v1:0', 'anthropic.claude-sonnet-4-20250514-v1:0', 'us.anthropic.claude-3-7-sonnet-20250219-v1:0' ) as $model ) {
	$payload = $format->invoke( $aws, $model, $messages, 800, 0.2 );
	check_aws( is_array( $payload ) && isset( $payload['temperature'] ) && 0.2 === $payload['temperature'], $model . ' must keep the configured temperature.' );
}

$nova = $format->invoke( $aws, 'amazon.nova-text-v1:0', $messages, 700, 0.3 );
check_aws( 'Hello' === $nova['messages'][0]['content'][0]['text'], 'Nova messages must use content text blocks.' );
check_aws( 700 === $nova['inferenceConfig']['maxTokens'], 'Nova max token configuration is invalid.' );
check_aws( 0.3 === $nova['inferenceConfig']['temperature'], 'Nova must keep the configured temperature.' );

$parsed = $parse->invoke( $aws, array( 'content' => array( array( 'type' => 'text', 'text' => 'Working' ), array( 'type' => 'tool_use', 'id' => 'x', 'name' => 'server___tool', 'input' => array( 'q' => 'value' ) ) ) ), 'anthropic.claude-3-haiku-20240307-v1:0' );
check_aws( true === $parsed['success'] && 'Working' === $parsed['data']['message'], 'Claude text response parsing failed.' );
check_aws( 'server___tool' === $parsed['tool_calls'][0]['name'], 'Claude tool call parsing failed.' );

$deepseek = $parse->invoke( $aws, array( 'choices' => array( array( 'text' => 'DeepSeek answer', 'stop_reason' => 'stop' ) ) ), 'us.deepseek.r1-v1:0' );
check_aws( true === $deepseek['success'] && 'DeepSeek answer' === $deepseek['data']['message'], 'DeepSeek InvokeModel choices.text parsing failed.' );

$headers = $sign->invoke( $aws, 'https://bedrock-runtime.us-east-1.amazonaws.com/model/example/invoke', '{}' );
check_aws( 'test-session-token' === $headers['X-Amz-Security-Token'], 'Session token header is missing.' );
check_aws( false !== strpos( $headers['Authorization'], 'x-amz-security-token' ), 'Session token must be covered by SignedHeaders.' );
check_aws( false === strpos( $headers['Authorization'], 'test-secret-value' ), 'Authorization must not expose the secret key.' );

// Credential provider chain.
$resolved = AI_Chat_Bedrock_AWS_Credentials::resolve( $GLOBALS['aicfab_test_options'] );
check_aws( is_array( $resolved ) && 'constants' === $resolved['source'], 'Constants must take precedence over other credential sources.' );
check_aws( 'AKIATESTACCESS' === $resolved['access_key'], 'Constant access key must be used for signing.' );
$described = AI_Chat_Bedrock_AWS_Credentials::describe( $GLOBALS['aicfab_test_options'] );
check_aws( true === $described['configured'] && false === strpos( wp_json_encode( $described ), 'test-secret-value' ), 'Credential description must not leak secrets.' );
check_aws( true === AI_Chat_Bedrock_AWS_Credentials::role_credentials_enabled( array() ), 'Role credentials must be enabled by default.' );
check_aws( false === AI_Chat_Bedrock_AWS_Credentials::role_credentials_enabled( array( 'aws_use_role_credentials' => false ) ), 'Role credentials must honor the opt-out setting.' );

// Event stream framing.
function aicfab_test_frame( array $chunk, $event_type = 'chunk' ) {
	$payload = json_encode( array( 'bytes' => base64_encode( json_encode( $chunk ) ) ) );
	$headers = '';
	foreach ( array( ':event-type' => $event_type, ':message-type' => 'event' ) as $name => $value ) {
		$headers .= chr( strlen( $name ) ) . $name . chr( 7 ) . pack( 'n', strlen( $value ) ) . $value;
	}
	$total = 12 + strlen( $headers ) + strlen( $payload ) + 4;
	return pack( 'N', $total ) . pack( 'N', strlen( $headers ) ) . pack( 'N', 0 ) . $headers . $payload . pack( 'N', 0 );
}

$stream_buffer  = aicfab_test_frame( array( 'type' => 'content_block_delta', 'delta' => array( 'type' => 'text_delta', 'text' => 'Hel' ) ) );
$stream_buffer .= aicfab_test_frame( array( 'type' => 'content_block_delta', 'delta' => array( 'type' => 'text_delta', 'text' => 'lo' ) ) );
$partial        = aicfab_test_frame( array( 'type' => 'message_delta', 'usage' => array( 'input_tokens' => 11, 'output_tokens' => 5 ) ) );
$stream_buffer .= substr( $partial, 0, 10 );

$events = AI_Chat_Bedrock_Event_Stream::extract_events( $stream_buffer );
check_aws( 2 === count( $events ), 'Only complete event stream frames may be emitted.' );
check_aws( 10 === strlen( $stream_buffer ), 'Incomplete trailing frames must remain buffered.' );
check_aws( 'chunk' === $events[0]['type'], 'Event type header parsing failed.' );

$streamed = '';
foreach ( $events as $event ) {
	$streamed .= AI_Chat_Bedrock_Event_Stream::text_delta( $event['payload'], 'anthropic.claude-3-haiku-20240307-v1:0' );
}
check_aws( 'Hello' === $streamed, 'Claude streaming text deltas must concatenate in order.' );

$stream_buffer .= substr( $partial, 10 );
$final_events   = AI_Chat_Bedrock_Event_Stream::extract_events( $stream_buffer );
check_aws( 1 === count( $final_events ) && '' === $stream_buffer, 'Buffered frames must complete once the remaining bytes arrive.' );
$usage = AI_Chat_Bedrock_Event_Stream::usage( $final_events[0]['payload'] );
check_aws( 11 === $usage['input_tokens'] && 5 === $usage['output_tokens'], 'Streaming usage extraction failed.' );

$nova_usage = AI_Chat_Bedrock_Event_Stream::usage( array( 'metadata' => array( 'usage' => array( 'inputTokens' => 7, 'outputTokens' => 3 ) ) ) );
check_aws( 7 === $nova_usage['input_tokens'] && 3 === $nova_usage['output_tokens'], 'Nova metadata usage extraction failed.' );
check_aws( 'chunk text' === AI_Chat_Bedrock_Event_Stream::text_delta( array( 'contentBlockDelta' => array( 'delta' => array( 'text' => 'chunk text' ) ) ), 'amazon.nova-pro-v1:0' ), 'Nova streaming delta extraction failed.' );

$tool_start = AI_Chat_Bedrock_Event_Stream::tool_fragment( array( 'type' => 'content_block_start', 'index' => 1, 'content_block' => array( 'type' => 'tool_use', 'id' => 'tool_1', 'name' => 'server___tool' ) ), 'anthropic.claude-3-haiku-20240307-v1:0' );
check_aws( is_array( $tool_start ) && 'start' === $tool_start['stage'] && 'server___tool' === $tool_start['name'], 'Streaming tool-call start parsing failed.' );
$tool_delta = AI_Chat_Bedrock_Event_Stream::tool_fragment( array( 'type' => 'content_block_delta', 'index' => 1, 'delta' => array( 'type' => 'input_json_delta', 'partial_json' => '{"q":' ) ), 'anthropic.claude-3-haiku-20240307-v1:0' );
check_aws( is_array( $tool_delta ) && '{"q":' === $tool_delta['partial'], 'Streaming tool-call argument parsing failed.' );

$malformed = pack( 'N', 8 ) . pack( 'N', 0 ) . pack( 'N', 0 ) . 'garbage';
check_aws( array() === AI_Chat_Bedrock_Event_Stream::extract_events( $malformed ) && '' === $malformed, 'Malformed frames must be discarded without emitting events.' );

$short = "\x00\x00\x00\x08abc";
check_aws( array() === AI_Chat_Bedrock_Event_Stream::extract_events( $short ) && "\x00\x00\x00\x08abc" === $short, 'Partial preludes must be retained until more bytes arrive.' );

$oversized = str_repeat( 'x', AI_Chat_Bedrock_Event_Stream::MAX_BUFFER_BYTES + 1 );
check_aws( array() === AI_Chat_Bedrock_Event_Stream::extract_events( $oversized ) && '' === $oversized, 'Oversized stream buffers must be dropped.' );

$claude_usage = $parse->invoke( $aws, array( 'content' => array( array( 'type' => 'text', 'text' => 'Answer' ) ), 'usage' => array( 'input_tokens' => 23, 'output_tokens' => 7 ) ), 'anthropic.claude-3-haiku-20240307-v1:0' );
check_aws( isset( $claude_usage['usage']['input_tokens'] ) && 23 === $claude_usage['usage']['input_tokens'], 'Buffered responses must report token usage.' );

// SigV4 canonical path must encode the already-encoded request path a second time.
$canonical = new ReflectionMethod( $aws, 'canonical_uri' );
$canonical->setAccessible( true );
$encoded_path = '/model/' . rawurlencode( 'us.anthropic.claude-haiku-4-5-20251001-v1:0' ) . '/invoke';
check_aws( '/model/us.anthropic.claude-haiku-4-5-20251001-v1%253A0/invoke' === $canonical->invoke( $aws, $encoded_path ), 'Canonical URI must double-encode percent-encoded model identifiers.' );
check_aws( '/foundation-models' === $canonical->invoke( $aws, '/foundation-models' ), 'Canonical URI must leave plain control-plane paths unchanged.' );

$arn_path = '/model/' . rawurlencode( 'arn:aws:bedrock:us-east-1:1234:provisioned-model/abc' ) . '/invoke';
check_aws( false === strpos( $canonical->invoke( $aws, $arn_path ), 'arn:aws' ), 'Model ARNs must stay percent-encoded in the canonical URI.' );

// Guardrail headers are optional, validated and covered by the signature.
$guard_method = new ReflectionMethod( $aws, 'guardrail_headers' );
$guard_method->setAccessible( true );
check_aws( array() === $guard_method->invoke( $aws ), 'No guardrail headers are sent when none is configured.' );

$GLOBALS['aicfab_test_options']['guardrail_id']      = 'gr-abc123';
$GLOBALS['aicfab_test_options']['guardrail_version'] = '2';
$guarded = new AI_Chat_Bedrock_AWS();
$guard_method2 = new ReflectionMethod( $guarded, 'guardrail_headers' );
$guard_method2->setAccessible( true );
$extra_headers = $guard_method2->invoke( $guarded );
check_aws( 'gr-abc123' === $extra_headers['x-amzn-bedrock-guardrailidentifier'], 'Guardrail identifier must be forwarded.' );

$sign2 = new ReflectionMethod( $guarded, 'signed_headers' );
$sign2->setAccessible( true );
$guarded_headers = $sign2->invoke( $guarded, 'https://bedrock-runtime.us-east-1.amazonaws.com/model/example/invoke', '{}', 'POST', 'bedrock', $extra_headers );
check_aws( false !== strpos( $guarded_headers['Authorization'], 'x-amzn-bedrock-guardrailidentifier' ), 'Guardrail headers must be covered by SignedHeaders.' );
check_aws( '2' === $guarded_headers['x-amzn-bedrock-guardrailversion'], 'Guardrail version header must be sent.' );

$GLOBALS['aicfab_test_options']['guardrail_id'] = 'invalid id';
$rejected = new AI_Chat_Bedrock_AWS();
$guard_method3 = new ReflectionMethod( $rejected, 'guardrail_headers' );
$guard_method3->setAccessible( true );
check_aws( array() === $guard_method3->invoke( $rejected ), 'Invalid guardrail identifiers must be rejected.' );
unset( $GLOBALS['aicfab_test_options']['guardrail_id'], $GLOBALS['aicfab_test_options']['guardrail_version'] );

// GET signing for the control plane must include the sorted canonical query string.
$get_headers = $sign->invoke( $aws, 'https://bedrock.us-east-1.amazonaws.com/foundation-models?byOutputModality=TEXT', '', 'GET' );
check_aws( isset( $get_headers['Authorization'] ) && false !== strpos( $get_headers['Authorization'], 'SignedHeaders=' ), 'Control-plane GET requests must be signed.' );
$query_method = new ReflectionMethod( $aws, 'canonical_query' );
$query_method->setAccessible( true );
check_aws( 'a=1&b=2' === $query_method->invoke( $aws, 'b=2&a=1' ), 'Canonical query parameters must be sorted.' );
check_aws( '' === $query_method->invoke( $aws, '' ), 'Empty query strings must produce an empty canonical query.' );

// Fallback eligibility: only access, throttling and service faults may be retried.
$should = new ReflectionMethod( $aws, 'should_fall_back' );
$should->setAccessible( true );
$case = function ( $code, $status ) use ( $should, $aws ) {
	return (bool) $should->invoke( $aws, array( 'success' => false, 'data' => array( 'code' => $code, 'status' => $status ) ) );
};
check_aws( true === $case( 'aicfab_http_error', 403 ), 'HTTP 403 is retried on the fallback model' );
check_aws( true === $case( 'aicfab_http_error', 429 ), 'throttling is retried on the fallback model' );
check_aws( true === $case( 'aicfab_http_error', 503 ), 'service faults are retried on the fallback model' );
check_aws( true === $case( 'aicfab_unreachable', 0 ), 'an unreachable endpoint is retried on the fallback model' );
check_aws( false === $case( 'aicfab_http_error', 400 ), 'a rejected payload is not retried' );
check_aws( true === (bool) $should->invoke( $aws, array( 'success' => false, 'data' => array( 'code' => 'aicfab_http_error', 'status' => 400, 'model_error' => true ) ) ), 'a 400 naming the model is retried' );
check_aws( true === AI_Chat_Bedrock_AWS::is_model_unavailable( '{"message":"The provided model identifier is invalid."}' ), 'an invalid model identifier is recognized' );
check_aws( true === AI_Chat_Bedrock_AWS::is_model_unavailable( '{"__type":"ResourceNotFoundException","message":"Could not resolve the model"}' ), 'a missing model resource is recognized' );
check_aws( true === AI_Chat_Bedrock_AWS::is_model_unavailable( '{"message":"You do not have access to the model with the specified model ID."}' ), 'a model the account cannot use is recognized' );
check_aws( false === AI_Chat_Bedrock_AWS::is_model_unavailable( '{"message":"malformed input request: expected type string"}' ), 'a payload complaint is not treated as a model problem' );
check_aws( false === AI_Chat_Bedrock_AWS::is_model_unavailable( '' ), 'an empty body is not treated as a model problem' );
check_aws( false === $case( 'aicfab_daily_limit', 0 ), 'the daily limit is not retried' );
check_aws( false === $case( 'aicfab_no_credentials', 0 ), 'missing credentials are not retried' );
check_aws( false === $case( 'aicfab_invalid_model', 0 ), 'an invalid model is not retried' );
check_aws( false === (bool) $should->invoke( $aws, array( 'success' => true, 'data' => array( 'message' => 'fine' ) ) ), 'a successful answer is never retried' );

// A fallback must be valid, known and different from the primary model.
$resolve = new ReflectionMethod( $aws, 'fallback_model_id' );
$resolve->setAccessible( true );
$GLOBALS['aicfab_test_options']['fallback_model_id'] = 'anthropic.claude-3-haiku-20240307-v1:0';
check_aws( 'anthropic.claude-3-haiku-20240307-v1:0' === $resolve->invoke( $aws, 'us.amazon.nova-pro-v1:0' ), 'a configured fallback is resolved' );
check_aws( '' === $resolve->invoke( $aws, 'anthropic.claude-3-haiku-20240307-v1:0' ), 'the fallback is refused when it equals the primary model' );
$GLOBALS['aicfab_test_options']['fallback_model_id'] = 'not a model!!';
check_aws( '' === $resolve->invoke( $aws, 'us.amazon.nova-pro-v1:0' ), 'a malformed fallback identifier is refused' );
$GLOBALS['aicfab_test_options']['fallback_model_id'] = '';
check_aws( '' === $resolve->invoke( $aws, 'us.amazon.nova-pro-v1:0' ), 'no fallback means no retry' );

// A trailing slash belongs to the resource path; GetPrompt signs it.
$canonical->setAccessible( true );
check_aws( '/prompts/ABC123/' === $canonical->invoke( $aws, '/prompts/ABC123/' ), 'a trailing slash is preserved when signing' );
check_aws( '/prompts/ABC123' === $canonical->invoke( $aws, '/prompts/ABC123' ), 'a path without a trailing slash is unchanged' );
check_aws( '/' === $canonical->invoke( $aws, '/' ), 'the root path signs as a single slash' );
check_aws( '/' === $canonical->invoke( $aws, '' ), 'an empty path signs as the root' );
check_aws( '/foundation-models' === $canonical->invoke( $aws, '/foundation-models' ), 'existing control plane paths are unaffected' );

/*
 * Several frames arriving in one read must all be parsed. The loop caches the buffer
 * length, so it has to shrink that cache as it consumes frames; if it does not, the
 * second frame is either skipped or the loop never ends.
 */
$build_frame = function ( $event_type, array $payload ) {
	$body    = wp_json_encode( $payload );
	$headers = '';
	foreach ( array( ':event-type' => $event_type, ':message-type' => 'event' ) as $name => $value ) {
		$headers .= chr( strlen( $name ) ) . $name . chr( 7 ) . pack( 'n', strlen( $value ) ) . $value;
	}
	$total = 16 + strlen( $headers ) + strlen( $body );
	return pack( 'NN', $total, strlen( $headers ) ) . pack( 'N', 0 ) . $headers . $body . pack( 'N', 0 );
};

$multi = $build_frame( 'chunk', array( 'bytes' => base64_encode( wp_json_encode( array( 'type' => 'content_block_delta', 'delta' => array( 'type' => 'text_delta', 'text' => 'one' ) ) ) ) ) )
	. $build_frame( 'chunk', array( 'bytes' => base64_encode( wp_json_encode( array( 'type' => 'content_block_delta', 'delta' => array( 'type' => 'text_delta', 'text' => 'two' ) ) ) ) ) )
	. $build_frame( 'chunk', array( 'bytes' => base64_encode( wp_json_encode( array( 'type' => 'content_block_delta', 'delta' => array( 'type' => 'text_delta', 'text' => 'three' ) ) ) ) ) );

$multi_buffer = $multi;
$multi_events = AI_Chat_Bedrock_Event_Stream::extract_events( $multi_buffer );
check_aws( 3 === count( $multi_events ), 'three frames in one read produce three events, got ' . count( $multi_events ) );

$deltas = '';
foreach ( $multi_events as $event ) {
	$deltas .= AI_Chat_Bedrock_Event_Stream::text_delta( $event['payload'], 'anthropic.claude-3-haiku-20240307-v1:0' );
}
check_aws( 'onetwothree' === $deltas, 'every frame contributes its delta, got ' . $deltas );
check_aws( '' === $multi_buffer, 'a fully consumed buffer is emptied' );

// A trailing partial frame must be kept for the next read rather than parsed or dropped.
$partial_buffer = $multi . substr( $build_frame( 'chunk', array( 'bytes' => 'x' ) ), 0, 10 );
$partial_events = AI_Chat_Bedrock_Event_Stream::extract_events( $partial_buffer );
check_aws( 3 === count( $partial_events ), 'a trailing partial frame is not parsed early' );
check_aws( 10 === strlen( $partial_buffer ), 'the partial frame stays in the buffer, got ' . strlen( $partial_buffer ) );

// --- A consumer can stop a stream --------------------------------------------

// Returning false from the delta callback means "stop". The chain that carries that answer
// back is easy to break by ignoring a return value, which would leave a visitor who closed
// the tab still paying for the rest of the answer.
$aicfab_stream_source = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-aws.php' );

check_aws(
	false !== strpos( $aicfab_stream_source, 'if ( false === call_user_func( $on_delta, $delta ) ) {' ),
	'the write callback stops when the consumer returns false'
);
check_aws(
	false !== strpos( $aicfab_stream_source, "\$state['stopped'] = true;" ),
	'a deliberate stop is recorded'
);
// Returning 0 from a cURL write callback is what tears the transfer down.
$aicfab_stop_block = substr(
	$aicfab_stream_source,
	strpos( $aicfab_stream_source, 'if ( false === call_user_func( $on_delta, $delta ) ) {' ),
	160
);
check_aws(
	false !== strpos( $aicfab_stop_block, 'return 0;' ),
	'stopping returns 0 so the transfer is torn down rather than merely ignored'
);
check_aws(
	false !== strpos( $aicfab_stream_source, 'return call_user_func( $on_delta, $delta );' ),
	'the observer forwards the consumer answer instead of swallowing it'
);
check_aws(
	false !== strpos( $aicfab_stream_source, "if ( ! \$stopped && false === \$completed" ),
	'a deliberate stop is not reported as an interrupted stream'
);
check_aws(
	false !== strpos( $aicfab_stream_source, "\$result['stopped'] = true;" ),
	'the result says the answer is partial by design'
);

$aicfab_route_source = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-stream.php' );
check_aws(
	false !== strpos( $aicfab_route_source, 'connection_aborted()' ),
	'the streaming route notices when the visitor has gone'
);
$aicfab_emit_block = substr(
	$aicfab_route_source,
	strpos( $aicfab_route_source, 'connection_aborted()' ),
	120
);
check_aws(
	false !== strpos( $aicfab_emit_block, 'return false;' ),
	'noticing the visitor has gone asks the Bedrock stream to stop'
);


// --- Converse for every family without a native payload ------------------------

// Observed on Bedrock 2026-09-25: gpt-oss and Qwen3 answer the old prompt/max_tokens body
// with "missing field `messages`", and DeepSeek R1 wrote both sides of the conversation.
foreach ( array( 'anthropic.claude-3-haiku-20240307-v1:0', 'us.anthropic.claude-sonnet-5', 'amazon.nova-lite-v1:0', 'us.amazon.nova-pro-v1:0', 'amazon.titan-text-express-v1' ) as $model ) {
	check_aws( 'invoke' === AI_Chat_Bedrock_AWS::chat_api( $model ), $model . ' keeps its native InvokeModel payload.' );
}
foreach ( array( 'openai.gpt-oss-20b-1:0', 'qwen.qwen3-32b-v1:0', 'us.deepseek.r1-v1:0', 'meta.llama3-8b-instruct-v1:0', 'us.meta.llama4-maverick-17b-instruct-v1:0', 'mistral.mistral-large-2402-v1:0', 'global.moonshotai.kimi-k3', 'some.future-model-v1:0' ) as $model ) {
	check_aws( 'converse' === AI_Chat_Bedrock_AWS::chat_api( $model ), $model . ' goes through Converse.' );
}

$history = array(
	'messages' => array(
		array( 'role' => 'system', 'content' => 'System instruction.' ),
		array( 'role' => 'user', 'content' => 'First question' ),
		array( 'role' => 'assistant', 'content' => 'First answer' ),
		array( 'role' => 'user', 'content' => 'Hello' ),
	),
);
$converse = $format->invoke( $aws, 'openai.gpt-oss-20b-1:0', $history, 600, 0.3 );
check_aws( array( array( 'text' => 'System instruction.' ) ) === $converse['system'], 'Converse carries the system prompt as a system block.' );
check_aws( 3 === count( $converse['messages'] ) && 'user' === $converse['messages'][0]['role'] && 'assistant' === $converse['messages'][1]['role'], 'Converse keeps the conversation turns in order.' );
check_aws( 'Hello' === $converse['messages'][2]['content'][0]['text'], 'Converse messages use content text blocks.' );
check_aws( 600 === $converse['inferenceConfig']['maxTokens'] && 0.3 === $converse['inferenceConfig']['temperature'], 'Converse gets the configured limits.' );
check_aws( ! isset( $converse['prompt'] ) && ! isset( $converse['max_tokens'] ) && ! isset( $converse['guardrailConfig'] ), 'Converse sends no legacy fields and no guardrail when none is set.' );

// Mistral 7B Instruct refuses a system block; the instructions lead the first user turn.
$mistral = $format->invoke( $aws, 'mistral.mistral-7b-instruct-v0:2', $history, 600, 0.3 );
check_aws( ! isset( $mistral['system'] ), 'Mistral 7B is not sent a system block.' );
check_aws( 0 === strpos( $mistral['messages'][0]['content'][0]['text'], 'System instruction.' ) && false !== strpos( $mistral['messages'][0]['content'][0]['text'], 'First question' ), 'Mistral 7B still gets the instructions, ahead of the first question.' );

// Converse takes the guardrail in the body and ignores the InvokeModel headers.
$GLOBALS['aicfab_test_options']['guardrail_id']      = 'gr-abc123';
$GLOBALS['aicfab_test_options']['guardrail_version'] = 'DRAFT';
$guarded_converse = new AI_Chat_Bedrock_AWS();
$guarded_payload  = $format->invoke( $guarded_converse, 'qwen.qwen3-32b-v1:0', $history, 600, 0.3 );
check_aws( isset( $guarded_payload['guardrailConfig'] ) && array( 'guardrailIdentifier' => 'gr-abc123', 'guardrailVersion' => 'DRAFT' ) === $guarded_payload['guardrailConfig'], 'Converse requests carry the configured guardrail in the body.' );
unset( $GLOBALS['aicfab_test_options']['guardrail_id'], $GLOBALS['aicfab_test_options']['guardrail_version'] );

$converse_answer = $parse->invoke( $aws, array( 'output' => array( 'message' => array( 'role' => 'assistant', 'content' => array( array( 'reasoningContent' => array( 'reasoningText' => array( 'text' => 'thinking' ) ) ), array( 'text' => 'Converse answer' ) ) ) ), 'usage' => array( 'inputTokens' => 9, 'outputTokens' => 4 ) ), 'openai.gpt-oss-20b-1:0' );
check_aws( true === $converse_answer['success'] && 'Converse answer' === $converse_answer['data']['message'], 'A Converse answer is read without its reasoning block.' );

check_aws( 'streamed' === AI_Chat_Bedrock_Event_Stream::text_delta( array( 'contentBlockDelta' => array( 'contentBlockIndex' => 1, 'delta' => array( 'text' => 'streamed' ) ) ), 'qwen.qwen3-32b-v1:0' ), 'ConverseStream text deltas are read.' );
check_aws( '' === AI_Chat_Bedrock_Event_Stream::text_delta( array( 'contentBlockDelta' => array( 'delta' => array( 'reasoningContent' => array( 'text' => 'thinking' ) ) ) ), 'openai.gpt-oss-20b-1:0' ), 'ConverseStream reasoning deltas are not shown to visitors.' );

// A model that refuses a field is asked once more without it, and remembered.
// Observed on Bedrock 2026-09-25 from GPT-6 Astra, GPT-5.6, Grok 4.6 and Kimi K3.
$invoke = new ReflectionMethod( $aws, 'invoke_model' );
$invoke->setAccessible( true );
$GLOBALS['aicfab_test_posts']     = array();
$GLOBALS['aicfab_test_responses'] = array(
	array( 'response' => array( 'code' => 400 ), 'body' => '{"message":"This model doesn\'t support the temperature field. Remove temperature and try again."}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"output":{"message":{"role":"assistant","content":[{"text":"Adapted answer"}]}},"usage":{"inputTokens":5,"outputTokens":2}}' ),
);
$refusing = 'global.openai.gpt-6-astra';
$adapted  = $invoke->invoke( $aws, $format->invoke( $aws, $refusing, $history, 600, 0.3 ), $refusing, 'converse' );
check_aws( true === $adapted['success'] && 'Adapted answer' === $adapted['data']['message'], 'A refused temperature is dropped and the question answered.' );
check_aws( 2 === count( $GLOBALS['aicfab_test_posts'] ), 'The refusal costs exactly one extra request.' );
check_aws( false !== strpos( $GLOBALS['aicfab_test_posts'][0]['url'], '/converse' ) && false === strpos( $GLOBALS['aicfab_test_posts'][0]['url'], '/invoke' ), 'Converse models are sent to the Converse endpoint.' );
$retried = json_decode( $GLOBALS['aicfab_test_posts'][1]['args']['body'], true );
check_aws( ! isset( $retried['inferenceConfig']['temperature'] ) && 600 === $retried['inferenceConfig']['maxTokens'], 'The retry leaves out only the refused field.' );
check_aws( false === strpos( wp_json_encode( $GLOBALS['aicfab_test_posts'][0]['args']['headers'] ), 'guardrail' ), 'Converse requests carry no guardrail headers.' );
$next = $format->invoke( $aws, $refusing, $history, 600, 0.3 );
check_aws( ! isset( $next['inferenceConfig']['temperature'] ), 'A learned refusal shapes the next request up front.' );
$other = $format->invoke( $aws, 'qwen.qwen3-32b-v1:0', $history, 600, 0.3 );
check_aws( 0.3 === $other['inferenceConfig']['temperature'], 'One model refusing a field does not change other models.' );

$GLOBALS['aicfab_test_posts']     = array();
$GLOBALS['aicfab_test_responses'] = array(
	array( 'response' => array( 'code' => 400 ), 'body' => '{"message":"This model doesn\'t support system messages."}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{"output":{"message":{"role":"assistant","content":[{"text":"Folded"}]}}}' ),
);
$folded = $invoke->invoke( $aws, $format->invoke( $aws, 'mistral.some-new-instruct', $history, 600, 0.3 ), 'mistral.some-new-instruct', 'converse' );
$folded_body = json_decode( $GLOBALS['aicfab_test_posts'][1]['args']['body'], true );
check_aws( true === $folded['success'] && ! isset( $folded_body['system'] ) && 0 === strpos( $folded_body['messages'][0]['content'][0]['text'], 'System instruction.' ), 'A refused system block is folded into the first question.' );

// A 400 the plugin cannot fix is reported once, not retried.
if ( ! class_exists( 'AI_Chat_Bedrock_Bedrock_Errors' ) ) {
	require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-bedrock-errors.php';
}
$GLOBALS['aicfab_test_posts']     = array();
$GLOBALS['aicfab_test_responses'] = array(
	array( 'response' => array( 'code' => 400 ), 'body' => '{"message":"Malformed input request"}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => '{}' ),
);
$unfixable = $invoke->invoke( $aws, $format->invoke( $aws, 'qwen.qwen3-32b-v1:0', $history, 600, 0.3 ), 'qwen.qwen3-32b-v1:0', 'converse' );
check_aws( false === $unfixable['success'] && 1 === count( $GLOBALS['aicfab_test_posts'] ), 'An unrelated 400 is not retried.' );
$GLOBALS['aicfab_test_responses'] = array();

// The streaming path adapts the same way, before any text has been shown.
check_aws( false !== strpos( $aicfab_stream_source, "'/converse-stream'" ), 'Converse models stream from ConverseStream.' );
check_aws( false !== strpos( $aicfab_stream_source, "self::adapt_refused_converse( \$prepared['payload'], \$model_id, \$state['raw'] )" ), 'A refused streaming request is adapted from the captured error body.' );


// Image input must produce a Claude content block alongside the text.
$image_payload = $format->invoke(
	$aws,
	'anthropic.claude-3-5-haiku-20241022-v1:0',
	array(
		'messages' => array( array( 'role' => 'user', 'content' => 'Describe this' ) ),
		'image'    => array( 'media_type' => 'image/jpeg', 'data' => 'QUJD' ),
	),
	500,
	0.2
);
$image_content = $image_payload['messages'][ count( $image_payload['messages'] ) - 1 ]['content'];
check_aws( is_array( $image_content ) && 2 === count( $image_content ), 'image input yields two content blocks' );
check_aws( 'image' === $image_content[0]['type'] && 'base64' === $image_content[0]['source']['type'], 'the image block uses base64 source' );
check_aws( 'image/jpeg' === $image_content[0]['source']['media_type'] && 'QUJD' === $image_content[0]['source']['data'], 'the image block carries the supplied bytes' );
check_aws( 'text' === $image_content[1]['type'] && 'Describe this' === $image_content[1]['text'], 'the prompt text follows the image' );

// Unsupported media types must be ignored rather than sent.
$rejected = $format->invoke(
	$aws,
	'anthropic.claude-3-5-haiku-20241022-v1:0',
	array(
		'messages' => array( array( 'role' => 'user', 'content' => 'Describe this' ) ),
		'image'    => array( 'media_type' => 'application/pdf', 'data' => 'QUJD' ),
	),
	500,
	0.2
);
check_aws( is_string( $rejected['messages'][ count( $rejected['messages'] ) - 1 ]['content'] ), 'unsupported media types are dropped' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "OK: AWS payload, response, and signing checks passed\n";
