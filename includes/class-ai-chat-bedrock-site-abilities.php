<?php
/**
 * Controlled WordPress and WooCommerce abilities.
 *
 * These abilities are deliberately narrow. Reads are limited to published,
 * publicly visible content. The only write operation creates a draft, never
 * publishes, and never touches an existing post. Nothing here executes code,
 * changes settings, installs plugins, or deletes data.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Site_Abilities {

	const MAX_RESULTS     = 10;
	const MAX_EXCERPT     = 1500;
	const MAX_DRAFT_CHARS = 20000;
	const MAX_TITLE_CHARS = 200;

	/**
	 * Whether abilities were already registered in this request.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Whether these abilities are enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$enabled = (bool) get_option( 'ai_chat_bedrock_site_abilities', false );
		return (bool) apply_filters( 'ai_chat_bedrock_site_abilities_enabled', $enabled );
	}

	/**
	 * Register the abilities.
	 */
	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) || ! self::enabled() || $this->registered ) {
			return;
		}
		$this->registered = true;

		wp_register_ability(
			'ai-chat-bedrock/search-content',
			array(
				'label'               => __( 'Search published content', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Search published posts and pages and return titles, URLs and excerpts. Read only.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'query'    => array(
							'type'        => 'string',
							'description' => __( 'Search terms.', 'ai-chat-for-amazon-bedrock' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Maximum results, up to 10.', 'ai-chat-for-amazon-bedrock' ),
						),
					),
					'required'   => array( 'query' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'search_content' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		wp_register_ability(
			'ai-chat-bedrock/get-post',
			array(
				'label'               => __( 'Read a published post', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Return the title, URL and plain-text content of one published post or page. Read only.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'Post ID.', 'ai-chat-for-amazon-bedrock' ),
						),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'get_post_content' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		wp_register_ability(
			'ai-chat-bedrock/create-draft',
			array(
				'label'               => __( 'Create a draft post', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Create a new draft post. Drafts are never published automatically and existing posts are never modified.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'   => array(
							'type'        => 'string',
							'description' => __( 'Draft title.', 'ai-chat-for-amazon-bedrock' ),
						),
						'content' => array(
							'type'        => 'string',
							'description' => __( 'Draft body text.', 'ai-chat-for-amazon-bedrock' ),
						),
					),
					'required'   => array( 'title', 'content' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'create_draft' ),
				'permission_callback' => array( $this, 'can_draft' ),
			)
		);

		wp_register_ability(
			'ai-chat-bedrock/suggest-seo-meta',
			array(
				'label'               => __( 'Suggest SEO metadata', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Analyse a published post and return suggested title and meta description text. Nothing is saved.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'Post ID.', 'ai-chat-for-amazon-bedrock' ),
						),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'suggest_seo_meta' ),
				'permission_callback' => array( $this, 'can_draft' ),
			)
		);

		if ( class_exists( 'WooCommerce' ) ) {
			wp_register_ability(
				'ai-chat-bedrock/get-products',
				array(
					'label'               => __( 'Read published WooCommerce products', 'ai-chat-for-amazon-bedrock' ),
					'description'         => __( 'Search published WooCommerce products and return name, price, stock status and URL. Read only; orders and customers are never exposed.', 'ai-chat-for-amazon-bedrock' ),
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'query'    => array(
								'type'        => 'string',
								'description' => __( 'Product search terms.', 'ai-chat-for-amazon-bedrock' ),
							),
							'per_page' => array(
								'type'        => 'integer',
								'description' => __( 'Maximum results, up to 10.', 'ai-chat-for-amazon-bedrock' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( $this, 'get_products' ),
					'permission_callback' => array( $this, 'can_read' ),
				)
			);
		}
	}

	/**
	 * Permission callback for read-only abilities.
	 *
	 * @return bool
	 */
	public function can_read() {
		return is_user_logged_in() && current_user_can( AI_Chat_Bedrock_Tool_Policy::required_capability() );
	}

	/**
	 * Permission callback for draft creation and analysis.
	 *
	 * @return bool
	 */
	public function can_draft() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Search published content.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function search_content( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$query    = isset( $input['query'] ) ? sanitize_text_field( (string) $input['query'] ) : '';
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 5;
		$per_page = max( 1, min( self::MAX_RESULTS, $per_page ) );

		if ( '' === $query ) {
			return array(
				'results' => array(),
				'count'   => 0,
			);
		}

		$search = new WP_Query(
			array(
				's'                      => $query,
				'post_type'              => array( 'post', 'page' ),
				'post_status'            => 'publish',
				'posts_per_page'         => $per_page,
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
			)
		);

		$results = array();
		foreach ( $search->posts as $post ) {
			if ( ! $this->is_public_post( $post ) ) {
				continue;
			}
			$results[] = array(
				'id'      => (int) $post->ID,
				'title'   => get_the_title( $post ),
				'url'     => get_permalink( $post ),
				'type'    => $post->post_type,
				'excerpt' => $this->plain_text( $post->post_content, 400 ),
			);
		}
		wp_reset_postdata();

		return array(
			'results' => $results,
			'count'   => count( $results ),
		);
	}

	/**
	 * Read one published post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function get_post_content( $input ) {
		$post = $this->resolve_public_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			'id'      => (int) $post->ID,
			'title'   => get_the_title( $post ),
			'url'     => get_permalink( $post ),
			'type'    => $post->post_type,
			'content' => $this->plain_text( $post->post_content, self::MAX_EXCERPT ),
		);
	}

	/**
	 * Create a draft post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function create_draft( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$title   = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		$content = isset( $input['content'] ) ? (string) $input['content'] : '';

		if ( '' === $title || '' === trim( $content ) ) {
			return new WP_Error( 'aicfab_missing_draft_fields', __( 'A title and content are required.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'aicfab_forbidden', __( 'You are not allowed to create drafts.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$title   = AI_Chat_Bedrock_Security::string_substr( $title, 0, self::MAX_TITLE_CHARS );
		$content = AI_Chat_Bedrock_Security::string_substr( wp_kses_post( $content ), 0, self::MAX_DRAFT_CHARS );

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $content,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array(
			'id'        => (int) $post_id,
			'status'    => 'draft',
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
			'published' => false,
		);
	}

	/**
	 * Suggest SEO metadata without saving anything.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function suggest_seo_meta( $input ) {
		$post = $this->resolve_public_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$title   = get_the_title( $post );
		$content = $this->plain_text( $post->post_content, 2000 );
		$words   = preg_split( '/\s+/', $content, -1, PREG_SPLIT_NO_EMPTY );
		$words   = is_array( $words ) ? $words : array();

		$description = AI_Chat_Bedrock_Security::string_substr( $content, 0, 155 );
		$suggested   = AI_Chat_Bedrock_Security::string_substr( $title, 0, 60 );

		return array(
			'id'                    => (int) $post->ID,
			'current_title'         => $title,
			'suggested_title'       => $suggested,
			'suggested_description' => $description,
			'word_count'            => count( $words ),
			'title_length'          => AI_Chat_Bedrock_Security::string_length( $title ),
			'saved'                 => false,
			'notes'                 => __( 'Suggestions only. Apply them in your SEO plugin after review.', 'ai-chat-for-amazon-bedrock' ),
		);
	}

	/**
	 * Read published WooCommerce products.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function get_products( $input ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'results' => array(),
				'count'   => 0,
			);
		}

		$input    = is_array( $input ) ? $input : array();
		$query    = isset( $input['query'] ) ? sanitize_text_field( (string) $input['query'] ) : '';
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 5;
		$per_page = max( 1, min( self::MAX_RESULTS, $per_page ) );

		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'has_password'           => false,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
		);
		if ( '' !== $query ) {
			$args['s'] = $query;
		}

		$search  = new WP_Query( $args );
		$results = array();

		foreach ( $search->posts as $post ) {
			if ( ! $this->is_public_post( $post ) ) {
				continue;
			}
			$entry = array(
				'id'   => (int) $post->ID,
				'name' => get_the_title( $post ),
				'url'  => get_permalink( $post ),
			);

			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post->ID );
				if ( $product && method_exists( $product, 'get_price_html' ) ) {
					$entry['price']        = wp_strip_all_tags( $product->get_price_html() );
					$entry['stock_status'] = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '';
					$entry['sku']          = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';
				}
			}
			$results[] = $entry;
		}
		wp_reset_postdata();

		return array(
			'results' => $results,
			'count'   => count( $results ),
		);
	}

	private function resolve_public_post( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		if ( $post_id < 1 ) {
			return new WP_Error( 'aicfab_invalid_post', __( 'A valid post ID is required.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$post = get_post( $post_id );
		if ( ! $this->is_public_post( $post ) ) {
			return new WP_Error( 'aicfab_post_not_available', __( 'That post is not published or not publicly readable.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return $post;
	}

	private function is_public_post( $post ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return false;
		}
		$type = get_post_type_object( $post->post_type );
		return $type && ! empty( $type->public );
	}

	private function plain_text( $content, $limit ) {
		$text = wp_strip_all_tags( strip_shortcodes( (string) $content ) );
		$text = preg_replace( '/\s+/', ' ', (string) $text );
		return AI_Chat_Bedrock_Security::string_substr( trim( (string) $text ), 0, absint( $limit ) );
	}
}
