<?php
/**
 * The gate on the streaming endpoint, against the real class.
 *
 * Streaming is the default way visitors talk to this plugin, so check_permission is the gate most
 * requests actually pass through. Its four steps are a nonce, the guest gate, whether streaming is
 * enabled, and a rate limit. Each helper it calls is tested on its own; the composition was not,
 * and no suite loaded this class. The only thing guarding the route was a source-text count of
 * permission_callback => '__return_true', which catches making the route public and catches
 * nothing inside the callback.
 *
 * Security, Profiles, Rate_Limits and Chat_Request are loaded for real, so what is under test is
 * the gate as shipped rather than a rehearsal of it.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride

define( 'ABSPATH', __DIR__ );
define( 'AI_CHAT_BEDROCK_VERSION', 'test' );

$failures = array();
function check_stream( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// --- The smallest WordPress this gate needs ------------------------------------

$GLOBALS['aicfab_opts']       = array();
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_logged_in']  = false;
$GLOBALS['aicfab_nonce_ok']   = true;
$GLOBALS['aicfab_routes']     = array();

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_opts'] ) ? $GLOBALS['aicfab_opts'][ $name ] : $default_value;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_opts'][ $name ] = $value;
	return true;
}
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['aicfab_transients'] ) ? $GLOBALS['aicfab_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['aicfab_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['aicfab_transients'][ $key ] );
	return true;
}
function is_user_logged_in() {
	return (bool) $GLOBALS['aicfab_logged_in'];
}
function get_current_user_id() {
	return $GLOBALS['aicfab_logged_in'] ? 7 : 0;
}
function wp_verify_nonce( $nonce, $action ) {
	// Controlled rather than real: what matters here is that the result is honoured.
	return $GLOBALS['aicfab_nonce_ok'] && 'ai_chat_bedrock_nonce' === $action && '' !== $nonce ? 1 : false;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function __( $text, $domain = null ) {
	return $text;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	return true;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_unslash( $value ) {
	return $value;
}
function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}
function wp_get_current_user() {
	$user        = new stdClass();
	$user->roles = $GLOBALS['aicfab_logged_in'] ? array( 'subscriber' ) : array();
	return $user;
}
function current_user_can( $capability ) {
	return (bool) $GLOBALS['aicfab_logged_in'];
}
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['aicfab_routes'][] = array( 'namespace' => $namespace, 'route' => $route, 'args' => $args );
	return true;
}

/*
 * Chat_Request::streaming_enabled asks whether the host can stream at all, which in production
 * means curl. Held true here so the gate's own decision is what the assertions observe.
 */
class AI_Chat_Bedrock_AWS {
	public static function streaming_supported() {
		return true;
	}
}

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

/**
 * Carries only what the gate reads from a request.
 */
class WP_REST_Request {
	private $params;
	public function __construct( $params = array() ) {
		$this->params = $params;
	}
	public function get_param( $name ) {
		return isset( $this->params[ $name ] ) ? $this->params[ $name ] : null;
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-profiles.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-rate-limits.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-chat-request.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-stream.php';

/**
 * Fresh state per case, because the rate limit is a counter and the gate is order sensitive.
 */
function aicfab_reset( $settings = array() ) {
	$GLOBALS['aicfab_transients'] = array();
	$GLOBALS['aicfab_nonce_ok']   = true;
	$GLOBALS['aicfab_logged_in']  = false;
	$GLOBALS['aicfab_opts']       = array(
		'ai_chat_bedrock_settings' => array_merge(
			array( 'enable_streaming' => true, 'rate_limit_per_minute' => 5 ),
			$settings
		),
	);
}

function aicfab_gate( $params = array() ) {
	$stream = new AI_Chat_Bedrock_Stream();
	return $stream->check_permission( new WP_REST_Request( array_merge( array( 'nonce' => 'n', 'message' => 'hello' ), $params ) ) );
}

function aicfab_code( $result ) {
	return $result instanceof WP_Error ? $result->get_error_code() : 'allowed';
}

function aicfab_status( $result ) {
	if ( ! $result instanceof WP_Error ) {
		return 0;
	}
	$data = $result->get_error_data();
	return isset( $data['status'] ) ? (int) $data['status'] : 0;
}

// --- A missing or wrong nonce stops everything --------------------------------

aicfab_reset();
$GLOBALS['aicfab_nonce_ok'] = false;
$aicfab_bad                 = aicfab_gate();
check_stream( 'aicfab_bad_nonce' === aicfab_code( $aicfab_bad ), 'A bad nonce is refused, got ' . aicfab_code( $aicfab_bad ) );
check_stream( 403 === aicfab_status( $aicfab_bad ), 'A bad nonce answers 403.' );

aicfab_reset();
$aicfab_empty = aicfab_gate( array( 'nonce' => '' ) );
check_stream( 'aicfab_bad_nonce' === aicfab_code( $aicfab_empty ), 'An empty nonce is refused.' );

/*
 * The rate limit is a counter keyed per client, so a request rejected at the nonce must not
 * touch it. Otherwise anyone can exhaust a legitimate visitor's allowance without holding a
 * valid nonce, which turns the rate limit into a denial of service against its own users.
 */
aicfab_reset();
$GLOBALS['aicfab_nonce_ok'] = false;
for ( $aicfab_i = 0; $aicfab_i < 20; $aicfab_i++ ) {
	aicfab_gate();
}
check_stream( array() === $GLOBALS['aicfab_transients'], 'A request refused at the nonce consumes no rate limit.' );

$GLOBALS['aicfab_nonce_ok'] = true;
$GLOBALS['aicfab_logged_in'] = true;
check_stream( true === aicfab_gate(), 'A legitimate request still passes after that flood.' );

// --- Guests are refused unless the site opted in ------------------------------

aicfab_reset();
$aicfab_guest = aicfab_gate();
check_stream( 'aicfab_forbidden' === aicfab_code( $aicfab_guest ), 'An anonymous visitor is refused by default, got ' . aicfab_code( $aicfab_guest ) );
check_stream( 401 === aicfab_status( $aicfab_guest ), 'The refusal is 401, not 403.' );

aicfab_reset();
$GLOBALS['aicfab_logged_in'] = true;
check_stream( true === aicfab_gate(), 'A signed-in visitor is allowed.' );

aicfab_reset( array( 'allow_public_chat' => true ) );
check_stream( true === aicfab_gate(), 'A guest is allowed once the site enables public chat.' );

/*
 * A profile key that does not exist falls back to the site settings. That fallback must not be a
 * way in: if the site has not enabled public chat, naming a profile cannot grant it.
 */
aicfab_reset();
$aicfab_unknown_profile = aicfab_gate( array( 'profile' => 'no-such-profile' ) );
check_stream(
	'aicfab_forbidden' === aicfab_code( $aicfab_unknown_profile ),
	'An unknown profile key does not unlock guest access, got ' . aicfab_code( $aicfab_unknown_profile )
);

aicfab_reset();
$aicfab_dirty_profile = aicfab_gate( array( 'profile' => '../../etc/passwd' ) );
check_stream(
	'aicfab_forbidden' === aicfab_code( $aicfab_dirty_profile ),
	'A hostile profile key is sanitised and still refused.'
);

// A profile that exists and does not enable public chat is no different.
aicfab_reset();
$GLOBALS['aicfab_opts'][ AI_Chat_Bedrock_Profiles::OPTION ] = array(
	'support' => array( 'label' => 'Support', 'model_id' => 'amazon.nova-lite-v1:0' ),
);
$aicfab_real_profile = aicfab_gate( array( 'profile' => 'support' ) );
check_stream(
	'aicfab_forbidden' === aicfab_code( $aicfab_real_profile ),
	'A real profile does not grant guest access either.'
);

// --- Streaming off means the endpoint is closed -------------------------------

aicfab_reset( array( 'enable_streaming' => 'off' ) );
$GLOBALS['aicfab_logged_in'] = true;
$aicfab_off                  = aicfab_gate();
check_stream( 'aicfab_streaming_disabled' === aicfab_code( $aicfab_off ), 'With streaming disabled the route refuses, got ' . aicfab_code( $aicfab_off ) );
check_stream( 409 === aicfab_status( $aicfab_off ), 'It answers 409 rather than a generic error.' );

/*
 * The setting is stored as the strings 'on' and 'off'. Values that are merely falsy do not
 * disable it, which is deliberate, and is worth pinning because reading this field with
 * ! empty() instead was a real defect in the IAM policy generator.
 */
aicfab_reset( array( 'enable_streaming' => 'on' ) );
$GLOBALS['aicfab_logged_in'] = true;
check_stream( true === aicfab_gate(), "The string 'on' enables streaming." );
aicfab_reset();
unset( $GLOBALS['aicfab_opts']['ai_chat_bedrock_settings']['enable_streaming'] );
$GLOBALS['aicfab_logged_in'] = true;
check_stream( true === aicfab_gate(), 'An absent setting means streaming, matching the field label.' );

// --- The rate limit applies, and says so -------------------------------------

aicfab_reset( array( 'rate_limit_per_minute' => 3 ) );
$GLOBALS['aicfab_logged_in'] = true;
$aicfab_results              = array();
for ( $aicfab_i = 0; $aicfab_i < 5; $aicfab_i++ ) {
	$aicfab_results[] = aicfab_code( aicfab_gate() );
}
check_stream( 'allowed' === $aicfab_results[0], 'The first request is allowed.' );
check_stream( in_array( 'aicfab_rate_limited', $aicfab_results, true ), 'The limit eventually refuses, got ' . implode( ',', $aicfab_results ) );
check_stream( 3 === count( array_filter( $aicfab_results, function ( $r ) {
	return 'allowed' === $r;
} ) ), 'Exactly the configured number are allowed, got ' . implode( ',', $aicfab_results ) );

aicfab_reset( array( 'rate_limit_per_minute' => 3 ) );
$GLOBALS['aicfab_logged_in'] = true;
aicfab_gate();
aicfab_gate();
aicfab_gate();
$aicfab_limited = aicfab_gate();
check_stream( 429 === aicfab_status( $aicfab_limited ), 'Exceeding the limit answers 429.' );

// A limit of zero or nonsense must not mean unlimited.
aicfab_reset( array( 'rate_limit_per_minute' => 0 ) );
$GLOBALS['aicfab_logged_in'] = true;
$aicfab_zero                 = array();
for ( $aicfab_i = 0; $aicfab_i < 4; $aicfab_i++ ) {
	$aicfab_zero[] = aicfab_code( aicfab_gate() );
}
check_stream( in_array( 'aicfab_rate_limited', $aicfab_zero, true ), 'A limit of zero still bounds requests rather than removing the bound.' );

aicfab_reset( array( 'rate_limit_per_minute' => 999999 ) );
$GLOBALS['aicfab_logged_in'] = true;
$aicfab_huge                 = array();
for ( $aicfab_i = 0; $aicfab_i < AI_Chat_Bedrock_Rate_Limits::MAX_PER_ROLE + 5; $aicfab_i++ ) {
	$aicfab_huge[] = aicfab_code( aicfab_gate() );
}
check_stream(
	in_array( 'aicfab_rate_limited', $aicfab_huge, true ),
	'An absurd configured limit is capped at MAX_PER_ROLE rather than honoured.'
);

// --- The rate limit identifies a guest without storing their address ----------

/*
 * The guest bucket is keyed by an HMAC of the address rather than the address, so the limit
 * works without the site accumulating visitor IPs in its options table. That is a privacy
 * property of this code, not an accident of hashing, so it is asserted.
 */
aicfab_reset();
$_SERVER['REMOTE_ADDR']       = '203.0.113.45';
$GLOBALS['aicfab_logged_in']  = false;
$GLOBALS['aicfab_opts']['ai_chat_bedrock_settings']['allow_public_chat'] = true;
aicfab_gate();
$aicfab_keys = implode( '|', array_keys( $GLOBALS['aicfab_transients'] ) );
check_stream( '' !== $aicfab_keys, 'A guest request creates a rate limit bucket.' );
check_stream(
	false === strpos( $aicfab_keys, '203.0.113.45' ),
	'A visitor address never appears in a rate limit key.'
);

// Two different addresses must not share one allowance, or one visitor throttles everyone.
aicfab_reset( array( 'allow_public_chat' => true, 'rate_limit_per_minute' => 1 ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.45';
check_stream( true === aicfab_gate(), 'The first guest is allowed.' );
check_stream( 'aicfab_rate_limited' === aicfab_code( aicfab_gate() ), 'The same guest is then limited.' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
check_stream( true === aicfab_gate(), 'A different guest has their own allowance.' );

// A signed-in visitor is counted per account rather than per address.
aicfab_reset( array( 'rate_limit_per_minute' => 1 ) );
$GLOBALS['aicfab_logged_in'] = true;
$_SERVER['REMOTE_ADDR']      = '203.0.113.45';
check_stream( true === aicfab_gate(), 'A signed-in visitor is allowed.' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
check_stream(
	'aicfab_rate_limited' === aicfab_code( aicfab_gate() ),
	'Changing address does not reset a signed-in allowance.'
);

// --- The route is wired the way the gate assumes ------------------------------

$GLOBALS['aicfab_routes'] = array();
$aicfab_stream            = new AI_Chat_Bedrock_Stream();
$aicfab_stream->register_routes();
check_stream( 1 === count( $GLOBALS['aicfab_routes'] ), 'One streaming route is registered, got ' . count( $GLOBALS['aicfab_routes'] ) );

$aicfab_route = $GLOBALS['aicfab_routes'][0]['args'];
check_stream( isset( $aicfab_route['permission_callback'] ), 'The route has a permission callback.' );
check_stream( '__return_true' !== $aicfab_route['permission_callback'], 'The streaming route is never anonymously open.' );
check_stream(
	is_array( $aicfab_route['permission_callback'] ) && 'check_permission' === $aicfab_route['permission_callback'][1],
	'The callback is the gate asserted above, not some other function.'
);
check_stream( 'POST' === $aicfab_route['methods'], 'Streaming is POST only.' );

/*
 * The parameter schema is the layer that stops message[]=x before the handler sees it, which is
 * the same malformed input that once reached the model as the literal string "Array".
 */
function aicfab_arg( $route, $name, $field ) {
	return isset( $route['args'][ $name ][ $field ] ) ? $route['args'][ $name ][ $field ] : null;
}
check_stream( 'string' === aicfab_arg( $aicfab_route, 'message', 'type' ), 'The message parameter is declared a string.' );
check_stream( true === aicfab_arg( $aicfab_route, 'message', 'required' ), 'The message parameter is required.' );
check_stream( 'string' === aicfab_arg( $aicfab_route, 'nonce', 'type' ), 'The nonce parameter is declared a string.' );
check_stream( true === aicfab_arg( $aicfab_route, 'nonce', 'required' ), 'The nonce parameter is required.' );
check_stream( 'string' === aicfab_arg( $aicfab_route, 'history', 'type' ), 'The history parameter is declared a string.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: streaming gate checks passed\n";
