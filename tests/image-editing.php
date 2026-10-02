<?php
/**
 * Standalone tests for image generation and the Media Library image edits.
 *
 * Run: php tests/image-editing.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['aicfab_options'] = array(
	'ai_chat_bedrock_settings' => array(
		'media_assistant' => true,
		'image_model_id'  => 'stability.sd3-5-large-v1:0',
	),
);
$GLOBALS['aicfab_posts']     = array();
$GLOBALS['aicfab_meta']      = array();
$GLOBALS['aicfab_calls']     = array();
$GLOBALS['aicfab_replies']   = array();
$GLOBALS['aicfab_guardrail'] = true;
$GLOBALS['aicfab_inserted']  = array();
$GLOBALS['aicfab_size']      = array(
	'width'  => 800,
	'height' => 600,
);
$GLOBALS['aicfab_resized']   = null;
$GLOBALS['aicfab_filters']   = array();

// --- WordPress stubs -------------------------------------------------------

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $key ] : $default;
}
function apply_filters( $hook, $value ) {
	return isset( $GLOBALS['aicfab_filters'][ $hook ] ) ? $GLOBALS['aicfab_filters'][ $hook ] : $value;
}
function __( $text, $domain = null ) {
	return $text;
}
function absint( $value ) {
	return abs( (int) $value );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
}
function sanitize_file_name( $value ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $value );
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function get_post_mime_type( $id ) {
	return isset( $GLOBALS['aicfab_posts'][ $id ]['mime'] ) ? $GLOBALS['aicfab_posts'][ $id ]['mime'] : false;
}
function get_attached_file( $id ) {
	return isset( $GLOBALS['aicfab_posts'][ $id ]['file'] ) ? $GLOBALS['aicfab_posts'][ $id ]['file'] : false;
}
function get_the_title( $id ) {
	return isset( $GLOBALS['aicfab_posts'][ $id ]['title'] ) ? $GLOBALS['aicfab_posts'][ $id ]['title'] : '';
}
function wp_get_post_parent_id( $id ) {
	return isset( $GLOBALS['aicfab_posts'][ $id ]['parent'] ) ? $GLOBALS['aicfab_posts'][ $id ]['parent'] : 0;
}
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['aicfab_meta'][ $id ][ $key ] ) ? $GLOBALS['aicfab_meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['aicfab_meta'][ $id ][ $key ] = $value;
	return true;
}
function wp_tempnam( $prefix = '' ) {
	return tempnam( sys_get_temp_dir(), $prefix );
}
function wp_delete_file( $path ) {
	if ( file_exists( $path ) ) {
		unlink( $path );
	}
}
function wp_get_image_editor( $path ) {
	return new AICFAB_Test_Editor( $path );
}
function wp_upload_bits( $name, $deprecated, $bytes ) {
	$file = sys_get_temp_dir() . '/aicfab-upload-' . $name;
	file_put_contents( $file, $bytes );
	return array(
		'file'  => $file,
		'error' => false,
	);
}
function wp_insert_attachment( $data, $file, $parent = 0, $wp_error = false ) {
	$id                          = 100 + count( $GLOBALS['aicfab_inserted'] );
	$GLOBALS['aicfab_inserted'][ $id ] = array(
		'data'   => $data,
		'file'   => $file,
		'parent' => $parent,
	);
	return $id;
}
function wp_generate_attachment_metadata( $id, $file ) {
	return array( 'file' => basename( $file ) );
}
function wp_update_attachment_metadata( $id, $meta ) {
	return true;
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

class AICFAB_Test_Editor {
	private $path;
	public function __construct( $path ) {
		$this->path = $path;
	}
	public function get_size() {
		return $GLOBALS['aicfab_size'];
	}
	public function resize( $width, $height, $crop = false ) {
		$GLOBALS['aicfab_resized'] = array( $width, $height );
		return true;
	}
	public function save( $file, $mime ) {
		file_put_contents( $file, 'PNGBYTES' );
		return array(
			'path'      => $file,
			'mime-type' => $mime,
		);
	}
}

class AI_Chat_Bedrock_Security {
	public static function string_length( $value ) {
		return strlen( (string) $value );
	}
}

class AI_Chat_Bedrock_AWS {
	private $overrides;
	public function __construct( $overrides = array() ) {
		$this->overrides = $overrides;
	}
	public function apply_guardrail( $text, $source = 'INPUT' ) {
		$GLOBALS['aicfab_calls'][] = array( 'guardrail', $text, $this->overrides );
		return $GLOBALS['aicfab_guardrail'];
	}
	public function invoke_image( $payload, $model_id ) {
		$GLOBALS['aicfab_calls'][] = array( 'image', $payload, $model_id, $this->overrides );
		return array_shift( $GLOBALS['aicfab_replies'] );
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-images.php';

$failures = array();
function check_images( $condition, $label ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $label;
	}
}
function aicfab_reply( $reason = null ) {
	return array(
		'images'         => array( base64_encode( 'EDITED' ) ),
		'finish_reasons' => array( $reason ),
		'seeds'          => array( 42 ),
	);
}

$source = tempnam( sys_get_temp_dir(), 'aicfab-src' );
file_put_contents( $source, 'ORIGINAL' );
$GLOBALS['aicfab_posts'][7] = array(
	'mime'   => 'image/jpeg',
	'file'   => $source,
	'title'  => 'Harbour',
	'parent' => 3,
);
$GLOBALS['aicfab_meta'][7]['_wp_attachment_image_alt'] = 'Boats in a harbour';
$GLOBALS['aicfab_posts'][8]                            = array(
	'mime' => 'image/gif',
	'file' => $source,
);

// --- Switches --------------------------------------------------------------

check_images( AI_Chat_Bedrock_Images::enabled() && AI_Chat_Bedrock_Images::editing_enabled(), 'Choosing an image model with the media assistant on turns editing on.' );
check_images( ! AI_Chat_Bedrock_Images::editing_enabled( array( 'image_model_id' => 'stability.sd3-5-large-v1:0' ) ), 'Editing needs the media assistant too.' );
check_images( ! AI_Chat_Bedrock_Images::editing_enabled( array( 'media_assistant' => true ) ), 'Editing needs an image model: choosing one agrees to send images to Stability.' );
check_images( ! AI_Chat_Bedrock_Images::enabled( array( 'image_model_id' => 'stability.unknown' ) ), 'An unknown image model leaves image generation off.' );
check_images( 'us.stability.stable-fast-upscale-v1:0' === AI_Chat_Bedrock_Images::profile( AI_Chat_Bedrock_Images::UPSCALE_MODEL ), 'The editing models go through the US profile by default.' );
$GLOBALS['aicfab_filters']['ai_chat_bedrock_image_region'] = 'ap-northeast-1';
check_images( 'apac.stability.stable-fast-upscale-v1:0' === AI_Chat_Bedrock_Images::profile( AI_Chat_Bedrock_Images::UPSCALE_MODEL ) && 'ap-northeast-1' === AI_Chat_Bedrock_Images::region(), 'A filtered region picks its geography\'s profile.' );
$GLOBALS['aicfab_filters']['ai_chat_bedrock_image_region'] = 'not a region';
check_images( 'us-west-2' === AI_Chat_Bedrock_Images::region(), 'A malformed region falls back to US West (Oregon).' );
unset( $GLOBALS['aicfab_filters']['ai_chat_bedrock_image_region'] );

// --- Background removal ----------------------------------------------------

$GLOBALS['aicfab_replies'] = array( aicfab_reply() );
$new                       = AI_Chat_Bedrock_Images::remove_background( 7 );
$call                      = $GLOBALS['aicfab_calls'][0];
check_images( 100 === $new, 'Background removal returns the new attachment.' );
check_images( 'image' === $call[0] && 'us.stability.stable-image-remove-background-v1:0' === $call[2] && array( 'aws_region' => 'us-west-2' ) === $call[3], 'Background removal calls the profile in the image region.' );
check_images( base64_encode( 'PNGBYTES' ) === $call[1]['image'] && 'png' === $call[1]['output_format'], 'The image is sent re-encoded as PNG, which drops its metadata.' );
$inserted = $GLOBALS['aicfab_inserted'][100];
check_images( 'Harbour (background removed)' === $inserted['data']['post_title'] && 'image/png' === $inserted['data']['post_mime_type'] && 3 === $inserted['parent'], 'The copy is a PNG titled after the original and attached to the same post.' );
check_images( 'EDITED' === file_get_contents( $inserted['file'] ) && false !== strpos( $inserted['file'], '-no-background.png' ), 'The returned image is saved under a new file name.' );
check_images( 'ORIGINAL' === file_get_contents( $source ), 'The original file is never changed.' );
check_images( 'Boats in a harbour' === $GLOBALS['aicfab_meta'][100]['_wp_attachment_image_alt'], 'The copy keeps the original alt text.' );
check_images( null === $GLOBALS['aicfab_resized'], 'An image within the limit is not resized.' );

$GLOBALS['aicfab_size'] = array(
	'width'  => 4000,
	'height' => 3000,
);
$GLOBALS['aicfab_replies'] = array( aicfab_reply() );
AI_Chat_Bedrock_Images::remove_background( 7 );
check_images( is_array( $GLOBALS['aicfab_resized'] ) && $GLOBALS['aicfab_resized'][0] * $GLOBALS['aicfab_resized'][1] <= AI_Chat_Bedrock_Images::REMOVE_BACKGROUND_MAX_PIXELS, 'A large image is scaled down to what background removal takes.' );

// --- Upscaling -------------------------------------------------------------

$GLOBALS['aicfab_calls'] = array();
$too_large               = AI_Chat_Bedrock_Images::upscale( 7 );
check_images( is_wp_error( $too_large ) && 'aicfab_image_too_large' === $too_large->get_error_code() && empty( $GLOBALS['aicfab_calls'] ), 'Upscaling refuses an image over one megapixel rather than shrinking it first.' );
$GLOBALS['aicfab_size']    = array(
	'width'  => 1024,
	'height' => 1024,
);
$GLOBALS['aicfab_replies'] = array( aicfab_reply() );
$up                        = AI_Chat_Bedrock_Images::upscale( 7 );
check_images( is_int( $up ) && 'us.stability.stable-fast-upscale-v1:0' === $GLOBALS['aicfab_calls'][0][2], 'A one-megapixel image is upscaled with Fast Upscale.' );
check_images( 'Harbour (upscaled)' === $GLOBALS['aicfab_inserted'][ $up ]['data']['post_title'], 'The upscaled copy says so in its title.' );

$GLOBALS['aicfab_size'] = array(
	'width'  => 40,
	'height' => 900,
);
check_images( 'aicfab_image_too_small' === AI_Chat_Bedrock_Images::upscale( 7 )->get_error_code(), 'An image under 64 pixels on a side is refused.' );
$GLOBALS['aicfab_size'] = array(
	'width'  => 800,
	'height' => 600,
);
check_images( 'aicfab_unsupported_image' === AI_Chat_Bedrock_Images::upscale( 8 )->get_error_code(), 'A GIF is refused.' );
check_images( 'aicfab_unsupported_image' === AI_Chat_Bedrock_Images::remove_background( 999 )->get_error_code(), 'A missing attachment is refused.' );

$GLOBALS['aicfab_replies'] = array( aicfab_reply( 'Filter reason: image' ) );
$filtered                  = AI_Chat_Bedrock_Images::upscale( 7 );
check_images( is_wp_error( $filtered ) && 'aicfab_image_filtered' === $filtered->get_error_code() && false !== strpos( $filtered->get_error_message(), 'Filter reason: image' ), 'A filtered image is reported with the model\'s reason, not saved.' );
$count = count( $GLOBALS['aicfab_inserted'] );
$GLOBALS['aicfab_replies'] = array(
	array(
		'images'         => array( '!!not base64!!' ),
		'finish_reasons' => array( null ),
	),
);
check_images( 'aicfab_no_image' === AI_Chat_Bedrock_Images::upscale( 7 )->get_error_code() && count( $GLOBALS['aicfab_inserted'] ) === $count, 'An unreadable image is not saved.' );

// --- Generation ------------------------------------------------------------

$GLOBALS['aicfab_calls']   = array();
$GLOBALS['aicfab_replies'] = array( aicfab_reply() );
$image                     = AI_Chat_Bedrock_Images::generate( '<b>A lighthouse</b> at dusk', array( 'aspect_ratio' => '21:9', 'mime_type' => 'image/jpeg' ) );
check_images( 'guardrail' === $GLOBALS['aicfab_calls'][0][0] && 'A lighthouse at dusk' === $GLOBALS['aicfab_calls'][0][1] && array() === $GLOBALS['aicfab_calls'][0][2], 'The prompt is checked with the guardrail in the site\'s own region first.' );
check_images( array( 'prompt' => 'A lighthouse at dusk', 'output_format' => 'jpeg', 'aspect_ratio' => '21:9' ) === $GLOBALS['aicfab_calls'][1][1] && 'stability.sd3-5-large-v1:0' === $GLOBALS['aicfab_calls'][1][2], 'The configured model gets the prompt, ratio and format.' );
check_images( 'image/jpeg' === $image['mime_type'] && 42 === $image['seed'] && base64_encode( 'EDITED' ) === $image['data'], 'The image comes back with its type and seed.' );

$GLOBALS['aicfab_calls']   = array();
$GLOBALS['aicfab_replies'] = array( aicfab_reply() );
AI_Chat_Bedrock_Images::generate(
	'Make it night',
	array(
		'image'    => array(
			'media_type' => 'image/png',
			'data'       => 'UE5H',
		),
		'strength' => 4,
	)
);
$payload = $GLOBALS['aicfab_calls'][1][1];
check_images( 'image-to-image' === $payload['mode'] && 'UE5H' === $payload['image'] && 1.0 === $payload['strength'] && ! isset( $payload['aspect_ratio'] ), 'Image-to-image clamps the strength and sends no aspect ratio.' );
check_images( 'aicfab_no_image_to_image' === AI_Chat_Bedrock_Images::generate( 'x', array( 'model' => 'stability.stable-image-core-v1:1', 'image' => array( 'media_type' => 'image/png', 'data' => 'UE5H' ) ) )->get_error_code(), 'Only SD3.5 is sent a reference image.' );

$GLOBALS['aicfab_calls']     = array();
$GLOBALS['aicfab_guardrail'] = new WP_Error( 'aicfab_guardrail_blocked', 'Sorry.' );
check_images( 'aicfab_guardrail_blocked' === AI_Chat_Bedrock_Images::generate( 'blocked' )->get_error_code() && 1 === count( $GLOBALS['aicfab_calls'] ), 'A blocked prompt never reaches the image model.' );
$GLOBALS['aicfab_guardrail'] = true;
check_images( 'aicfab_empty_prompt' === AI_Chat_Bedrock_Images::generate( '<p> </p>' )->get_error_code(), 'An empty prompt is refused.' );
check_images( 'aicfab_prompt_too_long' === AI_Chat_Bedrock_Images::generate( str_repeat( 'a', 10001 ) )->get_error_code(), 'A prompt over 10,000 characters is refused.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['image_model_id'] = '';
check_images( 'aicfab_images_off' === AI_Chat_Bedrock_Images::generate( 'x' )->get_error_code(), 'Nothing is generated while image generation is off.' );

// --- Media Library row actions (source checks) -----------------------------

$admin = file_get_contents( __DIR__ . '/../admin/class-ai-chat-bedrock-admin.php' );
$start = strpos( $admin, 'public function handle_edit_image_action' );
$body  = false === $start ? '' : substr( $admin, $start, 2500 );
check_images( '' !== $body, 'The edit handler exists.' );
check_images( false !== strpos( $body, "check_admin_referer( 'ai_chat_bedrock_edit_image_' . \$edit . '_' . \$attachment )" ), 'The edit handler checks a nonce bound to the edit and the image.' );
check_images( false !== strpos( $body, "current_user_can( 'edit_post', \$attachment )" ) && false !== strpos( $body, "current_user_can( 'upload_files' )" ), 'Editing needs edit rights on the image and the right to upload.' );
check_images( false !== strpos( $body, 'AI_Chat_Bedrock_Images::editing_enabled()' ), 'The handler refuses while editing is off.' );
check_images( strpos( $body, 'check_admin_referer' ) < strpos( $body, 'AI_Chat_Bedrock_Images::upscale' ), 'The nonce is checked before anything is sent.' );
check_images( false !== strpos( $admin, "'aicfab-image'" ), 'The notice flag is a removable query argument.' );
check_images( false !== strpos( file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock.php' ), "'admin_post_ai_chat_bedrock_edit_image', \$admin, 'handle_edit_image_action'" ), 'The edit handler is registered for admin-post.' );

foreach ( $GLOBALS['aicfab_inserted'] as $item ) {
	wp_delete_file( $item['file'] );
}
wp_delete_file( $source );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: image generation and editing checks passed\n";
