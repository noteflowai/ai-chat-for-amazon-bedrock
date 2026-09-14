<?php
/**
 * Media and content helpers.
 *
 * Two small automations that site owners ask for constantly:
 *   - Describe an image so it has useful alt text for accessibility and SEO.
 *   - Summarize a post into an excerpt.
 *
 * Both require the matching WordPress capability, never overwrite existing values
 * unless the caller asks, and store nothing beyond the field being filled.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Media_Assistant {

	const MAX_IMAGE_BYTES = 3800000;
	const MAX_EDGE        = 1024;
	const ALT_MAX_CHARS   = 160;
	const EXCERPT_MAX     = 320;
	const RATE_LIMIT      = 20;
	const MAX_BULK        = 20;

	/**
	 * Whether the media helpers are enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$enabled = ! empty( $options['media_assistant'] );
		return (bool) apply_filters( 'ai_chat_bedrock_media_assistant_enabled', $enabled );
	}

	/**
	 * Image types the vision model accepts.
	 *
	 * @return array
	 */
	public static function supported_types() {
		return array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	}

	/**
	 * Register the media and excerpt routes.
	 */
	public function register_routes() {
		register_rest_route(
			AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1,
			'/alt-text',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_alt_text' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'attachment' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'overwrite'  => array(
						'required' => false,
						'type'     => 'boolean',
					),
				),
			)
		);
		register_rest_route(
			AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1,
			'/excerpt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_excerpt' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'post' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'save' => array(
						'required' => false,
						'type'     => 'boolean',
					),
				),
			)
		);
	}

	/**
	 * Shared permission check.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( ! self::enabled() ) {
			return new WP_Error( 'aicfab_media_disabled', __( 'The media helpers are disabled on this site.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'upload_files' ) && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'aicfab_forbidden', __( 'You are not allowed to use the media helpers.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 403 ) );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'media-assistant', self::RATE_LIMIT ) ) {
			return new WP_Error( 'aicfab_rate_limited', __( 'Too many requests. Please wait a moment.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Describe an image and optionally store the alt text.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_alt_text( $request ) {
		$attachment_id = absint( $request->get_param( 'attachment' ) );
		$overwrite     = (bool) $request->get_param( 'overwrite' );
		$result        = $this->generate_alt_text( $attachment_id, $overwrite );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Generate alt text for one attachment.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $overwrite     Whether to replace existing alt text.
	 * @return array|WP_Error
	 */
	public function generate_alt_text( $attachment_id, $overwrite = false ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'aicfab_invalid_attachment', __( 'That attachment does not exist.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new WP_Error( 'aicfab_forbidden', __( 'You are not allowed to edit this attachment.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 403 ) );
		}

		$existing = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( $existing ) && ! $overwrite ) {
			return array(
				'attachment' => $attachment_id,
				'alt'        => $existing,
				'saved'      => false,
				'skipped'    => true,
				'reason'     => __( 'This image already has alt text.', 'ai-chat-for-amazon-bedrock' ),
			);
		}

		$image = $this->read_image( $attachment_id );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$context = wp_strip_all_tags( (string) get_the_title( $attachment_id ) );
		$prompt  = 'Write alt text for this image in at most 20 words. Describe what is visible for someone who cannot see it. Do not start with "image of" or "picture of", do not add quotation marks, and do not guess names, brands or text you cannot read clearly.';
		if ( '' !== $context ) {
			$prompt .= ' The file is titled: ' . $context . '.';
		}

		$aws      = new AI_Chat_Bedrock_AWS(
			array(
				'max_tokens'  => 200,
				'temperature' => 0.2,
			)
		);
		$response = $aws->handle_chat_message(
			array(
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => 'You write concise, factual alternative text for website images. You never speculate about identities or unreadable text.',
					),
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'image'    => $image,
			)
		);

		if ( empty( $response['success'] ) ) {
			$code = isset( $response['data']['code'] ) ? $response['data']['code'] : 'aicfab_error';
			return new WP_Error( $code, isset( $response['data']['message'] ) ? $response['data']['message'] : __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 502 ) );
		}

		$alt = self::clean_alt( isset( $response['data']['message'] ) ? $response['data']['message'] : '' );
		if ( '' === $alt ) {
			return new WP_Error( 'aicfab_empty_alt', __( 'The model did not return usable alt text.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 502 ) );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

		return array(
			'attachment' => $attachment_id,
			'alt'        => $alt,
			'saved'      => true,
			'skipped'    => false,
			'usage'      => isset( $response['usage'] ) ? $response['usage'] : array(),
		);
	}

	/**
	 * Summarize a post into an excerpt.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_excerpt( $request ) {
		$post_id = absint( $request->get_param( 'post' ) );
		$save    = (bool) $request->get_param( 'save' );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'aicfab_invalid_post', __( 'That post does not exist.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'aicfab_forbidden', __( 'You are not allowed to edit this post.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 403 ) );
		}

		$content = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$content = trim( preg_replace( '/\s+/', ' ', (string) $content ) );
		if ( '' === $content ) {
			return new WP_Error( 'aicfab_empty_post', __( 'This post has no content to summarize.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}

		$aws      = new AI_Chat_Bedrock_AWS(
			array(
				'max_tokens'  => 300,
				'temperature' => 0.2,
			)
		);
		$response = $aws->handle_chat_message(
			array(
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => 'You write short, factual excerpts for WordPress posts. Use only the supplied content and never add new claims.',
					),
					array(
						'role'    => 'user',
						'content' => 'Summarize the following post in one or two sentences, at most 40 words, in the language of the text. Return only the summary.' . "\n\n" . AI_Chat_Bedrock_Security::string_substr( $content, 0, 6000 ),
					),
				),
			)
		);

		if ( empty( $response['success'] ) ) {
			$code = isset( $response['data']['code'] ) ? $response['data']['code'] : 'aicfab_error';
			return new WP_Error( $code, isset( $response['data']['message'] ) ? $response['data']['message'] : __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 502 ) );
		}

		$excerpt = AI_Chat_Bedrock_Security::string_substr( sanitize_text_field( (string) $response['data']['message'] ), 0, self::EXCERPT_MAX );
		$saved   = false;
		if ( $save && '' !== $excerpt ) {
			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => $excerpt,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			$saved = true;
		}

		return rest_ensure_response(
			array(
				'post'    => $post_id,
				'excerpt' => $excerpt,
				'saved'   => $saved,
				'usage'   => isset( $response['usage'] ) ? $response['usage'] : array(),
			)
		);
	}

	/**
	 * Normalize model output into alt text.
	 *
	 * @param string $raw Model output.
	 * @return string
	 */
	public static function clean_alt( $raw ) {
		$alt = trim( wp_strip_all_tags( (string) $raw ) );
		$alt = preg_replace( '/\s+/', ' ', (string) $alt );
		$alt = trim( (string) $alt, " \"'`*" );
		$alt = preg_replace( '/^(an?\s+)?(image|picture|photo|photograph|screenshot)\s+(of|showing)\s+/i', '', (string) $alt );
		$alt = ucfirst( trim( (string) $alt ) );
		return AI_Chat_Bedrock_Security::string_substr( sanitize_text_field( $alt ), 0, self::ALT_MAX_CHARS );
	}

	/**
	 * Read an attachment as base64 data the vision model accepts.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error
	 */
	private function read_image( $attachment_id ) {
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::supported_types(), true ) ) {
			return new WP_Error( 'aicfab_unsupported_image', __( 'Only JPEG, PNG, GIF and WebP images are supported.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'aicfab_missing_file', __( 'The image file could not be read.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}

		// Prefer a resized copy so large uploads stay within the request limit.
		$editor = wp_get_image_editor( $path );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
			$temp = wp_tempnam( 'aicfab-alt' );
			$save = $editor->save( $temp, 'image/jpeg' );
			if ( ! is_wp_error( $save ) && ! empty( $save['path'] ) && file_exists( $save['path'] ) ) {
				$bytes = file_get_contents( $save['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				wp_delete_file( $save['path'] );
				if ( false !== $bytes && strlen( $bytes ) <= self::MAX_IMAGE_BYTES ) {
					return array(
						'media_type' => 'image/jpeg',
						'data'       => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding image bytes for the vision model, not obfuscation.
					);
				}
			}
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}
		}

		if ( filesize( $path ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'aicfab_image_too_large', __( 'This image is too large to describe. Upload a smaller version.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 413 ) );
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes ) {
			return new WP_Error( 'aicfab_missing_file', __( 'The image file could not be read.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		return array(
			'media_type' => $mime,
			'data'       => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding image bytes for the vision model, not obfuscation.
		);
	}
}
