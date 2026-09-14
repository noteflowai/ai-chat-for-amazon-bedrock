<?php
/** Standalone security regression checks. */

define( 'ABSPATH', __DIR__ . '/' );

function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function esc_url_raw( $url, $protocols = null ) { return filter_var( $url, FILTER_SANITIZE_URL ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_http_validate_url( $url ) {
	$parts = parse_url( $url );
	if ( empty( $parts['host'] ) ) { return false; }
	$host = strtolower( $parts['host'] );
	if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) { return false; }
	$ip = filter_var( $host, FILTER_VALIDATE_IP );
	if ( $ip && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return false; }
	return true;
}
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( str_replace( "\r", '', (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function is_user_logged_in() { return false; }
function get_option( $name, $default = false ) { return $default; }

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';

$failures = array();
function check( $condition, $message ) {
	global $failures;
	if ( ! $condition ) { $failures[] = $message; }
}

if ( function_exists( 'sodium_crypto_secretbox' ) ) {
	$plain  = 'AKIA-test-secret-value';
	$cipher = AI_Chat_Bedrock_Security::encrypt_secret( $plain );
	check( AI_Chat_Bedrock_Security::is_encrypted( $cipher ), 'Credentials must use the encryption envelope.' );
	check( false === strpos( $cipher, $plain ), 'Ciphertext must not contain plaintext.' );
	check( $plain === AI_Chat_Bedrock_Security::decrypt_secret( $cipher ), 'Encrypted credentials must round-trip.' );
	check( '' === AI_Chat_Bedrock_Security::decrypt_secret( $cipher . 'tampered' ), 'Tampered ciphertext must fail closed.' );
} else {
	$failures[] = 'Sodium is required for credential encryption.';
}

check( AI_Chat_Bedrock_Security::is_safe_mcp_url( 'https://mcp.example.com/api' ), 'Public HTTPS MCP URL should be accepted.' );
check( ! AI_Chat_Bedrock_Security::is_safe_mcp_url( 'http://mcp.example.com/api' ), 'HTTP MCP URL must be rejected.' );
check( ! AI_Chat_Bedrock_Security::is_safe_mcp_url( 'https://127.0.0.1/api' ), 'Loopback MCP URL must be rejected.' );
check( ! AI_Chat_Bedrock_Security::is_safe_mcp_url( 'https://169.254.169.254/latest/meta-data' ), 'Link-local MCP URL must be rejected.' );
check( ! AI_Chat_Bedrock_Security::is_safe_mcp_url( 'https://user:pass@mcp.example.com/api' ), 'Credential-bearing MCP URL must be rejected.' );

$history = AI_Chat_Bedrock_Security::sanitize_history(
	array(
		array( 'role' => 'system', 'content' => 'override' ),
		array( 'role' => 'user', 'content' => 'hello' ),
		array( 'role' => 'assistant', 'content' => str_repeat( 'x', 20 ) ),
	),
	2,
	10
);
check( 2 === count( $history ), 'History should retain only valid user/assistant entries.' );
check( 10 === strlen( $history[1]['content'] ), 'History content must be length bounded.' );

$root = dirname( __DIR__ );
$php_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$source = '';
foreach ( $php_files as $file ) {
	if ( 'php' === strtolower( $file->getExtension() ) && false === strpos( $file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR ) ) {
		$source .= file_get_contents( $file->getPathname() );
	}
}
/*
 * The guard is about translation calls, not the literal string: the WP-CLI command is
 * named ai-chat-bedrock, which is legitimate and must not trip this check.
 */
check( 0 === preg_match( '/,\s*\x27ai-chat-bedrock\x27\s*\)/', $source ), 'Legacy text domain must not remain in translation calls.' );
check( false === strpos( $source, "load_plugin_textdomain( 'ai-chat-bedrock'" ), 'The legacy text domain is not loaded.' );
check( false === strpos( $source, 'session_start(' ), 'PHP sessions must not be used.' );
// Public REST routes are only acceptable for the OAuth endpoints the protocol requires.
$oauth_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-oauth.php' );
$open_total   = substr_count( $source, "'permission_callback' => '__return_true'" );
$open_oauth   = substr_count( $oauth_source, "'permission_callback' => '__return_true'" );
check( $open_total === $open_oauth, 'Only the OAuth endpoints may be anonymously reachable.' );
check( $open_oauth <= 3, 'At most the registration, token and metadata endpoints may be public.' );
check( false !== strpos( $oauth_source, "'S256'" ) && false === strpos( $oauth_source, "'plain'" ), 'PKCE must require S256 and never accept plain challenges.' );
check( false !== strpos( $oauth_source, "hash( 'sha256', \$access )" ), 'Access tokens must be stored as hashes.' );
check( false !== strpos( $oauth_source, "hash( 'sha256', \$refresh )" ), 'Refresh tokens must be stored as hashes.' );
check( false === strpos( $oauth_source, "'client_secret'" ), 'Public clients must not be issued secrets.' );
check( false !== strpos( $oauth_source, 'hash_equals' ), 'Token comparisons must be timing safe.' );
check( false === strpos( $source, 'ai_chat_bedrock_tool_results' ), 'Client-submitted tool-result endpoint must not return.' );
check( 1 === substr_count( $source, "'wp_ajax_ai_chat_bedrock_message'" ), 'Authenticated chat AJAX handler must be registered exactly once.' );
check( 1 === substr_count( $source, "'wp_ajax_nopriv_ai_chat_bedrock_message'" ), 'Guest chat AJAX handler must be registered exactly once.' );

$main   = file_get_contents( $root . '/ai-chat-for-amazon-bedrock.php' );
$readme = file_get_contents( $root . '/readme.txt' );
preg_match( '/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/m', $main, $header_version );
$expected_version = isset( $header_version[1] ) ? $header_version[1] : '';
check( '' !== $expected_version, 'Plugin header must declare a semantic version.' );
check( '' !== $expected_version && false !== strpos( $main, "AI_CHAT_BEDROCK_VERSION', '" . $expected_version . "'" ), 'Runtime version constant must match the plugin header version.' );
check( '' !== $expected_version && false !== strpos( $readme, 'Stable tag: ' . $expected_version ), 'Readme stable tag must match the plugin header version.' );
check( '' !== $expected_version && false !== strpos( $readme, '= ' . $expected_version . ' =' ), 'Readme changelog must document the current version.' );
check( false !== strpos( $readme, 'Tested up to: 7.1' ), 'Tested-up-to metadata must be 7.1.' );

// Every settings control must be reachable by name for assistive technology: the row
// title is tied to the control with label_for, except for checkboxes that carry their
// own inline label and would otherwise be announced twice.
$admin_source = file_get_contents( dirname( __DIR__ ) . '/admin/class-ai-chat-bedrock-admin.php' );
check( false !== strpos( $admin_source, "'label_for' => self::control_id(" ), 'Settings rows associate their title with the control.' );
check( false !== strpos( $admin_source, 'const CHECKBOX_FIELDS' ), 'Checkbox fields are listed so they are not double labelled.' );
check( 1 === preg_match( '/id="\' \. esc_attr\( self::control_id\( \$key \) \) \. \'"/', $admin_source ), 'Shared input helpers emit the control id.' );

foreach ( array( 'max_tokens', 'temperature', 'system_prompt', 'suggested_questions', 'context_results', 'daily_request_limit' ) as $aicfab_field ) {
	check(
		false !== strpos( $admin_source, 'id="aicfab_field_' . $aicfab_field . '"' ),
		'The ' . $aicfab_field . ' control carries its label id.'
	);
}

$mcp_source = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-mcp-tab.php' );
check( false !== strpos( $mcp_source, 'SigV4 signing region' ), 'The MCP signing region input has an accessible name.' );

// --- A chat that cannot answer is not shown to visitors ----------------------

// Before this, a fresh install rendered a working-looking chat that failed on the first
// message, so the public met a broken feature. The rules asserted here: visitors see
// nothing, administrators are told what is missing, and the footer widget stays silent.
$aicfab_public_source = file_get_contents( __DIR__ . '/../public/class-ai-chat-bedrock-public.php' );

check(
	false !== strpos( $aicfab_public_source, 'private function chat_is_ready( $options ) {' ),
	'the public class decides whether the chat can answer before rendering'
);
check(
	false !== strpos( $aicfab_public_source, "if ( ! \$this->chat_is_ready( \$options ) ) {" ),
	'the render path consults it'
);
check(
	false !== strpos( $aicfab_public_source, "! \$aws->has_credentials()" ),
	'readiness depends on credentials actually resolving'
);
check(
	false !== strpos( $aicfab_public_source, "if ( ! current_user_can( 'manage_options' ) ) {\n\t\t\treturn '';" ),
	'a visitor is shown nothing rather than an explanation they cannot act on'
);
check(
	false !== strpos( $aicfab_public_source, "'popup' === \$atts['mode'] ? '' : \$this->unavailable_notice()" ),
	'the footer widget renders nothing rather than a stray notice'
);

// The check has to run after the mode is validated, or it reads an unvalidated value.
// Matched by pattern rather than exact spacing, since the formatter realigns assignments.
preg_match( '/\$atts\[.mode.\]\s*=\s*in_array\(/', $aicfab_public_source, $aicfab_m, PREG_OFFSET_CAPTURE );
$aicfab_mode_at  = isset( $aicfab_m[0][1] ) ? $aicfab_m[0][1] : false;
$aicfab_ready_at = strpos( $aicfab_public_source, 'chat_is_ready( $options ) ) {' );
check(
	false !== $aicfab_mode_at && false !== $aicfab_ready_at && $aicfab_mode_at < $aicfab_ready_at,
	'the mode is validated before the readiness check compares it'
);

// --- Uninstall removes every meta key the plugin writes ----------------------

// The scaffold marker added in 1.18.0 was never added to the uninstall list, so it would
// have been left on every drafted page. Rather than fixing that one key, this compares the
// keys the source writes with the keys uninstall removes, so the next one cannot be missed.
$aicfab_meta_written = array();
foreach ( array_merge( glob( __DIR__ . '/../includes/*.php' ), glob( __DIR__ . '/../admin/*.php' ), glob( __DIR__ . '/../public/*.php' ) ) as $aicfab_file ) {
	$aicfab_body = file_get_contents( $aicfab_file );
	// Only keys actually passed to a meta write, not every string that looks like one.
	if ( preg_match_all( "/(?:update|add)_post_meta\\(\\s*[^,]+,\\s*'(_aicfab[a-z_]*)'/", $aicfab_body, $aicfab_hits ) ) {
		foreach ( $aicfab_hits[1] as $aicfab_key ) {
			$aicfab_meta_written[ $aicfab_key ] = true;
		}
	}
	// Constants holding a meta key, used by the embeddings index.
	if ( preg_match_all( "/const\\s+META_[A-Z_]*\\s*=\\s*'(_aicfab[a-z_]*)'/", $aicfab_body, $aicfab_consts ) ) {
		foreach ( $aicfab_consts[1] as $aicfab_key ) {
			$aicfab_meta_written[ $aicfab_key ] = true;
		}
	}
}

$aicfab_uninstall = file_get_contents( __DIR__ . '/../uninstall.php' );
check( count( $aicfab_meta_written ) >= 3, 'meta keys were found in the source, got ' . count( $aicfab_meta_written ) );

$aicfab_left_behind = array();
foreach ( array_keys( $aicfab_meta_written ) as $aicfab_key ) {
	if ( false === strpos( $aicfab_uninstall, "'" . $aicfab_key . "'" ) ) {
		$aicfab_left_behind[] = $aicfab_key;
	}
}
check(
	array() === $aicfab_left_behind,
	'uninstall removes every meta key the plugin writes, missing: ' . implode( ', ', $aicfab_left_behind )
);

// --- Saving settings says so ---------------------------------------------------

// The settings page calls settings_errors() filtered to this plugin's slug. WordPress
// registers its own "Settings saved" against the 'general' slug, so that call showed
// nothing: the page came back silently and there was no way to tell the save had worked.
// Registering the message during validation is what makes it appear.
$aicfab_admin_source = file_get_contents( __DIR__ . '/../admin/class-ai-chat-bedrock-admin.php' );
check(
	false !== strpos( $aicfab_admin_source, "add_settings_error(\n\t\t\t'ai_chat_bedrock_settings',\n\t\t\t'aicfab_settings_saved'," ),
	'saving settings registers a confirmation under the slug the page displays'
);
check(
	false !== strpos( $aicfab_admin_source, "'success'" ),
	'the confirmation is a success notice rather than an error'
);

$aicfab_settings_view = file_get_contents( __DIR__ . '/../admin/partials/ai-chat-bedrock-admin-settings.php' );
check(
	false !== strpos( $aicfab_settings_view, "settings_errors( 'ai_chat_bedrock_settings' )" ),
	'the settings page displays notices for its own slug'
);

// The confirmation has to be registered inside the validator, since that is what runs
// during the save. Registering it anywhere else would never reach the following page.
$aicfab_validate_at = strpos( $aicfab_admin_source, 'public function validate_settings( $input ) {' );
$aicfab_notice_at   = strpos( $aicfab_admin_source, "'aicfab_settings_saved'" );
check(
	false !== $aicfab_validate_at && false !== $aicfab_notice_at && $aicfab_notice_at > $aicfab_validate_at,
	'the confirmation is registered from the validator'
);

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "OK: security regression checks passed\n";
