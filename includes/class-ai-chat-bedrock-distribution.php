<?php
/**
 * A record of where each post has been published outside the site.
 *
 * A post is often published again elsewhere: a lesson as a video on Bilibili and YouTube, an
 * article as a note on Xiaohongshu. Whatever does the publishing, a person or an agent working
 * in the platforms' own creator tools, the site is where the post lives, so the site keeps the
 * record: which platform and account, the item's ID and address, the language, and whether it
 * is public, private or replaced by a newer edition.
 *
 * Agents read a post's publishing package and write back what they did through the Abilities
 * API and the plugin's MCP server, with the WordPress permissions of the account they use. The
 * plugin never signs in to Bilibili or Xiaohongshu itself: neither offers a publishing API to
 * individual creators, and driving their sites from WordPress would mean keeping someone's
 * login on the server. YouTube has an API, which AI_Chat_Bedrock_YouTube uses.
 *
 * Off until enabled. Nothing here is shown to visitors unless the links are turned on too.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Distribution {

	const META = '_aicfab_distribution';

	// Records kept per post, newest first; the oldest go when there are more.
	const MAX_ENTRIES = 50;

	const STATUSES = array( 'planned', 'submitted', 'processing', 'public', 'unlisted', 'private', 'replaced', 'removed', 'failed' );

	// Statuses in which an item can be watched by anyone with the link, and so is linked.
	const VISIBLE = array( 'public' );

	/**
	 * Whether the publishing record is on.
	 *
	 * @param array|null $options Settings; the saved ones when omitted.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		$options = self::options( $options );
		return ! empty( $options['distribution_enabled'] );
	}

	/**
	 * Whether posts show links to where they are published.
	 *
	 * @param array|null $options Settings.
	 * @return bool
	 */
	public static function links_enabled( $options = null ) {
		$options = self::options( $options );
		return self::enabled( $options ) && ! empty( $options['distribution_links'] );
	}

	/**
	 * The platforms a record can name, with how their items are recognised.
	 *
	 * @return array Map of platform to label, the hosts of its addresses and the shape of its IDs.
	 */
	public static function platforms() {
		$platforms = array(
			'bilibili'    => array(
				'label' => __( 'Bilibili', 'ai-chat-for-amazon-bedrock' ),
				'hosts' => array( 'www.bilibili.com', 'bilibili.com', 'm.bilibili.com', 'b23.tv' ),
				'id'    => '/^BV[0-9A-Za-z]{10}$/',
			),
			'youtube'     => array(
				'label' => __( 'YouTube', 'ai-chat-for-amazon-bedrock' ),
				'hosts' => array( 'www.youtube.com', 'youtube.com', 'm.youtube.com', 'youtu.be' ),
				'id'    => '/^[A-Za-z0-9_-]{11}$/',
			),
			'xiaohongshu' => array(
				'label' => __( 'Xiaohongshu', 'ai-chat-for-amazon-bedrock' ),
				'hosts' => array( 'www.xiaohongshu.com', 'xiaohongshu.com', 'xhslink.com' ),
				'id'    => '/^[0-9a-f]{24}$/',
			),
			// A draft or article's media_id; the published article's address once it is out.
			'wechat'      => array(
				'label' => __( 'WeChat Official Account', 'ai-chat-for-amazon-bedrock' ),
				'hosts' => array( 'mp.weixin.qq.com' ),
				'id'    => '/^[A-Za-z0-9_-]{8,128}$/',
			),
		);

		/**
		 * Platforms a publishing record can name.
		 *
		 * @param array $platforms Map of platform key to label, hosts and an ID pattern.
		 */
		return (array) apply_filters( 'ai_chat_bedrock_distribution_platforms', $platforms );
	}

	/**
	 * The records of one post, newest first.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public static function entries( $post_id ) {
		$entries = get_post_meta( absint( $post_id ), self::META, true );
		return is_array( $entries ) ? array_values( array_filter( $entries, 'is_array' ) ) : array();
	}

	/**
	 * Record, or update, where a post was published.
	 *
	 * A record is found again by its platform and item ID, so reporting the same item twice
	 * updates it. Naming the record it replaces marks that one replaced, the way a new edition
	 * of a video takes the place of the old one.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $input   Platform, item_id, url, and optionally account, language, status,
	 *                        title, version, replaces and note.
	 * @param string $source Who reported it: agent, youtube or manual.
	 * @return array|WP_Error The saved record.
	 */
	public static function record( $post_id, $input, $source = 'agent' ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || 'revision' === $post->post_type ) {
			return new WP_Error( 'aicfab_distribution_post', __( 'There is no such post.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		$entry = self::clean( is_array( $input ) ? $input : array() );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$entry['source'] = in_array( $source, array( 'agent', 'youtube', 'wechat', 'manual' ), true ) ? $source : 'agent';

		$entries = self::entries( $post->ID );
		$now     = time();
		$found   = false;
		foreach ( $entries as $index => $existing ) {
			if ( isset( $existing['key'] ) && $existing['key'] === $entry['key'] ) {
				$entry['recorded_at'] = isset( $existing['recorded_at'] ) ? (int) $existing['recorded_at'] : $now;
				$entries[ $index ]    = array_merge( $existing, array_filter( $entry, array( __CLASS__, 'is_set' ) ), array( 'updated_at' => $now ) );
				$entry                = $entries[ $index ];
				$found                = true;
				break;
			}
		}
		if ( ! $found ) {
			$entry['status']      = '' !== $entry['status'] ? $entry['status'] : 'submitted';
			$entry['recorded_at'] = $now;
			$entry['updated_at']  = $now;
			array_unshift( $entries, $entry );
		}

		// The edition this one replaces is marked as such, and points to its successor.
		if ( '' !== $entry['replaces'] ) {
			foreach ( $entries as $index => $existing ) {
				if ( isset( $existing['key'] ) && $existing['key'] === $entry['replaces'] && $existing['key'] !== $entry['key'] ) {
					$entries[ $index ]['replaced_by'] = $entry['key'];
					if ( in_array( $existing['status'], array( 'public', 'unlisted', 'submitted', 'processing' ), true ) ) {
						$entries[ $index ]['status'] = 'replaced';
					}
					$entries[ $index ]['updated_at'] = $now;
				}
			}
		}

		update_post_meta( $post->ID, self::META, array_slice( $entries, 0, self::MAX_ENTRIES ) );
		return $entry;
	}

	/**
	 * A record as given, checked field by field.
	 *
	 * @param array $input Raw record.
	 * @return array|WP_Error
	 */
	public static function clean( $input ) {
		$platforms = self::platforms();
		$platform  = isset( $input['platform'] ) ? sanitize_key( (string) $input['platform'] ) : '';
		if ( ! isset( $platforms[ $platform ] ) ) {
			return new WP_Error( 'aicfab_distribution_platform', __( 'The platform is not one the record knows.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		$rules = $platforms[ $platform ];

		$item = isset( $input['item_id'] ) ? trim( (string) $input['item_id'] ) : '';
		if ( '' === $item || ( ! empty( $rules['id'] ) && ! preg_match( $rules['id'], $item ) ) ) {
			return new WP_Error( 'aicfab_distribution_item', __( 'The item ID does not look like one from that platform.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}

		$given = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
		$url   = '' !== $given ? esc_url_raw( $given, array( 'https' ) ) : '';
		if ( '' !== $given && '' === $url ) {
			return new WP_Error( 'aicfab_distribution_url', __( 'The address is not on that platform, or not https.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		if ( '' !== $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( ! in_array( $host, (array) $rules['hosts'], true ) ) {
				return new WP_Error( 'aicfab_distribution_url', __( 'The address is not on that platform, or not https.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
			}
		}

		// No status leaves a record's status as it was; a new record starts as submitted.
		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';
		if ( '' !== $status && ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'aicfab_distribution_status', __( 'The status is not one the record knows.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}

		$replaces = isset( $input['replaces'] ) ? trim( (string) $input['replaces'] ) : '';
		$version  = isset( $input['version'] ) ? trim( (string) $input['version'] ) : '';

		return array(
			'key'      => $platform . ':' . $item,
			'platform' => $platform,
			'item_id'  => $item,
			'url'      => '' !== $url ? $url : self::default_url( $platform, $item ),
			'account'  => isset( $input['account'] ) ? AI_Chat_Bedrock_Security::string_substr( sanitize_text_field( (string) $input['account'] ), 0, 100 ) : '',
			'language' => isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '',
			'status'   => $status,
			'title'    => isset( $input['title'] ) ? AI_Chat_Bedrock_Security::string_substr( sanitize_text_field( (string) $input['title'] ), 0, 200 ) : '',
			// An identity of the edition published, such as the hash of the video file.
			'version'  => preg_match( '/^[A-Za-z0-9._:-]{1,80}$/', $version ) ? $version : '',
			'replaces' => preg_match( '/^[a-z0-9_-]+:[A-Za-z0-9_-]+$/', $replaces ) ? $replaces : '',
			'note'     => isset( $input['note'] ) ? AI_Chat_Bedrock_Security::string_substr( sanitize_textarea_field( (string) $input['note'] ), 0, 500 ) : '',
		);
	}

	/**
	 * The usual address of an item, for a record that gave none.
	 *
	 * @param string $platform Platform.
	 * @param string $item     Item ID.
	 * @return string
	 */
	private static function default_url( $platform, $item ) {
		$addresses = array(
			'bilibili'    => 'https://www.bilibili.com/video/%s/',
			'youtube'     => 'https://www.youtube.com/watch?v=%s',
			'xiaohongshu' => 'https://www.xiaohongshu.com/explore/%s',
		);
		return isset( $addresses[ $platform ] ) ? sprintf( $addresses[ $platform ], rawurlencode( $item ) ) : '';
	}

	private static function is_set( $value ) {
		return '' !== $value && null !== $value;
	}

	/**
	 * What an agent needs to publish a post elsewhere.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $language Language of the edition wanted, for a translated post.
	 * @return array|WP_Error
	 */
	public static function package( $post_id, $language = '' ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || 'revision' === $post->post_type ) {
			return new WP_Error( 'aicfab_distribution_post', __( 'There is no such post.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		$language = sanitize_key( (string) $language );
		if ( '' !== $language && function_exists( 'pll_get_post' ) ) {
			$translated = (int) pll_get_post( $post->ID, $language );
			$post       = $translated > 0 ? get_post( $translated ) : $post;
		}

		// The text as a signed-out visitor reads it, so a section kept for members is never
		// handed to an agent to publish somewhere public.
		$text = class_exists( 'AI_Chat_Bedrock_Content' ) ? AI_Chat_Bedrock_Content::public_text( $post ) : '';
		$tags = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
		$cats = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'names' ) );

		$translations = array();
		if ( function_exists( 'pll_get_post_translations' ) ) {
			foreach ( (array) pll_get_post_translations( $post->ID ) as $slug => $id ) {
				$translations[ sanitize_key( (string) $slug ) ] = array(
					'id'  => (int) $id,
					'url' => (string) get_permalink( (int) $id ),
				);
			}
		}

		return array(
			'id'           => $post->ID,
			'status'       => $post->post_status,
			'title'        => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'          => (string) get_permalink( $post ),
			'language'     => class_exists( 'AI_Chat_Bedrock_Content' ) ? AI_Chat_Bedrock_Content::language( $post ) : '',
			'excerpt'      => trim( wp_strip_all_tags( (string) get_the_excerpt( $post ) ) ),
			'text'         => AI_Chat_Bedrock_Security::string_substr( (string) $text, 0, 20000 ),
			'tags'         => is_wp_error( $tags ) ? array() : array_values( $tags ),
			'categories'   => is_wp_error( $cats ) ? array() : array_values( $cats ),
			'image'        => (string) get_the_post_thumbnail_url( $post, 'full' ),
			'modified'     => (string) $post->post_modified_gmt,
			'translations' => $translations,
			'published'    => self::entries( $post->ID ),
		);
	}

	/**
	 * Records across posts, filtered.
	 *
	 * @param array $filters Platform, status, language, post_id and limit.
	 * @return array
	 */
	public static function search( $filters ) {
		$filters  = is_array( $filters ) ? $filters : array();
		$platform = isset( $filters['platform'] ) ? sanitize_key( (string) $filters['platform'] ) : '';
		$status   = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
		$language = isset( $filters['language'] ) ? sanitize_key( (string) $filters['language'] ) : '';
		$limit    = isset( $filters['limit'] ) ? max( 1, min( 200, absint( $filters['limit'] ) ) ) : 50;
		$ids      = ! empty( $filters['post_id'] ) ? array( absint( $filters['post_id'] ) ) : get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only posts that have a record, which is what is asked for.
				'meta_key'       => self::META,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$found = array();
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			foreach ( self::entries( $id ) as $entry ) {
				if ( ( '' !== $platform && $entry['platform'] !== $platform ) || ( '' !== $status && $entry['status'] !== $status ) || ( '' !== $language && $entry['language'] !== $language ) ) {
					continue;
				}
				$found[] = array(
					'post_id'    => (int) $id,
					'post_title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				) + $entry;
				if ( count( $found ) >= $limit ) {
					break 2;
				}
			}
		}
		return array( 'publications' => $found );
	}

	/**
	 * Register the abilities agents use, when the record is on.
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! self::enabled() ) {
			return;
		}
		$schemas = self::schemas();

		wp_register_ability(
			'ai-chat-bedrock/get-publish-package',
			array(
				'label'               => __( 'Get a post\'s publishing package', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Return what is needed to publish a post on another platform: title, address, plain text, excerpt, tags, image, translations and where it is already published. Read only.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => $schemas['get_publish_package'],
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_package' ),
				'permission_callback' => array( $this, 'can_edit_input_post' ),
				'category'            => AI_Chat_Bedrock_Abilities::CATEGORY,
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'public'      => true,
				),
			)
		);
		wp_register_ability(
			'ai-chat-bedrock/record-publication',
			array(
				'label'               => __( 'Record where a post was published', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Record or update one item a post was published as on Bilibili, YouTube or Xiaohongshu: its ID, address, account, language and status. Naming the record it replaces marks that edition replaced. Changes only this record, never the post.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => $schemas['record_publication'],
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_record' ),
				'permission_callback' => array( $this, 'can_edit_input_post' ),
				'category'            => AI_Chat_Bedrock_Abilities::CATEGORY,
				'meta'                => array(
					// It writes, but only to the record, and reporting the same item again updates it.
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'public'      => true,
				),
			)
		);
		wp_register_ability(
			'ai-chat-bedrock/list-publications',
			array(
				'label'               => __( 'List where posts are published', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'List publishing records across posts, filtered by platform, status, language or post, such as editions still public after being replaced. Read only.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => $schemas['list_publications'],
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'search' ),
				'permission_callback' => array( $this, 'can_list' ),
				'category'            => AI_Chat_Bedrock_Abilities::CATEGORY,
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'public'      => true,
				),
			)
		);
	}

	/**
	 * Input schemas, shared by the abilities and the MCP server.
	 *
	 * @return array
	 */
	public static function schemas() {
		$post_id = array(
			'type'        => 'integer',
			'description' => 'Post ID.',
		);
		return array(
			'get_publish_package' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'  => $post_id,
					'language' => array(
						'type'        => 'string',
						'description' => 'Language slug of the edition wanted, such as zh, en or ja, on a site with translations.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'record_publication'  => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'  => $post_id,
					'platform' => array(
						'type' => 'string',
						'enum' => array_keys( self::platforms() ),
					),
					'item_id'  => array(
						'type'        => 'string',
						'description' => 'The platform\'s ID: a Bilibili BV ID, a YouTube video ID or a Xiaohongshu note ID.',
					),
					'url'      => array(
						'type'        => 'string',
						'description' => 'The https address of the item on the platform.',
					),
					'account'  => array(
						'type'        => 'string',
						'description' => 'The account or channel it was published under.',
					),
					'language' => array( 'type' => 'string' ),
					'status'   => array(
						'type' => 'string',
						'enum' => self::STATUSES,
					),
					'title'    => array( 'type' => 'string' ),
					'version'  => array(
						'type'        => 'string',
						'description' => 'An identity of the edition, such as the SHA-256 of the video file.',
					),
					'replaces' => array(
						'type'        => 'string',
						'description' => 'The record this edition replaces, as platform:item_id.',
					),
					'note'     => array( 'type' => 'string' ),
				),
				'required'   => array( 'post_id', 'platform', 'item_id' ),
			),
			'list_publications'   => array(
				'type'       => 'object',
				'properties' => array(
					'platform' => array(
						'type' => 'string',
						'enum' => array_keys( self::platforms() ),
					),
					'status'   => array(
						'type' => 'string',
						'enum' => self::STATUSES,
					),
					'language' => array( 'type' => 'string' ),
					'post_id'  => $post_id,
					'limit'    => array( 'type' => 'integer' ),
				),
			),
		);
	}

	public function ability_package( $input ) {
		$input = is_array( $input ) ? $input : array();
		return self::package( isset( $input['post_id'] ) ? $input['post_id'] : 0, isset( $input['language'] ) ? $input['language'] : '' );
	}

	public function ability_record( $input ) {
		$input = is_array( $input ) ? $input : array();
		return self::record( isset( $input['post_id'] ) ? $input['post_id'] : 0, $input, 'agent' );
	}

	/**
	 * Permission for abilities about one post: the account must be able to edit it.
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public function can_edit_input_post( $input = array() ) {
		$id = is_array( $input ) && isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		return $id > 0 && current_user_can( 'edit_post', $id );
	}

	public function can_list() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Show the record on the post editing screen.
	 *
	 * @param string $post_type Post type.
	 */
	public function add_meta_box( $post_type ) {
		if ( ! self::box_needed() || ! post_type_supports( (string) $post_type, 'editor' ) ) {
			return;
		}
		add_meta_box( 'aicfab-distribution', __( 'Published elsewhere', 'ai-chat-for-amazon-bedrock' ), array( $this, 'render_meta_box' ), null, 'side', 'low' );
	}

	/**
	 * Whether the editing screen needs the box: for the record, or for an action in it, such
	 * as sending to the WeChat draft box or uploading to YouTube, which work with the record off.
	 *
	 * @return bool
	 */
	public static function box_needed() {
		return self::enabled()
			|| ( class_exists( 'AI_Chat_Bedrock_WeChat_Drafts' ) && AI_Chat_Bedrock_WeChat_Drafts::enabled() )
			|| ( class_exists( 'AI_Chat_Bedrock_YouTube' ) && AI_Chat_Bedrock_YouTube::ready() );
	}

	/**
	 * The record of one post, for its editing screen.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_meta_box( $post ) {
		$entries   = self::entries( $post->ID );
		$platforms = self::platforms();
		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'Not published on another platform yet. Agents record it here through the publishing abilities.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		} else {
			echo '<ul class="aicfab-distribution">';
			foreach ( $entries as $entry ) {
				$label = isset( $platforms[ $entry['platform'] ]['label'] ) ? $platforms[ $entry['platform'] ]['label'] : $entry['platform'];
				$name  = esc_html( $label . ( '' !== $entry['language'] ? ' (' . $entry['language'] . ')' : '' ) );
				// A WeChat draft has no address until it is published.
				echo '<li>' . ( '' !== $entry['url'] ? '<a href="' . esc_url( $entry['url'] ) . '" target="_blank" rel="noopener noreferrer">' . $name . '</a>' : $name ) . ' — ' . esc_html( self::status_label( $entry['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name is escaped above.
				if ( ! empty( $entry['account'] ) ) {
					echo '<br><small>' . esc_html( $entry['account'] ) . '</small>';
				}
				if ( ! empty( $entry['replaced_by'] ) ) {
					/* translators: %s: the record that replaced this one, as platform:id. */
					echo '<br><small>' . esc_html( sprintf( __( 'Replaced by %s', 'ai-chat-for-amazon-bedrock' ), $entry['replaced_by'] ) ) . '</small>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		/**
		 * Fires at the end of the Published elsewhere box, for actions such as uploading.
		 *
		 * @param WP_Post $post Post.
		 */
		do_action( 'ai_chat_bedrock_distribution_box', $post );
	}

	/**
	 * A status in words.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'planned'    => __( 'planned', 'ai-chat-for-amazon-bedrock' ),
			'submitted'  => __( 'submitted', 'ai-chat-for-amazon-bedrock' ),
			'processing' => __( 'processing', 'ai-chat-for-amazon-bedrock' ),
			'public'     => __( 'public', 'ai-chat-for-amazon-bedrock' ),
			'unlisted'   => __( 'unlisted', 'ai-chat-for-amazon-bedrock' ),
			'private'    => __( 'private', 'ai-chat-for-amazon-bedrock' ),
			'replaced'   => __( 'replaced', 'ai-chat-for-amazon-bedrock' ),
			'removed'    => __( 'removed', 'ai-chat-for-amazon-bedrock' ),
			'failed'     => __( 'failed', 'ai-chat-for-amazon-bedrock' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Links under a post to where it can also be watched or read.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function add_links( $content ) {
		if ( ! self::links_enabled() || is_feed() || ! is_singular() || ! in_the_loop() || ! is_main_query() || ( class_exists( 'AI_Chat_Bedrock_Content' ) && AI_Chat_Bedrock_Content::is_rendering() ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return $content;
		}
		$language  = class_exists( 'AI_Chat_Bedrock_Content' ) ? AI_Chat_Bedrock_Content::language( $post ) : '';
		$platforms = self::platforms();
		$links     = array();
		foreach ( self::entries( $post->ID ) as $entry ) {
			if ( ! in_array( $entry['status'], self::VISIBLE, true ) || ( '' !== $entry['language'] && '' !== $language && $entry['language'] !== $language ) || isset( $links[ $entry['platform'] ] ) ) {
				continue;
			}
			$label                       = isset( $platforms[ $entry['platform'] ]['label'] ) ? $platforms[ $entry['platform'] ]['label'] : $entry['platform'];
			$links[ $entry['platform'] ] = '<a href="' . esc_url( $entry['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
		}
		if ( empty( $links ) ) {
			return $content;
		}
		/* translators: %s: links to platforms, such as Bilibili and YouTube. */
		return $content . '<p class="aicfab-elsewhere">' . sprintf( esc_html__( 'Also on %s', 'ai-chat-for-amazon-bedrock' ), implode( ' · ', $links ) ) . '</p>';
	}

	private static function options( $options ) {
		if ( is_array( $options ) ) {
			return $options;
		}
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		return is_array( $options ) ? $options : array();
	}
}
