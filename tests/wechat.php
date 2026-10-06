<?php
/**
 * Standalone tests for answering a WeChat Official Account.
 *
 * Run: php tests/wechat.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

const AICFAB_TOKEN   = 'testtoken123';
const AICFAB_AES_KEY = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';
const AICFAB_APP_ID  = 'wx1234567890abcdef';

$GLOBALS['aicfab_options']    = array();
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_filters']    = array();
$GLOBALS['aicfab_runs']       = array();
$GLOBALS['aicfab_records']    = array();
$GLOBALS['aicfab_during']     = null;
$GLOBALS['aicfab_reply']      = 'We **deliver** on Sundays from 9 to 12.';
$GLOBALS['aicfab_build_fail'] = null;

class WP_Error {
	private $code;
	private $data;
	public function __construct( $code, $message = '', $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}
	public function get_error_data() {
		return $this->data;
	}
	public function get_error_code() {
		return $this->code;
	}
}
class WP_REST_Request {
	private $method;
	private $params;
	private $body;
	public function __construct( $method, $params, $body = '' ) {
		$this->method = $method;
		$this->params = $params;
		$this->body   = $body;
	}
	public function get_method() {
		return $this->method;
	}
	public function get_param( $name ) {
		return isset( $this->params[ $name ] ) ? $this->params[ $name ] : null;
	}
	public function get_body() {
		return $this->body;
	}
	public function get_route() {
		return '/ai-chat-bedrock/v1/wechat';
	}
}
class WP_REST_Response {
	private $data;
	private $status;
	private $headers = array();
	public function __construct( $data, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}
	public function get_headers() {
		return $this->headers;
	}
	public function get_data() {
		return $this->data;
	}
	public function get_status() {
		return $this->status;
	}
}
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['aicfab_store'] = array();
function get_option( $name, $fallback = false ) {
	if ( 'ai_chat_bedrock_settings' === $name ) {
		return $GLOBALS['aicfab_options'];
	}
	return array_key_exists( $name, $GLOBALS['aicfab_store'] ) ? $GLOBALS['aicfab_store'][ $name ] : $fallback;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_store'][ $name ] = $value;
	$GLOBALS['aicfab_writes']         = isset( $GLOBALS['aicfab_writes'] ) ? $GLOBALS['aicfab_writes'] + 1 : 1;
	return true;
}
function human_time_diff( $from, $to ) {
	return max( 1, (int) round( ( $to - $from ) / 60 ) ) . ' mins';
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
function add_filter( $hook, $callback ) {
	$GLOBALS['aicfab_filters'][ $hook ] = $callback;
}
function apply_filters( $hook, $value, ...$args ) {
	return isset( $GLOBALS['aicfab_filters'][ $hook ] ) ? call_user_func( $GLOBALS['aicfab_filters'][ $hook ], $value, ...$args ) : $value;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function __( $text, $domain = null ) {
	return $text;
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_strip_all_tags( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function esc_url_raw( $url ) {
	return (string) $url;
}
function rest_url( $path ) {
	return 'https://example.test/wp-json/' . $path;
}
function wp_salt() {
	return 'test-salt';
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
$GLOBALS['aicfab_route'] = array();
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['aicfab_route'] = array( $namespace, $route, $args );
}

class AI_Chat_Bedrock_WP_MCP_Server {
	const NAMESPACE_V1 = 'ai-chat-bedrock/v1';
}
class AI_Chat_Bedrock_Profiles {
	public static function overrides_for_client( $options ) {
		return isset( $options['model_id'] ) ? array( 'model_id' => $options['model_id'] ) : array();
	}
}
class AI_Chat_Bedrock_Translation {
	public static function presentation( $options ) {
		return $options;
	}
}
class AI_Chat_Bedrock_Chat_Request {
	public static function build( $message, $history, $options ) {
		if ( null !== $GLOBALS['aicfab_build_fail'] ) {
			return new WP_Error( $GLOBALS['aicfab_build_fail'] );
		}
		$GLOBALS['aicfab_built'] = compact( 'message', 'history', 'options' );
		return array(
			'messages' => array( array( 'role' => 'user', 'content' => $message ) ),
			'message'  => $message,
			'grounded' => true,
			'sources'  => array( array( 'title' => 'Delivery', 'url' => 'https://example.test/delivery/' ) ),
		);
	}
	public static function suggestions( $options ) {
		return isset( $options['suggested_questions'] ) ? $options['suggested_questions'] : array();
	}
}
class AI_Chat_Bedrock_Content {
	public static function is_public( $post ) {
		return 'members only' !== $post->post_title;
	}
	public static function request_language( $slug ) {
		return in_array( $slug, array( 'zh', 'en', 'ja' ), true ) ? $slug : '';
	}
}
class AI_Chat_Bedrock_AWS {
	public function __construct( $options = array() ) {
		$GLOBALS['aicfab_client'] = $options;
	}
}
class AI_Chat_Bedrock_Models {
	public static function is_valid_id( $id ) {
		return 1 === preg_match( '/^[a-z0-9.:\-]+$/', (string) $id );
	}
}
class AI_Chat_Bedrock_Tool_Runner {
	public static function run( $aws, $messages, $message ) {
		$GLOBALS['aicfab_runs'][] = $message;
		// WeChat asking again while the answer is still being written.
		if ( is_callable( $GLOBALS['aicfab_during'] ) ) {
			call_user_func( $GLOBALS['aicfab_during'] );
		}
		return false === $GLOBALS['aicfab_reply'] ? array( 'success' => false ) : array(
			'success' => true,
			'data'    => array( 'message' => $GLOBALS['aicfab_reply'] ),
			'usage'   => array( 'input_tokens' => 10 ),
		);
	}
}
class AI_Chat_Bedrock_Conversations {
	public static function record( $question, $answer, $context ) {
		$GLOBALS['aicfab_records'][] = compact( 'question', 'answer', 'context' );
	}
}

$GLOBALS['aicfab_feature_posts'] = array();
function get_posts( $args ) {
	$GLOBALS['aicfab_post_query'] = $args;
	return $GLOBALS['aicfab_feature_posts'];
}
function get_the_title( $post ) {
	return $post->post_title;
}
function get_permalink( $post ) {
	return 'https://example.test/?p=' . $post->ID;
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat-api.php';

$failures = array();
function check_wx( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}
function wx_settings( $extra = array() ) {
	$GLOBALS['aicfab_options'] = array_merge(
		array(
			'wechat_enabled' => true,
			'wechat_token'   => AI_Chat_Bedrock_Security::encrypt_secret( AICFAB_TOKEN ),
			'welcome_message' => 'Welcome to the bakery.',
			'suggested_questions' => array( 'When are you open?', 'Do you deliver?' ),
		),
		$extra
	);
}
function wx_message( $text, $id, $type = 'text', $extra = '' ) {
	return '<xml><ToUserName><![CDATA[gh_bakery]]></ToUserName><FromUserName><![CDATA[oFollower1]]></FromUserName><CreateTime>' . time() . '</CreateTime><MsgType><![CDATA[' . $type . ']]></MsgType><Content><![CDATA[' . $text . ']]></Content><MsgId>' . $id . '</MsgId>' . $extra . '</xml>';
}
/** As the REST server does it: the permission check first, then the handler. */
function wx_serve( $request ) {
	// Each request starts now, as a request from WeChat would.
	$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
	$handler = new AI_Chat_Bedrock_WeChat();
	$allowed = $handler->check_permission( $request );
	if ( true !== $allowed ) {
		return new WP_REST_Response( 'refused', $allowed->get_error_data()['status'] );
	}
	return $handler->handle( $request );
}
function wx_post( $body, $params = array() ) {
	$timestamp = isset( $params['timestamp'] ) ? $params['timestamp'] : (string) time();
	$nonce     = 'n' . uniqid( '', true );
	$parts     = array( AICFAB_TOKEN, $timestamp, $nonce );
	sort( $parts, SORT_STRING );
	$params += array(
		'timestamp' => $timestamp,
		'nonce'     => $nonce,
		'signature' => sha1( implode( '', $parts ) ),
	);
	return wx_serve( new WP_REST_Request( 'POST', $params, $body ) );
}
function wx_content( $response ) {
	$fields = AI_Chat_Bedrock_WeChat::parse( (string) $response->get_data() );
	return is_array( $fields ) && isset( $fields['Content'] ) ? $fields['Content'] : null;
}
function wx_reset() {
	$GLOBALS['aicfab_transients'] = array();
	$GLOBALS['aicfab_runs']       = array();
	$GLOBALS['aicfab_records']    = array();
	$GLOBALS['aicfab_during']     = null;
	$GLOBALS['aicfab_reply']      = 'We **deliver** on Sundays from 9 to 12.';
	$GLOBALS['aicfab_build_fail'] = null;
}
// Repeated requests wait no time in these tests.
add_filter( 'ai_chat_bedrock_wechat_wait', function () { return 0.0; } );

// --- Settings -------------------------------------------------------------------------------

check_wx( ! AI_Chat_Bedrock_WeChat::enabled( array() ), 'Off unless turned on.' );
check_wx( ! AI_Chat_Bedrock_WeChat::enabled( array( 'wechat_enabled' => true ) ), 'Not answering without a token.' );
wx_settings();
check_wx( AI_Chat_Bedrock_WeChat::enabled() && AICFAB_TOKEN === AI_Chat_Bedrock_WeChat::token(), 'On with a token, which is stored encrypted.' );
check_wx( false === strpos( $GLOBALS['aicfab_options']['wechat_token'], AICFAB_TOKEN ), 'The stored token is not the token itself.' );
check_wx( '' === AI_Chat_Bedrock_WeChat::clean_token( 'ab' ) && '' === AI_Chat_Bedrock_WeChat::clean_token( 'has space' ) && '' === AI_Chat_Bedrock_WeChat::clean_token( str_repeat( 'a', 33 ) ) && 'Abc123' === AI_Chat_Bedrock_WeChat::clean_token( ' Abc123 ' ), 'A token is 3 to 32 letters and digits, as WeChat allows.' );
check_wx( AICFAB_AES_KEY === AI_Chat_Bedrock_WeChat::clean_aes_key( AICFAB_AES_KEY ) && '' === AI_Chat_Bedrock_WeChat::clean_aes_key( substr( AICFAB_AES_KEY, 1 ) ), 'An EncodingAESKey is 43 characters.' );
check_wx( AICFAB_APP_ID === AI_Chat_Bedrock_WeChat::clean_app_id( AICFAB_APP_ID ) && '' === AI_Chat_Bedrock_WeChat::clean_app_id( 'gh_63e00737b4da' ), 'An AppID starts with wx; the original ID is not one.' );
check_wx( 20 === AI_Chat_Bedrock_WeChat::hourly_limit( array() ) && 1 === AI_Chat_Bedrock_WeChat::hourly_limit( array( 'wechat_hourly' => '0' ) ) && 500 === AI_Chat_Bedrock_WeChat::hourly_limit( array( 'wechat_hourly' => '99999' ) ), 'The hourly limit defaults to 20 and stays between 1 and 500.' );
check_wx( 'https://example.test/wp-json/ai-chat-bedrock/v1/wechat' === AI_Chat_Bedrock_WeChat::url(), 'The server URL to enter in WeChat is the plugin\'s REST route.' );
( new AI_Chat_Bedrock_WeChat() )->register_routes();
check_wx( 'ai-chat-bedrock/v1' === $GLOBALS['aicfab_route'][0] && '/wechat' === $GLOBALS['aicfab_route'][1] && 'GET' === $GLOBALS['aicfab_route'][2][0]['methods'] && 'POST' === $GLOBALS['aicfab_route'][2][1]['methods'], 'The route takes GET for the address check and POST for messages.' );
check_wx( is_array( $GLOBALS['aicfab_route'][2][1]['permission_callback'] ) && 'check_permission' === $GLOBALS['aicfab_route'][2][1]['permission_callback'][1], 'The signature is checked as the route\'s permission, so nothing unsigned reaches the handler.' );

// --- The address check ----------------------------------------------------------------------

$timestamp = (string) time();
$parts     = array( AICFAB_TOKEN, $timestamp, 'nonce1' );
sort( $parts, SORT_STRING );
$check = wx_serve( new WP_REST_Request( 'GET', array( 'signature' => sha1( implode( '', $parts ) ), 'timestamp' => $timestamp, 'nonce' => 'nonce1', 'echostr' => '5838479218127813673' ) ) );
check_wx( 200 === $check->get_status() && '5838479218127813673' === $check->get_data(), 'A signed address check is answered with echostr.' );
$bad = wx_serve( new WP_REST_Request( 'GET', array( 'signature' => str_repeat( 'a', 40 ), 'timestamp' => $timestamp, 'nonce' => 'nonce1', 'echostr' => '1' ) ) );
check_wx( 403 === $bad->get_status() && '1' !== $bad->get_data(), 'A wrong signature is refused.' );
$stale = (string) ( time() - 3600 );
$parts = array( AICFAB_TOKEN, $stale, 'nonce1' );
sort( $parts, SORT_STRING );
$old = wx_serve( new WP_REST_Request( 'GET', array( 'signature' => sha1( implode( '', $parts ) ), 'timestamp' => $stale, 'nonce' => 'nonce1', 'echostr' => '1' ) ) );
check_wx( 403 === $old->get_status(), 'A request signed an hour ago is refused, so a captured one cannot be replayed.' );
$GLOBALS['aicfab_options']['wechat_enabled'] = false;
check_wx( 404 === wx_serve( new WP_REST_Request( 'GET', array() ) )->get_status(), 'Switched off, the address does not answer.' );
wx_settings();

// --- The last contact, for setting up ---------------------------------------------------------

$GLOBALS['aicfab_store'] = array();
check_wx( '' === AI_Chat_Bedrock_WeChat::contact_summary(), 'Before WeChat calls, there is nothing to report.' );
wx_serve( new WP_REST_Request( 'GET', array( 'signature' => str_repeat( 'a', 40 ), 'timestamp' => (string) time(), 'nonce' => 'n1', 'echostr' => '1' ) ) );
check_wx( 'signature' === $GLOBALS['aicfab_store']['aicfab_wechat_contact']['result'] && false !== strpos( AI_Chat_Bedrock_WeChat::contact_summary(), 'did not match the token' ), 'A wrong signature is reported, with what to fix.' );
$timestamp = (string) time();
$parts     = array( AICFAB_TOKEN, $timestamp, 'n2' );
sort( $parts, SORT_STRING );
wx_serve( new WP_REST_Request( 'GET', array( 'signature' => sha1( implode( '', $parts ) ), 'timestamp' => $timestamp, 'nonce' => 'n2', 'echostr' => '1' ) ) );
check_wx( 'checked' === $GLOBALS['aicfab_store']['aicfab_wechat_contact']['result'] && 0 === strpos( AI_Chat_Bedrock_WeChat::contact_summary(), 'Last contact 1 mins ago: WeChat checked the address' ), 'A passed address check is reported.' );
wx_serve( new WP_REST_Request( 'GET', array( 'timestamp' => '1', 'nonce' => 'n3' ) ) );
check_wx( 'stale' === $GLOBALS['aicfab_store']['aicfab_wechat_contact']['result'], 'An old timestamp is reported.' );
$GLOBALS['aicfab_writes'] = 0;
for ( $i = 0; $i < 5; $i++ ) {
	wx_serve( new WP_REST_Request( 'GET', array( 'timestamp' => '1', 'nonce' => 'n3' ) ) );
}
check_wx( 0 === $GLOBALS['aicfab_writes'], 'The same outcome is written at most once a minute, so the address cannot be used to flood the database.' );
check_wx( false === strpos( json_encode( $GLOBALS['aicfab_store'] ), 'oFollower' ), 'Nothing about a follower is kept.' );
$GLOBALS['aicfab_options']['wechat_enabled'] = false;
wx_serve( new WP_REST_Request( 'GET', array() ) );
check_wx( 'off' === $GLOBALS['aicfab_store']['aicfab_wechat_contact']['result'], 'A call while answering is off is reported too.' );
wx_settings();

// --- A question, answered in time -------------------------------------------------------------

wx_reset();
$response = wx_post( wx_message( 'Do you deliver on Sundays?', 1001 ) );
$headers  = $response->get_headers();
$fields   = AI_Chat_Bedrock_WeChat::parse( (string) $response->get_data() );
check_wx( 200 === $response->get_status() && 0 === strpos( $headers['Content-Type'], 'application/xml' ), 'The reply is XML.' );
check_wx( 'oFollower1' === $fields['ToUserName'] && 'gh_bakery' === $fields['FromUserName'] && 'text' === $fields['MsgType'], 'The reply goes back to the follower, from the account.' );
check_wx( "We deliver on Sundays from 9 to 12.\n\nSources:\nDelivery\nhttps://example.test/delivery/\n\nGenerated by AI. Check the linked pages for anything important." === $fields['Content'], 'The answer is plain text, with its source, labelled as written by AI: ' . json_encode( $fields['Content'] ) );
check_wx( array( 'Do you deliver on Sundays?' ) === $GLOBALS['aicfab_runs'], 'The question is answered once.' );
check_wx( false !== strpos( $GLOBALS['aicfab_built']['options']['system_prompt'], 'WeChat Official Account' ) && false === $GLOBALS['aicfab_built']['options']['leads_enabled'], 'The model is told it writes plain text for WeChat, and not to point to a contact button.' );
check_wx( 'wechat' === $GLOBALS['aicfab_records'][0]['context']['source'] && false === strpos( wp_json_encode_safe( $GLOBALS['aicfab_records'] ), 'oFollower1' ), 'The conversation log records it as from WeChat, without the follower\'s ID.' );

function wp_json_encode_safe( $value ) {
	return (string) json_encode( $value );
}

$again = wx_post( wx_message( 'Do you deliver on Sundays?', 1001 ) );
check_wx( wx_content( $again ) === $fields['Content'] && 1 === count( $GLOBALS['aicfab_runs'] ), 'WeChat sending the same message again gets the same answer, not a second one.' );

wx_post( wx_message( 'And on Mondays?', 1002 ) );
check_wx( array( array( 'role' => 'user', 'content' => 'Do you deliver on Sundays?' ), array( 'role' => 'assistant', 'content' => 'We **deliver** on Sundays from 9 to 12.' ) ) === $GLOBALS['aicfab_built']['history'], 'A follow-up question carries the conversation so far.' );

// --- A signed address used again ----------------------------------------------------------

wx_reset();
wx_settings();
$aicfab_ts    = (string) time();
$aicfab_parts = array( AICFAB_TOKEN, $aicfab_ts, 'nreplay' );
sort( $aicfab_parts, SORT_STRING );
$aicfab_query = array( 'timestamp' => $aicfab_ts, 'nonce' => 'nreplay', 'signature' => sha1( implode( '', $aicfab_parts ) ) );
$first        = wx_serve( new WP_REST_Request( 'POST', $aicfab_query, wx_message( 'Do you deliver?', 8101 ) ) );
$again        = wx_serve( new WP_REST_Request( 'POST', $aicfab_query, wx_message( 'Do you deliver?', 8101 ) ) );
$swapped      = wx_serve( new WP_REST_Request( 'POST', $aicfab_query, str_replace( 'oFollower1', 'oSomeoneElse', wx_message( 'Spend money', 8102 ) ) ) );
check_wx( 200 === $first->get_status() && 200 === $again->get_status() && 403 === $swapped->get_status() && 1 === count( $GLOBALS['aicfab_runs'] ), 'WeChat sending the same request again is answered; another message under the same signature is refused.' );
wx_reset();

// --- The menu and featured posts ------------------------------------------------------------

wx_reset();
wx_settings( array( 'wechat_menu' => "Courses: https://example.test/courses/\nSend 精选 for new articles." ) );
$menu = wx_content( wx_post( wx_message( '菜单', 7001 ) ) );
check_wx( "Courses: https://example.test/courses/\nSend 精选 for new articles." === $menu && array() === $GLOBALS['aicfab_runs'], 'A follower who sends 菜单 gets the menu, without the model.' );
check_wx( $menu === wx_content( wx_post( wx_message( ' MENU ', 7002 ) ) ), 'The menu word is matched without case or spaces.' );
$GLOBALS['aicfab_feature_posts'] = array( (object) array( 'ID' => 11, 'post_title' => '具身智能入门' ), (object) array( 'ID' => 13, 'post_title' => 'members only' ), (object) array( 'ID' => 12, 'post_title' => 'World models &amp; robots' ) );
$featured                        = wx_content( wx_post( wx_message( '精选', 7003 ) ) );
check_wx( false !== strpos( $featured, "具身智能入门\nhttps://example.test/?p=11" ) && false !== strpos( $featured, 'World models & robots' ) && 0 === strpos( $featured, 'Featured articles:' ) && false === strpos( $featured, 'members only' ) && array() === $GLOBALS['aicfab_runs'], 'Sending 精选 lists the newest public posts with their addresses, without the model.' );
check_wx( 'publish' === $GLOBALS['aicfab_post_query']['post_status'] && false === $GLOBALS['aicfab_post_query']['has_password'], 'Only published posts without a password are listed.' );
$GLOBALS['aicfab_feature_posts'] = array();
check_wx( 'There are no featured articles yet.' === wx_content( wx_post( wx_message( '最新', 7004 ) ) ), 'With none, the follower is told so.' );
wx_settings( array( 'wechat_hourly' => 1, 'wechat_menu' => 'Menu' ) );
wx_post( wx_message( '菜单', 7005 ) );
wx_post( wx_message( '菜单', 7006 ) );
wx_post( wx_message( 'Do you deliver?', 7007 ) );
check_wx( 1 === count( $GLOBALS['aicfab_runs'] ), 'Menu words do not count towards the hourly limit.' );
$hello = wx_content( wx_post( '<xml><ToUserName><![CDATA[gh_bakery]]></ToUserName><FromUserName><![CDATA[oNew]]></FromUserName><CreateTime>' . time() . '</CreateTime><MsgType><![CDATA[event]]></MsgType><Event><![CDATA[subscribe]]></Event></xml>' ) );
check_wx( "\n\nMenu" === substr( $hello, -6 ), 'A new follower gets the menu after the welcome.' );
wx_reset();
wx_settings();
wx_post( wx_message( '菜单', 7008 ) );
check_wx( 1 === count( $GLOBALS['aicfab_runs'] ), 'Without a menu, 菜单 is a question like any other.' );
wx_reset();

// --- A faster model for WeChat -----------------------------------------------------------------

wx_reset();
wx_settings( array( 'model_id' => 'global.slow-main-v1', 'fallback_model_id' => 'jp.fast-haiku-v1' ) );
wx_post( wx_message( 'Hi', 1501 ) );
check_wx( array( 'model_id' => 'global.slow-main-v1' ) === $GLOBALS['aicfab_client'], 'By default WeChat is answered by the chat\'s model.' );
wx_settings( array( 'model_id' => 'global.slow-main-v1', 'fallback_model_id' => 'jp.fast-haiku-v1', 'wechat_model_id' => 'jp.fast-haiku-v1' ) );
wx_post( wx_message( 'Hi again', 1502 ) );
check_wx( array( 'model_id' => 'jp.fast-haiku-v1', 'fallback_model_id' => 'global.slow-main-v1' ) === $GLOBALS['aicfab_client'], 'A model chosen for WeChat answers there; being the fallback too, the main model stands in for it.' );
wx_settings( array( 'model_id' => 'global.slow-main-v1', 'wechat_model_id' => 'not a model!' ) );
check_wx( '' === AI_Chat_Bedrock_WeChat::model(), 'An invalid model is ignored.' );
wx_settings();

// --- A question that takes longer than WeChat waits -------------------------------------------

wx_reset();
// WeChat asks a second time five seconds in, and a third time ten seconds in.
$GLOBALS['aicfab_during'] = function () {
	$second                   = microtime( true );
	$GLOBALS['aicfab_second'] = wx_post( wx_message( 'What is in the sourdough?', 2001 ) );
	$GLOBALS['aicfab_held']   = microtime( true ) - $second;
	$GLOBALS['aicfab_third']  = wx_post( wx_message( 'What is in the sourdough?', 2001 ) );
};
$first = wx_post( wx_message( 'What is in the sourdough?', 2001 ) );
check_wx( $GLOBALS['aicfab_held'] >= 1.4, 'A request before the last is held past WeChat\'s timeout, so WeChat asks again instead of taking an empty reply.' );
check_wx( '' === (string) $GLOBALS['aicfab_second']->get_data() || 'success' === (string) $GLOBALS['aicfab_second']->get_data(), 'The second request has nothing to say yet.' );
check_wx( false !== strpos( (string) wx_content( $GLOBALS['aicfab_third'] ), 'Send 1' ), 'The last request tells the follower to send 1 for the answer.' );
check_wx( 1 === count( $GLOBALS['aicfab_runs'] ), 'Only the first request writes the answer.' );
$GLOBALS['aicfab_during'] = null;
$shown = wx_post( wx_message( '1', 2002 ) );
check_wx( 0 === strpos( (string) wx_content( $shown ), 'We deliver on Sundays' ) && false !== strpos( (string) wx_content( $shown ), 'Generated by AI' ) && 1 === count( $GLOBALS['aicfab_runs'] ), 'Sending 1 shows the answer that took longer, labelled, without asking the model again.' );
$after = wx_post( wx_message( '1', 2003 ) );
check_wx( 2 === count( $GLOBALS['aicfab_runs'] ), 'Once shown, 1 is an ordinary message again.' );

// A 1 sent while the answer is still being written.
wx_reset();
$GLOBALS['aicfab_transients'][ 'aicfab_wx_wait_' . md5( 'oFollower1' ) ] = 'aicfab_wx_unfinished';
check_wx( false !== strpos( (string) wx_content( wx_post( wx_message( '1', 2101 ) ) ), 'still being written' ) && array() === $GLOBALS['aicfab_runs'], 'Sending 1 too early says the answer is still being written.' );

// --- Other messages ---------------------------------------------------------------------------

wx_reset();
$welcome = wx_content( wx_post( '<xml><ToUserName><![CDATA[gh_bakery]]></ToUserName><FromUserName><![CDATA[oFollower1]]></FromUserName><CreateTime>' . time() . '</CreateTime><MsgType><![CDATA[event]]></MsgType><Event><![CDATA[subscribe]]></Event></xml>' ) );
check_wx( "Welcome to the bakery.\n\nYou could ask:\n· When are you open?\n· Do you deliver?" === $welcome && array() === $GLOBALS['aicfab_runs'], 'A new follower gets the welcome message and suggested questions, without asking the model, so without the AI label.' );
$unsubscribe = wx_post( '<xml><ToUserName><![CDATA[gh_bakery]]></ToUserName><FromUserName><![CDATA[oFollower1]]></FromUserName><CreateTime>' . time() . '</CreateTime><MsgType><![CDATA[event]]></MsgType><Event><![CDATA[unsubscribe]]></Event></xml>' );
check_wx( 'success' === $unsubscribe->get_data(), 'Other events get no reply.' );
$image = wx_content( wx_post( wx_message( '', 3001, 'image', '<PicUrl><![CDATA[http://mmbiz.example/pic.jpg]]></PicUrl>' ) ) );
check_wx( false !== strpos( (string) $image, 'only read text' ) && array() === $GLOBALS['aicfab_runs'], 'An image is answered with what the account can read.' );

// --- Limits and failures -------------------------------------------------------------------------

wx_reset();
wx_settings( array( 'wechat_hourly' => 2 ) );
wx_post( wx_message( 'One', 4001 ) );
wx_post( wx_message( 'Two', 4002 ) );
$limited = wx_content( wx_post( wx_message( 'Three', 4003 ) ) );
check_wx( false !== strpos( (string) $limited, 'this hour' ) && 2 === count( $GLOBALS['aicfab_runs'] ), 'A follower over the hourly limit is told so, and the model is not asked.' );
wx_settings();

wx_reset();
$GLOBALS['aicfab_build_fail'] = 'aicfab_daily_limit';
check_wx( false !== strpos( (string) wx_content( wx_post( wx_message( 'Hello', 4101 ) ) ), 'as many questions as it can today' ), 'The site\'s daily limit is explained to the follower.' );
$GLOBALS['aicfab_build_fail'] = null;
$GLOBALS['aicfab_reply']      = false;
check_wx( false !== strpos( (string) wx_content( wx_post( wx_message( 'Hello again', 4102 ) ) ), 'could not be written' ), 'A failed answer gets an apology, not silence.' );

// --- Requests that are not WeChat's --------------------------------------------------------------

wx_reset();
$evil = wx_post( '<?xml version="1.0"?><!DOCTYPE xml [<!ENTITY x SYSTEM "file:///etc/passwd">]><xml><ToUserName>a</ToUserName><FromUserName>b</FromUserName><MsgType>text</MsgType><Content>&x;</Content></xml>' );
check_wx( 400 === $evil->get_status() && array() === $GLOBALS['aicfab_runs'], 'XML with a document type is refused, so no entity is read.' );
$inline = wx_post( '<!DOCTYPE xml [<!ENTITY x "Injected question">]><xml><ToUserName>a</ToUserName><FromUserName>b</FromUserName><MsgType>text</MsgType><Content>&x;</Content><MsgId>5101</MsgId></xml>' );
check_wx( 400 === $inline->get_status() && array() === $GLOBALS['aicfab_runs'], 'So is one that defines its own entities.' );
check_wx( 400 === wx_post( 'not xml' )->get_status(), 'Something that is not XML is refused.' );
check_wx( 400 === wx_post( wx_message( str_repeat( 'a', 70000 ), 5201 ) )->get_status() && array() === $GLOBALS['aicfab_runs'], 'An oversized request is refused.' );
$unsigned = wx_serve( new WP_REST_Request( 'POST', array( 'timestamp' => (string) time(), 'nonce' => 'x', 'signature' => '' ), wx_message( 'Hi', 5001 ) ) );
check_wx( 403 === $unsigned->get_status() && array() === $GLOBALS['aicfab_runs'], 'An unsigned message is refused before anything is answered.' );

// --- The follower's language ---------------------------------------------------------------------

check_wx( 'zh_CN' === AI_Chat_Bedrock_WeChat::follower_locale( '周日送货吗？' ) && 'zh_CN' === AI_Chat_Bedrock_WeChat::follower_locale( '' ) && 'zh_CN' === AI_Chat_Bedrock_WeChat::follower_locale( '1' ), 'Chinese, an event and 1 get Chinese.' );
check_wx( 'ja' === AI_Chat_Bedrock_WeChat::follower_locale( '日曜日に配達しますか？' ), 'Japanese is told from Chinese by its kana.' );
check_wx( '' === AI_Chat_Bedrock_WeChat::follower_locale( 'Do you deliver?' ), 'Other text gets the site\'s language.' );
$GLOBALS['aicfab_locales'] = array();
function switch_to_locale( $locale ) {
	$GLOBALS['aicfab_locales'][] = $locale;
	return true;
}
function restore_previous_locale() {
	$GLOBALS['aicfab_locales'][] = 'restored';
	return true;
}
wx_reset();
wx_post( wx_message( '周日送货吗？', 7001 ) );
check_wx( array( 'zh_CN', 'restored' ) === $GLOBALS['aicfab_locales'], 'The reply is written in the follower\'s language, and the site\'s is restored after.' );
check_wx( 'zh' === $GLOBALS['aicfab_built']['options']['_retrieval_language'], 'A Chinese question is answered from Chinese pages first.' );
$GLOBALS['aicfab_locales'] = array();
wx_post( wx_message( 'Do you deliver?', 7002 ) );
check_wx( array() === $GLOBALS['aicfab_locales'], 'An English message leaves the site\'s language alone.' );
check_wx( ! isset( $GLOBALS['aicfab_built']['options']['_retrieval_language'] ), 'An English one is answered from pages in any language.' );

// --- Formatting ------------------------------------------------------------------------------------

$formatted = AI_Chat_Bedrock_WeChat::format( "## Opening hours\n\n* Tuesday to **Saturday**\n- Sunday, see [the page](https://example.test/hours/)\n\n\n\nThanks `now`" );
check_wx( "Opening hours\n\n· Tuesday to Saturday\n· Sunday, see the page https://example.test/hours/\n\nThanks now" === $formatted, 'Markdown is turned into plain text: ' . json_encode( $formatted ) );
for ( $aicfab_shift = 0; $aicfab_shift < 3; $aicfab_shift++ ) {
	$long = AI_Chat_Bedrock_WeChat::format( str_repeat( 'a', $aicfab_shift ) . str_repeat( '面包每天早上新鲜出炉。', 200 ), array( array( 'title' => 'Bread', 'url' => 'https://example.test/bread/' ) ), 'AI 生成' );
	check_wx( strlen( $long ) <= AI_Chat_Bedrock_WeChat::MAX_REPLY_BYTES && 1 === preg_match( '//u', $long ) && false !== strpos( $long, "…\n\nSources:\nBread\nhttps://example.test/bread/\n\nAI 生成" ), 'A long answer is cut to what WeChat shows, between characters, and keeps its source and label (shift ' . $aicfab_shift . ').' );
}
add_filter( 'ai_chat_bedrock_wechat_ai_label', function () { return ''; } );
check_wx( '' === AI_Chat_Bedrock_WeChat::ai_label(), 'A site can change or remove the label.' );
unset( $GLOBALS['aicfab_filters']['ai_chat_bedrock_wechat_ai_label'] );
check_wx( false === strpos( AI_Chat_Bedrock_WeChat::format( 'See https://example.test/delivery/ for times.', array( array( 'title' => 'Delivery', 'url' => 'https://example.test/delivery/' ) ) ), 'Sources:' ), 'A source the answer already links to is not repeated.' );
check_wx( false === strpos( AI_Chat_Bedrock_WeChat::format( "Sources:\nhttps://example.test/delivery/", array( array( 'title' => 'Delivery', 'url' => 'https://example.test/delivery/' ), array( 'title' => 'Hours', 'url' => 'https://example.test/hours/' ) ) ), 'hours' ), 'An answer that cites its sources gets no second list, even of the others.' );
check_wx( false === strpos( AI_Chat_Bedrock_WeChat::format( "Sources: Episode 0.1\nhttps://example.test/episode-0-1/", array( array( 'title' => 'Hours', 'url' => 'https://example.test/hours/' ) ) ), 'hours' ), 'Nor does one that links to another page than those listed.' );
$xml = AI_Chat_Bedrock_WeChat::text_xml( 'o1', 'gh', 'Tricky ]]> text' );
check_wx( 'Tricky ]]> text' === AI_Chat_Bedrock_WeChat::parse( $xml )['Content'], 'Text that would end a CDATA section is kept intact.' );

// --- Safe mode -----------------------------------------------------------------------------------

// Encrypted with an independent implementation of WeChat's scheme (Python cryptography).
$vector = 'Q3stYC6hdFzMh9T8HCvyDBmmox8qWutExXCCUzyXU6ZF0xp9U2Tf4hwvLbVby0M0iqmYIotBGUQOUKw2GwgpDZ2/hhr+v/5p8G3K+Vt8BnsgkyPc8ZqgCMzpAY3J4tjrFaLd0/YHA8eJzJoP7WPPoWoMc8RDhXNF66yW5DBVbrbl1+CFL1scacAOxwEHdy3zR7BiyZkt6PaK9B2ftizwCK1yrJEUjAIZdsc0vlOxpXPxuIZoAyA48V5gNF5M+fV8ZV+PCKy5pUh16PXaNsQCN84tySFlxnnwspPA82nAXWfL4S4sqy+3FqEed8aHSdFqxhvjc2pdJMNMTNoWgEgusQsVOkOLRcMcNtZHLSHemc84c4CNn2Xmq8otQMKrpES+';
check_wx( AI_Chat_Bedrock_WeChat::signature_matches( '008848a8e0d83b98b4c1fb48b038e2bf9cfd401f', array( AICFAB_TOKEN, '1791000000', 'abc123', $vector ) ), 'The message signature matches the reference implementation.' );
$plain = AI_Chat_Bedrock_WeChat::decrypt( $vector, AICFAB_AES_KEY, AICFAB_APP_ID );
check_wx( is_string( $plain ) && '周日送货吗？' === AI_Chat_Bedrock_WeChat::parse( $plain )['Content'], 'A message encrypted elsewhere is decrypted.' );
check_wx( null === AI_Chat_Bedrock_WeChat::decrypt( $vector, AICFAB_AES_KEY, 'wx0000000000000000' ), 'A message for another AppID is refused.' );
check_wx( null === AI_Chat_Bedrock_WeChat::decrypt( $vector, 'Bbcdefghijklmnopqrstuvwxyz0123456789ABCDEFG', AICFAB_APP_ID ), 'The wrong key decrypts nothing.' );
check_wx( null === AI_Chat_Bedrock_WeChat::decrypt( 'not base64!', AICFAB_AES_KEY, AICFAB_APP_ID ), 'Garbage decrypts nothing.' );
$round = AI_Chat_Bedrock_WeChat::encrypt( '<xml>hello</xml>', AICFAB_AES_KEY, AICFAB_APP_ID );
check_wx( '<xml>hello</xml>' === AI_Chat_Bedrock_WeChat::decrypt( $round, AICFAB_AES_KEY, AICFAB_APP_ID ) && AI_Chat_Bedrock_WeChat::encrypt( '<xml>hello</xml>', AICFAB_AES_KEY, AICFAB_APP_ID ) !== $round, 'A reply encrypts and decrypts, with a fresh random prefix each time.' );

wx_reset();
wx_settings( array( 'wechat_aes_key' => AI_Chat_Bedrock_Security::encrypt_secret( AICFAB_AES_KEY ), 'wechat_app_id' => AICFAB_APP_ID ) );
$downgrade = wx_post( wx_message( 'Spend money as someone else', 6099 ) );
check_wx( 403 === $downgrade->get_status() && 'plaintext' === $GLOBALS['aicfab_store']['aicfab_wechat_contact']['result'], 'With an EncodingAESKey set, a plaintext message, whose signature does not cover it, is refused.' );
$timestamp = (string) time();
$inner     = AI_Chat_Bedrock_WeChat::encrypt( wx_message( 'Do you deliver on Sundays?', 6001 ), AICFAB_AES_KEY, AICFAB_APP_ID );
$parts     = array( AICFAB_TOKEN, $timestamp, 'n6001', $inner );
sort( $parts, SORT_STRING );
$safe      = wx_serve( new WP_REST_Request( 'POST', array( 'encrypt_type' => 'aes', 'msg_signature' => sha1( implode( '', $parts ) ), 'timestamp' => $timestamp, 'nonce' => 'n6001', 'signature' => 'unused' ), '<xml><ToUserName><![CDATA[gh_bakery]]></ToUserName><Encrypt><![CDATA[' . $inner . ']]></Encrypt></xml>' ) );
$outer     = AI_Chat_Bedrock_WeChat::parse( (string) $safe->get_data() );
check_wx( is_array( $outer ) && ! empty( $outer['Encrypt'] ) && AI_Chat_Bedrock_WeChat::signature_matches( $outer['MsgSignature'], array( AICFAB_TOKEN, $outer['TimeStamp'], $outer['Nonce'], $outer['Encrypt'] ) ), 'In safe mode the reply is encrypted and signed.' );
$reply = AI_Chat_Bedrock_WeChat::parse( (string) AI_Chat_Bedrock_WeChat::decrypt( $outer['Encrypt'], AICFAB_AES_KEY, AICFAB_APP_ID ) );
check_wx( is_array( $reply ) && 0 === strpos( $reply['Content'], 'We deliver on Sundays' ), 'And it holds the answer.' );
$parts[] = 'x';
$forged  = wx_serve( new WP_REST_Request( 'POST', array( 'encrypt_type' => 'aes', 'msg_signature' => str_repeat( 'b', 40 ), 'timestamp' => $timestamp, 'nonce' => 'n6002' ), '<xml><Encrypt><![CDATA[' . $inner . ']]></Encrypt></xml>' ) );
check_wx( 403 === $forged->get_status(), 'In safe mode a wrong message signature is refused.' );

// --- Sent as it is ---------------------------------------------------------------------------------

ob_start();
$served = AI_Chat_Bedrock_WeChat::serve( false, $response, new WP_REST_Request( 'POST', array() ) );
$output = ob_get_clean();
check_wx( true === $served && 0 === strpos( $output, '<xml>' ), 'The reply is sent as XML, not encoded as JSON.' );
check_wx( false === AI_Chat_Bedrock_WeChat::serve( false, new WP_REST_Response( array( 'a' => 1 ) ), new WP_REST_Request( 'POST', array() ) ), 'Other responses on the route are left to WordPress.' );

$bootstrap = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock.php' );
check_wx( false !== strpos( $bootstrap, "add_filter( 'rest_pre_serve_request', 'AI_Chat_Bedrock_WeChat', 'serve', 10, 3 )" ) && false !== strpos( $bootstrap, "add_action( 'rest_api_init', \$wechat, 'register_routes' )" ), 'The route and the raw output are hooked.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: WeChat checks passed\n";
