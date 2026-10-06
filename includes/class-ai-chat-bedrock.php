<?php
/**
 * Core plugin class.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock {
	protected $loader;
	protected $plugin_name = 'ai-chat-for-amazon-bedrock';
	protected $version;

	public function __construct() {
		$this->version = defined( 'AI_CHAT_BEDROCK_VERSION' ) ? AI_CHAT_BEDROCK_VERSION : '1.1.0';
		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_mcp_hooks();
		$this->init_wp_mcp_server();
	}

	private function load_dependencies() {
		$base = plugin_dir_path( __DIR__ );
		require_once $base . 'includes/class-ai-chat-bedrock-loader.php';
		require_once $base . 'includes/class-ai-chat-bedrock-security.php';
		require_once $base . 'includes/class-ai-chat-bedrock-rate-limits.php';
		require_once $base . 'includes/class-ai-chat-bedrock-iam-policy.php';
		require_once $base . 'includes/class-ai-chat-bedrock-bedrock-errors.php';
		require_once $base . 'includes/class-ai-chat-bedrock-scaffold.php';
		require_once $base . 'includes/class-ai-chat-bedrock-insights.php';
		require_once $base . 'includes/class-ai-chat-bedrock-setup-steps.php';
		require_once $base . 'includes/class-ai-chat-bedrock-transfer.php';
		require_once $base . 'includes/class-ai-chat-bedrock-aws-credentials.php';
		require_once $base . 'includes/class-ai-chat-bedrock-event-stream.php';
		require_once $base . 'includes/class-ai-chat-bedrock-usage.php';
		require_once $base . 'includes/class-ai-chat-bedrock-aws.php';
		require_once $base . 'includes/class-ai-chat-bedrock-models.php';
		require_once $base . 'includes/class-ai-chat-bedrock-diagnostics.php';
		require_once $base . 'includes/class-ai-chat-bedrock-prompts.php';
		require_once $base . 'includes/class-ai-chat-bedrock-chat-request.php';
		require_once $base . 'includes/class-ai-chat-bedrock-profiles.php';
		require_once $base . 'includes/class-ai-chat-bedrock-content.php';
		require_once $base . 'includes/class-ai-chat-bedrock-embeddings.php';
		require_once $base . 'includes/class-ai-chat-bedrock-images.php';
		require_once $base . 'includes/class-ai-chat-bedrock-s3-vectors.php';
		require_once $base . 'includes/class-ai-chat-bedrock-integrations.php';
		require_once $base . 'includes/class-ai-chat-bedrock-translation.php';
		require_once $base . 'includes/class-ai-chat-bedrock-cli.php';
		require_once $base . 'includes/class-ai-chat-bedrock-retrieval.php';
		require_once $base . 'includes/class-ai-chat-bedrock-woocommerce.php';
		require_once $base . 'includes/class-ai-chat-bedrock-abilities.php';
		require_once $base . 'includes/class-ai-chat-bedrock-site-abilities.php';
		require_once $base . 'includes/class-ai-chat-bedrock-ontology.php';
		require_once $base . 'includes/class-ai-chat-bedrock-metrics.php';
		require_once $base . 'includes/class-ai-chat-bedrock-tool-policy.php';
		require_once $base . 'includes/class-ai-chat-bedrock-tool-log.php';
		require_once $base . 'includes/class-ai-chat-bedrock-tool-runner.php';
		require_once $base . 'includes/class-ai-chat-bedrock-demo.php';
		require_once $base . 'includes/class-ai-chat-bedrock-conversations.php';
		require_once $base . 'includes/class-ai-chat-bedrock-chat-history.php';
		require_once $base . 'includes/class-ai-chat-bedrock-speech.php';
		require_once $base . 'includes/class-ai-chat-bedrock-editor-assistant.php';
		require_once $base . 'includes/class-ai-chat-bedrock-content-generator.php';
		require_once $base . 'includes/class-ai-chat-bedrock-generator-stream.php';
		require_once $base . 'includes/class-ai-chat-bedrock-media-assistant.php';
		require_once $base . 'includes/class-ai-chat-bedrock-feedback.php';
		require_once $base . 'includes/class-ai-chat-bedrock-leads.php';
		require_once $base . 'includes/class-ai-chat-bedrock-analytics.php';
		require_once $base . 'includes/class-ai-chat-bedrock-consent.php';
		require_once $base . 'includes/class-ai-chat-bedrock-wechat.php';
		require_once $base . 'includes/class-ai-chat-bedrock-wechat-api.php';
		require_once $base . 'includes/class-ai-chat-bedrock-wechat-game.php';
		require_once $base . 'includes/class-ai-chat-bedrock-wechat-drafts.php';
		require_once $base . 'includes/class-ai-chat-bedrock-publish-kit.php';
		require_once $base . 'includes/class-ai-chat-bedrock-distribution.php';
		require_once $base . 'includes/class-ai-chat-bedrock-bilibili.php';
		require_once $base . 'includes/class-ai-chat-bedrock-youtube.php';
		require_once $base . 'includes/class-ai-chat-bedrock-review-prompt.php';
		require_once $base . 'includes/class-ai-chat-bedrock-sse.php';
		require_once $base . 'includes/class-ai-chat-bedrock-stream.php';
		require_once $base . 'admin/class-ai-chat-bedrock-admin.php';
		require_once $base . 'public/class-ai-chat-bedrock-public.php';
		require_once $base . 'includes/class-ai-chat-bedrock-mcp-transport.php';
		require_once $base . 'includes/class-ai-chat-bedrock-mcp-client.php';
		require_once $base . 'includes/class-ai-chat-bedrock-mcp-integration.php';
		require_once $base . 'includes/class-ai-chat-bedrock-wp-mcp-server.php';
		require_once $base . 'includes/class-ai-chat-bedrock-oauth.php';
		require_once $base . 'includes/class-ai-chat-bedrock-core-ai.php';
		require_once $base . 'includes/class-ai-chat-bedrock-eval.php';
		$this->loader = new AI_Chat_Bedrock_Loader();
	}

	private function set_locale() {
		$this->loader->add_filter( 'lang_dir_for_domain', 'AI_Chat_Bedrock_Translation', 'bundled_languages', 10, 3 );
	}

	private function define_admin_hooks() {
		$admin    = new AI_Chat_Bedrock_Admin( $this->plugin_name, self::asset_version() );
		$security = new AI_Chat_Bedrock_Security();
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
		$this->loader->add_filter( 'removable_query_args', $admin, 'removable_query_args' );
		$this->loader->add_action( 'admin_menu', $admin, 'add_plugin_admin_menu' );
		$this->loader->add_action( 'admin_init', $admin, 'register_settings' );
		$this->loader->add_action( 'admin_init', $security, 'maybe_migrate_credentials' );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_refresh_models', $admin, 'ajax_refresh_models' );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_run_diagnostics', $admin, 'ajax_run_diagnostics' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_clear_conversations', $admin, 'handle_clear_conversations' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_create_chat_page', 'AI_Chat_Bedrock_Setup_Steps', 'handle_create_chat_page' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_save_profile', $admin, 'handle_save_profile' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_delete_profile', $admin, 'handle_delete_profile' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_generate_content', $admin, 'handle_generate_content' );
		$this->loader->add_action( 'admin_notices', $admin, 'render_setup_notice' );
		$this->loader->add_action( 'admin_notices', $admin, 'render_demo_notice' );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_dismiss_setup_notice', $admin, 'ajax_dismiss_setup_notice' );
		$this->loader->add_action( 'admin_notices', $admin, 'render_review_prompt' );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_dismiss_review_prompt', 'AI_Chat_Bedrock_Review_Prompt', 'ajax_dismiss' );
		$this->loader->add_filter( 'media_row_actions', $admin, 'add_media_row_action', 10, 2 );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_alt_text', $admin, 'handle_alt_text_action' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_edit_image', $admin, 'handle_edit_image_action' );
		$this->loader->add_filter( 'bulk_actions-upload', $admin, 'add_media_bulk_action' );
		$this->loader->add_filter( 'handle_bulk_actions-upload', $admin, 'handle_media_bulk_action', 10, 3 );
		$this->loader->add_action( 'admin_notices', $admin, 'render_media_notice' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_export_conversations', $admin, 'handle_export_conversations' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_export_gaps', $admin, 'handle_export_gaps' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_export_metrics', $admin, 'handle_export_metrics' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_metrics_ask', $admin, 'handle_metrics_ask' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_export_settings', $admin, 'handle_export_settings' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_import_settings', $admin, 'handle_import_settings' );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_index_embeddings', $admin, 'ajax_index_embeddings' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_clear_embeddings', $admin, 'handle_clear_embeddings' );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_s3_vectors', $admin, 'ajax_s3_vectors' );
		$this->loader->add_action( 'save_post', 'AI_Chat_Bedrock_Embeddings', 'invalidate' );
		$this->loader->add_action( 'init', 'AI_Chat_Bedrock_CLI', 'register' );
		$this->loader->add_action( 'init', 'AI_Chat_Bedrock_Integrations', 'init' );
		$this->loader->add_action( 'admin_init', 'AI_Chat_Bedrock_Translation', 'register' );
		$this->loader->add_action( 'init', 'AI_Chat_Bedrock_Embeddings', 'schedule' );
		$this->loader->add_action( 'update_option_ai_chat_bedrock_settings', 'AI_Chat_Bedrock_Embeddings', 'schedule' );
		$this->loader->add_action( AI_Chat_Bedrock_Embeddings::CRON_HOOK, 'AI_Chat_Bedrock_Embeddings', 'run_scheduled_index' );
		$this->loader->add_action( 'init', 'AI_Chat_Bedrock_Chat_History', 'schedule' );
		$this->loader->add_action( 'update_option_ai_chat_bedrock_settings', 'AI_Chat_Bedrock_Chat_History', 'schedule' );
		$this->loader->add_action( 'update_option_ai_chat_bedrock_settings', 'AI_Chat_Bedrock_Chat_History', 'settings_updated', 10, 2 );
		$this->loader->add_action( AI_Chat_Bedrock_Chat_History::CRON_HOOK, 'AI_Chat_Bedrock_Chat_History', 'prune_expired' );
		$this->loader->add_action( 'init', 'AI_Chat_Bedrock_Leads', 'register_post_type' );
		$this->loader->add_action( 'init', 'AI_Chat_Bedrock_Leads', 'schedule' );
		$this->loader->add_action( 'init', 'AI_Chat_Bedrock_Consent', 'describe_storage' );
		$this->loader->add_action( 'update_option_ai_chat_bedrock_settings', 'AI_Chat_Bedrock_Leads', 'schedule' );
		$this->loader->add_action( AI_Chat_Bedrock_Leads::CRON_HOOK, 'AI_Chat_Bedrock_Leads', 'prune_expired' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_lead', 'AI_Chat_Bedrock_Leads', 'handle_admin_action' );
		// Before, not after: once a post is deleted its meta is gone and so is the record of its S3 vectors.
		$this->loader->add_action( 'before_delete_post', 'AI_Chat_Bedrock_Embeddings', 'forget' );

		$diagnostics = new AI_Chat_Bedrock_Diagnostics();
		$this->loader->add_filter( 'site_status_tests', $diagnostics, 'register_site_health_tests' );
	}

	private function define_public_hooks() {
		$public = new AI_Chat_Bedrock_Public( $this->plugin_name, self::asset_version() );
		$this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_scripts' );
		$this->loader->add_filter( 'wp_inline_script_attributes', $public, 'early_script_attributes' );
		$this->loader->add_filter( 'perfmatters_delay_js_exclusions', $public, 'perfmatters_delay_exclusions' );
		$this->loader->add_shortcode( 'ai_chat_bedrock', $public, 'display_chat_interface' );
		$this->loader->add_action( 'init', $public, 'register_blocks' );
		$this->loader->add_action( 'wp_footer', $public, 'render_site_wide_popup', 5 );
		// After every chat has rendered and before wp_print_footer_scripts, at 20.
		$this->loader->add_action( 'wp_footer', $public, 'trim_scripts', 19 );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_message', $public, 'handle_chat_message' );
		$this->loader->add_action( 'wp_ajax_nopriv_ai_chat_bedrock_message', $public, 'handle_chat_message' );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_refresh_nonce', $public, 'handle_refresh_nonce' );
		$this->loader->add_action( 'wp_ajax_nopriv_ai_chat_bedrock_refresh_nonce', $public, 'handle_refresh_nonce' );

		$stream = new AI_Chat_Bedrock_Stream();
		$this->loader->add_action( 'rest_api_init', $stream, 'register_routes' );

		$assistant = new AI_Chat_Bedrock_Editor_Assistant();
		$this->loader->add_action( 'rest_api_init', $assistant, 'register_routes' );

		$media = new AI_Chat_Bedrock_Media_Assistant();
		$this->loader->add_action( 'rest_api_init', $media, 'register_routes' );

		$woocommerce = new AI_Chat_Bedrock_WooCommerce();
		$this->loader->add_action( 'rest_api_init', $woocommerce, 'register_routes' );
		$this->loader->add_action( 'add_meta_boxes_product', $woocommerce, 'add_meta_box' );
		$this->loader->add_action( 'admin_enqueue_scripts', $woocommerce, 'enqueue_assets' );
		$this->loader->add_action( 'admin_init', 'AI_Chat_Bedrock_WooCommerce', 'privacy_policy_content' );

		// The dashboard checklist looks at published content and at the floating button, so
		// both have to invalidate the cached answer.
		$this->loader->add_action( 'update_option_ai_chat_bedrock_settings', 'AI_Chat_Bedrock_Setup_Steps', 'flush' );
		$this->loader->add_action( 'save_post', 'AI_Chat_Bedrock_Setup_Steps', 'flush' );
		$this->loader->add_action( 'deleted_post', 'AI_Chat_Bedrock_Setup_Steps', 'flush' );

		$scaffold = new AI_Chat_Bedrock_Scaffold();
		$eval     = new AI_Chat_Bedrock_Eval();
		$this->loader->add_action( 'wp_ajax_aicfab_eval_save', $eval, 'ajax_save' );
		$this->loader->add_action( 'wp_ajax_aicfab_eval_propose', $eval, 'ajax_propose' );
		$this->loader->add_action( 'wp_ajax_aicfab_eval_run', $eval, 'ajax_run' );

		$this->loader->add_action( 'wp_ajax_aicfab_scaffold_plan', $scaffold, 'ajax_plan' );
		$this->loader->add_action( 'wp_ajax_aicfab_scaffold_create', $scaffold, 'ajax_create' );

		$feedback = new AI_Chat_Bedrock_Feedback();
		$this->loader->add_action( 'rest_api_init', $feedback, 'register_routes' );

		$leads = new AI_Chat_Bedrock_Leads();
		$this->loader->add_action( 'rest_api_init', $leads, 'register_routes' );

		$wechat = new AI_Chat_Bedrock_WeChat();
		$this->loader->add_action( 'rest_api_init', $wechat, 'register_routes' );
		$this->loader->add_filter( 'rest_pre_serve_request', 'AI_Chat_Bedrock_WeChat', 'serve', 10, 3 );

		$wechat_game = new AI_Chat_Bedrock_WeChat_Game();
		$this->loader->add_action( 'rest_api_init', $wechat_game, 'register_routes' );
		$this->loader->add_filter( 'rest_pre_serve_request', 'AI_Chat_Bedrock_WeChat_Game', 'serve', 10, 3 );

		$chat_history = new AI_Chat_Bedrock_Chat_History();
		$this->loader->add_action( 'rest_api_init', $chat_history, 'register_routes' );

		// Before the chat script, which depends on the player script when answers can be heard.
		$speech = new AI_Chat_Bedrock_Speech();
		$this->loader->add_action( 'rest_api_init', $speech, 'register_routes' );
		$this->loader->add_action( 'wp_enqueue_scripts', 'AI_Chat_Bedrock_Speech', 'enqueue_assets', 5 );
		$this->loader->add_filter( 'the_content', 'AI_Chat_Bedrock_Speech', 'add_player', 20 );
		$this->loader->add_action( 'save_post', 'AI_Chat_Bedrock_Speech', 'forget_post' );
		$this->loader->add_action( 'before_delete_post', 'AI_Chat_Bedrock_Speech', 'forget_post' );
		$this->loader->add_action( 'update_option_ai_chat_bedrock_settings', 'AI_Chat_Bedrock_Speech', 'settings_updated', 10, 2 );

		$generator_stream = new AI_Chat_Bedrock_Generator_Stream();
		$this->loader->add_action( 'rest_api_init', $generator_stream, 'register_routes' );

		$this->loader->add_filter( 'wp_privacy_personal_data_exporters', 'AI_Chat_Bedrock_Conversations', 'register_exporter' );
		$this->loader->add_filter( 'wp_privacy_personal_data_erasers', 'AI_Chat_Bedrock_Conversations', 'register_eraser' );
		$this->loader->add_filter( 'wp_privacy_personal_data_exporters', 'AI_Chat_Bedrock_Chat_History', 'register_exporter' );
		$this->loader->add_filter( 'wp_privacy_personal_data_erasers', 'AI_Chat_Bedrock_Chat_History', 'register_eraser' );
		$this->loader->add_filter( 'wp_privacy_personal_data_exporters', 'AI_Chat_Bedrock_Leads', 'register_exporter' );
		$this->loader->add_filter( 'wp_privacy_personal_data_erasers', 'AI_Chat_Bedrock_Leads', 'register_eraser' );
		$this->loader->add_action( 'enqueue_block_editor_assets', $assistant, 'enqueue_editor_assets' );
	}

	private function define_mcp_hooks() {
		$mcp = new AI_Chat_Bedrock_MCP_Integration();
		$mcp->register_hooks( $this->loader );

		/*
		 * The hook is wp_abilities_api_init, with the prefix. This read abilities_api_init, which
		 * is not a hook WordPress has ever fired, and the init fallback beside it was refused by
		 * core because wp_register_ability() checks doing_action( 'wp_abilities_api_init' ) and
		 * warns that the ability was not registered. The result was that every ability this
		 * plugin defines was absent from the registry, so core's own MCP adapter and anything
		 * else reading the Abilities API saw nothing here at all.
		 */
		$abilities = new AI_Chat_Bedrock_Abilities();
		$this->loader->add_action( 'wp_abilities_api_categories_init', $abilities, 'register_category' );
		$this->loader->add_action( 'wp_abilities_api_init', $abilities, 'register_abilities' );
		$this->loader->add_filter( 'ai_chat_bedrock_message_payload', $abilities, 'add_ability_tools', 20, 2 );
		$this->loader->add_filter( 'ai_chat_bedrock_process_response', $abilities, 'execute_ability_tools', 20, 2 );

		// Offer this site's Bedrock configuration through core's own AI API, where core
		// has one. Guarded internally, so nothing happens on WordPress without it.
		AI_Chat_Bedrock_Core_AI::init();

		$site_abilities = new AI_Chat_Bedrock_Site_Abilities();
		$this->loader->add_action( 'wp_abilities_api_init', $site_abilities, 'register' );

		$distribution = new AI_Chat_Bedrock_Distribution();
		$this->loader->add_action( 'wp_abilities_api_init', $distribution, 'register_abilities' );
		$this->loader->add_action( 'add_meta_boxes', $distribution, 'add_meta_box' );
		$this->loader->add_filter( 'the_content', 'AI_Chat_Bedrock_Distribution', 'add_links', 25 );
		$this->loader->add_action( 'init', 'AI_Chat_Bedrock_Bilibili', 'register' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_youtube_connect', 'AI_Chat_Bedrock_YouTube', 'handle_connect' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_youtube_callback', 'AI_Chat_Bedrock_YouTube', 'handle_callback' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_youtube_disconnect', 'AI_Chat_Bedrock_YouTube', 'handle_disconnect' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_youtube_upload', 'AI_Chat_Bedrock_YouTube', 'handle_upload' );
		$this->loader->add_action( 'ai_chat_bedrock_youtube_upload', 'AI_Chat_Bedrock_YouTube', 'run' );
		$this->loader->add_action( 'ai_chat_bedrock_youtube_status', 'AI_Chat_Bedrock_YouTube', 'poll' );
		$this->loader->add_action( 'ai_chat_bedrock_distribution_box', 'AI_Chat_Bedrock_YouTube', 'render_box_section' );

		$wechat_drafts = new AI_Chat_Bedrock_WeChat_Drafts();
		$this->loader->add_action( 'wp_abilities_api_init', $wechat_drafts, 'register_abilities' );
		$this->loader->add_action( 'rest_api_init', $wechat_drafts, 'register_routes' );
		$this->loader->add_action( 'init', 'AI_Chat_Bedrock_WeChat_Drafts', 'sync_schedule' );
		$this->loader->add_action( 'ai_chat_bedrock_wechat_drafts', 'AI_Chat_Bedrock_WeChat_Drafts', 'run' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_wechat_draft', 'AI_Chat_Bedrock_WeChat_Drafts', 'handle_send' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_wechat_drafts_run', 'AI_Chat_Bedrock_WeChat_Drafts', 'handle_run_now' );
		$this->loader->add_action( 'ai_chat_bedrock_distribution_box', 'AI_Chat_Bedrock_WeChat_Drafts', 'render_box_section' );
		$this->loader->add_action( 'save_post', 'AI_Chat_Bedrock_Distribution', 'save_manual_record' );

		$publish_kit = new AI_Chat_Bedrock_Publish_Kit();
		$this->loader->add_action( 'rest_api_init', $publish_kit, 'register_routes' );
		$this->loader->add_action( 'wp_abilities_api_init', $publish_kit, 'register_abilities' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_publish_kit', 'AI_Chat_Bedrock_Publish_Kit', 'handle_write' );
		$this->loader->add_action( 'ai_chat_bedrock_distribution_box', 'AI_Chat_Bedrock_Publish_Kit', 'render_box_section' );
		$this->loader->add_action( 'save_post', 'AI_Chat_Bedrock_WeChat_Drafts', 'save_video' );

		// The site description, and the type and language it adds to retrieved passages.
		// Both check the setting when they run.
		$ontology = new AI_Chat_Bedrock_Ontology();
		$this->loader->add_action( 'wp_abilities_api_init', $ontology, 'register' );
		$this->loader->add_filter( 'ai_chat_bedrock_retrieved_passages', 'AI_Chat_Bedrock_Ontology', 'annotate_passages', 5 );

		$metrics = new AI_Chat_Bedrock_Metrics();
		$this->loader->add_action( 'wp_abilities_api_init', $metrics, 'register' );
		$this->loader->add_filter( 'ai_chat_bedrock_ontology_metrics', 'AI_Chat_Bedrock_Metrics', 'describe_metrics' );
	}

	private function init_wp_mcp_server() {
		if ( get_option( 'ai_chat_bedrock_enable_mcp', false ) ) {
			new AI_Chat_Bedrock_WP_MCP_Server();

			$oauth = new AI_Chat_Bedrock_OAuth();
			$this->loader->add_action( 'rest_api_init', $oauth, 'register_routes' );
			$this->loader->add_filter( 'do_parse_request', $oauth, 'maybe_handle_root_request' );
			$this->loader->add_filter( 'determine_current_user', $oauth, 'authenticate_bearer', 20 );
		}
	}

	public function run() {
		$this->loader->run();
	}

	public function get_plugin_name() {
		return $this->plugin_name;
	}

	public function get_loader() {
		return $this->loader;
	}

	/**
	 * The version scripts and styles are registered with: the plugin version and its build.
	 *
	 * @return string
	 */
	public static function asset_version() {
		if ( defined( 'AI_CHAT_BEDROCK_ASSET_VERSION' ) ) {
			return AI_CHAT_BEDROCK_ASSET_VERSION;
		}
		return defined( 'AI_CHAT_BEDROCK_VERSION' ) ? AI_CHAT_BEDROCK_VERSION : '1.1.0';
	}

	public function get_version() {
		return $this->version;
	}
}
