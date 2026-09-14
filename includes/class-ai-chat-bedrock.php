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
		require_once $base . 'includes/class-ai-chat-bedrock-aws-credentials.php';
		require_once $base . 'includes/class-ai-chat-bedrock-event-stream.php';
		require_once $base . 'includes/class-ai-chat-bedrock-usage.php';
		require_once $base . 'includes/class-ai-chat-bedrock-aws.php';
		require_once $base . 'includes/class-ai-chat-bedrock-models.php';
		require_once $base . 'includes/class-ai-chat-bedrock-diagnostics.php';
		require_once $base . 'includes/class-ai-chat-bedrock-prompts.php';
		require_once $base . 'includes/class-ai-chat-bedrock-chat-request.php';
		require_once $base . 'includes/class-ai-chat-bedrock-profiles.php';
		require_once $base . 'includes/class-ai-chat-bedrock-embeddings.php';
		require_once $base . 'includes/class-ai-chat-bedrock-cli.php';
		require_once $base . 'includes/class-ai-chat-bedrock-retrieval.php';
		require_once $base . 'includes/class-ai-chat-bedrock-abilities.php';
		require_once $base . 'includes/class-ai-chat-bedrock-site-abilities.php';
		require_once $base . 'includes/class-ai-chat-bedrock-tool-policy.php';
		require_once $base . 'includes/class-ai-chat-bedrock-tool-log.php';
		require_once $base . 'includes/class-ai-chat-bedrock-tool-runner.php';
		require_once $base . 'includes/class-ai-chat-bedrock-conversations.php';
		require_once $base . 'includes/class-ai-chat-bedrock-editor-assistant.php';
		require_once $base . 'includes/class-ai-chat-bedrock-content-generator.php';
		require_once $base . 'includes/class-ai-chat-bedrock-generator-stream.php';
		require_once $base . 'includes/class-ai-chat-bedrock-media-assistant.php';
		require_once $base . 'includes/class-ai-chat-bedrock-feedback.php';
		require_once $base . 'includes/class-ai-chat-bedrock-sse.php';
		require_once $base . 'includes/class-ai-chat-bedrock-stream.php';
		require_once $base . 'admin/class-ai-chat-bedrock-admin.php';
		require_once $base . 'public/class-ai-chat-bedrock-public.php';
		require_once $base . 'includes/class-ai-chat-bedrock-mcp-transport.php';
		require_once $base . 'includes/class-ai-chat-bedrock-mcp-client.php';
		require_once $base . 'includes/class-ai-chat-bedrock-mcp-integration.php';
		require_once $base . 'includes/class-ai-chat-bedrock-wp-mcp-server.php';
		require_once $base . 'includes/class-ai-chat-bedrock-oauth.php';
		$this->loader = new AI_Chat_Bedrock_Loader();
	}

	private function set_locale() {
	}

	private function define_admin_hooks() {
		$admin    = new AI_Chat_Bedrock_Admin( $this->plugin_name, $this->version );
		$security = new AI_Chat_Bedrock_Security();
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu', $admin, 'add_plugin_admin_menu' );
		$this->loader->add_action( 'admin_init', $admin, 'register_settings' );
		$this->loader->add_action( 'admin_init', $security, 'maybe_migrate_credentials' );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_refresh_models', $admin, 'ajax_refresh_models' );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_run_diagnostics', $admin, 'ajax_run_diagnostics' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_clear_conversations', $admin, 'handle_clear_conversations' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_save_profile', $admin, 'handle_save_profile' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_delete_profile', $admin, 'handle_delete_profile' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_generate_content', $admin, 'handle_generate_content' );
		$this->loader->add_action( 'admin_notices', $admin, 'render_setup_notice' );
		$this->loader->add_filter( 'media_row_actions', $admin, 'add_media_row_action', 10, 2 );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_alt_text', $admin, 'handle_alt_text_action' );
		$this->loader->add_filter( 'bulk_actions-upload', $admin, 'add_media_bulk_action' );
		$this->loader->add_filter( 'handle_bulk_actions-upload', $admin, 'handle_media_bulk_action', 10, 3 );
		$this->loader->add_action( 'admin_notices', $admin, 'render_media_notice' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_export_conversations', $admin, 'handle_export_conversations' );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_index_embeddings', $admin, 'ajax_index_embeddings' );
		$this->loader->add_action( 'admin_post_ai_chat_bedrock_clear_embeddings', $admin, 'handle_clear_embeddings' );
		$this->loader->add_action( 'save_post', 'AI_Chat_Bedrock_Embeddings', 'invalidate' );
		$this->loader->add_action( 'init', 'AI_Chat_Bedrock_CLI', 'register' );
		$this->loader->add_action( 'init', 'AI_Chat_Bedrock_Embeddings', 'schedule' );
		$this->loader->add_action( 'update_option_ai_chat_bedrock_settings', 'AI_Chat_Bedrock_Embeddings', 'schedule' );
		$this->loader->add_action( AI_Chat_Bedrock_Embeddings::CRON_HOOK, 'AI_Chat_Bedrock_Embeddings', 'run_scheduled_index' );
		$this->loader->add_action( 'deleted_post', 'AI_Chat_Bedrock_Embeddings', 'invalidate' );

		$diagnostics = new AI_Chat_Bedrock_Diagnostics();
		$this->loader->add_filter( 'site_status_tests', $diagnostics, 'register_site_health_tests' );
	}

	private function define_public_hooks() {
		$public = new AI_Chat_Bedrock_Public( $this->plugin_name, $this->version );
		$this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_scripts' );
		$this->loader->add_shortcode( 'ai_chat_bedrock', $public, 'display_chat_interface' );
		$this->loader->add_action( 'init', $public, 'register_blocks' );
		$this->loader->add_action( 'wp_footer', $public, 'render_site_wide_popup', 5 );
		$this->loader->add_action( 'wp_ajax_ai_chat_bedrock_message', $public, 'handle_chat_message' );
		$this->loader->add_action( 'wp_ajax_nopriv_ai_chat_bedrock_message', $public, 'handle_chat_message' );

		$stream = new AI_Chat_Bedrock_Stream();
		$this->loader->add_action( 'rest_api_init', $stream, 'register_routes' );

		$assistant = new AI_Chat_Bedrock_Editor_Assistant();
		$this->loader->add_action( 'rest_api_init', $assistant, 'register_routes' );

		$media = new AI_Chat_Bedrock_Media_Assistant();
		$this->loader->add_action( 'rest_api_init', $media, 'register_routes' );

		$feedback = new AI_Chat_Bedrock_Feedback();
		$this->loader->add_action( 'rest_api_init', $feedback, 'register_routes' );

		$generator_stream = new AI_Chat_Bedrock_Generator_Stream();
		$this->loader->add_action( 'rest_api_init', $generator_stream, 'register_routes' );

		$this->loader->add_filter( 'wp_privacy_personal_data_exporters', 'AI_Chat_Bedrock_Conversations', 'register_exporter' );
		$this->loader->add_filter( 'wp_privacy_personal_data_erasers', 'AI_Chat_Bedrock_Conversations', 'register_eraser' );
		$this->loader->add_action( 'enqueue_block_editor_assets', $assistant, 'enqueue_editor_assets' );
	}

	private function define_mcp_hooks() {
		$mcp = new AI_Chat_Bedrock_MCP_Integration();
		$mcp->register_hooks( $this->loader );

		$abilities = new AI_Chat_Bedrock_Abilities();
		$this->loader->add_action( 'abilities_api_init', $abilities, 'register_abilities' );
		$this->loader->add_action( 'init', $abilities, 'register_abilities', 20 );
		$this->loader->add_filter( 'ai_chat_bedrock_message_payload', $abilities, 'add_ability_tools', 20, 2 );
		$this->loader->add_filter( 'ai_chat_bedrock_process_response', $abilities, 'execute_ability_tools', 20, 2 );

		$site_abilities = new AI_Chat_Bedrock_Site_Abilities();
		$this->loader->add_action( 'abilities_api_init', $site_abilities, 'register' );
		$this->loader->add_action( 'init', $site_abilities, 'register', 21 );
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

	public function get_version() {
		return $this->version;
	}
}
