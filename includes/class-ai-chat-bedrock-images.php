<?php
/**
 * Image generation and editing with Stability AI models on Amazon Bedrock.
 *
 * Off until a site picks an image model. The Stability models are offered in a few regions
 * only, US West (Oregon) among them, so image requests go to that region whatever region
 * the chat uses; a site that must keep data in one geography can move them with the
 * ai_chat_bedrock_image_region filter. The prompt is checked with the site's guardrail
 * first, because Bedrock Guardrails headers do not apply to image models.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Images {

	/**
	 * Region of the image models unless filtered.
	 */
	const DEFAULT_REGION = 'us-west-2';

	/**
	 * The one generation model that also takes a reference image (image-to-image). Stable
	 * Image Core and Ultra answer that request with "does not support image-to-image mode".
	 */
	const EDIT_MODEL = 'stability.sd3-5-large-v1:0';

	/**
	 * Editing models, served only through geographic inference profiles. The prefix comes
	 * from the image region, see profile().
	 */
	const REMOVE_BACKGROUND_MODEL = 'stability.stable-image-remove-background-v1:0';
	const UPSCALE_MODEL           = 'stability.stable-fast-upscale-v1:0';

	/**
	 * Stability's prompt limit, in characters.
	 */
	const MAX_PROMPT = 10000;

	/**
	 * Fast upscale takes at most about one megapixel and makes it four times larger.
	 */
	const UPSCALE_MAX_PIXELS = 1048576;

	/**
	 * Background removal takes at most about four megapixels.
	 */
	const REMOVE_BACKGROUND_MAX_PIXELS = 4194304;

	/**
	 * Image generation models, best value first.
	 *
	 * @return array Map of model ID to label.
	 */
	public static function models() {
		return array(
			'stability.stable-image-core-v1:1'  => __( 'Stability AI Stable Image Core (fast, lowest cost)', 'ai-chat-for-amazon-bedrock' ),
			'stability.sd3-5-large-v1:0'        => __( 'Stability AI Stable Diffusion 3.5 Large (also edits an image)', 'ai-chat-for-amazon-bedrock' ),
			'stability.stable-image-ultra-v1:1' => __( 'Stability AI Stable Image Ultra (highest quality)', 'ai-chat-for-amazon-bedrock' ),
		);
	}

	/**
	 * Aspect ratios the Stability models accept.
	 *
	 * @return array
	 */
	public static function aspect_ratios() {
		return array( '1:1', '16:9', '21:9', '2:3', '3:2', '4:5', '5:4', '9:16', '9:21' );
	}

	/**
	 * Output types the models return.
	 *
	 * @return array Map of MIME type to Stability output_format.
	 */
	public static function mime_types() {
		return array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpeg',
		);
	}

	/**
	 * Plugin options.
	 *
	 * @param array|null $options Options, or null to read them.
	 * @return array
	 */
	private static function options( $options = null ) {
		if ( null === $options ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
		}
		return is_array( $options ) ? $options : array();
	}

	/**
	 * The image model the site chose, or an empty string when image generation is off.
	 *
	 * @param array|null $options Plugin options.
	 * @return string
	 */
	public static function configured_model( $options = null ) {
		$options = self::options( $options );
		$model   = isset( $options['image_model_id'] ) ? (string) $options['image_model_id'] : '';
		return isset( self::models()[ $model ] ) ? $model : '';
	}

	/**
	 * Whether image generation is on.
	 *
	 * @param array|null $options Plugin options.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		return '' !== self::configured_model( $options );
	}

	/**
	 * Whether the Media Library offers background removal and upscaling.
	 *
	 * Both the media helpers and an image model must be on: choosing an image model
	 * is what agrees to send images to the Stability models and their region.
	 *
	 * @param array|null $options Plugin options.
	 * @return bool
	 */
	public static function editing_enabled( $options = null ) {
		$options = self::options( $options );
		return ! empty( $options['media_assistant'] ) && self::enabled( $options );
	}

	/**
	 * Image types the editing models take.
	 *
	 * @return array
	 */
	public static function editable_types() {
		return array( 'image/jpeg', 'image/png', 'image/webp' );
	}

	/**
	 * Whether a generation model also takes a reference image.
	 *
	 * @param string $model_id Model ID.
	 * @return bool
	 */
	public static function accepts_reference( $model_id ) {
		return self::EDIT_MODEL === (string) $model_id;
	}

	/**
	 * The region image requests go to.
	 *
	 * @return string
	 */
	public static function region() {
		/**
		 * Filters the AWS region of the Stability image models.
		 *
		 * @since 1.57.0
		 *
		 * @param string $region AWS region. US West (Oregon) by default.
		 */
		$region = (string) apply_filters( 'ai_chat_bedrock_image_region', self::DEFAULT_REGION );
		return preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $region ) ? $region : self::DEFAULT_REGION;
	}

	/**
	 * Geographic inference profile of an editing model in the image region.
	 *
	 * @param string $model_id Foundation model ID.
	 * @return string
	 */
	public static function profile( $model_id ) {
		$region = self::region();
		$prefix = 'us';
		if ( 0 === strpos( $region, 'eu-' ) ) {
			$prefix = 'eu';
		} elseif ( 0 === strpos( $region, 'ap-' ) ) {
			$prefix = 'apac';
		}
		return $prefix . '.' . $model_id;
	}

	/**
	 * Client for the image region, with the site's credentials.
	 *
	 * @return AI_Chat_Bedrock_AWS
	 */
	private static function client() {
		return new AI_Chat_Bedrock_AWS( array( 'aws_region' => self::region() ) );
	}

	/**
	 * The site's daily limit, checked before anything is spent.
	 *
	 * @return true|WP_Error
	 */
	private static function within_limit() {
		if ( class_exists( 'AI_Chat_Bedrock_Usage' ) && AI_Chat_Bedrock_Usage::daily_limit_reached( self::options() ) ) {
			return new WP_Error( 'aicfab_daily_limit', __( 'The daily Amazon Bedrock request limit for this site has been reached.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return true;
	}

	/**
	 * Generate one image from a prompt.
	 *
	 * @param string $prompt Description of the image.
	 * @param array  $args   Optional model, aspect_ratio, mime_type, and image (array with
	 *                       media_type and base64 data) for image-to-image with strength 0..1.
	 * @return array|WP_Error Array with data (base64), mime_type, model and seed.
	 */
	public static function generate( $prompt, $args = array() ) {
		$args  = is_array( $args ) ? $args : array();
		$model = isset( $args['model'] ) && '' !== (string) $args['model'] ? (string) $args['model'] : self::configured_model();
		if ( ! isset( self::models()[ $model ] ) ) {
			return new WP_Error( 'aicfab_images_off', __( 'Choose an image model in the plugin settings first.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$prompt = trim( wp_strip_all_tags( (string) $prompt ) );
		if ( '' === $prompt ) {
			return new WP_Error( 'aicfab_empty_prompt', __( 'Describe the image to generate.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( AI_Chat_Bedrock_Security::string_length( $prompt ) > self::MAX_PROMPT ) {
			return new WP_Error( 'aicfab_prompt_too_long', __( 'The image prompt is longer than the 10,000 characters the model accepts.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$mime_types = self::mime_types();
		$mime       = isset( $args['mime_type'] ) && isset( $mime_types[ $args['mime_type'] ] ) ? (string) $args['mime_type'] : 'image/png';
		$payload    = array(
			'prompt'        => $prompt,
			'output_format' => $mime_types[ $mime ],
		);

		$reference = isset( $args['image'] ) && is_array( $args['image'] ) ? $args['image'] : array();
		if ( ! empty( $reference ) ) {
			if ( ! self::accepts_reference( $model ) ) {
				return new WP_Error( 'aicfab_no_image_to_image', __( 'Only Stable Diffusion 3.5 Large edits an existing image. Choose it as the image model.', 'ai-chat-for-amazon-bedrock' ) );
			}
			if ( empty( $reference['data'] ) || ! in_array( (string) ( isset( $reference['media_type'] ) ? $reference['media_type'] : '' ), array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				return new WP_Error( 'aicfab_unsupported_image', __( 'The reference image must be a JPEG, PNG or WebP image.', 'ai-chat-for-amazon-bedrock' ) );
			}
			$strength            = isset( $args['strength'] ) && is_numeric( $args['strength'] ) ? (float) $args['strength'] : 0.6;
			$payload['mode']     = 'image-to-image';
			$payload['image']    = (string) $reference['data'];
			$payload['strength'] = max( 0.0, min( 1.0, $strength ) );
		} else {
			$ratio                   = isset( $args['aspect_ratio'] ) ? (string) $args['aspect_ratio'] : '1:1';
			$payload['aspect_ratio'] = in_array( $ratio, self::aspect_ratios(), true ) ? $ratio : '1:1';
		}

		$allowed = self::within_limit();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		// The guardrail is configured, and checked, in the site's own region.
		$checked = ( new AI_Chat_Bedrock_AWS() )->apply_guardrail( $prompt, 'INPUT' );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$result = self::run( $payload, $model );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['mime_type'] = $mime;
		$result['model']     = $model;
		return $result;
	}

	/**
	 * Send one request and take the first image.
	 *
	 * @param array  $payload  Request body.
	 * @param string $model_id Model or inference profile ID.
	 * @return array|WP_Error Array with data and seed.
	 */
	private static function run( $payload, $model_id ) {
		$response = self::client()->invoke_image( $payload, $model_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		// Stability returns the image it filtered with a reason instead of null, such as
		// "Filter reason: prompt". That image is blurred, so it is reported, not returned.
		$reason = isset( $response['finish_reasons'][0] ) ? $response['finish_reasons'][0] : null;
		if ( null !== $reason && '' !== (string) $reason ) {
			return new WP_Error(
				'aicfab_image_filtered',
				sprintf(
					/* translators: %s: reason given by the image model, such as "Filter reason: prompt". */
					__( 'The image model declined this request (%s). Try a different prompt or image.', 'ai-chat-for-amazon-bedrock' ),
					sanitize_text_field( (string) $reason )
				)
			);
		}
		return array(
			'data' => (string) $response['images'][0],
			'seed' => isset( $response['seeds'][0] ) ? (int) $response['seeds'][0] : 0,
		);
	}

	/**
	 * Remove the background of an image and save the result as a new PNG attachment.
	 *
	 * @param int $attachment_id Image attachment.
	 * @return int|WP_Error New attachment ID.
	 */
	public static function remove_background( $attachment_id ) {
		$image = self::read_attachment( $attachment_id, self::REMOVE_BACKGROUND_MAX_PIXELS, true );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		$allowed = self::within_limit();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$result = self::run(
			array(
				'image'         => $image['data'],
				'output_format' => 'png',
			),
			self::profile( self::REMOVE_BACKGROUND_MODEL )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		/* translators: %s: title of the original image. */
		return self::save_copy( $attachment_id, $result['data'], 'image/png', 'no-background', __( '%s (background removed)', 'ai-chat-for-amazon-bedrock' ) );
	}

	/**
	 * Make an image four times larger and save the result as a new attachment.
	 *
	 * @param int $attachment_id Image attachment.
	 * @return int|WP_Error New attachment ID.
	 */
	public static function upscale( $attachment_id ) {
		$image = self::read_attachment( $attachment_id, self::UPSCALE_MAX_PIXELS, false );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		$allowed = self::within_limit();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$result = self::run(
			array(
				'image'         => $image['data'],
				'output_format' => 'png',
			),
			self::profile( self::UPSCALE_MODEL )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		/* translators: %s: title of the original image. */
		return self::save_copy( $attachment_id, $result['data'], 'image/png', 'upscaled', __( '%s (upscaled)', 'ai-chat-for-amazon-bedrock' ) );
	}

	/**
	 * Read an image attachment for an editing model.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param int  $max_pixels    Largest input the model takes.
	 * @param bool $shrink        Whether a larger image is scaled down to fit. Upscaling refuses
	 *                            instead, since shrinking first and enlarging after loses detail.
	 * @return array|WP_Error Array with data (base64 PNG).
	 */
	private static function read_attachment( $attachment_id, $max_pixels, $shrink ) {
		$attachment_id = absint( $attachment_id );
		$mime          = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::editable_types(), true ) ) {
			return new WP_Error( 'aicfab_unsupported_image', __( 'Only JPEG, PNG and WebP images can be edited.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'aicfab_missing_file', __( 'The image file could not be read.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$size   = $editor->get_size();
		$width  = isset( $size['width'] ) ? (int) $size['width'] : 0;
		$height = isset( $size['height'] ) ? (int) $size['height'] : 0;
		if ( $width < 64 || $height < 64 ) {
			return new WP_Error( 'aicfab_image_too_small', __( 'The image must be at least 64 pixels on each side.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		if ( $width * $height > $max_pixels ) {
			if ( ! $shrink ) {
				return new WP_Error( 'aicfab_image_too_large', __( 'This image is already larger than one megapixel, the most the upscaler takes.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
			}
			$scale = sqrt( $max_pixels / ( $width * $height ) );
			$editor->resize( (int) floor( $width * $scale ), (int) floor( $height * $scale ), false );
		}
		// Always re-encoded as PNG: it keeps transparency and drops the original's metadata.
		$temp = wp_tempnam( 'aicfab-image' );
		$save = $editor->save( $temp, 'image/png' );
		if ( file_exists( $temp ) && ( is_wp_error( $save ) || empty( $save['path'] ) || $save['path'] !== $temp ) ) {
			wp_delete_file( $temp );
		}
		if ( is_wp_error( $save ) || empty( $save['path'] ) || ! file_exists( $save['path'] ) ) {
			return new WP_Error( 'aicfab_missing_file', __( 'The image file could not be read.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 500 ) );
		}
		$bytes = file_get_contents( $save['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $save['path'] );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'aicfab_missing_file', __( 'The image file could not be read.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 500 ) );
		}
		return array(
			'data' => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding image bytes for the model, not obfuscation.
		);
	}

	/**
	 * Save an edited image as a new attachment beside the original, which is never changed.
	 *
	 * @param int    $original_id Original attachment.
	 * @param string $data        Base64 image.
	 * @param string $mime        MIME type of the image.
	 * @param string $suffix      File name suffix.
	 * @param string $title       Title format with %s for the original title.
	 * @return int|WP_Error New attachment ID.
	 */
	private static function save_copy( $original_id, $data, $mime, $suffix, $title ) {
		$bytes = base64_decode( (string) $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the image the model returned.
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'aicfab_no_image', __( 'Amazon Bedrock returned no image.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$name = sanitize_file_name( pathinfo( (string) get_attached_file( $original_id ), PATHINFO_FILENAME ) . '-' . $suffix . ( 'image/jpeg' === $mime ? '.jpg' : '.png' ) );
		return self::save_attachment( $name, $bytes, $mime, sprintf( $title, get_the_title( $original_id ) ), (int) wp_get_post_parent_id( $original_id ), (string) get_post_meta( $original_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Store image bytes in the Media Library.
	 *
	 * @param string $name      File name.
	 * @param string $bytes     Image bytes.
	 * @param string $mime      MIME type.
	 * @param string $title     Attachment title.
	 * @param int    $parent_id Post the attachment belongs to, or 0.
	 * @param string $alt       Alt text, or an empty string.
	 * @return int|WP_Error New attachment ID.
	 */
	public static function save_attachment( $name, $bytes, $mime, $title, $parent_id = 0, $alt = '' ) {
		$upload = wp_upload_bits( $name, null, $bytes );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return new WP_Error( 'aicfab_upload_failed', __( 'The image could not be saved to the Media Library.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => sanitize_text_field( $title ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			absint( $parent_id ),
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $upload['file'] );
			return $id;
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		if ( '' !== trim( $alt ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return (int) $id;
	}
}
