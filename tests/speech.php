<?php
/**
 * Standalone tests for reading aloud: which answers and posts may be read, in which voice,
 * how text is split, the daily limit, the engine fallback and the saved post audio.
 *
 * Run: php tests/speech.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'FS_CHMOD_FILE', 0644 );

$GLOBALS['aicfab_uploads'] = sys_get_temp_dir() . '/aicfab-speech-test-' . getmypid();
$GLOBALS['aicfab_options'] = array( 'ai_chat_bedrock_settings' => array() );
$GLOBALS['aicfab_filters'] = array();
$GLOBALS['aicfab_user']    = 7;
$GLOBALS['aicfab_chat_ok'] = true;
$GLOBALS['aicfab_rate_ok'] = true;
$GLOBALS['aicfab_trans']   = array();
$GLOBALS['aicfab_routes']  = array();
$GLOBALS['aicfab_fs']      = 'direct';
$GLOBALS['aicfab_posts']   = array();
$GLOBALS['aicfab_page']    = array();
$GLOBALS['aicfab_scripts'] = array();

// --- WordPress stubs -------------------------------------------------------

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $key ] : $default;
}
function aicfab_settings( $settings ) {
	$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = $settings;
}
function add_filter( $hook, $callback ) {
	$GLOBALS['aicfab_filters'][ $hook ] = $callback;
}
function remove_all_filters( $hook ) {
	unset( $GLOBALS['aicfab_filters'][ $hook ] );
}
function apply_filters( $hook, $value, ...$args ) {
	return isset( $GLOBALS['aicfab_filters'][ $hook ] ) ? call_user_func( $GLOBALS['aicfab_filters'][ $hook ], $value, ...$args ) : $value;
}
function __( $text, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function absint( $value ) {
	return abs( (int) $value );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}
function wp_hash( $data ) {
	return hash_hmac( 'md5', (string) $data, 'test-salt' );
}
function current_user_can( $capability ) {
	return ! empty( $GLOBALS['aicfab_admin'] ) && 'manage_options' === $capability;
}
function get_current_user_id() {
	return (int) $GLOBALS['aicfab_user'];
}
function determine_locale() {
	return 'en_US';
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['aicfab_routes'][ $namespace . $route ] = $args;
	return true;
}
function rest_url( $path ) {
	return 'https://site.test/wp-json/' . $path;
}
function get_transient( $key ) {
	return isset( $GLOBALS['aicfab_trans'][ $key ] ) ? $GLOBALS['aicfab_trans'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['aicfab_trans'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['aicfab_trans'][ $key ] );
	return true;
}
function get_post( $id = null ) {
	if ( null === $id ) {
		$id = isset( $GLOBALS['aicfab_page']['post'] ) ? $GLOBALS['aicfab_page']['post'] : 0;
	}
	return isset( $GLOBALS['aicfab_posts'][ $id ] ) ? $GLOBALS['aicfab_posts'][ $id ] : null;
}
function wp_upload_dir( $time = null, $create = true ) {
	return array(
		'basedir' => $GLOBALS['aicfab_uploads'],
		'baseurl' => 'http://site.test/wp-content/uploads',
		'error'   => false,
	);
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}
function set_url_scheme( $url ) {
	return preg_replace( '#^http://#', 'https://', $url );
}
function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0755, true );
}
function wp_delete_file( $file ) {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}
function wp_generate_password( $length = 12, $special = true ) {
	return substr( md5( (string) mt_rand() ), 0, $length );
}
function get_filesystem_method() {
	return $GLOBALS['aicfab_fs'];
}
class AICFAB_Test_Filesystem {
	public function put_contents( $file, $contents, $mode = false ) {
		return false !== file_put_contents( $file, $contents );
	}
	public function move( $from, $to, $overwrite = false ) {
		return rename( $from, $to );
	}
	public function rmdir( $dir ) {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			unlink( $file );
		}
		return rmdir( $dir );
	}
}
function WP_Filesystem() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- WordPress's own name.
	$GLOBALS['wp_filesystem'] = new AICFAB_Test_Filesystem();
	return true;
}
function plugin_dir_url( $file ) {
	return 'https://site.test/wp-content/plugins/ai-chat-for-amazon-bedrock/';
}
function wp_script_is( $handle, $list = 'enqueued' ) {
	return 'registered' === $list ? isset( $GLOBALS['aicfab_scripts'][ $handle ]['src'] ) : ! empty( $GLOBALS['aicfab_scripts'][ $handle ]['enqueued'] );
}
function wp_register_script( $handle, $src, $deps = array(), $ver = false, $footer = false ) {
	$GLOBALS['aicfab_scripts'][ $handle ] = array( 'src' => $src );
}
function wp_register_style( $handle, $src ) {
	$GLOBALS['aicfab_scripts'][ $handle . '-style' ] = array( 'src' => $src );
}
function wp_localize_script( $handle, $name, $data ) {
	$GLOBALS['aicfab_scripts'][ $handle ]['data'] = $data;
}
function wp_enqueue_script( $handle ) {
	$GLOBALS['aicfab_scripts'][ $handle ]['enqueued'] = true;
}
function wp_enqueue_style( $handle ) {
	$GLOBALS['aicfab_scripts'][ $handle . '-style' ]['enqueued'] = true;
}
function aicfab_page( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['aicfab_page'] ) ? $GLOBALS['aicfab_page'][ $key ] : $default;
}
function is_singular( $types = '' ) {
	$post = get_post( aicfab_page( 'post', 0 ) );
	return aicfab_page( 'singular' ) && $post && in_array( $post->post_type, (array) $types, true );
}
function is_feed() {
	return aicfab_page( 'feed' );
}
function doing_filter( $hook ) {
	return aicfab_page( 'excerpt' ) && 'get_the_excerpt' === $hook;
}
function in_the_loop() {
	return aicfab_page( 'loop', true );
}
function is_main_query() {
	return aicfab_page( 'main', true );
}
function get_queried_object_id() {
	return (int) aicfab_page( 'queried', aicfab_page( 'post', 0 ) );
}
function get_queried_object() {
	return get_post( get_queried_object_id() );
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
class WP_REST_Response {
	public $data;
	public $status;
	public $headers = array();
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}
}
class WP_REST_Request {
	private $params;
	public function __construct( $params = array() ) {
		$this->params = $params;
	}
	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}
}
class WP_Post {
	public $ID;
	public $post_type   = 'post';
	public $post_status = 'publish';
	public $text        = '';
	public $language    = '';
	public $public      = true;
	public function __construct( $id, $text, $props = array() ) {
		$this->ID   = $id;
		$this->text = $text;
		foreach ( $props as $key => $value ) {
			$this->$key = $value;
		}
	}
}

// --- Plugin stubs ----------------------------------------------------------

class AI_Chat_Bedrock_WP_MCP_Server {
	const NAMESPACE_V1 = 'ai-chat-bedrock/v1';
}
class AI_Chat_Bedrock_Security {
	public static function string_length( $value ) {
		return mb_strlen( (string) $value, 'UTF-8' );
	}
	public static function string_substr( $value, $start, $length ) {
		return mb_substr( (string) $value, $start, $length, 'UTF-8' );
	}
	public static function can_use_chat( $options = null ) {
		return $GLOBALS['aicfab_chat_ok'];
	}
	public static function check_rate_limit( $bucket, $limit, $window = 60 ) {
		return $GLOBALS['aicfab_rate_ok'];
	}
}
class AI_Chat_Bedrock_Content {
	public static $rendering = false;
	public static function is_public( $post ) {
		return $post instanceof WP_Post && $post->public;
	}
	public static function public_text( $post ) {
		return self::is_public( $post ) ? $post->text : '';
	}
	public static function language( $post ) {
		return $post->language;
	}
	public static function is_rendering() {
		return self::$rendering;
	}
}
class AI_Chat_Bedrock_Usage {
	public static $characters = 0;
	public static function today_totals() {
		return array( 'speech_characters' => self::$characters );
	}
}
/**
 * Polly as the speech class sees it: every call is recorded, and an engine can be refused.
 */
class AI_Chat_Bedrock_AWS {
	public static $calls  = array();
	public static $refuse = array();
	public static $fail   = false;
	private $region;
	public function __construct( $options = array() ) {
		$this->region = $options['aws_region'];
	}
	public function synthesize_speech( $text, $voice, $language, $engine = 'neural' ) {
		self::$calls[] = compact( 'text', 'voice', 'language', 'engine' ) + array( 'region' => $this->region );
		if ( 403 === self::$fail ) {
			return new WP_Error( 'aicfab_http_error', 'User: arn:aws:sts::111122223333:assumed-role/site/i-1 is not authorized to perform: polly:SynthesizeSpeech', array( 'status' => 403 ) );
		}
		if ( self::$fail ) {
			return new WP_Error( 'aicfab_http_error', 'Rate exceeded', array( 'status' => 429 ) );
		}
		if ( in_array( $engine, self::$refuse, true ) ) {
			return new WP_Error( 'aicfab_http_error', 'This voice does not support the selected engine: ' . $engine, array( 'status' => 400 ) );
		}
		AI_Chat_Bedrock_Usage::$characters += mb_strlen( $text, 'UTF-8' );
		return 'MP3:' . $voice . ':' . $text;
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-speech.php';

$failures = array();
function check_speech( $condition, $label ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $label;
	}
}
function aicfab_saved_files() {
	$files = glob( $GLOBALS['aicfab_uploads'] . '/' . AI_Chat_Bedrock_Speech::DIRECTORY . '/*' );
	return array_map( 'basename', is_array( $files ) ? $files : array() );
}

// --- Settings --------------------------------------------------------------

check_speech( ! AI_Chat_Bedrock_Speech::replies_enabled() && ! AI_Chat_Bedrock_Speech::posts_enabled(), 'Reading aloud is off by default.' );
check_speech( 'neural' === AI_Chat_Bedrock_Speech::engine() && 'neural' === AI_Chat_Bedrock_Speech::engine( array( 'speech_engine' => 'long-form' ) ) && 'generative' === AI_Chat_Bedrock_Speech::engine( array( 'speech_engine' => 'generative' ) ), 'The engine is neural unless generative is chosen.' );
check_speech( 100000 === AI_Chat_Bedrock_Speech::daily_characters() && 0 === AI_Chat_Bedrock_Speech::daily_characters( array( 'speech_daily_chars' => '0' ) ) && AI_Chat_Bedrock_Speech::MAX_DAILY_CHARACTERS === AI_Chat_Bedrock_Speech::daily_characters( array( 'speech_daily_chars' => PHP_INT_MAX ) ), 'The daily limit defaults to 100,000 characters, 0 removes it, and it is capped.' );
check_speech( 100000 === AI_Chat_Bedrock_Speech::daily_characters( array( 'speech_daily_chars' => '' ) ) && 100000 === AI_Chat_Bedrock_Speech::daily_characters( array( 'speech_daily_chars' => ' ' ) ), 'An emptied limit field keeps the default rather than removing the limit.' );
check_speech( array( 'post' ) === AI_Chat_Bedrock_Speech::post_types(), 'Only posts offer Listen to this post unless filtered.' );
add_filter( 'ai_chat_bedrock_speech_post_types', function () { return array( 'post', 'Page!', '' ); } );
check_speech( array( 'post', 'page' ) === AI_Chat_Bedrock_Speech::post_types(), 'Filtered post types are sanitized.' );
remove_all_filters( 'ai_chat_bedrock_speech_post_types' );

aicfab_settings( array( 'aws_region' => 'us-west-2' ) );
check_speech( 'us-west-2' === AI_Chat_Bedrock_Speech::region(), 'Polly runs in the chat region.' );
add_filter( 'ai_chat_bedrock_speech_region', function () { return 'eu-central-1'; } );
check_speech( 'eu-central-1' === AI_Chat_Bedrock_Speech::region(), 'The region can be filtered.' );
add_filter( 'ai_chat_bedrock_speech_region', function () { return 'evil.example/'; } );
check_speech( 'us-west-2' === AI_Chat_Bedrock_Speech::region(), 'A malformed filtered region is ignored.' );
remove_all_filters( 'ai_chat_bedrock_speech_region' );

// --- Voices and languages ----------------------------------------------------

check_speech( array( 'en-GB', 'Amy' ) === AI_Chat_Bedrock_Speech::voice( 'en_GB' ) && array( 'en-US', 'Joanna' ) === AI_Chat_Bedrock_Speech::voice( 'en_US' ), 'A regional English locale has its own voice, others fall back to US English.' );
check_speech( array( 'cmn-CN', 'Zhiyu' ) === AI_Chat_Bedrock_Speech::voice( 'zh_CN' ) && array( 'yue-CN', 'Hiujin' ) === AI_Chat_Bedrock_Speech::voice( 'zh-HK' ), 'Mainland Chinese is read in Mandarin, Hong Kong Chinese in Cantonese.' );
check_speech( array( 'ja-JP', 'Kazuha' ) === AI_Chat_Bedrock_Speech::voice( 'ja' ) && array( 'pt-BR', 'Camila' ) === AI_Chat_Bedrock_Speech::voice( 'pt_BR' ) && array( 'de-DE', 'Vicki' ) === AI_Chat_Bedrock_Speech::voice( 'de_DE_formal' ), 'Polylang slugs and WordPress locales both find a voice.' );
check_speech( array( 'en-US', 'Joanna' ) === AI_Chat_Bedrock_Speech::voice( 'xx' ), 'A language without a voice is read in English.' );
add_filter( 'ai_chat_bedrock_speech_voice', function ( $voice, $language ) { return 'en' === $language ? array( 'en-US', 'Matthew' ) : array( 'en-US', 'bad"voice' ); } );
check_speech( array( 'en-US', 'Matthew' ) === AI_Chat_Bedrock_Speech::voice( 'en' ) && array( 'ja-JP', 'Kazuha' ) === AI_Chat_Bedrock_Speech::voice( 'ja' ), 'The voice can be filtered, and a malformed one is ignored.' );
remove_all_filters( 'ai_chat_bedrock_speech_voice' );

check_speech( 'zh' === AI_Chat_Bedrock_Speech::reply_language( '营业时间是周一到周五。', 'en' ), 'A Chinese answer is read in Chinese on an English page.' );
check_speech( 'zh_hk' === AI_Chat_Bedrock_Speech::reply_language( '營業時間是星期一至五。', 'zh-hk' ), 'A Hong Kong page keeps Cantonese.' );
check_speech( 'ja' === AI_Chat_Bedrock_Speech::reply_language( '営業時間は月曜日から金曜日です。', 'en' ), 'Kana marks a Japanese answer.' );
check_speech( 'ko' === AI_Chat_Bedrock_Speech::reply_language( '영업시간은 월요일부터 금요일까지입니다.', '' ), 'Hangul marks a Korean answer.' );
check_speech( 'en' === AI_Chat_Bedrock_Speech::reply_language( 'We open at nine.', 'zh' ) && 'fr_fr' === AI_Chat_Bedrock_Speech::reply_language( 'Nous ouvrons à neuf heures.', 'fr_FR' ), 'A Latin-script answer is read in the page language, or English on a Chinese page.' );
check_speech( 'en_us' === AI_Chat_Bedrock_Speech::reply_language( 'Use the AWS console to open Amazon Bedrock, then pick 模型.', '' ), 'A few Chinese words in an English answer do not change its language.' );

// --- What is read --------------------------------------------------------------

$aicfab_spoken = AI_Chat_Bedrock_Speech::speakable( "## Opening hours\n\n- **Weekdays**: 9 to 5, see [the page](https://site.test/hours/).\n- Code: `npm run build`\n\n```js\nconsole.log('hidden');\n```\n| Day | Open |\n|---|---|\n| Mon | Yes |\nMore at https://site.test/more and <b>soon</b>." );
check_speech( false === strpos( $aicfab_spoken, 'console.log' ) && false === strpos( $aicfab_spoken, 'https://' ), 'Code blocks and web addresses are not read.' );
check_speech( false !== strpos( $aicfab_spoken, 'Weekdays: 9 to 5, see the page.' ) && false !== strpos( $aicfab_spoken, 'npm run build' ), 'Link text and inline code are read without their marks.' );
check_speech( false === strpos( $aicfab_spoken, '#' ) && false === strpos( $aicfab_spoken, '*' ) && false === strpos( $aicfab_spoken, '|' ) && false === strpos( $aicfab_spoken, '---' ) && false === strpos( $aicfab_spoken, '<b>' ), 'Headings, bullets, emphasis, tables and tags are not read as symbols.' );
check_speech( 0 === strpos( $aicfab_spoken, 'Opening hours' ) && false !== strpos( $aicfab_spoken, 'Mon Yes' ), 'The words themselves are all kept.' );

check_speech( array( 'Short answer.' ) === AI_Chat_Bedrock_Speech::parts( '  Short answer. ', 6000 ) && array() === AI_Chat_Bedrock_Speech::parts( ' ', 6000 ), 'Short text is one part, and blank text none.' );
$aicfab_long  = str_repeat( 'This sentence is about forty characters. ', 100 );
$aicfab_parts = AI_Chat_Bedrock_Speech::parts( $aicfab_long, 6000 );
$aicfab_max   = max( array_map( 'mb_strlen', $aicfab_parts ) );
check_speech( count( $aicfab_parts ) >= 3 && $aicfab_max <= AI_Chat_Bedrock_Speech::MAX_PART, 'Long text is split into parts Polly accepts.' );
check_speech( '.' === substr( $aicfab_parts[0], -1 ) && trim( $aicfab_long ) === implode( ' ', $aicfab_parts ), 'Parts end at sentence ends and lose nothing.' );
$aicfab_parts = AI_Chat_Bedrock_Speech::parts( str_repeat( '这是一个没有句号的很长的句子，', 300 ), 30000 );
check_speech( count( $aicfab_parts ) >= 3 && max( array_map( 'mb_strlen', $aicfab_parts ) ) <= AI_Chat_Bedrock_Speech::MAX_PART && '，' === mb_substr( $aicfab_parts[0], -1 ), 'Chinese without sentence ends is split at a comma.' );
$aicfab_parts = AI_Chat_Bedrock_Speech::parts( str_repeat( 'x', 4000 ), 30000 );
check_speech( 3 === count( $aicfab_parts ) && 4000 === array_sum( array_map( 'strlen', $aicfab_parts ) ), 'A run with no break is cut anywhere rather than refused.' );
$aicfab_parts = AI_Chat_Bedrock_Speech::parts( str_repeat( 'One more short sentence here. ', 1000 ), 6000 );
check_speech( array_sum( array_map( 'mb_strlen', $aicfab_parts ) ) <= 6000 && '.' === substr( end( $aicfab_parts ), -1 ), 'Only the first 6,000 characters are read, ending on a sentence.' );

// --- Signatures and the route ------------------------------------------------------

$aicfab_token = AI_Chat_Bedrock_Speech::token( 'Hello' );
check_speech( 1 === preg_match( '/^[a-f0-9]{32}$/', $aicfab_token ), 'A signature is 32 hex characters.' );
check_speech( $aicfab_token !== AI_Chat_Bedrock_Speech::token( 'Hello!' ) && $aicfab_token !== AI_Chat_Bedrock_Speech::token( 'Hello', 8 ) && $aicfab_token === AI_Chat_Bedrock_Speech::token( 'Hello', 7 ), 'A signature belongs to one text and one visitor.' );
$aicfab_window = (int) floor( time() / AI_Chat_Bedrock_Speech::TOKEN_WINDOW );
check_speech( AI_Chat_Bedrock_Speech::valid_token( 'Hello', $aicfab_token ) && AI_Chat_Bedrock_Speech::valid_token( 'Hello', AI_Chat_Bedrock_Speech::token( 'Hello', null, $aicfab_window - 1 ) ), 'A signature works on the day it was given and the next.' );
check_speech( ! AI_Chat_Bedrock_Speech::valid_token( 'Hello', AI_Chat_Bedrock_Speech::token( 'Hello', null, $aicfab_window - 2 ) ) && ! AI_Chat_Bedrock_Speech::valid_token( 'Hello', array( $aicfab_token ) ), 'An older signature, or one that is not a string, is refused.' );

$aicfab_speech = new AI_Chat_Bedrock_Speech();
$aicfab_speech->register_routes();
$aicfab_route = $GLOBALS['aicfab_routes']['ai-chat-bedrock/v1/speech'];
check_speech( 'POST' === $aicfab_route['methods'] && array( $aicfab_speech, 'check_permission' ) === $aicfab_route['permission_callback'] && AI_Chat_Bedrock_Speech::MAX_PARTS === $aicfab_route['args']['part']['maximum'] && AI_Chat_Bedrock_Speech::MAX_PARTS * 500 >= 200000, 'The speech route takes POST and checks permission.' );

$aicfab_check = $aicfab_speech->check_permission( new WP_REST_Request( array( 'post' => 0 ) ) );
check_speech( is_wp_error( $aicfab_check ) && 'aicfab_speech_off' === $aicfab_check->get_error_code() && array( 'status' => 404 ) === $aicfab_check->get_error_data(), 'With reading aloud off the route refuses.' );
aicfab_settings( array( 'aws_region' => 'us-east-1', 'speech_replies' => true ) );
$aicfab_check = $aicfab_speech->check_permission( new WP_REST_Request( array( 'post' => 5 ) ) );
check_speech( is_wp_error( $aicfab_check ) && 'aicfab_speech_off' === $aicfab_check->get_error_code(), 'Answers being on does not open posts.' );
$GLOBALS['aicfab_chat_ok'] = false;
$aicfab_check = $aicfab_speech->check_permission( new WP_REST_Request( array( 'post' => 0 ) ) );
check_speech( is_wp_error( $aicfab_check ) && array( 'status' => 401 ) === $aicfab_check->get_error_data(), 'Only someone who may use the chat can hear answers.' );
$GLOBALS['aicfab_chat_ok'] = true;
$GLOBALS['aicfab_rate_ok'] = false;
$aicfab_check = $aicfab_speech->check_permission( new WP_REST_Request( array( 'post' => 0 ) ) );
check_speech( is_wp_error( $aicfab_check ) && array( 'status' => 429 ) === $aicfab_check->get_error_data(), 'The route is rate limited.' );
$GLOBALS['aicfab_rate_ok'] = true;
check_speech( true === $aicfab_speech->check_permission( new WP_REST_Request( array( 'post' => 0 ) ) ), 'A signed-in visitor may hear answers.' );

// --- Answers -----------------------------------------------------------------------

$aicfab_answer = "营业时间是**周一到周五**。\n\n周末休息。";
$aicfab_result = AI_Chat_Bedrock_Speech::reply_part( $aicfab_answer, AI_Chat_Bedrock_Speech::token( $aicfab_answer ), 0, 'en' );
check_speech( is_array( $aicfab_result ) && 'audio/mpeg' === $aicfab_result['type'] && 1 === $aicfab_result['parts'], 'A signed answer is read.' );
check_speech( is_array( $aicfab_result ) && 'MP3:Zhiyu:营业时间是周一到周五。 周末休息。' === base64_decode( $aicfab_result['audio'] ), 'It is read without markdown, in a Chinese voice, and sent back as base64.' );
check_speech( 'neural' === AI_Chat_Bedrock_AWS::$calls[0]['engine'] && 'us-east-1' === AI_Chat_Bedrock_AWS::$calls[0]['region'], 'Polly is called with the neural engine in the configured region.' );

$aicfab_calls = count( AI_Chat_Bedrock_AWS::$calls );
$aicfab_bad   = AI_Chat_Bedrock_Speech::reply_part( 'Anything I like', AI_Chat_Bedrock_Speech::token( 'Something else' ), 0 );
check_speech( is_wp_error( $aicfab_bad ) && 'aicfab_speech_token' === $aicfab_bad->get_error_code() && array( 'status' => 403 ) === $aicfab_bad->get_error_data(), 'Text the chat did not give is refused, so the route is not a free text-to-speech service.' );
$GLOBALS['aicfab_user'] = 8;
$aicfab_bad             = AI_Chat_Bedrock_Speech::reply_part( $aicfab_answer, AI_Chat_Bedrock_Speech::token( $aicfab_answer, 7 ), 0 );
check_speech( is_wp_error( $aicfab_bad ) && 'aicfab_speech_token' === $aicfab_bad->get_error_code(), 'Another visitor\'s signature does not work.' );
$GLOBALS['aicfab_user'] = 7;
$aicfab_bad             = AI_Chat_Bedrock_Speech::reply_part( $aicfab_answer, 'nope', 0 );
check_speech( is_wp_error( $aicfab_bad ) && 'aicfab_speech_token' === $aicfab_bad->get_error_code(), 'A malformed signature is refused.' );
$aicfab_huge = str_repeat( 'a', AI_Chat_Bedrock_Speech::MAX_TEXT + 1 );
$aicfab_bad  = AI_Chat_Bedrock_Speech::reply_part( $aicfab_huge, AI_Chat_Bedrock_Speech::token( $aicfab_huge ), 0 );
check_speech( is_wp_error( $aicfab_bad ) && 'aicfab_speech_token' === $aicfab_bad->get_error_code(), 'Oversized text is refused before anything else.' );
$aicfab_bad = AI_Chat_Bedrock_Speech::reply_part( $aicfab_answer, AI_Chat_Bedrock_Speech::token( $aicfab_answer ), 1 );
check_speech( is_wp_error( $aicfab_bad ) && 'aicfab_speech_part' === $aicfab_bad->get_error_code() && array( 'status' => 404 ) === $aicfab_bad->get_error_data(), 'A part past the end is not found.' );
check_speech( count( AI_Chat_Bedrock_AWS::$calls ) === $aicfab_calls, 'Refused requests never reach Polly.' );

aicfab_settings( array( 'aws_region' => 'us-east-1', 'speech_replies' => true, 'speech_daily_chars' => 10 ) );
$aicfab_bad = AI_Chat_Bedrock_Speech::reply_part( $aicfab_answer, AI_Chat_Bedrock_Speech::token( $aicfab_answer ), 0 );
check_speech( is_wp_error( $aicfab_bad ) && 'aicfab_speech_limit' === $aicfab_bad->get_error_code() && array( 'status' => 429 ) === $aicfab_bad->get_error_data() && count( AI_Chat_Bedrock_AWS::$calls ) === $aicfab_calls, 'Over the daily limit nothing is sent to Polly.' );
aicfab_settings( array( 'aws_region' => 'us-east-1', 'speech_replies' => true, 'speech_daily_chars' => 0 ) );
check_speech( is_array( AI_Chat_Bedrock_Speech::reply_part( $aicfab_answer, AI_Chat_Bedrock_Speech::token( $aicfab_answer ), 0 ) ), 'With no limit the answer is read.' );

AI_Chat_Bedrock_AWS::$fail = true;
$aicfab_bad                = AI_Chat_Bedrock_Speech::reply_part( 'Hello.', AI_Chat_Bedrock_Speech::token( 'Hello.' ), 0 );
check_speech( is_wp_error( $aicfab_bad ) && array( 'status' => 429 ) === $aicfab_bad->get_error_data(), 'Throttling by Polly is passed on as such.' );
check_speech( false === strpos( $aicfab_bad->get_error_message(), 'Rate exceeded' ) && 'The audio could not be made right now. Please try again later.' === $aicfab_bad->get_error_message(), 'A visitor is not shown what AWS said.' );
AI_Chat_Bedrock_AWS::$fail = 403;
$aicfab_bad                = AI_Chat_Bedrock_Speech::reply_part( 'Hello.', AI_Chat_Bedrock_Speech::token( 'Hello.' ), 0 );
check_speech( is_wp_error( $aicfab_bad ) && array( 'status' => 502 ) === $aicfab_bad->get_error_data() && false === strpos( $aicfab_bad->get_error_message(), '111122223333' ), 'An AWS refusal is the site\'s failure to the visitor, and does not name the account.' );
$GLOBALS['aicfab_admin'] = true;
$aicfab_bad              = AI_Chat_Bedrock_Speech::reply_part( 'Hello.', AI_Chat_Bedrock_Speech::token( 'Hello.' ), 0 );
check_speech( is_wp_error( $aicfab_bad ) && false !== strpos( $aicfab_bad->get_error_message(), 'polly:SynthesizeSpeech' ), 'An administrator sees what AWS said, to fix the permission.' );
$GLOBALS['aicfab_admin']   = false;
AI_Chat_Bedrock_AWS::$fail = false;

// The generative engine falls back to neural once, and is remembered for that voice.
aicfab_settings( array( 'aws_region' => 'us-east-1', 'speech_replies' => true, 'speech_engine' => 'generative' ) );
AI_Chat_Bedrock_AWS::$calls  = array();
AI_Chat_Bedrock_AWS::$refuse = array( 'generative' );
$aicfab_result               = AI_Chat_Bedrock_Speech::reply_part( 'Hello.', AI_Chat_Bedrock_Speech::token( 'Hello.' ), 0, 'en_US' );
check_speech( is_array( $aicfab_result ) && 2 === count( AI_Chat_Bedrock_AWS::$calls ) && 'generative' === AI_Chat_Bedrock_AWS::$calls[0]['engine'] && 'neural' === AI_Chat_Bedrock_AWS::$calls[1]['engine'], 'A voice without the generative engine is read with neural.' );
AI_Chat_Bedrock_Speech::reply_part( 'Hello.', AI_Chat_Bedrock_Speech::token( 'Hello.' ), 0, 'en_US' );
check_speech( 3 === count( AI_Chat_Bedrock_AWS::$calls ) && 'neural' === AI_Chat_Bedrock_AWS::$calls[2]['engine'], 'The fallback is remembered, so it costs one refused request.' );
AI_Chat_Bedrock_AWS::$refuse = array();
AI_Chat_Bedrock_Speech::reply_part( 'Bonjour.', AI_Chat_Bedrock_Speech::token( 'Bonjour.' ), 0, 'fr_FR' );
check_speech( 'generative' === AI_Chat_Bedrock_AWS::$calls[3]['engine'] && 'Lea' === AI_Chat_Bedrock_AWS::$calls[3]['voice'], 'Other voices still use the generative engine.' );
AI_Chat_Bedrock_Speech::settings_updated( array( 'speech_engine' => 'generative' ), array( 'speech_engine' => 'neural' ) );
check_speech( false === get_transient( AI_Chat_Bedrock_Speech::FALLBACK ), 'Changing the engine forgets the fallbacks.' );

$aicfab_response = $aicfab_speech->handle_request( new WP_REST_Request( array( 'text' => 'Hello.', 'token' => AI_Chat_Bedrock_Speech::token( 'Hello.' ), 'part' => 0, 'post' => 0, 'lang' => 'en' ) ) );
check_speech( $aicfab_response instanceof WP_REST_Response && 'no-store, private' === $aicfab_response->headers['Cache-Control'], 'Answer audio is never cached.' );

// --- Posts -------------------------------------------------------------------------

aicfab_settings( array( 'aws_region' => 'us-east-1', 'speech_posts' => true ) );
AI_Chat_Bedrock_AWS::$calls     = array();
$GLOBALS['aicfab_posts'][5]     = new WP_Post( 5, "Opening hours\n\n" . str_repeat( 'We are open on weekdays from nine. ', 60 ), array( 'language' => 'en' ) );
$GLOBALS['aicfab_posts'][6]     = new WP_Post( 6, 'Members only.', array( 'public' => false ) );
$GLOBALS['aicfab_posts'][7]     = new WP_Post( 7, 'A page.', array( 'post_type' => 'page' ) );
$GLOBALS['aicfab_posts'][8]     = new WP_Post( 8, '日本語の記事です。', array( 'language' => 'ja' ) );

$aicfab_stale = $GLOBALS['aicfab_uploads'] . '/' . AI_Chat_Bedrock_Speech::DIRECTORY;
wp_mkdir_p( $aicfab_stale );
file_put_contents( $aicfab_stale . '/6-0123456789abcdef-0.mp3', 'old' );
$aicfab_bad = AI_Chat_Bedrock_Speech::post_part( 6, 0 );
check_speech( ! is_file( $aicfab_stale . '/6-0123456789abcdef-0.mp3' ), 'Audio saved while a post was public is deleted once it is not.' );
check_speech( is_wp_error( $aicfab_bad ) && 'aicfab_speech_post' === $aicfab_bad->get_error_code() && array( 'status' => 404 ) === $aicfab_bad->get_error_data(), 'A post a guest cannot read is never read aloud.' );
$aicfab_bad = AI_Chat_Bedrock_Speech::post_part( 7, 0 );
check_speech( is_wp_error( $aicfab_bad ) && 'aicfab_speech_post' === $aicfab_bad->get_error_code(), 'A post type that is not enabled is refused.' );
check_speech( is_wp_error( AI_Chat_Bedrock_Speech::post_part( 404, 0 ) ), 'A missing post is refused.' );
check_speech( array() === AI_Chat_Bedrock_AWS::$calls, 'Refused posts never reach Polly.' );

$aicfab_first = AI_Chat_Bedrock_Speech::post_part( 5, 0 );
check_speech( is_array( $aicfab_first ) && 2 === $aicfab_first['parts'] && ! isset( $aicfab_first['audio'] ), 'A post part comes back as a saved file, with the number of parts.' );
check_speech( is_array( $aicfab_first ) && 1 === preg_match( '#^https://site\.test/wp-content/uploads/ai-chat-bedrock-speech/5-[a-f0-9]{16}-0\.mp3$#', $aicfab_first['url'] ), 'The file is in the uploads folder, over HTTPS, named by post, version and part.' );
$aicfab_files = aicfab_saved_files();
check_speech( in_array( 'index.php', $aicfab_files, true ) && 2 === count( $aicfab_files ) && 0 === count( preg_grep( '/\.tmp$/', $aicfab_files ) ), 'The folder has an index file and no temporary files are left.' );
check_speech( 'Joanna' === AI_Chat_Bedrock_AWS::$calls[0]['voice'] && 0 === strpos( AI_Chat_Bedrock_AWS::$calls[0]['text'], 'Opening hours' ), 'The post is read from its title, in its language.' );
$aicfab_again = AI_Chat_Bedrock_Speech::post_part( 5, 0 );
check_speech( $aicfab_again === $aicfab_first && 1 === count( AI_Chat_Bedrock_AWS::$calls ), 'A saved part is not made again.' );
AI_Chat_Bedrock_Speech::post_part( 5, 1 );
check_speech( 3 === count( aicfab_saved_files() ), 'Each part is saved.' );
check_speech( is_wp_error( AI_Chat_Bedrock_Speech::post_part( 5, 2 ) ), 'A part past the end is not found.' );
AI_Chat_Bedrock_Speech::post_part( 8, 0 );
check_speech( 'Kazuha' === end( AI_Chat_Bedrock_AWS::$calls )['voice'], 'A Japanese post is read in a Japanese voice.' );
$GLOBALS['aicfab_posts'][9] = new WP_Post( 9, '这是一篇中文文章，没有设置语言。' );
AI_Chat_Bedrock_Speech::post_part( 9, 0 );
check_speech( 'Zhiyu' === end( AI_Chat_Bedrock_AWS::$calls )['voice'], 'A Chinese post on an English site without a language plugin is read in Chinese.' );

$GLOBALS['aicfab_posts'][5]->text = 'Opening hours changed. ' . str_repeat( 'We now open on weekdays from ten. ', 60 );
$aicfab_late                      = AI_Chat_Bedrock_Speech::post_part( 5, 1 );
check_speech( 1 === count( preg_grep( '/^5-.*-0\.mp3$/', aicfab_saved_files() ) ) && 0 === strpos( basename( $aicfab_first['url'] ), '5-' ) && in_array( basename( $aicfab_first['url'] ), aicfab_saved_files(), true ) && is_array( $aicfab_late ), 'A later part of a changed post leaves the earlier version, so a listener part way through can finish.' );
$GLOBALS['aicfab_posts'][5]->text = 'Opening hours changed.';
$aicfab_changed                   = AI_Chat_Bedrock_Speech::post_part( 5, 0 );
$aicfab_files                     = aicfab_saved_files();
check_speech( $aicfab_changed['url'] !== $aicfab_first['url'] && 1 === $aicfab_changed['parts'], 'Changed text makes new audio.' );
check_speech( 1 === count( preg_grep( '/^5-/', $aicfab_files ) ) && in_array( basename( $aicfab_changed['url'] ), $aicfab_files, true ), 'The audio of the earlier version is deleted.' );

AI_Chat_Bedrock_Speech::forget_post( 5 );
check_speech( 0 === count( preg_grep( '/^5-/', aicfab_saved_files() ) ) && 1 === count( preg_grep( '/^8-/', aicfab_saved_files() ) ), 'Saving a post deletes only its own audio.' );

$GLOBALS['aicfab_fs'] = 'ftpext';
$aicfab_inline        = AI_Chat_Bedrock_Speech::post_part( 5, 0 );
check_speech( isset( $aicfab_inline['audio'] ) && 'MP3:Joanna:Opening hours changed.' === base64_decode( $aicfab_inline['audio'] ) && 0 === count( preg_grep( '/^5-/', aicfab_saved_files() ) ), 'Where files cannot be written directly, the audio is sent and not kept.' );
$GLOBALS['aicfab_fs'] = 'direct';

AI_Chat_Bedrock_Speech::settings_updated( array( 'speech_posts' => true ), array( 'speech_posts' => true ) );
check_speech( count( aicfab_saved_files() ) > 0, 'Saving other settings keeps the audio.' );
AI_Chat_Bedrock_Speech::settings_updated( array( 'speech_posts' => true ), array( 'speech_posts' => false ) );
check_speech( ! is_dir( $GLOBALS['aicfab_uploads'] . '/' . AI_Chat_Bedrock_Speech::DIRECTORY ), 'Switching Listen to this post off deletes the saved audio and its folder.' );

// --- The player ------------------------------------------------------------------------

$GLOBALS['aicfab_page'] = array(
	'singular' => true,
	'post'     => 5,
);
$aicfab_html            = AI_Chat_Bedrock_Speech::add_player( '<p>Body</p>' );
check_speech( 0 === strpos( $aicfab_html, '<div class="aicfab-listen" data-post="5">' ) && false !== strpos( $aicfab_html, 'aria-pressed="false"' ) && false !== strpos( $aicfab_html, 'role="status"' ) && '<p>Body</p>' === substr( $aicfab_html, -11 ), 'The player is added above the post.' );
foreach ( array(
	'in a feed'             => array( 'feed' => true ),
	'in an excerpt'         => array( 'excerpt' => true ),
	'outside the loop'      => array( 'loop' => false ),
	'in a secondary query'  => array( 'main' => false ),
	'for another post'      => array( 'queried' => 8 ),
	'on an archive'         => array( 'singular' => false ),
) as $aicfab_where => $aicfab_state ) {
	$GLOBALS['aicfab_page'] = $aicfab_state + array(
		'singular' => true,
		'post'     => 5,
	);
	check_speech( '<p>Body</p>' === AI_Chat_Bedrock_Speech::add_player( '<p>Body</p>' ), 'No player ' . $aicfab_where . '.' );
}
$GLOBALS['aicfab_page'] = array(
	'singular' => true,
	'post'     => 6,
);
check_speech( '<p>Body</p>' === AI_Chat_Bedrock_Speech::add_player( '<p>Body</p>' ), 'No player on a members-only post.' );
$GLOBALS['aicfab_page']             = array(
	'singular' => true,
	'post'     => 5,
);
AI_Chat_Bedrock_Content::$rendering = true;
check_speech( '<p>Body</p>' === AI_Chat_Bedrock_Speech::add_player( '<p>Body</p>' ), 'No player in text rendered for the model.' );
AI_Chat_Bedrock_Content::$rendering = false;

AI_Chat_Bedrock_Speech::enqueue_assets();
check_speech( ! empty( $GLOBALS['aicfab_scripts']['ai-chat-bedrock-speech']['enqueued'] ) && ! empty( $GLOBALS['aicfab_scripts']['ai-chat-bedrock-speech-style']['enqueued'] ), 'The player loads on a post that offers it.' );
check_speech( ! isset( $GLOBALS['aicfab_scripts']['ai-chat-bedrock-speech']['data']['nonce'] ) && 'https://site.test/wp-json/ai-chat-bedrock/v1/speech' === $GLOBALS['aicfab_scripts']['ai-chat-bedrock-speech']['data']['url'], 'The player carries no nonce, so a cached page keeps working.' );
$GLOBALS['aicfab_scripts'] = array();
aicfab_settings( array( 'speech_replies' => true ) );
AI_Chat_Bedrock_Speech::enqueue_assets();
check_speech( isset( $GLOBALS['aicfab_scripts']['ai-chat-bedrock-speech']['src'] ) && empty( $GLOBALS['aicfab_scripts']['ai-chat-bedrock-speech']['enqueued'] ), 'With posts off the script is only registered, for the chat to use.' );
$GLOBALS['aicfab_scripts']['ai-chat-bedrock-speech']['data'] = 'kept';
AI_Chat_Bedrock_Speech::register_assets();
check_speech( 'kept' === $GLOBALS['aicfab_scripts']['ai-chat-bedrock-speech']['data'], 'Registering again, for a chat outside wp_enqueue_scripts, changes nothing.' );
check_speech( '<p>Body</p>' === AI_Chat_Bedrock_Speech::add_player( '<p>Body</p>' ), 'With posts off there is no player.' );

if ( is_dir( $GLOBALS['aicfab_uploads'] ) ) {
	if ( is_dir( $GLOBALS['aicfab_uploads'] . '/' . AI_Chat_Bedrock_Speech::DIRECTORY ) ) {
		( new AICFAB_Test_Filesystem() )->rmdir( $GLOBALS['aicfab_uploads'] . '/' . AI_Chat_Bedrock_Speech::DIRECTORY );
	}
	rmdir( $GLOBALS['aicfab_uploads'] );
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: read-aloud checks passed\n";
