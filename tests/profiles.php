<?php
/** Standalone tests for named chat profiles. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AI_CHAT_BEDROCK_VERSION', '1.6.0' );

$GLOBALS['aicfab_options']   = array();
$GLOBALS['aicfab_logged_in'] = false;

class WP_Error {
	private $code; private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function get_option( $name, $default = false ) { return isset( $GLOBALS['aicfab_options'][ $name ] ) ? $GLOBALS['aicfab_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['aicfab_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['aicfab_options'][ $name ] ); return true; }
function apply_filters( $hook, $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $message, $domain = null ) { return $message; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_user_logged_in() { return (bool) $GLOBALS['aicfab_logged_in']; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }
function wp_salt() { return 'profiles-salt'; }

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-models.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-chat-request.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-profiles.php';

$failures = array();
function check_profile( $condition, $message ) { global $failures; if ( ! $condition ) { $failures[] = $message; } }

$base = array(
	'model_id'              => 'anthropic.claude-3-haiku-20240307-v1:0',
	'chat_title'            => 'Site chat',
	'welcome_message'       => 'Hello',
	'system_prompt'         => 'Base prompt',
	'max_tokens'            => 1000,
	'temperature'           => 0.7,
	'rate_limit_per_minute' => 5,
	'allow_public_chat'     => false,
	'enable_site_context'   => false,
	'context_results'       => 3,
);
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = $base;

// Key validation rejects anything unusable.
check_profile( 'support' === AI_Chat_Bedrock_Profiles::sanitize_key( 'Support' ), 'Keys must be lowercased.' );
check_profile( '' === AI_Chat_Bedrock_Profiles::sanitize_key( '' ), 'Empty keys must be rejected.' );
check_profile( '' === AI_Chat_Bedrock_Profiles::sanitize_key( str_repeat( 'a', 40 ) ), 'Overlong keys must be rejected.' );
check_profile( 'etcpasswd' === AI_Chat_Bedrock_Profiles::sanitize_key( '../../etc/passwd' ), 'Path characters must be stripped.' );

// Saving normalizes values and unknown keys fall back to site settings.
$saved = AI_Chat_Bedrock_Profiles::save(
	'support',
	array(
		'label'               => '  Support desk  ',
		'model_id'            => 'us.anthropic.claude-haiku-4-5-20251001-v1:0',
		'system_prompt'       => 'You are the support desk.',
		'chat_title'          => 'Support',
		'welcome_message'     => 'How can we help?',
		'max_tokens'          => 9000,
		'temperature'         => 5,
		'rate_limit_per_minute' => 999,
		'context_results'     => 50,
		'allow_public_chat'   => 'on',
		'enable_site_context' => 'on',
	)
);
check_profile( 'support' === $saved, 'Saving must return the stored key.' );

$profile = AI_Chat_Bedrock_Profiles::all()['support'];
check_profile( 'Support desk' === $profile['label'], 'Labels must be trimmed.' );
check_profile( 4000 === $profile['max_tokens'], 'Max tokens must be capped at 4000.' );
check_profile( 1.0 === (float) $profile['temperature'], 'Temperature must be capped at 1.' );
check_profile( 60 === $profile['rate_limit_per_minute'], 'Rate limits must be capped at 60.' );
check_profile( 8 === $profile['context_results'], 'Passage counts must be capped at 8.' );

// Invalid models and flags are dropped rather than trusted.
AI_Chat_Bedrock_Profiles::save( 'broken', array( 'label' => 'Broken', 'model_id' => 'not a model!!', 'allow_public_chat' => 'maybe' ) );
$broken = AI_Chat_Bedrock_Profiles::all()['broken'];
check_profile( '' === $broken['model_id'], 'Invalid model identifiers must be cleared.' );
check_profile( 'inherit' === $broken['allow_public_chat'], 'Unknown flag values must fall back to inherit.' );

// Resolution merges overrides and leaves the base untouched.
$resolved = AI_Chat_Bedrock_Profiles::resolve( 'support', $base );
check_profile( 'us.anthropic.claude-haiku-4-5-20251001-v1:0' === $resolved['model_id'], 'Profile models must override the base model.' );
check_profile( 'You are the support desk.' === $resolved['system_prompt'], 'Profile prompts must override the base prompt.' );
check_profile( true === $resolved['allow_public_chat'], 'Profile guest access must override the base setting.' );
check_profile( true === $resolved['enable_site_context'], 'Profile grounding must override the base setting.' );
check_profile( 'support' === $resolved['profile'], 'Resolved options must record the profile key.' );
check_profile( 'Base prompt' === $base['system_prompt'], 'Resolution must not mutate the base settings.' );

$inherited = AI_Chat_Bedrock_Profiles::resolve( 'broken', $base );
check_profile( $base['model_id'] === $inherited['model_id'], 'Empty profile fields must inherit the base model.' );
check_profile( false === $inherited['allow_public_chat'], 'Inherited guest access must follow the base setting.' );

check_profile( $base === AI_Chat_Bedrock_Profiles::resolve( 'missing', $base ), 'Unknown profiles must return the base settings unchanged.' );
check_profile( $base === AI_Chat_Bedrock_Profiles::resolve( '', $base ), 'An empty key must return the base settings.' );
check_profile( AI_Chat_Bedrock_Profiles::exists( 'support' ) && ! AI_Chat_Bedrock_Profiles::exists( 'missing' ), 'Existence checks must match stored profiles.' );

// Only model parameters may reach the Bedrock client.
$overrides = AI_Chat_Bedrock_Profiles::overrides_for_client( $resolved );
check_profile( array( 'model_id', 'max_tokens', 'temperature' ) === array_keys( $overrides ), 'Client overrides must be limited to model parameters.' );
check_profile( ! isset( $overrides['allow_public_chat'] ), 'Access flags must never be sent to the client.' );
check_profile( array() === AI_Chat_Bedrock_Profiles::overrides_for_client( array() ), 'Empty options must produce no overrides.' );

// Guest access is decided from the resolved options.
check_profile( AI_Chat_Bedrock_Security::can_use_chat( $resolved ), 'A public profile must allow guests.' );
check_profile( ! AI_Chat_Bedrock_Security::can_use_chat( $inherited ), 'A private profile must block guests.' );
check_profile( ! AI_Chat_Bedrock_Security::can_use_chat( AI_Chat_Bedrock_Profiles::resolve( 'missing', $base ) ), 'Unknown profiles must not unlock guest access.' );
$GLOBALS['aicfab_logged_in'] = true;
check_profile( AI_Chat_Bedrock_Security::can_use_chat( $inherited ), 'Signed-in users may always chat.' );
$GLOBALS['aicfab_logged_in'] = false;

// The profile count is capped and deletion works.
for ( $i = 0; $i < 12; $i++ ) {
	AI_Chat_Bedrock_Profiles::save( 'extra' . $i, array( 'label' => 'Extra ' . $i ) );
}
check_profile( AI_Chat_Bedrock_Profiles::MAX_PROFILES >= count( AI_Chat_Bedrock_Profiles::all() ), 'Stored profiles must respect the cap.' );
$overflow = AI_Chat_Bedrock_Profiles::save( 'one-more', array( 'label' => 'One more' ) );
check_profile( is_wp_error( $overflow ) && 'aicfab_too_many_profiles' === $overflow->get_error_code(), 'Exceeding the cap must return an error.' );
check_profile( is_wp_error( AI_Chat_Bedrock_Profiles::save( '!!!', array() ) ), 'Invalid keys must be refused when saving.' );

$existing = array_keys( AI_Chat_Bedrock_Profiles::all() );
check_profile( AI_Chat_Bedrock_Profiles::delete( $existing[0] ), 'Deleting an existing profile must succeed.' );
check_profile( ! AI_Chat_Bedrock_Profiles::delete( 'never-existed' ), 'Deleting a missing profile must fail.' );

$choices = AI_Chat_Bedrock_Profiles::choices();
check_profile( array_key_exists( '', $choices ), 'Choices must include the site default option.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: chat profile checks passed\n";
