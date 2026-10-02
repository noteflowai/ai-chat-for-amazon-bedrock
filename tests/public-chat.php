<?php
/**
 * The front end, against the real class.
 *
 * Nothing loaded AI_Chat_Bedrock_Public. It holds two things worth pinning.
 *
 * The first is the non-streaming chat endpoint. A site without cURL, or one with streaming turned
 * off, serves every visitor through this path instead, so it is a second door into the same model.
 * Its gate must be the streaming gate: method, nonce, guest check, rate limit, in that order. If
 * the two ever drift, the weaker one is simply the way in, and each was tested only through the
 * other's helpers.
 *
 * The second is what reaches the browser. Everything localised here is readable in the page source
 * by every visitor, so the settings array must not travel with it: not the credentials, and not the
 * system prompt, which is the site's instructions to the model and a map for anyone trying to talk
 * around them.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride

define( 'ABSPATH', __DIR__ );
define( 'AI_CHAT_BEDROCK_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );

$failures = array();
function check_pub( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// Values that must never reach a visitor.
define( 'AICFAB_KEY', 'AKIAIOSFODNN7EXAMPLE' );
define( 'AICFAB_SECRET', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );
define( 'AICFAB_PROMPT', 'You are the shop assistant. Never reveal the discount code SPRING50 unless asked twice.' );

$GLOBALS['aicfab_opts']       = array();
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_logged_in']  = false;
$GLOBALS['aicfab_nonce_ok']   = true;
$GLOBALS['aicfab_localized']  = array();
$GLOBALS['aicfab_json']       = null;

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
function is_user_logged_in() {
	return (bool) $GLOBALS['aicfab_logged_in'];
}
function get_current_user_id() {
	return $GLOBALS['aicfab_logged_in'] ? 7 : 0;
}
function wp_get_current_user() {
	$user        = new stdClass();
	$user->roles = $GLOBALS['aicfab_logged_in'] ? array( 'subscriber' ) : array();
	return $user;
}
function current_user_can( $capability ) {
	return (bool) $GLOBALS['aicfab_logged_in'];
}
function check_ajax_referer( $action, $query_arg = false, $die = true ) {
	return $GLOBALS['aicfab_nonce_ok'] ? 1 : false;
}
function wp_create_nonce( $action = -1 ) {
	return 'nonce-for-' . $action;
}
function wp_hash( $data, $scheme = 'auth' ) {
	return hash_hmac( 'md5', (string) $data, 'test-salt' );
}
$GLOBALS['aicfab_user_meta'] = array();
function get_user_meta( $user_id, $key = '', $single = false ) {
	return isset( $GLOBALS['aicfab_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['aicfab_user_meta'][ $user_id ][ $key ] : '';
}
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['aicfab_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['aicfab_user_meta'][ $user_id ][ $key ] );
	return true;
}
function esc_url_raw( $url, $protocols = null ) {
	return preg_match( '#^https?://#', (string) $url ) ? (string) $url : '';
}
function wp_salt( $scheme = 'auth' ) {
	return 'test-salt';
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
function wp_unslash( $value ) {
	return $value;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function __( $text, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_url( $url ) {
	return $url;
}
function apply_filters( $hook, $value ) {
	return isset( $GLOBALS['aicfab_filtered'][ $hook ] ) ? $GLOBALS['aicfab_filtered'][ $hook ] : $value;
}
function home_url( $path = '' ) {
	return 'https://example.com' . $path;
}
function wp_login_url( $redirect = '' ) {
	return 'https://example.com/wp-login.php' . ( '' !== $redirect ? '?redirect_to=' . rawurlencode( $redirect ) : '' );
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	return true;
}
function admin_url( $path = '' ) {
	return 'https://example.com/wp-admin/' . $path;
}
function rest_url( $path = '' ) {
	return 'https://example.com/wp-json/' . $path;
}
function plugin_dir_url( $file ) {
	return 'https://example.com/wp-content/plugins/ai-chat-for-amazon-bedrock/' . basename( dirname( $file ) ) . '/';
}
function plugin_dir_path( $file ) {
	return dirname( __DIR__ ) . '/public/';
}
/*
 * WordPress's asset registry, as far as it matters here: a handle is registered once. A later
 * wp_register_*(), or a wp_enqueue_*() that brings a source, for a handle already taken changes
 * nothing, and the first file stays. Localised data goes with the handle.
 */
$GLOBALS['aicfab_assets']       = array();
$GLOBALS['aicfab_localized_on'] = array();
function aicfab_asset_add( $type, $handle, $src, $deps ) {
	if ( isset( $GLOBALS['aicfab_assets'][ $type ][ $handle ] ) ) {
		return false;
	}
	$GLOBALS['aicfab_assets'][ $type ][ $handle ] = array( 'src' => $src, 'deps' => (array) $deps, 'enqueued' => false );
	return true;
}
function aicfab_asset_enqueue( $type, $handle, $src, $deps ) {
	if ( '' !== $src ) {
		aicfab_asset_add( $type, $handle, $src, $deps );
	}
	if ( isset( $GLOBALS['aicfab_assets'][ $type ][ $handle ] ) ) {
		$GLOBALS['aicfab_assets'][ $type ][ $handle ]['enqueued'] = true;
	}
}
function wp_register_script( $handle, $src = '', $deps = array(), ...$rest ) {
	return aicfab_asset_add( 'script', $handle, $src, $deps );
}
function wp_register_style( $handle, $src = '', $deps = array(), ...$rest ) {
	return aicfab_asset_add( 'style', $handle, $src, $deps );
}
function wp_enqueue_script( $handle, $src = '', $deps = array(), ...$rest ) {
	aicfab_asset_enqueue( 'script', $handle, $src, $deps );
}
function wp_enqueue_style( $handle, $src = '', $deps = array(), ...$rest ) {
	aicfab_asset_enqueue( 'style', $handle, $src, $deps );
}
function wp_localize_script( $handle, $name, $data ) {
	$GLOBALS['aicfab_localized'][ $name ]    = $data;
	$GLOBALS['aicfab_localized_on'][ $name ] = $handle;
	return true;
}
/** The file behind a handle, or '' for none. */
function aicfab_file( $type, $handle ) {
	return isset( $GLOBALS['aicfab_assets'][ $type ][ $handle ] ) ? basename( (string) parse_url( $GLOBALS['aicfab_assets'][ $type ][ $handle ]['src'], PHP_URL_PATH ) ) : '';
}
/** The files the page loads, of one type. */
function aicfab_loaded( $type ) {
	$files = array();
	foreach ( isset( $GLOBALS['aicfab_assets'][ $type ] ) ? $GLOBALS['aicfab_assets'][ $type ] : array() as $handle => $asset ) {
		if ( $asset['enqueued'] ) {
			$files[] = aicfab_file( $type, $handle );
		}
	}
	return $files;
}
function is_admin() {
	return ! empty( $GLOBALS['aicfab_is_admin'] );
}
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default_value ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default_value;
	}
	return $out;
}
function has_shortcode( $content, $tag ) {
	return false !== strpos( (string) $content, '[' . $tag );
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function register_block_type( ...$args ) {
	return true;
}

// The remaining WordPress surface the class and its template touch, taken from the source rather
// than discovered one fatal error at a time.
$GLOBALS['aicfab_post'] = null;
function is_singular( $types = '' ) {
	return null !== $GLOBALS['aicfab_post'];
}
function get_post( $post = null ) {
	return $GLOBALS['aicfab_post'];
}
function has_block( $block, $post = null ) {
	return null !== $GLOBALS['aicfab_post'] && false !== strpos( (string) $GLOBALS['aicfab_post']->post_content, '<!-- wp:' . $block );
}
function esc_attr_e( $text, $domain = null ) {
	echo esc_attr( $text );
}
function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text );
}
function wp_unique_id( $prefix = '' ) {
	static $counter = 0;
	++$counter;
	return $prefix . $counter;
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
 * wp_send_json* end the request. Thrown instead, so each refusal can be inspected.
 */
class Aicfab_Sent extends Exception {
	public $payload;
	public $status;
	public $ok;
	public function __construct( $payload, $status, $ok ) {
		parent::__construct( 'sent' );
		$this->payload = $payload;
		$this->status  = $status;
		$this->ok      = $ok;
	}
}
function wp_send_json_error( $data = null, $status = 0 ) {
	throw new Aicfab_Sent( $data, $status, false );
}
function wp_send_json_success( $data = null, $status = 0 ) {
	throw new Aicfab_Sent( $data, $status, true );
}
function wp_send_json( $response, $status = 0 ) {
	throw new Aicfab_Sent( $response, $status, ! empty( $response['success'] ) );
}

/**
 * No model is called here; the gate is what is being examined.
 */
class AI_Chat_Bedrock_AWS {
	public function __construct( $overrides = array() ) {}
	public static function streaming_supported() {
		return true;
	}
	// chat_is_ready() refuses to render a chat that cannot answer, so this decides whether the
	// shortcode produces a widget or a notice.
	public function has_credentials() {
		return empty( $GLOBALS['aicfab_no_credentials'] );
	}
	public function handle_chat_message( $request ) {
		$GLOBALS['aicfab_model_calls'][] = $request;
		return array( 'success' => true, 'data' => array( 'message' => 'answered' ), 'usage' => array( 'input_tokens' => 1, 'output_tokens' => 1 ) );
	}
}
class AI_Chat_Bedrock_Tool_Runner {
	public static function run( $aws, $messages, $message, $on_delta = null, $on_round = null ) {
		return $aws->handle_chat_message( array( 'messages' => $messages ) );
	}
}
class AI_Chat_Bedrock_Conversations {
	public static function enabled() {
		return false;
	}
	public static function record( $question, $answer, $meta = array() ) {
		return '';
	}
}
class AI_Chat_Bedrock_Retrieval {
	public static function context( $query, $options = null, &$score = null, &$weak = null, &$sources = null ) {
		$sources = isset( $GLOBALS['aicfab_sources'] ) ? $GLOBALS['aicfab_sources'] : array();
		return $sources ? 'Reference material' : '';
	}
}
class AI_Chat_Bedrock_Feedback {
	const REST_ROUTE = '/feedback';
}
class AI_Chat_Bedrock_Generator_Stream {
	const REST_ROUTE = '/generate';
}
class AI_Chat_Bedrock_Models {
	public static function refresh() {
		return array( 'jp.anthropic.claude-haiku-4-5' => 'Claude Haiku 4.5', 'global.openai.gpt-6-luna' => 'GPT-6 Luna' );
	}
}
function _x( $text, $context, $domain = null ) {
	return $text;
}
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-content.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-profiles.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-translation.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-rate-limits.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-chat-request.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wp-mcp-server.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-stream.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-chat-history.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-woocommerce.php';
require dirname( __DIR__ ) . '/public/class-ai-chat-bedrock-public.php';
require dirname( __DIR__ ) . '/admin/class-ai-chat-bedrock-admin.php';

function aicfab_reset_pub( $settings = array() ) {
	$GLOBALS['aicfab_transients'] = array();
	$GLOBALS['aicfab_nonce_ok']   = true;
	$GLOBALS['aicfab_logged_in']  = false;
	$GLOBALS['aicfab_model_calls'] = array();
	$_SERVER['REQUEST_METHOD']    = 'POST';
	$_POST                        = array( 'message' => 'What is your refund policy?', 'nonce' => 'n' );
	$GLOBALS['aicfab_opts']       = array(
		'ai_chat_bedrock_settings' => array_merge(
			array(
				'enable_streaming'      => 'on',
				'rate_limit_per_minute' => 5,
				'model_id'              => 'amazon.nova-lite-v1:0',
				'aws_access_key'        => AICFAB_KEY,
				'aws_secret_key'        => AICFAB_SECRET,
				'system_prompt'         => AICFAB_PROMPT,
				'welcome_message'       => 'Hello there.',
			),
			$settings
		),
	);
}

/**
 * Run the AJAX endpoint and report how it answered.
 */
function aicfab_ajax() {
	$public = new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' );
	try {
		$public->handle_chat_message();
	} catch ( Aicfab_Sent $sent ) {
		return $sent;
	}
	return null;
}

// --- The non-streaming path applies the same gate ------------------------------

aicfab_reset_pub();
$_SERVER['REQUEST_METHOD'] = 'GET';
$aicfab_get                = aicfab_ajax();
check_pub( null !== $aicfab_get && 405 === $aicfab_get->status, 'A GET is refused with 405.' );
check_pub( array() === $GLOBALS['aicfab_model_calls'], 'A GET never reaches the model.' );

aicfab_reset_pub();
$GLOBALS['aicfab_nonce_ok'] = false;
$aicfab_nonce               = aicfab_ajax();
check_pub( null !== $aicfab_nonce && 403 === $aicfab_nonce->status, 'A bad nonce is refused with 403.' );
check_pub( array() === $GLOBALS['aicfab_model_calls'], 'A bad nonce never reaches the model.' );
check_pub( array() === $GLOBALS['aicfab_transients'], 'A bad nonce consumes no rate limit.' );
check_pub( isset( $aicfab_nonce->payload['code'] ) && 'aicfab_bad_nonce' === $aicfab_nonce->payload['code'], 'A bad nonce says so, so the script can refresh it and retry.' );

// --- A cached page can get fresh nonces ----------------------------------------

function aicfab_refresh() {
	try {
		( new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' ) )->handle_refresh_nonce();
	} catch ( Aicfab_Sent $sent ) {
		return $sent;
	}
	return null;
}
aicfab_reset_pub();
$_SERVER['REQUEST_METHOD'] = 'GET';
$aicfab_refreshed          = aicfab_refresh();
check_pub( null !== $aicfab_refreshed && 405 === $aicfab_refreshed->status, 'Nonces are only handed out to a POST, which no cache stores.' );
aicfab_reset_pub();
$aicfab_refreshed = aicfab_refresh();
check_pub( null !== $aicfab_refreshed && $aicfab_refreshed->ok && 'nonce-for-ai_chat_bedrock_nonce' === $aicfab_refreshed->payload['nonce'] && 'nonce-for-wp_rest' === $aicfab_refreshed->payload['rest_nonce'], 'A refresh returns the chat and REST nonces.' );
for ( $aicfab_i = 0; $aicfab_i < 30; $aicfab_i++ ) {
	$aicfab_refreshed = aicfab_refresh();
}
check_pub( null !== $aicfab_refreshed && 429 === $aicfab_refreshed->status, 'Refreshing is rate limited.' );
check_pub( false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock.php' ), "'wp_ajax_nopriv_ai_chat_bedrock_refresh_nonce', \$public, 'handle_refresh_nonce'" ), 'Guests can refresh their nonces.' );

// --- Sources are sent only when the site shows them ----------------------------

aicfab_reset_pub();
$GLOBALS['aicfab_logged_in'] = true;
$GLOBALS['aicfab_sources']   = array( array( 'title' => 'Refunds', 'url' => 'https://example.test/refunds/' ) );
$aicfab_hidden               = aicfab_ajax();
check_pub( null !== $aicfab_hidden && $aicfab_hidden->ok && ! isset( $aicfab_hidden->payload['data']['sources'] ), 'Sources are not sent while the setting is off.' );
aicfab_reset_pub( array( 'show_sources' => true ) );
$GLOBALS['aicfab_logged_in'] = true;
$aicfab_shown                = aicfab_ajax();
check_pub( null !== $aicfab_shown && $GLOBALS['aicfab_sources'] === $aicfab_shown->payload['data']['sources'], 'Sources are sent with the answer once the site shows them.' );
$GLOBALS['aicfab_sources'] = array();

// An answered question is saved with the account only when the site saves conversations.
$GLOBALS['aicfab_user_meta'] = array();
aicfab_reset_pub( array( 'chat_memory' => 'tab' ) );
$GLOBALS['aicfab_logged_in'] = true;
aicfab_ajax();
check_pub( array() === $GLOBALS['aicfab_user_meta'], 'Tab memory saves nothing on the site.' );
aicfab_reset_pub( array( 'chat_memory' => 'account', 'show_sources' => true ) );
$GLOBALS['aicfab_logged_in'] = true;
$GLOBALS['aicfab_sources']   = array( array( 'title' => 'Refunds', 'url' => 'https://example.test/refunds/' ) );
$aicfab_saved_reply          = aicfab_ajax();
$aicfab_saved                = AI_Chat_Bedrock_Chat_History::get( 7, '' );
check_pub( null !== $aicfab_saved_reply && $aicfab_saved_reply->ok && 2 === count( $aicfab_saved ) && 'What is your refund policy?' === $aicfab_saved[0]['content'] && 'assistant' === $aicfab_saved[1]['role'], 'Account memory saves the question and the answer.' );
check_pub( isset( $aicfab_saved[1]['sources'][0]['url'] ) && 'https://example.test/refunds/' === $aicfab_saved[1]['sources'][0]['url'], 'The answer is saved with its sources.' );
$GLOBALS['aicfab_sources']   = array();
$GLOBALS['aicfab_user_meta'] = array();

aicfab_reset_pub();
$aicfab_guest = aicfab_ajax();
check_pub( null !== $aicfab_guest && 401 === $aicfab_guest->status, 'An anonymous visitor is refused with 401.' );
check_pub( array() === $GLOBALS['aicfab_model_calls'], 'An anonymous visitor never reaches the model.' );

aicfab_reset_pub();
$GLOBALS['aicfab_logged_in'] = true;
$aicfab_ok                   = aicfab_ajax();
check_pub( null !== $aicfab_ok && $aicfab_ok->ok, 'A signed-in visitor gets an answer.' );
check_pub( 1 === count( $GLOBALS['aicfab_model_calls'] ), 'One model call per request.' );

aicfab_reset_pub( array( 'allow_public_chat' => true ) );
$aicfab_public_ok = aicfab_ajax();
check_pub( null !== $aicfab_public_ok && $aicfab_public_ok->ok, 'A guest is answered once the site enables public chat.' );

aicfab_reset_pub( array( 'rate_limit_per_minute' => 2 ) );
$GLOBALS['aicfab_logged_in'] = true;
aicfab_ajax();
aicfab_ajax();
$aicfab_limited = aicfab_ajax();
check_pub( null !== $aicfab_limited && 429 === $aicfab_limited->status, 'The rate limit applies here too, with 429.' );
check_pub( 2 === count( $GLOBALS['aicfab_model_calls'] ), 'The refused request did not reach the model.' );

// An unknown profile must not be a way past the guest check on this path either.
aicfab_reset_pub();
$_POST['profile'] = 'no-such-profile';
$aicfab_unknown   = aicfab_ajax();
check_pub( null !== $aicfab_unknown && 401 === $aicfab_unknown->status, 'An unknown profile does not unlock guest access here.' );

// A malformed message is refused before the model, as the streaming path does.
aicfab_reset_pub();
$GLOBALS['aicfab_logged_in'] = true;
$_POST['message']            = '';
$aicfab_empty                = aicfab_ajax();
check_pub( null !== $aicfab_empty && ! $aicfab_empty->ok, 'An empty message is refused.' );
check_pub( array() === $GLOBALS['aicfab_model_calls'], 'An empty message never reaches the model.' );

aicfab_reset_pub();
$GLOBALS['aicfab_logged_in'] = true;
$_POST['message']            = str_repeat( 'x', AI_Chat_Bedrock_Chat_Request::MAX_MESSAGE_CHARS + 10 );
$aicfab_long                 = aicfab_ajax();
check_pub( null !== $aicfab_long && ! $aicfab_long->ok, 'An over-long message is refused.' );
check_pub( array() === $GLOBALS['aicfab_model_calls'], 'An over-long message never reaches the model.' );

// --- The two visitor doors must agree -----------------------------------------

/*
 * Both entry points are compared as source rather than only behaviour, because what matters is
 * that neither grows a hole the other does not have. A site without cURL serves everyone through
 * the AJAX path, so a weaker gate there is not a lesser bug, it is the bug.
 */
$aicfab_stream_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-stream.php' );
$aicfab_public_src = file_get_contents( dirname( __DIR__ ) . '/public/class-ai-chat-bedrock-public.php' );

foreach ( array(
	'a guest check'   => 'AI_Chat_Bedrock_Security::can_use_chat',
	'a rate limit'    => 'AI_Chat_Bedrock_Security::check_rate_limit',
	'the role cap'    => 'AI_Chat_Bedrock_Rate_Limits::MAX_PER_ROLE',
	'profile resolve' => 'AI_Chat_Bedrock_Profiles::resolve',
	'request build'   => 'AI_Chat_Bedrock_Chat_Request::build',
) as $aicfab_label => $aicfab_needle ) {
	check_pub( false !== strpos( $aicfab_stream_src, $aicfab_needle ), 'The streaming path has ' . $aicfab_label . '.' );
	check_pub( false !== strpos( $aicfab_public_src, $aicfab_needle ), 'The AJAX path has ' . $aicfab_label . '.' );
}

// Each path verifies a nonce, by the mechanism appropriate to it.
check_pub( false !== strpos( $aicfab_stream_src, 'wp_verify_nonce' ), 'The streaming path verifies a nonce.' );
check_pub( false !== strpos( $aicfab_public_src, 'check_ajax_referer' ), 'The AJAX path verifies a nonce.' );

// --- What the browser is given -------------------------------------------------

aicfab_reset_pub();
$GLOBALS['aicfab_localized'] = array();
$aicfab_public               = new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' );
$aicfab_public->enqueue_scripts();

check_pub( isset( $GLOBALS['aicfab_localized']['ai_chat_bedrock_params'] ), 'The front end is given its parameters.' );
$aicfab_sent_to_browser = wp_json_encode( $GLOBALS['aicfab_localized'] );

check_pub( false === strpos( $aicfab_sent_to_browser, AICFAB_KEY ), 'No access key id reaches the browser.' );
check_pub( false === strpos( $aicfab_sent_to_browser, AICFAB_SECRET ), 'No secret key reaches the browser.' );
check_pub( false === strpos( $aicfab_sent_to_browser, 'wJalrXUtnFEMI' ), 'Not even a fragment of the secret.' );
check_pub( false === strpos( $aicfab_sent_to_browser, AICFAB_PROMPT ), 'The system prompt is not published to visitors.' );
check_pub( false === strpos( $aicfab_sent_to_browser, 'SPRING50' ), 'Nothing the system prompt contains is published either.' );
check_pub( false === strpos( $aicfab_sent_to_browser, 'amazon.nova-lite-v1:0' ), 'The model identifier is not advertised.' );

// What it does need, it gets.
$aicfab_params = $GLOBALS['aicfab_localized']['ai_chat_bedrock_params'];
check_pub( ! empty( $aicfab_params['nonce'] ), 'A chat nonce is provided.' );
check_pub( ! empty( $aicfab_params['rest_nonce'] ), 'A REST nonce is provided, which the streaming request needs.' );
check_pub( ! empty( $aicfab_params['ajax_url'] ), 'The AJAX url is provided.' );
check_pub( ! empty( $aicfab_params['stream_url'] ), 'The streaming url is provided.' );
check_pub(
	AI_Chat_Bedrock_Chat_Request::MAX_MESSAGE_CHARS === $aicfab_params['max_message_chars'],
	'The character limit shown to the browser matches the one enforced on the server.'
);

// With conversation logging off, no feedback endpoint is advertised.
check_pub( '' === $aicfab_params['feedback_url'], 'No feedback url is given when logging is off.' );

// --- Conversation memory --------------------------------------------------------

check_pub( '' === $aicfab_params['memory'] && '' === $aicfab_params['history_url'] && '0' === $aicfab_params['user_key'], 'Memory is off by default, and a guest gets no account key or history url.' );
aicfab_reset_pub( array( 'chat_memory' => 'account' ) );
$aicfab_public->enqueue_scripts();
$aicfab_params = $GLOBALS['aicfab_localized']['ai_chat_bedrock_params'];
check_pub( 'account' === $aicfab_params['memory'] && '' === $aicfab_params['history_url'], 'A guest keeps the conversation in the tab only, with no saved history to fetch.' );
$GLOBALS['aicfab_logged_in'] = true;
$aicfab_public->enqueue_scripts();
$aicfab_params = $GLOBALS['aicfab_localized']['ai_chat_bedrock_params'];
check_pub( false !== strpos( $aicfab_params['history_url'], 'ai-chat-bedrock/v1/history' ), 'A signed-in visitor is given the saved history url.' );
check_pub( 1 === preg_match( '/^[a-f0-9]{16}$/', $aicfab_params['user_key'] ) && false === strpos( wp_json_encode( $aicfab_params ), '"7"' ), 'The tab key is a hash, not the user ID.' );
aicfab_reset_pub( array( 'chat_memory' => 'tab' ) );
$GLOBALS['aicfab_logged_in'] = true;
$aicfab_public->enqueue_scripts();
$aicfab_params = $GLOBALS['aicfab_localized']['ai_chat_bedrock_params'];
check_pub( 'tab' === $aicfab_params['memory'] && '' === $aicfab_params['history_url'], 'Tab memory stores nothing on the site, so there is nothing to fetch.' );

// --- Shortcode attributes are bounded -----------------------------------------

aicfab_reset_pub( array( 'allow_public_chat' => true, 'chat_title' => 'Ask us' ) );
$aicfab_markup = $aicfab_public->display_chat_interface( array( 'height' => '600px', 'width' => '80%' ) );
check_pub( is_string( $aicfab_markup ) && '' !== $aicfab_markup, 'The shortcode renders something.' );
check_pub( false === strpos( $aicfab_markup, AICFAB_PROMPT ), 'The rendered markup does not contain the system prompt.' );
check_pub( false === strpos( $aicfab_markup, AICFAB_SECRET ), 'The rendered markup does not contain a credential.' );

/*
 * Both dimensions land inside a style attribute. esc_attr is not enough there: it escapes quotes
 * and angle brackets, so it stops an attribute being broken out of, but it does nothing about
 * extra CSS declarations appended after a semicolon. sanitize_dimension is the only thing
 * standing between an attribute value and arbitrary CSS, so each dimension is checked with a
 * payload aimed at that, not only with a script tag that esc_attr would have caught anyway.
 */
$aicfab_css_payload = '600px;background:url(javascript:alert(1))';

$aicfab_bad_height = $aicfab_public->display_chat_interface( array( 'height' => $aicfab_css_payload ) );
check_pub( false === strpos( $aicfab_bad_height, 'javascript:' ), 'CSS appended to a height is not rendered.' );
check_pub( false === strpos( $aicfab_bad_height, 'background:url' ), 'No extra declaration survives in the height.' );
check_pub( false !== strpos( $aicfab_bad_height, 'height: 500px' ), 'The height falls back to its default.' );

$aicfab_bad_width = $aicfab_public->display_chat_interface( array( 'width' => $aicfab_css_payload ) );
check_pub( false === strpos( $aicfab_bad_width, 'javascript:' ), 'CSS appended to a width is not rendered.' );
check_pub( false === strpos( $aicfab_bad_width, 'background:url' ), 'No extra declaration survives in the width.' );
check_pub( false !== strpos( $aicfab_bad_width, 'width: 100%' ), 'The width falls back to its default.' );

// Breaking out of the attribute entirely, which escaping does handle.
$aicfab_breakout = $aicfab_public->display_chat_interface( array( 'width' => '"><script>alert(1)</script>' ) );
check_pub( false === strpos( $aicfab_breakout, '<script>alert(1)</script>' ), 'A script tag in a dimension is not rendered.' );

// Legitimate values are kept, or the sanitiser would be a blunt instrument.
$aicfab_good = $aicfab_public->display_chat_interface( array( 'width' => '80%', 'height' => '42rem' ) );
check_pub( false !== strpos( $aicfab_good, 'width: 80%' ), 'A valid width is kept.' );
check_pub( false !== strpos( $aicfab_good, 'height: 42rem' ), 'A valid height is kept.' );

$aicfab_hostile_title = $aicfab_public->display_chat_interface( array( 'title' => '<script>alert(1)</script>Hi' ) );
check_pub( false === strpos( $aicfab_hostile_title, '<script>alert(1)</script>' ), 'A script tag in the title is not rendered.' );

// --- A visitor the chat is closed to is asked to sign in --------------------------
// Otherwise a guest typed a question and only then learned that the chat was for members.

aicfab_reset_pub( array( 'allow_public_chat' => false ) );
$_SERVER['REQUEST_URI'] = '/en/course/?ref=1';
$aicfab_guest_view      = $aicfab_public->display_chat_interface( array( 'mode' => 'popup' ) );
check_pub( false !== strpos( $aicfab_guest_view, 'is-signed-out' ) && false !== strpos( $aicfab_guest_view, 'Sign in to chat with the assistant.' ), 'A guest sees a sign-in prompt: ' . $aicfab_guest_view );
check_pub( false !== strpos( $aicfab_guest_view, 'href="https://example.com/wp-login.php?redirect_to=' . rawurlencode( 'https://example.com/en/course/?ref=1' ) . '"' ), 'Signing in returns to the page the guest was on.' );
check_pub( false === strpos( $aicfab_guest_view, '<textarea' ) && false === strpos( $aicfab_guest_view, 'ai-chat-bedrock-suggestion' ), 'A guest gets no message box or suggested questions.' );
check_pub( false !== strpos( $aicfab_guest_view, 'ai-chat-bedrock-launcher' ), 'The launcher is still there, so the assistant can be found.' );

$GLOBALS['aicfab_logged_in'] = true;
$aicfab_member_view          = $aicfab_public->display_chat_interface( array( 'mode' => 'popup' ) );
check_pub( false === strpos( $aicfab_member_view, 'is-signed-out' ) && false !== strpos( $aicfab_member_view, '<textarea' ), 'A signed-in visitor gets the message box.' );

aicfab_reset_pub( array( 'allow_public_chat' => true ) );
check_pub( false === strpos( $aicfab_public->display_chat_interface( array() ), 'is-signed-out' ), 'Guests chat right away where guest chat is on.' );

aicfab_reset_pub( array( 'allow_public_chat' => false ) );
$GLOBALS['aicfab_filtered']['ai_chat_bedrock_sign_in_url'] = '';
check_pub( '' === $aicfab_public->display_chat_interface( array( 'mode' => 'popup' ) ), 'A site can leave the chat out for guests.' );
$GLOBALS['aicfab_filtered']['ai_chat_bedrock_sign_in_url'] = 'https://example.com/members/';
check_pub( false !== strpos( $aicfab_public->display_chat_interface( array() ), 'href="https://example.com/members/"' ), 'A site can send guests to its own sign-in page.' );
unset( $GLOBALS['aicfab_filtered'] );
unset( $_SERVER['REQUEST_URI'] );

$aicfab_public_js = file_get_contents( dirname( __DIR__ ) . '/public/js/ai-chat-bedrock-public.js' );
check_pub( false !== strpos( $aicfab_public_js, "hasClass('is-signed-out')" ), 'The script leaves a sign-in prompt alone.' );

// --- The Test Chat screen in wp-admin ------------------------------------------

/*
 * The screen renders the shortcode inside wp-admin, so the admin assets are enqueued first
 * (admin_enqueue_scripts) and the chat's own when the shortcode runs. Both have to reach the page,
 * each with its own data. While they shared a handle, the chat there had neither its script nor
 * its styles: a chat box that did nothing.
 */
aicfab_reset_pub();
$GLOBALS['aicfab_assets']       = array();
$GLOBALS['aicfab_localized']    = array();
$GLOBALS['aicfab_localized_on'] = array();
$GLOBALS['aicfab_is_admin']     = true;
$GLOBALS['aicfab_logged_in']    = true;
$aicfab_admin                   = new AI_Chat_Bedrock_Admin( 'ai-chat-for-amazon-bedrock', 'test' );
$aicfab_admin->enqueue_styles( 'ai-chat-bedrock_page_ai-chat-for-amazon-bedrock-test' );
$aicfab_admin->enqueue_scripts( 'ai-chat-bedrock_page_ai-chat-for-amazon-bedrock-test' );
$aicfab_test_screen = ( new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' ) )->display_chat_interface( array( 'width' => '600px', 'height' => '500px' ) );

check_pub( false !== strpos( $aicfab_test_screen, 'ai-chat-bedrock-container' ), 'The Test Chat screen renders the chat.' );
check_pub( in_array( 'ai-chat-bedrock-public.js', aicfab_loaded( 'script' ), true ), 'The Test Chat screen loads the chat script.' );
check_pub( in_array( 'ai-chat-bedrock-public.css', aicfab_loaded( 'style' ), true ), 'The Test Chat screen loads the chat styles.' );
check_pub( in_array( 'ai-chat-bedrock-admin.js', aicfab_loaded( 'script' ), true ), 'The admin script still loads there.' );
check_pub( in_array( 'ai-chat-bedrock-admin.css', aicfab_loaded( 'style' ), true ), 'The admin styles still load there.' );
check_pub(
	'ai-chat-bedrock-public.js' === aicfab_file( 'script', $GLOBALS['aicfab_localized_on']['ai_chat_bedrock_params'] ?? '' ),
	'The chat parameters go with the chat script.'
);
check_pub(
	'ai-chat-bedrock-admin.js' === aicfab_file( 'script', $GLOBALS['aicfab_localized_on']['ai_chat_bedrock_admin'] ?? '' ),
	'The admin parameters go with the admin script.'
);

// The MCP screen's script names the admin script as a dependency, by handle.
$GLOBALS['aicfab_assets'] = array();
$aicfab_admin->enqueue_scripts( 'ai-chat-bedrock_page_ai-chat-for-amazon-bedrock-mcp' );
$aicfab_mcp_deps = $GLOBALS['aicfab_assets']['script']['ai-chat-for-amazon-bedrock-mcp']['deps'] ?? array();
check_pub( in_array( 'ai-chat-bedrock-admin.js', array_map( function ( $handle ) { return aicfab_file( 'script', $handle ); }, $aicfab_mcp_deps ), true ), 'The MCP script depends on the admin script.' );

// The front end still uses the plain plugin name, which themes may dequeue by.
$GLOBALS['aicfab_assets']   = array();
$GLOBALS['aicfab_is_admin'] = false;
( new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' ) )->display_chat_interface( array() );
check_pub( 'ai-chat-bedrock-public.js' === aicfab_file( 'script', 'ai-chat-for-amazon-bedrock' ), 'The chat script keeps its handle.' );
check_pub( 'ai-chat-bedrock-public.css' === aicfab_file( 'style', 'ai-chat-for-amazon-bedrock' ), 'The chat styles keep their handle.' );

// --- A chat with a profile, and the chat block -----------------------------------

// Clearing the chat put back the site-wide greeting instead of the profile's, because the
// script only had the site-wide one. The markup now carries the one it showed.
aicfab_reset_pub();
$GLOBALS['aicfab_opts'][ AI_Chat_Bedrock_Profiles::OPTION ] = array( 'support' => array( 'welcome_message' => 'Support desk here.' ) );
$aicfab_profiled = ( new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' ) )->display_chat_interface( array( 'profile' => 'support' ) );
check_pub( false !== strpos( $aicfab_profiled, 'data-welcome="Support desk here."' ), 'The chat carries its profile\'s greeting for when it is cleared.' );
$aicfab_default = ( new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' ) )->display_chat_interface( array() );
check_pub( false !== strpos( $aicfab_default, 'data-welcome="Hello there."' ), 'Without a profile it carries the site greeting.' );
$aicfab_script = file_get_contents( dirname( __DIR__ ) . '/public/js/ai-chat-bedrock-public.js' );

// The chat followed the visitor's device, so a phone in dark mode got a dark panel on a light theme.
check_pub( false !== strpos( $aicfab_default, 'data-scheme="light"' ), 'The chat is light unless chosen otherwise.' );
aicfab_reset_pub( array( 'chat_color_scheme' => 'auto' ) );
$aicfab_auto = ( new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' ) )->display_chat_interface( array() );
check_pub( false !== strpos( $aicfab_auto, 'data-scheme="auto"' ), 'The chat can follow the visitor\'s device.' );
aicfab_reset_pub( array( 'chat_color_scheme' => '" onmouseover="x' ) );
$aicfab_bad = ( new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' ) )->display_chat_interface( array() );
check_pub( false !== strpos( $aicfab_bad, 'data-scheme="light"' ), 'An unknown color scheme falls back to light.' );
$aicfab_css = file_get_contents( dirname( __DIR__ ) . '/public/css/ai-chat-bedrock-public.css' );
check_pub( 1 === preg_match( '/@media \(prefers-color-scheme: dark\) \{\s*\.ai-chat-bedrock-container\[data-scheme="auto"\]/', $aicfab_css ), 'Only a chat set to follow the device turns dark with it.' );
// A theme's textarea height pushed the send button out of the popup, which hides overflow.
check_pub( 1 === preg_match( '/\.ai-chat-bedrock-container \.ai-chat-bedrock-textarea \{[^}]*\bheight: 58px/', $aicfab_css ), 'The chat sets its own textarea height over the theme\'s.' );
// Focusing the text box on a phone opened the keyboard over the greeting and suggestions.
check_pub( false !== strpos( $aicfab_script, "matchMedia('(pointer: coarse)')" ), 'Opening the popup on a touch screen does not focus the text box.' );
check_pub( false !== strpos( $aicfab_script, "attr('data-welcome')" ) && false === strpos( $aicfab_script, '.text(params.welcome_message)' ), 'Clearing the chat restores the greeting from the markup.' );
check_pub( 1 === preg_match( '/JSON\.stringify\(\{ entry: entryId, rating: value, profile: profile \}\)/', $aicfab_script ), 'A rating names the chat\'s profile, which decides whether guests may rate.' );

// Every attribute the block's render callback reads is one the block declares, or the editor
// cannot set it: mode and launcher were read but not declared, so a block was never a popup.
$aicfab_block = json_decode( file_get_contents( dirname( __DIR__ ) . '/blocks/chat/block.json' ), true );
$aicfab_source = file_get_contents( dirname( __DIR__ ) . '/public/class-ai-chat-bedrock-public.php' );
preg_match( '/function render_chat_block.*?foreach \( array\( ([^)]*) \) as \$key \)/s', $aicfab_source, $aicfab_read );
$aicfab_read = isset( $aicfab_read[1] ) ? array_map( function ( $key ) { return trim( $key, " '" ); }, explode( ',', $aicfab_read[1] ) ) : array();
check_pub( count( $aicfab_read ) >= 7, 'The attributes the render callback reads were found.' );
foreach ( $aicfab_read as $aicfab_key ) {
	check_pub( isset( $aicfab_block['attributes'][ $aicfab_key ] ), "The block declares the $aicfab_key attribute." );
}
$aicfab_popup_block = ( new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' ) )->render_chat_block( array( 'mode' => 'popup', 'launcher' => 'Ask us' ) );
check_pub( false !== strpos( $aicfab_popup_block, 'ai-chat-bedrock-popup' ) && false !== strpos( $aicfab_popup_block, 'Ask us' ), 'A block set to popup renders as a floating button with its label.' );

// The editor preview is styled like the page. The path points outside the block directory, so
// check that it resolves to the chat's stylesheet.
$aicfab_editor_style = isset( $aicfab_block['editorStyle'] ) ? (string) $aicfab_block['editorStyle'] : '';
$aicfab_editor_css   = 0 === strpos( $aicfab_editor_style, 'file:' ) ? realpath( dirname( __DIR__ ) . '/blocks/chat/' . substr( $aicfab_editor_style, 5 ) ) : false;
check_pub( realpath( dirname( __DIR__ ) . '/public/css/ai-chat-bedrock-public.css' ) === $aicfab_editor_css, 'The block editor loads the chat\'s stylesheet.' );

// --- Admin screens: what a redirect announces is shown, and deleting asks first ---------

$aicfab_admin_dir = dirname( __DIR__ ) . '/admin';
$aicfab_views     = '';
foreach ( array_merge( array( $aicfab_admin_dir . '/class-ai-chat-bedrock-admin.php' ), glob( $aicfab_admin_dir . '/partials/*.php' ) ) as $aicfab_view ) {
	$aicfab_views .= file_get_contents( $aicfab_view );
}
$aicfab_handlers = file_get_contents( $aicfab_admin_dir . '/class-ai-chat-bedrock-admin.php' );

// Each flag a redirect sets is read by a screen: "conversations deleted" was set and never shown.
preg_match_all( "/(?:add_query_arg\\(\\s*|\\\$args\\[\\s*)'(aicfab-[a-z-]+)'|'(aicfab-[a-z-]+)'\\s*=>/", $aicfab_handlers, $aicfab_found );
$aicfab_flags = array_unique( array_filter( array_merge( $aicfab_found[1], $aicfab_found[2] ) ) );
check_pub( count( $aicfab_flags ) >= 10, 'The notice flags set by redirects were found.' );
$aicfab_removable = $aicfab_admin->removable_query_args( array( 'updated' ) );
check_pub( in_array( 'updated', $aicfab_removable, true ), 'WordPress\'s own removable arguments are kept.' );
foreach ( $aicfab_flags as $aicfab_flag ) {
	check_pub( 1 === preg_match( "/_GET\\[\\s*'" . preg_quote( $aicfab_flag, '/' ) . "'\\s*\\]/", $aicfab_views ), "A screen reads the $aicfab_flag flag its redirect sets." );
	check_pub( in_array( $aicfab_flag, $aicfab_removable, true ), "The $aicfab_flag flag leaves the address bar once shown." );
}

// Every action that deletes or disconnects for good asks before it runs.
foreach ( array( 'ai_chat_bedrock_clear_conversations', 'ai_chat_bedrock_delete_profile', 'ai_chat_bedrock_clear_embeddings' ) as $aicfab_action ) {
	check_pub( 1 === preg_match( '/<form (?:(?!<\/form>).)*?data-aicfab-confirm="<\?php esc_attr_e\( \'[^\']+\'(?:(?!<\/form>).)*value="' . $aicfab_action . '"/s', $aicfab_views ), "The $aicfab_action form asks for confirmation." );
}
check_pub( 1 === preg_match( '/name="revoke_all"[^>]*data-aicfab-confirm="[^"]+"/', $aicfab_views ), 'Revoking every connection asks for confirmation.' );
$aicfab_admin_js = file_get_contents( $aicfab_admin_dir . '/js/ai-chat-bedrock-admin.js' );
check_pub( false !== strpos( $aicfab_admin_js, "'form[data-aicfab-confirm]'" ) && false !== strpos( $aicfab_admin_js, "'button[data-aicfab-confirm]'" ), 'The admin script asks the confirmation questions.' );

// The index could not be deleted from any screen, though its handler existed.
check_pub( false !== strpos( $aicfab_views, 'form="aicfab-clear-embeddings"' ) && false !== strpos( $aicfab_views, 'id="aicfab-clear-embeddings"' ), 'A button submits the delete-index form.' );

// Refreshing the model list refills the menus, rather than asking for a reload.
$GLOBALS['aicfab_logged_in'] = true;
$GLOBALS['aicfab_nonce_ok']  = true;
try {
	$aicfab_admin->ajax_refresh_models();
	$aicfab_refreshed = null;
} catch ( Aicfab_Sent $sent ) {
	$aicfab_refreshed = $sent;
}
check_pub( $aicfab_refreshed && $aicfab_refreshed->ok && 2 === count( $aicfab_refreshed->payload['models'] ), 'Refreshing returns the model list.' );
check_pub( $aicfab_refreshed && array( 'value' => 'jp.anthropic.claude-haiku-4-5', 'label' => 'Claude Haiku 4.5' ) === $aicfab_refreshed->payload['models'][0], 'The list is in order, as value and label.' );
check_pub( $aicfab_refreshed && false === stripos( $aicfab_refreshed->payload['message'], 'reload' ), 'Refreshing no longer asks for a reload.' );
check_pub( false !== strpos( $aicfab_admin_js, 'refillModelMenus(response.data.models)' ), 'The admin script refills the model menus.' );

// --- The chat's own text in the visitor's language -------------------------------

/*
 * The title, greeting and suggested questions are typed once, so a multilingual site showed
 * them in that language on every edition. They are registered with Polylang and shown as it
 * translates them. Declared here, after every other check has run without Polylang.
 */
$aicfab_plain = array( 'chat_title' => 'Ask us', 'welcome_message' => 'Hello there.' );
check_pub( $aicfab_plain === AI_Chat_Bedrock_Translation::presentation( $aicfab_plain ), 'Without a multilingual plugin the text is unchanged.' );

if ( ! function_exists( 'pll__' ) ) {
	function pll__( $text ) {
		$ja = array(
			'Ask us'             => '質問する',
			'Hello there.'       => 'こんにちは。',
			'What is new?'       => '新着は？',
			'Support desk here.' => 'サポートです。',
		);
		return isset( $ja[ $text ] ) ? $ja[ $text ] : $text;
	}
	function pll_register_string( $name, $text, $context = 'Polylang', $multiline = false ) {
		$GLOBALS['aicfab_registered'][ $name ] = array( $text, $context, $multiline );
	}
	// Polylang answers WPML's API as well, and lists what is registered through it again.
	function has_action( $hook ) {
		return 'wpml_register_single_string' === $hook;
	}
	function do_action( $hook, ...$args ) {
		$GLOBALS['aicfab_actions'][] = array( $hook, $args );
	}
	function icl_unregister_string( $context, $name ) {
		$GLOBALS['aicfab_unregistered'][] = array( $context, $name );
	}
}

aicfab_reset_pub(
	array(
		'allow_public_chat'   => true,
		'chat_title'          => 'Ask us',
		'suggested_questions' => "What is new?\nHow do I start?",
	)
);
$aicfab_stored = $GLOBALS['aicfab_opts']['ai_chat_bedrock_settings'];
$aicfab_ja     = ( new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' ) )->display_chat_interface( array() );
check_pub( false !== strpos( $aicfab_ja, '質問する' ) && false === strpos( $aicfab_ja, 'Ask us' ), 'The title is shown translated.' );
check_pub( false !== strpos( $aicfab_ja, 'data-welcome="こんにちは。"' ) && false === strpos( $aicfab_ja, 'Hello there.' ), 'The greeting is shown translated, also for when the chat is cleared.' );
check_pub( false !== strpos( $aicfab_ja, '>新着は？</button>' ) && false !== strpos( $aicfab_ja, '>How do I start?</button>' ), 'Each suggested question is translated, and one without a translation is shown as written.' );
check_pub( $aicfab_stored === $GLOBALS['aicfab_opts']['ai_chat_bedrock_settings'], 'The saved settings keep the original text.' );

$GLOBALS['aicfab_localized'] = array();
( new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' ) )->enqueue_scripts();
check_pub( 'こんにちは。' === $GLOBALS['aicfab_localized']['ai_chat_bedrock_params']['welcome_message'], 'The script is given the translated greeting.' );

$GLOBALS['aicfab_opts'][ AI_Chat_Bedrock_Profiles::OPTION ] = array( 'support' => array( 'welcome_message' => 'Support desk here.' ) );
$aicfab_ja_profile = ( new AI_Chat_Bedrock_Public( 'ai-chat-for-amazon-bedrock', 'test' ) )->display_chat_interface( array( 'profile' => 'support' ) );
check_pub( false !== strpos( $aicfab_ja_profile, 'data-welcome="サポートです。"' ), 'A profile\'s greeting is translated too.' );

$GLOBALS['aicfab_registered'] = array();
AI_Chat_Bedrock_Translation::register();
$aicfab_registered = $GLOBALS['aicfab_registered'];
check_pub( isset( $aicfab_registered['chat_title'] ) && array( 'Ask us', 'AI Chat for Amazon Bedrock', false ) === $aicfab_registered['chat_title'], 'The title is registered for translation under the plugin\'s name.' );
check_pub( isset( $aicfab_registered['welcome_message'] ) && true === $aicfab_registered['welcome_message'][2], 'The greeting is registered as multiline text.' );
check_pub( 2 === count( preg_grep( '/^suggested_question [0-9a-f]{8}$/', array_keys( $aicfab_registered ) ) ), 'Each suggested question is registered on its own.' );
check_pub( isset( $aicfab_registered['support: welcome_message'] ) && 'Support desk here.' === $aicfab_registered['support: welcome_message'][0], 'A profile\'s text is registered under the profile\'s name.' );
check_pub( ! in_array( 'wpml_register_single_string', array_column( isset( $GLOBALS['aicfab_actions'] ) ? $GLOBALS['aicfab_actions'] : array(), 0 ), true ), 'With Polylang, strings are not registered again through its WPML layer.' );
check_pub( in_array( array( 'AI Chat for Amazon Bedrock', 'chat_title' ), isset( $GLOBALS['aicfab_unregistered'] ) ? $GLOBALS['aicfab_unregistered'] : array(), true ), 'The copy an earlier version registered through that layer is removed.' );

// --- An answer from the customer's orders lists no articles ------------------------
// WooCommerce is declared only here, at the end, so the checks above run without it.

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
	function wc_get_product( $id ) {
		return false;
	}
	function wc_get_orders( $args ) {
		return array();
	}
	function wc_get_order_statuses() {
		return array( 'wc-processing' => 'Processing' );
	}
}
aicfab_reset_pub( array( 'show_sources' => true, 'woo_orders' => true ) );
$GLOBALS['aicfab_logged_in'] = true;
$GLOBALS['aicfab_sources']   = array( array( 'title' => 'Radar', 'url' => 'https://example.test/radar/' ) );
$aicfab_built                = AI_Chat_Bedrock_Chat_Request::build( 'Where is my order?', '[]', $GLOBALS['aicfab_opts']['ai_chat_bedrock_settings'] );
$aicfab_sent                 = is_wp_error( $aicfab_built ) ? '' : wp_json_encode( $aicfab_built['messages'] );
check_pub( '' !== $aicfab_sent && false !== strpos( $aicfab_sent, 'has no orders' ), 'An order question reaches the store.' );
check_pub( '' !== $aicfab_sent && array() === $aicfab_built['sources'] && true === $aicfab_built['grounded'], 'An answer from the customer\'s orders lists no articles as its sources and is no content gap.' );
check_pub( false !== strpos( $aicfab_sent, 'Reference material' ), 'The passages stay, for a shipping or returns page.' );
$aicfab_built = AI_Chat_Bedrock_Chat_Request::build( 'What is a VLA model?', '[]', $GLOBALS['aicfab_opts']['ai_chat_bedrock_settings'] );
check_pub( ! is_wp_error( $aicfab_built ) && $GLOBALS['aicfab_sources'] === $aicfab_built['sources'], 'Another question keeps its sources.' );
$GLOBALS['aicfab_sources'] = array();

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: front end checks passed\n";
