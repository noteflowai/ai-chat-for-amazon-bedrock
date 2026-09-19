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
	return $value;
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
	return 'https://example.com/wp-content/plugins/ai-chat-for-amazon-bedrock/';
}
function plugin_dir_path( $file ) {
	return dirname( __DIR__ ) . '/public/';
}
function wp_register_script( ...$args ) {
	return true;
}
function wp_register_style( ...$args ) {
	return true;
}
function wp_enqueue_script( ...$args ) {
	return true;
}
function wp_enqueue_style( ...$args ) {
	return true;
}
function wp_localize_script( $handle, $name, $data ) {
	$GLOBALS['aicfab_localized'][ $name ] = $data;
	return true;
}
function is_admin() {
	return false;
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
class AI_Chat_Bedrock_Feedback {
	const REST_ROUTE = '/feedback';
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-profiles.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-rate-limits.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-chat-request.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wp-mcp-server.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-stream.php';
require dirname( __DIR__ ) . '/public/class-ai-chat-bedrock-public.php';

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

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: front end checks passed\n";
