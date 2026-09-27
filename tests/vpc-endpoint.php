<?php
/**
 * Standalone tests: requests to AWS still work when an interface VPC endpoint with private DNS
 * makes the service's name resolve to a private address, and nothing else gets through.
 *
 * The HTTP stubs refuse private addresses the way wp_http_validate_url() does, including its
 * http_request_host_is_external filter, against a fake DNS table.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AI_CHAT_BEDROCK_AWS_ACCESS_KEY', 'AKIATESTACCESS' );
define( 'AI_CHAT_BEDROCK_AWS_SECRET_KEY', 'test-secret-value' );

$GLOBALS['aicfab_test_options'] = array(
	'aws_region' => 'ap-northeast-1',
	'model_id'   => 'global.openai.gpt-6-luna',
	'max_tokens' => 500,
);
class WP_Error {
	private $code; private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
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
function wp_salt() { return 'test-salt'; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }
function delete_transient( $key ) { return true; }
function wp_remote_request( $url, $args = array() ) { return new WP_Error( 'aicfab_test_blocked', 'Metadata requests are blocked during tests.' ); }
function wp_remote_retrieve_response_code( $response ) { return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0; }
function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? (string) $response['body'] : ''; }
function wp_remote_retrieve_header( $response, $name ) { return ''; }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

// A hook registry just big enough for add_filter(), remove_filter() and apply_filters().
$GLOBALS['aicfab_test_filters'] = array();
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['aicfab_test_filters'][ $hook ][] = array( $callback, $accepted_args );
	return true;
}
function remove_filter( $hook, $callback, $priority = 10 ) {
	foreach ( isset( $GLOBALS['aicfab_test_filters'][ $hook ] ) ? $GLOBALS['aicfab_test_filters'][ $hook ] : array() as $i => $entry ) {
		if ( $entry[0] === $callback ) {
			unset( $GLOBALS['aicfab_test_filters'][ $hook ][ $i ] );
			return true;
		}
	}
	return false;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( isset( $GLOBALS['aicfab_test_filters'][ $hook ] ) ? $GLOBALS['aicfab_test_filters'][ $hook ] : array() as $entry ) {
		$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $args ), 0, $entry[1] ) );
	}
	return $value;
}

// What the VPC's resolver answers, and what wp_http_validate_url() makes of it.
$GLOBALS['aicfab_test_dns'] = array(
	'bedrock.ap-northeast-1.amazonaws.com'         => '52.119.1.1',    // no endpoint for the control plane
	'bedrock-runtime.ap-northeast-1.amazonaws.com' => '172.31.37.12',  // the interface endpoint
	'vpce-0abc-1234.bedrock-runtime.ap-northeast-1.vpce.amazonaws.com' => '172.31.5.9',
	'sts.ap-northeast-1.amazonaws.com'             => '10.0.4.4',
	'metadata.internal.example'                    => '169.254.169.254',
	'bedrock-runtime.ap-northeast-1.amazonaws.com.evil.example' => '10.9.9.9',
);
function aicfab_test_validate( $url ) {
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	$ip   = isset( $GLOBALS['aicfab_test_dns'][ $host ] ) ? $GLOBALS['aicfab_test_dns'][ $host ] : '';
	$p    = array_map( 'intval', explode( '.', $ip ) );
	$private = '' !== $ip && ( 10 === $p[0] || 127 === $p[0] || ( 172 === $p[0] && $p[1] >= 16 && $p[1] <= 31 ) || ( 192 === $p[0] && 168 === $p[1] ) || ( 169 === $p[0] && 254 === $p[1] ) );
	return ! $private || apply_filters( 'http_request_host_is_external', false, $host, $url );
}
$GLOBALS['aicfab_test_sent']      = array();
$GLOBALS['aicfab_test_responses'] = array();
function aicfab_test_send( $method, $url, $args ) {
	if ( empty( $args['reject_unsafe_urls'] ) ) {
		return new WP_Error( 'aicfab_test_unsafe', 'The plugin must keep reject_unsafe_urls on.' );
	}
	if ( ! aicfab_test_validate( $url ) ) {
		return new WP_Error( 'http_request_failed', 'A valid URL was not provided.' );
	}
	$GLOBALS['aicfab_test_sent'][] = array( 'method' => $method, 'url' => $url );
	$next = array_shift( $GLOBALS['aicfab_test_responses'] );
	return $next ? $next : array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
}
function wp_safe_remote_get( $url, $args = array() ) { return aicfab_test_send( 'GET', $url, $args ); }
function wp_safe_remote_post( $url, $args = array() ) { return aicfab_test_send( 'POST', $url, $args ); }

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws-credentials.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-event-stream.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws.php';

$failures = array();
function check_vpce( $condition, $label ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $label;
	}
}
function aicfab_test_host_filters() {
	return isset( $GLOBALS['aicfab_test_filters']['http_request_host_is_external'] ) ? count( $GLOBALS['aicfab_test_filters']['http_request_host_is_external'] ) : 0;
}

// Host names.
foreach ( array(
	'bedrock-runtime.ap-northeast-1.amazonaws.com',
	'bedrock-agent-runtime.eu-west-1.amazonaws.com',
	'vpce-0abc-1234.bedrock-runtime.ap-northeast-1.vpce.amazonaws.com',
	'bedrock-runtime.cn-north-1.amazonaws.com.cn',
	'bedrock-runtime.us-east-1.api.aws',
	'sts.ap-northeast-1.amazonaws.com',
) as $host ) {
	check_vpce( AI_Chat_Bedrock_AWS::is_aws_host( $host ), "{$host} is an AWS host" );
}
foreach ( array(
	'',
	'amazonaws.com',
	'evilamazonaws.com',
	'bedrock-runtime.ap-northeast-1.amazonaws.com.evil.example',
	'bedrock-runtime.ap-northeast-1.amazonaws.com:443',
	'-bad.amazonaws.com',
	'metadata.internal.example',
	'169.254.169.254',
) as $host ) {
	check_vpce( ! AI_Chat_Bedrock_AWS::is_aws_host( $host ), "'{$host}' is not an AWS host" );
}

$aws    = new AI_Chat_Bedrock_AWS();
$remote = new ReflectionMethod( 'AI_Chat_Bedrock_AWS', 'aws_remote' );
$remote->setAccessible( true );
$args = array( 'reject_unsafe_urls' => true );

// The runtime behind its endpoint: the request goes out, and the waiver is gone afterwards.
$response = $remote->invoke( null, 'POST', 'https://bedrock-runtime.ap-northeast-1.amazonaws.com/model/x/converse', $args );
check_vpce( ! is_wp_error( $response ), 'a POST to bedrock-runtime behind a private-DNS endpoint goes out' );
check_vpce( 0 === aicfab_test_host_filters(), 'the host waiver is removed after the request' );
check_vpce( ! aicfab_test_validate( 'https://bedrock-runtime.ap-northeast-1.amazonaws.com/' ), 'outside a plugin request, the endpoint address is still refused' );

$response = $remote->invoke( null, 'GET', 'https://vpce-0abc-1234.bedrock-runtime.ap-northeast-1.vpce.amazonaws.com/x', $args );
check_vpce( ! is_wp_error( $response ), 'an endpoint-specific vpce name goes out' );

$response = $remote->invoke( null, 'GET', 'https://sts.ap-northeast-1.amazonaws.com/?Action=GetCallerIdentity', $args );
check_vpce( ! is_wp_error( $response ), 'STS behind its own endpoint goes out' );

// Everything else stays refused, even from inside the plugin.
foreach ( array( 'https://metadata.internal.example/latest/', 'https://bedrock-runtime.ap-northeast-1.amazonaws.com.evil.example/model/x/converse' ) as $url ) {
	$response = $remote->invoke( null, 'POST', $url, $args );
	check_vpce( is_wp_error( $response ) && 'http_request_failed' === $response->get_error_code(), "{$url} is still refused" );
}
check_vpce( 0 === aicfab_test_host_filters(), 'refused requests leave no waiver behind' );

// The waiver covers the requested host only, not other private hosts looked up meanwhile.
$GLOBALS['aicfab_test_probe_result'] = null;
$probe_filter = function ( $external, $host ) {
	if ( 'bedrock-runtime.ap-northeast-1.amazonaws.com' === $host ) {
		$GLOBALS['aicfab_test_probe_result'] = aicfab_test_validate( 'https://metadata.internal.example/' );
	}
	return $external;
};
add_filter( 'http_request_host_is_external', $probe_filter, 10, 2 );
$remote->invoke( null, 'POST', 'https://bedrock-runtime.ap-northeast-1.amazonaws.com/model/x/converse', $args );
remove_filter( 'http_request_host_is_external', $probe_filter );
check_vpce( false === $GLOBALS['aicfab_test_probe_result'], 'during an AWS request, other private hosts are still refused' );

// The public calls: model discovery (GET, no endpoint) and a model invocation (POST, endpoint).
$GLOBALS['aicfab_test_sent']        = array();
$GLOBALS['aicfab_test_responses'][] = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array( 'modelSummaries' => array( array( 'modelId' => 'openai.gpt-6-luna', 'modelName' => 'GPT-6 Luna', 'inferenceTypesSupported' => array( 'INFERENCE_PROFILE' ) ) ) ) ),
);
$models = $aws->list_foundation_models();
check_vpce( is_array( $models ) && 1 === count( $models ) && 'openai.gpt-6-luna' === $models[0]['id'], 'model discovery still works' );

$GLOBALS['aicfab_test_responses'][] = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode(
		array(
			'output'     => array( 'message' => array( 'role' => 'assistant', 'content' => array( array( 'text' => 'pong' ) ) ) ),
			'stopReason' => 'end_turn',
			'usage'      => array( 'inputTokens' => 3, 'outputTokens' => 1 ),
		)
	),
);
$result = $aws->test_model_access( 'global.openai.gpt-6-luna' );
$sent   = end( $GLOBALS['aicfab_test_sent'] );
check_vpce( $sent && 0 === strpos( $sent['url'], 'https://bedrock-runtime.ap-northeast-1.amazonaws.com/' ), 'the model test is sent to bedrock-runtime behind the endpoint' );
check_vpce( ! empty( $result['success'] ), 'the model test succeeds behind a private-DNS endpoint' );
check_vpce( 0 === aicfab_test_host_filters(), 'no waiver is left after the public calls' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "OK: AWS requests work behind a private-DNS VPC endpoint, and only AWS hosts are let through\n";
