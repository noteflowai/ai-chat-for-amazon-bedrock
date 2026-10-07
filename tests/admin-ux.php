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
class AicfabUxJson extends RuntimeException {}
$GLOBALS['aicfab_ux_meta']    = array();
$GLOBALS['aicfab_ux_referer'] = true;
function get_current_user_id() { return 7; }
function update_user_meta( $user, $key, $value ) { $GLOBALS['aicfab_ux_meta'][ $user ][ $key ] = $value; return true; }
function check_ajax_referer( $action, $arg = false, $stop = true ) { return 'ai_chat_bedrock_dismiss_setup_notice' === $action && $GLOBALS['aicfab_ux_referer'] ? 1 : false; }
function wp_send_json_success( $data = null ) { throw new AicfabUxJson( 'success' ); }
function wp_send_json_error( $data = null, $status = null ) { throw new AicfabUxJson( 'error ' . $status ); }

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
foreach ( array( array( 'applied' => array( 'ai_chat_bedrock_settings' ), 'skipped' => array( 'ai_chat_bedrock_future' ) ), new WP_Error( 'bad', 'Not a configuration file.' ) ) as $result ) {
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
		check_ux( is_wp_error( $result ) || ( isset( $query['aicfab-skipped'] ) && '1' === $query['aicfab-skipped'] ), 'the import reports how many groups it skipped' );
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
// two access settings are a form saved with its button, as on every other settings screen.
$mcp    = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-mcp-tab.php' );
$mcp_js = file_get_contents( dirname( __DIR__ ) . '/admin/js/ai-chat-bedrock-mcp.js' );
check_ux( false === strpos( $mcp, 'role="tablist"' ) && false !== strpos( $mcp, '<nav class="nav-tab-wrapper aicfab-mcp-nav" aria-label=' ), 'the MCP sections are a labelled nav' );
foreach ( array( 'ai_chat_bedrock_enable_mcp' => 'enable_mcp', 'ai_chat_bedrock_mcp_public_access' => 'mcp_public_access' ) as $id => $name ) {
	check_ux( 1 === preg_match( '/<label><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"/', $mcp ), "{$id} has an inline label and is sent with the form" );
}
check_ux( false !== strpos( $mcp, "wp_nonce_field( 'ai_chat_bedrock_mcp_access' )" ) && false !== strpos( $mcp, 'value="ai_chat_bedrock_save_mcp_access"' ) && false !== strpos( $mcp, 'submit_button(' ), 'the access settings are a nonce-protected form with a Save button' );
check_ux( false === strpos( $mcp_js, 'ai_chat_bedrock_save_option' ) && false === strpos( $mcp, 'aicfab-mcp-instant' ), 'nothing on the screen saves on change any more' );
foreach ( array( 'saved', 'policy', 'oauth', 'revoked' ) as $aicfab_state ) {
	check_ux( false !== strpos( $mcp, "'" . $aicfab_state . "'" ), "a save that returns with aicfab-mcp={$aicfab_state} is confirmed on screen" );
}
check_ux( false !== strpos( $mcp_js, "addEventListener( 'hashchange'" ) && false !== strpos( $mcp_js, 'history.replaceState' ), 'MCP sections follow and update the address' );

// Content generator: one control per row, hints in descriptions rather than placeholders
// that vanish on typing, and no inline styles.
$generator = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-generator.php' );
check_ux( false === strpos( $generator, 'style=' ), 'the generator view has no inline styles' );
check_ux( 1 === preg_match( '#<th scope="row"><label for="aicfab_length">#', $generator ), 'the draft length has a row of its own' );
check_ux( 1 === preg_match( '/id="aicfab_language"[^>]*aria-describedby="aicfab_language_help"/', $generator ) && false === strpos( $generator, "placeholder=\"<?php esc_attr_e( 'Leave empty" ), 'the language hint is a description, not a placeholder' );
check_ux( 0 === substr_count( $generator, '<div class="notice notice-success">' ) + substr_count( $generator, '<div class="notice notice-error">' ), 'the generator notices can be dismissed' );

// Answer checks: one primary action, the run saves what is on screen, nothing sends twice.
$eval    = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-eval.php' );
$eval_js = file_get_contents( dirname( __DIR__ ) . '/admin/js/ai-chat-bedrock-eval.js' );
check_ux( 1 === substr_count( $eval, 'button-primary' ), 'the answer checks screen has one primary button' );
check_ux( 1 === preg_match( "/'aicfab-eval-run'.*?save\(\)\.then/s", $eval_js ), 'running the checks saves the table first' );
check_ux( false !== strpos( $eval_js, 'button.disabled = on;' ) && 2 === substr_count( $eval_js, 'busy( true );' ), 'the buttons are disabled while a request is out' );

// Settings fields: numbers use the core width class, the prompt version has its own line
// and label, and a failed prompt read is a standard inline notice.
foreach ( array( 'max_tokens', 'temperature', 'rate_limit_per_minute', 'context_results' ) as $key ) {
	check_ux( false !== strpos( $admin_source, 'id="aicfab_field_' . $key . '" class="small-text"' ), "{$key} uses the small-text width" );
}
check_ux( false !== strpos( $admin_source, '<br><label for="aicfab_field_prompt_version">' ) && false !== strpos( $admin_source, 'id="aicfab_field_prompt_version" name="ai_chat_bedrock_settings[prompt_version]"' ), 'the prompt version is labelled on its own line' );
check_ux( false !== strpos( $admin_source, '<div class="aicfab-prompt-error notice notice-error inline"><p>' ), 'a prompt error is a standard inline notice' );
check_ux( false === strpos( $admin_source, 'suggested_questions]" rows="4" class="large-text code"' ), 'suggested questions are prose, not code' );

// The setup notice can be dismissed, and the dismissal is remembered for the user.
check_ux( false !== strpos( $admin_source, 'notice-warning is-dismissible aicfab-setup-notice' ), 'the setup notice has a dismiss button' );
check_ux( false !== strpos( $admin_source, "update_user_meta( get_current_user_id(), 'aicfab_dismissed_setup_notice', 1 )" ), 'dismissing the setup notice is remembered' );
foreach ( array( false => 'error 403', true => 'success' ) as $valid => $expected ) {
	$GLOBALS['aicfab_ux_referer'] = (bool) $valid;
	$GLOBALS['aicfab_ux_meta']    = array();
	try {
		$admin->ajax_dismiss_setup_notice();
		check_ux( false, 'the dismissal answers with JSON' );
	} catch ( AicfabUxJson $json ) {
		check_ux( $expected === $json->getMessage(), "a dismissal with a valid nonce: {$valid}, answers {$expected}" );
		check_ux( (bool) $valid === ! empty( $GLOBALS['aicfab_ux_meta'][7]['aicfab_dismissed_setup_notice'] ), 'only a checked request is remembered' );
	}
}
$loader = file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock.php' );
check_ux( false !== strpos( $loader, "'wp_ajax_ai_chat_bedrock_dismiss_setup_notice', \$admin, 'ajax_dismiss_setup_notice'" ), 'the dismissal request is handled' );

// Diagnostics lists its fixes as a list, and Test Chat labels its rows and links to the settings.
check_ux( false !== strpos( $diagnostics, '<ul class="ul-disc">' ), 'the common fixes read as a bulleted list' );
$test_view = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-test.php' );
check_ux( 3 === substr_count( $test_view, '<th scope="row">' ) && false !== strpos( $test_view, 'tab=model' ), 'the test screen labels its rows and links to the model settings' );

// Third pass. Profiles: limits use the core number width, suggested questions are prose, and the
// Edit and Delete buttons of each row say which profile they act on.
check_ux( false !== strpos( $profiles, 'id="aicfab_profile_<?php echo esc_attr( $key ); ?>" class="small-text"' ), 'profile limits use the small-text width' );
check_ux( false === strpos( $profiles, 'large-text code' ), 'profile suggested questions are prose, not code' );
check_ux( 2 <= substr_count( $profiles, '<span class="screen-reader-text"> <?php echo esc_html( $profile[\'label\'] ); ?></span>' ), 'profile row buttons name their profile' );

// MCP: the signing region has a visible label and a description, the tables follow core, row
// buttons name their server, Remove recovers after a failure, and the tools dialog behaves.
check_ux( false !== strpos( $mcp, '<label for="ai_chat_bedrock_mcp_auth_region">' ) && false !== strpos( $mcp, 'aria-describedby="aicfab_mcp_auth_region_help"' ), 'the signing region is labelled and described' );
check_ux( 1 === preg_match( '/id="ai_chat_bedrock_mcp_auth_region"(?![^>]*placeholder=)[^>]*>/', $mcp ), 'the region hint is a description, not a placeholder' );
check_ux( false === strpos( $mcp, '<th>' ), 'MCP table headers have a scope' );
check_ux( false !== strpos( $mcp, 'id="aicfab_mcp_max_rounds" class="small-text"' ), 'the tool round limit uses the small-text width' );
check_ux( false !== strpos( $mcp, "wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' )" ), 'MCP dates follow the site format' );
check_ux( false !== strpos( $mcp_js, "'class': 'screen-reader-text'" ) && 3 === substr_count( $mcp_js, 'rowButton(\'ai-chat-bedrock-' ), 'MCP row buttons name their server' );
check_ux( 1 === preg_match( "/ai_chat_bedrock_unregister_mcp_server.*?\.always\(/s", $mcp_js ), 'the Remove button recovers after a failed request' );
check_ux( false !== strpos( $mcp_js, "'Escape' === event.key" ) && false !== strpos( $mcp_js, '.trigger(\'focus\')' ), 'the tools dialog closes on Escape and manages focus' );

// Notices added by script get core's translated dismiss button and are announced.
check_ux( false !== strpos( $admin_js, "trigger('wp-updates-notice-added')" ) && false === strpos( $admin_js, 'Dismiss this notice.' ), 'script notices use the core dismiss button' );
check_ux( false !== strpos( $admin_js, 'wp.a11y.speak' ), 'script notices are announced' );
check_ux( false === strpos( $admin_js, "'button button-primary'" ), 'the streamed generator result is a link, not a second primary button' );

// Site builder: the example is a description, and the summary counts what really happened.
$scaffold    = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-scaffold.php' );
$scaffold_js = file_get_contents( dirname( __DIR__ ) . '/admin/js/ai-chat-bedrock-scaffold.js' );
check_ux( false === strpos( $scaffold, 'placeholder=' ) && false !== strpos( $scaffold, 'aria-describedby="aicfab-scaffold-description-help"' ), 'the site description example is a description, not a placeholder' );
check_ux( false !== strpos( $scaffold_js, "classList.remove('button-primary')" ), 'once a plan is shown, creating drafts is the one primary action' );
check_ux( false !== strpos( $scaffold_js, 'button.disabled = on;' ) && 2 === substr_count( $scaffold_js, 'busy(true);' ), 'the site builder buttons are disabled while a request is out' );
check_ux( false !== strpos( $scaffold_js, "replace('%3\$d', counts.failed)" ) && false === strpos( $scaffold_js, 'planReady' ), 'the site builder summary counts created, skipped and failed pages' );

// Counts are translated with plural forms, dates follow the site settings, and log sources
// are shown by name rather than by their internal keys.
$conversations = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-conversations.php' );
$dashboard     = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-display.php' );
check_ux( 4 <= substr_count( $conversations, '_n(' ) && 4 <= substr_count( $dashboard, '_n(' ) && 2 <= substr_count( $admin_source, '_n(' ), 'counts use plural forms' );
check_ux( false !== strpos( $conversations, "get_option( 'date_format' ) . ' ' . get_option( 'time_format' )" ) && false === strpos( $conversations, "'Y-m-d H:i'" ), 'log times follow the site date format' );
check_ux( false !== strpos( $conversations, "'stream'  => __( 'Chat, streamed'" ) || false !== strpos( $conversations, "'Chat, streamed'" ), 'log sources are shown by name' );
check_ux( false !== strpos( $conversations, '<div class="aicfab-table-scroll">' ), 'the log table scrolls on its own on a narrow screen' );
$admin_css = file_get_contents( dirname( __DIR__ ) . '/admin/css/ai-chat-bedrock-admin.css' );
check_ux( 1 === preg_match( '/\.aicfab-table-scroll \{[^}]*position: relative;[^}]*overflow-x: auto;/', $admin_css ), 'screen reader text in a scrolling table is clipped with it, not widening the page' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "OK: admin screens follow the WordPress layout conventions\n";
