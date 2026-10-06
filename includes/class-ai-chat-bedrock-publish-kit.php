<?php
/**
 * Platform copy for a post, written by the site's own model, for its owner to publish.
 *
 * Bilibili's publishing API is open only to registered companies, and Xiaohongshu has none for
 * creators, so on most sites a person publishes there. The kit does the writing: from the text
 * a signed-out visitor reads, the chat's model drafts each platform's title, description and
 * tags within that platform's limits, in the post's language. The editor reviews and copies it,
 * opens the platform's creator page, publishes, and records the address in the same box. No
 * agent outside WordPress is needed; agents can ask for a kit through an ability as well.
 *
 * The copy is a draft by AI and is labelled so: China's rules on AI-generated content ask the
 * person who publishes it to declare it with the platform's own label.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Publish_Kit {

	const META = '_aicfab_publish_kit';

	const REST_ROUTE = '/publish-kit';

	// Kits a user may ask for in a minute; each is one model request.
	const RATE_LIMIT = 6;

	// Text of the post given to the model.
	const MAX_SOURCE = 6000;

	/**
	 * Each platform's fields and their limits, in characters.
	 *
	 * @return array
	 */
	public static function platforms() {
		return array(
			'bilibili'    => array(
				'label'   => __( 'Bilibili', 'ai-chat-for-amazon-bedrock' ),
				'title'   => 80,
				'body'    => 2000,
				'tags'    => 10,
				'tag'     => 20,
				'publish' => 'https://member.bilibili.com/platform/upload/video/frame',
				'rules'   => 'Bilibili video: title up to 80 characters, informative rather than clickbait; description up to 2000 characters with a short summary and what the viewer will learn; up to 10 tags of up to 20 characters; "category" is the Bilibili 分区 that fits best, such as 科技 > 人工智能.',
			),
			'xiaohongshu' => array(
				'label'   => __( 'Xiaohongshu', 'ai-chat-for-amazon-bedrock' ),
				'title'   => 20,
				'body'    => 1000,
				'tags'    => 10,
				'tag'     => 20,
				'publish' => 'https://creator.xiaohongshu.com/publish/publish',
				'rules'   => 'Xiaohongshu note: title up to 20 characters; body up to 1000 characters in short paragraphs, practical and personal in tone, no links (Xiaohongshu does not show them); up to 10 topics, given as \"tags\", without the # sign; \"category\" is empty.',
			),
			'youtube'     => array(
				'label'   => __( 'YouTube', 'ai-chat-for-amazon-bedrock' ),
				'title'   => 100,
				'body'    => 5000,
				'tags'    => 15,
				'tag'     => 30,
				'publish' => 'https://studio.youtube.com/',
				'rules'   => 'YouTube video: title up to 100 characters; description up to 5000 characters, opening with two sentences that say what the video covers, then the key points, then the article\'s address on its own line; up to 15 tags; "category" is the YouTube category that fits, such as Science & Technology.',
			),
		);
	}

	/**
	 * Whether kits can be made: the setting, and a model to write them.
	 *
	 * @param array|null $options Settings.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		$options = is_array( $options ) ? $options : get_option( 'ai_chat_bedrock_settings', array() );
		return is_array( $options ) && ! empty( $options['publish_kit'] );
	}

	/**
	 * The saved kit of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get( $post_id ) {
		$kit = get_post_meta( absint( $post_id ), self::META, true );
		return is_array( $kit ) && isset( $kit['platforms'] ) && is_array( $kit['platforms'] ) ? $kit : null;
	}

	/**
	 * The messages that ask the model for a kit.
	 *
	 * Only the title and the text a signed-out visitor reads are given, so a members-only
	 * section never reaches another platform.
	 *
	 * @param WP_Post  $post      Post.
	 * @param string[] $platforms Platforms.
	 * @return array|WP_Error
	 */
	public static function prompt( $post, $platforms ) {
		$text = AI_Chat_Bedrock_Content::public_text( $post );
		if ( '' === trim( (string) $text ) ) {
			return new WP_Error( 'aicfab_kit_empty', __( 'The post has no public text to write from.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		$all   = self::platforms();
		$rules = array();
		foreach ( $platforms as $platform ) {
			$rules[] = '- "' . $platform . '": ' . $all[ $platform ]['rules'];
		}
		$name     = AI_Chat_Bedrock_Content::language_name( AI_Chat_Bedrock_Content::language( $post ) );
		$language = '' !== $name ? 'Write in ' . $name . '.' : 'Write in the language of the article.';
		$ask      = 'Write copy to publish this article on other platforms. Use only what the article says: no facts, numbers, quotes or promises it does not contain, and no hype. ' . $language . "\n\n"
			. "Return only a JSON object with one key per platform below, each an object with \"title\", \"body\", \"tags\" (an array of strings) and \"category\":\n" . implode( "\n", $rules ) . "\n\n"
			. 'Article address: ' . get_permalink( $post ) . "\n\n"
			. "Article:\n" . AI_Chat_Bedrock_Security::string_substr( (string) $text, 0, self::MAX_SOURCE );
		return array(
			array(
				'role'    => 'user',
				'content' => $ask,
			),
		);
	}

	/**
	 * Write a post's kit and keep it.
	 *
	 * @param int      $post_id   Post ID.
	 * @param string[] $platforms Platforms; all when empty.
	 * @return array|WP_Error The kit.
	 */
	public static function generate( $post_id, $platforms = array() ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! AI_Chat_Bedrock_Content::is_public( $post ) ) {
			return new WP_Error( 'aicfab_kit_post', __( 'Kits are written for published posts that anyone can read.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		$known     = array_keys( self::platforms() );
		$platforms = array_values( array_intersect( $known, $platforms ? array_map( 'sanitize_key', (array) $platforms ) : $known ) );
		if ( ! $platforms ) {
			return new WP_Error( 'aicfab_kit_platform', __( 'Choose a platform.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		$prompt = self::prompt( $post, $platforms );
		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}
		$aws      = new AI_Chat_Bedrock_AWS(
			array(
				'max_tokens'  => 2500,
				'temperature' => 0.4,
			)
		);
		$response = $aws->handle_chat_message( array( 'messages' => $prompt ) );
		if ( empty( $response['success'] ) ) {
			$code = isset( $response['data']['code'] ) ? $response['data']['code'] : 'aicfab_error';
			return new WP_Error( $code, isset( $response['data']['message'] ) ? $response['data']['message'] : __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 502 ) );
		}
		$copy = self::parse( isset( $response['data']['message'] ) ? (string) $response['data']['message'] : '', $platforms );
		if ( ! $copy ) {
			return new WP_Error( 'aicfab_kit_unreadable', __( 'The model\'s answer could not be read as platform copy. Please try again.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 502 ) );
		}
		$saved = self::get( $post->ID );
		$kit   = array(
			'time'      => time(),
			// The chat's model, or the fallback when it answered instead.
			'model'     => sanitize_text_field( (string) ( isset( $response['fallback_model'] ) ? $response['fallback_model'] : self::setting( 'model_id' ) ) ),
			'platforms' => array_merge( $saved ? $saved['platforms'] : array(), $copy ),
		);
		update_post_meta( $post->ID, self::META, $kit );
		return $kit;
	}

	/**
	 * The model's answer as copy within each platform's limits.
	 *
	 * @param string   $answer    Answer.
	 * @param string[] $platforms Platforms asked for.
	 * @return array Platform to title, body, tags and category; empty when nothing could be read.
	 */
	public static function parse( $answer, $platforms ) {
		$start = strpos( $answer, '{' );
		$end   = strrpos( $answer, '}' );
		$data  = false !== $start && false !== $end && $end > $start ? json_decode( substr( $answer, $start, $end - $start + 1 ), true ) : null;
		if ( ! is_array( $data ) ) {
			return array();
		}
		$all  = self::platforms();
		$copy = array();
		foreach ( $platforms as $platform ) {
			$entry = isset( $data[ $platform ] ) && is_array( $data[ $platform ] ) ? $data[ $platform ] : null;
			if ( null === $entry ) {
				continue;
			}
			$rules = $all[ $platform ];
			$tags  = array();
			// Models sometimes name Xiaohongshu's topics as such.
			$given = isset( $entry['tags'] ) && is_array( $entry['tags'] ) ? $entry['tags'] : ( isset( $entry['topics'] ) && is_array( $entry['topics'] ) ? $entry['topics'] : array() );
			foreach ( $given as $tag ) {
				$tag = trim( ltrim( sanitize_text_field( is_scalar( $tag ) ? (string) $tag : '' ), '#＃' ) );
				if ( '' !== $tag && ! in_array( $tag, $tags, true ) ) {
					$tags[] = AI_Chat_Bedrock_Security::string_substr( $tag, 0, $rules['tag'] );
				}
			}
			$title = self::field( $entry, 'title', $rules['title'], false );
			$body  = self::field( $entry, 'body', $rules['body'], true );
			if ( '' === $title && '' === $body ) {
				continue;
			}
			$copy[ $platform ] = array(
				'title'    => $title,
				'body'     => $body,
				'tags'     => array_slice( $tags, 0, $rules['tags'] ),
				'category' => self::field( $entry, 'category', 40, false ),
			);
		}
		return $copy;
	}

	private static function setting( $key ) {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		return is_array( $options ) && isset( $options[ $key ] ) && is_scalar( $options[ $key ] ) ? (string) $options[ $key ] : '';
	}

	private static function field( $entry, $key, $limit, $lines ) {
		$value = isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ? (string) $entry[ $key ] : '';
		$value = $lines ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		return AI_Chat_Bedrock_Security::string_length( $value ) > $limit ? AI_Chat_Bedrock_Security::string_substr( $value, 0, $limit ) : $value;
	}

	/**
	 * Register the route the editor's button and agents use.
	 */
	public function register_routes() {
		register_rest_route(
			AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'post'      => array(
						'required' => true,
						'type'     => 'integer',
					),
					'platforms' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * Who may ask for a kit: anyone who may edit the post, a few times a minute.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'aicfab_kit_off', __( 'Publishing kits are off on this site.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', absint( $request->get_param( 'post' ) ) ) ) {
			return new WP_Error( 'aicfab_forbidden', __( 'You are not allowed to edit this post.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 403 ) );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'publish-kit', self::RATE_LIMIT ) ) {
			return new WP_Error( 'aicfab_rate_limited', __( 'Too many requests. Please wait a moment.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}
		return true;
	}

	public function handle( $request ) {
		$kit = self::generate( absint( $request->get_param( 'post' ) ), (array) $request->get_param( 'platforms' ) );
		return is_wp_error( $kit ) ? $kit : rest_ensure_response( $kit );
	}

	/**
	 * Write a kit from the editor's box, and return to the editor.
	 */
	public static function handle_write() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		check_admin_referer( 'aicfab_publish_kit_' . $post_id );
		if ( ! $post_id || ! self::enabled() || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this post.', 'ai-chat-for-amazon-bedrock' ), 403 );
		}
		$kit = AI_Chat_Bedrock_Security::check_rate_limit( 'publish-kit', self::RATE_LIMIT ) ? self::generate( $post_id ) : new WP_Error( 'aicfab_rate_limited', __( 'Too many requests. Please wait a moment.', 'ai-chat-for-amazon-bedrock' ) );
		set_transient( 'aicfab_publish_kit_notice_' . get_current_user_id(), is_wp_error( $kit ) ? $kit->get_error_message() : __( 'Platform copy written. Check it before publishing.', 'ai-chat-for-amazon-bedrock' ), 300 );
		wp_safe_redirect( (string) get_edit_post_link( $post_id, 'url' ) );
		exit;
	}

	/**
	 * The kit in the editor's Published elsewhere box, with the button that writes it.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_box_section( $post ) {
		if ( ! self::enabled() || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$notice = get_transient( 'aicfab_publish_kit_notice_' . get_current_user_id() );
		if ( is_string( $notice ) && '' !== $notice ) {
			delete_transient( 'aicfab_publish_kit_notice_' . get_current_user_id() );
			echo '<p><strong>' . esc_html( $notice ) . '</strong></p>';
		}
		if ( 'publish' !== $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Platform copy is written for published posts.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
			return;
		}
		$kit = self::get( $post->ID );
		echo '<hr><p><strong>' . esc_html__( 'Publishing kit', 'ai-chat-for-amazon-bedrock' ) . '</strong></p>';
		if ( $kit ) {
			echo '<p class="description">' . esc_html__( 'Drafted by AI from the public text. Check every word, and declare AI assistance with the platform\'s own label when you publish.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
			$cover = (string) get_the_post_thumbnail_url( $post, 'full' );
			foreach ( self::platforms() as $platform => $rules ) {
				if ( empty( $kit['platforms'][ $platform ] ) ) {
					continue;
				}
				$copy = $kit['platforms'][ $platform ];
				echo '<details><summary>' . esc_html( $rules['label'] ) . '</summary>';
				echo '<label>' . esc_html__( 'Title', 'ai-chat-for-amazon-bedrock' ) . '<input type="text" class="widefat" readonly value="' . esc_attr( $copy['title'] ) . '"></label>';
				echo '<label>' . esc_html__( 'Text', 'ai-chat-for-amazon-bedrock' ) . '<textarea class="widefat" rows="6" readonly>' . esc_textarea( $copy['body'] ) . '</textarea></label>';
				if ( $copy['tags'] ) {
					echo '<label>' . esc_html__( 'Tags', 'ai-chat-for-amazon-bedrock' ) . '<input type="text" class="widefat" readonly value="' . esc_attr( implode( ', ', $copy['tags'] ) ) . '"></label>';
				}
				if ( '' !== $copy['category'] ) {
					/* translators: %s: suggested category on the platform. */
					echo '<p class="description">' . esc_html( sprintf( __( 'Category: %s', 'ai-chat-for-amazon-bedrock' ), $copy['category'] ) ) . '</p>';
				}
				echo '<p><a href="' . esc_url( $rules['publish'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open the creator page', 'ai-chat-for-amazon-bedrock' ) . '</a>';
				if ( '' !== $cover ) {
					echo ' · <a href="' . esc_url( $cover ) . '" download>' . esc_html__( 'Download the cover', 'ai-chat-for-amazon-bedrock' ) . '</a>';
				}
				echo '</p></details>';
			}
		}
		$label = $kit ? __( 'Write the platform copy again', 'ai-chat-for-amazon-bedrock' ) : __( 'Write platform copy', 'ai-chat-for-amazon-bedrock' );
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ai_chat_bedrock_publish_kit&post=' . (int) $post->ID ), 'aicfab_publish_kit_' . (int) $post->ID ) ) . '">' . esc_html( $label ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Uses one request of the chat\'s model. Save your changes to the post first: the button leaves the editor.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}

	/**
	 * Register the ability agents use to ask for a kit.
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! self::enabled() ) {
			return;
		}
		wp_register_ability(
			'ai-chat-bedrock/prepare-publish-kit',
			array(
				'label'               => __( 'Write a post\'s platform copy', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Write the title, text, tags and category to publish a published post on Bilibili, Xiaohongshu or YouTube, from the text a signed-out visitor reads, with the site\'s model, and keep it with the post. Publishes nothing.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => self::schema(),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_generate' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'category'            => AI_Chat_Bedrock_Abilities::CATEGORY,
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'public'      => true,
				),
			)
		);
	}

	public static function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'   => array( 'type' => 'integer' ),
				'platforms' => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
						'enum' => array_keys( self::platforms() ),
					),
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	public function can_generate( $input = array() ) {
		return is_array( $input ) && ! empty( $input['post_id'] ) && current_user_can( 'edit_post', absint( $input['post_id'] ) ) && AI_Chat_Bedrock_Security::check_rate_limit( 'publish-kit', self::RATE_LIMIT );
	}

	public function ability_generate( $input ) {
		return self::generate( absint( $input['post_id'] ), isset( $input['platforms'] ) ? (array) $input['platforms'] : array() );
	}
}
