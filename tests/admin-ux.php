<?php
/**
 * Standalone tests: the admin screens follow the WordPress layout conventions they claim to.
 *
 * The settings view is rendered for real and read as a document. Import and export is a tab
 * of its own, so it no longer repeats under every group of options, and an import comes back
 * to it. The remaining checks read the views where rendering would mean stubbing half of
 * WordPress.
 *
 * Run: php tests/admin-ux.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class AicfabUxRedirect extends RuntimeException {}
function __( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $text ) { return esc_html( $text ); }
function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function number_format_i18n( $number ) { return number_format( $number ); }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url = null ) {
	$args = is_array( $key ) ? $key : array( $key => $value );
	$url  = is_array( $key ) ? $value : $url;
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}
function get_admin_page_title() { return 'Settings'; }
function settings_errors( $setting ) {}
function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '">'; }
function do_settings_sections( $page ) { echo '<table class="form-table" data-page="' . esc_attr( $page ) . '"></table>'; }
function submit_button() { echo '<p class="submit"><input type="submit" class="button button-primary" value="Save Changes"></p>'; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( 'nonce-' . $action ) . '">'; }
function check_admin_referer( $action ) { return 1; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_safe_redirect( $url ) { throw new AicfabUxRedirect( $url ); }
function add_action() {}
function add_filter() {}

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
class AI_Chat_Bedrock_Security {}
class AI_Chat_Bedrock_Diagnostics {}
class AI_Chat_Bedrock_Embeddings {
	const CRON_HOOK = 'test_embeddings_cron';
	public static function enabled() { return false; }
}
class AI_Chat_Bedrock_Transfer {
	public static $result;
	public static function import( $json ) { return self::$result; }
}
require dirname( __DIR__ ) . '/admin/class-ai-chat-bedrock-admin.php';

$failures = array();
function check_ux( $condition, $label ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $label;
	}
}

function render_settings( $tab ) {
	$_GET = null === $tab ? array() : array( 'tab' => $tab );
	ob_start();
	require dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-settings.php';
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$document->loadHTML( '<?xml encoding="utf-8"?>' . ob_get_clean() );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	return new DOMXPath( $document );
}
$import_form  = '//form[input[@name="action" and @value="ai_chat_bedrock_import_settings"]]';
$export_form  = '//form[input[@name="action" and @value="ai_chat_bedrock_export_settings"]]';
$options_form = '//form[@action="options.php"]';

// Every group of options: its own form, and nothing about moving the configuration.
foreach ( array_keys( AI_Chat_Bedrock_Admin::tabs() ) as $tab ) {
	$xpath = render_settings( $tab );
	check_ux( 1 === $xpath->query( $options_form )->length, "the {$tab} tab has its settings form" );
	check_ux( 0 === $xpath->query( $import_form )->length && 0 === $xpath->query( $export_form )->length, "the {$tab} tab does not repeat import and export" );
}

// Import and export: both forms, labelled controls, and no options form to save by mistake.
$xpath = render_settings( 'transfer' );
check_ux( 0 === $xpath->query( $options_form )->length, 'the import and export tab has no settings form' );
check_ux( 1 === $xpath->query( $export_form )->length, 'the export form is on its tab' );
check_ux( 1 === $xpath->query( $import_form )->length, 'the import form is on its tab' );
check_ux( 'multipart/form-data' === $xpath->query( $import_form )->item( 0 )->getAttribute( 'enctype' ), 'the import form can upload a file' );
foreach ( array( 'aicfab_import_file', 'aicfab_import_json' ) as $id ) {
	check_ux( 1 === $xpath->query( '//label[@for="' . $id . '"]' )->length, "{$id} has a label" );
}

// The navigation: a labelled nav element, one link per tab, the current one marked for everyone.
$links = $xpath->query( '//nav[contains(@class,"nav-tab-wrapper") and @aria-label]/a' );
check_ux( count( AI_Chat_Bedrock_Admin::tabs() ) + 1 === $links->length, 'the navigation links every tab, import and export included' );
$current = $xpath->query( '//nav/a[@aria-current="page"]' );
check_ux( 1 === $current->length && false !== strpos( $current->item( 0 )->getAttribute( 'class' ), 'nav-tab-active' ) && false !== strpos( $current->item( 0 )->getAttribute( 'href' ), 'tab=transfer' ), 'the active tab is marked visually and with aria-current' );

// An unknown tab falls back to the first one rather than rendering an empty page.
$xpath = render_settings( 'nonsense' );
check_ux( 1 === $xpath->query( $options_form )->length && 0 === $xpath->query( $import_form )->length, 'an unknown tab shows the first settings tab' );
$xpath = render_settings( null );
check_ux( 1 === $xpath->query( '//nav/a[@aria-current="page" and contains(@href,"tab=aws")]' )->length, 'no tab means the AWS tab' );

// An import, successful or not, returns to the tab it was started from.
$admin  = ( new ReflectionClass( 'AI_Chat_Bedrock_Admin' ) )->newInstanceWithoutConstructor();
$_FILES = array();
foreach ( array( array( 'applied' => array( 'ai_chat_bedrock_settings' ) ), new WP_Error( 'bad', 'Not a configuration file.' ) ) as $result ) {
	AI_Chat_Bedrock_Transfer::$result = $result;
	$_POST                            = array( 'aicfab_import_json' => '{}' );
	try {
		$admin->handle_import_settings();
		check_ux( false, 'the import redirects' );
	} catch ( AicfabUxRedirect $redirect ) {
		$query = array();
		parse_str( (string) parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $query );
		check_ux( isset( $query['tab'] ) && 'transfer' === $query['tab'], 'the import returns to the import and export tab' );
		check_ux( isset( $query['aicfab-transfer'] ) && ( is_wp_error( $result ) ? 'error' : 'imported' ) === $query['aicfab-transfer'], 'the import reports its outcome' );
	}
}

// Options with a second control put it on its own line inside a fieldset, the way core's
// Discussion settings do, rather than running both into one sentence.
$admin_source = file_get_contents( dirname( __DIR__ ) . '/admin/class-ai-chat-bedrock-admin.php' );
foreach ( array( 'log_conversations_render', 'popup_site_wide_render' ) as $method ) {
	$start = strpos( $admin_source, "public function {$method}()" );
	$body  = substr( $admin_source, $start, strpos( $admin_source, "\n\t}", $start ) - $start );
	check_ux( false !== strpos( $body, '<fieldset><legend class="screen-reader-text">' ) && false !== strpos( $body, '</label><br>' ), "{$method} groups its controls one per line" );
	check_ux( false === strpos( $body, 'style=' ), "{$method} has no inline styles" );
}
check_ux( false !== strpos( $admin_source, "\$this->text_input( 'welcome_message', 'Hello! How can I help you today?', 500, 'large-text' );" ), 'the welcome message field is wide enough to read' );

// Profiles: limits are labelled one per row, empty means inherit, and editing can be abandoned.
$profiles = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-profiles.php' );
check_ux( false === strpos( $profiles, 'style=' ), 'the profiles view has no inline styles' );
check_ux( false !== strpos( $profiles, '<label for="aicfab_profile_<?php echo esc_attr( $key ); ?>">' ), 'every profile limit has its own label' );
check_ux( false !== strpos( $profiles, "esc_html_e( 'Cancel'" ), 'editing a profile can be cancelled' );

// Diagnostics: the status reads as a pill in the server-rendered table and after a rerun.
$diagnostics = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-diagnostics.php' );
$admin_js    = file_get_contents( dirname( __DIR__ ) . '/admin/js/ai-chat-bedrock-admin.js' );
check_ux( false !== strpos( $diagnostics, '<span class="aicfab-pill' ), 'diagnostics render their status as a pill' );
check_ux( false !== strpos( $admin_js, "'class': 'aicfab-pill ' + statusTone(status)" ), 'rerun diagnostics render the same pill' );
check_ux( false === strpos( $diagnostics, 'style=' ), 'the diagnostics view has no inline styles' );

// MCP: the section links are navigation, not an ARIA tab widget they do not implement, and the
// two settings that save on change say so and have a visible label next to them.
$mcp    = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-mcp-tab.php' );
$mcp_js = file_get_contents( dirname( __DIR__ ) . '/admin/js/ai-chat-bedrock-mcp.js' );
check_ux( false === strpos( $mcp, 'role="tablist"' ) && false !== strpos( $mcp, '<nav class="nav-tab-wrapper aicfab-mcp-nav" aria-label=' ), 'the MCP sections are a labelled nav' );
foreach ( array( 'ai_chat_bedrock_enable_mcp', 'ai_chat_bedrock_mcp_public_access' ) as $id ) {
	check_ux( 1 === preg_match( '/<label><input type="checkbox" id="' . $id . '" value="1" aria-describedby="aicfab-mcp-instant"/', $mcp ), "{$id} has an inline label and the saves-immediately note" );
}
check_ux( false !== strpos( $mcp, 'id="aicfab-mcp-instant"' ), 'the saves-immediately note exists' );
check_ux( false !== strpos( $mcp_js, "addEventListener( 'hashchange'" ) && false !== strpos( $mcp_js, 'history.replaceState' ), 'MCP sections follow and update the address' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "OK: admin screens follow the WordPress layout conventions\n";
