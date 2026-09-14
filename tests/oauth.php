<?php
/** Standalone tests for the OAuth 2.1 authorization server. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AI_CHAT_BEDROCK_VERSION', '1.4.0' );

$GLOBALS['aicfab_options']    = array( 'ai_chat_bedrock_oauth_enabled' => true );
$GLOBALS['aicfab_transients'] = array();

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
	public $data; public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}
class WP_REST_Request {
	private $params;
	public function __construct( $params = array() ) { $this->params = $params; }
	public function get_param( $key ) { return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null; }
	public function get_json_params() { return $this->params; }
}

function get_option( $name, $default = false ) { return isset( $GLOBALS['aicfab_options'][ $name ] ) ? $GLOBALS['aicfab_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['aicfab_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['aicfab_options'][ $name ] ); return true; }
function get_transient( $key ) { return isset( $GLOBALS['aicfab_transients'][ $key ] ) ? $GLOBALS['aicfab_transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['aicfab_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['aicfab_transients'][ $key ] ); return true; }
function apply_filters( $hook, $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $message, $domain = null ) { return $message; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
function esc_url_raw( $url, $schemes = null ) { return trim( (string) $url ); }
function wp_generate_password( $length = 12, $special = true, $extra = true ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$out = '';
	for ( $i = 0; $i < $length; $i++ ) { $out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ]; }
	return $out;
}
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ); }
function rest_ensure_response( $value ) { return new WP_REST_Response( $value, 200 ); }
function register_rest_route( $namespace, $route, $args = array() ) { return true; }
function get_userdata( $id ) { return (object) array( 'display_name' => 'Demo' ); }
function get_current_user_id() { return 1; }
function current_user_can( $capability ) { return true; }
function is_user_logged_in() { return true; }
function wp_salt() { return 'oauth-salt'; }

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-tool-policy.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wp-mcp-server.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-oauth.php';

$failures = array();
function check_oauth( $condition, $message ) { global $failures; if ( ! $condition ) { $failures[] = $message; } }

$oauth = new AI_Chat_Bedrock_OAuth();

// Redirect URI policy follows OAuth 2.1.
check_oauth( AI_Chat_Bedrock_OAuth::is_allowed_redirect( 'https://claude.ai/api/mcp/auth_callback' ), 'HTTPS redirects must be allowed.' );
check_oauth( AI_Chat_Bedrock_OAuth::is_allowed_redirect( 'http://127.0.0.1:41234/callback' ), 'Loopback redirects must be allowed.' );
check_oauth( AI_Chat_Bedrock_OAuth::is_allowed_redirect( 'http://localhost:8000/cb' ), 'Localhost redirects must be allowed.' );
check_oauth( ! AI_Chat_Bedrock_OAuth::is_allowed_redirect( 'http://evil.example.com/cb' ), 'Plain HTTP hosts must be rejected.' );
check_oauth( ! AI_Chat_Bedrock_OAuth::is_allowed_redirect( 'https://example.test/cb#fragment' ), 'Redirects with fragments must be rejected.' );
check_oauth( ! AI_Chat_Bedrock_OAuth::is_allowed_redirect( 'javascript:alert(1)' ), 'Non-HTTP schemes must be rejected.' );

// Metadata documents describe the endpoints clients need.
$meta = $oauth->authorization_server_metadata();
check_oauth( array( 'S256' ) === $meta['code_challenge_methods_supported'], 'Only S256 PKCE may be advertised.' );
check_oauth( array( 'authorization_code', 'refresh_token' ) === $meta['grant_types_supported'], 'Supported grants must be code and refresh.' );
check_oauth( array( 'none' ) === $meta['token_endpoint_auth_methods_supported'], 'Public clients use no token endpoint auth.' );
$resource = $oauth->protected_resource_metadata();
check_oauth( false !== strpos( $resource['resource'], '/ai-chat-bedrock/v1/mcp' ), 'Protected resource must point at the MCP endpoint.' );

// Registration validates redirect URIs and never returns a secret.
$registered = $oauth->handle_register( new WP_REST_Request( array( 'client_name' => 'Test Client', 'redirect_uris' => array( 'https://claude.ai/cb' ) ) ) );
check_oauth( $registered instanceof WP_REST_Response && 201 === $registered->get_status(), 'Registration must return 201.' );
$client = $registered->get_data();
check_oauth( ! isset( $client['client_secret'] ), 'Public clients must not receive a secret.' );
check_oauth( 0 === strpos( $client['client_id'], 'aicfab_' ), 'Client identifiers must be namespaced.' );
$rejected = $oauth->handle_register( new WP_REST_Request( array( 'client_name' => 'Bad', 'redirect_uris' => array( 'http://evil.example.com/cb' ) ) ) );
check_oauth( is_wp_error( $rejected ) && 'invalid_redirect_uri' === $rejected->get_error_code(), 'Invalid redirect URIs must be refused.' );

// Authorization code exchange requires the matching PKCE verifier.
$verifier  = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
$code      = wp_generate_password( 48, false, false );
set_transient(
	AI_Chat_Bedrock_OAuth::CODE_PREFIX . hash( 'sha256', $code ),
	array( 'client_id' => $client['client_id'], 'user' => 1, 'redirect_uri' => 'https://claude.ai/cb', 'challenge' => $challenge, 'created' => time() ),
	600
);

$wrong = $oauth->handle_token( new WP_REST_Request( array(
	'grant_type' => 'authorization_code',
	'code' => $code,
	'client_id' => $client['client_id'],
	'redirect_uri' => 'https://claude.ai/cb',
	'code_verifier' => rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ),
) ) );
check_oauth( 'invalid_grant' === $wrong->get_data()['error'], 'A wrong verifier must fail with invalid_grant.' );

// The code was consumed by the failed attempt, so a fresh code is required.
$code2 = wp_generate_password( 48, false, false );
set_transient(
	AI_Chat_Bedrock_OAuth::CODE_PREFIX . hash( 'sha256', $code2 ),
	array( 'client_id' => $client['client_id'], 'user' => 1, 'redirect_uri' => 'https://claude.ai/cb', 'challenge' => $challenge, 'created' => time() ),
	600
);
$tokens = $oauth->handle_token( new WP_REST_Request( array(
	'grant_type' => 'authorization_code',
	'code' => $code2,
	'client_id' => $client['client_id'],
	'redirect_uri' => 'https://claude.ai/cb',
	'code_verifier' => $verifier,
) ) )->get_data();
check_oauth( 'Bearer' === $tokens['token_type'] && 3600 === $tokens['expires_in'], 'Token responses must describe a short-lived bearer token.' );
check_oauth( 64 === strlen( $tokens['access_token'] ) && 64 === strlen( $tokens['refresh_token'] ), 'Tokens must be long random strings.' );

// Tokens are stored only as hashes.
$stored = wp_json_encode( get_option( AI_Chat_Bedrock_OAuth::OPTION_GRANTS ) );
check_oauth( false === strpos( $stored, $tokens['access_token'] ), 'Access tokens must never be stored in clear text.' );
check_oauth( false === strpos( $stored, $tokens['refresh_token'] ), 'Refresh tokens must never be stored in clear text.' );

// Bearer authentication resolves the granted user and rejects unknown tokens.
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
check_oauth( 1 === $oauth->authenticate_bearer( false ), 'A valid bearer token must resolve the granted user.' );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-token';
check_oauth( false === $oauth->authenticate_bearer( false ), 'Unknown bearer tokens must not authenticate.' );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
check_oauth( 7 === $oauth->authenticate_bearer( 7 ), 'An already authenticated user must be preserved.' );

// Refresh rotation issues new tokens and reuse revokes the grant.
$rotated = $oauth->handle_token( new WP_REST_Request( array(
	'grant_type' => 'refresh_token',
	'refresh_token' => $tokens['refresh_token'],
	'client_id' => $client['client_id'],
) ) )->get_data();
check_oauth( isset( $rotated['refresh_token'] ) && $rotated['refresh_token'] !== $tokens['refresh_token'], 'Refresh tokens must rotate.' );

$reused = $oauth->handle_token( new WP_REST_Request( array(
	'grant_type' => 'refresh_token',
	'refresh_token' => $tokens['refresh_token'],
	'client_id' => $client['client_id'],
) ) )->get_data();
check_oauth( 'invalid_grant' === $reused['error'], 'Reusing a rotated refresh token must fail.' );

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $rotated['access_token'];
check_oauth( 1 === $oauth->authenticate_bearer( false ), 'The rotated access token must work.' );

// Unsupported grants and disabled OAuth are refused.
$unsupported = $oauth->handle_token( new WP_REST_Request( array( 'grant_type' => 'password' ) ) );
check_oauth( 'unsupported_grant_type' === $unsupported->get_data()['error'], 'Only code and refresh grants are supported.' );

$GLOBALS['aicfab_options']['ai_chat_bedrock_oauth_enabled'] = false;
check_oauth( ! AI_Chat_Bedrock_OAuth::enabled(), 'OAuth must be opt-in.' );
$disabled = $oauth->handle_token( new WP_REST_Request( array( 'grant_type' => 'refresh_token', 'refresh_token' => 'x' ) ) );
check_oauth( is_wp_error( $disabled ), 'Token requests must fail while OAuth is disabled.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_oauth_enabled'] = true;

// Revocation clears the grant.
$summaries = $oauth->grant_summaries();
check_oauth( 1 === count( $summaries ), 'One grant must be listed after rotation.' );
check_oauth( $oauth->revoke_grant( $summaries[0]['id'] ), 'Revoking an existing grant must succeed.' );
check_oauth( array() === $oauth->grant_summaries(), 'Revoked grants must disappear.' );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $rotated['access_token'];
check_oauth( false === $oauth->authenticate_bearer( false ), 'Revoked tokens must stop working.' );
unset( $_SERVER['HTTP_AUTHORIZATION'] );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: OAuth authorization server checks passed\n";
