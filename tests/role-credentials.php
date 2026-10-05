<?php
/**
 * Standalone tests for IAM role credentials from the instance metadata service and the
 * container credentials endpoint, and for not waiting on them when no role answers.
 *
 * Run: php tests/role-credentials.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options']    = array( 'aws_region' => 'us-east-1' );
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_ttls']       = array();
$GLOBALS['aicfab_requests']   = array();
$GLOBALS['aicfab_imds']       = 'down';

class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) {
		$this->code = $code;
	}
	public function get_error_code() {
		return $this->code;
	}
}
function get_option( $name, $fallback = false ) {
	return 'ai_chat_bedrock_settings' === $name ? $GLOBALS['aicfab_options'] : $fallback;
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
function __( $message ) {
	return $message;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
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
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['aicfab_transients'] ) ? $GLOBALS['aicfab_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	if ( ! empty( $GLOBALS['aicfab_no_store'] ) ) {
		return false;
	}
	$GLOBALS['aicfab_transients'][ $key ] = $value;
	$GLOBALS['aicfab_ttls'][ $key ]       = $ttl;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['aicfab_transients'][ $key ] );
	return true;
}

/**
 * The metadata service as the plugin sees it: down (a site off AWS), up with a role, or up
 * without one (an instance with no role attached).
 */
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['aicfab_requests'][] = $args['method'] . ' ' . $url;
	if ( 'down' === $GLOBALS['aicfab_imds'] ) {
		return new WP_Error( 'http_request_failed', 'Operation timed out after 2000 milliseconds' );
	}
	if ( false !== strpos( $url, '/api/token' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => 'token-123' );
	}
	if ( 'norole' === $GLOBALS['aicfab_imds'] ) {
		return array( 'response' => array( 'code' => 404 ), 'body' => '' );
	}
	if ( '/' === substr( $url, -1 ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => "site-role\n" );
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode(
			array(
				'Code'            => 'Success',
				'AccessKeyId'     => 'ASIAEXAMPLE',
				'SecretAccessKey' => 'secret-example',
				'Token'           => 'session-example',
				'Expiration'      => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
			)
		),
	);
}
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? (string) $response['body'] : '';
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-aws-credentials.php';

$failures = array();
function check_role( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}
/** A new request: the transients persist, what the class remembered in memory does not. */
function next_request() {
	$GLOBALS['aicfab_requests'] = array();
	$property = new ReflectionProperty( 'AI_Chat_Bedrock_AWS_Credentials', 'role_missed' );
	// Needed before PHP 8.1, deprecated since 8.5.
	if ( PHP_VERSION_ID < 80100 ) {
		$property->setAccessible( true );
	}
	$property->setValue( null, false );
}

// --- Off AWS: one wait, then none ----------------------------------------------------------

$result = AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( is_wp_error( $result ) && 'aicfab_no_credentials' === $result->get_error_code(), 'Without a role the site has no credentials.' );
check_role( array( 'PUT http://169.254.169.254/latest/api/token' ) === $GLOBALS['aicfab_requests'], 'The metadata service is asked once.' );
check_role( 1 === get_transient( AI_Chat_Bedrock_AWS_Credentials::ROLE_MISS_KEY ) && 300 === $GLOBALS['aicfab_ttls'][ AI_Chat_Bedrock_AWS_Credentials::ROLE_MISS_KEY ], 'That no role answered is remembered for five minutes.' );

$GLOBALS['aicfab_requests'] = array();
AI_Chat_Bedrock_AWS_Credentials::resolve();
AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( array() === $GLOBALS['aicfab_requests'], 'Later lookups in the same request do not wait for it again.' );

next_request();
check_role( is_wp_error( AI_Chat_Bedrock_AWS_Credentials::resolve() ) && array() === $GLOBALS['aicfab_requests'], 'Nor do lookups in the next requests.' );

unset( $GLOBALS['aicfab_transients'][ AI_Chat_Bedrock_AWS_Credentials::ROLE_MISS_KEY ] );
$GLOBALS['aicfab_requests'] = array();
AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( array() === $GLOBALS['aicfab_requests'], 'Within one request the answer holds even where transients are not kept.' );

// A site where transients cannot be written still asks only once per request.
next_request();
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_no_store']   = true;
AI_Chat_Bedrock_AWS_Credentials::resolve();
AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( 1 === count( $GLOBALS['aicfab_requests'] ), 'Without a stored record the miss is still remembered for the rest of the request.' );
$GLOBALS['aicfab_no_store'] = false;

// Saving the settings in the same request asks again, for the request that saved them.
$GLOBALS['aicfab_requests'] = array();
AI_Chat_Bedrock_AWS_Credentials::flush_cache();
AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( 1 === count( $GLOBALS['aicfab_requests'] ), 'Flushing asks again within the same request too.' );

// --- Credentials that need no metadata service are still found --------------------------------

next_request();
$GLOBALS['aicfab_transients'][ AI_Chat_Bedrock_AWS_Credentials::ROLE_MISS_KEY ] = 1;
putenv( 'AWS_ACCESS_KEY_ID=AKIAENVEXAMPLE' );
putenv( 'AWS_SECRET_ACCESS_KEY=env-secret' );
$result = AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( is_array( $result ) && 'environment' === $result['source'], 'Environment variables are read even while no role answers.' );
putenv( 'AWS_ACCESS_KEY_ID' );
putenv( 'AWS_SECRET_ACCESS_KEY' );
$GLOBALS['aicfab_options']['aws_access_key'] = AI_Chat_Bedrock_Security::encrypt_secret( 'AKIAOPTIONEXAMPLE' );
$GLOBALS['aicfab_options']['aws_secret_key'] = AI_Chat_Bedrock_Security::encrypt_secret( 'option-secret' );
$result                                      = AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( is_array( $result ) && 'options' === $result['source'] && array() === $GLOBALS['aicfab_requests'], 'Keys saved in the settings are used without asking the metadata service.' );
unset( $GLOBALS['aicfab_options']['aws_access_key'], $GLOBALS['aicfab_options']['aws_secret_key'] );

// --- Asking again --------------------------------------------------------------------------------

$GLOBALS['aicfab_imds'] = 'role';
AI_Chat_Bedrock_AWS_Credentials::flush_cache();
check_role( false === get_transient( AI_Chat_Bedrock_AWS_Credentials::ROLE_MISS_KEY ), 'Flushing forgets that no role answered, as saving the settings does.' );
$GLOBALS['aicfab_requests'] = array();
$result                     = AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( is_array( $result ) && 'instance_role' === $result['source'] && 'ASIAEXAMPLE' === $result['access_key'] && 3 === count( $GLOBALS['aicfab_requests'] ), 'A role attached since is found at once.' );
check_role( is_array( get_transient( AI_Chat_Bedrock_AWS_Credentials::ROLE_CACHE_KEY ) ) && false === get_transient( AI_Chat_Bedrock_AWS_Credentials::ROLE_MISS_KEY ), 'Its credentials are cached, and no miss is recorded.' );
next_request();
$result = AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( is_array( $result ) && 'instance_role' === $result['source'] && array() === $GLOBALS['aicfab_requests'], 'Cached role credentials are used without a request.' );

// An instance without a role answers, but has nothing to give.
$GLOBALS['aicfab_imds'] = 'norole';
AI_Chat_Bedrock_AWS_Credentials::flush_cache();
next_request();
check_role( is_wp_error( AI_Chat_Bedrock_AWS_Credentials::resolve() ) && 1 === get_transient( AI_Chat_Bedrock_AWS_Credentials::ROLE_MISS_KEY ), 'An instance with no role attached is remembered as a miss too.' );

// A container endpoint that does not answer is remembered the same way, before the instance is asked.
AI_Chat_Bedrock_AWS_Credentials::flush_cache();
next_request();
$GLOBALS['aicfab_imds'] = 'down';
putenv( 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI=/v2/credentials/abc' );
AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( 2 === count( $GLOBALS['aicfab_requests'] ) && 0 === strpos( $GLOBALS['aicfab_requests'][0], 'GET http://169.254.170.2/v2/credentials/abc' ), 'The container endpoint is asked first, then the instance.' );
$GLOBALS['aicfab_requests'] = array();
AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( array() === $GLOBALS['aicfab_requests'], 'Then neither is asked again.' );
putenv( 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI' );

// With role credentials switched off nothing is asked or remembered.
AI_Chat_Bedrock_AWS_Credentials::flush_cache();
next_request();
$GLOBALS['aicfab_options']['aws_use_role_credentials'] = false;
AI_Chat_Bedrock_AWS_Credentials::resolve();
check_role( array() === $GLOBALS['aicfab_requests'] && false === get_transient( AI_Chat_Bedrock_AWS_Credentials::ROLE_MISS_KEY ), 'With role credentials off the metadata service is never asked.' );

// --- Where the record is cleared ------------------------------------------------------------------

$root        = dirname( __DIR__ );
$admin       = (string) file_get_contents( $root . '/admin/class-ai-chat-bedrock-admin.php' );
$diagnostics = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock-diagnostics.php' );
check_role( false !== strpos( $admin, 'AI_Chat_Bedrock_AWS_Credentials::flush_cache();' ), 'Saving the settings asks again.' );
check_role( 1 === preg_match( '/if \( \$include_live && class_exists\( \'AI_Chat_Bedrock_AWS_Credentials\' \) \) \{\s*AI_Chat_Bedrock_AWS_Credentials::flush_cache\(\);/', $diagnostics ), 'Running the Diagnostics checks asks again.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: role credential checks passed\n";
