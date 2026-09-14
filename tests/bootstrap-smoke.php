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
/**
 * Record a failure when a condition does not hold.
 *
 * @param bool   $condition Condition that must hold.
 * @param string $message   What was expected.
 */
function expect_true( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

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

// --- The chat block can actually be inserted ---------------------------------

// The editor script was registered with no dependencies, so it ran before window.wp existed,
// threw, and the block was never registered in the editor: it could not be inserted at all.
// register_block_type() reads dependencies from editor.asset.php, so that file has to exist
// and has to list what the script uses.
$aicfab_asset_path = __DIR__ . '/../blocks/chat/editor.asset.php';
expect_true( file_exists( $aicfab_asset_path ), 'the block editor script declares its dependencies in editor.asset.php' );

$aicfab_asset    = file_exists( $aicfab_asset_path ) ? include $aicfab_asset_path : array();
$aicfab_declared = ( is_array( $aicfab_asset ) && isset( $aicfab_asset['dependencies'] ) ) ? (array) $aicfab_asset['dependencies'] : array();
expect_true( ! empty( $aicfab_declared ), 'the asset file returns a dependency list' );

$aicfab_editor = file_get_contents( __DIR__ . '/../blocks/chat/editor.js' );

// Tie the declared handles to the globals the script is invoked with, so adding an argument
// without declaring its package is caught.
$aicfab_globals = array(
	'wp-blocks'             => 'window.wp.blocks',
	'wp-element'            => 'window.wp.element',
	'wp-block-editor'       => 'window.wp.blockEditor',
	'wp-components'         => 'window.wp.components',
	'wp-i18n'               => 'window.wp.i18n',
	'wp-server-side-render' => 'window.wp.serverSideRender',
);
$aicfab_undeclared = array();
foreach ( $aicfab_globals as $aicfab_handle => $aicfab_global ) {
	if ( false !== strpos( $aicfab_editor, $aicfab_global ) && ! in_array( $aicfab_handle, $aicfab_declared, true ) ) {
		$aicfab_undeclared[] = $aicfab_handle;
	}
}
expect_true(
	array() === $aicfab_undeclared,
	'every wp global the script uses is declared as a dependency, missing: ' . implode( ', ', $aicfab_undeclared )
);

// The block is rendered on the server, so the editor must not be the source of saved markup.
expect_true(
	false === strpos( $aicfab_editor, 'save:' ) || false !== strpos( $aicfab_editor, 'ServerSideRender' ),
	'the block previews through the server rather than saving markup from the editor'
);

// --- Heading levels and editor APIs ------------------------------------------

// The chat title was an h3 directly under the page h1, which skips a level on the front end
// and on the Test Chat screen. It is an h2, styled by class so the level can move again
// without touching CSS.
$aicfab_chat_view = file_get_contents( __DIR__ . '/../public/partials/ai-chat-bedrock-public-display.php' );
expect_true(
	false !== strpos( $aicfab_chat_view, '<h2 class="ai-chat-bedrock-title">' ),
	'the chat title is an h2 so it does not skip a level under the page title'
);
expect_true(
	false === strpos( $aicfab_chat_view, '<h3>' ),
	'no bare h3 is left in the chat markup'
);
$aicfab_chat_css = file_get_contents( __DIR__ . '/../public/css/ai-chat-bedrock-public.css' );
expect_true(
	false !== strpos( $aicfab_chat_css, '.ai-chat-bedrock-title' ),
	'the title is styled by class rather than by tag name'
);

// wp.editPost.PluginSidebar was deprecated in WordPress 6.6. Using it still works but will
// stop, and the sidebar would then vanish without a word.
$aicfab_editor_js = file_get_contents( __DIR__ . '/../admin/js/ai-chat-bedrock-editor.js' );
expect_true(
	false !== strpos( $aicfab_editor_js, 'var host = ( editor && editor.PluginSidebar ) ? editor : editPost;' ),
	'the assistant prefers wp.editor and falls back to wp.editPost'
);
expect_true(
	false === strpos( $aicfab_editor_js, 'editPost.PluginSidebarMoreMenuItem' ),
	'the deprecated menu item reference is gone'
);
$aicfab_assistant_php = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-editor-assistant.php' );
expect_true(
	false !== strpos( $aicfab_assistant_php, "'wp-editor'" ),
	'wp-editor is declared as a dependency, since the script now reads window.wp.editor'
);

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: plugin bootstrap and hook registration passed\n";
