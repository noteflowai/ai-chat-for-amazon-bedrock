<?php
/**
 * Standalone tests for conversation memory: what is saved with an account, for how long, and
 * how it is exported, erased and cleared.
 *
 * Run: php tests/chat-history.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['aicfab_options']   = array( 'ai_chat_bedrock_settings' => array( 'chat_memory' => 'account' ) );
$GLOBALS['aicfab_user_meta'] = array();
$GLOBALS['aicfab_logged_in'] = 7;
$GLOBALS['aicfab_schedule']  = array();
$GLOBALS['aicfab_routes']    = array();
$GLOBALS['aicfab_rate_ok']   = true;

// --- WordPress stubs -------------------------------------------------------

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $key ] : $default;
}
function __( $text, $domain = null ) {
	return $text;
}
function absint( $value ) {
	return abs( (int) $value );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
}
function esc_url_raw( $url, $protocols = null ) {
	return preg_match( '#^https?://[^\s"<>]+$#', (string) $url ) ? (string) $url : '';
}
function get_user_meta( $user_id, $key = '', $single = false ) {
	return isset( $GLOBALS['aicfab_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['aicfab_user_meta'][ $user_id ][ $key ] : '';
}
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['aicfab_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['aicfab_user_meta'][ $user_id ][ $key ] );
	if ( empty( $GLOBALS['aicfab_user_meta'][ $user_id ] ) ) {
		unset( $GLOBALS['aicfab_user_meta'][ $user_id ] );
	}
	return true;
}
function delete_metadata( $type, $object_id, $key, $value = '', $delete_all = false ) {
	foreach ( array_keys( $GLOBALS['aicfab_user_meta'] ) as $user_id ) {
		delete_user_meta( $user_id, $key );
	}
	return true;
}
function get_user_by( $field, $value ) {
	$users = array(
		'ana@example.test' => 7,
		'ben@example.test' => 8,
	);
	if ( 'email' !== $field || ! isset( $users[ $value ] ) ) {
		return false;
	}
	$user     = new stdClass();
	$user->ID = $users[ $value ];
	return $user;
}
function is_user_logged_in() {
	return $GLOBALS['aicfab_logged_in'] > 0;
}
function get_current_user_id() {
	return (int) $GLOBALS['aicfab_logged_in'];
}
function wp_next_scheduled( $hook ) {
	return isset( $GLOBALS['aicfab_schedule'][ $hook ] ) ? $GLOBALS['aicfab_schedule'][ $hook ] : false;
}
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$GLOBALS['aicfab_schedule'][ $hook ] = $timestamp;
	return true;
}
function wp_unschedule_event( $timestamp, $hook ) {
	unset( $GLOBALS['aicfab_schedule'][ $hook ] );
	return true;
}
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['aicfab_routes'][ $namespace . $route ] = $args;
	return true;
}

class WP_Error {
	public $code;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}
}
class WP_REST_Response {
	public $data;
	public $status;
	public $headers = array();
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}
}
class WP_REST_Request {
	private $params;
	public function __construct( $params = array() ) {
		$this->params = $params;
	}
	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}
}
/**
 * Users whose oldest saved message is older than the meta query's value.
 */
class WP_User_Query {
	private $results = array();
	public function __construct( $args ) {
		$clause = $args['meta_query'][0];
		foreach ( $GLOBALS['aicfab_user_meta'] as $user_id => $meta ) {
			if ( isset( $meta[ $clause['key'] ] ) && (int) $meta[ $clause['key'] ] < (int) $clause['value'] ) {
				$this->results[] = $user_id;
			}
		}
		$this->results = array_slice( $this->results, 0, $args['number'] );
	}
	public function get_results() {
		return $this->results;
	}
}

class AI_Chat_Bedrock_WP_MCP_Server {
	const NAMESPACE_V1 = 'ai-chat-bedrock/v1';
}
class AI_Chat_Bedrock_Profiles {
	public static function sanitize_key( $key ) {
		$key = sanitize_key( $key );
		return strlen( $key ) > 32 ? '' : $key;
	}
}
class AI_Chat_Bedrock_Security {
	public static function string_length( $value ) {
		return mb_strlen( (string) $value, 'UTF-8' );
	}
	public static function string_substr( $value, $start, $length ) {
		return mb_substr( (string) $value, $start, $length, 'UTF-8' );
	}
	public static function check_rate_limit( $bucket, $limit, $window = 60 ) {
		return $GLOBALS['aicfab_rate_ok'];
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-chat-history.php';

$failures = array();
function check_hist( $condition, $label ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $label;
	}
}
function aicfab_memory( $mode, $days = null ) {
	$settings = array( 'chat_memory' => $mode );
	if ( null !== $days ) {
		$settings['chat_memory_days'] = $days;
	}
	$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = $settings;
}
$key    = 'wp_' . AI_Chat_Bedrock_Chat_History::OPTION;
$oldest = 'wp_' . AI_Chat_Bedrock_Chat_History::OPTION_OLDEST;

// --- Settings ----------------------------------------------------------------

aicfab_memory( '' );
check_hist( '' === AI_Chat_Bedrock_Chat_History::mode() && ! AI_Chat_Bedrock_Chat_History::saves_for( 7 ), 'Memory is off unless chosen.' );
check_hist( '' === AI_Chat_Bedrock_Chat_History::mode( array( 'chat_memory' => 'forever' ) ), 'An unknown mode is off.' );
check_hist( 30 === AI_Chat_Bedrock_Chat_History::retention_days( array() ) && 365 === AI_Chat_Bedrock_Chat_History::retention_days( array( 'chat_memory_days' => 9999 ) ) && 30 === AI_Chat_Bedrock_Chat_History::retention_days( array( 'chat_memory_days' => 0 ) ), 'Retention defaults to 30 days and is capped at a year.' );
check_hist( ! AI_Chat_Bedrock_Chat_History::append( 7, '', 'Hi', 'Hello' ) && array() === $GLOBALS['aicfab_user_meta'], 'With memory off nothing is saved.' );
aicfab_memory( 'tab' );
check_hist( ! AI_Chat_Bedrock_Chat_History::append( 7, '', 'Hi', 'Hello' ) && array() === $GLOBALS['aicfab_user_meta'], 'Tab memory saves nothing on the site.' );
aicfab_memory( 'account' );
check_hist( ! AI_Chat_Bedrock_Chat_History::append( 0, '', 'Hi', 'Hello' ) && array() === $GLOBALS['aicfab_user_meta'], 'A guest\'s conversation is never saved on the site.' );

// --- Saving ------------------------------------------------------------------

check_hist( AI_Chat_Bedrock_Chat_History::append( 7, '', 'Where is my parcel?', 'On its way.', array( array( 'title' => 'Shipping <b>info</b>', 'url' => 'https://example.test/shipping/' ), array( 'title' => 'Bad', 'url' => 'javascript:alert(1)' ) ) ), 'A signed-in visitor\'s exchange is saved.' );
$saved = AI_Chat_Bedrock_Chat_History::get( 7 );
check_hist( 2 === count( $saved ) && 'user' === $saved[0]['role'] && 'Where is my parcel?' === $saved[0]['content'] && 'On its way.' === $saved[1]['content'], 'The question and answer are saved in order.' );
check_hist( array( array( 'title' => 'Shipping info', 'url' => 'https://example.test/shipping/' ) ) === $saved[1]['sources'], 'Only web links are kept as sources, with plain titles.' );
check_hist( ! isset( $saved[0]['sources'] ), 'A question has no sources.' );
check_hist( isset( $GLOBALS['aicfab_user_meta'][7][ $oldest ] ) && $GLOBALS['aicfab_user_meta'][7][ $oldest ] === $saved[0]['time'], 'The time of the oldest message is kept, so expiry can find it.' );
check_hist( ! AI_Chat_Bedrock_Chat_History::append( 7, '', '   ', 'x' ) && ! AI_Chat_Bedrock_Chat_History::append( 7, '', 'x', '' ), 'An empty question or answer is not saved.' );

AI_Chat_Bedrock_Chat_History::append( 7, 'support', 'Refund?', 'Within 30 days.' );
check_hist( 2 === count( AI_Chat_Bedrock_Chat_History::get( 7, 'support' ) ) && 2 === count( AI_Chat_Bedrock_Chat_History::get( 7, '' ) ), 'Each chat profile has its own conversation.' );
check_hist( array() === AI_Chat_Bedrock_Chat_History::get( 8 ), 'Another user sees nothing.' );

$long = str_repeat( '答', AI_Chat_Bedrock_Chat_History::MAX_TEXT + 50 );
AI_Chat_Bedrock_Chat_History::append( 7, 'long', 'Long?', $long );
$answer = AI_Chat_Bedrock_Chat_History::get( 7, 'long' )[1]['content'];
check_hist( AI_Chat_Bedrock_Chat_History::MAX_TEXT === mb_strlen( $answer, 'UTF-8' ) && '…' === mb_substr( $answer, -1, 1, 'UTF-8' ), 'A long answer is shortened by characters, not bytes.' );

for ( $i = 0; $i < 20; $i++ ) {
	AI_Chat_Bedrock_Chat_History::append( 7, 'busy', 'Question ' . $i, 'Answer ' . $i );
}
$busy = AI_Chat_Bedrock_Chat_History::get( 7, 'busy' );
check_hist( AI_Chat_Bedrock_Chat_History::MAX_MESSAGES === count( $busy ) && 'user' === $busy[0]['role'] && 'Answer 19' === end( $busy )['content'], 'A conversation keeps its most recent messages, starting with a question.' );

foreach ( array( 'a', 'b', 'c', 'd' ) as $profile ) {
	AI_Chat_Bedrock_Chat_History::append( 7, $profile, 'Q', 'A' );
}
$threads = $GLOBALS['aicfab_user_meta'][7][ $key ];
check_hist( AI_Chat_Bedrock_Chat_History::MAX_THREADS === count( $threads ) && ! isset( $threads['default'] ) && isset( $threads['d'] ), 'Only the conversations used most recently are kept.' );

// --- Expiry ------------------------------------------------------------------

$GLOBALS['aicfab_user_meta'] = array();
AI_Chat_Bedrock_Chat_History::append( 7, '', 'Fresh', 'Yes' );
$GLOBALS['aicfab_user_meta'][8][ $key ]    = array(
	'default' => array(
		array( 'role' => 'user', 'content' => 'Old', 'time' => time() - 40 * DAY_IN_SECONDS ),
		array( 'role' => 'assistant', 'content' => 'Old answer', 'time' => time() - 40 * DAY_IN_SECONDS ),
		array( 'role' => 'user', 'content' => 'New', 'time' => time() - DAY_IN_SECONDS - 60 ),
		array( 'role' => 'assistant', 'content' => 'New answer', 'time' => time() - DAY_IN_SECONDS - 60 ),
	),
	'support' => array(
		array( 'role' => 'user', 'content' => 'Ancient', 'time' => time() - 90 * DAY_IN_SECONDS ),
		array( 'role' => 'evil', 'content' => 'x', 'time' => time() ),
	),
);
$GLOBALS['aicfab_user_meta'][8][ $oldest ] = time() - 90 * DAY_IN_SECONDS;
$read                                      = AI_Chat_Bedrock_Chat_History::get( 8 );
check_hist( 2 === count( $read ) && 'New' === $read[0]['content'], 'Messages past the retention period are not returned.' );
check_hist( array() === AI_Chat_Bedrock_Chat_History::get( 8, 'support' ), 'Unknown roles are dropped.' );
check_hist( 1 === AI_Chat_Bedrock_Chat_History::prune_expired(), 'Pruning finds only users with expired messages.' );
check_hist( array( 'default' ) === array_keys( $GLOBALS['aicfab_user_meta'][8][ $key ] ) && 2 === count( $GLOBALS['aicfab_user_meta'][8][ $key ]['default'] ), 'Pruning removes the expired messages from storage.' );
check_hist( $GLOBALS['aicfab_user_meta'][8][ $oldest ] > time() - 2 * DAY_IN_SECONDS, 'The oldest time moves forward once old messages are gone.' );
check_hist( 0 === AI_Chat_Bedrock_Chat_History::prune_expired(), 'Nothing is left to prune.' );
aicfab_memory( 'account', 1 );
AI_Chat_Bedrock_Chat_History::prune_expired();
check_hist( ! isset( $GLOBALS['aicfab_user_meta'][8] ) && isset( $GLOBALS['aicfab_user_meta'][7] ), 'A user whose every message expired has nothing left stored.' );
aicfab_memory( 'account' );

// --- Scheduling and switching off ----------------------------------------------

AI_Chat_Bedrock_Chat_History::schedule();
check_hist( false !== wp_next_scheduled( AI_Chat_Bedrock_Chat_History::CRON_HOOK ), 'Pruning is scheduled while account memory is on.' );
aicfab_memory( 'tab' );
AI_Chat_Bedrock_Chat_History::schedule();
check_hist( false === wp_next_scheduled( AI_Chat_Bedrock_Chat_History::CRON_HOOK ), 'Pruning is unscheduled once it is off.' );
check_hist( 0 === AI_Chat_Bedrock_Chat_History::prune_expired(), 'Pruning does nothing while account memory is off.' );

aicfab_memory( 'account' );
AI_Chat_Bedrock_Chat_History::append( 8, '', 'Q', 'A' );
AI_Chat_Bedrock_Chat_History::settings_updated( array( 'chat_memory' => 'account' ), array( 'chat_memory' => 'account', 'chat_memory_days' => 10 ) );
check_hist( isset( $GLOBALS['aicfab_user_meta'][7], $GLOBALS['aicfab_user_meta'][8] ), 'Changing another setting keeps saved conversations.' );
AI_Chat_Bedrock_Chat_History::settings_updated( array( 'chat_memory' => 'account' ), array( 'chat_memory' => 'tab' ) );
check_hist( array() === $GLOBALS['aicfab_user_meta'], 'Switching account memory off deletes every saved conversation.' );

// --- REST --------------------------------------------------------------------

$rest = new AI_Chat_Bedrock_Chat_History();
$rest->register_routes();
$route = $GLOBALS['aicfab_routes']['ai-chat-bedrock/v1/history'];
check_hist( 'GET' === $route[0]['methods'] && 'DELETE' === $route[1]['methods'] && array( $rest, 'check_permission' ) === $route[0]['permission_callback'] && array( $rest, 'check_permission' ) === $route[1]['permission_callback'], 'Both routes check permission.' );

AI_Chat_Bedrock_Chat_History::append( 7, '', 'Mine', 'Yours' );
AI_Chat_Bedrock_Chat_History::append( 8, '', 'Not yours', 'Private' );
$GLOBALS['aicfab_logged_in'] = 0;
$denied                      = $rest->check_permission();
check_hist( $denied instanceof WP_Error && 401 === $denied->data['status'], 'A guest is refused.' );
$GLOBALS['aicfab_logged_in'] = 7;
check_hist( true === $rest->check_permission(), 'A signed-in user is let in.' );
$GLOBALS['aicfab_rate_ok'] = false;
check_hist( $rest->check_permission() instanceof WP_Error, 'The routes are rate limited.' );
$GLOBALS['aicfab_rate_ok'] = true;

$got = $rest->handle_get( new WP_REST_Request( array( 'profile' => '' ) ) );
check_hist( true === $got->data['enabled'] && 2 === count( $got->data['messages'] ) && 'Mine' === $got->data['messages'][0]['content'], 'GET returns the signed-in user\'s own conversation.' );
check_hist( false === strpos( json_encode( $got->data ), 'Private' ), 'GET never returns another user\'s conversation.' );
check_hist( 'no-store, private' === $got->headers['Cache-Control'], 'The response is not cached.' );
aicfab_memory( 'tab' );
$off = $rest->handle_get( new WP_REST_Request( array( 'profile' => '' ) ) );
check_hist( false === $off->data['enabled'] && array() === $off->data['messages'], 'GET returns nothing once saving is off.' );
aicfab_memory( 'account' );
$deleted = $rest->handle_delete( new WP_REST_Request( array( 'profile' => '' ) ) );
check_hist( 2 === $deleted->data['removed'] && array() === AI_Chat_Bedrock_Chat_History::get( 7 ) && 2 === count( AI_Chat_Bedrock_Chat_History::get( 8 ) ), 'DELETE clears only the signed-in user\'s conversation.' );

// --- Personal data -------------------------------------------------------------

AI_Chat_Bedrock_Chat_History::append( 7, '', 'Export me', 'Exported' );
AI_Chat_Bedrock_Chat_History::append( 7, 'support', 'And me', 'Also' );
check_hist( isset( AI_Chat_Bedrock_Chat_History::register_exporter( array() )['ai-chat-for-amazon-bedrock-history'] ) && isset( AI_Chat_Bedrock_Chat_History::register_eraser( array() )['ai-chat-for-amazon-bedrock-history'] ), 'The exporter and eraser are registered.' );
$export = AI_Chat_Bedrock_Chat_History::export_personal_data( 'ana@example.test' );
check_hist( 4 === count( $export['data'] ) && true === $export['done'], 'Every saved message is exported.' );
check_hist( 'Export me' === $export['data'][0]['data'][2]['value'] && 'Question' === $export['data'][0]['data'][2]['name'] && 'support' === $export['data'][2]['data'][0]['value'], 'Each item says which chat, when, and what was said.' );
check_hist( array() === AI_Chat_Bedrock_Chat_History::export_personal_data( 'nobody@example.test' )['data'], 'An unknown address exports nothing.' );
$erased = AI_Chat_Bedrock_Chat_History::erase_personal_data( 'ana@example.test' );
check_hist( true === $erased['items_removed'] && array() === AI_Chat_Bedrock_Chat_History::get( 7, 'support' ) && ! isset( $GLOBALS['aicfab_user_meta'][7] ), 'Erasing removes every saved conversation of that user.' );
check_hist( 2 === count( AI_Chat_Bedrock_Chat_History::get( 8 ) ), 'Erasing leaves other users alone.' );
check_hist( false === AI_Chat_Bedrock_Chat_History::erase_personal_data( 'ana@example.test' )['items_removed'], 'Erasing again reports nothing removed.' );

// --- Wiring (source checks) ----------------------------------------------------

$main = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock.php' );
check_hist( false !== strpos( $main, "'wp_privacy_personal_data_exporters', 'AI_Chat_Bedrock_Chat_History', 'register_exporter'" ) && false !== strpos( $main, "'wp_privacy_personal_data_erasers', 'AI_Chat_Bedrock_Chat_History', 'register_eraser'" ), 'The exporter and eraser are hooked.' );
check_hist( false !== strpos( $main, "'update_option_ai_chat_bedrock_settings', 'AI_Chat_Bedrock_Chat_History', 'settings_updated', 10, 2" ), 'Switching memory off is watched with both values.' );
check_hist( false !== strpos( $main, 'AI_Chat_Bedrock_Chat_History::CRON_HOOK' ), 'The pruning event has a handler.' );
foreach ( array( 'public/class-ai-chat-bedrock-public.php', 'includes/class-ai-chat-bedrock-stream.php' ) as $file ) {
	$body = file_get_contents( __DIR__ . '/../' . $file );
	check_hist( false !== strpos( $body, 'AI_Chat_Bedrock_Chat_History::append( ' ) || false !== strpos( $body, "AI_Chat_Bedrock_Chat_History::append(\n" ), 'Answers are saved from ' . $file . '.' );
}
$deactivator = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-deactivator.php' );
check_hist( false !== strpos( $deactivator, "'" . AI_Chat_Bedrock_Chat_History::CRON_HOOK . "'" ), 'Deactivation clears the pruning event.' );
$script = file_get_contents( __DIR__ . '/../public/js/ai-chat-bedrock-public.js' );
check_hist( false !== strpos( $script, "savedHistory('DELETE')" ) && false !== strpos( $script, 'storeTranscript();' ), 'Clear in the chat also deletes the kept and saved conversation.' );
check_hist( false !== strpos( $script, 'const requestHistory = historyForRequest();' ) && false !== strpos( $script, 'MAX_HISTORY_BYTES' ), 'Requests send history within the server\'s byte limit.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: conversation memory checks passed\n";
