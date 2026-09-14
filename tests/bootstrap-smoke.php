<?php
/** Standalone plugin bootstrap smoke test with minimal WordPress stubs. */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WPINC', 'wp-includes' );

$GLOBALS['aicfab_actions'] = array();
$GLOBALS['aicfab_filters'] = array();
$GLOBALS['aicfab_shortcodes'] = array();
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/plugin/' . basename( dirname( $file ) ) . '/'; }
function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['aicfab_actions'][ $hook ][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['aicfab_filters'][ $hook ][] = $callback; }
function add_shortcode( $tag, $callback ) { $GLOBALS['aicfab_shortcodes'][ $tag ][] = $callback; }
function get_option( $name, $default = false ) { return $default; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function load_plugin_textdomain() { return true; }
function absint( $value ) { return abs( (int) $value ); }

require dirname( __DIR__ ) . '/ai-chat-for-amazon-bedrock.php';

$failures = array();
function expect_count( $collection, $key, $expected ) {
	global $failures;
	$count = isset( $collection[ $key ] ) ? count( $collection[ $key ] ) : 0;
	if ( $expected !== $count ) { $failures[] = "$key expected $expected registration(s), found $count"; }
}
function expect_registered( $collection, $key, $minimum = 1 ) {
	global $failures;
	$count = isset( $collection[ $key ] ) ? count( $collection[ $key ] ) : 0;
	if ( $count < $minimum ) { $failures[] = "$key expected at least $minimum registration(s), found $count"; }
}
function expect_no_duplicate_handlers( $collection, $key ) {
	global $failures;
	$seen = array();
	foreach ( isset( $collection[ $key ] ) ? $collection[ $key ] : array() as $callback ) {
		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$signature = ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . $callback[1];
		} else {
			$signature = is_string( $callback ) ? $callback : 'closure';
		}
		if ( isset( $seen[ $signature ] ) ) { $failures[] = "$key registers $signature more than once"; }
		$seen[ $signature ] = true;
	}
}
expect_count( $GLOBALS['aicfab_actions'], 'wp_ajax_ai_chat_bedrock_message', 1 );
expect_count( $GLOBALS['aicfab_actions'], 'wp_ajax_nopriv_ai_chat_bedrock_message', 1 );
expect_count( $GLOBALS['aicfab_actions'], 'wp_ajax_ai_chat_bedrock_save_option', 1 );
expect_count( $GLOBALS['aicfab_actions'], 'wp_ajax_ai_chat_bedrock_register_mcp_server', 1 );
expect_count( $GLOBALS['aicfab_actions'], 'wp_ajax_ai_chat_bedrock_refresh_models', 1 );
expect_count( $GLOBALS['aicfab_actions'], 'wp_ajax_ai_chat_bedrock_run_diagnostics', 1 );
expect_count( $GLOBALS['aicfab_actions'], 'admin_post_ai_chat_bedrock_save_tool_policy', 1 );
expect_registered( $GLOBALS['aicfab_actions'], 'rest_api_init', 2 );
expect_no_duplicate_handlers( $GLOBALS['aicfab_actions'], 'rest_api_init' );
expect_registered( $GLOBALS['aicfab_actions'], 'init', 2 );
expect_registered( $GLOBALS['aicfab_filters'], 'site_status_tests', 1 );
expect_registered( $GLOBALS['aicfab_filters'], 'ai_chat_bedrock_message_payload', 2 );
expect_registered( $GLOBALS['aicfab_filters'], 'ai_chat_bedrock_process_response', 2 );
expect_no_duplicate_handlers( $GLOBALS['aicfab_filters'], 'ai_chat_bedrock_message_payload' );
expect_no_duplicate_handlers( $GLOBALS['aicfab_filters'], 'ai_chat_bedrock_process_response' );
expect_no_duplicate_handlers( $GLOBALS['aicfab_actions'], 'init' );
expect_count( $GLOBALS['aicfab_actions'], 'enqueue_block_editor_assets', 1 );
expect_count( $GLOBALS['aicfab_actions'], 'admin_post_ai_chat_bedrock_clear_conversations', 1 );
expect_count( $GLOBALS['aicfab_shortcodes'], 'ai_chat_bedrock', 1 );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: plugin bootstrap and hook registration passed\n";
