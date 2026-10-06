<?php
/**
 * What is set up, and what is worth doing next.
 *
 * The dashboard used to show a four step checklist whose last step was hard-coded as
 * incomplete, so it could never be finished no matter what the site did. A checklist that
 * cannot be completed tells nobody anything. Every step here is decided by looking at the
 * site, and the list continues past first-run into the things that make the chat better.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Setup_Steps {

	/**
	 * Block name and shortcode that place the chat on a page.
	 */
	const BLOCK     = 'wp:ai-chat-bedrock/chat';
	const SHORTCODE = '[ai_chat_bedrock';

	/**
	 * The action that creates the chat page, and the mark left on the page it creates.
	 */
	const CREATE_ACTION = 'ai_chat_bedrock_create_chat_page';
	const PAGE_META     = '_aicfab_chat_page';

	/**
	 * How long the published-chat lookup is cached.
	 */
	const CACHE_TTL = 300;

	/**
	 * Where the chat is on the front end, if anywhere.
	 *
	 * @param bool  $refresh Ignore the cached answer.
	 * @param array $options Settings to read, or null to load them.
	 * @return array Array with placed, post_id and how keys.
	 */
	public static function chat_placement( $refresh = false, $options = null ) {
		$cache_key = 'aicfab_chat_placement';
		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
		}
		$options = is_array( $options ) ? $options : array();

		// The floating button puts the chat on every page, so nothing else is needed.
		if ( ! empty( $options['popup_site_wide'] ) ) {
			$placement = array(
				'placed'  => true,
				'post_id' => 0,
				'how'     => 'floating',
			);
			set_transient( $cache_key, $placement, self::CACHE_TTL );
			return $placement;
		}

		global $wpdb;

		// One indexed-enough LIKE over published content. Cached, and only on this screen.
		// A LIKE against the exact block comment and shortcode. WP_Query's search does
		// word matching, which would match pages merely discussing the plugin. The result
		// is cached in the transient above for five minutes and invalidated when settings
		// or posts change, so this runs at most once per admin page load.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				WHERE post_status = 'publish'
				AND post_type NOT IN ( 'revision', 'attachment' )
				AND ( post_content LIKE %s OR post_content LIKE %s )
				ORDER BY post_modified DESC
				LIMIT 1",
				'%' . $wpdb->esc_like( self::BLOCK ) . '%',
				'%' . $wpdb->esc_like( self::SHORTCODE ) . '%'
			)
		);

		$placement = array(
			'placed'  => null !== $found,
			'post_id' => null !== $found ? (int) $found->ID : 0,
			'how'     => null === $found
				? ''
				: ( false !== strpos( (string) $found->post_content, self::BLOCK ) ? 'block' : 'shortcode' ),
		);

		set_transient( $cache_key, $placement, self::CACHE_TTL );
		return $placement;
	}

	/**
	 * Forget the cached placement.
	 *
	 * Hooked to saving settings and to content changes, because otherwise switching the
	 * floating button on would leave the checklist claiming the chat is not published
	 * until the cache expired.
	 */
	public static function flush() {
		delete_transient( 'aicfab_chat_placement' );
	}

	/**
	 * Where the chat page is created from.
	 *
	 * @return string
	 */
	public static function chat_page_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::CREATE_ACTION ), self::CREATE_ACTION );
	}

	/**
	 * Open a draft page with the chat block on it, creating it the first time.
	 *
	 * The last setup step used to open an empty page, which left the site owner to find the
	 * block. The page is a draft, so nothing goes live until it is published, and following
	 * the link again opens the same draft rather than piling up new ones.
	 */
	public static function handle_create_chat_page() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to create pages.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::CREATE_ACTION );

		$edit = self::open_chat_page();
		if ( '' === $edit ) {
			wp_die( esc_html__( 'The page could not be created. Add the Amazon Bedrock Chat block to a page instead.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 500 ) );
		}
		wp_safe_redirect( $edit );
		exit;
	}

	/**
	 * The editor for the draft chat page, created if there is none yet.
	 *
	 * @return string Edit URL, or an empty string when the page could not be created.
	 */
	public static function open_chat_page() {
		$page_id = self::draft_chat_page();
		if ( ! $page_id ) {
			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_author'  => get_current_user_id(),
					'post_title'   => _x( 'Ask us', 'title of the page the chat is on', 'ai-chat-for-amazon-bedrock' ),
					'post_content' => '<!-- ' . self::BLOCK . ' /-->',
					'meta_input'   => array( self::PAGE_META => 1 ),
				),
				true
			);
		}
		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return '';
		}
		$edit = get_edit_post_link( $page_id, 'raw' );
		return $edit ? (string) $edit : admin_url( 'edit.php?post_type=page' );
	}

	/**
	 * The chat page created earlier and not yet published, if the user may still edit it.
	 *
	 * @return int Page ID, or 0.
	 */
	private static function draft_chat_page() {
		$found   = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'draft', 'pending' ),
				'meta_key'       => self::PAGE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of pages at most carry it.
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$page_id = $found ? (int) $found[0] : 0;
		return $page_id && current_user_can( 'edit_post', $page_id ) ? $page_id : 0;
	}

	/**
	 * Whether the answer can be grounded in this site's own content.
	 *
	 * @param array $options Plugin settings.
	 * @return bool
	 */
	public static function grounding_ready( $options ) {
		$options = is_array( $options ) ? $options : array();
		if ( ! empty( $options['enable_site_context'] ) || ! empty( $options['knowledge_base_id'] ) ) {
			return true;
		}
		return class_exists( 'AI_Chat_Bedrock_Embeddings' ) && AI_Chat_Bedrock_Embeddings::enabled();
	}

	/**
	 * The essential steps, each decided from the site's own state.
	 *
	 * @param array $context Precomputed credentials, model, region and usage.
	 * @return array
	 */
	public static function essential( $context ) {
		$options     = isset( $context['options'] ) && is_array( $context['options'] ) ? $context['options'] : null;
		$credentials = isset( $context['credentials'] ) && is_array( $context['credentials'] ) ? $context['credentials'] : array();
		$model       = isset( $context['model'] ) ? (string) $context['model'] : '';
		$region_name = isset( $context['region_name'] ) ? (string) $context['region_name'] : '';
		$requests    = isset( $context['requests'] ) ? (int) $context['requests'] : 0;
		$settings    = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings' );

		$configured = ! empty( $credentials['configured'] );
		$chosen     = '' !== $model && '' !== $region_name;
		$placement  = self::chat_placement( false, $options );

		$steps = array(
			array(
				'done'  => $configured,
				'label' => __( 'Connect AWS credentials', 'ai-chat-for-amazon-bedrock' ),
				'help'  => $configured && ! empty( $credentials['message'] )
					? $credentials['message']
					: __( 'Paste an Amazon Bedrock API key for the quickest start, or use an IAM role, wp-config.php constants or encrypted access keys.', 'ai-chat-for-amazon-bedrock' ),
				'url'   => $settings,
			),
			array(
				'done'  => $chosen,
				'label' => __( 'Choose a region and model', 'ai-chat-for-amazon-bedrock' ),
				'help'  => $chosen
					? $region_name . ' · ' . $model
					: __( 'Select a region and a model. If the model is new to the AWS account, open it once in the Amazon Bedrock console playground first.', 'ai-chat-for-amazon-bedrock' ),
				'url'   => $settings,
			),
			array(
				'done'  => $requests > 0,
				'label' => __( 'Send a test message', 'ai-chat-for-amazon-bedrock' ),
				'help'  => $requests > 0
					? __( 'Amazon Bedrock has answered from this site.', 'ai-chat-for-amazon-bedrock' )
					: __( 'Confirm the model answers before putting the chat in front of visitors.', 'ai-chat-for-amazon-bedrock' ),
				'url'   => admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-test' ),
			),
		);

		// This step used to be hard-coded as never done. It now reflects the site.
		if ( $placement['placed'] && 'floating' === $placement['how'] ) {
			$steps[] = array(
				'done'  => true,
				'label' => __( 'Publish the chat', 'ai-chat-for-amazon-bedrock' ),
				'help'  => __( 'The floating button shows the chat on every page.', 'ai-chat-for-amazon-bedrock' ),
				'url'   => $settings,
			);
		} elseif ( $placement['placed'] ) {
			$steps[] = array(
				'done'  => true,
				'label' => __( 'Publish the chat', 'ai-chat-for-amazon-bedrock' ),
				'help'  => 'block' === $placement['how']
					? __( 'The chat block is on a published page.', 'ai-chat-for-amazon-bedrock' )
					: __( 'The shortcode is on a published page.', 'ai-chat-for-amazon-bedrock' ),
				'url'   => get_edit_post_link( $placement['post_id'], 'raw' ) ? get_edit_post_link( $placement['post_id'], 'raw' ) : $settings,
			);
		} else {
			$steps[] = array(
				'done'  => false,
				'label' => __( 'Publish the chat', 'ai-chat-for-amazon-bedrock' ),
				'help'  => __( 'Opens a draft page with the chat on it, ready to publish. Or add the Amazon Bedrock Chat block to any page, use the [ai_chat_bedrock] shortcode, or switch on the floating button.', 'ai-chat-for-amazon-bedrock' ),
				'url'   => self::chat_page_url(),
			);
		}

		return $steps;
	}

	/**
	 * Worthwhile next steps once the chat works.
	 *
	 * Only things that change the answer quality or the running of it. Each one is
	 * suggested only while it is still worth doing.
	 *
	 * @param array $context Precomputed state.
	 * @return array
	 */
	public static function next( $context ) {
		$options  = isset( $context['options'] ) && is_array( $context['options'] ) ? $context['options'] : array();
		$settings = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings' );
		$next     = array();

		if ( ! self::grounding_ready( $options ) ) {
			$next[] = array(
				'label' => __( 'Ground answers in your own content', 'ai-chat-for-amazon-bedrock' ),
				'help'  => __( 'Without this the model answers from general knowledge and cannot cite your pages.', 'ai-chat-for-amazon-bedrock' ),
				'url'   => $settings . '&tab=knowledge',
			);
		}

		if ( class_exists( 'AI_Chat_Bedrock_Conversations' ) && ! AI_Chat_Bedrock_Conversations::enabled() ) {
			$next[] = array(
				'label' => __( 'Record conversations to see what visitors ask', 'ai-chat-for-amazon-bedrock' ),
				'help'  => __( 'Off by default. With it on, the Conversations screen reports the questions your site does not answer. Disclose the recording to visitors.', 'ai-chat-for-amazon-bedrock' ),
				'url'   => $settings . '&tab=governance',
			);
		}

		if ( empty( $options['fallback_model_id'] ) ) {
			$next[] = array(
				'label' => __( 'Pick a fallback model', 'ai-chat-for-amazon-bedrock' ),
				'help'  => __( 'Used automatically when the main model is throttled or unavailable, so the chat keeps working.', 'ai-chat-for-amazon-bedrock' ),
				'url'   => $settings,
			);
		}

		// Other plugins can answer with their own live data, through the abilities they register.
		$sources = isset( $context['ability_sources'] ) ? array_filter( (array) $context['ability_sources'], 'is_string' ) : array();
		if ( ! empty( $sources ) && ( empty( $options['abilities_tools'] ) || ! get_option( 'ai_chat_bedrock_enable_mcp', false ) ) ) {
			$next[] = array(
				'label' => __( 'Let the chat use your plugins\' abilities', 'ai-chat-for-amazon-bedrock' ),
				'help'  => sprintf(
					/* translators: %s: names of plugins. */
					__( '%s register abilities with WordPress. Signed-in users can then get answers from their live data. Read-only abilities are offered, and ones that change data stay off until you allow them.', 'ai-chat-for-amazon-bedrock' ),
					AI_Chat_Bedrock_Translation::items( array_slice( $sources, 0, 4 ) )
				),
				'url'   => $settings . '&tab=agents',
			);
		}

		// Guest chat spends money for anyone who visits, so the limit matters more.
		if ( ! empty( $options['allow_public_chat'] ) ) {
			$overrides = class_exists( 'AI_Chat_Bedrock_Rate_Limits' ) ? AI_Chat_Bedrock_Rate_Limits::all() : array();
			if ( ! isset( $overrides[ AI_Chat_Bedrock_Rate_Limits::GUEST_KEY ] ) ) {
				$next[] = array(
					'label' => __( 'Set a limit for visitors who are not signed in', 'ai-chat-for-amazon-bedrock' ),
					'help'  => __( 'Guest chat is on. A per-role limit for guests caps what an anonymous visitor can spend.', 'ai-chat-for-amazon-bedrock' ),
					'url'   => $settings . '&tab=governance',
				);
			}
		}

		return $next;
	}

	/**
	 * How far along the essential steps are.
	 *
	 * @param array $steps Steps from essential().
	 * @return array Array with done, total and complete keys.
	 */
	public static function progress( $steps ) {
		$steps = is_array( $steps ) ? $steps : array();
		$done  = 0;
		foreach ( $steps as $step ) {
			if ( ! empty( $step['done'] ) ) {
				++$done;
			}
		}
		return array(
			'done'     => $done,
			'total'    => count( $steps ),
			'complete' => count( $steps ) > 0 && count( $steps ) === $done,
		);
	}
}
