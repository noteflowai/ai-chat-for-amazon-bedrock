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
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/ai-chat-bedrock-admin.css', array(), $this->version );
		if ( false !== strpos( $hook_suffix, $this->plugin_name . '-mcp' ) ) {
			wp_enqueue_style( $this->plugin_name . '-mcp', plugin_dir_url( __FILE__ ) . 'css/ai-chat-bedrock-mcp.css', array(), $this->version );
		}
	}

	public function enqueue_scripts( $hook_suffix ) {
		if ( ! $this->is_plugin_screen( $hook_suffix ) ) {
			return;
		}
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/ai-chat-bedrock-admin.js', array( 'jquery' ), $this->version, true );

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
						/* translators: %d: number of pages proposed. */
						'planReady'     => __( '%d pages proposed. Review them before creating drafts.', 'ai-chat-for-amazon-bedrock' ),
						'writing'       => __( 'Writing…', 'ai-chat-for-amazon-bedrock' ),
						'skippedByYou'  => __( 'Skipped.', 'ai-chat-for-amazon-bedrock' ),
						'editDraft'     => __( 'Edit the draft', 'ai-chat-for-amazon-bedrock' ),
						/* translators: 1: pages handled so far, 2: pages in total. */
						'progress'      => __( '%1$d of %2$d done…', 'ai-chat-for-amazon-bedrock' ),
						'finished'      => __( 'Finished. Every page was created as a draft.', 'ai-chat-for-amazon-bedrock' ),
						'unexpected'    => __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' ),
						'includeLabel'  => __( 'Include', 'ai-chat-for-amazon-bedrock' ),
						'titleLabel'    => __( 'Page title', 'ai-chat-for-amazon-bedrock' ),
					),
				)
			);
		}
		wp_localize_script(
			$this->plugin_name,
			'ai_chat_bedrock_admin',
			array(
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'ai_chat_bedrock_nonce' ),
				'mcp_nonce'    => wp_create_nonce( 'ai_chat_bedrock_mcp_nonce' ),
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
			wp_enqueue_script( $this->plugin_name . '-mcp', plugin_dir_url( __FILE__ ) . 'js/ai-chat-bedrock-mcp.js', array( 'jquery', $this->plugin_name ), $this->version, true );
		}
	}

	public function add_plugin_admin_menu() {
		add_menu_page( __( 'AI Chat for Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ), __( 'AI Chat Bedrock', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name, array( $this, 'display_plugin_admin_page' ), 'dashicons-format-chat', 100 );
		add_submenu_page( $this->plugin_name, __( 'Settings', 'ai-chat-for-amazon-bedrock' ), __( 'Settings', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-settings', array( $this, 'display_plugin_admin_settings_page' ) );
		add_submenu_page( $this->plugin_name, __( 'Test Chat', 'ai-chat-for-amazon-bedrock' ), __( 'Test Chat', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-test', array( $this, 'display_plugin_admin_test_page' ) );
		add_submenu_page( $this->plugin_name, __( 'MCP Settings', 'ai-chat-for-amazon-bedrock' ), __( 'MCP Settings', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-mcp', array( $this, 'display_plugin_admin_mcp_page' ) );
		add_submenu_page( $this->plugin_name, __( 'Content Generator', 'ai-chat-for-amazon-bedrock' ), __( 'Content Generator', 'ai-chat-for-amazon-bedrock' ), 'edit_posts', $this->plugin_name . '-generator', array( $this, 'display_plugin_admin_generator_page' ) );
		add_submenu_page( $this->plugin_name, __( 'Site Pages', 'ai-chat-for-amazon-bedrock' ), __( 'Site Pages', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_Scaffold::CAPABILITY, $this->plugin_name . '-scaffold', array( $this, 'display_plugin_admin_scaffold_page' ) );
		add_submenu_page( $this->plugin_name, __( 'Chat Profiles', 'ai-chat-for-amazon-bedrock' ), __( 'Chat Profiles', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-profiles', array( $this, 'display_plugin_admin_profiles_page' ) );
		add_submenu_page( $this->plugin_name, __( 'Conversations', 'ai-chat-for-amazon-bedrock' ), __( 'Conversations', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-conversations', array( $this, 'display_plugin_admin_conversations_page' ) );
		add_submenu_page( $this->plugin_name, __( 'Answer checks', 'ai-chat-for-amazon-bedrock' ), __( 'Answer checks', 'ai-chat-for-amazon-bedrock' ), AI_Chat_Bedrock_Eval::CAPABILITY, $this->plugin_name . '-eval', array( $this, 'display_plugin_admin_eval_page' ) );
		add_submenu_page( $this->plugin_name, __( 'Diagnostics', 'ai-chat-for-amazon-bedrock' ), __( 'Diagnostics', 'ai-chat-for-amazon-bedrock' ), 'manage_options', $this->plugin_name . '-diagnostics', array( $this, 'display_plugin_admin_diagnostics_page' ) );
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
		return $actions;
	}

	/**
	 * Index one batch of posts for semantic search.
	 */
	public function ajax_index_embeddings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
		}
		check_ajax_referer( 'ai_chat_bedrock_nonce', 'nonce' );

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
		wp_safe_redirect( add_query_arg( 'aicfab-cleared', (int) $cleared, admin_url( 'admin.php?page=' . $this->plugin_name . '-settings' ) ) );
		exit;
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
			fputcsv( $handle, $row ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
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
	 * Report the outcome of alt text generation on the media screen.
	 */
	public function render_media_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

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
		if ( ! empty( $status['configured'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'AI Chat for Amazon Bedrock:', 'ai-chat-for-amazon-bedrock' ),
			esc_html__( 'no usable AWS credentials were found, so the chat cannot answer yet. An Amazon Bedrock API key is the quickest way to connect.', 'ai-chat-for-amazon-bedrock' ),
			esc_url( admin_url( 'admin.php?page=' . $this->plugin_name . '-settings' ) ),
			esc_html__( 'Finish setup', 'ai-chat-for-amazon-bedrock' )
		);
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
	 * Delete all stored conversations.
	 */
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
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings' ) ) );
		exit;
	}

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
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ai_chat_bedrock_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'admin_refresh_models', 10, 60 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please wait a moment.', 'ai-chat-for-amazon-bedrock' ) ), 429 );
		}

		$models = AI_Chat_Bedrock_Models::refresh();
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				/* translators: %d: number of models discovered. */
				'message' => sprintf( __( '%d models available. Reload the page to see the updated list.', 'ai-chat-for-amazon-bedrock' ), count( $models ) ),
				'count'   => count( $models ),
			)
		);
	}

	/**
	 * Run diagnostics, including a live Bedrock invocation.
	 */
	public function ajax_run_diagnostics() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'ai_chat_bedrock_nonce', 'nonce', false ) ) {
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

		add_settings_section( 'aicfab_governance', __( 'Safety and spend controls', 'ai-chat-for-amazon-bedrock' ), array( $this, 'governance_section_callback' ), 'aicfab_tab_governance' );
		$this->field( 'guardrail_id', __( 'Guardrail identifier', 'ai-chat-for-amazon-bedrock' ), 'guardrail_id_render', 'aicfab_governance' );
		$this->field( 'guardrail_version', __( 'Guardrail version', 'ai-chat-for-amazon-bedrock' ), 'guardrail_version_render', 'aicfab_governance' );
		$this->field( 'daily_request_limit', __( 'Daily request limit', 'ai-chat-for-amazon-bedrock' ), 'daily_request_limit_render', 'aicfab_governance' );

		add_settings_section( 'aicfab_knowledge', __( 'Answer grounding', 'ai-chat-for-amazon-bedrock' ), array( $this, 'knowledge_section_callback' ), 'aicfab_tab_knowledge' );
		$this->field( 'enable_site_context', __( 'Use site content', 'ai-chat-for-amazon-bedrock' ), 'enable_site_context_render', 'aicfab_knowledge' );
		$this->field( 'context_results', __( 'Passages per answer', 'ai-chat-for-amazon-bedrock' ), 'context_results_render', 'aicfab_knowledge' );
		$this->field( 'embedding_model_id', __( 'Semantic search', 'ai-chat-for-amazon-bedrock' ), 'embedding_model_render', 'aicfab_knowledge' );
		$this->field( 'knowledge_base_id', __( 'Bedrock knowledge base ID', 'ai-chat-for-amazon-bedrock' ), 'knowledge_base_id_render', 'aicfab_knowledge' );
		$this->field( 'abilities_tools', __( 'WordPress abilities as tools', 'ai-chat-for-amazon-bedrock' ), 'abilities_tools_render', 'aicfab_knowledge' );
		$this->field( 'site_abilities', __( 'Site content abilities', 'ai-chat-for-amazon-bedrock' ), 'site_abilities_render', 'aicfab_knowledge' );
		$this->field( 'editor_assistant', __( 'Editor assistant', 'ai-chat-for-amazon-bedrock' ), 'editor_assistant_render', 'aicfab_knowledge' );
		$this->field( 'log_conversations', __( 'Conversation log', 'ai-chat-for-amazon-bedrock' ), 'log_conversations_render', 'aicfab_knowledge' );
		$this->field( 'media_assistant', __( 'Media helpers', 'ai-chat-for-amazon-bedrock' ), 'media_assistant_render', 'aicfab_knowledge' );

		add_settings_section( 'aicfab_chat', __( 'Chat security and presentation', 'ai-chat-for-amazon-bedrock' ), '__return_false', 'aicfab_tab_chat' );
		$this->field( 'system_prompt', __( 'System prompt', 'ai-chat-for-amazon-bedrock' ), 'system_prompt_render', 'aicfab_chat' );
		$this->field( 'prompt_id', __( 'Managed prompt', 'ai-chat-for-amazon-bedrock' ), 'managed_prompt_render', 'aicfab_chat' );
		$this->field( 'chat_title', __( 'Chat title', 'ai-chat-for-amazon-bedrock' ), 'chat_title_render', 'aicfab_chat' );
		$this->field( 'welcome_message', __( 'Welcome message', 'ai-chat-for-amazon-bedrock' ), 'welcome_message_render', 'aicfab_chat' );
		$this->field( 'suggested_questions', __( 'Suggested questions', 'ai-chat-for-amazon-bedrock' ), 'suggested_questions_render', 'aicfab_chat' );
		$this->field( 'enable_streaming', __( 'Streaming responses', 'ai-chat-for-amazon-bedrock' ), 'enable_streaming_render', 'aicfab_chat' );
		$this->field( 'allow_public_chat', __( 'Guest access', 'ai-chat-for-amazon-bedrock' ), 'allow_public_chat_render', 'aicfab_chat' );
		$this->field( 'rate_limit_per_minute', __( 'Requests per visitor per minute', 'ai-chat-for-amazon-bedrock' ), 'rate_limit_render', 'aicfab_chat' );
		$this->field( 'role_limits', __( 'Per-role limits', 'ai-chat-for-amazon-bedrock' ), 'role_limits_render', 'aicfab_chat' );
		$this->field( 'popup_site_wide', __( 'Floating chat', 'ai-chat-for-amazon-bedrock' ), 'popup_site_wide_render', 'aicfab_chat' );
		$this->field( 'debug_mode', __( 'Debug logging', 'ai-chat-for-amazon-bedrock' ), 'debug_mode_render', 'aicfab_chat' );
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
		echo '<p>' . esc_html__( 'Apply an Amazon Bedrock guardrail and cap daily requests. Counters store request and token totals only.', 'ai-chat-for-amazon-bedrock' ) . '</p>';

		$today = AI_Chat_Bedrock_Usage::today_totals();
		$week  = AI_Chat_Bedrock_Usage::totals( 7 );
		echo '<p>' . sprintf(
			/* translators: 1: requests today, 2: input tokens today, 3: output tokens today, 4: requests in the last seven days. */
			esc_html__( 'Today: %1$d requests, %2$d input tokens, %3$d output tokens. Last 7 days: %4$d requests.', 'ai-chat-for-amazon-bedrock' ),
			(int) $today['requests'],
			(int) $today['input_tokens'],
			(int) $today['output_tokens'],
			(int) $week['requests']
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
		echo '<p class="description">' . esc_html__( 'Maximum Bedrock requests per day for the whole site. Use 0 for no plugin-side limit. This is not a billing guarantee; configure AWS Budgets as well.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
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
		echo '<input type="number" id="aicfab_field_context_results" name="ai_chat_bedrock_settings[context_results]" value="' . esc_attr( max( 1, min( 8, $value ) ) ) . '" min="1" max="8">';
		echo '<p class="description">' . esc_html__( 'More passages improve grounding but increase input tokens and cost.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function knowledge_base_id_render() {
		$this->text_input( 'knowledge_base_id', '', 64 );
		echo '<p class="description">' . esc_html__( 'Optional. Queries an existing Amazon Bedrock knowledge base with the Retrieve API. Requires bedrock:Retrieve permission for that knowledge base.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function abilities_tools_render() {
		$checked   = ! empty( $this->option( 'abilities_tools', false ) );
		$available = AI_Chat_Bedrock_Abilities::available();
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[abilities_tools]" value="1" ' . checked( $checked, true, false ) . ' ' . disabled( $available, false, false ) . '> ' . esc_html__( 'Offer abilities registered by other plugins to the chat model', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		if ( $available ) {
			echo '<p class="description">' . esc_html__( 'Abilities follow the same tool policy: a capability is required, permission callbacks are honored, and abilities that change data need explicit approval.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
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
	public function editor_assistant_render() {
		$checked = ! empty( $this->option( 'editor_assistant', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[editor_assistant]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Add a writing assistant sidebar to the block editor', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Improve, shorten, expand, summarize, suggest titles or translate. Suggestions are never saved automatically and require the edit_posts capability.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function log_conversations_render() {
		$checked   = AI_Chat_Bedrock_Conversations::enabled();
		$retention = AI_Chat_Bedrock_Conversations::retention_days();
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[log_conversations]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Store questions and answers for review', 'ai-chat-for-amazon-bedrock' ) . '</label> ';
		echo '<label>' . esc_html__( 'Keep for', 'ai-chat-for-amazon-bedrock' ) . ' <input type="number" id="aicfab_field_log_retention_days" name="ai_chat_bedrock_settings[log_retention_days]" value="' . esc_attr( $retention ) . '" min="1" max="' . esc_attr( AI_Chat_Bedrock_Conversations::MAX_DAYS ) . '" style="width:80px"> ' . esc_html__( 'days', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Disabled by default. When enabled, chat content is stored in the database, capped at 200 recent entries, and visible to administrators. Disclose this to your visitors.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function suggested_questions_render() {
		$value = (string) $this->option( 'suggested_questions', '' );
		echo '<textarea id="aicfab_field_suggested_questions" name="ai_chat_bedrock_settings[suggested_questions]" rows="4" class="large-text code" placeholder="' . esc_attr__( 'What are your opening hours?', 'ai-chat-for-amazon-bedrock' ) . '">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One question per line, up to four. They appear as buttons above the input so visitors know what to ask, and disappear once the conversation starts.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}

	public function role_limits_render() {
		$fallback = max( 1, absint( $this->option( 'rate_limit_per_minute', 5 ) ) );
		$limits   = AI_Chat_Bedrock_Rate_Limits::all();

		echo '<fieldset>';
		echo '<legend class="screen-reader-text">' . esc_html__( 'Requests per minute for each role', 'ai-chat-for-amazon-bedrock' ) . '</legend>';
		echo '<table class="aicfab-role-limits"><tbody>';

		foreach ( AI_Chat_Bedrock_Rate_Limits::roles() as $role => $label ) {
			$field = 'aicfab_role_limit_' . $role;
			$value = isset( $limits[ $role ] ) ? (int) $limits[ $role ] : '';
			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" id="%1$s" name="ai_chat_bedrock_settings[role_limits][%3$s]" value="%4$s" min="0" max="%5$d" step="1" placeholder="%6$s"></td></tr>',
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
		$this->text_input( 'prompt_id', '', 2048 );
		echo ' <label>' . esc_html__( 'Version', 'ai-chat-for-amazon-bedrock' ) . ' ';
		echo '<input type="text" name="ai_chat_bedrock_settings[prompt_version]" value="' . esc_attr( (string) $this->option( 'prompt_version', '' ) ) . '" size="8" maxlength="10" placeholder="DRAFT"></label>';
		echo '<p class="description">' . esc_html__( 'Optional. Point at a prompt in Amazon Bedrock Prompt Management and its text replaces the system prompt above, so one prompt can be reviewed in AWS and reused by every site. The prompt must live in the same region as the chat. Leave the version empty to follow the draft.', 'ai-chat-for-amazon-bedrock' ) . '</p>';

		if ( ! AI_Chat_Bedrock_Prompts::enabled() ) {
			return;
		}

		$text = AI_Chat_Bedrock_Prompts::text();
		if ( is_wp_error( $text ) ) {
			echo '<p class="aicfab-prompt-error notice notice-error inline"><span>' . esc_html( $text->get_error_message() ) . '</span></p>';
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
				esc_html( implode( ', ', $unresolved ) )
			);
			echo '</p>';
		}
	}

	public function embedding_model_render() {
		$choices = array( '' => __( 'Off, use keyword search only', 'ai-chat-for-amazon-bedrock' ) ) + AI_Chat_Bedrock_Embeddings::models();
		$this->select( 'embedding_model_id', $choices, '' );
		echo '<p class="description">' . esc_html__( 'Finds content by meaning instead of shared words. It adds one embedding request per question, plus one per post while indexing. Keyword search still runs when nothing relevant is found.', 'ai-chat-for-amazon-bedrock' ) . '</p>';

		if ( ! AI_Chat_Bedrock_Embeddings::enabled() ) {
			return;
		}

		$status = AI_Chat_Bedrock_Embeddings::status();
		echo '<p class="aicfab-index-status" role="status">';
		printf(
			/* translators: 1: indexed item count, 2: total published item count. */
			esc_html__( 'Indexed %1$d of %2$d published items.', 'ai-chat-for-amazon-bedrock' ),
			(int) $status['indexed'],
			(int) $status['total']
		);
		echo '</p>';
		echo '<p><button type="button" class="button" id="aicfab-index-embeddings">' . esc_html__( 'Index content now', 'ai-chat-for-amazon-bedrock' ) . '</button> <span id="aicfab-index-progress"></span></p>';
		$background = ! empty( $this->option( 'embedding_background', false ) );
		echo '<p><label><input type="checkbox" name="ai_chat_bedrock_settings[embedding_background]" value="1" ' . checked( $background, true, false ) . '> ' . esc_html__( 'Keep the index up to date in the background', 'ai-chat-for-amazon-bedrock' ) . '</label></p>';
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
	public function max_tokens_render() {
		$value = $this->option( 'max_tokens', 1000 );
		echo '<input type="number" id="aicfab_field_max_tokens" name="ai_chat_bedrock_settings[max_tokens]" value="' . esc_attr( $value ) . '" min="100" max="4000" step="100">';
	}
	public function temperature_render() {
		$value = $this->option( 'temperature', 0.7 );
		echo '<input type="number" id="aicfab_field_temperature" name="ai_chat_bedrock_settings[temperature]" value="' . esc_attr( $value ) . '" min="0" max="1" step="0.1">';
		echo '<p class="description">' . esc_html__( 'Not sent to Claude Opus 4.7, Sonnet 5, Opus 5 and newer Claude models, because Amazon Bedrock rejects it for them.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function system_prompt_render() {
		echo '<textarea id="aicfab_field_system_prompt" name="ai_chat_bedrock_settings[system_prompt]" rows="5" class="large-text" maxlength="8000">' . esc_textarea( $this->option( 'system_prompt', 'You are a helpful AI assistant powered by Amazon Bedrock.' ) ) . '</textarea>';
	}
	public function chat_title_render() {
		$this->text_input( 'chat_title', 'Chat with AI', 120 );
	}
	public function welcome_message_render() {
		$this->text_input( 'welcome_message', 'Hello! How can I help you today?', 500 );
	}
	public function allow_public_chat_render() {
		$checked = ! empty( $this->option( 'allow_public_chat', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[allow_public_chat]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Allow unauthenticated visitors to use paid Bedrock requests', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Disabled by default. Enabling this can incur AWS charges even with rate limiting.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	public function rate_limit_render() {
		$value = $this->option( 'rate_limit_per_minute', 5 );
		echo '<input type="number" id="aicfab_field_rate_limit_per_minute" name="ai_chat_bedrock_settings[rate_limit_per_minute]" value="' . esc_attr( $value ) . '" min="1" max="60">';
	}
	public function popup_site_wide_render() {
		$checked = ! empty( $this->option( 'popup_site_wide', false ) );
		echo '<label><input type="checkbox" name="ai_chat_bedrock_settings[popup_site_wide]" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html__( 'Show a floating chat button on every page', 'ai-chat-for-amazon-bedrock' ) . '</label>';
		$profiles = AI_Chat_Bedrock_Profiles::choices();
		echo ' <label style="margin-left:10px">' . esc_html__( 'Profile', 'ai-chat-for-amazon-bedrock' ) . ' <select id="aicfab_field_popup_profile" name="ai_chat_bedrock_settings[popup_profile]">';
		$current = (string) $this->option( 'popup_profile', '' );
		foreach ( $profiles as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<p class="description">' . esc_html__( 'Pages that already contain the chat block or shortcode are left unchanged. Use a profile with guest access if visitors should be able to chat.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
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
		$output['abilities_tools']     = ! empty( $input['abilities_tools'] );
		$output['site_abilities']      = ! empty( $input['site_abilities'] );
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
		update_option( AI_Chat_Bedrock_Conversations::OPTION_ENABLED, $output['log_conversations'], false );
		update_option( AI_Chat_Bedrock_Conversations::OPTION_RETENTION, $output['log_retention_days'], false );
		update_option( 'ai_chat_bedrock_site_abilities', $output['site_abilities'], false );

		$knowledge_base = isset( $input['knowledge_base_id'] ) ? trim( sanitize_text_field( $input['knowledge_base_id'] ) ) : '';
		if ( '' === $knowledge_base || preg_match( '/^[A-Za-z0-9]{1,64}$/', $knowledge_base ) ) {
			$output['knowledge_base_id'] = $knowledge_base;
		} else {
			$output['knowledge_base_id'] = '';
			$this->notice( 'knowledge_base_id', __( 'The knowledge base ID must be alphanumeric; the value was cleared.', 'ai-chat-for-amazon-bedrock' ) );
		}

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
			foreach ( array( 'aws_access_key', 'aws_secret_key', 'aws_session_token', 'bedrock_api_key', 'enable_streaming', 'log_retention_days', 'popup_profile', 'guardrail_version' ) as $paired ) {
				if ( in_array( $paired, $submitted, true ) && array_key_exists( $paired, $output ) ) {
					$merged[ $paired ] = $output[ $paired ];
				}
			}
			$output = $merged;
		}

		// WordPress registers its own "Settings saved" against the 'general' slug, which a
		// settings_errors() call filtered to this plugin's slug never shows. Without this the
		// page came back silently and there was no way to tell whether the save worked.
		$this->notice( 'aicfab_settings_saved', __( 'Settings saved.', 'ai-chat-for-amazon-bedrock' ), 'success' );

		return $output;
	}

	private function is_plugin_screen( $hook_suffix ) {
		return false !== strpos( (string) $hook_suffix, $this->plugin_name );
	}
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
			'aicfab_aws'        => 'aicfab_tab_aws',
			'aicfab_model'      => 'aicfab_tab_model',
			'aicfab_governance' => 'aicfab_tab_governance',
			'aicfab_knowledge'  => 'aicfab_tab_knowledge',
			'aicfab_chat'       => 'aicfab_tab_chat',
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
		return array(
			'aws'        => array(
				'page'  => 'aicfab_tab_aws',
				'label' => __( 'AWS', 'ai-chat-for-amazon-bedrock' ),
			),
			'model'      => array(
				'page'  => 'aicfab_tab_model',
				'label' => __( 'Model', 'ai-chat-for-amazon-bedrock' ),
			),
			'chat'       => array(
				'page'  => 'aicfab_tab_chat',
				'label' => __( 'Chat', 'ai-chat-for-amazon-bedrock' ),
			),
			'knowledge'  => array(
				'page'  => 'aicfab_tab_knowledge',
				'label' => __( 'Grounding', 'ai-chat-for-amazon-bedrock' ),
			),
			'governance' => array(
				'page'  => 'aicfab_tab_governance',
				'label' => __( 'Safety and spend', 'ai-chat-for-amazon-bedrock' ),
			),
		);
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
	private function text_input( $key, $fallback, $maxlength ) {
		echo '<input type="text" class="regular-text" id="' . esc_attr( self::control_id( $key ) ) . '" name="ai_chat_bedrock_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $this->option( $key, $fallback ) ) . '" maxlength="' . absint( $maxlength ) . '">';
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
