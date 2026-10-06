<?php
/**
 * Standalone tests for analytics events and the WP Consent API.
 *
 * Run: php tests/analytics.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_cookies'] = array();
$GLOBALS['aicfab_memory']  = '';

function get_option( $name, $fallback = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $fallback;
}
function __( $text, $domain = null ) {
	return $text;
}

class AI_Chat_Bedrock_Chat_History {
	public static function mode() {
		return $GLOBALS['aicfab_memory'];
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-analytics.php';
require_once __DIR__ . '/../includes/class-ai-chat-bedrock-consent.php';

$failures = array();
function check_analytics( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// --- The setting ----------------------------------------------------------------

check_analytics( ! AI_Chat_Bedrock_Analytics::enabled(), 'Analytics events are off on a site that has not turned them on.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = 'corrupt';
check_analytics( ! AI_Chat_Bedrock_Analytics::enabled(), 'A damaged settings option leaves them off.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'analytics_events' => true );
check_analytics( AI_Chat_Bedrock_Analytics::enabled(), 'The saved setting turns them on.' );
check_analytics( ! AI_Chat_Bedrock_Analytics::enabled( array() ), 'Settings passed in are used instead of the saved ones.' );
check_analytics( AI_Chat_Bedrock_Analytics::enabled( array( 'analytics_events' => '1' ) ), 'A checked box turns them on.' );

// --- Analytics plugins found --------------------------------------------------------

check_analytics( array() === AI_Chat_Bedrock_Analytics::tools(), 'No analytics plugin is named when none is active.' );
define( 'GOOGLESITEKIT_VERSION', '1.170.0' );
define( 'GTM4WP_VERSION', '2.0.5' );
check_analytics( array( 'Site Kit by Google', 'GTM4WP' ) === AI_Chat_Bedrock_Analytics::tools(), 'Site Kit and GTM4WP are recognised by their constants.' );
define( 'MONSTERINSIGHTS_VERSION', '9.0.0' );
define( 'MATOMO_ANALYTICS_FILE', '/plugins/matomo/matomo.php' );
define( 'PLAUSIBLE_ANALYTICS_PLUGIN_FILE', '/plugins/plausible-analytics/plausible-analytics.php' );
check_analytics( 5 === count( AI_Chat_Bedrock_Analytics::tools() ), 'MonsterInsights, Matomo and Plausible are recognised too.' );

// --- What the events may carry --------------------------------------------------------

// The chat and its popup script, which opens the panel and sends the events.
$script = (string) file_get_contents( dirname( __DIR__ ) . '/public/js/ai-chat-bedrock-public.js' ) . (string) file_get_contents( dirname( __DIR__ ) . '/public/js/ai-chat-bedrock-popup.js' );
preg_match_all( "/track\\('([a-z_]+)', \\{(.*?)\\}\\);/s", $script, $calls, PREG_SET_ORDER );
$sent = array();
foreach ( $calls as $call ) {
	preg_match_all( '/([a-z_]+):/', $call[2], $keys );
	$sent[ $call[1] ] = array_unique( array_merge( isset( $sent[ $call[1] ] ) ? $sent[ $call[1] ] : array(), $keys[1] ) );
}
ksort( $sent );
$documented = AI_Chat_Bedrock_Analytics::EVENTS;
ksort( $documented );
check_analytics( array_keys( $documented ) === array_keys( $sent ), 'The chat sends exactly the events that are documented.' );
foreach ( $documented as $event => $params ) {
	$actual = isset( $sent[ $event ] ) ? $sent[ $event ] : array();
	sort( $actual );
	sort( $params );
	check_analytics( $params === $actual, $event . ' carries exactly its documented parameters.' );
}
foreach ( $sent as $event => $params ) {
	check_analytics( ! array_intersect( $params, array( 'message', 'text', 'email', 'phone', 'name', 'question', 'answer' ) ), $event . ' carries nothing that was written.' );
	check_analytics( strlen( $event ) <= 40, $event . ' fits the 40 characters Google Analytics allows for an event name.' );
}

$admin = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-ai-chat-bedrock-admin.php' );
check_analytics( 1 === preg_match( "/const CHECKBOX_FIELDS = array\\([^)]*'analytics_events',/s", $admin ), 'The setting is a checkbox, labelled by its own text rather than the row title.' );

// --- The WP Consent API -----------------------------------------------------------

$main = (string) file_get_contents( dirname( __DIR__ ) . '/ai-chat-for-amazon-bedrock.php' );
check_analytics( false !== strpos( $main, "add_filter( 'wp_consent_api_registered_' . plugin_basename( __FILE__ ), '__return_true' );" ), 'The plugin declares that it follows the WP Consent API, under its own file name.' );
$bootstrap = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock.php' );
check_analytics( false !== strpos( $bootstrap, "add_action( 'init', 'AI_Chat_Bedrock_Consent', 'describe_storage' )" ), 'What the chat stores is described on init.' );
check_analytics( false !== strpos( $bootstrap, "includes/class-ai-chat-bedrock-analytics.php'" ) && false !== strpos( $bootstrap, "includes/class-ai-chat-bedrock-consent.php'" ), 'Both classes are loaded.' );

AI_Chat_Bedrock_Consent::describe_storage();
check_analytics( true, 'Without the WP Consent API, describing the storage does nothing and does not fail.' );

// Declared here, not hoisted: until now the WP Consent API was missing.
if ( ! function_exists( 'wp_add_cookie_info' ) ) {
	function wp_add_cookie_info( $name, $service, $category, $expires, $purpose, $data = '', $member = false, $administrator = false, $type = 'HTTP', $domain = '' ) {
		$GLOBALS['aicfab_cookies'][ $name ] = compact( 'service', 'category', 'expires', 'purpose', 'data', 'member', 'administrator', 'type' );
	}
}

AI_Chat_Bedrock_Consent::describe_storage();
check_analytics( array( 'aicfabPopupOpen' ) === array_keys( $GLOBALS['aicfab_cookies'] ), 'Without conversation memory only the floating chat\'s state is described.' );
$popup = $GLOBALS['aicfab_cookies']['aicfabPopupOpen'];
check_analytics( 'functional' === $popup['category'] && 'LOCALSTORAGE' === $popup['type'], 'It is described as functional browser storage, not a cookie.' );
check_analytics( '' === $popup['data'] && false === $popup['member'] && false === $popup['administrator'], 'It holds no personal data and is used by every visitor.' );

$GLOBALS['aicfab_cookies'] = array();
$GLOBALS['aicfab_memory']  = 'tab';
AI_Chat_Bedrock_Consent::describe_storage();
$chat = isset( $GLOBALS['aicfab_cookies']['aicfabChat:*'] ) ? $GLOBALS['aicfab_cookies']['aicfabChat:*'] : array();
check_analytics( 2 === count( $GLOBALS['aicfab_cookies'] ) && 'functional' === $chat['category'] && 'Chat messages' === $chat['data'], 'With conversation memory the kept conversation is described, as chat messages.' );
check_analytics( false !== strpos( $script, "const MEMORY_PREFIX = 'aicfabChat:';" ) && false !== strpos( $script, "const stateKey = 'aicfabPopupOpen';" ), 'The names described are the ones the chat uses.' );
check_analytics( 'AI Chatbot & Agents for Amazon Bedrock' === $chat['service'] && 'Until the browser tab is closed' === $chat['expires'], 'Each is named after the plugin and kept for the session.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: analytics and consent checks passed\n";
