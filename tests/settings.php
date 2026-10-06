<?php
/**
 * Standalone tests for saving the settings screen.
 *
 * Each tab submits only its own fields and names them in _aicfab_fields. Values a field
 * renders next to its own control (the retention period beside the log switch, the profile
 * beside the site-wide popup) are not in that list, and were silently dropped on every save.
 * Settings mirrored into options of their own were written from the submitted tab only, so
 * saving any other tab switched the conversation log and site abilities off.
 *
 * Run: php tests/settings.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_notices'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_options'][ $name ] = $value;
	return true;
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['aicfab_notices'][ $code ] = $type;
}
function delete_transient( $key ) {
	$GLOBALS['aicfab_deleted_transients'][] = $key;
	return true;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function __( $text, $domain = null ) {
	return $text;
}
function absint( $value ) {
	return abs( (int) $value );
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function sanitize_textarea_field( $value ) {
	return trim( (string) $value );
}
function pll_languages_list( $args = array() ) {
	return 'name' === $args['fields'] ? array( '中文', 'English' ) : array( 'zh', 'en' );
}

class AI_Chat_Bedrock_Models {
	const DEFAULT_MODEL = 'amazon.nova-lite-v1:0';
	public static function regions() {
		return array(
			'us-east-1'      => 'US East',
			'ap-northeast-1' => 'Tokyo',
		);
	}
	public static function is_valid_id( $id ) {
		return 1 === preg_match( '/^[a-z0-9.:-]+$/', (string) $id );
	}
	public static function flush_cache() {}
}
class AI_Chat_Bedrock_AWS_Credentials {
	public static function clean_api_key( $key ) {
		return '';
	}
	public static function flush_cache() {}
}
class AI_Chat_Bedrock_Rate_Limits {
	public static function save( $limits ) {}
}
class AI_Chat_Bedrock_Chat_Request {
	public static function sanitize_suggestions( $value ) {
		return (string) $value;
	}
	public static function color_scheme( $value ) {
		return in_array( $value, array( 'light', 'dark', 'auto' ), true ) ? $value : 'light';
	}
}
class AI_Chat_Bedrock_Profiles {
	public static function sanitize_key( $value ) {
		return sanitize_key( $value );
	}
}
class AI_Chat_Bedrock_Embeddings {
	public static function models() {
		return array( 'amazon.titan-embed-text-v2:0' => 'Titan' );
	}
}
class AI_Chat_Bedrock_Conversations {
	const OPTION_ENABLED   = 'ai_chat_bedrock_log_conversations';
	const OPTION_RETENTION = 'ai_chat_bedrock_log_retention_days';
	const DEFAULT_DAYS     = 14;
	const MAX_DAYS         = 90;
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt';
	}
}
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-s3-vectors.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-integrations.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-woocommerce.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-images.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-retrieval.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-chat-history.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-speech.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-leads.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat-game.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wechat-drafts.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-youtube.php';
require dirname( __DIR__ ) . '/admin/class-ai-chat-bedrock-admin.php';

$failures = array();
function check_set( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$admin = new AI_Chat_Bedrock_Admin( 'ai-chat-for-amazon-bedrock', 'test' );

/**
 * Save one tab the way the settings form does, and store the result.
 */
function save_tab( $admin, $fields, $values ) {
	$GLOBALS['aicfab_notices'] = array();
	$values['_aicfab_fields']  = $fields;
	$saved                     = $admin->validate_settings( $values );
	$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = $saved;
	return $saved;
}

// --- Companion values are saved with their field -------------------------------

$saved = save_tab(
	$admin,
	array( 'log_conversations', 'popup_site_wide', 'prompt_id', 'embedding_model_id', 'vector_store' ),
	array(
		'log_conversations'    => '1',
		'log_retention_days'   => '30',
		'popup_site_wide'      => '1',
		'popup_profile'        => 'support',
		'prompt_id'            => 'PROMPT123',
		'prompt_version'       => '3',
		'embedding_model_id'   => 'amazon.titan-embed-text-v2:0',
		'embedding_background' => '1',
		'vector_store'         => 's3_vectors',
		's3_vectors_bucket'    => 'Site-Vectors',
		's3_vectors_index'     => 'posts',
		's3_vectors_region'    => 'ap-northeast-1',
	)
);
check_set( 30 === $saved['log_retention_days'], 'The retention period is saved with the log switch.' );
check_set( 'support' === $saved['popup_profile'], 'The popup profile is saved with the site-wide switch.' );
check_set( '3' === $saved['prompt_version'], 'The prompt version is saved with the prompt.' );
check_set( true === $saved['embedding_background'], 'Background indexing is saved with the embedding model.' );
check_set( 's3_vectors' === $saved['vector_store'] && 'site-vectors' === $saved['s3_vectors_bucket'] && 'posts' === $saved['s3_vectors_index'], 'The vector store and its lowercased names are saved.' );
check_set( 'ap-northeast-1' === $saved['s3_vectors_region'], 'The vector Region is saved.' );
check_set( ! isset( $GLOBALS['aicfab_notices']['vector_store'] ), 'A complete S3 Vectors setup raises no warning.' );
check_set( true === get_option( 'ai_chat_bedrock_log_conversations' ) && 30 === get_option( 'ai_chat_bedrock_log_retention_days' ), 'The log options mirror the saved values.' );

// --- Saving another tab keeps everything above ---------------------------------

$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['site_abilities'] = true;
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['site_ontology']  = true;
$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask us' ) );
check_set( true === $saved['site_ontology'], 'Another tab does not switch the site description off.' );
check_set( 'Ask us' === $saved['chat_title'], 'The submitted field is saved.' );
check_set( true === $saved['log_conversations'] && 30 === $saved['log_retention_days'], 'Another tab does not reset the conversation log.' );
check_set( 'support' === $saved['popup_profile'] && '3' === $saved['prompt_version'], 'Another tab does not drop companion values.' );
check_set( 'site-vectors' === $saved['s3_vectors_bucket'], 'Another tab does not drop the vector store.' );
check_set( true === get_option( 'ai_chat_bedrock_log_conversations' ), 'Another tab does not switch the log option off.' );
check_set( true === get_option( 'ai_chat_bedrock_site_abilities' ), 'Another tab does not switch site abilities off.' );

// The Grounding tab saves the site description, and unticking it switches it off.
$saved = save_tab( $admin, array( 'site_ontology' ), array( 'site_ontology' => '1' ) );
check_set( true === $saved['site_ontology'], 'The site description switch is saved.' );
$saved = save_tab( $admin, array( 'site_ontology' ), array() );
check_set( false === $saved['site_ontology'] && true === $saved['site_abilities'], 'An unticked site description is saved as off, and nothing else changes.' );

// Business insights are saved from the same tab, and unticking switches them off.
$saved = save_tab( $admin, array( 'business_metrics' ), array( 'business_metrics' => '1' ) );
check_set( true === $saved['business_metrics'], 'The business insights switch is saved.' );
$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask us' ) );
check_set( true === $saved['business_metrics'], 'Another tab does not switch business insights off.' );
$saved = save_tab( $admin, array( 'business_metrics' ), array() );
check_set( false === $saved['business_metrics'] && true === $saved['site_abilities'], 'Unticked business insights are saved as off, and nothing else changes.' );

// Unticking the owner field clears it, and its companions follow the submission.
$saved = save_tab( $admin, array( 'log_conversations' ), array( 'log_retention_days' => '7' ) );
check_set( false === $saved['log_conversations'] && 7 === $saved['log_retention_days'], 'An unticked log switch is saved as off with its retention.' );
check_set( false === get_option( 'ai_chat_bedrock_log_conversations' ), 'The log option follows the switch.' );

// --- WooCommerce ---------------------------------------------------------------

$saved = save_tab(
	$admin,
	array( 'woo_catalog', 'woo_catalog_limit', 'woo_orders', 'woo_product_assistant' ),
	array(
		'woo_catalog'       => '1',
		'woo_catalog_limit' => '50',
	)
);
check_set( true === $saved['woo_catalog'] && false === $saved['woo_orders'] && false === $saved['woo_product_assistant'], 'WooCommerce switches are saved as booleans.' );
check_set( 8 === $saved['woo_catalog_limit'], 'Products per answer is kept to the maximum.' );
$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask the shop' ) );
check_set( true === $saved['woo_catalog'] && 8 === $saved['woo_catalog_limit'], 'Another tab does not reset the WooCommerce settings.' );

// --- Validation ----------------------------------------------------------------

$saved = save_tab(
	$admin,
	array( 'vector_store' ),
	array(
		'vector_store'      => 's3_vectors',
		's3_vectors_bucket' => 'bad_bucket!',
		's3_vectors_index'  => 'posts',
		's3_vectors_region' => 'mars-north-1',
	)
);
check_set( '' === $saved['s3_vectors_bucket'] && isset( $GLOBALS['aicfab_notices']['s3_vectors_bucket'] ), 'An invalid bucket name is cleared with a notice.' );
check_set( 'warning' === ( isset( $GLOBALS['aicfab_notices']['vector_store'] ) ? $GLOBALS['aicfab_notices']['vector_store'] : '' ), 'S3 Vectors without a bucket warns that search is off.' );
check_set( '' === $saved['s3_vectors_region'], 'An unknown Region falls back to the Bedrock Region.' );
$saved = save_tab( $admin, array( 'vector_store' ), array( 'vector_store' => 'elasticsearch' ) );
check_set( 'post_meta' === $saved['vector_store'], 'An unknown store falls back to post meta.' );

$saved = save_tab(
	$admin,
	array( 'github_read_scope', 'social_only_registration', 'hreflang_x_default', 'organization_author' ),
	array(
		'github_read_scope'  => '1',
		'hreflang_x_default' => 'en',
	)
);
check_set( true === $saved['github_read_scope'] && false === $saved['social_only_registration'] && false === $saved['organization_author'], 'Integration switches are saved as booleans.' );
check_set( 'en' === $saved['hreflang_x_default'], 'A served language is accepted for x-default.' );
$saved = save_tab( $admin, array( 'hreflang_x_default' ), array( 'hreflang_x_default' => 'fr' ) );
check_set( '' === $saved['hreflang_x_default'], 'A language the site does not serve is refused for x-default.' );
check_set( true === $saved['github_read_scope'], 'Saving one integration keeps the others.' );

$saved = save_tab( $admin, array( 'show_sources' ), array( 'show_sources' => '1' ) );
check_set( true === $saved['show_sources'], 'Showing sources is saved.' );
$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask' ) );
check_set( true === $saved['show_sources'], 'Another tab keeps sources shown.' );
$saved = save_tab( $admin, array( 'show_sources' ), array() );
check_set( false === $saved['show_sources'], 'An unticked sources switch is saved as off.' );

// --- Conversation memory --------------------------------------------------------

$saved = save_tab( $admin, array( 'chat_memory' ), array( 'chat_memory' => 'account', 'chat_memory_days' => '400' ) );
check_set( 'account' === $saved['chat_memory'] && AI_Chat_Bedrock_Chat_History::MAX_DAYS === $saved['chat_memory_days'], 'Account memory is saved with its retention, capped at a year.' );
$saved = save_tab( $admin, array( 'chat_color_scheme' ), array( 'chat_color_scheme' => 'light' ) );
check_set( 'account' === $saved['chat_memory'] && 365 === $saved['chat_memory_days'], 'Another tab keeps the memory setting and its retention.' );
$saved = save_tab( $admin, array( 'chat_memory' ), array( 'chat_memory' => 'everywhere', 'chat_memory_days' => '0' ) );
check_set( '' === $saved['chat_memory'] && AI_Chat_Bedrock_Chat_History::DEFAULT_DAYS === $saved['chat_memory_days'], 'An unknown memory mode is saved as off, and no retention as the default.' );
$saved = save_tab( $admin, array( 'chat_memory' ), array( 'chat_memory' => 'tab', 'chat_memory_days' => '14' ) );
check_set( 'tab' === $saved['chat_memory'] && 14 === $saved['chat_memory_days'], 'Tab memory is saved.' );

// --- Every field shows on its section's tab ----------------------------------------

$GLOBALS['aicfab_sections'] = array();
$GLOBALS['aicfab_fields']   = array();
if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $group, $name, $args = array() ) {}
	function add_settings_section( $id, $title, $callback, $page ) {
		$GLOBALS['aicfab_sections'][ $id ] = $page;
	}
	function add_settings_field( $id, $title, $callback, $page, $section, $args = array() ) {
		$GLOBALS['aicfab_fields'][ $id ] = array( $page, $section );
	}
}
$admin->register_settings();
$aicfab_misplaced = array();
foreach ( $GLOBALS['aicfab_fields'] as $aicfab_field => $aicfab_where ) {
	if ( ! isset( $GLOBALS['aicfab_sections'][ $aicfab_where[1] ] ) || $GLOBALS['aicfab_sections'][ $aicfab_where[1] ] !== $aicfab_where[0] ) {
		$aicfab_misplaced[] = $aicfab_field;
	}
}
check_set( count( $GLOBALS['aicfab_fields'] ) > 30 && array() === $aicfab_misplaced, 'Every settings field is on the tab of its section; misplaced: ' . implode( ', ', $aicfab_misplaced ) );
check_set( 'aicfab_tab_publishing' === $GLOBALS['aicfab_fields']['youtube_client_id'][0] && 'aicfab_tab_publishing' === $GLOBALS['aicfab_fields']['wechat_enabled'][0] && 'aicfab_tab_publishing' === $GLOBALS['aicfab_fields']['wxgame_enabled'][0] && 'aicfab_tab_publishing' === $GLOBALS['aicfab_fields']['wechat_drafts_enabled'][0] && 'aicfab_tab_agents' === $GLOBALS['aicfab_fields']['abilities_tools'][0] && 'aicfab_tab_governance' === $GLOBALS['aicfab_fields']['allow_public_chat'][0] && 'aicfab_tab_governance' === $GLOBALS['aicfab_fields']['log_conversations'][0], 'Channels hold YouTube and every WeChat setting; agents and safety have tabs of their own.' );

// --- Publishing ------------------------------------------------------------------

$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask' ) );
check_set( empty( $saved['distribution_enabled'] ) && empty( $saved['distribution_links'] ) && empty( $saved['bilibili_embeds'] ) && '' === AI_Chat_Bedrock_YouTube::client( $saved )['id'], 'Publishing features are off by default.' );
$saved = save_tab( $admin, array( 'distribution_enabled', 'bilibili_embeds', 'youtube_client_id' ), array( 'distribution_enabled' => '1', 'distribution_links' => '1', 'bilibili_embeds' => '1', 'youtube_client_id' => '123456-abcdef.apps.googleusercontent.com', 'youtube_client_secret' => 'GOCSPX-abcdefghijklmnop', 'youtube_daily_uploads' => '3' ) );
check_set( true === $saved['distribution_enabled'] && true === $saved['distribution_links'] && true === $saved['bilibili_embeds'] && 3 === $saved['youtube_daily_uploads'], 'The record, its links, Bilibili embeds and the daily uploads are saved.' );
check_set( 'GOCSPX-abcdefghijklmnop' === AI_Chat_Bedrock_YouTube::client( $saved )['secret'] && false === strpos( json_encode( $saved ), 'GOCSPX-abcdefghijklmnop' ), 'The client secret is stored encrypted.' );
$saved = save_tab( $admin, array( 'distribution_enabled', 'bilibili_embeds', 'youtube_client_id' ), array( 'distribution_enabled' => '1', 'youtube_client_id' => '123456-abcdef.apps.googleusercontent.com', 'youtube_client_secret' => '' ) );
check_set( 'GOCSPX-abcdefghijklmnop' === AI_Chat_Bedrock_YouTube::client( $saved )['secret'] && false === $saved['distribution_links'], 'An empty secret field keeps the saved secret; an unticked box turns its option off.' );
$saved = save_tab( $admin, array( 'distribution_enabled', 'bilibili_embeds', 'youtube_client_id' ), array( 'youtube_client_id' => 'not-a-client', 'youtube_client_secret' => 'bad secret!' ) );
check_set( '' === $saved['youtube_client_id'] && isset( $GLOBALS['aicfab_notices']['youtube_client_id'] ) && isset( $GLOBALS['aicfab_notices']['youtube_client_secret'] ) && 'GOCSPX-abcdefghijklmnop' === AI_Chat_Bedrock_YouTube::client( array( 'youtube_client_secret' => $saved['youtube_client_secret'] ) )['secret'], 'A malformed client ID or secret is refused with a notice.' );

// --- WeChat Official Account -----------------------------------------------------

$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask' ) );
check_set( empty( $saved['wechat_enabled'] ) && '' === AI_Chat_Bedrock_WeChat::token( $saved ), 'WeChat is off, with no token, by default.' );
$saved = save_tab( $admin, array( 'wechat_enabled' ), array( 'wechat_enabled' => '1', 'wechat_token' => 'Tok3nForTests', 'wechat_aes_key' => 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG', 'wechat_app_id' => 'wx1234567890abcdef', 'wechat_hourly' => '30', 'wechat_model_id' => 'jp.anthropic.claude-haiku-4-5-20251001-v1:0' ) );
check_set( true === $saved['wechat_enabled'] && 'Tok3nForTests' === AI_Chat_Bedrock_WeChat::token( $saved ) && 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG' === AI_Chat_Bedrock_WeChat::aes_key( $saved ) && 'wx1234567890abcdef' === $saved['wechat_app_id'] && 30 === $saved['wechat_hourly'] && 'jp.anthropic.claude-haiku-4-5-20251001-v1:0' === $saved['wechat_model_id'], 'The switch, token, key, AppID, model and limit are saved together.' );
check_set( false === strpos( json_encode( $saved ), 'Tok3nForTests' ) && false === strpos( json_encode( $saved ), 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG' ), 'The token and key are stored encrypted.' );
$saved = save_tab( $admin, array( 'wechat_enabled' ), array( 'wechat_enabled' => '1', 'wechat_token' => '', 'wechat_aes_key' => '', 'wechat_app_id' => 'wx1234567890abcdef' ) );
check_set( 'Tok3nForTests' === AI_Chat_Bedrock_WeChat::token( $saved ) && '' !== AI_Chat_Bedrock_WeChat::aes_key( $saved ), 'Empty fields keep the saved token and key, which are never shown again.' );
$saved = save_tab( $admin, array( 'chat_color_scheme' ), array( 'chat_color_scheme' => 'light' ) );
check_set( 'Tok3nForTests' === AI_Chat_Bedrock_WeChat::token( $saved ) && true === $saved['wechat_enabled'], 'Another tab keeps them.' );
$saved = save_tab( $admin, array( 'wechat_enabled' ), array( 'wechat_enabled' => '1', 'wechat_token' => 'no spaces allowed', 'wechat_app_id' => 'gh_63e00737b4da' ) );
check_set( 'Tok3nForTests' === AI_Chat_Bedrock_WeChat::token( $saved ) && '' === $saved['wechat_app_id'] && isset( $GLOBALS['aicfab_notices']['wechat_token'] ), 'A malformed token is refused with a notice, and the original ID is not taken for an AppID.' );
$saved = save_tab( $admin, array( 'wechat_enabled' ), array( 'wechat_clear' => '1' ) );
check_set( false === $saved['wechat_enabled'] && '' === AI_Chat_Bedrock_WeChat::token( $saved ) && '' === AI_Chat_Bedrock_WeChat::aes_key( $saved ), 'The token and key can be removed.' );

// --- WeChat menu and drafts ------------------------------------------------------

$saved = save_tab( $admin, array( 'wechat_enabled' ), array( 'wechat_enabled' => '1', 'wechat_token' => 'Tok3nForTests', 'wechat_menu' => "Courses: https://example.test/courses/\nNews" ) );
check_set( "Courses: https://example.test/courses/\nNews" === $saved['wechat_menu'], 'The WeChat menu text is saved, line breaks kept.' );
$saved = save_tab( $admin, array( 'wechat_drafts_enabled' ), array( 'wechat_drafts_enabled' => '1', 'wechat_app_secret' => 'fedcba9876543210fedcba9876543210', 'wechat_drafts_category' => '4', 'wechat_drafts_schedule' => 'weekly', 'wechat_drafts_count' => '12', 'wechat_drafts_author' => 'Lab', 'wechat_drafts_notify' => '1' ) );
check_set( true === $saved['wechat_drafts_enabled'] && 'fedcba9876543210fedcba9876543210' === AI_Chat_Bedrock_WeChat_Drafts::app_secret( $saved ) && 4 === $saved['wechat_drafts_category'] && 'weekly' === $saved['wechat_drafts_schedule'] && 8 === $saved['wechat_drafts_count'] && 'Lab' === $saved['wechat_drafts_author'] && true === $saved['wechat_drafts_notify'], 'Drafts: switch, AppSecret, category, schedule, count (at most 8), author and email are saved.' );
check_set( false === strpos( json_encode( $saved ), 'fedcba9876543210fedcba9876543210' ) && "Courses: https://example.test/courses/\nNews" === $saved['wechat_menu'], 'The AppSecret is stored encrypted, and the Chat tab keeps its menu.' );
$saved = save_tab( $admin, array( 'wechat_drafts_enabled' ), array( 'wechat_drafts_enabled' => '1', 'wechat_app_secret' => 'nope', 'wechat_drafts_schedule' => 'hourly' ) );
check_set( 'fedcba9876543210fedcba9876543210' === AI_Chat_Bedrock_WeChat_Drafts::app_secret( $saved ) && 'off' === $saved['wechat_drafts_schedule'] && isset( $GLOBALS['aicfab_notices']['wechat_app_secret'] ), 'A malformed AppSecret is refused and the saved one kept; an unknown schedule is off.' );
$saved = save_tab( $admin, array( 'wechat_drafts_enabled' ), array( 'wechat_drafts_clear' => '1' ) );
check_set( '' === AI_Chat_Bedrock_WeChat_Drafts::app_secret( $saved ) && false === $saved['wechat_drafts_enabled'], 'The AppSecret can be removed.' );

// --- WeChat mini game ------------------------------------------------------------

$saved = save_tab( $admin, array( 'wxgame_enabled' ), array( 'wxgame_enabled' => '1', 'wxgame_app_id' => 'wx1234567890game00', 'wxgame_token' => 'GameTok3n', 'wxgame_aes_key' => 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG', 'wxgame_app_secret' => '0123456789abcdef0123456789abcdef', 'wxgame_welcome' => "Hi!\nAsk away.", 'wxgame_answers' => "pay, 充值 = WeChat Pay handles it.\nnonsense", 'wxgame_fallback' => 'We read every message.' ) );
check_set( true === $saved['wxgame_enabled'] && 'wx1234567890game00' === $saved['wxgame_app_id'] && 'GameTok3n' === AI_Chat_Bedrock_WeChat_Game::token( $saved ) && '0123456789abcdef0123456789abcdef' === AI_Chat_Bedrock_WeChat_Game::app_secret( $saved ) && "Hi!\nAsk away." === $saved['wxgame_welcome'] && 'pay, 充值 = WeChat Pay handles it.' === $saved['wxgame_answers'] && 'We read every message.' === $saved['wxgame_fallback'], 'The mini game\'s switch, AppID, secrets, welcome, answers and fallback are saved.' );
check_set( false === strpos( json_encode( $saved ), 'GameTok3n' ) && false === strpos( json_encode( $saved ), '0123456789abcdef0123456789abcdef' ), 'The mini game\'s token and AppSecret are stored encrypted.' );
$saved = save_tab( $admin, array( 'wxgame_enabled' ), array( 'wxgame_enabled' => '1', 'wxgame_app_id' => 'wx1234567890game00', 'wxgame_token' => '', 'wxgame_app_secret' => 'too short' ) );
check_set( 'GameTok3n' === AI_Chat_Bedrock_WeChat_Game::token( $saved ) && '0123456789abcdef0123456789abcdef' === AI_Chat_Bedrock_WeChat_Game::app_secret( $saved ) && isset( $GLOBALS['aicfab_notices']['wxgame_app_secret'] ), 'Empty fields keep the saved secrets, and a malformed AppSecret is refused with a notice.' );
$saved = save_tab( $admin, array( 'wechat_enabled' ), array( 'wechat_enabled' => '1', 'wechat_token' => 'Tok3nForTests', 'wechat_app_id' => 'wx1234567890abcdef' ) );
$saved = save_tab( $admin, array( 'wxgame_enabled' ), array( 'wxgame_enabled' => '1', 'wxgame_app_id' => 'wx1234567890abcdef', 'wxgame_token' => 'Tok3nForTests' ) );
check_set( '' === $saved['wxgame_app_id'] && 'Tok3nForTests' === AI_Chat_Bedrock_WeChat_Game::token( $saved ) && isset( $GLOBALS['aicfab_notices']['wxgame_app_id'], $GLOBALS['aicfab_notices']['wxgame_token'] ) && 'Tok3nForTests' === AI_Chat_Bedrock_WeChat::token( $saved ), 'The mini game cannot take the Official Account\'s AppID, even from another tab; the same token is saved, with a reminder.' );
$saved = save_tab( $admin, array( 'wxgame_enabled' ), array( 'wxgame_clear' => '1' ) );
check_set( false === $saved['wxgame_enabled'] && '' === AI_Chat_Bedrock_WeChat_Game::token( $saved ) && ! AI_Chat_Bedrock_WeChat_Game::can_reply( $saved ), 'The mini game\'s secrets can be removed.' );

// --- Pages hidden from search ----------------------------------------------------

$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask' ) );
check_set( empty( $saved['include_noindex'] ), 'Pages hidden from search engines are left out by default.' );
$saved = save_tab( $admin, array( 'include_noindex' ), array( 'include_noindex' => '1' ) );
check_set( true === $saved['include_noindex'], 'They can be included.' );
$saved = save_tab( $admin, array( 'chat_color_scheme' ), array( 'chat_color_scheme' => 'light' ) );
check_set( true === $saved['include_noindex'], 'Another tab keeps the choice.' );
$saved = save_tab( $admin, array( 'include_noindex' ), array() );
check_set( false === $saved['include_noindex'], 'An unticked box leaves them out again.' );

// --- Analytics events ------------------------------------------------------------

$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask' ) );
check_set( empty( $saved['analytics_events'] ), 'Analytics events are off by default.' );
$saved = save_tab( $admin, array( 'analytics_events' ), array( 'analytics_events' => '1' ) );
check_set( true === $saved['analytics_events'], 'Analytics events can be turned on.' );
$saved = save_tab( $admin, array( 'chat_color_scheme' ), array( 'chat_color_scheme' => 'light' ) );
check_set( true === $saved['analytics_events'], 'Another tab keeps them on.' );
$saved = save_tab( $admin, array( 'analytics_events' ), array() );
check_set( false === $saved['analytics_events'], 'An unticked box turns them off.' );

// --- Reading aloud -------------------------------------------------------------

$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask' ) );
check_set( ! AI_Chat_Bedrock_Speech::replies_enabled( $saved ) && ! AI_Chat_Bedrock_Speech::posts_enabled( $saved ) && 'neural' === AI_Chat_Bedrock_Speech::engine( $saved ) && AI_Chat_Bedrock_Speech::DEFAULT_DAILY_CHARACTERS === AI_Chat_Bedrock_Speech::daily_characters( $saved ), 'Reading aloud is off by default, with the neural engine and the default daily limit.' );
$saved = save_tab( $admin, array( 'speech_replies' ), array( 'speech_replies' => '1', 'speech_posts' => '1', 'speech_engine' => 'generative', 'speech_daily_chars' => '25000' ) );
check_set( true === $saved['speech_replies'] && true === $saved['speech_posts'] && 'generative' === $saved['speech_engine'] && 25000 === $saved['speech_daily_chars'], 'Both switches, the engine and the daily limit are saved together.' );
$saved = save_tab( $admin, array( 'chat_color_scheme' ), array( 'chat_color_scheme' => 'light' ) );
check_set( true === $saved['speech_replies'] && true === $saved['speech_posts'] && 'generative' === $saved['speech_engine'] && 25000 === $saved['speech_daily_chars'], 'Another tab keeps the reading-aloud settings.' );
$saved = save_tab( $admin, array( 'speech_replies' ), array( 'speech_posts' => '1', 'speech_engine' => 'standard', 'speech_daily_chars' => '0' ) );
check_set( false === $saved['speech_replies'] && true === $saved['speech_posts'] && 'neural' === $saved['speech_engine'] && 0 === $saved['speech_daily_chars'], 'An unticked switch is off, an unknown engine is neural, and 0 removes the limit.' );
$saved = save_tab( $admin, array( 'speech_replies' ), array( 'speech_posts' => '1', 'speech_posts_signed_in' => '1' ) );
check_set( true === $saved['speech_posts_signed_in'], 'Listening only when signed in is saved.' );
$saved = save_tab( $admin, array( 'chat_color_scheme' ), array( 'chat_color_scheme' => 'light' ) );
check_set( true === $saved['speech_posts_signed_in'], 'Another tab keeps it.' );
$saved = save_tab( $admin, array( 'speech_replies' ), array( 'speech_posts' => '1' ) );
check_set( false === $saved['speech_posts_signed_in'], 'An unticked box opens posts to everyone again.' );
$saved = save_tab( $admin, array( 'speech_replies' ), array( 'speech_daily_chars' => '99999999999' ) );
check_set( false === $saved['speech_posts'] && AI_Chat_Bedrock_Speech::MAX_DAILY_CHARACTERS === $saved['speech_daily_chars'], 'The daily limit is capped.' );
$saved = save_tab( $admin, array( 'speech_replies' ), array( 'speech_daily_chars' => '' ) );
check_set( AI_Chat_Bedrock_Speech::DEFAULT_DAILY_CHARACTERS === $saved['speech_daily_chars'], 'An emptied daily limit is saved as the default, not as no limit.' );

$saved = save_tab( $admin, array( 'chat_color_scheme' ), array( 'chat_color_scheme' => 'auto' ) );
check_set( 'auto' === $saved['chat_color_scheme'], 'The color scheme is saved.' );

$saved = save_tab( $admin, array( 'model_id', 'image_model_id' ), array( 'model_id' => 'amazon.nova-lite-v1:0', 'image_model_id' => 'stability.stable-image-core-v1:1' ) );
check_set( 'stability.stable-image-core-v1:1' === $saved['image_model_id'], 'The image model is saved with the Model tab.' );
$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask' ) );
check_set( 'stability.stable-image-core-v1:1' === $saved['image_model_id'], 'Another tab keeps the image model.' );
$saved = save_tab( $admin, array( 'model_id', 'image_model_id' ), array( 'model_id' => 'amazon.nova-lite-v1:0', 'image_model_id' => 'stability.not-a-model' ) );
check_set( '' === $saved['image_model_id'] && isset( $GLOBALS['aicfab_notices']['image_model_id'] ), 'An unsupported image model leaves image generation off, with a notice.' );
$saved = save_tab( $admin, array( 'model_id', 'image_model_id' ), array( 'model_id' => 'amazon.nova-lite-v1:0' ) );
check_set( '' === $saved['image_model_id'], 'Choosing no image model turns image generation off.' );

$GLOBALS['aicfab_deleted_transients'] = array();
$saved = save_tab( $admin, array( 'knowledge_base_id', 'rerank_model_id' ), array( 'knowledge_base_id' => 'KB123', 'rerank_model_id' => 'cohere.rerank-v3-5:0' ) );
check_set( 'cohere.rerank-v3-5:0' === $saved['rerank_model_id'] && ! isset( $GLOBALS['aicfab_notices']['rerank_model_id'] ), 'A supported reranking model is saved.' );
check_set( in_array( AI_Chat_Bedrock_Retrieval::RERANK_PAUSED, $GLOBALS['aicfab_deleted_transients'], true ), 'Saving lifts a reranking pause.' );
$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask' ) );
check_set( 'cohere.rerank-v3-5:0' === $saved['rerank_model_id'], 'Another tab keeps the reranking model.' );
$saved = save_tab( $admin, array( 'knowledge_base_id', 'rerank_model_id' ), array( 'knowledge_base_id' => 'KB123', 'rerank_model_id' => 'cohere.rerank-v9:0' ) );
check_set( '' === $saved['rerank_model_id'] && isset( $GLOBALS['aicfab_notices']['rerank_model_id'] ), 'An unsupported reranking model leaves reranking off, with a notice.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: settings save checks passed\n";
