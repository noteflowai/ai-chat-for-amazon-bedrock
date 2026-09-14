<?php
/**
 * Standalone tests for the media and excerpt helpers.
 *
 * Run: php tests/media-assistant.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AI_CHAT_BEDROCK_VERSION', 'test' );

$GLOBALS['aicfab_options']  = array( 'ai_chat_bedrock_settings' => array( 'media_assistant' => true ) );
$GLOBALS['aicfab_meta']     = array();
$GLOBALS['aicfab_posts']    = array();
$GLOBALS['aicfab_caps']     = array( 'upload_files' => true, 'edit_posts' => true, 'edit_post' => true );
$GLOBALS['aicfab_payloads'] = array();
$GLOBALS['aicfab_reply']    = 'A tabby cat asleep on a grey sofa.';
$GLOBALS['aicfab_updates']  = array();

// --- WordPress stubs -------------------------------------------------------

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $key ] : $default;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function __( $text, $domain = null ) {
	return $text;
}
function current_user_can( $cap ) {
	return ! empty( $GLOBALS['aicfab_caps'][ $cap ] );
}
function absint( $value ) {
	return abs( (int) $value );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) );
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}
function strip_shortcodes( $value ) {
	return (string) $value;
}
function get_post_type( $id ) {
	return isset( $GLOBALS['aicfab_posts'][ $id ] ) ? $GLOBALS['aicfab_posts'][ $id ]['type'] : '';
}
function get_post_mime_type( $post ) {
	$id = is_object( $post ) ? $post->ID : $post;
	return isset( $GLOBALS['aicfab_posts'][ $id ]['mime'] ) ? $GLOBALS['aicfab_posts'][ $id ]['mime'] : '';
}
function get_the_title( $id ) {
	return isset( $GLOBALS['aicfab_posts'][ $id ]['title'] ) ? $GLOBALS['aicfab_posts'][ $id ]['title'] : '';
}
function get_attached_file( $id ) {
	return isset( $GLOBALS['aicfab_posts'][ $id ]['file'] ) ? $GLOBALS['aicfab_posts'][ $id ]['file'] : '';
}
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['aicfab_meta'][ $id ][ $key ] ) ? $GLOBALS['aicfab_meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['aicfab_meta'][ $id ][ $key ] = $value;
	return true;
}
function get_post( $id ) {
	if ( ! isset( $GLOBALS['aicfab_posts'][ $id ] ) || 'post' !== $GLOBALS['aicfab_posts'][ $id ]['type'] ) {
		return null;
	}
	$post               = new WP_Post();
	$post->ID           = $id;
	$post->post_content = $GLOBALS['aicfab_posts'][ $id ]['content'];
	return $post;
}
function wp_update_post( $data, $wp_error = false ) {
	$GLOBALS['aicfab_updates'][] = $data;
	return $data['ID'];
}
function rest_ensure_response( $value ) {
	return $value;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function register_rest_route( $ns, $route, $args = array() ) {
	$GLOBALS['aicfab_routes'][] = $ns . $route;
	return true;
}
function wp_get_image_editor( $path ) {
	return new WP_Error( 'no_editor', 'no editor in tests' );
}
function wp_tempnam( $prefix = '' ) {
	return tempnam( sys_get_temp_dir(), $prefix );
}
function wp_delete_file( $path ) {
	if ( file_exists( $path ) ) {
		unlink( $path );
	}
}

class WP_Post {
	public $ID           = 0;
	public $post_content = '';
	public $post_excerpt = '';
}

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
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

class AI_Chat_Bedrock_Security {
	public static function check_rate_limit( $bucket, $limit, $window = 60 ) {
		return true;
	}
	public static function string_substr( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length ) : substr( (string) $value, $start, $length );
	}
}

class AI_Chat_Bedrock_WP_MCP_Server {
	const NAMESPACE_V1 = 'ai-chat-bedrock/v1';
}

class AI_Chat_Bedrock_AWS {
	public function __construct( $overrides = array() ) {}
	public function handle_chat_message( $data ) {
		$GLOBALS['aicfab_payloads'][] = $data;
		return array(
			'success' => true,
			'data'    => array( 'message' => $GLOBALS['aicfab_reply'] ),
			'usage'   => array( 'input_tokens' => 12, 'output_tokens' => 9 ),
		);
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-media-assistant.php';

function assert_true( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $label . "\n" );
		exit( 1 );
	}
}

$media = new AI_Chat_Bedrock_Media_Assistant();

// --- Alt text --------------------------------------------------------------

$image_path = tempnam( sys_get_temp_dir(), 'aicfab-img' );
file_put_contents( $image_path, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==' ) );

$GLOBALS['aicfab_posts'][10] = array( 'type' => 'attachment', 'mime' => 'image/png', 'title' => 'cat-on-sofa', 'file' => $image_path );
$GLOBALS['aicfab_posts'][11] = array( 'type' => 'attachment', 'mime' => 'application/pdf', 'title' => 'brochure', 'file' => $image_path );
$GLOBALS['aicfab_posts'][12] = array( 'type' => 'post', 'content' => 'Our refund window is 21 days from delivery. Contact support with the order number to start a return.' );

$result = $media->generate_alt_text( 10 );
assert_true( is_array( $result ) && true === $result['saved'], 'alt text is generated and saved' );
assert_true( 'A tabby cat asleep on a grey sofa.' === $result['alt'], 'alt text is returned verbatim when already clean' );
assert_true( 'A tabby cat asleep on a grey sofa.' === get_post_meta( 10, '_wp_attachment_image_alt', true ), 'alt text is stored in the standard meta key' );

$payload = $GLOBALS['aicfab_payloads'][0];
assert_true( isset( $payload['image']['data'], $payload['image']['media_type'] ), 'the image is passed to the model' );
assert_true( 'image/png' === $payload['image']['media_type'], 'the original mime type is used when no editor is available' );
assert_true( base64_decode( $payload['image']['data'], true ) === file_get_contents( $image_path ), 'the image bytes are base64 encoded' );

// Existing alt text is never replaced silently.
$before = count( $GLOBALS['aicfab_payloads'] );
$result = $media->generate_alt_text( 10 );
assert_true( true === $result['skipped'] && false === $result['saved'], 'existing alt text is preserved' );
assert_true( count( $GLOBALS['aicfab_payloads'] ) === $before, 'no model call is made when alt text already exists' );

// Overwrite is opt-in.
$GLOBALS['aicfab_reply'] = 'Picture of a dog running on wet sand';
$result                  = $media->generate_alt_text( 10, true );
assert_true( true === $result['saved'], 'overwrite regenerates alt text' );
assert_true( 'A dog running on wet sand' === $result['alt'], 'redundant lead-ins are stripped: ' . $result['alt'] );

// Non-images and missing attachments are rejected.
assert_true( 'aicfab_unsupported_image' === $media->generate_alt_text( 11 )->get_error_code(), 'PDF attachments are rejected' );
assert_true( 'aicfab_invalid_attachment' === $media->generate_alt_text( 999 )->get_error_code(), 'missing attachments are rejected' );
assert_true( 'aicfab_invalid_attachment' === $media->generate_alt_text( 12 )->get_error_code(), 'posts are not treated as attachments' );

// Capability enforcement.
$GLOBALS['aicfab_caps']['edit_post'] = false;
assert_true( 'aicfab_forbidden' === $media->generate_alt_text( 10 )->get_error_code(), 'editing capability is required' );
$GLOBALS['aicfab_caps']['edit_post'] = true;

// Model output is bounded.
$GLOBALS['aicfab_reply'] = str_repeat( 'very long description ', 40 );
$result                  = $media->generate_alt_text( 10, true );
assert_true( strlen( $result['alt'] ) <= AI_Chat_Bedrock_Media_Assistant::ALT_MAX_CHARS, 'alt text length is capped' );

// --- Excerpt ---------------------------------------------------------------

$GLOBALS['aicfab_reply'] = 'Refunds are available within 21 days of delivery.';

$response = $media->handle_excerpt( new WP_REST_Request( array( 'post' => 12, 'save' => false ) ) );
assert_true( is_array( $response ) && false === $response['saved'], 'excerpts are not saved unless requested' );
assert_true( 'Refunds are available within 21 days of delivery.' === $response['excerpt'], 'the excerpt is returned' );
assert_true( empty( $GLOBALS['aicfab_updates'] ), 'no post is written when save is false' );

$response = $media->handle_excerpt( new WP_REST_Request( array( 'post' => 12, 'save' => true ) ) );
assert_true( true === $response['saved'], 'the excerpt is saved when requested' );
assert_true( 1 === count( $GLOBALS['aicfab_updates'] ), 'exactly one update is performed' );
assert_true( 12 === $GLOBALS['aicfab_updates'][0]['ID'], 'the update targets the requested post' );
assert_true( ! isset( $GLOBALS['aicfab_updates'][0]['post_status'] ), 'the excerpt update never changes post status' );
assert_true( ! isset( $GLOBALS['aicfab_updates'][0]['post_content'] ), 'the excerpt update never touches content' );

$GLOBALS['aicfab_caps']['edit_post'] = false;
assert_true( 'aicfab_forbidden' === $media->handle_excerpt( new WP_REST_Request( array( 'post' => 12, 'save' => true ) ) )->get_error_code(), 'excerpt generation requires edit rights' );
$GLOBALS['aicfab_caps']['edit_post'] = true;

assert_true( 'aicfab_invalid_post' === $media->handle_excerpt( new WP_REST_Request( array( 'post' => 999 ) ) )->get_error_code(), 'missing posts are rejected' );

// --- Feature flag ----------------------------------------------------------

assert_true( true === $media->check_permission(), 'permission passes when enabled' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array();
assert_true( false === AI_Chat_Bedrock_Media_Assistant::enabled(), 'the helpers are off unless enabled' );
assert_true( 'aicfab_media_disabled' === $media->check_permission()->get_error_code(), 'requests are refused while disabled' );

wp_delete_file( $image_path );

echo "OK: media assistant checks passed\n";
