<?php
/**
 * Standalone tests for Amazon Bedrock API key authentication.
 *
 * An API key is a bearer token that Bedrock and Bedrock Runtime accept in place of a
 * Signature Version 4 signature. The Agents endpoints (Knowledge Bases, Prompt Management),
 * STS and AgentCore do not, so those must keep signing, or say plainly why they cannot.
 *
 * Run: php tests/api-key.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

// No access-key constants here: this site has only an API key.
$GLOBALS['aicfab_test_options'] = array(
	'aws_region'               => 'us-east-1',
	'model_id'                 => 'amazon.nova-lite-v1:0',
	'aws_use_role_credentials' => false,
);
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_code() {
		return $this->code;
	}
}
function get_option( $name, $default = false ) {
	return 'ai_chat_bedrock_settings' === $name ? $GLOBALS['aicfab_test_options'] : $default;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function wp_unslash( $value ) {
	return $value;
}
function absint( $value ) {
	return abs( (int) $value );
}
function __( $message ) {
	return $message;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function wp_salt() {
	return 'test-salt';
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}
function get_transient( $key ) {
	return false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	return true;
}
function delete_transient( $key ) {
	return true;
}
$GLOBALS['aicfab_metadata_calls'] = 0;
function wp_remote_request( $url, $args = array() ) {
	++$GLOBALS['aicfab_metadata_calls'];
	return new WP_Error( 'aicfab_test_blocked', 'Metadata requests are blocked during tests.' );
}
$GLOBALS['aicfab_test_posts'] = array();
function wp_safe_remote_post( $url, $args = array() ) {
	$GLOBALS['aicfab_test_posts'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '{"output":{"message":{"role":"assistant","content":[{"text":"Hello from Nova"}]}},"usage":{"inputTokens":4,"outputTokens":3}}',
	);
}
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? (string) $response['body'] : '';
}
function wp_remote_retrieve_header( $response, $name ) {
	return '';
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws-credentials.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-event-stream.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws.php';

$failures = array();
function check_key( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// Shapes taken from the Amazon Bedrock documentation: long-term keys are base64 text, and
// short-term keys are "bedrock-api-key-" followed by an encoded presigned URL.
$long_term  = 'ABSKQmVkcm9ja0FQSUtleS1leGFtcGxlLWF0LTEyMzQ1Njc4OTAxMjo' . str_repeat( 'A', 60 ) . '=';
$short_term = 'bedrock-api-key-YmVkcm9jay5hbWF6b25hd3MuY29tLz9BY3Rpb249Q2FsbFdpdGhCZWFyZXJUb2tlbg' . str_repeat( 'x', 40 );

// --- Cleaning what gets pasted ------------------------------------------------

check_key( $long_term === AI_Chat_Bedrock_AWS_Credentials::clean_api_key( "  $long_term\n" ), 'surrounding whitespace is removed from a pasted key' );
check_key( $long_term === AI_Chat_Bedrock_AWS_Credentials::clean_api_key( 'Bearer ' . $long_term ), 'a pasted "Bearer " prefix is removed' );
check_key( $short_term === AI_Chat_Bedrock_AWS_Credentials::clean_api_key( $short_term ), 'a short-term key is accepted' );
check_key( '' === AI_Chat_Bedrock_AWS_Credentials::clean_api_key( 'short' ), 'a value too short to be a key is refused' );
check_key( '' === AI_Chat_Bedrock_AWS_Credentials::clean_api_key( $long_term . "\r\nX-Injected: 1" ), 'a value that would add a header is refused' );
check_key( '' === AI_Chat_Bedrock_AWS_Credentials::clean_api_key( 'AKIA1234567890 secret words here' ), 'a value with spaces inside is refused' );

// --- Resolution -------------------------------------------------------------

check_key( null === AI_Chat_Bedrock_AWS_Credentials::api_key( $GLOBALS['aicfab_test_options'] ), 'no key is reported when none is configured' );
$none = new AI_Chat_Bedrock_AWS();
check_key( false === $none->has_credentials(), 'a site with no key and no role has no credentials' );

$GLOBALS['aicfab_test_options']['bedrock_api_key'] = AI_Chat_Bedrock_Security::encrypt_secret( $long_term );
check_key( 0 === strpos( $GLOBALS['aicfab_test_options']['bedrock_api_key'], 'aicfab:' ), 'the stored key is encrypted' );
$resolved = AI_Chat_Bedrock_AWS_Credentials::api_key( $GLOBALS['aicfab_test_options'] );
check_key( is_array( $resolved ) && $long_term === $resolved['key'] && 'api_key_option' === $resolved['source'] && false === $resolved['temporary'], 'the encrypted setting resolves to the key' );

$described = AI_Chat_Bedrock_AWS_Credentials::describe( $GLOBALS['aicfab_test_options'] );
check_key( true === $described['configured'] && 'api_key_option' === $described['source'], 'the settings screen reports the API key as the active source' );
check_key( false === strpos( wp_json_encode( $described ), $long_term ), 'describing the source never shows the key' );

putenv( 'AWS_BEARER_TOKEN_BEDROCK=' . $short_term );
$env_options = array( 'aws_region' => 'us-east-1' );
$from_env    = AI_Chat_Bedrock_AWS_Credentials::api_key( $env_options );
check_key( is_array( $from_env ) && 'api_key_environment' === $from_env['source'] && true === $from_env['temporary'], 'the AWS SDK environment variable is read, and a short-term key is recognised' );
check_key( 'api_key_option' === AI_Chat_Bedrock_AWS_Credentials::api_key( $GLOBALS['aicfab_test_options'] )['source'], 'the key in the settings wins over the environment' );
$with_keys = array(
	'aws_access_key' => AI_Chat_Bedrock_Security::encrypt_secret( 'AKIAEXAMPLEKEY' ),
	'aws_secret_key' => AI_Chat_Bedrock_Security::encrypt_secret( 'example-secret' ),
);
check_key( null === AI_Chat_Bedrock_AWS_Credentials::api_key( $with_keys ), 'access keys entered for this plugin are not overridden by an ambient environment key' );
putenv( 'AWS_BEARER_TOKEN_BEDROCK' );

// --- Requests ---------------------------------------------------------------

$GLOBALS['aicfab_metadata_calls'] = 0;
$aws = new AI_Chat_Bedrock_AWS();
check_key( true === $aws->has_credentials(), 'an API key alone is enough to chat' );
check_key( 'api_key_option' === $aws->credential_source(), 'the client reports the API key source' );
check_key( 0 === $GLOBALS['aicfab_metadata_calls'], 'a site with an API key does not probe metadata endpoints on every request' );

$sign = new ReflectionMethod( $aws, 'signed_headers' );
$sign->setAccessible( true );
$runtime = $sign->invoke( $aws, 'https://bedrock-runtime.us-east-1.amazonaws.com/model/amazon.nova-lite-v1%3A0/converse', '{}', 'POST', 'bedrock', array( 'x-amzn-bedrock-guardrailidentifier' => 'gr-1' ) );
check_key( 'Bearer ' . $long_term === $runtime['Authorization'], 'Bedrock Runtime requests carry the key as a bearer token' );
check_key( ! isset( $runtime['X-Amz-Date'] ) && ! isset( $runtime['X-Amz-Security-Token'] ), 'a bearer request carries no signature headers' );
check_key( 'gr-1' === $runtime['x-amzn-bedrock-guardrailidentifier'], 'guardrail headers still travel with a bearer request' );
$control = $sign->invoke( $aws, 'https://bedrock.us-east-1.amazonaws.com/foundation-models?byOutputModality=TEXT', '', 'GET' );
check_key( 'Bearer ' . $long_term === $control['Authorization'], 'the model list uses the key too' );

$answer = $aws->handle_chat_message( array( 'messages' => array( array( 'role' => 'user', 'content' => 'Hello' ) ) ) );
check_key( true === $answer['success'] && 'Hello from Nova' === $answer['data']['message'], 'a chat answers with only an API key configured' );
$sent = end( $GLOBALS['aicfab_test_posts'] );
check_key( 'Bearer ' . $long_term === $sent['args']['headers']['Authorization'], 'the chat request was sent with the bearer token' );

// The Agents endpoints, STS and AgentCore refuse API keys, and say so.
$kb = $aws->retrieve_from_knowledge_base( 'KB12345678', 'opening hours' );
check_key( is_wp_error( $kb ) && 'aicfab_api_key_unsupported' === $kb->get_error_code(), 'Knowledge Base retrieval explains that an API key cannot be used' );
check_key( is_wp_error( $kb ) && false !== strpos( $kb->get_error_message(), 'bedrock-agent-runtime' ), 'the explanation names the service' );
check_key( 0 === count( array_filter( $GLOBALS['aicfab_test_posts'], function ( $post ) { return false !== strpos( $post['url'], 'bedrock-agent-runtime' ); } ) ), 'no doomed Knowledge Base request is sent' );
$identity = $aws->caller_identity( true );
check_key( is_wp_error( $identity ) && 'aicfab_api_key_unsupported' === $identity->get_error_code(), 'STS is not called with an API key' );
$agentcore = AI_Chat_Bedrock_AWS::sign_request( 'https://gateway.bedrock-agentcore.us-east-1.amazonaws.com/mcp', '{}' );
check_key( is_wp_error( $agentcore ) && 'aicfab_api_key_unsupported' === $agentcore->get_error_code(), 'AgentCore requests are not signed with empty credentials' );
$bedrock_signed = AI_Chat_Bedrock_AWS::sign_request( 'https://bedrock.us-east-1.amazonaws.com/x', '{}', 'POST', 'bedrock' );
check_key( is_wp_error( $bedrock_signed ), 'sign_request always means a real signature, so an API key does not satisfy it' );
$agent_headers = $sign->invoke( $aws, 'https://bedrock-agent.us-east-1.amazonaws.com/prompts/P1/', '', 'GET' );
check_key( false === strpos( (string) $agent_headers['Authorization'], 'Bearer' ), 'Prompt Management is never sent the key' );

// With an IAM role or access keys as well, the key serves Bedrock and the rest is signed.
define( 'AI_CHAT_BEDROCK_AWS_ACCESS_KEY', 'AKIATESTACCESS' );
define( 'AI_CHAT_BEDROCK_AWS_SECRET_KEY', 'test-secret-value' );
$both = new AI_Chat_Bedrock_AWS();
check_key( true === $both->has_signing_credentials(), 'signing credentials are still found alongside an API key' );
$both_sign = new ReflectionMethod( $both, 'signed_headers' );
$both_sign->setAccessible( true );
$kb_headers = $both_sign->invoke( $both, 'https://bedrock-agent-runtime.us-east-1.amazonaws.com/knowledgebases/KB1/retrieve', '{}' );
check_key( 0 === strpos( $kb_headers['Authorization'], 'AWS4-HMAC-SHA256 Credential=AKIATESTACCESS/' ), 'Knowledge Base requests are signed when keys exist' );
$runtime_both = $both_sign->invoke( $both, 'https://bedrock-runtime.us-east-1.amazonaws.com/model/x/invoke', '{}' );
check_key( 'Bearer ' . $long_term === $runtime_both['Authorization'], 'the configured API key still serves Bedrock Runtime' );
$signed_agentcore = AI_Chat_Bedrock_AWS::sign_request( 'https://gateway.bedrock-agentcore.us-east-1.amazonaws.com/mcp', '{}' );
check_key( is_array( $signed_agentcore ) && 0 === strpos( $signed_agentcore['Authorization'], 'AWS4-HMAC-SHA256' ), 'AgentCore is signed with the access keys' );

// Exported settings never carry the key.
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-transfer.php';
check_key( in_array( 'bedrock_api_key', AI_Chat_Bedrock_Transfer::credential_keys(), true ), 'the API key is excluded from settings exports' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: Bedrock API key checks passed\n";
