<?php
/**
 * Standalone tests for publishing kits: platform copy written by the site's model.
 *
 * Run: php tests/publish-kit.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options']    = array( 'publish_kit' => true, 'model_id' => 'jp.test-model' );
$GLOBALS['aicfab_meta']       = array();
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_can']        = array( 'edit_post:7', 'edit_post:8', 'edit_post:9' );
$GLOBALS['aicfab_rate_ok']    = true;
$GLOBALS['aicfab_answer']     = array( 'success' => true );
$GLOBALS['aicfab_asked']      = array();
$GLOBALS['aicfab_route']      = array();

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
class WP_Post {
	public $ID;
	public $post_type   = 'post';
	public $post_status = 'publish';
	public $post_title  = '';
	public $public      = true;
	public function __construct( $id, $title, $status = 'publish', $public = true ) {
		$this->ID          = $id;
		$this->post_title  = $title;
		$this->post_status = $status;
		$this->public      = $public;
	}
}
class WP_REST_Request {
	private $params;
	public function __construct( $params ) {
		$this->params = $params;
	}
	public function get_param( $name ) {
		return isset( $this->params[ $name ] ) ? $this->params[ $name ] : null;
	}
}
$GLOBALS['aicfab_posts'] = array(
	7 => new WP_Post( 7, '具身智能入门' ),
	8 => new WP_Post( 8, 'Draft', 'draft' ),
	9 => new WP_Post( 9, 'Members', 'publish', false ),
);
function get_option( $name, $fallback = false ) {
	return 'ai_chat_bedrock_settings' === $name ? $GLOBALS['aicfab_options'] : $fallback;
}
function get_post( $id ) {
	return isset( $GLOBALS['aicfab_posts'][ (int) $id ] ) ? $GLOBALS['aicfab_posts'][ (int) $id ] : null;
}
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['aicfab_meta'][ $id ][ $key ] ) ? $GLOBALS['aicfab_meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['aicfab_meta'][ $id ][ $key ] = $value;
	return true;
}
function get_permalink( $post ) {
	return 'https://example.test/?p=' . $post->ID;
}
function get_the_post_thumbnail_url( $post, $size ) {
	return 'https://example.test/cover.jpg';
}
function current_user_can( $capability, $id = 0 ) {
	return in_array( $capability . ( $id ? ':' . $id : '' ), $GLOBALS['aicfab_can'], true );
}
function get_current_user_id() {
	return 1;
}
function get_transient( $key ) {
	return isset( $GLOBALS['aicfab_transients'][ $key ] ) ? $GLOBALS['aicfab_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['aicfab_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['aicfab_transients'][ $key ] );
	return true;
}
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['aicfab_route'] = array( $namespace, $route, $args );
}
function rest_ensure_response( $data ) {
	return $data;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function __( $text, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_url( $url ) {
	return (string) $url;
}
function esc_url_raw( $url, $protocols = null ) {
	return preg_match( '#^https?://#', (string) $url ) ? (string) $url : '';
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_parse_str( $input, &$output ) {
	parse_str( (string) $input, $output );
}
function wp_nonce_url( $url, $action ) {
	return $url . '&_wpnonce=n';
}
function admin_url( $path ) {
	return 'https://example.test/wp-admin/' . $path;
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
}
function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

class AI_Chat_Bedrock_WP_MCP_Server {
	const NAMESPACE_V1 = 'ai-chat-bedrock/v1';
}
class AI_Chat_Bedrock_Abilities {
	const CATEGORY = 'ai-chat-bedrock';
}
class AI_Chat_Bedrock_Security {
	public static function string_substr( $value, $start, $length ) {
		return mb_substr( (string) $value, $start, $length );
	}
	public static function string_length( $value ) {
		return mb_strlen( (string) $value );
	}
	public static function check_rate_limit( $bucket, $limit ) {
		return $GLOBALS['aicfab_rate_ok'];
	}
}
class AI_Chat_Bedrock_Content {
	public static function public_text( $post ) {
		return $post->public ? $post->post_title . "\n\nThe public lesson text about kinematics." : '';
	}
	public static function is_public( $post ) {
		return $post->public;
	}
	public static function language( $post ) {
		return 'zh';
	}
	public static function language_name( $slug ) {
		return 'zh' === $slug ? 'Chinese' : '';
	}
}
class AI_Chat_Bedrock_AWS {
	public function __construct( $options = array() ) {
		$GLOBALS['aicfab_aws_options'] = $options;
	}
	public function handle_chat_message( $data ) {
		$GLOBALS['aicfab_asked'][] = $data['messages'][0]['content'];
		return $GLOBALS['aicfab_answer'];
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-publish-kit.php';

$failures = array();
function check_kit( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}
function kit_answer( $json, $prose = 'Here is the copy:' ) {
	$GLOBALS['aicfab_answer'] = array(
		'success' => true,
		'data'    => array( 'message' => $prose . "\n```json\n" . json_encode( $json, JSON_UNESCAPED_UNICODE ) . "\n```" ),
	);
}

// --- Settings and the prompt -------------------------------------------------------------------

check_kit( AI_Chat_Bedrock_Publish_Kit::enabled() && ! AI_Chat_Bedrock_Publish_Kit::enabled( array() ), 'Off unless turned on.' );
$prompt = AI_Chat_Bedrock_Publish_Kit::prompt( get_post( 7 ), array( 'xiaohongshu' ) )[0]['content'];
check_kit( false !== strpos( $prompt, 'The public lesson text' ) && false !== strpos( $prompt, 'Write in Chinese.' ) && false !== strpos( $prompt, 'https://example.test/?p=7' ), 'The model gets the public text, the post\'s language and its address.' );
check_kit( false !== strpos( $prompt, 'Xiaohongshu note: title up to 20' ) && false === strpos( $prompt, 'Bilibili video' ), 'Only the platforms asked for are described.' );
check_kit( false !== strpos( $prompt, 'no facts, numbers, quotes or promises it does not contain' ), 'The model is told not to add claims.' );

// --- Writing a kit -------------------------------------------------------------------------------

kit_answer(
	array(
		'xiaohongshu' => array(
			'title'    => '手算两连杆机械臂，一只杯子讲清楚坐标系与运动学的全部要点',
			'body'     => "第一段<script>alert(1)</script>\n\n第二段",
			'tags'     => array( '#机器人', '机器人', '具身智能', 7, '' ),
			'category' => '',
		),
		'bilibili'    => array(
			'title'    => 'Kinematics by hand',
			'body'     => 'What you will learn.',
			'tags'     => array_fill( 0, 14, 'tag' ),
			'category' => '科技 > 人工智能',
		),
		'tiktok'      => array( 'title' => 'Not a platform here' ),
	)
);
$kit = AI_Chat_Bedrock_Publish_Kit::generate( 7, array( 'xiaohongshu', 'bilibili', 'tiktok' ) );
$xhs = $kit['platforms']['xiaohongshu'];
check_kit( 20 === mb_strlen( $xhs['title'] ) && false === strpos( $xhs['body'], '<script>' ) && false !== strpos( $xhs['body'], "\n\n第二段" ), 'Copy is cut to the platform\'s limits, without markup, keeping paragraphs.' );
check_kit( array( '机器人', '具身智能', '7' ) === $xhs['tags'], 'Topics lose the # sign, repeats and blanks.' );
check_kit( array( 'tag' ) === $kit['platforms']['bilibili']['tags'] && '科技 > 人工智能' === $kit['platforms']['bilibili']['category'], 'Tags are unique and the category is kept.' );
check_kit( ! isset( $kit['platforms']['tiktok'] ) && 'jp.test-model' === $kit['model'], 'Only known platforms are kept, with the model that wrote them.' );
check_kit( $kit === AI_Chat_Bedrock_Publish_Kit::get( 7 ), 'The kit is kept with the post.' );
kit_answer( array( 'youtube' => array( 'title' => 'Kinematics', 'body' => 'Desc', 'tags' => array( 'robots' ), 'category' => 'Science & Technology' ) ) );
$kit = AI_Chat_Bedrock_Publish_Kit::generate( 7, array( 'youtube' ) );
check_kit( isset( $kit['platforms']['youtube'], $kit['platforms']['xiaohongshu'] ), 'Writing one platform again keeps the others.' );
kit_answer( array( 'xiaohongshu' => array( 'title' => '坐标系', 'body' => '正文', 'topics' => array( '#机器人', '运动学' ) ) ) );
check_kit( array( '机器人', '运动学' ) === AI_Chat_Bedrock_Publish_Kit::generate( 7, array( 'xiaohongshu' ) )['platforms']['xiaohongshu']['tags'], 'Topics given under that name are taken as the tags.' );

check_kit( 'aicfab_kit_post' === AI_Chat_Bedrock_Publish_Kit::generate( 8 )->get_error_code() && 'aicfab_kit_post' === AI_Chat_Bedrock_Publish_Kit::generate( 9 )->get_error_code(), 'Drafts and members-only posts get no kit.' );
$GLOBALS['aicfab_asked']  = array();
$GLOBALS['aicfab_answer'] = array(
	'success' => false,
	'data'    => array(
		'code'    => 'aicfab_daily_limit',
		'message' => 'Daily limit reached.',
	),
);
check_kit( 'aicfab_daily_limit' === AI_Chat_Bedrock_Publish_Kit::generate( 7 )->get_error_code(), 'The model\'s refusal, such as the daily limit, is passed on.' );
kit_answer( array(), 'No JSON here at all' );
$GLOBALS['aicfab_answer']['data']['message'] = 'I cannot help with that.';
check_kit( 'aicfab_kit_unreadable' === AI_Chat_Bedrock_Publish_Kit::generate( 7 )->get_error_code(), 'An answer that is not copy is refused, and nothing is kept over the old kit.' );
check_kit( isset( AI_Chat_Bedrock_Publish_Kit::get( 7 )['platforms']['youtube'] ), 'The earlier kit is still there.' );

// --- The editor's box ----------------------------------------------------------------------------

kit_answer( array( 'bilibili' => array( 'title' => '"><img src=x onerror=alert(1)>', 'body' => '</textarea><script>x</script>', 'tags' => array(), 'category' => '' ) ) );
AI_Chat_Bedrock_Publish_Kit::generate( 7, array( 'bilibili' ) );
ob_start();
AI_Chat_Bedrock_Publish_Kit::render_box_section( get_post( 7 ) );
$box = ob_get_clean();
check_kit( false === strpos( $box, '<script>' ) && false === strpos( $box, '<img src=x' ), 'What the model wrote is shown escaped.' );
check_kit( false !== strpos( $box, 'member.bilibili.com/platform/upload' ) && false !== strpos( $box, 'creator.xiaohongshu.com' ) && false !== strpos( $box, 'cover.jpg' ), 'Each platform links to its creator page, with the cover.' );
check_kit( false !== strpos( $box, 'declare AI assistance' ) && false !== strpos( $box, 'action=ai_chat_bedrock_publish_kit&post=7&_wpnonce=' ), 'The kit is labelled an AI draft, and the button carries a nonce.' );
ob_start();
AI_Chat_Bedrock_Publish_Kit::render_box_section( get_post( 8 ) );
check_kit( false === strpos( ob_get_clean(), 'Write platform copy' ), 'A draft post is not offered a kit.' );

// --- Who may ask -----------------------------------------------------------------------------------

$kits = new AI_Chat_Bedrock_Publish_Kit();
$kits->register_routes();
check_kit( '/publish-kit' === $GLOBALS['aicfab_route'][1] && 'POST' === $GLOBALS['aicfab_route'][2]['methods'], 'The route takes POST.' );
check_kit( true === $kits->check_permission( new WP_REST_Request( array( 'post' => 7 ) ) ), 'An editor of the post may ask.' );
check_kit( 403 === $kits->check_permission( new WP_REST_Request( array( 'post' => 99 ) ) )->get_error_data()['status'], 'Someone who may not edit it may not.' );
$GLOBALS['aicfab_rate_ok'] = false;
check_kit( 429 === $kits->check_permission( new WP_REST_Request( array( 'post' => 7 ) ) )->get_error_data()['status'] && ! $kits->can_generate( array( 'post_id' => 7 ) ), 'Asking too often is limited, for agents too.' );
$GLOBALS['aicfab_rate_ok'] = true;
$GLOBALS['aicfab_options'] = array();
check_kit( 404 === $kits->check_permission( new WP_REST_Request( array( 'post' => 7 ) ) )->get_error_data()['status'], 'Nothing is offered while kits are off.' );
$GLOBALS['aicfab_options'] = array( 'publish_kit' => true, 'model_id' => 'jp.test-model' );

// --- Recording an address by hand ------------------------------------------------------------------

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-distribution.php';
$cases = array(
	'https://www.bilibili.com/video/BV1xx411c7mD/?spm_id_from=333' => array( 'bilibili', 'BV1xx411c7mD' ),
	'https://youtu.be/dQw4w9WgXcQ?t=3'                              => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=x'            => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://www.youtube.com/shorts/dQw4w9WgXcQ'                    => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://www.xiaohongshu.com/explore/64a1b2c3d4e5f60718293a4b'  => array( 'xiaohongshu', '64a1b2c3d4e5f60718293a4b' ),
	'https://mp.weixin.qq.com/s/AbCdEfGhIjKlMnOpQrStUv'             => array( 'wechat', 'AbCdEfGhIjKlMnOpQrStUv' ),
	'http://mp.weixin.qq.com/s?__biz=MzI&mid=1&idx=1&sn=0a1b2c3d4e5f' => array( 'wechat', '0a1b2c3d4e5f' ),
);
foreach ( $cases as $url => $expected ) {
	$found = AI_Chat_Bedrock_Distribution::from_url( $url );
	check_kit( is_array( $found ) && $expected === array( $found['platform'], $found['item_id'] ) && 0 === strpos( $found['url'], 'https://' ), 'An address is read as its platform and item: ' . $url );
}
check_kit( null === AI_Chat_Bedrock_Distribution::from_url( 'https://evil.test/video/BV1xx411c7mD' ) && null === AI_Chat_Bedrock_Distribution::from_url( 'javascript:alert(1)' ), 'Other addresses are not taken.' );

function wp_verify_nonce( $nonce, $action ) {
	return 'good' === $nonce && 'aicfab_record_7' === $action;
}
function wp_unslash( $value ) {
	return $value;
}
function wp_is_post_revision( $id ) {
	return false;
}
function apply_filters( $hook, $value ) {
	return $value;
}
$_POST = array( 'aicfab_record_nonce' => 'good', 'aicfab_record' => array( 'url' => 'https://www.bilibili.com/video/BV1xx411c7mD/', 'status' => 'public' ) );
AI_Chat_Bedrock_Distribution::save_manual_record( 7 );
$entry = AI_Chat_Bedrock_Distribution::entries( 7 )[0];
check_kit( 'bilibili:BV1xx411c7mD' === $entry['key'] && 'public' === $entry['status'] && 'manual' === $entry['source'], 'An address entered in the box is recorded when the post is saved.' );
$_POST['aicfab_record']['url'] = 'https://example.test/not-a-platform';
AI_Chat_Bedrock_Distribution::save_manual_record( 7 );
check_kit( 1 === count( AI_Chat_Bedrock_Distribution::entries( 7 ) ) && false !== get_transient( 'aicfab_record_notice_1' ), 'An unknown address is not recorded, and the editor is told why.' );
$_POST = array( 'aicfab_record_nonce' => 'forged', 'aicfab_record' => array( 'url' => 'https://youtu.be/dQw4w9WgXcQ' ) );
AI_Chat_Bedrock_Distribution::save_manual_record( 7 );
check_kit( 1 === count( AI_Chat_Bedrock_Distribution::entries( 7 ) ), 'Without the box\'s nonce nothing is recorded.' );
$_POST = array();

$root      = dirname( __DIR__ );
$bootstrap = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock.php' );
check_kit( false !== strpos( $bootstrap, "add_action( 'save_post', 'AI_Chat_Bedrock_Distribution', 'save_manual_record' )" ) && false !== strpos( $bootstrap, "'admin_post_ai_chat_bedrock_publish_kit'" ) && false !== strpos( $bootstrap, "add_action( 'rest_api_init', \$publish_kit, 'register_routes' )" ), 'The route, the button and recording by hand are wired.' );
$mcp = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock-wp-mcp-server.php' );
check_kit( false !== strpos( $mcp, "case 'prepare_publish_kit':" ) && false !== strpos( $mcp, '$kit->can_generate( $arguments )' ), 'Agents can ask through MCP, with the same permission.' );
check_kit( false !== strpos( (string) file_get_contents( $root . '/uninstall.php' ), "'_aicfab_publish_kit'" ), 'Uninstalling removes the kits.' );

if ( $failures ) {
	echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}
echo "OK: publishing kit checks passed\n";
