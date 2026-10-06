<?php
/**
 * Administration screens and settings.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Admin {
	/**
	 * Field identifiers grouped by settings tab page.
	 *
	 * @var array
	 */
	private static $tab_fields = array();

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function enqueue_styles( $hook_suffix ) {
		if ( ! $this->is_plugin_screen( $hook_suffix ) ) {
			return;
		}
		wp_enqueue_style( $this->admin_handle(), plugin_dir_url( __FILE__ ) . 'css/ai-chat-bedrock-admin.css', array(), $this->version );
		if ( false !== strpos( $hook_suffix, $this->plugin_name . '-mcp' ) ) {
			wp_enqueue_style( $this->plugin_name . '-mcp', plugin_dir_url( __FILE__ ) . 'css/ai-chat-bedrock-mcp.css', array(), $this->version );
		}
	}

	public function enqueue_scripts( $hook_suffix ) {
		if ( ! $this->is_plugin_screen( $hook_suffix ) ) {
			return;
		}
		wp_enqueue_script( $this->admin_handle(), plugin_dir_url( __FILE__ ) . 'js/ai-chat-bedrock-admin.js', array( 'jquery', 'common', 'wp-a11y' ), $this->version, true );

		if ( false !== strpos( (string) $hook_suffix, $this->plugin_name . '-eval' ) ) {
			wp_enqueue_script( $this->plugin_name . '-eval', plugin_dir_url( __FILE__ ) . 'js/ai-chat-bedrock-eval.js', array(), $this->version, true );
			wp_localize_script(
				$this->plugin_name . '-eval',
				'AICFABEval',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'aicfab_eval' ),
					'i18n'    => array(
						'question'       => __( 'Question', 'ai-chat-for-amazon-bedrock' ),
						'expect'         => __( 'Expect', 'ai-chat-for-amazon-bedrock' ),
						'mustInclude'    => __( 'Must include, comma separated', 'ai-chat-for-amazon-bedrock' ),
						'mustNotInclude' => __( 'Must not include, comma separated', 'ai-chat-for-amazon-bedrock' ),
						'cite'           => __( 'Page the answer should credit', 'ai-chat-for-amazon-bedrock' ),
						'minRelevance'   => __( 'Minimum match, 0 to 1', 'ai-chat-for-amazon-bedrock' ),
						'tokenCeiling'   => __( 'Output token ceiling', 'ai-chat-for-amazon-bedrock' ),
						'remove'         => __( 'Remove', 'ai-chat-for-amazon-bedrock' ),
						'removed'        => __( 'Case removed. Save to keep the change.', 'ai-chat-for-amazon-bedrock' ),
						'saving'         => __( 'Saving cases…', 'ai-chat-for-amazon-bedrock' ),
						'saved'          => __( 'Cases saved.', 'ai-chat-for-amazon-bedrock' ),
						/* translators: %d: number of cases that were dropped. */
						'savedWithDrops' => __( 'Cases saved. %d could not be run and were dropped: a question and an expectation are both required.', 'ai-chat-for-amazon-bedrock' ),
						'proposing'      => __( 'Reading recorded questions…', 'ai-chat-for-amazon-bedrock' ),
						/* translators: %d: number of proposed questions. */
						'proposed'       => __( '%d question(s) proposed. Add the ones you want, then state what a good answer contains.', 'ai-chat-for-amazon-bedrock' ),
						'addThis'        => __( 'Add as a case', 'ai-chat-for-amazon-bedrock' ),
						'addedUnsaved'   => __( 'Added to the table. Save to keep it.', 'ai-chat-for-amazon-bedrock' ),
						'running'        => __( 'Running the checks. One Amazon Bedrock request per case.', 'ai-chat-for-amazon-bedrock' ),
						'ran'            => __( 'Run finished.', 'ai-chat-for-amazon-bedrock' ),
						'failed'         => __( 'The request failed.', 'ai-chat-for-amazon-bedrock' ),
						'pass'           => __( 'Pass', 'ai-chat-for-amazon-bedrock' ),
						'fail'           => __( 'Fail', 'ai-chat-for-amazon-bedrock' ),
						'casesPassed'    => __( 'cases passed', 'ai-chat-for-amazon-bedrock' ),
						'neverChecked'   => __( 'Never checked in this run:', 'ai-chat-for-amazon-bedrock' ),
						'notComparable'  => __( 'not comparable with the previous run, the number of checks changed', 'ai-chat-for-amazon-bedrock' ),
						'sinceLastRun'   => __( 'since the previous run', 'ai-chat-for-amazon-bedrock' ),
					),
				)
			);
		}

		if ( false !== strpos( (string) $hook_suffix, $this->plugin_name . '-scaffold' ) ) {
			wp_enqueue_script( $this->plugin_name . '-scaffold', plugin_dir_url( __FILE__ ) . 'js/ai-chat-bedrock-scaffold.js', array(), $this->version, true );
			wp_localize_script(
				$this->plugin_name . '-scaffold',
				'aicfabScaffold',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'aicfab_scaffold' ),
					'i18n'    => array(
						'describeFirst' => __( 'Describe the site first.', 'ai-chat-for-amazon-bedrock' ),
						'thinking'      => __( 'Working out which pages this site needs…', 'ai-chat-for-amazon-bedrock' ),
						'writing'       => __( 'Writing…', 'ai-chat-for-amazon-bedrock' ),
						'skippedByYou'  => __( 'Skipped.', 'ai-chat-for-amazon-bedrock' ),
						'editDraft'     => __( 'Edit the draft', 'ai-chat-for-amazon-bedrock' ),
						/* translators: 1: pages handled so far, 2: pages in total. */
						'progress'      => __( '%1$d of %2$d done…', 'ai-chat-for-amazon-bedrock' ),
						/* translators: 1: drafts created, 2: pages skipped, 3: pages that failed. */
						'finished'      => __( 'Finished. Drafts created: %1$d. Skipped: %2$d. Failed: %3$d.', 'ai-chat-for-amazon-bedrock' ),
						'unexpected'    => __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' ),
						'includeLabel'  => __( 'Include', 'ai-chat-for-amazon-bedrock' ),
						'titleLabel'    => __( 'Page title', 'ai-chat-for-amazon-bedrock' ),
					),
				)
			);
		}
		wp_localize_script(
			$this->admin_handle(),
			'ai_chat_bedrock_admin',
			array(
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'ai_chat_bedrock_admin' ),
				'mcp_nonce'    => wp_create_nonce( 'ai_chat_bedrock_mcp_nonce' ),
				's3v_nonce'    => wp_create_nonce( 'ai_chat_bedrock_s3_vectors' ),
				'rest_nonce'   => wp_create_nonce( 'wp_rest' ),
				'generate_url' => rest_url( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . AI_Chat_Bedrock_Generator_Stream::REST_ROUTE ),
				'i18n'         => array(
					'settings_saved'        => __( 'Setting saved.', 'ai-chat-for-amazon-bedrock' ),
					'generating'            => __( 'Writing the draft…', 'ai-chat-for-amazon-bedrock' ),
					'indexing'              => __( 'Indexing…', 'ai-chat-for-amazon-bedrock' ),
					'index_button'          => __( 'Index content now', 'ai-chat-for-amazon-bedrock' ),
					/* translators: 1: items indexed so far, 2: items still pending. */
					'index_progress'        => __( '%1$d indexed, %2$d remaining…', 'ai-chat-for-amazon-bedrock' ),
					/* translators: 1: items indexed, 2: items skipped, 3: items that failed. */
					'index_done'            => __( 'Finished: %1$d indexed, %2$d already current, %3$d failed.', 'ai-chat-for-amazon-bedrock' ),
					/* translators: %s: title the model chose for the draft. */
					'generating_titled'     => __( 'Writing “%s”…', 'ai-chat-for-amazon-bedrock' ),
					'generate_button'       => __( 'Generate draft', 'ai-chat-for-amazon-bedrock' ),
					'generate_topic'        => __( 'Enter a topic first.', 'ai-chat-for-amazon-bedrock' ),
					/* translators: 1: draft title, 2: word count. */
					'generate_done'         => __( 'Draft "%1$s" created with about %2$d words.', 'ai-chat-for-amazon-bedrock' ),
					'generate_edit'         => __( 'Open the draft', 'ai-chat-for-amazon-bedrock' ),
					/* translators: %s: model identifier that answered instead of the main model. */
					'generate_fallback'     => __( 'The main model was unavailable, so %s wrote this draft.', 'ai-chat-for-amazon-bedrock' ),
					'ajax_error'            => __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' ),
					'testing'               => __( 'Testing…', 'ai-chat-for-amazon-bedrock' ),
					'status_pass'           => __( 'Pass', 'ai-chat-for-amazon-bedrock' ),
					'status_warn'           => __( 'Review', 'ai-chat-for-amazon-bedrock' ),
					'status_fail'           => __( 'Action required', 'ai-chat-for-amazon-bedrock' ),
					'no_servers'            => __( 'No MCP servers registered.', 'ai-chat-for-amazon-bedrock' ),
					'missing_fields'        => __( 'A server name and HTTPS URL are required.', 'ai-chat-for-amazon-bedrock' ),
					'adding'                => __( 'Adding…', 'ai-chat-for-amazon-bedrock' ),
					'add_server'            => __( 'Add server', 'ai-chat-for-amazon-bedrock' ),
					'removing'              => __( 'Removing…', 'ai-chat-for-amazon-bedrock' ),
					'remove'                => __( 'Remove', 'ai-chat-for-amazon-bedrock' ),
					'refreshing'            => __( 'Refreshing…', 'ai-chat-for-amazon-bedrock' ),
					'refresh'               => __( 'Refresh', 'ai-chat-for-amazon-bedrock' ),
					'loading_tools'         => __( 'Loading tools…', 'ai-chat-for-amazon-bedrock' ),
					'no_tools'              => __( 'No tools found.', 'ai-chat-for-amazon-bedrock' ),
					'no_parameters'         => __( 'No parameters required.', 'ai-chat-for-amazon-bedrock' ),
					'available'             => __( 'Available', 'ai-chat-for-amazon-bedrock' ),
					'unavailable'           => __( 'Unavailable', 'ai-chat-for-amazon-bedrock' ),
					'confirm_remove_server' => __( 'Remove this MCP server?', 'ai-chat-for-amazon-bedrock' ),
					'view_tools'            => __( 'View tools', 'ai-chat-for-amazon-bedrock' ),
					'parameters'            => __( 'Parameters', 'ai-chat-for-amazon-bedrock' ),
				),
			)
		);
		if ( false !== strpos( $hook_suffix, $this->plugin_name . '-mcp' ) ) {
			wp_enqueue_script( $this->plugin_name . '-mcp', plugin_dir_url( __FILE__ ) . 'js/ai-chat-bedrock-mcp.js', array( 'jquery', $this->admin_handle() ), $this->version, true );
		}
	}

	public function add_plugin_admin_menu() {
		add_menu_page( __( 'AI Chatbot & Agents for Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ), __( 'AI Chat Bedrock', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name, array( $this, 'display_plugin_admin_page' ), 'dashicons-format-chat', 100 );
		// The first item opens the menu's own page; named for what it is, not the plugin again.
		add_submenu_page( $this->plugin_name, __( 'Overview', 'ai-chat-for-amazon-bedrock' ), __( 'Overview', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name, array( $this, 'display_plugin_admin_page' ) );
		// Daily work first: trying the chat, reading what visitors asked and what they need.
		add_submenu_page( $this->plugin_name, __( 'Test Chat', 'ai-chat-for-amazon-bedrock' ), __( 'Test Chat', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-test', array( $this, 'display_plugin_admin_test_page' ) );
		add_submenu_page( $this->plugin_name, __( 'Conversations', 'ai-chat-for-amazon-bedrock' ), __( 'Conversations', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-conversations', array( $this, 'display_plugin_admin_conversations_page' ) );
		// Listed while requests are taken, or while any are still stored.
		$waiting = AI_Chat_Bedrock_Leads::enabled() ? AI_Chat_Bedrock_Leads::waiting() : 0;
		if ( AI_Chat_Bedrock_Leads::enabled() || AI_Chat_Bedrock_Leads::query( array( 'per_page' => 1 ) )['total'] > 0 ) {
			$bubble = $waiting > 0 ? ' <span class="awaiting-mod count-' . (int) $waiting . '"><span class="pending-count">' . esc_html( number_format_i18n( $waiting ) ) . '</span></span>' : '';
			add_submenu_page( $this->plugin_name, __( 'Contact requests', 'ai-chat-for-amazon-bedrock' ), __( 'Contact requests', 'ai-chat-for-amazon-bedrock' ) . $bubble, AI_Chat_Bedrock_Leads::CAPABILITY, $this->plugin_name . '-leads', array( $this, 'display_plugin_admin_leads_page' ) );
		}
		add_submenu_page( $this->plugin_name, __( 'Answer checks', 'ai-chat-for-amazon-bedrock' ), __( 'Answer checks', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_Eval::CAPABILITY, $this->plugin_name . '-eval', array( $this, 'display_plugin_admin_eval_page' ) );
		if ( AI_Chat_Bedrock_Metrics::enabled() ) {
			add_submenu_page( $this->plugin_name, __( 'Business insights', 'ai-chat-for-amazon-bedrock' ), __( 'Business insights', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_Metrics::CAPABILITY, $this->plugin_name . '-metrics', array( $this, 'display_plugin_admin_metrics_page' ) );
		}
		// Making content.
		add_submenu_page( $this->plugin_name, __( 'Content Generator', 'ai-chat-for-amazon-bedrock' ), __( 'Content Generator', 'ai-chat-for-amazon-bedrock' ), 'edit_posts', $this->plugin_name . '-generator', array( $this, 'display_plugin_admin_generator_page' ) );
		add_submenu_page( $this->plugin_name, __( 'Site Pages', 'ai-chat-for-amazon-bedrock' ), __( 'Site Pages', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_Scaffold::CAPABILITY, $this->plugin_name . '-scaffold', array( $this, 'display_plugin_admin_scaffold_page' ) );
		if ( class_exists( 'AI_Chat_Bedrock_YouTube' ) ) {
			AI_Chat_Bedrock_YouTube::add_page( $this->plugin_name );
		}
		// Setting it up.
		add_submenu_page( $this->plugin_name, __( 'Chat Profiles', 'ai-chat-for-amazon-bedrock' ), __( 'Chat Profiles', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-profiles', array( $this, 'display_plugin_admin_profiles_page' ) );
		add_submenu_page( $this->plugin_name, __( 'MCP Settings', 'ai-chat-for-amazon-bedrock' ), __( 'MCP Settings', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-mcp', array( $this, 'display_plugin_admin_mcp_page' ) );
		add_submenu_page( $this->plugin_name, __( 'Settings', 'ai-chat-for-amazon-bedrock' ), __( 'Settings', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-settings', array( $this, 'display_plugin_admin_settings_page' ) );
		add_submenu_page( $this->plugin_name, __( 'Diagnostics', 'ai-chat-for-amazon-bedrock' ), __( 'Diagnostics', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-diagnostics', array( $this, 'display_plugin_admin_diagnostics_page' ) );
	}

	/**
	 * Contact requests screen.
	 */
	public function display_plugin_admin_leads_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-leads.php';
	}

	public function display_plugin_admin_generator_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-generator.php';
	}

	/**
	 * Answer checks screen.
	 *
	 * @return void
	 */
	public function display_plugin_admin_eval_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-eval.php';
	}

	public function display_plugin_admin_scaffold_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-scaffold.php';
	}

	public function display_plugin_admin_profiles_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-profiles.php';
	}

	public function display_plugin_admin_conversations_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-conversations.php';
	}

	public function display_plugin_admin_diagnostics_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-diagnostics.php';
	}

	/**
	 * Business insights screen.
	 *
	 * @return void
	 */
	public function display_plugin_admin_metrics_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-metrics.php';
	}

	/**
	 * Offer alt text generation from the media library list view.
	 *
	 * @param array   $actions Existing actions.
	 * @param WP_Post $post    Attachment.
	 * @return array
	 */
	public function add_media_row_action( $actions, $post ) {
		if ( ! AI_Chat_Bedrock_Media_Assistant::enabled() || ! $post instanceof WP_Post ) {
			return $actions;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		if ( ! in_array( (string) get_post_mime_type( $post ), AI_Chat_Bedrock_Media_Assistant::supported_types(), true ) ) {
			return $actions;
		}

		$url                        = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'ai_chat_bedrock_alt_text',
					'attachment' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'ai_chat_bedrock_alt_text_' . $post->ID
		);
		$actions['aicfab_alt_text'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Generate alt text', 'ai-chat-for-amazon-bedrock' ) . '</a>';
		return $this->add_image_edit_actions( $actions, $post );
	}

	/**
	 * Offer background removal and upscaling for an image.
	 *
	 * Upscaling is offered only for images the upscaler takes, at most about one megapixel.
	 *
	 * @param array   $actions Existing actions.
	 * @param WP_Post $post    Attachment.
	 * @return array
	 */
	private function add_image_edit_actions( $actions, $post ) {
		if ( ! AI_Chat_Bedrock_Images::editing_enabled() || ! current_user_can( 'upload_files' ) ) {
			return $actions;
		}
		if ( ! in_array( (string) get_post_mime_type( $post ), AI_Chat_Bedrock_Images::editable_types(), true ) ) {
			return $actions;
		}

		$edits = array(
			'remove_background' => __( 'Remove background', 'ai-chat-for-amazon-bedrock' ),
		);
		$meta  = wp_get_attachment_metadata( $post->ID );
		if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) && (int) $meta['width'] * (int) $meta['height'] <= AI_Chat_Bedrock_Images::UPSCALE_MAX_PIXELS ) {
			$edits['upscale'] = __( 'Upscale 4×', 'ai-chat-for-amazon-bedrock' );
		}
		foreach ( $edits as $edit => $label ) {
			$url                          = wp_nonce_url(
				add_query_arg(
					array(
						'action'     => 'ai_chat_bedrock_edit_image',
						'edit'       => $edit,
						'attachment' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'ai_chat_bedrock_edit_image_' . $edit . '_' . $post->ID
			);
			$actions[ 'aicfab_' . $edit ] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Remove the background of an image, or upscale it, into a new attachment.
	 *
	 * The outcome is kept for the current user for a minute and shown on the Media Library,
	 * so an error message never travels in the URL.
	 */
	public function handle_edit_image_action() {
		$attachment = isset( $_GET['attachment'] ) ? absint( wp_unslash( $_GET['attachment'] ) ) : 0;
		$edit       = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		if ( ! in_array( $edit, array( 'remove_background', 'upscale' ), true ) ) {
			wp_die( esc_html__( 'Unknown image edit.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_edit_image_' . $edit . '_' . $attachment );

		if ( ! current_user_can( 'edit_post', $attachment ) || ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		if ( ! AI_Chat_Bedrock_Images::editing_enabled() ) {
			wp_die( esc_html__( 'Image editing is off. Turn on the media helpers and choose an image model first.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 400 ) );
		}

		$result = 'upscale' === $edit ? AI_Chat_Bedrock_Images::upscale( $attachment ) : AI_Chat_Bedrock_Images::remove_background( $attachment );
		set_transient(
			'aicfab_image_edit_' . get_current_user_id(),
			is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : array( 'attachment' => (int) $result ),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( 'aicfab-image', '1', admin_url( 'upload.php' ) ) );
		exit;
	}

	/**
	 * Report the outcome of an image edit on the media screen.
	 */
	private function render_image_edit_notice() {
		if ( ! isset( $_GET['aicfab-image'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$key     = 'aicfab_image_edit_' . get_current_user_id();
		$outcome = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $outcome ) ) {
			return;
		}
		if ( ! empty( $outcome['attachment'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'The edited image was saved as a new item in the Media Library. The original is unchanged.', 'ai-chat-for-amazon-bedrock' ),
				esc_url( (string) get_edit_post_link( (int) $outcome['attachment'] ) ),
				esc_html__( 'Open it', 'ai-chat-for-amazon-bedrock' )
			);
			return;
		}
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( isset( $outcome['error'] ) ? (string) $outcome['error'] : __( 'The image could not be edited.', 'ai-chat-for-amazon-bedrock' ) )
		);
	}

	/**
	 * Index one batch of posts for semantic search.
	 */
	public function ajax_index_embeddings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
		}
		check_ajax_referer( 'ai_chat_bedrock_admin', 'nonce' );

		if ( ! AI_Chat_Bedrock_Embeddings::enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Choose an embedding model first.', 'ai-chat-for-amazon-bedrock' ) ), 400 );
		}

		wp_send_json_success( AI_Chat_Bedrock_Embeddings::index_batch() );
	}

	/**
	 * Delete every stored vector.
	 */
	public function handle_clear_embeddings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_clear_embeddings' );

		$cleared = AI_Chat_Bedrock_Embeddings::clear();
		$target  = admin_url( 'admin.php?page=' . $this->plugin_name . '-settings&tab=knowledge' );
		if ( is_wp_error( $cleared ) ) {
			// Nothing local was reset, so the index and the records of it still agree.
			wp_safe_redirect(
				add_query_arg(
					array(
						'aicfab-transfer' => 'error',
						'aicfab-message'  => rawurlencode( $cleared->get_error_message() ),
					),
					$target
				)
			);
			exit;
		}
		wp_safe_redirect( add_query_arg( 'aicfab-cleared', (int) $cleared, $target ) );
		exit;
	}

	/**
	 * Check or create the configured S3 Vectors index.
	 */
	public function ajax_s3_vectors() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ai_chat_bedrock_s3_vectors', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
		}
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$model   = isset( $options['embedding_model_id'] ) ? (string) $options['embedding_model_id'] : '';
		if ( '' === $model || ! AI_Chat_Bedrock_S3_Vectors::enabled( $options ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose an embedding model, Amazon S3 Vectors, a bucket and an index, and save first.', 'ai-chat-for-amazon-bedrock' ) ), 400 );
		}

		$operation = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'check';
		if ( 'create' === $operation ) {
			$created = AI_Chat_Bedrock_S3_Vectors::create_index( $model, $options );
			if ( is_wp_error( $created ) && 'aicfab_s3v_ConflictException' !== $created->get_error_code() ) {
				wp_send_json_error( array( 'message' => $created->get_error_message() ), 400 );
			}
		}

		$index = AI_Chat_Bedrock_S3_Vectors::describe_index( $model, $options );
		if ( is_wp_error( $index ) ) {
			$message = 'aicfab_s3v_NotFoundException' === $index->get_error_code()
				? __( 'The index does not exist yet. Create it here, or in the Amazon S3 console with the cosine metric and text and title as non-filterable metadata.', 'ai-chat-for-amazon-bedrock' )
				: $index->get_error_message();
			wp_send_json_error( array( 'message' => $message ), 400 );
		}
		if ( ! empty( $index['problems'] ) ) {
			wp_send_json_error( array( 'message' => AI_Chat_Bedrock_Translation::sentences( ...$index['problems'] ) ), 400 );
		}
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: vector dimension. */
					__( 'The index is ready: %d dimensions, cosine metric.', 'ai-chat-for-amazon-bedrock' ),
					(int) $index['dimension']
				),
			)
		);
	}

	/**
	 * Send the conversation log as a CSV download.
	 */
	public function handle_export_conversations() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_export_conversations' );

		$rows     = AI_Chat_Bedrock_Conversations::export_rows();
		$filename = 'ai-chat-bedrock-conversations-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		/*
		 * A download is streamed straight to the client, so WP_Filesystem does not apply:
		 * there is no file on disk, only the response body.
		 */
		$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			wp_die( esc_html__( 'The export could not be created.', 'ai-chat-for-amazon-bedrock' ) );
		}
		foreach ( $rows as $row ) {
			// Neutralize values a spreadsheet would treat as a formula.
			$row = array_map(
				static function ( $value ) {
					$value = (string) $value;
					return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) ? "'" . $value : $value;
				},
				$row
			);
			// No escape character, as in RFC 4180. Leaving it out is deprecated since PHP 8.4, and the
			// notice would be written into the download where errors are displayed.
			fputcsv( $handle, $row, ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Download the same content gaps shown in the editorial panel, for the period it shows.
	 */
	public function handle_export_gaps() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_export_gaps' );

		// Read only once the capability and nonce are checked. A missing or unrecognised period exports 30 days.
		$raw_days = isset( $_POST['aicfab_gap_days'] ) && is_scalar( $_POST['aicfab_gap_days'] ) ? wp_unslash( $_POST['aicfab_gap_days'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only 7, 30 or 90 pass gap_window().
		$days     = AI_Chat_Bedrock_Insights::gap_window( $raw_days );
		$rows     = AI_Chat_Bedrock_Insights::export_rows( array( 'days' => $days ) );
		$filename = AI_Chat_Bedrock_Insights::export_filename( $days, time() );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// This is a response stream, not a file on disk; WP_Filesystem does not apply.
		$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			wp_die( esc_html__( 'The export could not be created.', 'ai-chat-for-amazon-bedrock' ) );
		}
		foreach ( $rows as $row ) {
			$row = array_map(
				static function ( $value ) {
					$value = (string) $value;
					// Also cover whitespace before a spreadsheet formula.
					return preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) ? "'" . $value : $value;
				},
				$row
			);
			// An empty escape character preserves literal backslashes and RFC 4180 quoting.
			fputcsv( $handle, $row, ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * The query fields of the Business insights form, read from a request.
	 *
	 * Values are only passed on: AI_Chat_Bedrock_Metrics::normalize() checks each one
	 * against its fixed list.
	 *
	 * @param array $source $_GET or $_POST, unslashed.
	 * @return array
	 */
	public static function metrics_query_args( $source ) {
		$args = array();
		foreach ( array( 'metric', 'period', 'after', 'before', 'compare', 'interval', 'dimension', 'limit' ) as $key ) {
			if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( (string) $source[ $key ] );
			}
		}
		return $args;
	}

	/**
	 * Turn a question in words into a query, and show its figure on the Business insights screen.
	 *
	 * The question is not put in the address; only the query it became, or an error code.
	 */
	public function handle_metrics_ask() {
		if ( ! current_user_can( AI_Chat_Bedrock_Metrics::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_metrics_ask' );

		$question = isset( $_POST['aicfab_question'] ) && is_scalar( $_POST['aicfab_question'] ) ? sanitize_textarea_field( wp_unslash( $_POST['aicfab_question'] ) ) : '';
		$plan     = AI_Chat_Bedrock_Metrics::plan( $question );
		$url      = admin_url( 'admin.php?page=' . $this->plugin_name . '-metrics' );
		if ( is_wp_error( $plan ) ) {
			$url = add_query_arg( 'aicfab_ask_error', rawurlencode( sanitize_key( $plan->get_error_code() ) ), $url );
		} else {
			unset( $plan['notes'] );
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $plan ) ) + array( 'aicfab_asked' => '1' ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Download the figure shown on the Business insights screen as CSV.
	 */
	public function handle_export_metrics() {
		if ( ! current_user_can( AI_Chat_Bedrock_Metrics::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_export_metrics' );

		$result = AI_Chat_Bedrock_Metrics::query( self::metrics_query_args( wp_unslash( $_POST ) ), 'analytics' );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}
		$this->send_csv( AI_Chat_Bedrock_Metrics::export_rows( $result ), AI_Chat_Bedrock_Metrics::export_filename( $result ) );
	}

	/**
	 * Stream rows as a CSV download and stop.
	 *
	 * @param array  $rows     Rows, the header first.
	 * @param string $filename File name.
	 */
	private function send_csv( $rows, $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// This is a response stream, not a file on disk; WP_Filesystem does not apply.
		$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			wp_die( esc_html__( 'The export could not be created.', 'ai-chat-for-amazon-bedrock' ) );
		}
		foreach ( $rows as $row ) {
			$row = array_map(
				static function ( $value ) {
					$value = (string) $value;
					// A plain number, such as net sales after refunds, stays a number.
					if ( preg_match( '/\A-?[0-9]+(?:\.[0-9]+)?\z/', $value ) ) {
						return $value;
					}
					// Also cover whitespace before a spreadsheet formula.
					return preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) ? "'" . $value : $value;
				},
				$row
			);
			// An empty escape character preserves literal backslashes and RFC 4180 quoting.
			fputcsv( $handle, $row, ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Offer alt text generation as a media library bulk action.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function add_media_bulk_action( $actions ) {
		if ( ! AI_Chat_Bedrock_Media_Assistant::enabled() || ! current_user_can( 'upload_files' ) ) {
			return $actions;
		}
		$actions['ai_chat_bedrock_alt_text'] = __( 'Generate alt text', 'ai-chat-for-amazon-bedrock' );
		return $actions;
	}

	/**
	 * Run alt text generation for the selected attachments.
	 *
	 * Capped per request because every image is a paid model call, and images that
	 * already have alt text are skipped rather than overwritten.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Requested action.
	 * @param array  $ids      Selected attachment IDs.
	 * @return string
	 */
	public function handle_media_bulk_action( $redirect, $action, $ids ) {
		if ( 'ai_chat_bedrock_alt_text' !== $action ) {
			return $redirect;
		}
		if ( ! AI_Chat_Bedrock_Media_Assistant::enabled() ) {
			return $redirect;
		}

		$ids     = array_slice( array_map( 'absint', (array) $ids ), 0, AI_Chat_Bedrock_Media_Assistant::MAX_BULK );
		$media   = new AI_Chat_Bedrock_Media_Assistant();
		$done    = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				++$failed;
				continue;
			}
			$result = $media->generate_alt_text( $id, false );
			if ( is_wp_error( $result ) ) {
				++$failed;
			} elseif ( ! empty( $result['skipped'] ) ) {
				++$skipped;
			} else {
				++$done;
			}
		}

		return add_query_arg(
			array(
				'aicfab-alt-done'    => $done,
				'aicfab-alt-skipped' => $skipped,
				'aicfab-alt-failed'  => $failed,
			),
			$redirect
		);
	}

	/**
	 * Report the outcome of alt text generation and image edits on the media screen.
	 */
	public function render_media_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$this->render_image_edit_notice();

		$single = isset( $_GET['aicfab-alt'] ) ? sanitize_key( wp_unslash( $_GET['aicfab-alt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $single ) {
			if ( 'saved' === $single ) {
				$message = __( 'Alt text generated and saved.', 'ai-chat-for-amazon-bedrock' );
				$class   = 'notice-success';
			} elseif ( 'skipped' === $single ) {
				$message = __( 'That image already has alt text, so it was left unchanged.', 'ai-chat-for-amazon-bedrock' );
				$class   = 'notice-info';
			} else {
				$message = __( 'Alt text could not be generated for that image.', 'ai-chat-for-amazon-bedrock' );
				$class   = 'notice-error';
			}
			printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
			return;
		}

		if ( ! isset( $_GET['aicfab-alt-done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$done    = absint( wp_unslash( $_GET['aicfab-alt-done'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_GET['aicfab-alt-skipped'] ) ? absint( wp_unslash( $_GET['aicfab-alt-skipped'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed  = isset( $_GET['aicfab-alt-failed'] ) ? absint( wp_unslash( $_GET['aicfab-alt-failed'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $failed > 0 ? 'notice-warning' : 'notice-success' ),
			esc_html(
				sprintf(
					/* translators: 1: number of images described, 2: number skipped, 3: number that failed. */
					__( 'Alt text generated for %1$d image(s). Skipped %2$d that already had alt text, and %3$d could not be processed.', 'ai-chat-for-amazon-bedrock' ),
					$done,
					$skipped,
					$failed
				)
			)
		);
	}

	/**
	 * Generate and store alt text for one attachment.
	 */
	public function handle_alt_text_action() {
		$attachment = isset( $_GET['attachment'] ) ? absint( wp_unslash( $_GET['attachment'] ) ) : 0;
		check_admin_referer( 'ai_chat_bedrock_alt_text_' . $attachment );

		if ( ! current_user_can( 'edit_post', $attachment ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}

		$media  = new AI_Chat_Bedrock_Media_Assistant();
		$result = $media->generate_alt_text( $attachment, false );
		$state  = is_wp_error( $result ) ? $result->get_error_code() : ( ! empty( $result['skipped'] ) ? 'skipped' : 'saved' );

		wp_safe_redirect( add_query_arg( 'aicfab-alt', $state, admin_url( 'upload.php' ) ) );
		exit;
	}

	/**
	 * Nudge administrators when Bedrock is not usable yet.
	 */
	public function render_setup_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, $this->plugin_name ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), 'aicfab_dismissed_setup_notice', true ) ) {
			return;
		}

		$status = AI_Chat_Bedrock_AWS_Credentials::describe();
		if ( ! empty( $status['configured'] ) || AI_Chat_Bedrock_Demo::enabled() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible aicfab-setup-notice"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'AI Chatbot & Agents for Amazon Bedrock:', 'ai-chat-for-amazon-bedrock' ),
			esc_html__( 'no usable AWS credentials were found, so the chat cannot answer yet. An Amazon Bedrock API key is the quickest way to connect.', 'ai-chat-for-amazon-bedrock' ),
			esc_url( admin_url( 'admin.php?page=' . $this->plugin_name . '-settings' ) ),
			esc_html__( 'Finish setup', 'ai-chat-for-amazon-bedrock' )
		);

		// The notice is shown on every admin screen, where the plugin's own script is not
		// loaded. Core's common.js adds the dismiss button; this remembers the click, which
		// the check above has always read but nothing ever wrote.
		wp_add_inline_script(
			'common',
			sprintf(
				'jQuery( document ).on( "click", ".aicfab-setup-notice .notice-dismiss", function () { jQuery.post( ajaxurl, { action: "ai_chat_bedrock_dismiss_setup_notice", _ajax_nonce: %s } ); } );',
				wp_json_encode( wp_create_nonce( 'ai_chat_bedrock_dismiss_setup_notice' ) )
			)
		);
	}

	/**
	 * Say on the plugin's own screens that the chat is answering in demo mode.
	 *
	 * The Live Preview turns demo mode on, and whoever tries it there should know that the
	 * replies quote the site's pages rather than come from a model. It goes away by itself
	 * once credentials are found, so there is nothing to dismiss.
	 */
	public function render_demo_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! AI_Chat_Bedrock_Demo::active() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, $this->plugin_name ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info aicfab-demo-notice"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Demo mode: the chat replies with the passage of this site\'s pages that best matches each question, and no AI model is called. Connect Amazon Bedrock to get written answers, which ends demo mode.', 'ai-chat-for-amazon-bedrock' ),
			esc_url( admin_url( 'admin.php?page=' . $this->plugin_name . '-settings' ) ),
			esc_html__( 'Connect Amazon Bedrock', 'ai-chat-for-amazon-bedrock' )
		);
	}

	/**
	 * Ask once for a review, on the plugin's own screens, after the chat has proved itself.
	 */
	public function render_review_prompt() {
		AI_Chat_Bedrock_Review_Prompt::render( $this->plugin_name );
	}

	/**
	 * Stop showing the setup notice to the current user.
	 */
	public function ajax_dismiss_setup_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ai_chat_bedrock_dismiss_setup_notice', false, false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
		}
		update_user_meta( get_current_user_id(), 'aicfab_dismissed_setup_notice', 1 );
		wp_send_json_success();
	}

	/**
	 * Generate a draft post from a topic.
	 */
	public function handle_generate_content() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_generate_content' );

		$generator = new AI_Chat_Bedrock_Content_Generator();
		$result    = $generator->generate(
			array(
				'topic'    => isset( $_POST['topic'] ) ? wp_unslash( $_POST['topic'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'notes'    => isset( $_POST['notes'] ) ? wp_unslash( $_POST['notes'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'tone'     => isset( $_POST['tone'] ) ? wp_unslash( $_POST['tone'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'length'   => isset( $_POST['length'] ) ? wp_unslash( $_POST['length'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'language' => isset( $_POST['language'] ) ? wp_unslash( $_POST['language'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			)
		);

		$base = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-generator' );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'aicfab-generated', $result->get_error_code(), $base ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'aicfab-generated' => 'draft',
					'post_id'          => (int) $result['id'],
				),
				$base
			)
		);
		exit;
	}

	/**
	 * Save a chat profile.
	 */
	public function handle_save_profile() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_save_profile' );

		$fields = array();
		foreach ( array( 'key', 'label', 'model_id', 'system_prompt', 'chat_title', 'welcome_message', 'suggested_questions', 'max_tokens', 'temperature', 'rate_limit_per_minute', 'context_results', 'allow_public_chat', 'enable_site_context' ) as $field ) {
			$fields[ $field ] = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$saved = AI_Chat_Bedrock_Profiles::save( $fields['key'], $fields );
		$state = is_wp_error( $saved ) ? $saved->get_error_code() : 'saved';
		wp_safe_redirect( add_query_arg( 'aicfab-profile', $state, admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-profiles' ) ) );
		exit;
	}

	/**
	 * Delete a chat profile.
	 */
	public function handle_delete_profile() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_delete_profile' );

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		AI_Chat_Bedrock_Profiles::delete( $key );
		wp_safe_redirect( add_query_arg( 'aicfab-profile', 'deleted', admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-profiles' ) ) );
		exit;
	}

	/**
	 * Send the configuration as a downloadable file.
	 */
	public function handle_export_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_export_settings' );

		$payload = AI_Chat_Bedrock_Transfer::export();
		$body    = wp_json_encode( $payload, defined( 'JSON_PRETTY_PRINT' ) ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : 0 );
		$name    = 'ai-chat-bedrock-settings-' . gmdate( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( (string) $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body, not markup.
		exit;
	}

	/**
	 * Apply a configuration file.
	 */
	public function handle_import_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_import_settings' );

		$json = '';
		if ( ! empty( $_FILES['aicfab_import_file']['tmp_name'] ) && is_uploaded_file( $_FILES['aicfab_import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path checked by is_uploaded_file.
			$size = isset( $_FILES['aicfab_import_file']['size'] ) ? (int) $_FILES['aicfab_import_file']['size'] : 0;
			if ( $size > 0 && $size <= 512000 ) {
				$json = (string) file_get_contents( $_FILES['aicfab_import_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}
		if ( '' === $json && isset( $_POST['aicfab_import_json'] ) ) {
			$json = (string) wp_unslash( $_POST['aicfab_import_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as JSON below, never echoed.
		}

		$result = AI_Chat_Bedrock_Transfer::import( $json );
		$state  = is_wp_error( $result ) ? 'error' : 'imported';
		$args   = array( 'aicfab-transfer' => $state );
		if ( is_wp_error( $result ) ) {
			$args['aicfab-message'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['aicfab-applied'] = count( $result['applied'] );
			$args['aicfab-skipped'] = count( $result['skipped'] );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings&tab=transfer' ) ) );
		exit;
	}

	/**
	 * Delete all stored conversations.
	 */
	public function handle_clear_conversations() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_clear_conversations' );
		AI_Chat_Bedrock_Conversations::clear();
		wp_safe_redirect( add_query_arg( 'aicfab-log', 'cleared', admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-conversations' ) ) );
		exit;
	}

	/**
	 * Refresh the Bedrock model catalog for the configured region.
	 */
	public function ajax_refresh_models() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ai_chat_bedrock_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'admin_refresh_models', 10, 60 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please wait a moment.', 'ai-chat-for-amazon-bedrock' ) ), 429 );
		}

		$models = AI_Chat_Bedrock_Models::refresh();
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ), 400 );
		}

		// The model menus are refilled in place from this list, instead of asking for a reload
		// that would also throw away unsaved changes on the screen. A list, so the order holds.
		$choices = array();
		foreach ( $models as $value => $label ) {
			$choices[] = array(
				'value' => (string) $value,
				'label' => (string) $label,
			);
		}
		wp_send_json_success(
			array(
				/* translators: %d: number of models discovered. */
				'message' => sprintf( _n( '%d model available. The model menus are up to date.', '%d models available. The model menus are up to date.', count( $models ), 'ai-chat-for-amazon-bedrock' ), count( $models ) ),
				'count'   => count( $models ),
				'models'  => $choices,
			)
		);
	}

	/**
	 * Run diagnostics, including a live Bedrock invocation.
	 */
	public function ajax_run_diagnostics() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ai_chat_bedrock_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'admin_diagnostics', 6, 60 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please wait a moment.', 'ai-chat-for-amazon-bedrock' ) ), 429 );
		}

		$diagnostics = new AI_Chat_Bedrock_Diagnostics();
		wp_send_json_success( array( 'checks' => $diagnostics->run( true ) ) );
	}

	public function register_settings() {
		register_setting(
			'ai_chat_bedrock_settings',
			'ai_chat_bedrock_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'validate_settings' ),
				'default'           => array(),
			)
		);
		add_settings_section( 'aicfab_aws', __( 'AWS authentication', 'ai-chat-for-amazon-bedrock' ), array( $this, 'aws_settings_section_callback' ), 'aicfab_tab_aws' );
		$this->field( 'aws_region', __( 'AWS Region', 'ai-chat-for-amazon-bedrock' ), 'aws_region_render', 'aicfab_aws' );
		$this->field( 'bedrock_api_key', __( 'Amazon Bedrock API key', 'ai-chat-for-amazon-bedrock' ), 'bedrock_api_key_render', 'aicfab_aws' );
		$this->field( 'aws_access_key', __( 'AWS Access Key ID', 'ai-chat-for-amazon-bedrock' ), 'aws_access_key_render', 'aicfab_aws' );
		$this->field( 'aws_secret_key', __( 'AWS Secret Access Key', 'ai-chat-for-amazon-bedrock' ), 'aws_secret_key_render', 'aicfab_aws' );
		$this->field( 'aws_session_token', __( 'AWS Session Token', 'ai-chat-for-amazon-bedrock' ), 'aws_session_token_render', 'aicfab_aws' );
		$this->field( 'aws_use_role_credentials', __( 'IAM role credentials', 'ai-chat-for-amazon-bedrock' ), 'aws_use_role_credentials_render', 'aicfab_aws' );

		add_settings_section( 'aicfab_model', __( 'Model', 'ai-chat-for-amazon-bedrock' ), '__return_false', 'aicfab_tab_model' );
		$this->field( 'model_id', __( 'Model', 'ai-chat-for-amazon-bedrock' ), 'model_id_render', 'aicfab_model' );
		$this->field( 'fallback_model_id', __( 'Fallback model', 'ai-chat-for-amazon-bedrock' ), 'fallback_model_render', 'aicfab_model' );
		$this->field( 'max_tokens', __( 'Maximum output tokens', 'ai-chat-for-amazon-bedrock' ), 'max_tokens_render', 'aicfab_model' );
		$this->field( 'temperature', __( 'Temperature', 'ai-chat-for-amazon-bedrock' ), 'temperature_render', 'aicfab_model' );
		$this->field( 'image_model_id', __( 'Image model', 'ai-chat-for-amazon-bedrock' ), 'image_model_render', 'aicfab_model' );

		add_settings_section( 'aicfab_knowledge', __( 'Answer grounding', 'ai-chat-for-amazon-bedrock' ), array( $this, 'knowledge_section_callback' ), 'aicfab_tab_knowledge' );
		$this->field( 'enable_site_context', __( 'Use site content', 'ai-chat-for-amazon-bedrock' ), 'enable_site_context_render', 'aicfab_knowledge' );
		$this->field( 'context_results', __( 'Passages per answer', 'ai-chat-for-amazon-bedrock' ), 'context_results_render', 'aicfab_knowledge' );
		$this->field( 'show_sources', __( 'Show sources', 'ai-chat-for-amazon-bedrock' ), 'show_sources_render', 'aicfab_knowledge' );
		$this->field( 'include_noindex', __( 'Pages hidden from search', 'ai-chat-for-amazon-bedrock' ), 'include_noindex_render', 'aicfab_knowledge' );
		$this->field( 'embedding_model_id', __( 'Semantic search', 'ai-chat-for-amazon-bedrock' ), 'embedding_model_render', 'aicfab_knowledge' );
		$this->field( 'vector_store', __( 'Vector store', 'ai-chat-for-amazon-bedrock' ), 'vector_store_render', 'aicfab_knowledge' );
		$this->field( 'knowledge_base_id', __( 'Bedrock knowledge base ID', 'ai-chat-for-amazon-bedrock' ), 'knowledge_base_id_render', 'aicfab_knowledge' );
		$this->field( 'rerank_model_id', __( 'Reranking', 'ai-chat-for-amazon-bedrock' ), 'rerank_model_render', 'aicfab_knowledge' );

		add_settings_section( 'aicfab_chat', __( 'What visitors see', 'ai-chat-for-amazon-bedrock' ), '__return_false', 'aicfab_tab_chat' );
		$this->field( 'system_prompt', __( 'System prompt', 'ai-chat-for-amazon-bedrock' ), 'system_prompt_render', 'aicfab_chat' );
		$this->field( 'prompt_id', __( 'Managed prompt', 'ai-chat-for-amazon-bedrock' ), 'managed_prompt_render', 'aicfab_chat' );
		$this->field( 'chat_title', __( 'Chat title', 'ai-chat-for-amazon-bedrock' ), 'chat_title_render', 'aicfab_chat' );
		$this->field( 'welcome_message', __( 'Welcome message', 'ai-chat-for-amazon-bedrock' ), 'welcome_message_render', 'aicfab_chat' );
		$this->field( 'suggested_questions', __( 'Suggested questions', 'ai-chat-for-amazon-bedrock' ), 'suggested_questions_render', 'aicfab_chat' );
		$this->field( 'enable_streaming', __( 'Streaming responses', 'ai-chat-for-amazon-bedrock' ), 'enable_streaming_render', 'aicfab_chat' );
		$this->field( 'chat_memory', __( 'Conversation memory', 'ai-chat-for-amazon-bedrock' ), 'chat_memory_render', 'aicfab_chat' );
		$this->field( 'speech_replies', __( 'Read aloud', 'ai-chat-for-amazon-bedrock' ), 'speech_render', 'aicfab_chat' );
		$this->field( 'leads_enabled', __( 'Contact requests', 'ai-chat-for-amazon-bedrock' ), 'leads_render', 'aicfab_chat' );
		$this->field( 'analytics_events', __( 'Analytics events', 'ai-chat-for-amazon-bedrock' ), 'analytics_events_render', 'aicfab_chat' );
		$this->field( 'popup_site_wide', __( 'Floating chat', 'ai-chat-for-amazon-bedrock' ), 'popup_site_wide_render', 'aicfab_chat' );
		$this->field( 'chat_color_scheme', __( 'Color scheme', 'ai-chat-for-amazon-bedrock' ), 'chat_color_scheme_render', 'aicfab_chat' );

		// Tools the chat and editors use, beyond answering from the site's pages.
		add_settings_section( 'aicfab_agents', __( 'Agents and tools', 'ai-chat-for-amazon-bedrock' ), '__return_false', 'aicfab_tab_agents' );
		$this->field( 'abilities_tools', __( 'WordPress abilities as tools', 'ai-chat-for-amazon-bedrock' ), 'abilities_tools_render', 'aicfab_agents' );
		$this->field( 'site_abilities', __( 'Site content abilities', 'ai-chat-for-amazon-bedrock' ), 'site_abilities_render', 'aicfab_agents' );
		$this->field( 'site_ontology', __( 'Site description', 'ai-chat-for-amazon-bedrock' ), 'site_ontology_render', 'aicfab_agents' );
		$this->field( 'business_metrics', __( 'Business insights', 'ai-chat-for-amazon-bedrock' ), 'business_metrics_render', 'aicfab_agents' );
		$this->field( 'editor_assistant', __( 'Editor assistant', 'ai-chat-for-amazon-bedrock' ), 'editor_assistant_render', 'aicfab_agents' );
		$this->field( 'media_assistant', __( 'Media helpers', 'ai-chat-for-amazon-bedrock' ), 'media_assistant_render', 'aicfab_agents' );

		// The WeChat Official Account and mini game are separate accounts, set up side by side.
		add_settings_section( 'aicfab_wechat', __( 'WeChat', 'ai-chat-for-amazon-bedrock' ), '__return_false', 'aicfab_tab_publishing' );
		$this->field( 'wechat_enabled', __( 'WeChat Official Account', 'ai-chat-for-amazon-bedrock' ), 'wechat_render', 'aicfab_wechat' );
		$this->field( 'wechat_drafts_enabled', __( 'WeChat Official Account drafts', 'ai-chat-for-amazon-bedrock' ), 'wechat_drafts_render', 'aicfab_wechat' );
		$this->field( 'wxgame_enabled', __( 'WeChat mini game', 'ai-chat-for-amazon-bedrock' ), 'wxgame_render', 'aicfab_wechat' );

		add_settings_section( 'aicfab_publishing', __( 'Video and social platforms', 'ai-chat-for-amazon-bedrock' ), '__return_false', 'aicfab_tab_publishing' );
		$this->field( 'distribution_enabled', __( 'Publishing record', 'ai-chat-for-amazon-bedrock' ), 'distribution_render', 'aicfab_publishing' );
		$this->field( 'bilibili_embeds', __( 'Bilibili videos', 'ai-chat-for-amazon-bedrock' ), 'bilibili_embeds_render', 'aicfab_publishing' );
		$this->field( 'youtube_client_id', __( 'YouTube uploads', 'ai-chat-for-amazon-bedrock' ), 'youtube_render', 'aicfab_publishing' );

		add_settings_section( 'aicfab_governance', __( 'Safety and spend controls', 'ai-chat-for-amazon-bedrock' ), array( $this, 'governance_section_callback' ), 'aicfab_tab_governance' );
		$this->field( 'allow_public_chat', __( 'Guest access', 'ai-chat-for-amazon-bedrock' ), 'allow_public_chat_render', 'aicfab_governance' );
		$this->field( 'rate_limit_per_minute', __( 'Requests per visitor per minute', 'ai-chat-for-amazon-bedrock' ), 'rate_limit_render', 'aicfab_governance' );
		$this->field( 'role_limits', __( 'Per-role limits', 'ai-chat-for-amazon-bedrock' ), 'role_limits_render', 'aicfab_governance' );
		$this->field( 'daily_request_limit', __( 'Daily request limit', 'ai-chat-for-amazon-bedrock' ), 'daily_request_limit_render', 'aicfab_governance' );
		$this->field( 'guardrail_id', __( 'Guardrail identifier', 'ai-chat-for-amazon-bedrock' ), 'guardrail_id_render', 'aicfab_governance' );
		$this->field( 'guardrail_version', __( 'Guardrail version', 'ai-chat-for-amazon-bedrock' ), 'guardrail_version_render', 'aicfab_governance' );
		$this->field( 'log_conversations', __( 'Conversation log', 'ai-chat-for-amazon-bedrock' ), 'log_conversations_render', 'aicfab_governance' );
		$this->field( 'debug_mode', __( 'Debug logging', 'ai-chat-for-amazon-bedrock' ), 'debug_mode_render', 'aicfab_governance' );

		add_settings_section( 'aicfab_integrations', __( 'Fixes for other plugins', 'ai-chat-for-amazon-bedrock' ), array( $this, 'integrations_section_callback' ), 'aicfab_tab_integrations' );
		$this->field( 'github_read_scope', __( 'GitHub sign-in scope', 'ai-chat-for-amazon-bedrock' ), 'github_read_scope_render', 'aicfab_integrations' );
		$this->field( 'social_only_registration', __( 'Registration', 'ai-chat-for-amazon-bedrock' ), 'social_only_registration_render', 'aicfab_integrations' );
		$this->field( 'hreflang_x_default', __( 'Default language for search engines', 'ai-chat-for-amazon-bedrock' ), 'hreflang_x_default_render', 'aicfab_integrations' );
		$this->field( 'organization_author', __( 'Article author', 'ai-chat-for-amazon-bedrock' ), 'organization_author_render', 'aicfab_integrations' );

		if ( AI_Chat_Bedrock_WooCommerce::active() ) {
			add_settings_section( 'aicfab_woocommerce', __( 'WooCommerce', 'ai-chat-for-amazon-bedrock' ), array( $this, 'woocommerce_section_callback' ), 'aicfab_tab_woocommerce' );
			$this->field( 'woo_catalog', __( 'Product answers', 'ai-chat-for-amazon-bedrock' ), 'woo_catalog_render', 'aicfab_woocommerce' );
			$this->field( 'woo_catalog_limit', __( 'Products per answer', 'ai-chat-for-amazon-bedrock' ), 'woo_catalog_limit_render', 'aicfab_woocommerce' );
			$this->field( 'woo_orders', __( 'Order questions', 'ai-chat-for-amazon-bedrock' ), 'woo_orders_render', 'aicfab_woocommerce' );
			$this->field( 'woo_product_assistant', __( 'Product assistant', 'ai-chat-for-amazon-bedrock' ), 'woo_product_assistant_render', 'aicfab_woocommerce' );
		}
	}

	public function display_plugin_admin_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-display.php';
	}
	public function display_plugin_admin_settings_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-settings.php';
	}
	public function display_plugin_admin_test_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-test.php';
	}
	public function display_plugin_admin_mcp_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/ai-chat-bedrock-admin-mcp-tab.php';
	}

	public function aws_settings_section_callback() {
		echo '<p>' . esc_html__( 'Prefer wp-config.php constants or an IAM role. Database credentials are encrypted with WordPress salts and are never displayed after saving.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		echo '<p><code>AI_CHAT_BEDROCK_API_KEY</code>, <code>AI_CHAT_BEDROCK_AWS_ACCESS_KEY</code>, <code>AI_CHAT_BEDROCK_AWS_SECRET_KEY</code>, <code>AI_CHAT_BEDROCK_AWS_SESSION_TOKEN</code></p>';

		$status = AI_Chat_Bedrock_AWS_Credentials::describe();
		if ( $status['configured'] ) {
			echo '<p><strong>' . esc_html__( 'Active credential source:', 'ai-chat-for-amazon-bedrock' ) . '</strong> ' . esc_html( $status['message'] );
			if ( $status['temporary'] ) {
				echo ' <em>' . esc_html__( '(temporary credentials)', 'ai-chat-for-amazon-bedrock' ) . '</em>';
			}
			echo '</p>';
		} else {
			echo '<p><strong>' . esc_html__( 'No usable AWS credentials were found.', 'ai-chat-for-amazon-bedrock' ) . '</strong></p>';
		}
	}

	public function aws_region_render() {
		$this->select( 'aws_region', AI_Chat_Bedrock_Models::regions(), 'us-east-1' );
	}
	public function bedrock_api_key_render() {
		$this->credential_input( 'bedrock_api_key', true );
		echo '<p class="description">' . esc_html__( 'The quickest way to connect: create a long-term API key in the Amazon Bedrock console under API keys, choose the same Region as above, and paste it here. It is used for chat, the model list and embeddings. Knowledge Bases, Prompt Management and AgentCore still need access keys or an IAM role.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		if ( AI_Chat_Bedrock_Core_AI::connectors_available() ) {
			echo '<p class="description">' . wp_kses_post(
				sprintf(
					/* translators: %s: link to the Settings > Connectors screen. */
					__( 'A key can also be entered under %s, where it is checked with Amazon Bedrock before it is kept. A key here takes precedence.', 'ai-chat-for-amazon-bedrock' ),
					'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Settings > Connectors', 'ai-chat-for-amazon-bedrock' ) . '</a>'
				)
			) . '</p>';
		}
		if ( '' !== $this->option( 'bedrock_api_key', '' ) ) {
			echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[bedrock_api_key_clear]" value="1"> ' . esc_html__( 'Remove the stored API key', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		}
	}
	public function aws_access_key_render() {
		$this->credential_input( 'aws_access_key', false );
	}
	public function aws_secret_key_render() {
		$this->credential_input( 'aws_secret_key', true );
	}
	public function aws_session_token_render() {
		$this->credential_input( 'aws_session_token', true );
		echo '<p class="description">' . esc_html__( 'Required only for temporary AWS credentials.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function aws_use_role_credentials_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$checked = ! isset( $options['aws_use_role_credentials'] ) || ! empty( $options['aws_use_role_credentials'] );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[aws_use_role_credentials]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Use the server IAM role when no keys are configured', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Supports environment variables, ECS or EKS task roles, and EC2 instance roles, so no long-lived AWS keys are stored in WordPress.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function enable_streaming_render() {
		$options   = get_option( 'ai_chat_bedrock_settings', array() );
		$options   = is_array( $options ) ? $options : array();
		$checked   = ! isset( $options['enable_streaming'] ) || 'off' !== $options['enable_streaming'];
		$supported = AI_Chat_Bedrock_AWS::streaming_supported();
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[enable_streaming]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Stream responses as they are generated (default)', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		if ( $supported ) {
			echo '<p class="description">' . esc_html__( 'Streaming uses one authenticated POST request per message and falls back to a buffered response automatically.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'This server cannot stream because the PHP cURL extension is unavailable. Buffered responses will be used.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		}
	}
	public function governance_section_callback() {
		echo '<p>' . esc_html__( 'Who may chat and how often, a daily cap, an Amazon Bedrock guardrail, and what is logged. Counters store request and token totals only.', 'ai-chat-for-amazon-bedrock' ) . '</p>';

		$today = AI_Chat_Bedrock_Usage::today_totals();
		$week  = AI_Chat_Bedrock_Usage::totals( 7 );
		echo '<p>' . esc_html(
			AI_Chat_Bedrock_Translation::sentences(
				sprintf(
					/* translators: 1: requests today, 2: input tokens today, 3: output tokens today. */
					_n( 'Today: %1$s request, %2$s input tokens, %3$s output tokens.', 'Today: %1$s requests, %2$s input tokens, %3$s output tokens.', (int) $today['requests'], 'ai-chat-for-amazon-bedrock' ),
					number_format_i18n( (int) $today['requests'] ),
					number_format_i18n( (int) $today['input_tokens'] ),
					number_format_i18n( (int) $today['output_tokens'] )
				),
				sprintf(
					/* translators: %s: requests in the last seven days. */
					_n( 'Last 7 days: %s request.', 'Last 7 days: %s requests.', (int) $week['requests'], 'ai-chat-for-amazon-bedrock' ),
					number_format_i18n( (int) $week['requests'] )
				)
			)
		) . '</p>';
	}
	public function guardrail_id_render() {
		$this->text_input( 'guardrail_id', '', 200 );
		echo '<p class="description">' . esc_html__( 'Optional. Applies an existing Amazon Bedrock guardrail to every request.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function guardrail_version_render() {
		$this->text_input( 'guardrail_version', 'DRAFT', 20 );
		echo '<p class="description">' . esc_html__( 'Use DRAFT or a published numeric version.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function daily_request_limit_render() {
		$value = absint( $this->option( 'daily_request_limit', 0 ) );
		echo '<input type="number" id="aicfab_field_daily_request_limit" name="ai_chat_bedrock_settings[daily_request_limit]" value="' . esc_attr( $value ) . '" min="0" max="100000" step="10">';
		echo '<p class="description">' . esc_html__( 'Maximum Bedrock requests per day for the whole site. Use 0 for no plugin-side limit. Embeddings for search and indexing are not counted. This is not a billing guarantee; configure AWS Budgets as well.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function knowledge_section_callback() {
		echo '<p>' . esc_html__( 'Ground answers in your own content. Retrieved passages are passed to the model as reference data, never as instructions, and only published content is used.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function enable_site_context_render() {
		$checked = ! empty( $this->option( 'enable_site_context', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[enable_site_context]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Search published posts and pages for each question', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Uses the built-in WordPress search. Password-protected, private and draft content is excluded.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function context_results_render() {
		$value = absint( $this->option( 'context_results', 3 ) );
		echo '<input type="number" id="aicfab_field_context_results" class="small-text" name="ai_chat_bedrock_settings[context_results]" value="' . esc_attr( max( 1, min( 8, $value ) ) ) . '" min="1" max="8">';
		echo '<p class="description">' . esc_html__( 'More passages improve grounding but increase input tokens and cost.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function include_noindex_render() {
		$checked = ! empty( $this->option( 'include_noindex', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[include_noindex]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Also answer from pages that search engines are told not to index', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. A page kept out of search engines, such as a thank-you page with a download or a campaign landing page, is usually kept out for a reason, so the chat and agents leave it out too. The noindex settings of Yoast SEO, Rank Math and SEOPress are read for each page and post type; All in One SEO for each page. Reading a post aloud is not affected.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function show_sources_render() {
		$checked = ! empty( $this->option( 'show_sources', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[show_sources]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'List links to the pages an answer was drawn from', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Up to three links under each answer, to published pages and knowledge base documents with a web address. Pages that only share a single word with the question are not listed.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function knowledge_base_id_render() {
		$this->text_input( 'knowledge_base_id', '', 64 );
		echo '<p class="description">' . esc_html__( 'Optional. Queries an existing Amazon Bedrock knowledge base with the Retrieve API. Requires bedrock:Retrieve permission for that knowledge base.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function rerank_model_render() {
		$choices = array( '' => __( 'Off', 'ai-chat-for-amazon-bedrock' ) ) + AI_Chat_Bedrock_Retrieval::rerank_models();
		$this->select( 'rerank_model_id', $choices, '' );
		echo '<p class="description">' . esc_html__( 'Optional. Gathers more passages from site content and the knowledge base, then has a reranking model keep the ones that best answer the question. It adds one rerank request per question, billed per query, and the passages are used in their original order if it fails. Requires bedrock:Rerank, and bedrock:InvokeModel on the reranking model, in the chat region.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function abilities_tools_render() {
		$checked   = ! empty( $this->option( 'abilities_tools', false ) );
		$available = AI_Chat_Bedrock_Abilities::available();
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[abilities_tools]" value="1" ' . checked( $checked, true, false ) . ' ' . disabled( $available, false, false ) . '> ' . esc_html__( 'Offer abilities registered by other plugins to the chat model', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		if ( $available ) {
			echo '<p class="description">' . esc_html__( 'Abilities follow the same tool policy: a capability is required, permission callbacks are honored, and abilities that change data need explicit approval.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
			$sources = ( new AI_Chat_Bedrock_Abilities() )->source_labels();
			$policy  = '<a href="' . esc_url( admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-mcp#aicfab-mcp-policy' ) ) . '">' . esc_html__( 'MCP > Tool policy', 'ai-chat-for-amazon-bedrock' ) . '</a>';
			if ( ! empty( $sources ) ) {
				echo '<p class="description">' . sprintf(
					/* translators: 1: names of plugins, 2: link to the tool policy. */
					esc_html__( 'Found on this site: %1$s. Choose plugins and abilities under %2$s.', 'ai-chat-for-amazon-bedrock' ),
					esc_html( AI_Chat_Bedrock_Translation::items( $sources ) ),
					$policy // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
				) . '</p>';
			}
			if ( ! get_option( 'ai_chat_bedrock_enable_mcp', false ) ) {
				echo '<p class="description">' . esc_html__( 'Tools in chat are switched off on the MCP screen, so no ability is offered until they are switched on there.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
			}
		} else {
			echo '<p class="description">' . esc_html__( 'The WordPress Abilities API is not available on this site, so this option is inactive.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		}
	}
	public function site_abilities_render() {
		$checked   = ! empty( $this->option( 'site_abilities', false ) );
		$available = AI_Chat_Bedrock_Abilities::available();
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[site_abilities]" value="1" ' . checked( $checked, true, false ) . ' ' . disabled( $available, false, false ) . '> ' . esc_html__( 'Register read-only content abilities plus draft creation', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		if ( $available ) {
			echo '<p class="description">' . esc_html__( 'Registers search, read, SEO suggestion and WooCommerce product lookup as read-only abilities, plus one write ability that creates drafts. Nothing is published, updated or deleted, and orders and customers are never exposed.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Requires the WordPress Abilities API.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		}
	}
	public function site_ontology_render() {
		$checked = ! empty( $this->option( 'site_ontology', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[site_ontology]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Describe the site to agents and label passages with their type and language', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Adds a read-only describe-site ability and MCP tool. It lists what the site holds as schema.org types, with counts per language, how they relate and which data an AI may see, using the same IDs as Yoast SEO and WooCommerce. Passages given to the model also say whether they come from a post, page or product, and in which language. Only published, public content is described.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function business_metrics_render() {
		$checked = ! empty( $this->option( 'business_metrics', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[business_metrics]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Show figures for content, questions, AI usage and the store, and let agents query them', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Adds a Business insights screen with periods, comparisons, charts, a CSV download and questions in words, and a read-only query-metrics ability and MCP tool. Figures are read from data the site already keeps, including WooCommerce Analytics. Figures from fewer than five questions or orders are withheld, question figures stay on the screen, and no figure is sent to Amazon Bedrock.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function editor_assistant_render() {
		$checked = ! empty( $this->option( 'editor_assistant', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[editor_assistant]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Add a writing assistant sidebar to the block editor', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Improve, shorten, expand, summarize, suggest titles or translate. Suggestions are never saved automatically and require the edit_posts capability.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function log_conversations_render() {
		$checked   = AI_Chat_Bedrock_Conversations::enabled();
		$retention = AI_Chat_Bedrock_Conversations::retention_days();
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Conversation log', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[log_conversations]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Store questions and answers for review', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<label for="aicfab_field_log_retention_days">' . esc_html__( 'Keep for', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="number" id="aicfab_field_log_retention_days" class="small-text" name="ai_chat_bedrock_settings[log_retention_days]" value="' . esc_attr( $retention ) . '" min="1" max="' . esc_attr( AI_Chat_Bedrock_Conversations::MAX_DAYS ) . '"> ' . esc_html__( 'days', 'ai-chat-for-amazon-bedrock' );
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Disabled by default. When enabled, chat content is stored in the database, capped at 200 recent entries, and visible to administrators. Disclose this to your visitors.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function chat_memory_render() {
		$current = AI_Chat_Bedrock_Chat_History::mode( get_option( 'ai_chat_bedrock_settings', array() ) );
		$days    = AI_Chat_Bedrock_Chat_History::retention_days();
		$choices = array(
			''        => __( 'Off (default): each page starts a new conversation', 'ai-chat-for-amazon-bedrock' ),
			'tab'     => __( 'Keep it while the visitor browses, in their browser tab', 'ai-chat-for-amazon-bedrock' ),
			'account' => __( 'Also save it for signed-in visitors, across visits and devices', 'ai-chat-for-amazon-bedrock' ),
		);
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Conversation memory', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<select id="aicfab_field_chat_memory" name="ai_chat_bedrock_settings[chat_memory]">';
		foreach ( $choices as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><br>';
		echo '<label for="aicfab_field_chat_memory_days">' . esc_html__( 'Keep saved conversations for', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="number" id="aicfab_field_chat_memory_days" class="small-text" name="ai_chat_bedrock_settings[chat_memory_days]" value="' . esc_attr( $days ) . '" min="1" max="' . esc_attr( AI_Chat_Bedrock_Chat_History::MAX_DAYS ) . '"> ' . esc_html__( 'days', 'ai-chat-for-amazon-bedrock' );
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Kept in the browser tab, the conversation is stored only in the visitor\'s browser and is gone when the tab is closed. Saved for signed-in visitors, it is also stored on this site with their account, included in personal data exports and erasures, and deleted after the days above. Clear in the chat deletes it, and switching saving off deletes every saved conversation. Disclose this to your visitors.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function speech_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$engine  = AI_Chat_Bedrock_Speech::engine( $options );
		$engines = array(
			'neural'     => __( 'Neural (default)', 'ai-chat-for-amazon-bedrock' ),
			'generative' => __( 'Generative: more natural, about twice the price, not every voice or Region', 'ai-chat-for-amazon-bedrock' ),
		);
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Read aloud', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[speech_replies]" value="1" ' . checked( AI_Chat_Bedrock_Speech::replies_enabled( $options ), true, false ) . '> ' . esc_html__( 'Add a Listen button to chat answers', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[speech_posts]" value="1" ' . checked( AI_Chat_Bedrock_Speech::posts_enabled( $options ), true, false ) . '> ' . esc_html__( 'Add a Listen to this post button to posts', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[speech_posts_signed_in]" value="1" ' . checked( AI_Chat_Bedrock_Speech::posts_need_sign_in( $options ), true, false ) . '> ' . esc_html__( 'Only for signed-in visitors, as on a members site', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<label for="aicfab_field_speech_engine">' . esc_html__( 'Voice engine', 'ai-chat-for-amazon-bedrock' ) . '</label> <select id="aicfab_field_speech_engine" name="ai_chat_bedrock_settings[speech_engine]">';
		foreach ( $engines as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $engine, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><br>';
		echo '<label for="aicfab_field_speech_daily_chars">' . esc_html__( 'Characters read per day, across the site', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="number" id="aicfab_field_speech_daily_chars" class="regular-text" name="ai_chat_bedrock_settings[speech_daily_chars]" value="' . esc_attr( AI_Chat_Bedrock_Speech::daily_characters( $options ) ) . '" min="0" max="' . esc_attr( AI_Chat_Bedrock_Speech::MAX_DAILY_CHARACTERS ) . '" step="1000">';
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Off by default. Amazon Polly reads the text aloud, in a voice for its language, and is billed per character; 0 removes the daily limit. Only answers this chat gave can be read, by the visitor they were given to. A post is read as a signed-out visitor sees it, so members-only content is never sent; its audio is saved in the uploads folder and made again when the post changes. The AWS identity needs polly:SynthesizeSpeech.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		$visitor = AI_Chat_Bedrock_Speech::visitor_characters( $options );
		echo '<p class="description">' . esc_html(
			$visitor > 0
				/* translators: %s: number of characters. */
				? sprintf( __( 'Saved audio plays for everyone at no cost. Making new audio is limited to %s characters per visitor a day, so one visitor or script cannot use up the day for the rest, and crawlers and scripts cannot have posts read at all. Administrators are not limited.', 'ai-chat-for-amazon-bedrock' ), number_format_i18n( $visitor ) )
				: __( 'Saved audio plays for everyone at no cost. Crawlers and scripts cannot have posts read. Without a daily limit for the site, visitors have none either.', 'ai-chat-for-amazon-bedrock' )
		) . '</p>';
	}
	public function wechat_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$saved   = __( 'Saved — enter a value to replace', 'ai-chat-for-amazon-bedrock' );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'WeChat Official Account', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[wechat_enabled]" value="1" ' . checked( ! empty( $options['wechat_enabled'] ), true, false ) . '> ' . esc_html__( 'Answer messages that followers send to the account', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<label for="aicfab_field_wechat_token">' . esc_html__( 'Token', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="password" id="aicfab_field_wechat_token" class="regular-text" name="ai_chat_bedrock_settings[wechat_token]" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== AI_Chat_Bedrock_WeChat::token( $options ) ? $saved : '' ) . '"><br>';
		echo '<label for="aicfab_field_wechat_aes_key">' . esc_html__( 'EncodingAESKey, for safe mode', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="password" id="aicfab_field_wechat_aes_key" class="regular-text" name="ai_chat_bedrock_settings[wechat_aes_key]" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== AI_Chat_Bedrock_WeChat::aes_key( $options ) ? $saved : '' ) . '"><br>';
		echo '<label for="aicfab_field_wechat_app_id">' . esc_html__( 'AppID, for safe mode', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="text" id="aicfab_field_wechat_app_id" class="regular-text" name="ai_chat_bedrock_settings[wechat_app_id]" value="' . esc_attr( AI_Chat_Bedrock_WeChat::app_id( $options ) ) . '" placeholder="wx…"><br>';
		echo '<label for="aicfab_field_wechat_model_id">' . esc_html__( 'Model for WeChat', 'ai-chat-for-amazon-bedrock' ) . '</label> <select id="aicfab_field_wechat_model_id" name="ai_chat_bedrock_settings[wechat_model_id]">';
		foreach ( array( '' => __( 'Same as the chat', 'ai-chat-for-amazon-bedrock' ) ) + AI_Chat_Bedrock_Models::options() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( AI_Chat_Bedrock_WeChat::model( $options ), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><br>';
		echo '<label for="aicfab_field_wechat_hourly">' . esc_html__( 'Messages per follower per hour', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="number" id="aicfab_field_wechat_hourly" class="small-text" name="ai_chat_bedrock_settings[wechat_hourly]" value="' . esc_attr( AI_Chat_Bedrock_WeChat::hourly_limit( $options ) ) . '" min="1" max="' . esc_attr( AI_Chat_Bedrock_WeChat::MAX_HOURLY ) . '">';
		echo '<br><label for="aicfab_field_wechat_menu">' . esc_html__( 'Menu, sent to followers who write 菜单, 目录 or menu, and after the welcome', 'ai-chat-for-amazon-bedrock' ) . '</label><br><textarea id="aicfab_field_wechat_menu" class="large-text" rows="4" name="ai_chat_bedrock_settings[wechat_menu]" placeholder="' . esc_attr__( "Courses: https://example.com/courses/\nNews: https://example.com/news/\nSend 精选 for the newest featured articles.", 'ai-chat-for-amazon-bedrock' ) . '">' . esc_textarea( AI_Chat_Bedrock_WeChat::menu( $options ) ) . '</textarea>';
		if ( '' !== AI_Chat_Bedrock_WeChat::token( $options ) || '' !== AI_Chat_Bedrock_WeChat::aes_key( $options ) ) {
			echo '<br><label><input type="checkbox" name="ai_chat_bedrock_settings[wechat_clear]" value="1"> ' . esc_html__( 'Remove the saved token and key', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		}
		echo '</fieldset>';
		$contact = AI_Chat_Bedrock_WeChat::contact_summary();
		echo '<p><strong>' . esc_html( '' !== $contact ? $contact : __( 'WeChat has not reached this address yet.', 'ai-chat-for-amazon-bedrock' ) ) . '</strong></p>';
		/* translators: %s: the address WeChat sends messages to. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Off by default. In the WeChat Official Accounts Platform, under Settings and Development > Basic Configuration, enable the server configuration with the URL %s and the token entered here. Plaintext mode needs only the token; compatible and safe mode also need the EncodingAESKey and AppID. No AppSecret is needed.', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_WeChat::url() ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'With message push on, WeChat turns off the menu set in its console, and an account that is not verified cannot set one through its API, so followers write a word instead: 菜单 gets the menu above, and 精选 or 最新 the newest featured posts (the category chosen for WeChat drafts below) with their addresses. Neither calls the model.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'The chat answers each text message from the site\'s pages, in plain text with its sources. WeChat waits about fifteen seconds in all; a longer answer is kept and the follower is told to send 1 to see it, so choose a fast model for WeChat if the chat\'s takes longer. A new follower gets the welcome message and suggested questions. Every answer counts towards the daily request limit, and the conversation log records them when it is on.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function wechat_drafts_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'WeChat Official Account drafts', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[wechat_drafts_enabled]" value="1" ' . checked( ! empty( $options['wechat_drafts_enabled'] ), true, false ) . '> ' . esc_html__( 'Send posts to the Official Account\'s draft box', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<label for="aicfab_field_wechat_app_secret">' . esc_html__( 'AppSecret of the Official Account', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="password" id="aicfab_field_wechat_app_secret" class="regular-text" name="ai_chat_bedrock_settings[wechat_app_secret]" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== AI_Chat_Bedrock_WeChat_Drafts::app_secret( $options ) ? __( 'Saved — enter a value to replace', 'ai-chat-for-amazon-bedrock' ) : '' ) . '"><br>';
		echo '<label for="aicfab_field_wechat_drafts_category">' . esc_html__( 'Featured posts', 'ai-chat-for-amazon-bedrock' ) . '</label> ';
		wp_dropdown_categories(
			array(
				'name'            => 'ai_chat_bedrock_settings[wechat_drafts_category]',
				'id'              => 'aicfab_field_wechat_drafts_category',
				'selected'        => AI_Chat_Bedrock_WeChat_Drafts::category( $options ),
				'show_option_all' => __( 'All posts', 'ai-chat-for-amazon-bedrock' ),
				'hide_empty'      => false,
				'hierarchical'    => true,
			)
		);
		echo '<br><label for="aicfab_field_wechat_drafts_schedule">' . esc_html__( 'Collect the newest featured posts into a draft', 'ai-chat-for-amazon-bedrock' ) . '</label> <select id="aicfab_field_wechat_drafts_schedule" name="ai_chat_bedrock_settings[wechat_drafts_schedule]">';
		foreach ( array(
			'off'    => __( 'Never; only when sent from a post or by an agent', 'ai-chat-for-amazon-bedrock' ),
			'daily'  => __( 'Every day at 9:00', 'ai-chat-for-amazon-bedrock' ),
			'weekly' => __( 'Every week', 'ai-chat-for-amazon-bedrock' ),
		) as $aicfab_value => $aicfab_label ) {
			echo '<option value="' . esc_attr( $aicfab_value ) . '" ' . selected( AI_Chat_Bedrock_WeChat_Drafts::schedule( $options ), $aicfab_value, false ) . '>' . esc_html( $aicfab_label ) . '</option>';
		}
		echo '</select><br>';
		echo '<label for="aicfab_field_wechat_drafts_count">' . esc_html__( 'Articles a draft', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="number" id="aicfab_field_wechat_drafts_count" class="small-text" name="ai_chat_bedrock_settings[wechat_drafts_count]" value="' . esc_attr( AI_Chat_Bedrock_WeChat_Drafts::count( $options ) ) . '" min="1" max="' . esc_attr( AI_Chat_Bedrock_WeChat_Drafts::MAX_ARTICLES ) . '"><br>';
		echo '<label for="aicfab_field_wechat_drafts_author">' . esc_html__( 'Author shown in WeChat', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="text" id="aicfab_field_wechat_drafts_author" class="regular-text" maxlength="16" name="ai_chat_bedrock_settings[wechat_drafts_author]" value="' . esc_attr( AI_Chat_Bedrock_WeChat_Drafts::author( $options ) ) . '"><br>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[wechat_drafts_notify]" value="1" ' . checked( ! empty( $options['wechat_drafts_notify'] ), true, false ) . '> ' . esc_html__( 'Email the site when a scheduled draft is ready', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		if ( '' !== AI_Chat_Bedrock_WeChat_Drafts::app_secret( $options ) ) {
			echo '<br><label><input type="checkbox" name="ai_chat_bedrock_settings[wechat_drafts_clear]" value="1"> ' . esc_html__( 'Remove the saved AppSecret', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		}
		echo '</fieldset>';
		$status = AI_Chat_Bedrock_WeChat_Drafts::status_summary();
		if ( '' !== $status ) {
			echo '<p><strong>' . esc_html( $status ) . '</strong></p>';
		}
		echo '<p class="description">' . esc_html__( 'Off by default. Uses the AppID entered for the WeChat Official Account above, and the AppSecret and IP whitelist under Basic Information > Developer Key in the WeChat Developers Platform. Each post becomes an article with its title, excerpt, the featured image as cover, the text and images a signed-out visitor sees, and the post as "Read more"; links in the text become plain text, as WeChat does not open them. Posts are sent from the Published elsewhere box, by an agent, or on the schedule, which takes featured posts of the last 60 days not sent before, in Chinese when the site has it, and only those with a featured image and at least 600 characters of public text.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Only drafts are made: WeChat lets only verified company accounts publish through its API. Check each draft and publish it in the Official Accounts Platform.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function wxgame_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$saved   = __( 'Saved — enter a value to replace', 'ai-chat-for-amazon-bedrock' );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'WeChat mini game', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[wxgame_enabled]" value="1" ' . checked( ! empty( $options['wxgame_enabled'] ), true, false ) . '> ' . esc_html__( 'Take the mini game\'s customer service messages and count what players do there', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<label for="aicfab_field_wxgame_app_id">' . esc_html__( 'AppID', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="text" id="aicfab_field_wxgame_app_id" class="regular-text" name="ai_chat_bedrock_settings[wxgame_app_id]" value="' . esc_attr( AI_Chat_Bedrock_WeChat_Game::app_id( $options ) ) . '" placeholder="wx…"><br>';
		foreach ( array(
			'wxgame_token'      => array( __( 'Token', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_WeChat_Game::token( $options ) ),
			'wxgame_aes_key'    => array( __( 'EncodingAESKey, for safe mode', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_WeChat_Game::aes_key( $options ) ),
			'wxgame_app_secret' => array( __( 'AppSecret, to send answers', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_WeChat_Game::app_secret( $options ) ),
		) as $aicfab_key => $aicfab_field ) {
			echo '<label for="aicfab_field_' . esc_attr( $aicfab_key ) . '">' . esc_html( $aicfab_field[0] ) . '</label> <input type="password" id="aicfab_field_' . esc_attr( $aicfab_key ) . '" class="regular-text" name="ai_chat_bedrock_settings[' . esc_attr( $aicfab_key ) . ']" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== $aicfab_field[1] ? $saved : '' ) . '"><br>';
		}
		echo '<label for="aicfab_field_wxgame_welcome">' . esc_html__( 'Welcome, when a player opens the chat', 'ai-chat-for-amazon-bedrock' ) . '</label><br><textarea id="aicfab_field_wxgame_welcome" class="large-text" rows="2" name="ai_chat_bedrock_settings[wxgame_welcome]">' . esc_textarea( isset( $options['wxgame_welcome'] ) ? (string) $options['wxgame_welcome'] : '' ) . '</textarea><br>';
		echo '<label for="aicfab_field_wxgame_answers">' . esc_html__( 'Set answers, one per line as: keywords = answer', 'ai-chat-for-amazon-bedrock' ) . '</label><br><textarea id="aicfab_field_wxgame_answers" class="large-text code" rows="5" name="ai_chat_bedrock_settings[wxgame_answers]" placeholder="' . esc_attr__( 'recharge, payment = Payments are handled by WeChat Pay. Send your order number if one is missing.', 'ai-chat-for-amazon-bedrock' ) . '">' . esc_textarea( AI_Chat_Bedrock_WeChat_Game::clean_answers( isset( $options['wxgame_answers'] ) ? $options['wxgame_answers'] : '' ) ) . '</textarea><br>';
		echo '<label for="aicfab_field_wxgame_fallback">' . esc_html__( 'Reply when no answer matches (optional)', 'ai-chat-for-amazon-bedrock' ) . '</label><br><textarea id="aicfab_field_wxgame_fallback" class="large-text" rows="2" name="ai_chat_bedrock_settings[wxgame_fallback]">' . esc_textarea( isset( $options['wxgame_fallback'] ) ? (string) $options['wxgame_fallback'] : '' ) . '</textarea>';
		if ( '' !== AI_Chat_Bedrock_WeChat_Game::token( $options ) || '' !== AI_Chat_Bedrock_WeChat_Game::aes_key( $options ) || AI_Chat_Bedrock_WeChat_Game::can_reply( $options ) ) {
			echo '<br><label><input type="checkbox" name="ai_chat_bedrock_settings[wxgame_clear]" value="1"> ' . esc_html__( 'Remove the saved token, key and AppSecret', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		}
		echo '</fieldset>';
		$contact = AI_Chat_Bedrock_WeChat_Game::contact_summary();
		echo '<p><strong>' . esc_html( $contact ? implode( ' ', $contact ) : __( 'WeChat has not reached this address yet.', 'ai-chat-for-amazon-bedrock' ) ) . '</strong></p>';
		$summary = AI_Chat_Bedrock_WeChat_Game::summary( 30 );
		if ( $summary['sessions'] || $summary['messages'] || $summary['templates'] ) {
			$asked = $summary['answered'] + $summary['unanswered'];
			/* translators: 1: player-days, 2: chats opened, 3: messages, 4: share of questions answered, 5: answers sent. */
			$line   = sprintf( __( 'Last 30 days: %1$s players (counted once a day), %2$s chats opened, %3$s messages, %4$s of questions matched a set answer, %5$s answers sent.', 'ai-chat-for-amazon-bedrock' ), number_format_i18n( $summary['players'] ), number_format_i18n( $summary['sessions'] ), number_format_i18n( $summary['messages'] ), $asked ? number_format_i18n( 100 * $summary['answered'] / $asked ) . '%' : '—', number_format_i18n( $summary['replies'] ) );
			$scenes = array();
			foreach ( array_slice( $summary['scenes'], 0, 3, true ) as $aicfab_scene => $aicfab_count ) {
				$scenes[] = $aicfab_scene . ' ' . number_format_i18n( $aicfab_count );
			}
			if ( $scenes ) {
				/* translators: %s: scenes, the sessionFrom the game passed, with counts. */
				$line .= ' ' . sprintf( __( 'Opened most from: %s.', 'ai-chat-for-amazon-bedrock' ), implode( ', ', $scenes ) );
			}
			foreach ( array_slice( $summary['templates'], 0, 3, true ) as $aicfab_template => $aicfab_outcomes ) {
				$accepted = isset( $aicfab_outcomes['accepted'] ) ? $aicfab_outcomes['accepted'] : 0;
				$declined = isset( $aicfab_outcomes['declined'] ) ? $aicfab_outcomes['declined'] : 0;
				if ( $accepted + $declined ) {
					/* translators: 1: subscription template ID, 2: share of players who accepted, 3: how many were asked. */
					$line .= ' ' . sprintf( __( 'Template %1$s: %2$s accepted of %3$s asked.', 'ai-chat-for-amazon-bedrock' ), $aicfab_template, number_format_i18n( 100 * $accepted / ( $accepted + $declined ) ) . '%', number_format_i18n( $accepted + $declined ) );
				}
			}
			echo '<p>' . esc_html( $line ) . '</p>';
		}
		/* translators: %s: the address WeChat sends the game's messages to. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Off by default. In the mini game\'s console, under Development Management > Development Settings > Message Push, enter the URL %s, the token and, for safe mode, the EncodingAESKey; JSON and XML both work. The game opens the chat with wx.openCustomerServiceConversation, and the sessionFrom it passes is counted as the scene.', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_WeChat_Game::url() ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Questions are answered with the set answers only, matched by keyword; nothing is generated, as a mini game needs an AI category and an algorithm filing to answer with AI. Answers are sent through WeChat\'s customer service API, which needs the AppSecret and this server\'s address in the game\'s IP whitelist, and allows a few answers within 48 hours of a player\'s message. Only daily totals are kept, with players counted under a code that changes every day; questions go to the conversation log when it is on. What players do in the game itself is reported by the game with wx.reportEvent and shown in WeChat\'s own analysis.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function analytics_events_render() {
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[analytics_events]" value="1" ' . checked( AI_Chat_Bedrock_Analytics::enabled(), true, false ) . '> ' . esc_html__( 'Report chat activity to the analytics already on this site', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. The chat tells the site\'s analytics tag when it is opened, a question is asked or answered, a source or product in an answer is followed, an answer is rated and a contact request is sent, with no message text or contact details. Google Analytics (through Site Kit, MonsterInsights or a gtag snippet), Google Tag Manager, Matomo and Plausible receive the events; mark ai_chat_contact as a key event to count contact requests as conversions. With a consent plugin that uses the WP Consent API, events wait until the visitor allows statistics.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		$tools = AI_Chat_Bedrock_Analytics::tools();
		if ( $tools ) {
			/* translators: %s: comma separated names of analytics plugins. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Found on this site: %s.', 'ai-chat-for-amazon-bedrock' ), implode( ', ', $tools ) ) ) . '</p>';
		}
	}
	public function leads_render() {
		$options  = get_option( 'ai_chat_bedrock_settings', array() );
		$options  = is_array( $options ) ? $options : array();
		$link     = isset( $options['leads_link'] ) ? AI_Chat_Bedrock_Leads::clean_link( $options['leads_link'] ) : '';
		$joinchat = AI_Chat_Bedrock_Leads::joinchat_url();
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Contact requests', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[leads_enabled]" value="1" ' . checked( ! empty( $options['leads_enabled'] ), true, false ) . '> ' . esc_html__( 'Let visitors leave their details for a person to get back to them', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		/* translators: %s: the site's administration email address. */
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[leads_notify]" value="1" ' . checked( AI_Chat_Bedrock_Leads::notifies( $options ), true, false ) . '> ' . esc_html( sprintf( __( 'Email each request to %s', 'ai-chat-for-amazon-bedrock' ), (string) get_option( 'admin_email' ) ) ) . '</label><br>';
		echo '<label for="aicfab_field_leads_days">' . esc_html__( 'Keep requests for', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="number" id="aicfab_field_leads_days" class="small-text" name="ai_chat_bedrock_settings[leads_days]" value="' . esc_attr( AI_Chat_Bedrock_Leads::retention_days( $options ) ) . '" min="1" max="' . esc_attr( AI_Chat_Bedrock_Leads::MAX_DAYS ) . '"> ' . esc_html__( 'days', 'ai-chat-for-amazon-bedrock' ) . '<br>';
		echo '<label for="aicfab_field_leads_link">' . esc_html__( 'Another way to reach you (optional)', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="text" id="aicfab_field_leads_link" class="regular-text" name="ai_chat_bedrock_settings[leads_link]" value="' . esc_attr( $link ) . '" placeholder="' . esc_attr( '' !== $joinchat ? $joinchat : 'https://wa.me/15551234567' ) . '">';
		echo '</fieldset>';
		$found = array();
		if ( class_exists( 'Flamingo_Inbound_Message' ) ) {
			$found[] = __( 'Flamingo is active, so each request is also filed in its inbox.', 'ai-chat-for-amazon-bedrock' );
		}
		if ( AI_Chat_Bedrock_Leads::uses_akismet() ) {
			$found[] = __( 'Akismet is set up, so each request is checked for spam, as a contact form is.', 'ai-chat-for-amazon-bedrock' );
		}
		if ( '' !== $joinchat ) {
			$found[] = __( 'Joinchat is active: while the link is empty, its WhatsApp number is offered.', 'ai-chat-for-amazon-bedrock' );
		}
		echo '<p class="description">' . esc_html__( 'Off by default. A Contact a person button appears below the chat, and the assistant points to it when it cannot help. Visitors give an email address or phone number and must agree before anything is stored. Requests are kept on this site and listed under Contact requests, where they can be exported. The link can be a web, mailto: or tel: address. Disclose this in your privacy policy.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		foreach ( $found as $line ) {
			echo '<p class="description">' . esc_html( $line ) . '</p>';
		}
	}
	public function suggested_questions_render() {
		$value = (string) $this->option( 'suggested_questions', '' );
		echo '<textarea id="aicfab_field_suggested_questions" name="ai_chat_bedrock_settings[suggested_questions]" rows="4" class="large-text" placeholder="' . esc_attr__( 'What are your opening hours?', 'ai-chat-for-amazon-bedrock' ) . '">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One question per line, up to four. They appear as buttons above the input so visitors know what to ask, and disappear once the conversation starts.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}

	public function role_limits_render() {
		$fallback = max( 1, absint( $this->option( 'rate_limit_per_minute', 5 ) ) );
		$limits   = AI_Chat_Bedrock_Rate_Limits::all();

		echo '<fieldset>';
		echo '<legend class="screen-reader-text">' . esc_html__( 'Requests per minute for each role', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<table class="aicfab-role-limits" role="presentation"><tbody>';

		foreach ( AI_Chat_Bedrock_Rate_Limits::roles() as $role => $label ) {
			$field = 'aicfab_role_limit_' . $role;
			$value = isset( $limits[ $role ] ) ? (int) $limits[ $role ] : '';
			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" id="%1$s" class="small-text" name="ai_chat_bedrock_settings[role_limits][%3$s]" value="%4$s" min="0" max="%5$d" step="1" placeholder="%6$s"></td></tr>',
				esc_attr( $field ),
				esc_html( $label ),
				esc_attr( $role ),
				esc_attr( (string) $value ),
				(int) AI_Chat_Bedrock_Rate_Limits::MAX_PER_ROLE,
				esc_attr( (string) $fallback )
			);
		}

		echo '</tbody></table></fieldset>';
		echo '<p class="description">' . esc_html__( 'Optional. Leave a row empty to use the site-wide limit above. A visitor with several roles gets the most permissive of them, and requests are still counted per profile.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		echo '<p class="description"><strong>' . esc_html( AI_Chat_Bedrock_Rate_Limits::describe( $fallback ) ) . '</strong></p>';
	}

	public function managed_prompt_render() {
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Managed prompt', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		$this->text_input( 'prompt_id', '', 2048 );
		echo '<br><label for="aicfab_field_prompt_version">' . esc_html__( 'Version', 'ai-chat-for-amazon-bedrock' ) . '</label> ';
		echo '<input type="text" id="aicfab_field_prompt_version" name="ai_chat_bedrock_settings[prompt_version]" value="' . esc_attr( (string) $this->option( 'prompt_version', '' ) ) . '" size="10" maxlength="10" placeholder="DRAFT">';
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Optional. Point at a prompt in Amazon Bedrock Prompt Management and its text replaces the system prompt above, so one prompt can be reviewed in AWS and reused by every site. The prompt must live in the same region as the chat. Leave the version empty to follow the draft.', 'ai-chat-for-amazon-bedrock' ) . '</p>';

		if ( ! AI_Chat_Bedrock_Prompts::enabled() ) {
			return;
		}

		$text = AI_Chat_Bedrock_Prompts::text();
		if ( is_wp_error( $text ) ) {
			echo '<div class="aicfab-prompt-error notice notice-error inline"><p>' . esc_html( $text->get_error_message() ) . '</p></div>';
			echo '<p class="description">' . esc_html__( 'While the prompt cannot be read, the system prompt above is used instead.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
			return;
		}

		echo '<p class="description"><strong>' . esc_html__( 'Prompt in use:', 'ai-chat-for-amazon-bedrock' ) . '</strong></p>';
		echo '<pre class="aicfab-prompt-preview">' . esc_html( $text ) . '</pre>';

		$unresolved = AI_Chat_Bedrock_Prompts::unresolved( $text );
		if ( ! empty( $unresolved ) ) {
			echo '<p class="description">';
			printf(
				/* translators: %s: comma separated list of variable names. */
				esc_html__( 'These variables are sent literally because this plugin cannot resolve them: %s', 'ai-chat-for-amazon-bedrock' ),
				esc_html( AI_Chat_Bedrock_Translation::items( $unresolved ) )
			);
			echo '</p>';
		}
	}

	public function embedding_model_render() {
		$choices = array( '' => __( 'Off, use keyword search only', 'ai-chat-for-amazon-bedrock' ) ) + AI_Chat_Bedrock_Embeddings::models();
		$this->select( 'embedding_model_id', $choices, '' );
		echo '<p class="description">' . esc_html__( 'Finds content by meaning instead of shared words. It adds one embedding request per question, plus one per passage while indexing. Keyword search still runs when nothing relevant is found. Only what a signed-out visitor can read is indexed.', 'ai-chat-for-amazon-bedrock' ) . '</p>';

		// Always printed: a checkbox that is missing from the form is saved as unchecked.
		$background = ! empty( $this->option( 'embedding_background', false ) );
		echo '<p><label><input type="checkbox" name="ai_chat_bedrock_settings[embedding_background]" value="1" ' . checked( $background, true, false ) . '> ' . esc_html__( 'Keep the index up to date in the background', 'ai-chat-for-amazon-bedrock' ) . '</label></p>';

		if ( ! AI_Chat_Bedrock_Embeddings::enabled() ) {
			return;
		}

		$status = AI_Chat_Bedrock_Embeddings::status();
		echo '<p class="aicfab-index-status">';
		echo esc_html(
			AI_Chat_Bedrock_Translation::sentences(
				sprintf(
					/* translators: 1: indexed item count, 2: total published item count. */
					_n( 'Indexed %1$s of %2$s published item.', 'Indexed %1$s of %2$s published items.', (int) $status['total'], 'ai-chat-for-amazon-bedrock' ),
					number_format_i18n( (int) $status['indexed'] ),
					number_format_i18n( (int) $status['total'] )
				),
				empty( $status['delete_pending'] ) ? '' : sprintf(
					/* translators: %s: number of vectors waiting to be deleted. */
					_n( '%s removed passage is still waiting to be deleted from S3 Vectors.', '%s removed passages are still waiting to be deleted from S3 Vectors.', (int) $status['delete_pending'], 'ai-chat-for-amazon-bedrock' ),
					number_format_i18n( (int) $status['delete_pending'] )
				)
			)
		);
		echo '</p>';
		if ( isset( $status['searchable'] ) && (int) $status['total'] > (int) $status['searchable'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: number of items compared per question. */
					__( 'With vectors stored in the WordPress database, each question is compared with the %s most recent items only. Choose Amazon S3 Vectors below to search everything.', 'ai-chat-for-amazon-bedrock' ),
					number_format_i18n( (int) $status['searchable'] )
				)
			) . '</p></div>';
		}
		// The delete handler was there, but nothing on any screen submitted to it. This field
		// sits inside the settings form, so its button belongs to a form printed after that one.
		echo '<p><button type="button" class="button" id="aicfab-index-embeddings">' . esc_html__( 'Index content now', 'ai-chat-for-amazon-bedrock' ) . '</button> <button type="submit" class="button" form="aicfab-clear-embeddings">' . esc_html__( 'Delete the index', 'ai-chat-for-amazon-bedrock' ) . '</button> <span id="aicfab-index-progress" role="status"></span></p>';
		if ( $background ) {
			$next = wp_next_scheduled( AI_Chat_Bedrock_Embeddings::CRON_HOOK );
			echo '<p class="description">';
			if ( $next ) {
				printf(
					/* translators: %s: human readable time until the next scheduled run. */
					esc_html__( 'Next background batch in %s. On a large site, run wp ai-chat-bedrock index once to finish faster.', 'ai-chat-for-amazon-bedrock' ),
					esc_html( human_time_diff( time(), (int) $next ) )
				);
			} else {
				esc_html_e( 'The background run will be scheduled on the next page load.', 'ai-chat-for-amazon-bedrock' );
			}
			echo '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Indexing runs in small batches. Editing a post marks it for re-indexing on the next run.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}

	public function vector_store_render() {
		$store = AI_Chat_Bedrock_Embeddings::store( get_option( 'ai_chat_bedrock_settings', array() ) );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Vector store', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		$this->select(
			'vector_store',
			array(
				'post_meta'  => __( 'WordPress database (small sites)', 'ai-chat-for-amazon-bedrock' ),
				's3_vectors' => __( 'Amazon S3 Vectors', 'ai-chat-for-amazon-bedrock' ),
			),
			'post_meta'
		);
		echo '<p class="description">' . esc_html__( 'The database keeps one vector per post and compares a question with the most recent 500. Amazon S3 Vectors stores every passage of every post and searches all of them, at a cost per stored gigabyte and per query.', 'ai-chat-for-amazon-bedrock' ) . '</p>';

		echo '<p><label for="aicfab_field_s3_vectors_bucket">' . esc_html__( 'Vector bucket', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		$this->text_input( 's3_vectors_bucket', '', 63 );
		echo '</p><p><label for="aicfab_field_s3_vectors_index">' . esc_html__( 'Vector index', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		$this->text_input( 's3_vectors_index', '', 63 );
		echo '</p><p><label for="aicfab_field_s3_vectors_region">' . esc_html__( 'Region of the bucket', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		$this->select( 's3_vectors_region', array( '' => __( 'Same as Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ) ) + AI_Chat_Bedrock_Models::regions(), '' );
		echo '</p></fieldset>';
		echo '<p class="description">' . esc_html__( 'Create the vector bucket in the Amazon S3 console, then check or create the index here. Several sites can share one index: each only reads and deletes its own vectors. Needs s3vectors:PutVectors, QueryVectors, GetVectors, DeleteVectors, ListVectors and GetIndex, plus CreateIndex to create it from here.', 'ai-chat-for-amazon-bedrock' ) . '</p>';

		if ( 's3_vectors' === $store && AI_Chat_Bedrock_S3_Vectors::enabled() ) {
			echo '<p><button type="button" class="button" id="aicfab-s3v-check" data-op="check">' . esc_html__( 'Check the index', 'ai-chat-for-amazon-bedrock' ) . '</button> <button type="button" class="button" id="aicfab-s3v-create" data-op="create">' . esc_html__( 'Create the index', 'ai-chat-for-amazon-bedrock' ) . '</button> <span id="aicfab-s3v-status" role="status"></span></p>';
		}
	}

	public function integrations_section_callback() {
		echo '<p>' . esc_html__( 'Optional adjustments to plugins this site runs. Each is off until you turn it on, and does nothing while the plugin it adjusts is inactive.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function github_read_scope_render() {
		$this->integration_checkbox(
			'github_read_scope',
			'fluentauth',
			__( 'Ask GitHub for read-only access when visitors sign in with GitHub', 'ai-chat-for-amazon-bedrock' ),
			__( 'FluentAuth requests the "user" scope, which also lets the site change the visitor\'s GitHub profile. Signing in only needs read:user and user:email.', 'ai-chat-for-amazon-bedrock' ),
			__( 'Requires FluentAuth.', 'ai-chat-for-amazon-bedrock' )
		);
	}
	public function social_only_registration_render() {
		$this->integration_checkbox(
			'social_only_registration',
			'fluentauth',
			__( 'Sign up through social login only', 'ai-chat-for-amazon-bedrock' ),
			__( 'Social sign-up needs "Anyone can register", which also opens the WordPress registration form. That form emails a password link and attracts spam accounts. This sends it to the login page, where the social buttons are, and hides the Register link.', 'ai-chat-for-amazon-bedrock' ),
			__( 'Requires FluentAuth.', 'ai-chat-for-amazon-bedrock' )
		);
	}
	public function hreflang_x_default_render() {
		$detected  = AI_Chat_Bedrock_Integrations::detected();
		$languages = AI_Chat_Bedrock_Integrations::languages();
		$current   = (string) $this->option( 'hreflang_x_default', '' );
		if ( '' !== $current && ! isset( $languages[ $current ] ) ) {
			$languages[ $current ] = $current;
		}
		echo '<select id="aicfab_field_hreflang_x_default" name="ai_chat_bedrock_settings[hreflang_x_default]" ' . disabled( $detected['polylang'], false, false ) . '>';
		echo '<option value="">' . esc_html__( 'None', 'ai-chat-for-amazon-bedrock' ) . '</option>';
		foreach ( $languages as $slug => $name ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $current, $slug, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
		if ( ! $detected['polylang'] && '' !== $current ) {
			echo '<input type="hidden" name="ai_chat_bedrock_settings[hreflang_x_default]" value="' . esc_attr( $current ) . '">';
		}
		echo '<p class="description">' . esc_html( $detected['polylang'] ? __( 'Polylang lists each translation of a page for search engines but no x-default, so they guess which one to show a reader whose language the site does not have. The edition chosen here is named as the default.', 'ai-chat-for-amazon-bedrock' ) : __( 'Requires Polylang.', 'ai-chat-for-amazon-bedrock' ) ) . '</p>';
	}
	public function distribution_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Publishing record', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[distribution_enabled]" value="1" ' . checked( ! empty( $options['distribution_enabled'] ), true, false ) . '> ' . esc_html__( 'Keep a record of where each post is published on Bilibili, YouTube or Xiaohongshu', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[distribution_links]" value="1" ' . checked( ! empty( $options['distribution_links'] ), true, false ) . '> ' . esc_html__( 'Link to the public ones under each post', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Off by default. Agents read a post\'s publishing package and record what they published, with the item\'s ID, address, account, language and status, through the abilities and the MCP server, using the WordPress permissions of their account; a new edition can be marked as replacing the old one. The record shows in the Published elsewhere box when editing a post. The plugin never signs in to Bilibili or Xiaohongshu: neither has a publishing API for individual creators, so publishing there stays with the agent or person using their creator tools.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function youtube_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$client  = AI_Chat_Bedrock_YouTube::client( $options );
		$channel = AI_Chat_Bedrock_YouTube::channel();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['aicfab_youtube'] ) ? sanitize_key( wp_unslash( $_GET['aicfab_youtube'] ) ) : '';
		$notes  = array(
			'youtube_connected'    => __( 'The YouTube channel is connected.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_disconnected' => __( 'The YouTube channel is disconnected, and the site\'s access was revoked at Google.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_client'       => __( 'Save the OAuth client ID and secret first.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_state'        => __( 'The sign-in did not come back from the request this site made, so it was ignored. Try again.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_denied'       => __( 'Google did not grant access to the channel.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_token'        => __( 'Google did not return a lasting token. Check the client secret and the redirect URI, then try again.', 'ai-chat-for-amazon-bedrock' ),
			'youtube_channel'      => __( 'The Google account has no YouTube channel.', 'ai-chat-for-amazon-bedrock' ),
		);
		if ( isset( $notes[ $notice ] ) ) {
			echo '<p><strong>' . esc_html( $notes[ $notice ] ) . '</strong></p>';
		}
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'YouTube uploads', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label for="aicfab_field_youtube_client_id">' . esc_html__( 'OAuth client ID', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="text" id="aicfab_field_youtube_client_id" class="regular-text" name="ai_chat_bedrock_settings[youtube_client_id]" value="' . esc_attr( $client['id'] ) . '" placeholder="' . esc_attr__( 'Client ID from Google Cloud', 'ai-chat-for-amazon-bedrock' ) . '"><br>';
		echo '<label for="aicfab_field_youtube_client_secret">' . esc_html__( 'OAuth client secret', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="password" id="aicfab_field_youtube_client_secret" class="regular-text" name="ai_chat_bedrock_settings[youtube_client_secret]" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== $client['secret'] ? __( 'Saved — enter a value to replace', 'ai-chat-for-amazon-bedrock' ) : '' ) . '"><br>';
		echo '<label for="aicfab_field_youtube_daily_uploads">' . esc_html__( 'Uploads a day', 'ai-chat-for-amazon-bedrock' ) . '</label> <input type="number" id="aicfab_field_youtube_daily_uploads" class="small-text" name="ai_chat_bedrock_settings[youtube_daily_uploads]" value="' . esc_attr( AI_Chat_Bedrock_YouTube::daily_limit( $options ) ) . '" min="1" max="' . esc_attr( AI_Chat_Bedrock_YouTube::MAX_DAILY ) . '">';
		echo '</fieldset>';
		if ( null !== $channel ) {
			/* translators: %s: YouTube channel name. */
			echo '<p>' . esc_html( sprintf( __( 'Connected to the channel %s.', 'ai-chat-for-amazon-bedrock' ), $channel['title'] ) ) . ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ai_chat_bedrock_youtube_disconnect' ), 'ai_chat_bedrock_youtube_disconnect' ) ) . '">' . esc_html__( 'Disconnect', 'ai-chat-for-amazon-bedrock' ) . '</a></p>';
		} elseif ( '' !== $client['id'] && '' !== $client['secret'] ) {
			echo '<p><a class="button button-secondary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ai_chat_bedrock_youtube_connect' ), 'ai_chat_bedrock_youtube_connect' ) ) . '">' . esc_html__( 'Connect a YouTube channel', 'ai-chat-for-amazon-bedrock' ) . '</a></p>';
		}
		/* translators: %s: redirect URI to register in Google Cloud. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Off until a channel is connected. In Google Cloud, enable the YouTube Data API v3, create an OAuth client of type Web application with the authorized redirect URI %s, and enter its ID and secret here; then connect the channel. Uploads run in the background from the Published elsewhere box of a post, with the publishing record on, and are added to it. Google keeps videos uploaded from a project that has not passed YouTube\'s audit private, and each upload uses 1,600 of the 10,000 quota units a project gets a day.', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_YouTube::redirect_uri() ) ) . '</p>';
	}
	public function bilibili_embeds_render() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[bilibili_embeds]" value="1" ' . checked( ! empty( $options['bilibili_embeds'] ), true, false ) . '> ' . esc_html__( 'Embed Bilibili videos from their links, as WordPress does for YouTube', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. A Bilibili video address on a line of its own, or in an Embed block, shows the Bilibili player. The player is loaded from Bilibili, which can then set its own cookies, so add the suggested text from Settings > Privacy to your privacy policy before turning it on.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function organization_author_render() {
		$this->integration_checkbox(
			'organization_author',
			'yoast',
			__( 'Credit articles to the organization', 'ai-chat-for-amazon-bedrock' ),
			__( 'Yoast SEO names the account that published a post as its author. When Yoast is set to represent an organization, this names the organization instead, in structured data, the author meta tag and Slack previews.', 'ai-chat-for-amazon-bedrock' ),
			__( 'Requires Yoast SEO.', 'ai-chat-for-amazon-bedrock' )
		);
	}

	public function woocommerce_section_callback() {
		echo '<p>' . esc_html__( 'Answers about products and orders from the store itself, and help writing product pages. Each is off until you turn it on.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function woo_catalog_render() {
		$checked = ! empty( $this->option( 'woo_catalog', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[woo_catalog]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Answer product questions from the live catalog', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Matching products are looked up for each question, by name, description, SKU or category, and their current price, stock, rating and attributes are given to the model, so it does not quote an old price. The products are shown as cards under the answer, with a link to add simple products to the cart. On a product page, that product comes first. Products that are not published, are hidden from the shop or search, or are out of stock on a store that hides those, are never used.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function woo_catalog_limit_render() {
		$value = AI_Chat_Bedrock_WooCommerce::limit( array( 'woo_catalog_limit' => $this->option( 'woo_catalog_limit', AI_Chat_Bedrock_WooCommerce::DEFAULT_PRODUCTS ) ) );
		echo '<input type="number" id="aicfab_field_woo_catalog_limit" class="small-text" name="ai_chat_bedrock_settings[woo_catalog_limit]" value="' . esc_attr( (string) $value ) . '" min="1" max="' . esc_attr( (string) AI_Chat_Bedrock_WooCommerce::MAX_PRODUCTS ) . '">';
		echo '<p class="description">' . esc_html__( 'More products give the model more to compare but increase input tokens and cost.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function woo_orders_render() {
		$checked = ! empty( $this->option( 'woo_orders', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[woo_orders]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Let signed-in customers ask about their own orders', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Only when a question is about orders, shipping or returns, the customer\'s five latest orders are given to the model: number, dates, status, items, total, shipping method and tracking number. Addresses, email, phone and payment details are never sent, and nobody can see another customer\'s orders. This sends customer data to Amazon Bedrock, so add the suggested text under Settings, Privacy, Policy Guide to your privacy policy first.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function woo_product_assistant_render() {
		$checked = ! empty( $this->option( 'woo_product_assistant', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[woo_product_assistant]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Draft product descriptions and summarize reviews', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Adds a box to the product edit screen that writes the short and the full description from the product\'s own attributes, categories and text, without inventing specifications, and sums up what reviews praise and criticize, without reviewer names. Drafts go into the editor for you to review and are saved only when you update the product.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}

	private function integration_checkbox( $key, $plugin, $label, $description, $missing ) {
		$detected = AI_Chat_Bedrock_Integrations::detected();
		$active   = ! empty( $detected[ $plugin ] );
		$checked  = ! empty( $this->option( $key, false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[' . esc_attr( $key ) . ']" value="1" ' . checked( $checked, true, false ) . ' ' . disabled( $active, false, false ) . '> ' . esc_html( $label ) . '</label>';
		echo '<p class="description">' . esc_html( $active ? $description : $missing ) . '</p>';
		if ( ! $active && $checked ) {
			// A disabled checkbox is not submitted, so keep the choice until the plugin is back.
			echo '<input type="hidden" name="ai_chat_bedrock_settings[' . esc_attr( $key ) . ']" value="1">';
		}
	}

	public function media_assistant_render() {
		$checked = ! empty( $this->option( 'media_assistant', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[media_assistant]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Generate image alt text and post excerpts', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Adds a Generate alt text action to the media library and an excerpt helper for posts. Alt text needs a model that accepts images, such as Claude. Existing alt text is never replaced unless you ask.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function model_id_render() {
		$options = AI_Chat_Bedrock_Models::options();
		$this->select( 'model_id', $options, AI_Chat_Bedrock_Models::DEFAULT_MODEL );
		echo '<p class="description">' . esc_html__( 'The list is discovered from your AWS account and region. Many current models require a cross-region inference profile ID.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		echo '<p><button type="button" class="button" id="aicfab-refresh-models">' . esc_html__( 'Refresh model list', 'ai-chat-for-amazon-bedrock' ) . '</button> <span id="aicfab-refresh-models-status" role="status"></span></p>';
	}
	public function fallback_model_render() {
		$options = array( '' => __( 'No fallback', 'ai-chat-for-amazon-bedrock' ) ) + AI_Chat_Bedrock_Models::options();
		$this->select( 'fallback_model_id', $options, '' );
		echo '<p class="description">' . esc_html__( 'Used only when the main model cannot answer because access was denied, the request was throttled, or Amazon Bedrock was unreachable. The reply states which model answered. Requests rejected for other reasons are never retried.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function image_model_render() {
		$choices = array( '' => __( 'Off', 'ai-chat-for-amazon-bedrock' ) ) + AI_Chat_Bedrock_Images::models();
		$this->select( 'image_model_id', $choices, '' );
		echo '<p class="description">' . esc_html__( 'Lets plugins that use the WordPress AI Client generate images with Stability AI, and adds Remove background and Upscale to images in the Media Library when the media helpers are on. Results are saved as new images; originals are never changed. Each image is one paid request and counts toward the daily limit.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: AWS region code, such as us-west-2. */
				__( 'The Stability models run in %s, whatever region the chat uses, so prompts and images are processed there. The guardrail, if set, checks each prompt first in your own region.', 'ai-chat-for-amazon-bedrock' ),
				AI_Chat_Bedrock_Images::region()
			)
		) . '</p>';
	}
	public function max_tokens_render() {
		$value = $this->option( 'max_tokens', 1000 );
		echo '<input type="number" id="aicfab_field_max_tokens" class="small-text" name="ai_chat_bedrock_settings[max_tokens]" value="' . esc_attr( $value ) . '" min="100" max="4000" step="100">';
	}
	public function temperature_render() {
		$value = $this->option( 'temperature', 0.7 );
		echo '<input type="number" id="aicfab_field_temperature" class="small-text" name="ai_chat_bedrock_settings[temperature]" value="' . esc_attr( $value ) . '" min="0" max="1" step="0.1">';
		echo '<p class="description">' . esc_html__( 'Not sent to Claude Opus 4.7, Sonnet 5, Opus 5 and newer Claude models, because Amazon Bedrock rejects it for them.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function system_prompt_render() {
		echo '<textarea id="aicfab_field_system_prompt" name="ai_chat_bedrock_settings[system_prompt]" rows="5" class="large-text" maxlength="8000">' . esc_textarea( $this->option( 'system_prompt', 'You are a helpful AI assistant powered by Amazon Bedrock.' ) ) . '</textarea>';
	}
	public function chat_title_render() {
		$this->text_input( 'chat_title', 'Chat with AI', 120 );
	}
	public function welcome_message_render() {
		$this->text_input( 'welcome_message', 'Hello! How can I help you today?', 500, 'large-text' );
	}
	public function allow_public_chat_render() {
		$checked = ! empty( $this->option( 'allow_public_chat', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[allow_public_chat]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Allow unauthenticated visitors to use paid Bedrock requests', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Disabled by default. Enabling this can incur AWS charges even with rate limiting.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function rate_limit_render() {
		$value = $this->option( 'rate_limit_per_minute', 5 );
		echo '<input type="number" id="aicfab_field_rate_limit_per_minute" class="small-text" name="ai_chat_bedrock_settings[rate_limit_per_minute]" value="' . esc_attr( $value ) . '" min="1" max="60">';
	}
	public function popup_site_wide_render() {
		$checked = ! empty( $this->option( 'popup_site_wide', false ) );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Floating chat', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[popup_site_wide]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Show a floating chat button on every page', 'ai-chat-for-amazon-bedrock' ) . '</label><br>';
		$profiles = AI_Chat_Bedrock_Profiles::choices();
		echo '<label for="aicfab_field_popup_profile">' . esc_html__( 'Profile', 'ai-chat-for-amazon-bedrock' ) . '</label> <select id="aicfab_field_popup_profile" name="ai_chat_bedrock_settings[popup_profile]">';
		$current = (string) $this->option( 'popup_profile', '' );
		foreach ( $profiles as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Pages that already contain the chat block or shortcode are left unchanged. Use a profile with guest access if visitors should be able to chat.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function chat_color_scheme_render() {
		$current = AI_Chat_Bedrock_Chat_Request::color_scheme( $this->option( 'chat_color_scheme', 'light' ) );
		$choices = array(
			'light' => __( 'Light (default)', 'ai-chat-for-amazon-bedrock' ),
			'dark'  => __( 'Dark', 'ai-chat-for-amazon-bedrock' ),
			'auto'  => __( 'Follow the visitor\'s device', 'ai-chat-for-amazon-bedrock' ),
		);
		echo '<select id="aicfab_field_chat_color_scheme" name="ai_chat_bedrock_settings[chat_color_scheme]">';
		foreach ( $choices as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Choose what matches your theme. Following the device turns the chat dark for visitors in dark mode, even on a light theme.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function debug_mode_render() {
		$checked = 'on' === $this->option( 'debug_mode', 'off' );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[debug_mode]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Log redacted request metadata', 'ai-chat-for-amazon-bedrock' ) . '</label>';
	}

	/**
	 * Register a settings notice when the environment can show one.
	 *
	 * WordPress only defines add_settings_error() inside wp-admin. The validator is also run
	 * by the
	 * configuration import, so calling it directly made the validator admin-only.
	 *
	 * @param string $code    Notice code.
	 * @param string $message Notice text.
	 * @param string $type    Notice type.
	 */
	private function notice( $code, $message, $type = 'error' ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'ai_chat_bedrock_settings', $code, $message, $type );
		}
	}

	public function validate_settings( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = get_option( 'ai_chat_bedrock_settings', array() );
		$current = is_array( $current ) ? $current : array();
		$output  = array();

		// A tab only submits its own fields, so remember which keys it owns.
		$submitted = isset( $input['_aicfab_fields'] ) ? (array) $input['_aicfab_fields'] : array();
		$submitted = array_filter( array_map( 'sanitize_key', $submitted ) );
		unset( $input['_aicfab_fields'] );
		$regions              = AI_Chat_Bedrock_Models::regions();
		$region               = isset( $input['aws_region'] ) ? sanitize_key( $input['aws_region'] ) : 'us-east-1';
		$model                = isset( $input['model_id'] ) ? sanitize_text_field( $input['model_id'] ) : AI_Chat_Bedrock_Models::DEFAULT_MODEL;
		$output['aws_region'] = isset( $regions[ $region ] ) ? $region : 'us-east-1';
		$output['model_id']   = AI_Chat_Bedrock_Models::is_valid_id( $model ) ? $model : AI_Chat_Bedrock_Models::DEFAULT_MODEL;

		$fallback = isset( $input['fallback_model_id'] ) ? sanitize_text_field( $input['fallback_model_id'] ) : '';
		if ( '' !== $fallback && ( ! AI_Chat_Bedrock_Models::is_valid_id( $fallback ) || $fallback === $output['model_id'] ) ) {
			$this->notice( 'fallback_model_id', __( 'The fallback model must be a valid model that differs from the main model; it was cleared.', 'ai-chat-for-amazon-bedrock' ) );
			$fallback = '';
		}
		$output['fallback_model_id'] = $fallback;

		$image = isset( $input['image_model_id'] ) ? sanitize_text_field( $input['image_model_id'] ) : '';
		if ( '' !== $image && ! isset( AI_Chat_Bedrock_Images::models()[ $image ] ) ) {
			$this->notice( 'image_model_id', __( 'That image model is not supported; image generation was left off.', 'ai-chat-for-amazon-bedrock' ) );
			$image = '';
		}
		$output['image_model_id'] = $image;

		if ( ! AI_Chat_Bedrock_Models::is_valid_id( $model ) ) {
			$this->notice( 'model_id', __( 'The submitted model ID was not valid; the default model was kept.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( isset( $current['aws_region'] ) && $current['aws_region'] !== $output['aws_region'] ) {
			AI_Chat_Bedrock_Models::flush_cache();
		}

		foreach ( array( 'aws_access_key', 'aws_secret_key', 'aws_session_token' ) as $key ) {
			$new_value = isset( $input[ $key ] ) ? trim( sanitize_text_field( $input[ $key ] ) ) : '';
			if ( '' === $new_value ) {
				$output[ $key ] = isset( $current[ $key ] ) ? $current[ $key ] : '';
				continue;
			}
			$encrypted = AI_Chat_Bedrock_Security::encrypt_secret( $new_value );
			if ( '' === $encrypted ) {
				$this->notice( 'credential_encryption', __( 'The credential could not be encrypted; the existing value was preserved.', 'ai-chat-for-amazon-bedrock' ) );
				$output[ $key ] = isset( $current[ $key ] ) ? $current[ $key ] : '';
			} else {
				$output[ $key ] = $encrypted;
			}
		}

		// An API key is a bearer token, so a malformed paste is refused rather than stored.
		$raw_api_key = isset( $input['bedrock_api_key'] ) && is_string( $input['bedrock_api_key'] ) ? trim( $input['bedrock_api_key'] ) : '';
		$new_api_key = AI_Chat_Bedrock_AWS_Credentials::clean_api_key( $raw_api_key );
		if ( ! empty( $input['bedrock_api_key_clear'] ) ) {
			$output['bedrock_api_key'] = '';
		} elseif ( '' !== $new_api_key ) {
			$encrypted = AI_Chat_Bedrock_Security::encrypt_secret( $new_api_key );
			if ( '' === $encrypted ) {
				$this->notice( 'credential_encryption', __( 'The credential could not be encrypted; the existing value was preserved.', 'ai-chat-for-amazon-bedrock' ) );
				$output['bedrock_api_key'] = isset( $current['bedrock_api_key'] ) ? $current['bedrock_api_key'] : '';
			} else {
				$output['bedrock_api_key'] = $encrypted;
			}
		} else {
			if ( '' !== $raw_api_key ) {
				$this->notice( 'bedrock_api_key', __( 'That does not look like an Amazon Bedrock API key, so it was not saved.', 'ai-chat-for-amazon-bedrock' ) );
			}
			$output['bedrock_api_key'] = isset( $current['bedrock_api_key'] ) ? $current['bedrock_api_key'] : '';
		}
		unset( $output['bedrock_api_key_clear'] );

		$output['max_tokens']            = max( 100, min( 4000, isset( $input['max_tokens'] ) ? absint( $input['max_tokens'] ) : 1000 ) );
		$output['temperature']           = max( 0, min( 1, isset( $input['temperature'] ) ? (float) $input['temperature'] : 0.7 ) );
		$output['system_prompt']         = isset( $input['system_prompt'] ) ? AI_Chat_Bedrock_Security::string_substr( sanitize_textarea_field( $input['system_prompt'] ), 0, 8000 ) : '';
		$output['chat_title']            = isset( $input['chat_title'] ) ? sanitize_text_field( $input['chat_title'] ) : 'Chat with AI';
		$output['welcome_message']       = isset( $input['welcome_message'] ) ? sanitize_text_field( $input['welcome_message'] ) : 'Hello! How can I help you today?';
		$output['suggested_questions']   = isset( $input['suggested_questions'] ) ? AI_Chat_Bedrock_Chat_Request::sanitize_suggestions( $input['suggested_questions'] ) : '';
		$output['allow_public_chat']     = ! empty( $input['allow_public_chat'] );
		$output['rate_limit_per_minute'] = max( 1, min( 60, isset( $input['rate_limit_per_minute'] ) ? absint( $input['rate_limit_per_minute'] ) : 5 ) );

		if ( in_array( 'role_limits', $submitted, true ) || isset( $input['role_limits'] ) ) {
			// Stored separately from the settings blob so role changes cannot bloat it.
			AI_Chat_Bedrock_Rate_Limits::save( isset( $input['role_limits'] ) ? (array) $input['role_limits'] : array() );
		}
		unset( $output['role_limits'] );
		$output['aws_use_role_credentials'] = ! empty( $input['aws_use_role_credentials'] );
		$output['enable_streaming']         = empty( $input['enable_streaming'] ) ? 'off' : 'on';
		$output['debug_mode']               = ! empty( $input['debug_mode'] ) ? 'on' : 'off';
		$output['popup_site_wide']          = ! empty( $input['popup_site_wide'] );
		$output['popup_profile']            = isset( $input['popup_profile'] ) ? AI_Chat_Bedrock_Profiles::sanitize_key( $input['popup_profile'] ) : '';
		$output['chat_color_scheme']        = AI_Chat_Bedrock_Chat_Request::color_scheme( isset( $input['chat_color_scheme'] ) ? $input['chat_color_scheme'] : '' );

		$guardrail_id           = isset( $input['guardrail_id'] ) ? trim( sanitize_text_field( $input['guardrail_id'] ) ) : '';
		$output['guardrail_id'] = preg_match( '#^[A-Za-z0-9._:/-]{0,200}$#', $guardrail_id ) ? $guardrail_id : '';
		if ( '' !== $guardrail_id && '' === $output['guardrail_id'] ) {
			$this->notice( 'guardrail_id', __( 'The guardrail identifier contained unsupported characters and was cleared.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$guardrail_version             = isset( $input['guardrail_version'] ) ? strtoupper( trim( sanitize_text_field( $input['guardrail_version'] ) ) ) : 'DRAFT';
		$output['guardrail_version']   = preg_match( '/^(?:DRAFT|[0-9]{1,10})$/', $guardrail_version ) ? $guardrail_version : 'DRAFT';
		$output['daily_request_limit'] = min( 100000, isset( $input['daily_request_limit'] ) ? absint( $input['daily_request_limit'] ) : 0 );

		$output['enable_site_context'] = ! empty( $input['enable_site_context'] );
		$output['context_results']     = max( 1, min( 8, isset( $input['context_results'] ) ? absint( $input['context_results'] ) : 3 ) );
		$output['show_sources']        = ! empty( $input['show_sources'] );
		$output['include_noindex']     = ! empty( $input['include_noindex'] );
		$output['abilities_tools']     = ! empty( $input['abilities_tools'] );
		$output['site_abilities']      = ! empty( $input['site_abilities'] );
		$output['site_ontology']       = ! empty( $input['site_ontology'] );
		$output['business_metrics']    = ! empty( $input['business_metrics'] );
		$output['editor_assistant']    = ! empty( $input['editor_assistant'] );
		$output['log_conversations']   = ! empty( $input['log_conversations'] );
		$output['media_assistant']     = ! empty( $input['media_assistant'] );

		$embedding = isset( $input['embedding_model_id'] ) ? sanitize_text_field( $input['embedding_model_id'] ) : '';
		$known     = AI_Chat_Bedrock_Embeddings::models();
		if ( '' !== $embedding && ! isset( $known[ $embedding ] ) ) {
			$this->notice( 'embedding_model_id', __( 'That embedding model is not supported; semantic search was left off.', 'ai-chat-for-amazon-bedrock' ) );
			$embedding = '';
		}
		$output['embedding_model_id']   = $embedding;
		$output['embedding_background'] = ! empty( $input['embedding_background'] );

		$store                  = isset( $input['vector_store'] ) ? sanitize_key( $input['vector_store'] ) : 'post_meta';
		$output['vector_store'] = 's3_vectors' === $store ? 's3_vectors' : 'post_meta';
		foreach ( array( 's3_vectors_bucket', 's3_vectors_index' ) as $key ) {
			$name           = isset( $input[ $key ] ) ? strtolower( trim( sanitize_text_field( $input[ $key ] ) ) ) : '';
			$output[ $key ] = '' === $name || AI_Chat_Bedrock_S3_Vectors::valid_name( $name ) ? $name : '';
			if ( '' !== $name && '' === $output[ $key ] ) {
				$this->notice( $key, __( 'S3 Vectors bucket and index names are 3 to 63 lowercase letters, digits, hyphens or dots; the value was cleared.', 'ai-chat-for-amazon-bedrock' ) );
			}
		}
		$vector_region               = isset( $input['s3_vectors_region'] ) ? sanitize_key( $input['s3_vectors_region'] ) : '';
		$output['s3_vectors_region'] = isset( $regions[ $vector_region ] ) ? $vector_region : '';
		if ( 's3_vectors' === $output['vector_store'] && ( '' === $output['s3_vectors_bucket'] || '' === $output['s3_vectors_index'] ) ) {
			$this->notice( 'vector_store', __( 'Amazon S3 Vectors needs a bucket and an index name. Until both are set, semantic search is off.', 'ai-chat-for-amazon-bedrock' ), 'warning' );
		}

		$output['github_read_scope']        = ! empty( $input['github_read_scope'] );
		$output['social_only_registration'] = ! empty( $input['social_only_registration'] );
		$output['organization_author']      = ! empty( $input['organization_author'] );
		$output['distribution_enabled']     = ! empty( $input['distribution_enabled'] );
		$output['distribution_links']       = ! empty( $input['distribution_links'] );
		$output['bilibili_embeds']          = ! empty( $input['bilibili_embeds'] );
		$output['youtube_client_id']        = isset( $input['youtube_client_id'] ) ? AI_Chat_Bedrock_YouTube::clean_client_id( $input['youtube_client_id'] ) : '';
		$output['youtube_daily_uploads']    = AI_Chat_Bedrock_YouTube::daily_limit( array( 'youtube_daily_uploads' => isset( $input['youtube_daily_uploads'] ) ? $input['youtube_daily_uploads'] : '' ) );
		$raw_youtube_secret                 = isset( $input['youtube_client_secret'] ) && is_string( $input['youtube_client_secret'] ) ? trim( $input['youtube_client_secret'] ) : '';
		$output['youtube_client_secret']    = isset( $current['youtube_client_secret'] ) ? $current['youtube_client_secret'] : '';
		if ( preg_match( '/^[A-Za-z0-9_-]{10,100}$/', $raw_youtube_secret ) ) {
			$encrypted = AI_Chat_Bedrock_Security::encrypt_secret( $raw_youtube_secret );
			if ( '' !== $encrypted ) {
				$output['youtube_client_secret'] = $encrypted;
			}
		} elseif ( '' !== $raw_youtube_secret ) {
			$this->notice( 'youtube_client_secret', __( 'That does not look like an OAuth client secret, so it was not saved.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( isset( $input['youtube_client_id'] ) && '' !== trim( (string) $input['youtube_client_id'] ) && '' === $output['youtube_client_id'] ) {
			$this->notice( 'youtube_client_id', __( 'That is not an OAuth client ID from Google Cloud, so it was not saved.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$output['woo_catalog']           = ! empty( $input['woo_catalog'] );
		$output['woo_catalog_limit']     = AI_Chat_Bedrock_WooCommerce::limit( $input );
		$output['woo_orders']            = ! empty( $input['woo_orders'] );
		$output['woo_product_assistant'] = ! empty( $input['woo_product_assistant'] );
		$x_default                       = isset( $input['hreflang_x_default'] ) ? sanitize_key( $input['hreflang_x_default'] ) : '';
		$languages                       = AI_Chat_Bedrock_Integrations::languages();
		$output['hreflang_x_default']    = '' === $x_default || empty( $languages ) || isset( $languages[ $x_default ] ) ? $x_default : '';

		$prompt_id = isset( $input['prompt_id'] ) ? trim( sanitize_text_field( $input['prompt_id'] ) ) : '';
		if ( '' !== $prompt_id && ! preg_match( '#^[A-Za-z0-9:._/-]{1,2048}$#', $prompt_id ) ) {
			$this->notice( 'prompt_id', __( 'The managed prompt identifier contained unsupported characters and was cleared.', 'ai-chat-for-amazon-bedrock' ) );
			$prompt_id = '';
		}
		$output['prompt_id'] = $prompt_id;

		$prompt_version           = isset( $input['prompt_version'] ) ? strtoupper( trim( sanitize_text_field( $input['prompt_version'] ) ) ) : '';
		$output['prompt_version'] = preg_match( '/^(?:DRAFT|[0-9]{1,10})$/', $prompt_version ) ? $prompt_version : '';

		if ( class_exists( 'AI_Chat_Bedrock_Prompts' ) ) {
			// The cache key includes the identifier and version, so clear both the old and new entries.
			AI_Chat_Bedrock_Prompts::flush( $current );
			AI_Chat_Bedrock_Prompts::flush( $output );
		}
		$retention_days               = isset( $input['log_retention_days'] ) ? absint( $input['log_retention_days'] ) : AI_Chat_Bedrock_Conversations::DEFAULT_DAYS;
		$output['log_retention_days'] = max( 1, min( AI_Chat_Bedrock_Conversations::MAX_DAYS, $retention_days ) );

		$output['chat_memory']      = AI_Chat_Bedrock_Chat_History::mode( $input );
		$output['chat_memory_days'] = AI_Chat_Bedrock_Chat_History::retention_days( $input );

		$output['speech_replies']         = ! empty( $input['speech_replies'] );
		$output['speech_posts']           = ! empty( $input['speech_posts'] );
		$output['speech_posts_signed_in'] = ! empty( $input['speech_posts_signed_in'] );
		$output['speech_engine']          = AI_Chat_Bedrock_Speech::engine( $input );
		$output['speech_daily_chars']     = AI_Chat_Bedrock_Speech::daily_characters( $input );

		$output['analytics_events'] = ! empty( $input['analytics_events'] );

		$output['wechat_enabled']  = ! empty( $input['wechat_enabled'] );
		$output['wechat_app_id']   = isset( $input['wechat_app_id'] ) ? AI_Chat_Bedrock_WeChat::clean_app_id( $input['wechat_app_id'] ) : '';
		$output['wechat_model_id'] = AI_Chat_Bedrock_WeChat::model( array( 'wechat_model_id' => isset( $input['wechat_model_id'] ) ? sanitize_text_field( $input['wechat_model_id'] ) : '' ) );
		$output['wechat_hourly']   = AI_Chat_Bedrock_WeChat::hourly_limit( array( 'wechat_hourly' => isset( $input['wechat_hourly'] ) ? $input['wechat_hourly'] : '' ) );
		foreach ( array(
			'wechat_token'   => array( 'clean_token', __( 'The WeChat token must be 3 to 32 letters and digits, as in the Official Accounts Platform; it was not saved.', 'ai-chat-for-amazon-bedrock' ) ),
			'wechat_aes_key' => array( 'clean_aes_key', __( 'The EncodingAESKey must be 43 letters and digits; it was not saved.', 'ai-chat-for-amazon-bedrock' ) ),
		) as $aicfab_key => $aicfab_rule ) {
			$raw   = isset( $input[ $aicfab_key ] ) && is_string( $input[ $aicfab_key ] ) ? trim( $input[ $aicfab_key ] ) : '';
			$clean = call_user_func( array( 'AI_Chat_Bedrock_WeChat', $aicfab_rule[0] ), $raw );
			// A field left empty keeps the saved value, which is never shown again.
			$output[ $aicfab_key ] = ! empty( $input['wechat_clear'] ) ? '' : ( isset( $current[ $aicfab_key ] ) ? $current[ $aicfab_key ] : '' );
			if ( '' !== $clean ) {
				$encrypted = AI_Chat_Bedrock_Security::encrypt_secret( $clean );
				if ( '' === $encrypted ) {
					$this->notice( 'credential_encryption', __( 'The credential could not be encrypted; the existing value was preserved.', 'ai-chat-for-amazon-bedrock' ) );
				} else {
					$output[ $aicfab_key ] = $encrypted;
				}
			} elseif ( '' !== $raw ) {
				$this->notice( $aicfab_key, $aicfab_rule[1] );
			}
		}

		$output['wechat_menu'] = isset( $input['wechat_menu'] ) && is_string( $input['wechat_menu'] ) ? AI_Chat_Bedrock_Security::string_substr( sanitize_textarea_field( $input['wechat_menu'] ), 0, 1500 ) : '';

		$output['wechat_drafts_enabled']  = ! empty( $input['wechat_drafts_enabled'] );
		$output['wechat_drafts_notify']   = ! empty( $input['wechat_drafts_notify'] );
		$output['wechat_drafts_schedule'] = AI_Chat_Bedrock_WeChat_Drafts::schedule( array( 'wechat_drafts_schedule' => isset( $input['wechat_drafts_schedule'] ) ? (string) $input['wechat_drafts_schedule'] : 'off' ) );
		$output['wechat_drafts_count']    = AI_Chat_Bedrock_WeChat_Drafts::count( array( 'wechat_drafts_count' => isset( $input['wechat_drafts_count'] ) ? $input['wechat_drafts_count'] : 0 ) );
		$output['wechat_drafts_category'] = isset( $input['wechat_drafts_category'] ) ? absint( $input['wechat_drafts_category'] ) : 0;
		$output['wechat_drafts_author']   = AI_Chat_Bedrock_WeChat_Drafts::author( array( 'wechat_drafts_author' => isset( $input['wechat_drafts_author'] ) && is_string( $input['wechat_drafts_author'] ) ? $input['wechat_drafts_author'] : '' ) );
		$raw                              = isset( $input['wechat_app_secret'] ) && is_string( $input['wechat_app_secret'] ) ? trim( $input['wechat_app_secret'] ) : '';
		$clean                            = AI_Chat_Bedrock_WeChat_Game::clean_app_secret( $raw );
		// A field left empty keeps the saved value, which is never shown again.
		$output['wechat_app_secret'] = ! empty( $input['wechat_drafts_clear'] ) ? '' : ( isset( $current['wechat_app_secret'] ) ? $current['wechat_app_secret'] : '' );
		if ( '' !== $clean ) {
			$encrypted = AI_Chat_Bedrock_Security::encrypt_secret( $clean );
			if ( '' === $encrypted ) {
				$this->notice( 'credential_encryption', __( 'The credential could not be encrypted; the existing value was preserved.', 'ai-chat-for-amazon-bedrock' ) );
			} else {
				$output['wechat_app_secret'] = $encrypted;
			}
		} elseif ( '' !== $raw ) {
			$this->notice( 'wechat_app_secret', __( 'The AppSecret must be 32 letters and digits; it was not saved.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( ! empty( $input['wechat_drafts_clear'] ) ) {
			delete_transient( AI_Chat_Bedrock_WeChat_Drafts::ACCESS_KEY );
		}

		$output['wxgame_enabled']  = ! empty( $input['wxgame_enabled'] );
		$output['wxgame_app_id']   = isset( $input['wxgame_app_id'] ) ? AI_Chat_Bedrock_WeChat::clean_app_id( $input['wxgame_app_id'] ) : '';
		$output['wxgame_answers']  = isset( $input['wxgame_answers'] ) && is_string( $input['wxgame_answers'] ) ? AI_Chat_Bedrock_WeChat_Game::clean_answers( $input['wxgame_answers'] ) : '';
		$output['wxgame_welcome']  = isset( $input['wxgame_welcome'] ) && is_string( $input['wxgame_welcome'] ) ? sanitize_textarea_field( $input['wxgame_welcome'] ) : '';
		$output['wxgame_fallback'] = isset( $input['wxgame_fallback'] ) && is_string( $input['wxgame_fallback'] ) ? sanitize_textarea_field( $input['wxgame_fallback'] ) : '';
		if ( isset( $input['wxgame_app_id'] ) && is_string( $input['wxgame_app_id'] ) && '' !== trim( $input['wxgame_app_id'] ) && '' === $output['wxgame_app_id'] ) {
			$this->notice( 'wxgame_app_id', __( 'The mini game AppID starts with wx and has 18 characters; it was not saved.', 'ai-chat-for-amazon-bedrock' ) );
		}
		foreach ( array(
			'wxgame_token'      => array( 'clean_token', __( 'The mini game token must be 3 to 32 letters and digits; it was not saved.', 'ai-chat-for-amazon-bedrock' ) ),
			'wxgame_aes_key'    => array( 'clean_aes_key', __( 'The EncodingAESKey must be 43 letters and digits; it was not saved.', 'ai-chat-for-amazon-bedrock' ) ),
			'wxgame_app_secret' => array( 'clean_app_secret', __( 'The AppSecret must be 32 letters and digits; it was not saved.', 'ai-chat-for-amazon-bedrock' ) ),
		) as $aicfab_key => $aicfab_rule ) {
			$raw   = isset( $input[ $aicfab_key ] ) && is_string( $input[ $aicfab_key ] ) ? trim( $input[ $aicfab_key ] ) : '';
			$clean = call_user_func( array( 'AI_Chat_Bedrock_WeChat_Game', $aicfab_rule[0] ), $raw );
			// A field left empty keeps the saved value, which is never shown again.
			$output[ $aicfab_key ] = ! empty( $input['wxgame_clear'] ) ? '' : ( isset( $current[ $aicfab_key ] ) ? $current[ $aicfab_key ] : '' );
			if ( '' !== $clean ) {
				$encrypted = AI_Chat_Bedrock_Security::encrypt_secret( $clean );
				if ( '' === $encrypted ) {
					$this->notice( 'credential_encryption', __( 'The credential could not be encrypted; the existing value was preserved.', 'ai-chat-for-amazon-bedrock' ) );
				} else {
					$output[ $aicfab_key ] = $encrypted;
				}
			} elseif ( '' !== $raw ) {
				$this->notice( $aicfab_key, $aicfab_rule[1] );
			}
		}
		if ( ! empty( $input['wxgame_clear'] ) ) {
			delete_transient( AI_Chat_Bedrock_WeChat_Game::ACCESS_KEY );
		}
		// The Official Account and the mini game are separate accounts with their own AppIDs. In
		// safe mode each message carries its AppID, so they stay apart even when an owner gives both
		// the same token and key, which is allowed but advised against.
		$official_app = '' !== $output['wechat_app_id'] ? $output['wechat_app_id'] : ( isset( $current['wechat_app_id'] ) ? (string) $current['wechat_app_id'] : '' );
		if ( '' !== $output['wxgame_app_id'] && $output['wxgame_app_id'] === $official_app ) {
			$output['wxgame_app_id'] = '';
			$this->notice( 'wxgame_app_id', __( 'The mini game has its own AppID, not the Official Account\'s; it was not saved.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$aicfab_shared = false;
		foreach ( array(
			'wxgame_token'   => array( 'wechat_token', 'clean_token' ),
			'wxgame_aes_key' => array( 'wechat_aes_key', 'clean_aes_key' ),
		) as $aicfab_key => $aicfab_pair ) {
			$game     = '' !== $output[ $aicfab_key ] ? call_user_func( array( 'AI_Chat_Bedrock_WeChat', $aicfab_pair[1] ), AI_Chat_Bedrock_Security::decrypt_secret( $output[ $aicfab_key ] ) ) : '';
			$official = isset( $output[ $aicfab_pair[0] ] ) && '' !== $output[ $aicfab_pair[0] ] ? call_user_func( array( 'AI_Chat_Bedrock_WeChat', $aicfab_pair[1] ), AI_Chat_Bedrock_Security::decrypt_secret( $output[ $aicfab_pair[0] ] ) ) : '';
			if ( '' !== $game && $game === $official ) {
				$aicfab_shared = true;
			}
		}
		// Said when the mini game's token or key is entered, not on every later save.
		if ( ! empty( $aicfab_shared ) && ( ! empty( $input['wxgame_token'] ) || ! empty( $input['wxgame_aes_key'] ) ) ) {
			$this->notice( 'wxgame_token', __( 'The mini game uses the same token or EncodingAESKey as the Official Account. It was saved; choose safe mode in both, so each message is checked against its own AppID, and consider separate values.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$output['leads_enabled'] = ! empty( $input['leads_enabled'] );
		$output['leads_notify']  = ! empty( $input['leads_notify'] );
		$output['leads_days']    = AI_Chat_Bedrock_Leads::retention_days( array( 'leads_days' => isset( $input['leads_days'] ) ? $input['leads_days'] : 0 ) );
		$output['leads_link']    = isset( $input['leads_link'] ) ? AI_Chat_Bedrock_Leads::clean_link( $input['leads_link'] ) : '';
		if ( isset( $input['leads_link'] ) && is_scalar( $input['leads_link'] ) && '' !== trim( (string) $input['leads_link'] ) && '' === $output['leads_link'] ) {
			$this->notice( 'leads_link', __( 'The contact link must be a web, mailto: or tel: address; it was cleared.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$knowledge_base = isset( $input['knowledge_base_id'] ) ? trim( sanitize_text_field( $input['knowledge_base_id'] ) ) : '';
		if ( '' === $knowledge_base || preg_match( '/^[A-Za-z0-9]{1,64}$/', $knowledge_base ) ) {
			$output['knowledge_base_id'] = $knowledge_base;
		} else {
			$output['knowledge_base_id'] = '';
			$this->notice( 'knowledge_base_id', __( 'The knowledge base ID must be alphanumeric; the value was cleared.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$rerank = isset( $input['rerank_model_id'] ) ? sanitize_text_field( $input['rerank_model_id'] ) : '';
		if ( '' !== $rerank && ! isset( AI_Chat_Bedrock_Retrieval::rerank_models()[ $rerank ] ) ) {
			$this->notice( 'rerank_model_id', __( 'That reranking model is not supported; reranking was left off.', 'ai-chat-for-amazon-bedrock' ) );
			$rerank = '';
		}
		$output['rerank_model_id'] = $rerank;
		// A saved model, Region or credential change may be what fixes a refused rerank request.
		delete_transient( AI_Chat_Bedrock_Retrieval::RERANK_PAUSED );

		if ( class_exists( 'AI_Chat_Bedrock_AWS_Credentials' ) ) {
			AI_Chat_Bedrock_AWS_Credentials::flush_cache();
		}

		/*
		 * Preserve settings owned by tabs that were not part of this submission.
		 *
		 * If the field list is missing, for example because a cached form was submitted,
		 * fall back to the keys that were actually posted. Losing unrelated settings is
		 * worse than ignoring a cleared checkbox in that rare case.
		 */
		if ( empty( $submitted ) && ! empty( $current ) ) {
			$submitted = array_filter( array_map( 'sanitize_key', array_keys( $input ) ) );
		}

		if ( ! empty( $submitted ) ) {
			$merged = $current;
			foreach ( $submitted as $key ) {
				if ( array_key_exists( $key, $output ) ) {
					$merged[ $key ] = $output[ $key ];
				}
			}
			// Keys a field renders next to its own control, which the field list does not name.
			foreach ( self::COMPANION_FIELDS as $owner => $companions ) {
				if ( ! in_array( $owner, $submitted, true ) ) {
					continue;
				}
				foreach ( $companions as $companion ) {
					if ( array_key_exists( $companion, $output ) ) {
						$merged[ $companion ] = $output[ $companion ];
					}
				}
			}
			$output = $merged;
		}

		/*
		 * Some settings are also kept in options of their own, which other code reads without
		 * loading the settings array. They are written from the merged result, so saving one
		 * tab no longer switched off the conversation log and site abilities set on another.
		 */
		update_option( AI_Chat_Bedrock_Conversations::OPTION_ENABLED, ! empty( $output['log_conversations'] ), false );
		update_option( AI_Chat_Bedrock_Conversations::OPTION_RETENTION, isset( $output['log_retention_days'] ) ? (int) $output['log_retention_days'] : AI_Chat_Bedrock_Conversations::DEFAULT_DAYS, false );
		update_option( 'ai_chat_bedrock_site_abilities', ! empty( $output['site_abilities'] ), false );

		// WordPress registers its own "Settings saved" against the 'general' slug, which a
		// settings_errors() call filtered to this plugin's slug never shows. Without this the
		// page came back silently and there was no way to tell whether the save worked.
		$this->notice( 'aicfab_settings_saved', __( 'Settings saved.', 'ai-chat-for-amazon-bedrock' ), 'success' );

		return $output;
	}

	/**
	 * The one-off notice flags the plugin's redirects add. WordPress takes them out of the
	 * address bar once the page has loaded, as it does its own, so reloading or bookmarking
	 * the page does not show the notice again.
	 *
	 * @param array $args Query arguments WordPress removes.
	 * @return array
	 */
	public function removable_query_args( $args ) {
		$args = is_array( $args ) ? $args : array();
		return array_merge( $args, array( 'aicfab-alt', 'aicfab-alt-done', 'aicfab-alt-skipped', 'aicfab-alt-failed', 'aicfab-applied', 'aicfab-cleared', 'aicfab-generated', 'aicfab-image', 'aicfab-log', 'aicfab-message', 'aicfab-profile', 'aicfab-skipped', 'aicfab-transfer' ) );
	}

	/**
	 * The handle of the admin script and stylesheet. The chat's own assets use the bare plugin name,
	 * and the Test Chat screen needs both: sharing the handle made WordPress keep whichever was
	 * registered first, the admin one, so the chat there had neither its script nor its styles.
	 */
	private function admin_handle() {
		return $this->plugin_name . '-admin';
	}

	private function is_plugin_screen( $hook_suffix ) {
		return false !== strpos( (string) $hook_suffix, $this->plugin_name );
	}
	/**
	 * Settings rendered inside another field's row, saved whenever that field's tab is.
	 */
	const COMPANION_FIELDS = array(
		'log_conversations'     => array( 'log_retention_days' ),
		'chat_memory'           => array( 'chat_memory_days' ),
		'speech_replies'        => array( 'speech_posts', 'speech_posts_signed_in', 'speech_engine', 'speech_daily_chars' ),
		'leads_enabled'         => array( 'leads_notify', 'leads_days', 'leads_link' ),
		'wechat_enabled'        => array( 'wechat_token', 'wechat_aes_key', 'wechat_app_id', 'wechat_model_id', 'wechat_hourly', 'wechat_menu' ),
		'wechat_drafts_enabled' => array( 'wechat_app_secret', 'wechat_drafts_category', 'wechat_drafts_schedule', 'wechat_drafts_count', 'wechat_drafts_author', 'wechat_drafts_notify' ),
		'wxgame_enabled'        => array( 'wxgame_app_id', 'wxgame_token', 'wxgame_aes_key', 'wxgame_app_secret', 'wxgame_welcome', 'wxgame_answers', 'wxgame_fallback' ),
		'distribution_enabled'  => array( 'distribution_links' ),
		'youtube_client_id'     => array( 'youtube_client_secret', 'youtube_daily_uploads' ),
		'popup_site_wide'       => array( 'popup_profile' ),
		'prompt_id'             => array( 'prompt_version' ),
		'embedding_model_id'    => array( 'embedding_background' ),
		'vector_store'          => array( 's3_vectors_bucket', 's3_vectors_index', 's3_vectors_region' ),
	);

	const CHECKBOX_FIELDS = array(
		'embedding_background',
		'abilities_tools',
		'allow_public_chat',
		'aws_use_role_credentials',
		'debug_mode',
		'editor_assistant',
		'enable_site_context',
		'enable_streaming',
		'log_conversations',
		'media_assistant',
		'popup_site_wide',
		'site_abilities',
		'site_ontology',
		'business_metrics',
		'show_sources',
		'include_noindex',
		'speech_replies',
		'speech_posts',
		'leads_enabled',
		'leads_notify',
		'analytics_events',
		'wechat_enabled',
		'wxgame_enabled',
		'wechat_drafts_enabled',
		'wechat_drafts_notify',
		'github_read_scope',
		'social_only_registration',
		'organization_author',
		'distribution_enabled',
		'bilibili_embeds',
		'woo_catalog',
		'woo_orders',
		'woo_product_assistant',
	);

	/**
	 * DOM id used to tie a settings row title to its control.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function control_id( $key ) {
		return 'aicfab_field_' . sanitize_key( (string) $key );
	}

	private function field( $id, $title, $callback, $section ) {
		$pages = array(
			'aicfab_aws'          => 'aicfab_tab_aws',
			'aicfab_model'        => 'aicfab_tab_model',
			'aicfab_governance'   => 'aicfab_tab_governance',
			'aicfab_knowledge'    => 'aicfab_tab_knowledge',
			'aicfab_chat'         => 'aicfab_tab_chat',
			'aicfab_integrations' => 'aicfab_tab_integrations',
			'aicfab_publishing'   => 'aicfab_tab_publishing',
			'aicfab_wechat'       => 'aicfab_tab_publishing',
			'aicfab_agents'       => 'aicfab_tab_agents',
			'aicfab_woocommerce'  => 'aicfab_tab_woocommerce',
		);
		$page  = isset( $pages[ $section ] ) ? $pages[ $section ] : 'aicfab_tab_chat';

		/*
		 * Associate the row title with the control so screen readers announce a name.
		 * Checkbox fields already carry their own inline label and would be read twice.
		 */
		$args = in_array( $id, self::CHECKBOX_FIELDS, true ) ? array() : array( 'label_for' => self::control_id( $id ) );
		add_settings_field( $id, $title, array( $this, $callback ), $page, $section, $args );
		self::$tab_fields[ $page ][] = $id;
	}

	/**
	 * Settings tabs and the fields each one owns.
	 *
	 * @return array
	 */
	public static function tabs() {
		// In the order a site is set up: connect, choose the model, ground and shape the chat,
		// add tools and channels, then limit what it may spend.
		return array(
			'aws'          => array(
				'page'  => 'aicfab_tab_aws',
				'label' => __( 'AWS', 'ai-chat-for-amazon-bedrock' ),
			),
			'model'        => array(
				'page'  => 'aicfab_tab_model',
				'label' => __( 'Model', 'ai-chat-for-amazon-bedrock' ),
			),
			'knowledge'    => array(
				'page'  => 'aicfab_tab_knowledge',
				'label' => __( 'Grounding', 'ai-chat-for-amazon-bedrock' ),
			),
			'chat'         => array(
				'page'  => 'aicfab_tab_chat',
				'label' => __( 'Chat', 'ai-chat-for-amazon-bedrock' ),
			),
			'agents'       => array(
				'page'  => 'aicfab_tab_agents',
				'label' => __( 'Agents and tools', 'ai-chat-for-amazon-bedrock' ),
			),
			// The slug stays publishing, so links to it keep working.
			'publishing'   => array(
				'page'  => 'aicfab_tab_publishing',
				'label' => __( 'Channels', 'ai-chat-for-amazon-bedrock' ),
			),
			'governance'   => array(
				'page'  => 'aicfab_tab_governance',
				'label' => __( 'Safety and spend', 'ai-chat-for-amazon-bedrock' ),
			),
			'integrations' => array(
				'page'  => 'aicfab_tab_integrations',
				'label' => __( 'Integrations', 'ai-chat-for-amazon-bedrock' ),
			),
		) + ( class_exists( 'AI_Chat_Bedrock_WooCommerce' ) && AI_Chat_Bedrock_WooCommerce::active()
			? array(
				'woocommerce' => array(
					'page'  => 'aicfab_tab_woocommerce',
					'label' => __( 'WooCommerce', 'ai-chat-for-amazon-bedrock' ),
				),
			)
			: array() );
	}

	/**
	 * Field identifiers registered for one tab page.
	 *
	 * @param string $page Tab page slug.
	 * @return array
	 */
	public static function fields_for_page( $page ) {
		return isset( self::$tab_fields[ $page ] ) ? self::$tab_fields[ $page ] : array();
	}
	private function option( $key, $fallback = '' ) {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		return isset( $options[ $key ] ) ? $options[ $key ] : $fallback;
	}
	private function text_input( $key, $fallback, $maxlength, $css_class = 'regular-text' ) {
		echo '<input type="text" class="' . esc_attr( $css_class ) . '" id="' . esc_attr( self::control_id( $key ) ) . '" name="ai_chat_bedrock_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $this->option( $key, $fallback ) ) . '" maxlength="' . absint( $maxlength ) . '">';
	}
	private function credential_input( $key, $password ) {
		$constants  = array(
			'aws_access_key'    => 'AI_CHAT_BEDROCK_AWS_ACCESS_KEY',
			'aws_secret_key'    => 'AI_CHAT_BEDROCK_AWS_SECRET_KEY',
			'aws_session_token' => 'AI_CHAT_BEDROCK_AWS_SESSION_TOKEN',
			'bedrock_api_key'   => 'AI_CHAT_BEDROCK_API_KEY',
		);
		$configured = ( isset( $constants[ $key ] ) && defined( $constants[ $key ] ) ) || '' !== $this->option( $key, '' );
		echo '<input type="' . ( $password ? 'password' : 'text' ) . '" class="regular-text" id="' . esc_attr( self::control_id( $key ) ) . '" name="ai_chat_bedrock_settings[' . esc_attr( $key ) . ']" value="" autocomplete="new-password" placeholder="' . esc_attr( $configured ? __( 'Configured — enter a value to replace', 'ai-chat-for-amazon-bedrock' ) : '' ) . '">';
	}
	private function select( $key, $values, $fallback ) {
		$current = $this->option( $key, $fallback );
		echo '<select id="' . esc_attr( self::control_id( $key ) ) . '" name="ai_chat_bedrock_settings[' . esc_attr( $key ) . ']">';
		foreach ( $values as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}
}
