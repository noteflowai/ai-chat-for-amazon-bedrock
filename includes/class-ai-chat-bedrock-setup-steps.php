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
					: __( 'Request model access in your AWS account, then select the model here.', 'ai-chat-for-amazon-bedrock' ),
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
				'help'  => __( 'Add the Amazon Bedrock Chat block to a page, use the [ai_chat_bedrock] shortcode, or switch on the floating button.', 'ai-chat-for-amazon-bedrock' ),
				'url'   => admin_url( 'post-new.php?post_type=page' ),
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
				'url'   => $settings . '&tab=chat',
			);
		}

		if ( empty( $options['fallback_model_id'] ) ) {
			$next[] = array(
				'label' => __( 'Pick a fallback model', 'ai-chat-for-amazon-bedrock' ),
				'help'  => __( 'Used automatically when the main model is throttled or unavailable, so the chat keeps working.', 'ai-chat-for-amazon-bedrock' ),
				'url'   => $settings,
			);
		}

		// Guest chat spends money for anyone who visits, so the limit matters more.
		if ( ! empty( $options['allow_public_chat'] ) ) {
			$overrides = class_exists( 'AI_Chat_Bedrock_Rate_Limits' ) ? AI_Chat_Bedrock_Rate_Limits::all() : array();
			if ( ! isset( $overrides[ AI_Chat_Bedrock_Rate_Limits::GUEST_KEY ] ) ) {
				$next[] = array(
					'label' => __( 'Set a limit for visitors who are not signed in', 'ai-chat-for-amazon-bedrock' ),
					'help'  => __( 'Guest chat is on. A per-role limit for guests caps what an anonymous visitor can spend.', 'ai-chat-for-amazon-bedrock' ),
					'url'   => $settings . '&tab=chat',
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
