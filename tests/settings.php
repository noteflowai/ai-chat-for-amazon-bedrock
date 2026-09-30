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

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-s3-vectors.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-integrations.php';
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
$saved = save_tab( $admin, array( 'chat_title' ), array( 'chat_title' => 'Ask us' ) );
check_set( 'Ask us' === $saved['chat_title'], 'The submitted field is saved.' );
check_set( true === $saved['log_conversations'] && 30 === $saved['log_retention_days'], 'Another tab does not reset the conversation log.' );
check_set( 'support' === $saved['popup_profile'] && '3' === $saved['prompt_version'], 'Another tab does not drop companion values.' );
check_set( 'site-vectors' === $saved['s3_vectors_bucket'], 'Another tab does not drop the vector store.' );
check_set( true === get_option( 'ai_chat_bedrock_log_conversations' ), 'Another tab does not switch the log option off.' );
check_set( true === get_option( 'ai_chat_bedrock_site_abilities' ), 'Another tab does not switch site abilities off.' );

// Unticking the owner field clears it, and its companions follow the submission.
$saved = save_tab( $admin, array( 'log_conversations' ), array( 'log_retention_days' => '7' ) );
check_set( false === $saved['log_conversations'] && 7 === $saved['log_retention_days'], 'An unticked log switch is saved as off with its retention.' );
check_set( false === get_option( 'ai_chat_bedrock_log_conversations' ), 'The log option follows the switch.' );

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

$saved = save_tab( $admin, array( 'chat_color_scheme' ), array( 'chat_color_scheme' => 'auto' ) );
check_set( 'auto' === $saved['chat_color_scheme'], 'The color scheme is saved.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: settings save checks passed\n";
