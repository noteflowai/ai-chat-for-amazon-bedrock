<?php
/**
 * Standalone tests for sending posts to a WeChat Official Account's draft box.
 *
 * Run: php tests/wechat-drafts.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

const AICFAB_APP_ID = 'wx1234567890abcdef';
const AICFAB_SECRET = 'fedcba9876543210fedcba9876543210';

$GLOBALS['aicfab_options']    = array();
$GLOBALS['aicfab_store']      = array();
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_meta']       = array();
$GLOBALS['aicfab_posts']      = array();
$GLOBALS['aicfab_http']       = array();
$GLOBALS['aicfab_replies']    = array();
$GLOBALS['aicfab_queries']    = array();
$GLOBALS['aicfab_cron']       = array();
$GLOBALS['aicfab_mail']       = array();
$GLOBALS['aicfab_caps']       = array( 'publish_posts' => true, 'edit_post' => true );

// An uploads folder with real images.
$aicfab_uploads = sys_get_temp_dir() . '/aicfab-drafts-' . getmypid();
@mkdir( $aicfab_uploads . '/2026/10', 0777, true );
file_put_contents( $aicfab_uploads . '/2026/10/robot.jpg', "\xFF\xD8\xFF" . str_repeat( 'a', 2000 ) );
file_put_contents( $aicfab_uploads . '/2026/10/arm.png', "\x89PNG" . str_repeat( 'b', 3000 ) );
file_put_contents( $aicfab_uploads . '/2026/10/huge.jpg', "\xFF\xD8\xFF" . str_repeat( 'c', 1100000 ) );
file_put_contents( $aicfab_uploads . '/2026/10/huge-1024x768.jpg', "\xFF\xD8\xFF" . str_repeat( 'd', 5000 ) );
file_put_contents( $aicfab_uploads . '/2026/10/anim.gif', 'GIF89a' );
file_put_contents( $aicfab_uploads . '/2026/10/cover.jpg', "\xFF\xD8\xFF" . str_repeat( 'e', 4000 ) );

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
class WP_REST_Request_Stub {
	private $params;
	public function __construct( $params ) {
		$this->params = $params;
	}
	public function get_param( $name ) {
		return isset( $this->params[ $name ] ) ? $this->params[ $name ] : null;
	}
}
class WP_Post {
	public $ID;
	public $post_type     = 'post';
	public $post_status   = 'publish';
	public $post_password = '';
	public $post_title    = '';
	public $post_content  = '';
	public $post_excerpt  = '';
	public $language      = 'zh';
	public $thumbnail     = 0;
	public $post_modified_gmt = '2026-10-01 00:00:00';
	public function __construct( $fields ) {
		foreach ( $fields as $key => $value ) {
			$this->$key = $value;
		}
	}
}
function get_option( $name, $fallback = false ) {
	if ( 'ai_chat_bedrock_settings' === $name ) {
		return $GLOBALS['aicfab_options'];
	}
	if ( 'date_format' === $name || 'time_format' === $name ) {
		return 'date_format' === $name ? 'Y-m-d' : 'H:i';
	}
	if ( 'admin_email' === $name ) {
		return 'owner@example.test';
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
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['aicfab_meta'][ $id ][ $key ] ) ? $GLOBALS['aicfab_meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['aicfab_meta'][ $id ][ $key ] = $value;
	return true;
}
function get_post( $id = null ) {
	return isset( $GLOBALS['aicfab_posts'][ (int) $id ] ) ? $GLOBALS['aicfab_posts'][ (int) $id ] : null;
}
function get_posts( $args ) {
	if ( isset( $args['meta_key'] ) ) {
		$ids = array();
		foreach ( $GLOBALS['aicfab_meta'] as $id => $meta ) {
			if ( isset( $meta[ $args['meta_key'] ] ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
	if ( isset( $args['meta_query'][0]['key'] ) ) {
		$ids = array();
		foreach ( $GLOBALS['aicfab_meta'] as $id => $meta ) {
			if ( isset( $meta[ $args['meta_query'][0]['key'] ] ) && false !== strpos( serialize( $meta[ $args['meta_query'][0]['key'] ] ), $args['meta_query'][0]['value'] ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
	$GLOBALS['aicfab_queries'][] = $args;
	$posts                       = array_values( $GLOBALS['aicfab_posts'] );
	usort(
		$posts,
		function ( $a, $b ) {
			return $b->ID - $a->ID;
		}
	);
	return $posts;
}
function get_the_title( $post ) {
	$post = is_object( $post ) ? $post : get_post( $post );
	return $post->post_title;
}
function get_permalink( $post ) {
	$post = is_object( $post ) ? $post : get_post( $post );
	return 'https://example.test/?p=' . $post->ID;
}
function get_the_excerpt( $post ) {
	return $post->post_excerpt;
}
function get_post_thumbnail_id( $post ) {
	return $post->thumbnail;
}
function wp_get_attachment_url( $id ) {
	return 'https://example.test/wp-content/uploads/2026/10/' . ( 7 === $id ? 'cover.jpg' : 'anim.gif' );
}
function wp_get_upload_dir() {
	return array(
		'basedir' => $GLOBALS['aicfab_uploads'],
		'baseurl' => 'https://example.test/wp-content/uploads',
	);
}
function attachment_url_to_postid( $url ) {
	return false !== strpos( $url, 'huge.jpg' ) ? 9 : 0;
}
function wp_get_attachment_image_src( $id, $size ) {
	return 9 === $id && 'large' === $size ? array( 'https://example.test/wp-content/uploads/2026/10/huge-1024x768.jpg', 1024, 768, true ) : false;
}
function current_user_can( $cap, $id = 0 ) {
	return ! empty( $GLOBALS['aicfab_caps'][ $cap ] ) && ( 'edit_post' !== $cap || 99 !== $id );
}
function wp_safe_remote_post( $url, $args ) {
	$path                     = substr( strtok( $url, '?' ), strlen( 'https://api.weixin.qq.com/cgi-bin/' ) );
	$GLOBALS['aicfab_http'][] = array(
		'path' => $path,
		'url'  => $url,
		'body' => $args['body'],
		'type' => $args['headers']['Content-Type'],
	);
	if ( ! empty( $GLOBALS['aicfab_replies'][ $path ] ) ) {
		return array( 'body' => array_shift( $GLOBALS['aicfab_replies'][ $path ] ) );
	}
	// By default WeChat lists every draft the plugin sent, as last changed when it was sent.
	if ( 'draft/batchget' === $path ) {
		$items = array();
		foreach ( $GLOBALS['aicfab_meta'] as $meta ) {
			foreach ( isset( $meta['_aicfab_distribution'] ) ? $meta['_aicfab_distribution'] : array() as $entry ) {
				if ( 'wechat' === $entry['platform'] && 'planned' === $entry['status'] ) {
					$items[ $entry['item_id'] ] = array(
						'media_id'    => $entry['item_id'],
						'update_time' => $entry['updated_at'],
					);
				}
			}
		}
		return array( 'body' => json_encode( array( 'total_count' => count( $items ), 'item_count' => count( $items ), 'item' => array_values( $items ) ) ) );
	}
	$n       = count( $GLOBALS['aicfab_http'] );
	$replies = array(
		'stable_token'          => '{"access_token":"AT","expires_in":7200}',
		'media/uploadimg'       => '{"url":"http://mmbiz.qpic.cn/img/' . $n . '"}',
		'material/add_material' => '{"media_id":"COVER_MEDIA_' . $n . '","url":"http://mmbiz.qpic.cn/c"}',
		'draft/add'             => '{"media_id":"DRAFT_MEDIA_ID_' . $n . '"}',
		'draft/update'          => '{"errcode":0,"errmsg":"ok"}',
		'draft/delete'          => '{"errcode":0,"errmsg":"ok"}',
		'material/batchget_material' => '{"total_count":0,"item_count":0,"item":[]}',
	);
	return array( 'body' => $replies[ $path ] );
}
$GLOBALS['aicfab_remote'] = array();
function wp_safe_remote_get( $url, $args ) {
	$GLOBALS['aicfab_fetched'][] = $url;
	return isset( $GLOBALS['aicfab_remote'][ $url ] ) ? array( 'body' => $GLOBALS['aicfab_remote'][ $url ], 'code' => 200 ) : array( 'body' => '', 'code' => 404 );
}
function wp_tempnam( $name ) {
	return tempnam( sys_get_temp_dir(), $name );
}
function wp_delete_file( $path ) {
	$GLOBALS['aicfab_deleted'][] = $path;
	unlink( $path );
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['code'] ) ? $response['code'] : 200;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
/** Enough of wp_kses for these tests: the tags kept, and only src and alt on images. */
function wp_kses( $html, $allowed ) {
	$html = strip_tags( $html, '<' . implode( '><', array_keys( $allowed ) ) . '>' );
	return preg_replace_callback(
		'#<(\w+)(\s[^>]*)?>#',
		function ( $m ) {
			if ( 'img' !== strtolower( $m[1] ) ) {
				return '<' . $m[1] . '>';
			}
			preg_match_all( '#\s(src|alt)="([^"]*)"#', isset( $m[2] ) ? $m[2] : '', $attrs, PREG_SET_ORDER );
			$out = '<img';
			foreach ( $attrs as $attr ) {
				$out .= ' ' . $attr[1] . '="' . $attr[2] . '"';
			}
			return $out . '>';
		},
		$html
	);
}
function wp_list_pluck( $list, $field ) {
	return array_map(
		function ( $item ) use ( $field ) {
			return $item->$field;
		},
		$list
	);
}
function wp_next_scheduled( $hook ) {
	return isset( $GLOBALS['aicfab_cron'][ $hook ] ) ? $GLOBALS['aicfab_cron'][ $hook ][0] : false;
}
function wp_get_schedule( $hook ) {
	return isset( $GLOBALS['aicfab_cron'][ $hook ] ) ? $GLOBALS['aicfab_cron'][ $hook ][1] : false;
}
function wp_schedule_event( $time, $recurrence, $hook ) {
	$GLOBALS['aicfab_cron'][ $hook ] = array( $time, $recurrence );
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['aicfab_cron'][ $hook ] );
}
function wp_mail( $to, $subject, $body ) {
	$GLOBALS['aicfab_mail'][] = compact( 'to', 'subject', 'body' );
	return true;
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
}
function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function esc_url_raw( $url, $protocols = null ) {
	return (string) $url;
}
function esc_url( $url ) {
	return (string) $url;
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function do_action( $hook, ...$args ) {
	if ( 'ai_chat_bedrock_distribution_box' === $hook ) {
		AI_Chat_Bedrock_WeChat_Drafts::render_box_section( $args[0] );
	}
}
function get_current_user_id() {
	return 1;
}
function wp_nonce_url( $url, $action ) {
	return $url . '&_wpnonce=nonce';
}
function admin_url( $path ) {
	return 'https://example.test/wp-admin/' . $path;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function absint( $value ) {
	return abs( (int) $value );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function wp_verify_nonce( $nonce, $action ) {
	return 'good' === $nonce;
}
function wp_unslash( $value ) {
	return $value;
}
function wp_is_post_revision( $id ) {
	return false;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['aicfab_meta'][ $id ][ $key ] );
	return true;
}
function wp_nonce_field( $action, $name ) {
	echo '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';
}
$GLOBALS['aicfab_attachments'] = array();
function get_attached_file( $id ) {
	return isset( $GLOBALS['aicfab_attachments'][ $id ] ) ? $GLOBALS['aicfab_attachments'][ $id ][0] : false;
}
function get_post_mime_type( $id ) {
	return isset( $GLOBALS['aicfab_attachments'][ $id ] ) ? $GLOBALS['aicfab_attachments'][ $id ][1] : false;
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
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}
function number_format_i18n( $number ) {
	return (string) $number;
}
function human_time_diff( $from, $to ) {
	return max( 1, (int) round( ( $to - $from ) / 60 ) ) . ' mins';
}
function wp_salt() {
	return 'test-salt';
}

class AI_Chat_Bedrock_Content {
	public static function is_public( $post ) {
		return 'members' !== $post->post_title;
	}
	public static function render_as_guest( $post ) {
		return $post->post_content;
	}
	public static function language( $post ) {
		return $post->language;
	}
	public static function public_text( $post ) {
		return $post->post_title . "\n\n" . trim( strip_tags( $post->post_content ) );
	}
	public static function title( $post ) {
		return $post->post_title;
	}
}
class AI_Chat_Bedrock_Abilities {
	const CATEGORY = 'ai-chat-bedrock';
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat-api.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat-game.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-distribution.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat-drafts.php';

$failures = array();
function check_drafts( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}
function drafts_settings( $extra = array() ) {
	$GLOBALS['aicfab_options'] = array_merge(
		array(
			'wechat_app_id'          => AICFAB_APP_ID,
			'wechat_drafts_enabled'  => true,
			'wechat_app_secret'      => AI_Chat_Bedrock_Security::encrypt_secret( AICFAB_SECRET ),
			'wechat_drafts_category' => 4,
			'wechat_drafts_author'   => '物理AI实验室编辑部的很长很长的名字',
		),
		$extra
	);
}
function drafts_posts() {
	$GLOBALS['aicfab_posts'] = array(
		1 => new WP_Post(
			array(
				'ID'           => 1,
				'post_title'   => '具身智能入门：从感知到动作的完整路线图，以及为什么机器人需要世界模型来规划',
				'post_excerpt' => str_repeat( '摘要', 80 ),
				'thumbnail'    => 7,
				'post_content' => '<p>' . str_repeat( '具身智能，', 140 ) . '</p><p>See <a href="https://example.test/robots/">our robots</a>.</p><script>alert(1)</script><p class="x" style="color:red">Text</p><img class="wp-image-3" src="https://example.test/wp-content/uploads/2026/10/robot.jpg" alt="A robot"><img src="https://cdn.other.test/remote.jpg"><img src="https://example.test/wp-content/uploads/2026/10/huge.jpg" alt="Huge"><img src="https://example.test/wp-content/uploads/2026/10/anim.gif"><img src="https://example.test/wp-content/uploads/2026/10/../../../../etc/passwd"><p></p>',
			)
		),
		2 => new WP_Post(
			array(
				'ID'           => 2,
				'post_title'   => 'Arms',
				'post_excerpt' => 'Robot arms.',
				'post_content' => '<p>Arms.</p><img src="https://example.test/wp-content/uploads/2026/10/arm.png" alt="">',
			)
		),
		3 => new WP_Post(
			array(
				'ID'           => 3,
				'post_title'   => 'No pictures',
				'post_content' => '<p>Nothing to show.</p>',
			)
		),
		4 => new WP_Post(
			array(
				'ID'          => 4,
				'post_title'  => 'members',
				'thumbnail'   => 7,
			)
		),
	);
}
function drafts_reset() {
	$GLOBALS['aicfab_store']      = array();
	$GLOBALS['aicfab_transients'] = array();
	$GLOBALS['aicfab_meta']       = array();
	$GLOBALS['aicfab_http']       = array();
	$GLOBALS['aicfab_replies']    = array();
	$GLOBALS['aicfab_queries']    = array();
	$GLOBALS['aicfab_mail']       = array();
	drafts_posts();
}
function drafts_paths() {
	return array_column( $GLOBALS['aicfab_http'], 'path' );
}
function drafts_sent_body() {
	foreach ( array_reverse( $GLOBALS['aicfab_http'] ) as $call ) {
		if ( 'draft/add' === $call['path'] ) {
			return json_decode( $call['body'], true );
		}
	}
	return null;
}

// --- Settings -------------------------------------------------------------------------------

check_drafts( ! AI_Chat_Bedrock_WeChat_Drafts::enabled( array() ), 'Off unless turned on.' );
check_drafts( ! AI_Chat_Bedrock_WeChat_Drafts::enabled( array( 'wechat_drafts_enabled' => true, 'wechat_app_id' => AICFAB_APP_ID ) ), 'Not without the AppSecret.' );
drafts_settings();
drafts_posts();
check_drafts( AI_Chat_Bedrock_WeChat_Drafts::enabled() && AICFAB_SECRET === AI_Chat_Bedrock_WeChat_Drafts::app_secret(), 'On with the AppID and AppSecret, which is stored encrypted.' );
check_drafts( 3 === AI_Chat_Bedrock_WeChat_Drafts::count() && 8 === AI_Chat_Bedrock_WeChat_Drafts::count( array( 'wechat_drafts_count' => 40 ) ) && 'off' === AI_Chat_Bedrock_WeChat_Drafts::schedule() && 'off' === AI_Chat_Bedrock_WeChat_Drafts::schedule( array( 'wechat_drafts_schedule' => 'hourly' ) ), 'Three articles a draft by default and eight at most; no schedule unless chosen.' );
check_drafts( 16 === mb_strlen( AI_Chat_Bedrock_WeChat_Drafts::author() ), 'The author is cut to the 16 characters WeChat allows.' );

// --- A draft --------------------------------------------------------------------------------

$done = AI_Chat_Bedrock_WeChat_Drafts::create( array( 1, 2 ), null, 'manual' );
$body = drafts_sent_body();
check_drafts( is_array( $done ) && 'DRAFT_MEDIA_ID_' . count( $GLOBALS['aicfab_http'] ) === $done['media_id'] && array( 1, 2 ) === $done['posts'], 'Two posts become one draft.' );
check_drafts( 'stable_token' === drafts_paths()[0] && 'draft/add' === end( $GLOBALS['aicfab_http'] )['path'] && false !== strpos( end( $GLOBALS['aicfab_http'] )['url'], 'access_token=AT' ), 'The draft is added with the account\'s access token.' );
$first = $body['articles'][0];
check_drafts( '具身智能入门：从感知到动作的完整路线图' === $first['title'] && 120 === mb_strlen( $first['digest'] ) && 'https://example.test/?p=1' === $first['content_source_url'] && 'news' === $first['article_type'], 'A long title ends at its last break and the digest is cut to WeChat\'s limits, and Read more leads to the post.' );
check_drafts( '物理AI实验室 0.5：人形机器人热潮' === AI_Chat_Bedrock_WeChat_Drafts::title( '物理AI实验室 0.5：人形机器人热潮，把 Tesla 季度报告的一段话拆到演示、样机、量产与上岗' ) && 'Short title' === AI_Chat_Bedrock_WeChat_Drafts::title( 'Short title' ) && 32 === mb_strlen( AI_Chat_Bedrock_WeChat_Drafts::title( str_repeat( '字', 40 ) ) ), 'Titles: cut at a break, kept when short, and ended with … when there is no break.' );
$lesson = new WP_Post( array( 'ID' => 30, 'post_title' => 'L', 'post_excerpt' => '' ) );
check_drafts( '机器人认出了杯子，为什么伸手去拿，反而更难？这一集讲运动学。' === AI_Chat_Bedrock_WeChat_Drafts::digest( $lesson, '<h2>本节视频</h2><blockquote><p>▶ This lesson has a video, insert it here in the editor please, thank you very much.</p></blockquote><p>配音由 AI 合成。</p><p>机器人认出了杯子，为什么伸手去拿，反而更难？这一集讲运动学。' . str_repeat( '它管一件事：把手要去哪翻译成每个关节转多少', 5 ) . '</p>' ), 'The digest skips the video note and credit line, and ends at a sentence.' );
check_drafts( 0 === strpos( $first['thumb_media_id'], 'COVER_MEDIA_' ) && 'Arms' === $body['articles'][1]['title'], 'The featured image is the cover; a post without one has its first image.' );
check_drafts( false !== strpos( $first['content'], 'See our robots.' ) && false === strpos( $first['content'], '<a ' ) && false === strpos( $first['content'], 'alert' ) && false === strpos( $first['content'], 'color:red' ) && false === strpos( $first['content'], 'class=' ), 'Links become text, and the post\'s scripts, styles and classes go.' );
preg_match_all( '#<img src="([^"]+)"#', $first['content'], $images );
check_drafts( 2 === count( $images[1] ) && 0 === strpos( $images[1][0], 'http://mmbiz.qpic.cn/img/' ), 'Uploaded images take WeChat\'s address; remote, GIF and outside-uploads images are left out.' );
$uploads = array_values( array_filter( $GLOBALS['aicfab_http'], function ( $c ) { return 'media/uploadimg' === $c['path']; } ) );
check_drafts( false !== strpos( $uploads[1]['body'], str_repeat( 'd', 50 ) ) && false === strpos( $uploads[1]['body'], str_repeat( 'c', 50 ) ) && 0 === strpos( $uploads[0]['type'], 'multipart/form-data; boundary=' ), 'An image over 1 MB is sent in its large size, as a multipart upload.' );
check_drafts( false !== strpos( $first['content'], 'Read more' ) && false === strpos( $first['content'], '<p></p>' ), 'The text ends by pointing to Read more, without empty paragraphs.' );
$record = AI_Chat_Bedrock_Distribution::entries( 1 );
check_drafts( 'wechat' === $record[0]['platform'] && 'planned' === $record[0]['status'] && $done['media_id'] === $record[0]['item_id'] && 'wechat' === $record[0]['source'] && AI_Chat_Bedrock_WeChat_Drafts::sent( 1 ), 'The draft is noted in each post\'s publishing record.' );
check_drafts( false !== strpos( AI_Chat_Bedrock_WeChat_Drafts::status_summary(), '2 articles are in the WeChat draft box' ), 'The settings screen says what was sent.' );

$calls = count( $GLOBALS['aicfab_http'] );
$again = AI_Chat_Bedrock_WeChat_Drafts::create( array( 1 ), null, 'manual' );
check_drafts( array( 'draft/update' ) === array_slice( drafts_paths(), $calls ) && true === $again['updated'] && $done['media_id'] === $again['media_id'], 'Sending a post again replaces its article in the draft it is in, reusing the images, the cover and the token.' );
$update = json_decode( end( $GLOBALS['aicfab_http'] )['body'], true );
check_drafts( $done['media_id'] === $update['media_id'] && 0 === $update['index'] && 'https://example.test/?p=1' === $update['articles']['content_source_url'], 'The update names the draft and the article\'s place in it.' );
check_drafts( 1 === count( array_filter( AI_Chat_Bedrock_Distribution::entries( 1 ), function ( $e ) { return 'wechat' === $e['platform']; } ) ), 'And the record keeps one entry for it.' );
$GLOBALS['aicfab_replies']['draft/update'] = array( '{"errcode":40007,"errmsg":"invalid media_id"}' );
$anew = AI_Chat_Bedrock_WeChat_Drafts::create( array( 1 ), null, 'manual' );
check_drafts( false === $anew['updated'] && $done['media_id'] !== $anew['media_id'] && 'draft/add' === end( $GLOBALS['aicfab_http'] )['path'], 'A draft already published or deleted is made anew.' );
// Two drafts of one post, as when an update failed before: the older one goes.
drafts_reset();
AI_Chat_Bedrock_Distribution::record( 2, array( 'platform' => 'wechat', 'item_id' => 'OLDCOPY_A1', 'status' => 'planned', 'version' => 'idx:0' ), 'wechat' );
AI_Chat_Bedrock_Distribution::record( 2, array( 'platform' => 'wechat', 'item_id' => 'NEWCOPY_B2', 'status' => 'planned', 'version' => 'idx:0' ), 'wechat' );
AI_Chat_Bedrock_Distribution::record( 3, array( 'platform' => 'wechat', 'item_id' => 'SHARED_C3', 'status' => 'planned', 'version' => 'idx:1' ), 'wechat' );
AI_Chat_Bedrock_Distribution::record( 2, array( 'platform' => 'wechat', 'item_id' => 'SHARED_C3', 'status' => 'planned', 'version' => 'idx:0' ), 'wechat' );
AI_Chat_Bedrock_Distribution::record( 2, array( 'platform' => 'wechat', 'item_id' => 'NEWCOPY_B2', 'status' => 'planned', 'version' => 'idx:0' ), 'wechat' );
$GLOBALS['aicfab_meta'][2][ AI_Chat_Bedrock_Distribution::META ] = array_values( array_merge( array_filter( AI_Chat_Bedrock_Distribution::entries( 2 ), function ( $e ) { return 'NEWCOPY_B2' === $e['item_id']; } ), array_filter( AI_Chat_Bedrock_Distribution::entries( 2 ), function ( $e ) { return 'NEWCOPY_B2' !== $e['item_id']; } ) ) );
$sent    = AI_Chat_Bedrock_WeChat_Drafts::create( array( 2 ), null, 'manual' );
$deletes = array_values( array_filter( $GLOBALS['aicfab_http'], function ( $c ) { return 'draft/delete' === $c['path']; } ) );
$status  = array();
foreach ( AI_Chat_Bedrock_Distribution::entries( 2 ) as $e ) {
	$status[ $e['item_id'] ] = $e['status'];
}
check_drafts( true === $sent['updated'] && 'NEWCOPY_B2' === $sent['media_id'] && 1 === count( $deletes ) && false !== strpos( $deletes[0]['body'], 'OLDCOPY_A1' ), 'The older copy of a post is deleted in WeChat once it is sent again.' );
ksort( $status );
check_drafts( array( 'NEWCOPY_B2' => 'planned', 'OLDCOPY_A1' => 'removed', 'SHARED_C3' => 'planned' ) === $status, 'It is marked removed, and a draft another post shares is kept.' );
drafts_reset();
AI_Chat_Bedrock_WeChat_Drafts::create( array( 1 ), null, 'manual' );

$GLOBALS['aicfab_replies']['draft/update'] = array( '{"errcode":45003,"errmsg":"title size out of limit"}' );
$calls   = count( $GLOBALS['aicfab_http'] );
$refused = AI_Chat_Bedrock_WeChat_Drafts::create( array( 1 ), null, 'manual' );
check_drafts( is_wp_error( $refused ) && 'wx_45003' === $refused->get_error_code() && 'draft/update' === end( $GLOBALS['aicfab_http'] )['path'] && false !== strpos( AI_Chat_Bedrock_WeChat_Drafts::status_summary(), 'wx_45003' ), 'Any other refusal of the update is reported, and no second draft is made.' );

$skip = AI_Chat_Bedrock_WeChat_Drafts::create( array( 3, 4 ), null, 'manual' );
check_drafts( is_wp_error( $skip ) && 'wx_nothing' === $skip->get_error_code() && array( 3 => 'no_cover', 4 => 'not_public' ) === $skip->get_error_data()['skipped'], 'A post without any image, or not public, is skipped with the reason.' );

$long                                  = new WP_Post( array( 'ID' => 5, 'post_title' => 'Long', 'thumbnail' => 7, 'post_content' => str_repeat( '<p>' . str_repeat( '字', 300 ) . '</p>', 80 ) ) );
$GLOBALS['aicfab_posts'][5]            = $long;
$article                               = AI_Chat_Bedrock_WeChat_Drafts::article( $long, $GLOBALS['aicfab_options'] );
check_drafts( mb_strlen( $article['content'] ) <= AI_Chat_Bedrock_WeChat_Drafts::MAX_CONTENT && mb_strlen( $article['content'] ) > 18000 && false !== strpos( $article['content'], '字字</p><p style="margin:0 0 16px;line-height:1.75;font-size:16px;color:#333;">Tap' ), 'A long Chinese post is cut at a paragraph, counting characters, near WeChat\'s limit.' );
check_drafts( 0 === strpos( $article['digest'], '字字' ) && 120 === mb_strlen( $article['digest'] ), 'Without a written excerpt, the digest comes from what a guest reads.' );
$list = AI_Chat_Bedrock_WeChat_Drafts::fit( '<ul><li>' . str_repeat( '项', 25000 ) . '</li></ul>', '<p>Read more</p>' );
check_drafts( 0 === strpos( $list, '<p>项项' ) && false !== strpos( $list, '…</p><p>Read more</p>' ) && mb_strlen( $list ) <= AI_Chat_Bedrock_WeChat_Drafts::MAX_CONTENT, 'Content with no whole block that fits keeps its text instead of going empty.' );

// --- The editor's box ---------------------------------------------------------------------

check_drafts( ! AI_Chat_Bedrock_Distribution::enabled() && AI_Chat_Bedrock_Distribution::box_needed(), 'The editor shows the box for drafts even with the publishing record off.' );
ob_start();
( new AI_Chat_Bedrock_Distribution() )->render_meta_box( get_post( 1 ) );
$box = ob_get_clean();
check_drafts( false !== strpos( $box, 'WeChat Official Account (zh)</li>' ) || false !== strpos( $box, 'WeChat Official Account (zh) — planned' ), 'A draft is listed without a link, as it has no address yet.' );
check_drafts( false === strpos( $box, 'href=""' ) && false !== strpos( $box, 'action=ai_chat_bedrock_wechat_draft&post=1&_wpnonce=' ) && false !== strpos( $box, 'Send to the WeChat draft box again' ), 'The box offers to send the post again, with a nonce.' );
drafts_settings( array( 'wechat_drafts_enabled' => false ) );
check_drafts( ! AI_Chat_Bedrock_Distribution::box_needed(), 'With drafts and the record off, there is no box.' );
drafts_settings();

// --- WeChat's article format -------------------------------------------------------------------

$clean = AI_Chat_Bedrock_WeChat_Drafts::clean_html( "<h1>Title</h1>\n<ul class=\"wp-block-list\">\n<li><strong>One.</strong> first</li>\n\n<li>  </li>\n\n<li>Two</li>\n</ul>\n\n<p> </p><p>Text<br><br><br>more</p>\n<figure class=\"wp-block-image\"><img src=\"x.jpg\" alt=\"\"><figcaption> Caption </figcaption></figure>\n<pre><code>a = 1\n  b = 2</code></pre>" );
check_drafts( false === strpos( $clean, "\n<li" ) && false === strpos( $clean, '</li><li></li>' ) && false !== strpos( $clean, '<ul><li><strong>One.</strong> first</li><li>Two</li></ul>' ), 'No white space or empty items in lists, which WeChat shows as empty bullet points.' );
check_drafts( false !== strpos( $clean, '<h2>Title</h2>' ) && false === strpos( $clean, '<p></p>' ) && false !== strpos( $clean, '<p>Text<br>more</p>' ), 'Headings start at h2, and empty paragraphs and repeated breaks go.' );
check_drafts( false === strpos( $clean, 'figure' ) && false !== strpos( $clean, '<p class="aicfab-caption">Caption</p>' ) && false !== strpos( $clean, "<pre><code>a = 1\n  b = 2</code></pre>" ), 'Figures become their image and caption; code keeps its spacing.' );
$styled = AI_Chat_Bedrock_WeChat_Drafts::style( $clean );
check_drafts( false !== strpos( $styled, '<ul style="margin:0 0 16px;padding-left:24px;"><li style="' ) && false !== strpos( $styled, '<pre style="' ) && false !== strpos( $styled, '<code style="font-family:Menlo,Consolas,monospace;font-size:13px;background:none' ) && false !== strpos( $styled, '<p style="margin:-8px 0 16px' ), 'Every block is styled inline, as WeChat takes no style sheet.' );
$folded = AI_Chat_Bedrock_WeChat_Drafts::clean_html( "<details><summary>文字稿</summary>\n<h2>文字稿</h2>\n<p>正文</p></details><details><summary>出处</summary><p>链接</p></details><div>loose text</div><p>After</p><svg><title>icon</title></svg>" );
$gate = AI_Chat_Bedrock_WeChat_Drafts::clean_html( '<div><p>登录后继续阅读。</p><div>  <a class="fs_auth_btn fs_auth_google" href="https://example.test/wp-login.php?x=1"><svg></svg> 使用 Google </a>   <a href="https://example.test/wp-login.php">Log in</a><a class="wp-block-button__link" href="/x">Buy</a> <a href="https://example.test/page">a page</a></div></div>' );
check_drafts( '<p>登录后继续阅读。</p><p>a page</p>' === $gate, 'Sign-in and other buttons go whole, an ordinary link keeps its text, and runs of spaces collapse.' );
check_drafts( '<h2>文字稿</h2><p>正文</p><p><strong>出处</strong></p><p>链接</p><p>loose text</p><p>After</p>' === $folded, 'A folded section is shown open without repeating its heading, loose text becomes a paragraph, and icons go.' );
$players = AI_Chat_Bedrock_WeChat_Drafts::without_players( '<figure class="wp-block-video"><video controls src="https://cdn.test/a.mp4" poster="https://cdn.test/poster.jpg"></video><figcaption>Lesson</figcaption></figure><p>After</p><figure class="wp-block-embed is-type-video"><div><iframe src="https://player.bilibili.com/x"></iframe></div></figure>' );
check_drafts( 2 === substr_count( $players, 'Watch this lesson\'s video on the site' ) && false !== strpos( $players, '<img src="https://cdn.test/poster.jpg"' ) && false === strpos( $players, '<video' ) && false === strpos( $players, '<iframe' ) && false !== strpos( $players, '<p>After</p>' ), 'A video or embedded player becomes its poster and a note to insert the video in WeChat.' );

drafts_reset();
$png                                               = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' );
$GLOBALS['aicfab_remote']['https://cdn.test/poster.png'] = $png;
$GLOBALS['aicfab_remote']['https://cdn.test/fake.jpg']   = 'not an image at all';
$GLOBALS['aicfab_posts'][6]                        = new WP_Post( array( 'ID' => 6, 'post_title' => 'Video lesson', 'thumbnail' => 7, 'post_content' => '<figure class="wp-block-video"><video src="https://cdn.test/a.mp4" poster="https://cdn.test/poster.png"></video></figure><p>Text</p><img src="https://cdn.test/fake.jpg"><img src="http://cdn.test/poster.png">' ) );
$GLOBALS['aicfab_deleted']                         = array();
$article                                           = AI_Chat_Bedrock_WeChat_Drafts::article( $GLOBALS['aicfab_posts'][6], $GLOBALS['aicfab_options'] );
check_drafts( 1 === substr_count( $article['content'], '<img src="http://mmbiz.qpic.cn/img/' ) && false !== strpos( $article['content'], 'Watch this lesson\'s video on the site' ), 'A poster on a CDN is fetched and uploaded to WeChat; a file that is not an image, and plain http, are not.' );
check_drafts( 1 === count( $GLOBALS['aicfab_deleted'] ) && ! file_exists( $GLOBALS['aicfab_deleted'][0] ), 'The fetched file is deleted after the upload.' );
drafts_reset();

// --- Videos uploaded to WeChat, quizzes, and keeping drafts current -------------------------

check_drafts( 'wxv_3712345678901234567' === AI_Chat_Bedrock_WeChat_Drafts::clean_video( ' wxv_3712345678901234567 ' ) && '' === AI_Chat_Bedrock_WeChat_Drafts::clean_video( 'https://evil.test/"><script>' ), 'A WeChat video ID is wxv_ and letters or digits.' );
$_POST = array( 'aicfab_wechat_video_nonce' => 'good', 'aicfab_wechat_video' => 'wxv_3712345678901234567' );
AI_Chat_Bedrock_WeChat_Drafts::save_video( 6 );
check_drafts( 'wxv_3712345678901234567' === AI_Chat_Bedrock_WeChat_Drafts::video_id( 6 ), 'The video ID entered in the box is kept when the post is saved.' );
$_POST = array( 'aicfab_wechat_video_nonce' => 'forged', 'aicfab_wechat_video' => '' );
AI_Chat_Bedrock_WeChat_Drafts::save_video( 6 );
check_drafts( '' !== AI_Chat_Bedrock_WeChat_Drafts::video_id( 6 ), 'Not without the box\'s nonce.' );
$_POST = array();
$GLOBALS['aicfab_remote']['https://cdn.test/poster.png'] = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' );
$GLOBALS['aicfab_posts'][6] = new WP_Post( array( 'ID' => 6, 'post_title' => 'Video lesson', 'thumbnail' => 7, 'post_content' => '<figure class="wp-block-video"><video src="https://cdn.test/a.mp4" poster="https://cdn.test/poster.png"></video></figure><p>Text</p><video src="https://cdn.test/b.mp4"></video><ul><li><a href="https://cdn.test/quiz.html">互动小测（5 题）</a></li><li><a href="https://cdn.test/lab/">浏览器实验</a></li></ul><p><a href="https://cdn.test/quiz.html">Take the quiz</a></p><p>See <a href="/a">one</a> and <a href="/b">two</a>.</p>' ) );
$html = AI_Chat_Bedrock_WeChat_Drafts::content( $GLOBALS['aicfab_posts'][6], array( 'app_id' => AICFAB_APP_ID, 'secret' => AICFAB_SECRET, 'cache' => 'aicfab_wechat_access' ) );
check_drafts( 1 === substr_count( $html, '<iframe class="video_iframe rich_pages" data-vidtype="2" data-mpvid="wxv_3712345678901234567"' ) && false !== strpos( $html, 'vid=wxv_3712345678901234567' ) && false !== strpos( $html, 'If the video does not show' ), 'With a video ID, the first video is WeChat\'s own player, with a line for when it does not show.' );
check_drafts( 1 === substr_count( $html, 'Watch this lesson\'s video on the site' ) && false === strpos( $html, '[[aicfab' ), 'A further video still points readers to the site.' );
check_drafts( false !== strpos( $html, '互动小测（5 题）</li><li style="margin:0 0 8px;line-height:1.75;font-size:16px;color:#333;">浏览器实验</li></ul><p style="margin:0 0 16px;line-height:1.75;font-size:16px;color:#333;">(tap "Read more" at the end to open it)</p>' ), 'A list of links, such as a quiz and a lab, is followed once by where to open them.' );
check_drafts( false !== strpos( $html, 'Take the quiz (tap "Read more" at the end to open it)</p>' ) && false !== strpos( $html, 'See one and two.</p>' ), 'A link that is a whole paragraph gets the hint; links within a sentence do not.' );

drafts_reset();
drafts_settings( array( 'wechat_drafts_schedule' => 'daily', 'wechat_drafts_sync' => true, 'wechat_drafts_category' => 99 ) );
AI_Chat_Bedrock_Distribution::record( 2, array( 'platform' => 'wechat', 'item_id' => 'CURRENT_D1', 'status' => 'planned', 'version' => 'idx:0' ), 'wechat' );
AI_Chat_Bedrock_Distribution::record( 4, array( 'platform' => 'wechat', 'item_id' => 'CURRENT_D2', 'status' => 'planned', 'version' => 'idx:0' ), 'wechat' );
$GLOBALS['aicfab_posts'][2]->post_content     = '<p>' . str_repeat( '新增小测。', 200 ) . '</p><img src="https://example.test/wp-content/uploads/2026/10/arm.png" alt="">';
$GLOBALS['aicfab_posts'][2]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', time() - 60 );
foreach ( array( 2, 4 ) as $aicfab_id ) {
	$GLOBALS['aicfab_meta'][ $aicfab_id ][ AI_Chat_Bedrock_Distribution::META ][0]['updated_at'] = time() - 3600;
}
$GLOBALS['aicfab_posts'][2]->thumbnail         = 7;
AI_Chat_Bedrock_WeChat_Drafts::run();
$updates = array_values( array_filter( $GLOBALS['aicfab_http'], function ( $c ) { return 'draft/update' === $c['path']; } ) );
check_drafts( 1 === count( $updates ) && false !== strpos( $updates[0]['body'], 'CURRENT_D1' ) && false !== strpos( $updates[0]['body'], '新增小测' ), 'A scheduled run replaces the article of a draft whose post changed, as when a quiz is added.' );
$GLOBALS['aicfab_http'] = array();
AI_Chat_Bedrock_WeChat_Drafts::run();
check_drafts( array() === array_filter( $GLOBALS['aicfab_http'], function ( $c ) { return 'draft/update' === $c['path']; } ), 'An unchanged post is left alone on the next run.' );

// The post changes again, but its owner has edited the draft in WeChat since.
drafts_settings( array( 'wechat_drafts_schedule' => 'daily', 'wechat_drafts_sync' => true, 'wechat_drafts_notify' => true, 'wechat_drafts_category' => 99 ) );
$aicfab_sent = AI_Chat_Bedrock_WeChat_Drafts::draft_of( 2 );
$GLOBALS['aicfab_posts'][2]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', $aicfab_sent['updated_at'] + 600 );
$GLOBALS['aicfab_replies']['draft/batchget']   = array( '{"total_count":1,"item_count":1,"item":[{"media_id":"' . $aicfab_sent['media_id'] . '","update_time":' . ( $aicfab_sent['updated_at'] + 300 ) . '}]}' );
$GLOBALS['aicfab_http']                        = array();
$GLOBALS['aicfab_mail']                        = array();
function get_edit_post_link( $id, $context ) {
	return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit';
}
AI_Chat_Bedrock_WeChat_Drafts::run();
check_drafts( array() === array_filter( $GLOBALS['aicfab_http'], function ( $c ) { return 'draft/update' === $c['path']; } ), 'A draft edited in WeChat after it was sent is not replaced.' );
check_drafts( 1 === count( $GLOBALS['aicfab_mail'] ) && false !== strpos( $GLOBALS['aicfab_mail'][0]['body'], 'post.php?post=2' ) && false !== strpos( $GLOBALS['aicfab_mail'][0]['body'], 'were not replaced' ), 'The site is emailed which posts changed while their drafts hold edits.' );
$GLOBALS['aicfab_replies']['draft/batchget'] = array( '{"total_count":1,"item_count":1,"item":[{"media_id":"' . $aicfab_sent['media_id'] . '","update_time":' . ( $aicfab_sent['updated_at'] + 300 ) . '}]}' );
AI_Chat_Bedrock_WeChat_Drafts::run();
check_drafts( 1 === count( $GLOBALS['aicfab_mail'] ), 'Once for each change of the post.' );
$GLOBALS['aicfab_replies']['draft/batchget'] = array( '{"errcode":-1,"errmsg":"system error"}' );
$aicfab_cache = new ReflectionProperty( 'AI_Chat_Bedrock_WeChat_Drafts', 'draft_times' );
if ( PHP_VERSION_ID < 80100 ) {
	$aicfab_cache->setAccessible( true );
}
$aicfab_cache->setValue( null, null );
check_drafts( 'unknown' === AI_Chat_Bedrock_WeChat_Drafts::draft_state( $aicfab_sent, $GLOBALS['aicfab_options'] ) && true === AI_Chat_Bedrock_WeChat_Drafts::edited_in_wechat( $aicfab_sent, $GLOBALS['aicfab_options'] ), 'When WeChat cannot say, nothing is replaced on a guess.' );
$aicfab_cache->setValue( null, array( $aicfab_sent['media_id'] => $aicfab_sent['updated_at'] + 30 ) );
check_drafts( false === AI_Chat_Bedrock_WeChat_Drafts::edited_in_wechat( $aicfab_sent, $GLOBALS['aicfab_options'] ), 'The update the plugin itself made is not taken for an edit.' );

// WeChat unreachable on a run: nothing replaced, nobody told of edits that may not exist.
$GLOBALS['aicfab_posts'][2]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', $aicfab_sent['updated_at'] + 900 );
$GLOBALS['aicfab_replies']['draft/batchget']   = array( '{"errcode":40164,"errmsg":"invalid ip 198.51.100.9, not in whitelist"}' );
$GLOBALS['aicfab_http']                        = array();
$GLOBALS['aicfab_mail']                        = array();
AI_Chat_Bedrock_WeChat_Drafts::run();
check_drafts( array() === array_filter( $GLOBALS['aicfab_http'], function ( $c ) { return 'draft/update' === $c['path'] || 'draft/add' === $c['path']; } ) && array() === $GLOBALS['aicfab_mail'] && false !== strpos( AI_Chat_Bedrock_WeChat_Drafts::status_summary(), '198.51.100.9' ), 'When WeChat cannot be asked, nothing is replaced, no edit is claimed, and the reason is shown.' );

// The draft was published (or deleted) in WeChat: it is not made again when the post changes.
$GLOBALS['aicfab_replies']['draft/batchget'] = array( '{"total_count":0,"item_count":0,"item":[]}' );
$GLOBALS['aicfab_http']                      = array();
AI_Chat_Bedrock_WeChat_Drafts::run();
$aicfab_after = AI_Chat_Bedrock_Distribution::entries( 2 );
check_drafts( array() === array_filter( $GLOBALS['aicfab_http'], function ( $c ) { return 'draft/update' === $c['path'] || 'draft/add' === $c['path']; } ) && 'submitted' === $aicfab_after[0]['status'] && null === AI_Chat_Bedrock_WeChat_Drafts::draft_of( 2 ) && AI_Chat_Bedrock_WeChat_Drafts::sent( 2 ), 'A draft no longer in the draft box, published or deleted, is not made again; its record says so.' );
drafts_settings();
drafts_reset();

// --- When WeChat refuses --------------------------------------------------------------------

drafts_reset();
$GLOBALS['aicfab_replies']['stable_token'] = array( '{"errcode":40164,"errmsg":"invalid ip 198.51.100.7 ipv6 ::ffff:198.51.100.7, not in whitelist rid: 9"}' );
$refused                                   = AI_Chat_Bedrock_WeChat_Drafts::create( array( 2 ), null, 'manual' );
check_drafts( is_wp_error( $refused ) && false !== strpos( AI_Chat_Bedrock_WeChat_Drafts::status_summary(), 'Add it to the IP whitelist' ) && false !== strpos( AI_Chat_Bedrock_WeChat_Drafts::status_summary(), '198.51.100.7' ), 'An address WeChat refuses is named, with where to add it.' );
check_drafts( ! AI_Chat_Bedrock_WeChat_Drafts::sent( 2 ), 'Nothing is recorded when the draft fails.' );
check_drafts( false !== strpos( AI_Chat_Bedrock_WeChat_Drafts::describe( new WP_Error( 'wx_48001' ) ), 'may not use' ) && false !== strpos( AI_Chat_Bedrock_WeChat_Drafts::describe( new WP_Error( 'wx_40125' ) ), 'AppSecret' ), 'Other refusals say what to do.' );

// --- Featured posts and the schedule --------------------------------------------------------

drafts_reset();
AI_Chat_Bedrock_Distribution::record( 2, array( 'platform' => 'wechat', 'item_id' => 'OLDDRAFT123', 'status' => 'public' ), 'wechat' );
$GLOBALS['aicfab_posts'][5] = $long;
$ids                        = AI_Chat_Bedrock_WeChat_Drafts::candidates();
check_drafts( array( 5, 1 ) === $ids, 'Candidates are the newest public posts with a cover and enough text, not sent before.' );
$GLOBALS['aicfab_posts'][3]->post_content = '<p>' . str_repeat( '字', 900 ) . '</p>';
check_drafts( 'no_cover' === AI_Chat_Bedrock_WeChat_Drafts::shortfall( $GLOBALS['aicfab_posts'][3] ) && 'too_short' === AI_Chat_Bedrock_WeChat_Drafts::shortfall( new WP_Post( array( 'ID' => 31, 'post_title' => 'Brief', 'thumbnail' => 7, 'post_content' => '<p>Two lines.</p>' ) ) ) && '' === AI_Chat_Bedrock_WeChat_Drafts::shortfall( $long ), 'The schedule leaves out posts without a cover or with little text.' );
check_drafts( 4 === $GLOBALS['aicfab_queries'][0]['cat'] && isset( $GLOBALS['aicfab_queries'][0]['date_query'] ) && 'publish' === $GLOBALS['aicfab_queries'][0]['post_status'], 'Only the featured category, recent and published.' );

AI_Chat_Bedrock_WeChat_Drafts::run();
check_drafts( array() === $GLOBALS['aicfab_http'], 'Nothing runs while the schedule is off.' );
drafts_settings( array( 'wechat_drafts_schedule' => 'daily', 'wechat_drafts_notify' => true, 'wechat_drafts_count' => 2 ) );
AI_Chat_Bedrock_WeChat_Drafts::run();
$aicfab_adds = array_values( array_filter( $GLOBALS['aicfab_http'], function ( $c ) { return 'draft/add' === $c['path']; } ) );
$aicfab_firsts = array_map( function ( $c ) { return json_decode( $c['body'], true )['articles']; }, $aicfab_adds );
check_drafts( 2 === count( $aicfab_adds ) && 1 === count( $aicfab_firsts[0] ) && 1 === count( $aicfab_firsts[1] ) && 'Long' === $aicfab_firsts[1][0]['title'], 'A scheduled run sends the newest featured posts, as many as set, each as a draft of its own, the newest last so it lists first.' );
check_drafts( 1 === count( $GLOBALS['aicfab_mail'] ) && 'owner@example.test' === $GLOBALS['aicfab_mail'][0]['to'] && false !== strpos( $GLOBALS['aicfab_mail'][0]['body'], '· Long' ) && false !== strpos( $GLOBALS['aicfab_mail'][0]['body'], 'mp.weixin.qq.com' ), 'The site is emailed to check and publish them.' );
$GLOBALS['aicfab_posts'] = array_intersect_key( $GLOBALS['aicfab_posts'], array( 2 => 1, 4 => 1 ) );
$calls                   = count( $GLOBALS['aicfab_http'] );
AI_Chat_Bedrock_WeChat_Drafts::run();
check_drafts( count( $GLOBALS['aicfab_http'] ) === $calls && ! empty( get_option( AI_Chat_Bedrock_WeChat_Drafts::STATUS_OPTION )['nothing'] ) && 1 === count( $GLOBALS['aicfab_mail'] ), 'With nothing new, nothing is sent and nobody is emailed.' );

drafts_settings( array( 'wechat_drafts_schedule' => 'weekly' ) );
AI_Chat_Bedrock_WeChat_Drafts::sync_schedule();
$cron = $GLOBALS['aicfab_cron'][ AI_Chat_Bedrock_WeChat_Drafts::CRON ];
check_drafts( 'weekly' === $cron[1] && $cron[0] > time() && $cron[0] - time() <= DAY_IN_SECONDS, 'The schedule is set, starting at the next 9:00.' );
drafts_settings( array( 'wechat_drafts_schedule' => 'daily' ) );
AI_Chat_Bedrock_WeChat_Drafts::sync_schedule();
check_drafts( 'daily' === $GLOBALS['aicfab_cron'][ AI_Chat_Bedrock_WeChat_Drafts::CRON ][1], 'A changed schedule replaces the old one.' );
drafts_settings( array( 'wechat_drafts_schedule' => 'daily', 'wechat_drafts_enabled' => false ) );
AI_Chat_Bedrock_WeChat_Drafts::sync_schedule();
check_drafts( ! isset( $GLOBALS['aicfab_cron'][ AI_Chat_Bedrock_WeChat_Drafts::CRON ] ), 'Turning drafts off removes the schedule.' );
drafts_settings();

// --- The schedule, as the settings screen shows it ----------------------------------------------

function wp_date( $format, $timestamp = null ) {
	return gmdate( 'Y-m-d H:i', null === $timestamp ? time() : $timestamp );
}
$GLOBALS['aicfab_cron'][ AI_Chat_Bedrock_WeChat_Drafts::CRON ] = array( time() + 3600, 'daily' );
check_drafts( 0 === strpos( AI_Chat_Bedrock_WeChat_Drafts::schedule_summary(), 'Next scheduled run: ' ) && false === strpos( AI_Chat_Bedrock_WeChat_Drafts::schedule_summary(), 'overdue' ), 'The settings screen says when the next run is due.' );
$GLOBALS['aicfab_cron'][ AI_Chat_Bedrock_WeChat_Drafts::CRON ] = array( time() - 7200, 'daily' );
check_drafts( false !== strpos( AI_Chat_Bedrock_WeChat_Drafts::schedule_summary(), 'overdue' ), 'And that WordPress\'s scheduler is not running when the run is long overdue.' );
unset( $GLOBALS['aicfab_cron'][ AI_Chat_Bedrock_WeChat_Drafts::CRON ] );
check_drafts( '' === AI_Chat_Bedrock_WeChat_Drafts::schedule_summary(), 'Nothing while there is no schedule.' );

// --- Agents ---------------------------------------------------------------------------------

$drafts = new AI_Chat_Bedrock_WeChat_Drafts();
check_drafts( $drafts->can_send( array( 'post_ids' => array( 1, 2 ) ) ) && ! $drafts->can_send( array( 'post_ids' => array( 1, 99 ) ) ) && ! $drafts->can_send( array( 'post_ids' => range( 1, 9 ) ) ) && ! $drafts->can_send( array() ), 'An agent needs to be able to edit every post, and may send up to eight.' );
$GLOBALS['aicfab_caps']['publish_posts'] = false;
check_drafts( ! $drafts->can_send( array( 'post_ids' => array( 1 ) ) ), 'And to publish posts.' );
$GLOBALS['aicfab_caps']['publish_posts'] = true;
$schema                                  = AI_Chat_Bedrock_WeChat_Drafts::schema();
check_drafts( 8 === $schema['properties']['post_ids']['maxItems'] && array( 'post_ids' ) === $schema['required'], 'The ability takes up to eight post IDs.' );

$root = dirname( __DIR__ );
$mcp  = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock-wp-mcp-server.php' );
check_drafts( false !== strpos( $mcp, "case 'create_wechat_draft':" ) && false !== strpos( $mcp, '$drafts->can_send( $arguments )' ), 'The MCP server offers it, with the same permission check.' );
$bootstrap = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock.php' );
check_drafts( false !== strpos( $bootstrap, "add_action( 'ai_chat_bedrock_wechat_drafts', 'AI_Chat_Bedrock_WeChat_Drafts', 'run' )" ) && false !== strpos( $bootstrap, "add_action( 'init', 'AI_Chat_Bedrock_WeChat_Drafts', 'sync_schedule' )" ) && false !== strpos( $bootstrap, "'admin_post_ai_chat_bedrock_wechat_draft'" ), 'The schedule, the run and the editor\'s button are wired.' );
check_drafts( false !== strpos( (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock-transfer.php' ), "'wechat_app_secret'" ), 'Exports leave out the AppSecret.' );
$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
check_drafts( false !== strpos( $uninstall, "'ai_chat_bedrock_wechat_drafts'," ) && false !== strpos( $uninstall, "'_aicfab_wechat_images', '_aicfab_wechat_cover'" ) && false !== strpos( $uninstall, "'aicfab_wechat_access'" ) && false !== strpos( (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock-deactivator.php' ), "'ai_chat_bedrock_wechat_drafts'" ), 'Deactivating and uninstalling remove the schedule and the token.' );

array_map( 'unlink', glob( $aicfab_uploads . '/2026/10/*' ) );
@rmdir( $aicfab_uploads . '/2026/10' );
@rmdir( $aicfab_uploads . '/2026' );
@rmdir( $aicfab_uploads );

if ( $failures ) {
	echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}
echo "OK: WeChat draft checks passed\n";
