<?php
/**
 * Standalone tests for a WeChat mini game's customer service and counts.
 *
 * Run: php tests/wechat-game.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

const AICFAB_TOKEN   = 'gametoken42';
const AICFAB_AES_KEY = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';
const AICFAB_APP_ID  = 'wx1234567890game00';
const AICFAB_SECRET  = '0123456789abcdef0123456789abcdef';

$GLOBALS['aicfab_options']    = array();
$GLOBALS['aicfab_store']      = array();
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_records']    = array();
$GLOBALS['aicfab_http']       = array();
$GLOBALS['aicfab_replies']    = array();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = array() ) {
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
class WP_REST_Request {
	private $method;
	private $params;
	private $body;
	private $route;
	public function __construct( $method, $params, $body = '', $route = '/ai-chat-bedrock/v1/wechat-game' ) {
		$this->method = $method;
		$this->params = $params;
		$this->body   = $body;
		$this->route  = $route;
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
		return $this->route;
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
function get_option( $name, $fallback = false ) {
	if ( 'ai_chat_bedrock_settings' === $name ) {
		return $GLOBALS['aicfab_options'];
	}
	return array_key_exists( $name, $GLOBALS['aicfab_store'] ) ? $GLOBALS['aicfab_store'][ $name ] : $fallback;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_store'][ $name ] = $value;
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
function apply_filters( $hook, $value ) {
	return $value;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function __( $text, $domain = null ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
}
function wp_strip_all_tags( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, null === $timestamp ? time() : $timestamp );
}
function wp_salt() {
	return 'test-salt';
}
function human_time_diff( $from, $to ) {
	return max( 1, (int) round( ( $to - $from ) / 60 ) ) . ' mins';
}
function rest_url( $path ) {
	return 'https://example.test/wp-json/' . $path;
}
$GLOBALS['aicfab_route'] = array();
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['aicfab_route'] = array( $namespace, $route, $args );
}
/** WeChat's API: the next queued answer for a path, or a good one. */
function wp_safe_remote_post( $url, $args ) {
	$path                     = substr( strtok( $url, '?' ), strlen( 'https://api.weixin.qq.com/cgi-bin/' ) );
	$GLOBALS['aicfab_http'][] = array(
		'path' => $path,
		'url'  => $url,
		'body' => json_decode( $args['body'], true ),
		'raw'  => $args['body'],
	);
	if ( ! empty( $GLOBALS['aicfab_replies'][ $path ] ) ) {
		return array( 'body' => array_shift( $GLOBALS['aicfab_replies'][ $path ] ) );
	}
	return array( 'body' => 'stable_token' === $path ? '{"access_token":"AT-' . count( $GLOBALS['aicfab_http'] ) . '","expires_in":7200}' : '{"errcode":0,"errmsg":"ok"}' );
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}
function wp_remote_retrieve_response_code( $response ) {
	return 200;
}

class AI_Chat_Bedrock_WP_MCP_Server {
	const NAMESPACE_V1 = 'ai-chat-bedrock/v1';
}
class AI_Chat_Bedrock_Conversations {
	public static function record( $question, $answer, $context ) {
		$GLOBALS['aicfab_records'][] = compact( 'question', 'answer', 'context' );
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat-api.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat-game.php';

$failures = array();
function check_game( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}
function game_settings( $extra = array() ) {
	$GLOBALS['aicfab_options'] = array_merge(
		array(
			'wxgame_enabled'    => true,
			'wxgame_app_id'     => AICFAB_APP_ID,
			'wxgame_token'      => AI_Chat_Bedrock_Security::encrypt_secret( AICFAB_TOKEN ),
			'wxgame_app_secret' => AI_Chat_Bedrock_Security::encrypt_secret( AICFAB_SECRET ),
			'wxgame_welcome'    => 'Welcome! Ask about levels or payments.',
			'wxgame_answers'    => "充值, 支付 | pay = 充值由微信支付处理，发送订单号查询。\nlevel 3 = Level 3 opens after the forest boss.",
		),
		$extra
	);
}
function game_reset() {
	$GLOBALS['aicfab_store']      = array();
	$GLOBALS['aicfab_transients'] = array();
	$GLOBALS['aicfab_records']    = array();
	$GLOBALS['aicfab_http']       = array();
	$GLOBALS['aicfab_replies']    = array();
}
function game_serve( $request ) {
	$handler = new AI_Chat_Bedrock_WeChat_Game();
	$allowed = $handler->check_permission( $request );
	if ( true !== $allowed ) {
		return new WP_REST_Response( 'refused', $allowed->get_error_data()['status'] );
	}
	return $handler->handle( $request );
}
function game_signed( $method, $body = '', $params = array() ) {
	$timestamp = (string) time();
	$nonce     = 'n' . uniqid( '', true );
	$parts     = array( AICFAB_TOKEN, $timestamp, $nonce );
	sort( $parts, SORT_STRING );
	return game_serve(
		new WP_REST_Request(
			$method,
			$params + array(
				'timestamp' => $timestamp,
				'nonce'     => $nonce,
				'signature' => sha1( implode( '', $parts ) ),
			),
			$body
		)
	);
}
function game_json( $fields ) {
	return json_encode( $fields + array( 'ToUserName' => 'gh_game', 'FromUserName' => 'oPlayer1', 'CreateTime' => time() ), JSON_UNESCAPED_UNICODE );
}
function game_sent() {
	$sent = array();
	foreach ( $GLOBALS['aicfab_http'] as $call ) {
		if ( 'message/custom/send' === $call['path'] ) {
			$sent[] = $call['body']['text']['content'];
		}
	}
	return $sent;
}
function game_today() {
	$stats = AI_Chat_Bedrock_WeChat_Game::stats();
	return isset( $stats[ gmdate( 'Y-m-d' ) ] ) ? $stats[ gmdate( 'Y-m-d' ) ] : array();
}

// --- Settings -------------------------------------------------------------------------------

check_game( ! AI_Chat_Bedrock_WeChat_Game::enabled( array() ), 'Off unless turned on.' );
check_game( ! AI_Chat_Bedrock_WeChat_Game::enabled( array( 'wxgame_enabled' => true, 'wxgame_token' => AI_Chat_Bedrock_Security::encrypt_secret( AICFAB_TOKEN ) ) ), 'Not on without the AppID, which every message carries.' );
game_settings();
check_game( AI_Chat_Bedrock_WeChat_Game::enabled() && AICFAB_TOKEN === AI_Chat_Bedrock_WeChat_Game::token() && AICFAB_SECRET === AI_Chat_Bedrock_WeChat_Game::app_secret(), 'On with a token and AppID; the secrets are stored encrypted.' );
check_game( false === strpos( $GLOBALS['aicfab_options']['wxgame_app_secret'], AICFAB_SECRET ), 'The stored AppSecret is not the AppSecret itself.' );
check_game( '' === AI_Chat_Bedrock_WeChat_Game::clean_app_secret( 'short' ) && '' === AI_Chat_Bedrock_WeChat_Game::clean_app_secret( AICFAB_SECRET . 'x' ), 'An AppSecret is 32 letters and digits.' );
check_game( 'https://example.test/wp-json/ai-chat-bedrock/v1/wechat-game' === AI_Chat_Bedrock_WeChat_Game::url(), 'The game has its own address, apart from the Official Account\'s.' );
( new AI_Chat_Bedrock_WeChat_Game() )->register_routes();
check_game( '/wechat-game' === $GLOBALS['aicfab_route'][1] && 'GET' === $GLOBALS['aicfab_route'][2][0]['methods'] && 'POST' === $GLOBALS['aicfab_route'][2][1]['methods'] && is_array( $GLOBALS['aicfab_route'][2][0]['permission_callback'] ), 'GET and POST are registered, the signature being the permission.' );

// --- Set answers ----------------------------------------------------------------------------

$answers = AI_Chat_Bedrock_WeChat_Game::answers();
check_game( 2 === count( $answers ) && array( '充值', '支付', 'pay' ) === $answers[0]['keywords'], 'Keywords may be separated by commas or |.' );
check_game( '充值由微信支付处理，发送订单号查询。' === AI_Chat_Bedrock_WeChat_Game::match( '我充值没到账', $answers ), 'A Chinese question finds its answer.' );
check_game( 'Level 3 opens after the forest boss.' === AI_Chat_Bedrock_WeChat_Game::match( 'How do I open LEVEL 3?', $answers ), 'Matching ignores case.' );
check_game( '' === AI_Chat_Bedrock_WeChat_Game::match( 'hello', $answers ), 'A question without a keyword has no answer.' );
check_game( "a = b\nc, d = e" === AI_Chat_Bedrock_WeChat_Game::clean_answers( "a = b\nnot an answer\n = no keywords\nc, d = e\n" ), 'Lines without keywords and an answer are dropped.' );
check_game( AI_Chat_Bedrock_WeChat_Game::MAX_ANSWERS === count( explode( "\n", AI_Chat_Bedrock_WeChat_Game::clean_answers( str_repeat( "k = v\n", 80 ) ) ) ), 'The set answers are limited.' );
check_game( 'x = <b>bold</b>' !== AI_Chat_Bedrock_WeChat_Game::clean_answers( 'x = <b>bold</b>' ), 'Markup is removed from answers.' );

// --- Requests that are not WeChat's ---------------------------------------------------------

$check = game_signed( 'GET', '', array( 'echostr' => '7788' ) );
check_game( '7788' === $check->get_data() && 'checked' === $GLOBALS['aicfab_store']['aicfab_wxgame_contact']['result'], 'WeChat\'s check of the address gets echostr back, and is noted.' );
$forged = game_serve( new WP_REST_Request( 'POST', array( 'timestamp' => (string) time(), 'nonce' => 'n1', 'signature' => str_repeat( 'a', 40 ) ), game_json( array( 'MsgType' => 'text', 'Content' => 'pay', 'MsgId' => 1 ) ) ) );
check_game( 403 === $forged->get_status() && array() === $GLOBALS['aicfab_http'] && 'signature' === $GLOBALS['aicfab_store']['aicfab_wxgame_contact']['result'], 'A request with the wrong signature is refused, and nothing is sent.' );
$old = game_serve( new WP_REST_Request( 'GET', array( 'timestamp' => (string) ( time() - 3600 ), 'nonce' => 'n1', 'signature' => 'x' ) ) );
check_game( 403 === $old->get_status(), 'An old request is refused.' );
check_game( null === AI_Chat_Bedrock_WeChat_Game::parse( '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><xml><a>&e;</a></xml>' ), 'XML with a document type is refused.' );
game_settings( array( 'wxgame_enabled' => false ) );
check_game( 404 === game_signed( 'GET', '', array( 'echostr' => '1' ) )->get_status(), 'The address is not found while the game is off.' );
game_settings();
game_reset();

// --- A question with a set answer, in plaintext JSON ----------------------------------------

$response = game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => '充值没到账', 'MsgId' => 9001 ) ) );
check_game( 'success' === $response->get_data() && 200 === $response->get_status(), 'WeChat is told success.' );
check_game( array( 'stable_token', 'message/custom/send' ) === array_column( $GLOBALS['aicfab_http'], 'path' ), 'An access token is fetched from the stable token API, then the answer is sent.' );
check_game( AICFAB_APP_ID === $GLOBALS['aicfab_http'][0]['body']['appid'] && false === $GLOBALS['aicfab_http'][0]['body']['force_refresh'], 'The token is asked for the game, without cancelling one the game\'s server holds.' );
check_game( 'oPlayer1' === $GLOBALS['aicfab_http'][1]['body']['touser'] && false !== strpos( $GLOBALS['aicfab_http'][1]['raw'], '充值由微信支付处理' ) && false !== strpos( $GLOBALS['aicfab_http'][1]['url'], 'access_token=AT-1' ), 'The set answer goes to the player, as readable UTF-8.' );
$today = game_today();
check_game( 1 === $today['players'] && 1 === $today['messages']['text'] && 1 === $today['answered'] && 1 === $today['replies'], 'The player, message, match and answer are counted.' );
check_game( 'wxgame' === $GLOBALS['aicfab_records'][0]['context']['source'] && true === $GLOBALS['aicfab_records'][0]['context']['grounded'], 'The conversation log, when on, records the question as the mini game\'s.' );
check_game( false === strpos( json_encode( $GLOBALS['aicfab_store'] ) . json_encode( $GLOBALS['aicfab_records'] ), 'oPlayer1' ), 'No OpenID is kept in the counts or the log.' );
$again = game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => '充值没到账', 'MsgId' => 9001 ) ) );
check_game( 'success' === $again->get_data() && 1 === count( game_sent() ) && 1 === game_today()['messages']['text'], 'WeChat sending the message again is neither answered nor counted twice.' );

game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => 'level 3?', 'MsgId' => 9002 ) ) );
check_game( 2 === count( game_sent() ) && 3 === count( $GLOBALS['aicfab_http'] ), 'The access token is kept for the next answer.' );
check_game( false === strpos( json_encode( $GLOBALS['aicfab_transients'][ AI_Chat_Bedrock_WeChat_Game::ACCESS_KEY ] ), 'AT-1' ), 'The kept access token is encrypted.' );
check_game( 1 === game_today()['players'], 'A player is counted once a day.' );

game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => 'hello?', 'MsgId' => 9003 ) ) );
check_game( 2 === count( game_sent() ) && 1 === game_today()['unanswered'] && false === $GLOBALS['aicfab_records'][2]['context']['grounded'], 'A question without a set answer gets nothing by default, and shows as a gap.' );
game_settings( array( 'wxgame_fallback' => 'Thanks — we read every message.' ) );
game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => 'hello again', 'MsgId' => 9004 ) ) );
$aicfab_sent = game_sent();
check_game( 'Thanks — we read every message.' === end( $aicfab_sent ), 'Or gets the reply set for that.' );
game_signed( 'POST', game_json( array( 'MsgType' => 'image', 'PicUrl' => 'https://example.test/p.jpg', 'MediaId' => 'm', 'MsgId' => 9005 ) ) );
check_game( 1 === game_today()['messages']['image'] && 3 === count( game_sent() ), 'Images are counted, and not answered.' );
game_settings();

// --- Opening the chat, in XML ---------------------------------------------------------------

game_reset();
$enter = '<xml><ToUserName><![CDATA[gh_game]]></ToUserName><FromUserName><![CDATA[oPlayer2]]></FromUserName><CreateTime>' . time() . '</CreateTime><MsgType><![CDATA[event]]></MsgType><Event><![CDATA[user_enter_tempsession]]></Event><SessionFrom><![CDATA[level-3 shop!]]></SessionFrom></xml>';
game_signed( 'POST', $enter );
$today = game_today();
check_game( 1 === $today['sessions'] && array( 'level-3shop' => 1 ) === $today['scenes'] && 1 === $today['players'], 'Opening the chat is counted with its scene.' );
check_game( array( 'Welcome! Ask about levels or payments.' ) === game_sent(), 'The player is welcomed.' );
game_signed( 'POST', str_replace( '<CreateTime>' . time(), '<CreateTime>' . ( time() + 1 ), $enter ) );
check_game( 1 === count( game_sent() ) && 2 === game_today()['sessions'], 'A player opening the chat again soon is counted, not welcomed again.' );
for ( $aicfab_i = 0; $aicfab_i < 25; $aicfab_i++ ) {
	game_signed( 'POST', game_json( array( 'MsgType' => 'event', 'Event' => 'user_enter_tempsession', 'SessionFrom' => 'scene' . $aicfab_i, 'CreateTime' => time() + 10 + $aicfab_i ) ) );
}
check_game( AI_Chat_Bedrock_WeChat_Game::MAX_KEYS === count( game_today()['scenes'] ) && isset( game_today()['scenes']['other'] ), 'Scenes are limited a day, the rest counted as other.' );

// --- Subscription prompts -------------------------------------------------------------------

game_reset();
$popup = '<xml><ToUserName><![CDATA[gh_game]]></ToUserName><FromUserName><![CDATA[oPlayer3]]></FromUserName><CreateTime>' . time() . '</CreateTime><MsgType><![CDATA[event]]></MsgType><Event><![CDATA[subscribe_msg_popup_event]]></Event><SubscribeMsgPopupEvent><List><TemplateId><![CDATA[TplA]]></TemplateId><SubscribeStatusString><![CDATA[accept]]></SubscribeStatusString><PopupScene>0</PopupScene></List><List><TemplateId><![CDATA[TplB]]></TemplateId><SubscribeStatusString><![CDATA[reject]]></SubscribeStatusString><PopupScene>0</PopupScene></List></SubscribeMsgPopupEvent></xml>';
game_signed( 'POST', $popup );
game_signed( 'POST', game_json( array( 'MsgType' => 'event', 'Event' => 'subscribe_msg_popup_event', 'SubscribeMsgPopupEvent' => array( array( 'TemplateId' => 'TplA', 'SubscribeStatusString' => 'reject', 'PopupScene' => '0' ) ) ) ) );
game_signed( 'POST', game_json( array( 'MsgType' => 'event', 'Event' => 'subscribe_msg_sent_event', 'CreateTime' => time() + 1, 'SubscribeMsgSentEvent' => array( 'List' => array( 'TemplateId' => 'TplA', 'MsgID' => '1', 'ErrorCode' => '0', 'ErrorStatus' => 'success' ) ) ) ) );
game_signed( 'POST', game_json( array( 'MsgType' => 'event', 'Event' => 'subscribe_msg_change_event', 'CreateTime' => time() + 2, 'SubscribeMsgChangeEvent' => array( 'List' => array( array( 'TemplateId' => 'TplB', 'SubscribeStatusString' => 'reject' ) ) ) ) ) );
$templates = game_today()['templates'];
check_game( array( 'accepted' => 1, 'declined' => 1, 'delivered' => 1 ) === $templates['TplA'] && array( 'declined' => 1, 'turned_off' => 1 ) === $templates['TplB'], 'Answers to prompts, deliveries and changes are counted per template, from XML lists and JSON alike.' );
check_game( array() === $GLOBALS['aicfab_http'] && ! isset( game_today()['players'] ), 'Prompt events are not answered, and not counted as players.' );
game_signed( 'POST', game_json( array( 'MsgType' => 'event', 'Event' => 'xpay_goods_deliver_notify', 'CreateTime' => time() + 3 ) ) );
check_game( array( 'xpay_goods_deliver_notify' => 1 ) === game_today()['events'], 'Other events are counted by name, and nothing else is done.' );

// --- Safe mode ------------------------------------------------------------------------------

game_reset();
game_settings( array( 'wxgame_aes_key' => AI_Chat_Bedrock_Security::encrypt_secret( AICFAB_AES_KEY ) ) );
$downgrade = game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => 'pay', 'MsgId' => 9099 ) ) );
check_game( 403 === $downgrade->get_status() && 'plaintext' === $GLOBALS['aicfab_store']['aicfab_wxgame_contact']['result'] && array() === $GLOBALS['aicfab_http'], 'With an EncodingAESKey set, a plaintext message, whose signature does not cover it, is refused.' );
$inner     = game_json( array( 'MsgType' => 'text', 'Content' => 'pay', 'MsgId' => 9100 ) );
$encrypted = AI_Chat_Bedrock_WeChat::encrypt( $inner, AICFAB_AES_KEY, AICFAB_APP_ID );
$timestamp = (string) time();
$parts     = array( AICFAB_TOKEN, $timestamp, 'nsafe', $encrypted );
sort( $parts, SORT_STRING );
$safe = game_serve( new WP_REST_Request( 'POST', array( 'timestamp' => $timestamp, 'nonce' => 'nsafe', 'encrypt_type' => 'aes', 'msg_signature' => sha1( implode( '', $parts ) ) ), json_encode( array( 'ToUserName' => 'gh_game', 'Encrypt' => $encrypted ) ) ) );
check_game( 'success' === $safe->get_data() && 1 === count( game_sent() ), 'A safe mode message in JSON is decrypted and answered.' );
$other     = AI_Chat_Bedrock_WeChat::encrypt( $inner, AICFAB_AES_KEY, 'wx9999999999999999' );
$parts     = array( AICFAB_TOKEN, $timestamp, 'nsafe2', $other );
sort( $parts, SORT_STRING );
$refused = game_serve( new WP_REST_Request( 'POST', array( 'timestamp' => $timestamp, 'nonce' => 'nsafe2', 'encrypt_type' => 'aes', 'msg_signature' => sha1( implode( '', $parts ) ) ), '<xml><ToUserName>gh</ToUserName><Encrypt><![CDATA[' . $other . ']]></Encrypt></xml>' ) );
check_game( 403 === $refused->get_status() && 1 === count( game_sent() ), 'A message for another AppID, such as the Official Account\'s, is refused.' );

// --- A signed address used again ----------------------------------------------------------

game_reset();
game_settings();
$aicfab_ts    = (string) time();
$aicfab_parts = array( AICFAB_TOKEN, $aicfab_ts, 'nreplay' );
sort( $aicfab_parts, SORT_STRING );
$aicfab_query = array( 'timestamp' => $aicfab_ts, 'nonce' => 'nreplay', 'signature' => sha1( implode( '', $aicfab_parts ) ) );
$body         = game_json( array( 'MsgType' => 'text', 'Content' => 'pay', 'MsgId' => 9140 ) );
$first        = game_serve( new WP_REST_Request( 'POST', $aicfab_query, $body ) );
$again        = game_serve( new WP_REST_Request( 'POST', $aicfab_query, $body ) );
$swapped      = game_serve( new WP_REST_Request( 'POST', $aicfab_query, str_replace( 'oPlayer1', 'oVictim', $body ) ) );
check_game( 'success' === $first->get_data() && 'success' === $again->get_data() && 403 === $swapped->get_status() && 1 === count( game_sent() ), 'A repeat of WeChat\'s request is taken once; a different message under the same signature, to send to another player, is refused.' );
check_game( AI_Chat_Bedrock_WeChat_Game::SEEN_TTL >= AI_Chat_Bedrock_WeChat_Game::MAX_AGE, 'A message is remembered for as long as its signature is accepted.' );

// --- Sharing the Official Account's token ---------------------------------------------------

game_reset();
game_settings( array( 'wechat_token' => AI_Chat_Bedrock_Security::encrypt_secret( AICFAB_TOKEN ), 'wxgame_aes_key' => AI_Chat_Bedrock_Security::encrypt_secret( AICFAB_AES_KEY ) ) );
$plain = game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => 'pay', 'MsgId' => 9150 ) ) );
check_game( 403 === $plain->get_status() && 'plaintext' === $GLOBALS['aicfab_store']['aicfab_wxgame_contact']['result'] && array() === $GLOBALS['aicfab_http'], 'With the Official Account\'s token, a plaintext message is refused, as it cannot show which account it is for.' );
check_game( '7788' === game_signed( 'GET', '', array( 'echostr' => '7788' ) )->get_data(), 'WeChat can still check the address.' );
$inner     = game_json( array( 'MsgType' => 'text', 'Content' => 'pay', 'MsgId' => 9151 ) );
$encrypted = AI_Chat_Bedrock_WeChat::encrypt( $inner, AICFAB_AES_KEY, AICFAB_APP_ID );
$timestamp = (string) time();
$parts     = array( AICFAB_TOKEN, $timestamp, 'nshared', $encrypted );
sort( $parts, SORT_STRING );
$shared = game_serve( new WP_REST_Request( 'POST', array( 'timestamp' => $timestamp, 'nonce' => 'nshared', 'encrypt_type' => 'aes', 'msg_signature' => sha1( implode( '', $parts ) ) ), json_encode( array( 'ToUserName' => 'gh_game', 'Encrypt' => $encrypted ) ) ) );
check_game( 'success' === $shared->get_data() && 1 === count( game_sent() ), 'A safe mode message for the game\'s AppID is taken.' );
game_settings();

// --- When an answer cannot be sent ----------------------------------------------------------

game_reset();
$GLOBALS['aicfab_replies']['message/custom/send'] = array( '{"errcode":40001,"errmsg":"invalid credential"}' );
game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => 'pay', 'MsgId' => 9200 ) ) );
check_game( array( 'stable_token', 'message/custom/send', 'stable_token', 'message/custom/send' ) === array_column( $GLOBALS['aicfab_http'], 'path' ) && true === $GLOBALS['aicfab_http'][2]['body']['force_refresh'] && 1 === game_today()['replies'], 'A token WeChat no longer accepts is replaced once, and the answer sent.' );

game_reset();
$GLOBALS['aicfab_replies']['stable_token'] = array( '{"errcode":40164,"errmsg":"invalid ip 203.0.113.9 ipv6 ::ffff:203.0.113.9, not in whitelist rid: 1"}' );
game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => 'pay', 'MsgId' => 9300 ) ) );
$summary = implode( ' ', AI_Chat_Bedrock_WeChat_Game::contact_summary() );
check_game( 1 === game_today()['failed'] && false !== strpos( $summary, 'Add 203.0.113.9 to the IP whitelist' ), 'An address not in the whitelist is named on the settings screen.' );
check_game( false === strpos( json_encode( $GLOBALS['aicfab_store'] ), 'rid' ), 'Only the code and address are kept, not WeChat\'s whole message.' );
$GLOBALS['aicfab_replies'] = array();
game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => 'pay', 'MsgId' => 9301 ) ) );
check_game( false !== strpos( implode( ' ', AI_Chat_Bedrock_WeChat_Game::contact_summary() ), 'The last answer was sent' ), 'An answer sent later clears the error.' );

game_reset();
game_settings( array( 'wxgame_app_secret' => '' ) );
game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => 'pay', 'MsgId' => 9400 ) ) );
check_game( array() === $GLOBALS['aicfab_http'] && 1 === game_today()['answered'] && ! isset( game_today()['failed'] ), 'Without the AppSecret nothing is sent, and the counts are kept.' );
game_settings();

// --- Totals ---------------------------------------------------------------------------------

game_reset();
$stats = array();
for ( $aicfab_day = 0; $aicfab_day < 100; $aicfab_day++ ) {
	$stats[ gmdate( 'Y-m-d', time() - $aicfab_day * DAY_IN_SECONDS ) ] = array(
		'players'  => 2,
		'sessions' => 3,
		'messages' => array( 'text' => 4 ),
		'answered' => 1,
		'scenes'   => array( 'shop' => 1 ),
	);
}
$GLOBALS['aicfab_store'][ AI_Chat_Bedrock_WeChat_Game::STATS_OPTION ] = $stats;
$summary = AI_Chat_Bedrock_WeChat_Game::summary( 30 );
check_game( 60 === $summary['players'] && 90 === $summary['sessions'] && 120 === $summary['messages'] && 30 === $summary['scenes']['shop'], 'The totals cover the last 30 days.' );
game_signed( 'POST', game_json( array( 'MsgType' => 'text', 'Content' => 'x', 'MsgId' => 9500 ) ) );
check_game( AI_Chat_Bedrock_WeChat_Game::STATS_DAYS === count( AI_Chat_Bedrock_WeChat_Game::stats() ), 'Counts are kept for 90 days.' );

// --- Serving --------------------------------------------------------------------------------

ob_start();
$served = AI_Chat_Bedrock_WeChat_Game::serve( false, game_signed( 'GET', '', array( 'echostr' => 'abc' ) ), new WP_REST_Request( 'GET', array() ) );
check_game( true === $served && 'abc' === ob_get_clean(), 'The route\'s replies are sent as plain text.' );
check_game( false === AI_Chat_Bedrock_WeChat_Game::serve( false, new WP_REST_Response( 'x' ), new WP_REST_Request( 'GET', array(), '', '/ai-chat-bedrock/v1/wechat' ) ), 'Other routes are left alone.' );

$root      = dirname( __DIR__ );
$bootstrap = file_get_contents( $root . '/includes/class-ai-chat-bedrock.php' );
check_game( false !== strpos( $bootstrap, "add_filter( 'rest_pre_serve_request', 'AI_Chat_Bedrock_WeChat_Game', 'serve', 10, 3 )" ) && false !== strpos( $bootstrap, "add_action( 'rest_api_init', \$wechat_game, 'register_routes' )" ), 'The route and its plain text replies are registered.' );
check_game( false !== strpos( file_get_contents( $root . '/includes/class-ai-chat-bedrock-transfer.php' ), "'wxgame_token', 'wxgame_aes_key', 'wxgame_app_secret'" ), 'Exports leave out the game\'s secrets.' );
$uninstall = file_get_contents( $root . '/uninstall.php' );
check_game( false !== strpos( $uninstall, "'aicfab_wxgame_stats'" ) && false !== strpos( $uninstall, "'aicfab_wxgame_access'" ), 'Uninstalling removes the counts and the access token.' );
check_game( false !== strpos( file_get_contents( $root . '/includes/class-ai-chat-bedrock-woocommerce.php' ), 'WeChat mini game' ), 'The suggested privacy policy describes the mini game.' );

// A UTF-16 body hides <!DOCTYPE from a check for its bytes; it is refused before parsing.
$aicfab_utf16 = "\xFF\xFE" . mb_convert_encoding( '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE xml [<!ENTITY a "aaaa">]><xml><Content>&a;</Content></xml>', 'UTF-16LE', 'UTF-8' );
check_game( null === AI_Chat_Bedrock_WeChat_Game::parse( $aicfab_utf16 ), 'The mini game refuses a UTF-16 body.' );
check_game( 'text' === AI_Chat_Bedrock_WeChat_Game::parse( '<xml><MsgType><![CDATA[text]]></MsgType></xml>' )['MsgType'], 'And still reads an ordinary XML message.' );

if ( $failures ) {
	echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}
echo "OK: WeChat mini game checks passed\n";
