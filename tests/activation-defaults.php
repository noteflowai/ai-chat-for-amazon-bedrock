<?php
/**
 * The state a new installation starts in.
 *
 * Nothing loaded AI_Chat_Bedrock_Activator, and these defaults are the only configuration most
 * sites ever see. Two of them are security posture rather than preference, and one of them was
 * wrong: activation wrote enable_streaming => 'off' while the settings field labelled streaming
 * as the default and every reader of an absent value agreed with the label, so a fresh install
 * shipped with it disabled and the box unticked.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride

define( 'ABSPATH', __DIR__ );
define( 'AI_CHAT_BEDROCK_VERSION', 'test' );

$failures = array();
function check_act( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$GLOBALS['aicfab_opts']  = array();
$GLOBALS['aicfab_added'] = array();

function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	// add_option does not overwrite, which is what makes reactivation non-destructive.
	if ( array_key_exists( $name, $GLOBALS['aicfab_opts'] ) ) {
		return false;
	}
	$GLOBALS['aicfab_opts'][ $name ] = $value;
	$GLOBALS['aicfab_added'][]       = $name;
	return true;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_opts'][ $name ] = $value;
	return true;
}
function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_opts'] ) ? $GLOBALS['aicfab_opts'][ $name ] : $default_value;
}
function apply_filters( $hook, $value ) {
	return $value;
}
$GLOBALS['aicfab_schedule'] = array();
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$GLOBALS['aicfab_schedule'][ $hook ] = $timestamp;
	return true;
}
function wp_next_scheduled( $hook ) {
	return isset( $GLOBALS['aicfab_schedule'][ $hook ] ) ? $GLOBALS['aicfab_schedule'][ $hook ] : false;
}
function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['aicfab_schedule'][ $hook ] );
	return true;
}
function __( $text, $domain = null ) {
	return $text;
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-activator.php';

AI_Chat_Bedrock_Activator::activate();
$aicfab_settings = get_option( 'ai_chat_bedrock_settings', array() );

check_act( is_array( $aicfab_settings ) && ! empty( $aicfab_settings ), 'Activation writes the settings option.' );

// --- Posture that must not drift ----------------------------------------------

check_act(
	isset( $aicfab_settings['allow_public_chat'] ) && ! $aicfab_settings['allow_public_chat'],
	'A new installation does not let anonymous visitors chat.'
);
check_act(
	false === get_option( 'ai_chat_bedrock_enable_mcp', false ) || ! get_option( 'ai_chat_bedrock_enable_mcp' ),
	'MCP tools are off until someone turns them on.'
);
check_act(
	! get_option( 'ai_chat_bedrock_mcp_public_access' ),
	'The MCP endpoint is not publicly readable on a new installation.'
);
check_act(
	! isset( $aicfab_settings['aws_access_key'] ) && ! isset( $aicfab_settings['aws_secret_key'] ),
	'Activation stores no credential fields at all.'
);
check_act(
	isset( $aicfab_settings['rate_limit_per_minute'] ) && $aicfab_settings['rate_limit_per_minute'] > 0,
	'A rate limit applies from the first request.'
);
check_act(
	! isset( $aicfab_settings['enable_site_context'] ) || ! $aicfab_settings['enable_site_context'],
	'Site content is not searched until the owner asks for it.'
);
check_act(
	! isset( $aicfab_settings['knowledge_base_id'] ) || '' === $aicfab_settings['knowledge_base_id'],
	'No knowledge base is assumed.'
);
check_act(
	isset( $aicfab_settings['debug_mode'] ) && 'off' === $aicfab_settings['debug_mode'],
	'Debug logging is off by default.'
);

// --- Streaming is the default, which the field already claimed ------------------

check_act(
	isset( $aicfab_settings['enable_streaming'] ) && 'off' !== $aicfab_settings['enable_streaming'],
	"A new installation streams, got " . var_export( isset( $aicfab_settings['enable_streaming'] ) ? $aicfab_settings['enable_streaming'] : '(absent)', true )
);

/*
 * The value is compared as a string everywhere it is read, so the stored value must be one of the
 * two strings the sanitizer produces rather than a boolean that happens to look right.
 */
check_act(
	in_array( $aicfab_settings['enable_streaming'], array( 'on', 'off' ), true ),
	'The stored value is one of the strings the rest of the plugin compares against.'
);

// --- Reactivating does not overwrite a configured site -------------------------

$GLOBALS['aicfab_opts']['ai_chat_bedrock_settings']['model_id']         = 'us.anthropic.claude-haiku-4-5-20251001-v1:0';
$GLOBALS['aicfab_opts']['ai_chat_bedrock_settings']['allow_public_chat'] = true;
AI_Chat_Bedrock_Activator::activate();
$aicfab_after = get_option( 'ai_chat_bedrock_settings' );
check_act(
	'us.anthropic.claude-haiku-4-5-20251001-v1:0' === $aicfab_after['model_id'],
	'Reactivating leaves a configured model alone.'
);
check_act(
	true === $aicfab_after['allow_public_chat'],
	'Reactivating does not silently re-lock a site that opened its chat on purpose.'
);

// --- A model is selected, and it is a real identifier --------------------------

check_act( ! empty( $aicfab_settings['model_id'] ), 'A default model is chosen so the plugin is usable before configuration.' );
check_act(
	(bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $aicfab_settings['model_id'] ),
	'The default model id is structurally valid, got ' . $aicfab_settings['model_id']
);
check_act(
	isset( $aicfab_settings['aws_region'] ) && '' !== $aicfab_settings['aws_region'],
	'A region is chosen rather than left empty.'
);

// --- Deactivation stops the schedule and keeps the settings --------------------

/*
 * Settings surviving deactivation is deliberate. A recurring event surviving it is not: the
 * embeddings index schedules an hourly event, nothing removed it on deactivation, and a site
 * with the plugin switched off was left firing it every hour with no code listening. Verified
 * on a live site before this was changed.
 */
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-deactivator.php';

wp_schedule_event( time() + 60, 'hourly', 'ai_chat_bedrock_index_embeddings' );
check_act( false !== wp_next_scheduled( 'ai_chat_bedrock_index_embeddings' ), 'The fixture has the event scheduled, or the next check proves nothing.' );

$aicfab_before_deactivate = get_option( 'ai_chat_bedrock_settings' );
AI_Chat_Bedrock_Deactivator::deactivate();

check_act(
	false === wp_next_scheduled( 'ai_chat_bedrock_index_embeddings' ),
	'Deactivation leaves no recurring event behind.'
);
check_act(
	$aicfab_before_deactivate === get_option( 'ai_chat_bedrock_settings' ),
	'Deactivation changes no setting.'
);
check_act(
	array() === $GLOBALS['aicfab_schedule'],
	'No plugin event of any kind survives deactivation.'
);

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: activation default checks passed\n";
