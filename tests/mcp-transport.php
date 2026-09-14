<?php
/** Standalone MCP transport tests. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AI_CHAT_BEDROCK_VERSION', '1.2.0' );
define( 'AI_CHAT_BEDROCK_AWS_ACCESS_KEY', 'AKIATESTACCESS' );
define( 'AI_CHAT_BEDROCK_AWS_SECRET_KEY', 'test-secret-value' );
define( 'AI_CHAT_BEDROCK_AWS_SESSION_TOKEN', 'test-session-token' );

$GLOBALS['aicfab_options'] = array( 'aws_region' => 'us-east-1', 'model_id' => 'anthropic.claude-3-haiku-20240307-v1:0' );
$GLOBALS['aicfab_next']    = array();
$GLOBALS['aicfab_sent']    = array();

class WP_Error {
	private $code; private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function get_option( $name, $default = false ) { return 'ai_chat_bedrock_settings' === $name ? $GLOBALS['aicfab_options'] : $default; }
function update_option( $name, $value, $autoload = null ) { return true; }
function delete_option( $name ) { return true; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $message, $domain = null ) { return $message; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $hook, $value ) { return $value; }
function wp_salt() { return 'transport-salt'; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }
function delete_transient( $key ) { return true; }
function wp_remote_request( $url, $args = array() ) { return new WP_Error( 'blocked', 'blocked' ); }
function wp_generate_uuid4() { return '11111111-2222-3333-4444-555555555555'; }
function esc_url_raw( $url, $schemes = null ) { return trim( (string) $url ); }
function wp_http_validate_url( $url ) {
	$host = parse_url( $url, PHP_URL_HOST );
	if ( ! $host ) { return false; }
	return ! in_array( $host, array( 'localhost', '127.0.0.1', '169.254.169.254' ), true );
}
function wp_safe_remote_post( $url, $args = array() ) {
	$GLOBALS['aicfab_sent'][] = array( 'url' => $url, 'args' => $args );
	$next = array_shift( $GLOBALS['aicfab_next'] );
	return null === $next ? new WP_Error( 'no_stub', 'no stubbed response' ) : $next;
}
function wp_remote_retrieve_response_code( $response ) { return isset( $response['status'] ) ? $response['status'] : 0; }
function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? $response['body'] : ''; }
function wp_remote_retrieve_header( $response, $header ) {
	$header = strtolower( $header );
	return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws-credentials.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-event-stream.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-usage.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-mcp-transport.php';

$failures = array();
function check_mcp( $condition, $message ) { global $failures; if ( ! $condition ) { $failures[] = $message; } }
function queue( $status, $body, $headers = array() ) { $GLOBALS['aicfab_next'][] = array( 'status' => $status, 'body' => $body, 'headers' => $headers ); }

$endpoint = 'https://gateway.example.com/mcp';

// Plain JSON-RPC result.
queue( 200, wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => '1', 'result' => array( 'tools' => array( array( 'name' => 'search', 'description' => 'Search', 'inputSchema' => array( 'type' => 'object' ) ) ) ) ) ) );
$result = AI_Chat_Bedrock_MCP_Transport::call( $endpoint, 'tools/list' );
check_mcp( is_array( $result ) && isset( $result['tools'][0]['name'] ) && 'search' === $result['tools'][0]['name'], 'JSON-RPC results must be returned to the caller.' );

$sent = end( $GLOBALS['aicfab_sent'] );
$body = json_decode( $sent['args']['body'], true );
check_mcp( '2.0' === $body['jsonrpc'] && 'tools/list' === $body['method'], 'Requests must use JSON-RPC 2.0 with the requested method.' );
check_mcp( isset( $sent['args']['headers']['MCP-Protocol-Version'] ), 'Requests must declare the MCP protocol version.' );
check_mcp( ! isset( $sent['args']['headers']['Authorization'] ), 'Unauthenticated servers must not receive an Authorization header.' );
check_mcp( true === $sent['args']['reject_unsafe_urls'] && 0 === $sent['args']['redirection'], 'MCP requests must keep SSRF protections enabled.' );

// Server-sent event framing used by Streamable HTTP.
queue( 200, "event: message\ndata: " . wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => '2', 'result' => array( 'content' => array( array( 'type' => 'text', 'text' => 'streamed' ) ) ) ) ) . "\n\n", array( 'content-type' => 'text/event-stream' ) );
$sse = AI_Chat_Bedrock_MCP_Transport::call( $endpoint, 'tools/call', array( 'name' => 'search' ) );
check_mcp( is_array( $sse ) && 'streamed' === $sse['content'][0]['text'], 'Event-stream framed responses must be decoded.' );

// JSON-RPC error mapping.
queue( 200, wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => '3', 'error' => array( 'code' => -32601, 'message' => 'Method not found' ) ) ) );
$error = AI_Chat_Bedrock_MCP_Transport::call( $endpoint, 'tools/unknown' );
check_mcp( is_wp_error( $error ) && 'Method not found' === $error->get_error_message(), 'JSON-RPC errors must become WP_Error results.' );

// Transport level failures.
queue( 401, '{}' );
$unauthorized = AI_Chat_Bedrock_MCP_Transport::call( $endpoint, 'tools/list' );
check_mcp( is_wp_error( $unauthorized ) && 'aicfab_mcp_unauthorized' === $unauthorized->get_error_code(), 'Authentication failures must be reported distinctly.' );

queue( 500, 'server error' );
$server_error = AI_Chat_Bedrock_MCP_Transport::call( $endpoint, 'tools/list' );
check_mcp( is_wp_error( $server_error ) && 'aicfab_mcp_http_error' === $server_error->get_error_code(), 'HTTP failures must be reported.' );

queue( 200, 'not json' );
$invalid = AI_Chat_Bedrock_MCP_Transport::call( $endpoint, 'tools/list' );
check_mcp( is_wp_error( $invalid ) && 'aicfab_mcp_invalid_json' === $invalid->get_error_code(), 'Invalid JSON must be rejected.' );

// Unsafe destinations and malformed methods are refused before any request.
$before = count( $GLOBALS['aicfab_sent'] );
$unsafe = AI_Chat_Bedrock_MCP_Transport::call( 'http://gateway.example.com/mcp', 'tools/list' );
check_mcp( is_wp_error( $unsafe ) && 'aicfab_mcp_unsafe_url' === $unsafe->get_error_code(), 'Plain HTTP endpoints must be refused.' );
$loopback = AI_Chat_Bedrock_MCP_Transport::call( 'https://127.0.0.1/mcp', 'tools/list' );
check_mcp( is_wp_error( $loopback ), 'Loopback endpoints must be refused.' );
$bad_method = AI_Chat_Bedrock_MCP_Transport::call( $endpoint, 'Tools/List; DROP' );
check_mcp( is_wp_error( $bad_method ) && 'aicfab_mcp_invalid_method' === $bad_method->get_error_code(), 'Malformed method names must be refused.' );
check_mcp( $before === count( $GLOBALS['aicfab_sent'] ), 'Refused requests must never reach the network.' );

// Bearer authentication stores the token encrypted and sends it once.
$auth = AI_Chat_Bedrock_MCP_Transport::sanitize_auth( array( 'type' => 'bearer', 'token' => 'super-secret-token' ) );
check_mcp( 'bearer' === $auth['type'] && AI_Chat_Bedrock_Security::is_encrypted( $auth['token'] ), 'Bearer tokens must be stored encrypted.' );
check_mcp( false === strpos( wp_json_encode( $auth ), 'super-secret-token' ), 'Stored auth must not contain the plaintext token.' );

queue( 200, wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => '4', 'result' => array() ) ) );
AI_Chat_Bedrock_MCP_Transport::call( $endpoint, 'tools/list', array(), $auth );
$sent = end( $GLOBALS['aicfab_sent'] );
check_mcp( 'Bearer super-secret-token' === $sent['args']['headers']['Authorization'], 'Bearer tokens must be decrypted for the request.' );

// SigV4 authentication signs for the requested service.
$sig_auth = AI_Chat_Bedrock_MCP_Transport::sanitize_auth( array( 'type' => 'sigv4', 'service' => 'Bedrock-AgentCore', 'region' => 'us-west-2' ) );
check_mcp( 'bedrock-agentcore' === $sig_auth['service'] && 'us-west-2' === $sig_auth['region'], 'SigV4 configuration must be normalized.' );

queue( 200, wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => '5', 'result' => array() ) ) );
AI_Chat_Bedrock_MCP_Transport::call( $endpoint, 'tools/list', array(), $sig_auth );
$sent = end( $GLOBALS['aicfab_sent'] );
$authorization = isset( $sent['args']['headers']['Authorization'] ) ? $sent['args']['headers']['Authorization'] : '';
check_mcp( false !== strpos( $authorization, 'AWS4-HMAC-SHA256' ), 'SigV4 requests must carry an AWS signature.' );
check_mcp( false !== strpos( $authorization, '/us-west-2/bedrock-agentcore/aws4_request' ), 'SigV4 scope must use the configured service and region.' );
check_mcp( false === strpos( $authorization, 'test-secret-value' ), 'Signatures must not leak the secret key.' );
check_mcp( isset( $sent['args']['headers']['X-Amz-Security-Token'] ), 'Temporary credentials must include the session token.' );

$rejected = AI_Chat_Bedrock_AWS::sign_request( $endpoint, '{}', 'POST', 'bedrock-agentcore', 'not-a-region' );
check_mcp( is_wp_error( $rejected ) && 'aicfab_invalid_region' === $rejected->get_error_code(), 'Invalid signing regions must be refused.' );

$default_auth = AI_Chat_Bedrock_MCP_Transport::sanitize_auth( array( 'type' => 'sigv4' ) );
check_mcp( 'bedrock-agentcore' === $default_auth['service'], 'SigV4 must default to the AgentCore service name.' );
check_mcp( array( 'type' => 'none' ) === AI_Chat_Bedrock_MCP_Transport::sanitize_auth( array( 'type' => 'bearer', 'token' => '' ) ), 'Empty bearer tokens must fall back to no authentication.' );
check_mcp( array( 'type' => 'none' ) === AI_Chat_Bedrock_MCP_Transport::sanitize_auth( array( 'type' => 'unknown' ) ), 'Unknown auth types must fall back to no authentication.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: MCP transport, framing and authentication checks passed\n";
