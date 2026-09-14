<?php
/** Standalone tests for conversation logging and the editor assistant. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'AI_CHAT_BEDROCK_VERSION', '1.5.0' );

$GLOBALS['aicfab_logged_in'] = true;
$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_user']    = 5;
$GLOBALS['aicfab_caps']    = array( 'edit_posts' => true );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Request {
	private $params;
	public function __construct( $params = array() ) { $this->params = $params; }
	public function get_param( $key ) { return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null; }
}
class WP_REST_Response {
	public $data;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; }
	public function get_data() { return $this->data; }
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
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_current_user_id() { return $GLOBALS['aicfab_user']; }
function current_user_can( $capability ) { return ! empty( $GLOBALS['aicfab_caps'][ $capability ] ); }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }
function wp_salt() { return 'conv-salt'; }
function register_rest_route( $namespace, $route, $args = array() ) { return true; }
function is_user_logged_in() { return ! empty( $GLOBALS['aicfab_logged_in'] ); }
function get_user_by( $field, $value ) {
	if ( 'email' !== $field || empty( $GLOBALS['aicfab_users'][ $value ] ) ) {
		return false;
	}
	$user = new stdClass();
	$user->ID = (int) $GLOBALS['aicfab_users'][ $value ];
	return $user;
}
function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/' ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/plugin/'; }
function wp_enqueue_script() { return true; }
function wp_localize_script() { return true; }

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-conversations.php';

$failures = array();
function check_conv( $condition, $message ) { global $failures; if ( ! $condition ) { $failures[] = $message; } }

// Logging is opt-in.
check_conv( ! AI_Chat_Bedrock_Conversations::enabled(), 'Conversation logging must be disabled by default.' );
AI_Chat_Bedrock_Conversations::record( 'Question while disabled', 'Answer while disabled' );
check_conv( 0 === AI_Chat_Bedrock_Conversations::summary()['count'], 'Nothing may be stored while logging is disabled.' );

$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Conversations::OPTION_ENABLED ] = true;
check_conv( AI_Chat_Bedrock_Conversations::enabled(), 'Logging must switch on when enabled.' );

// Recording captures content, usage and source.
AI_Chat_Bedrock_Conversations::record(
	'<strong>How many days</strong> for a refund?',
	'Twenty one days.',
	array( 'usage' => array( 'input_tokens' => 12, 'output_tokens' => 5 ), 'source' => 'stream', 'model' => 'us.anthropic.claude-haiku-4-5-20251001-v1:0' )
);
$entry = AI_Chat_Bedrock_Conversations::recent( 1 )[0];
check_conv( 'How many days for a refund?' === $entry['question'], 'Stored questions must have markup stripped.' );
check_conv( 'stream' === $entry['source'] && 12 === $entry['input_tokens'], 'Source and usage must be recorded.' );
check_conv( 5 === $entry['user'], 'Entries must record the acting user.' );

// Unknown sources fall back to chat.
AI_Chat_Bedrock_Conversations::record( 'q', 'a', array( 'source' => 'not-a-source' ) );
check_conv( 'chat' === AI_Chat_Bedrock_Conversations::recent( 1 )[0]['source'], 'Unknown sources must normalize to chat.' );

// Long content is truncated.
AI_Chat_Bedrock_Conversations::record( str_repeat( 'x', 5000 ), str_repeat( 'y', 5000 ) );
$long = AI_Chat_Bedrock_Conversations::recent( 1 )[0];
check_conv( AI_Chat_Bedrock_Conversations::MAX_TEXT === strlen( $long['question'] ), 'Stored questions must be truncated.' );
check_conv( AI_Chat_Bedrock_Conversations::MAX_TEXT === strlen( $long['answer'] ), 'Stored answers must be truncated.' );

// Retention removes old entries.
$rows   = get_option( AI_Chat_Bedrock_Conversations::OPTION_LOG );
$rows[] = array( 'time' => time() - ( 40 * DAY_IN_SECONDS ), 'user' => 5, 'source' => 'chat', 'question' => 'old', 'answer' => 'old' );
update_option( AI_Chat_Bedrock_Conversations::OPTION_LOG, $rows );
$before = count( get_option( AI_Chat_Bedrock_Conversations::OPTION_LOG ) );
AI_Chat_Bedrock_Conversations::enforce_retention();
check_conv( count( get_option( AI_Chat_Bedrock_Conversations::OPTION_LOG ) ) === $before - 1, 'Entries older than the retention window must be pruned.' );

$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Conversations::OPTION_RETENTION ] = 500;
check_conv( AI_Chat_Bedrock_Conversations::MAX_DAYS === AI_Chat_Bedrock_Conversations::retention_days(), 'Retention must be capped.' );
$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Conversations::OPTION_RETENTION ] = 7;

// The row cap is enforced.
for ( $i = 0; $i < 210; $i++ ) {
	AI_Chat_Bedrock_Conversations::record( 'q' . $i, 'a' . $i );
}
check_conv( AI_Chat_Bedrock_Conversations::MAX_ENTRIES >= AI_Chat_Bedrock_Conversations::summary()['count'], 'Stored entries must respect the row cap.' );

// Per-user deletion supports privacy requests.
$GLOBALS['aicfab_user'] = 9;
AI_Chat_Bedrock_Conversations::record( 'other user question', 'other user answer' );
$removed = AI_Chat_Bedrock_Conversations::forget_user( 5 );
check_conv( $removed > 0, 'Deleting one user must remove their entries.' );
foreach ( AI_Chat_Bedrock_Conversations::recent( 200 ) as $row ) {
	check_conv( 5 !== $row['user'], 'No entries may remain for a forgotten user.' );
}
check_conv( 0 === AI_Chat_Bedrock_Conversations::forget_user( 0 ), 'Invalid user IDs must be ignored.' );

AI_Chat_Bedrock_Conversations::clear();
check_conv( 0 === AI_Chat_Bedrock_Conversations::summary()['count'], 'Clearing must remove every entry.' );

// Editor assistant configuration and validation.
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wp-mcp-server.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-editor-assistant.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-feedback.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-chat-request.php';

check_conv( ! AI_Chat_Bedrock_Editor_Assistant::enabled(), 'The editor assistant must be disabled by default.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'editor_assistant' => true );
check_conv( AI_Chat_Bedrock_Editor_Assistant::enabled(), 'The assistant must switch on when enabled.' );

$actions = AI_Chat_Bedrock_Editor_Assistant::actions();
check_conv( 6 === count( $actions ), 'Six writing actions must be offered.' );
foreach ( array( 'improve', 'shorten', 'expand', 'summarize', 'headline', 'translate' ) as $key ) {
	check_conv( isset( $actions[ $key ]['prompt'] ) && '' !== $actions[ $key ]['prompt'], "Action $key must define an instruction." );
}
check_conv( false !== strpos( $actions['expand']['prompt'], 'Do not invent facts' ), 'Expansion must forbid inventing facts.' );

$assistant = new AI_Chat_Bedrock_Editor_Assistant();
$invalid   = $assistant->handle_request( new WP_REST_Request( array( 'action_type' => 'unknown', 'text' => 'hello' ) ) );
check_conv( is_wp_error( $invalid ) && 'aicfab_invalid_action' === $invalid->get_error_code(), 'Unknown actions must be refused.' );
$empty = $assistant->handle_request( new WP_REST_Request( array( 'action_type' => 'improve', 'text' => "  \n" ) ) );
check_conv( is_wp_error( $empty ) && 'aicfab_empty_text' === $empty->get_error_code(), 'Empty text must be refused.' );
$missing = $assistant->handle_request( new WP_REST_Request( array( 'action_type' => 'translate', 'text' => 'hello' ) ) );
check_conv( is_wp_error( $missing ) && 'aicfab_missing_language' === $missing->get_error_code(), 'Translation without a language must be refused.' );
$long = $assistant->handle_request( new WP_REST_Request( array( 'action_type' => 'improve', 'text' => str_repeat( 'a', AI_Chat_Bedrock_Editor_Assistant::MAX_INPUT + 10 ) ) ) );
check_conv( is_wp_error( $long ) && 'aicfab_text_too_long' === $long->get_error_code(), 'Oversized input must be refused.' );

$GLOBALS['aicfab_caps']['edit_posts'] = false;
$forbidden = $assistant->check_permission();
check_conv( is_wp_error( $forbidden ) && 'aicfab_forbidden' === $forbidden->get_error_code(), 'Users without edit_posts must be refused.' );
$GLOBALS['aicfab_caps']['edit_posts'] = true;

$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'editor_assistant' => false );
$disabled = $assistant->check_permission();
check_conv( is_wp_error( $disabled ) && 'aicfab_assistant_disabled' === $disabled->get_error_code(), 'A disabled assistant must refuse requests.' );


// --- Ratings, search, pagination and export -------------------------------

$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'editor_assistant' => true );
$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Conversations::OPTION_ENABLED ] = true;
delete_option( AI_Chat_Bedrock_Conversations::OPTION_LOG );

$id_one = AI_Chat_Bedrock_Conversations::record( 'What is the refund window?', 'Refunds are accepted for 21 days.', array( 'source' => 'chat', 'model' => 'claude', 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 5 ) ) );
$id_two = AI_Chat_Bedrock_Conversations::record( 'Where do you ship from?', 'Orders ship from Rotterdam.', array( 'source' => 'stream', 'model' => 'claude' ) );

check_conv( is_string( $id_one ) && 24 === strlen( $id_one ), 'Recording returns a 24 character identifier.' );
check_conv( $id_one !== $id_two, 'Each entry gets its own identifier.' );
check_conv( 1 === preg_match( '/^[a-f0-9]{24}$/', $id_one ), 'Identifiers are hexadecimal.' );

check_conv( true === AI_Chat_Bedrock_Conversations::rate( $id_one, 1 ), 'A helpful rating is stored.' );
check_conv( false === AI_Chat_Bedrock_Conversations::rate( $id_one, 5 ), 'Only 1 and -1 are accepted as ratings.' );
check_conv( false === AI_Chat_Bedrock_Conversations::rate( 'a' . str_repeat( 'f', 23 ), 1 ), 'An unknown identifier is refused.' );
check_conv( false === AI_Chat_Bedrock_Conversations::rate( 'short', 1 ), 'A malformed identifier is refused.' );

$counts = AI_Chat_Bedrock_Conversations::ratings();
check_conv( 1 === $counts['up'] && 0 === $counts['down'], 'Rating totals are counted.' );

check_conv( true === AI_Chat_Bedrock_Conversations::rate( $id_two, -1 ), 'An unhelpful rating is stored.' );
$counts = AI_Chat_Bedrock_Conversations::ratings();
check_conv( 1 === $counts['up'] && 1 === $counts['down'], 'Both rating directions are counted.' );

$search = AI_Chat_Bedrock_Conversations::query( array( 'search' => 'Rotterdam' ) );
check_conv( 1 === $search['total'], 'Search matches answer text.' );
check_conv( 'Where do you ship from?' === $search['entries'][0]['question'], 'Search returns the matching entry.' );

$search = AI_Chat_Bedrock_Conversations::query( array( 'search' => 'REFUND' ) );
check_conv( 1 === $search['total'], 'Search is case insensitive.' );

$filtered = AI_Chat_Bedrock_Conversations::query( array( 'source' => 'stream' ) );
check_conv( 1 === $filtered['total'] && 'stream' === $filtered['entries'][0]['source'], 'Source filtering works.' );

$rated = AI_Chat_Bedrock_Conversations::query( array( 'rating' => 'down' ) );
check_conv( 1 === $rated['total'] && -1 === (int) $rated['entries'][0]['rating'], 'Rating filtering works.' );

$unrated = AI_Chat_Bedrock_Conversations::query( array( 'rating' => 'none' ) );
check_conv( 0 === $unrated['total'], 'Both entries are rated, so the unrated filter is empty.' );

for ( $i = 0; $i < 25; $i++ ) {
	AI_Chat_Bedrock_Conversations::record( 'Question ' . $i, 'Answer ' . $i, array( 'source' => 'chat' ) );
}
$page_one = AI_Chat_Bedrock_Conversations::query( array( 'per_page' => 10, 'page' => 1 ) );
$page_two = AI_Chat_Bedrock_Conversations::query( array( 'per_page' => 10, 'page' => 2 ) );
check_conv( 27 === $page_one['total'], 'Pagination reports the full match count.' );
check_conv( 10 === count( $page_one['entries'] ), 'A page holds the requested number of entries.' );
check_conv( 3 === $page_one['pages'], 'Page count is derived from the total.' );
check_conv( $page_one['entries'][0]['question'] !== $page_two['entries'][0]['question'], 'Pages return different entries.' );
$beyond = AI_Chat_Bedrock_Conversations::query( array( 'per_page' => 10, 'page' => 99 ) );
check_conv( 3 === $beyond['page'], 'A page beyond the end is clamped.' );

$rows = AI_Chat_Bedrock_Conversations::export_rows();
check_conv( 'time_utc' === $rows[0][0] && 10 === count( $rows[0] ), 'The export starts with a header row.' );
// Named rather than counted, so adding a column is a deliberate change and a renamed one
// is caught. The grounding column is what the content gap report reads.
foreach ( array( 'time_utc', 'user_id', 'source', 'model', 'input_tokens', 'output_tokens', 'rating', 'grounded', 'question', 'answer' ) as $aicfab_column ) {
	check_conv( in_array( $aicfab_column, $rows[0], true ), "The export has a $aicfab_column column." );
}
check_conv( count( $rows ) === 28, 'The export contains every stored entry.' );
check_conv( false === strpos( implode( ',', $rows[0] ), 'rating_time' ), 'The export does not expose internal fields.' );

// Feedback endpoint boundaries.
$feedback = new AI_Chat_Bedrock_Feedback();
check_conv( true === $feedback->check_permission(), 'Logged in users may send feedback while logging is on.' );

$ok = $feedback->handle_request( new WP_REST_Request( array( 'entry' => $id_one, 'rating' => 'up' ) ) );
$ok_data = $ok instanceof WP_REST_Response ? $ok->get_data() : $ok;
check_conv( is_array( $ok_data ) && ! empty( $ok_data['recorded'] ), 'A valid rating is recorded through the endpoint.' );

$bad = $feedback->handle_request( new WP_REST_Request( array( 'entry' => $id_one, 'rating' => 'sideways' ) ) );
check_conv( is_wp_error( $bad ) && 'aicfab_invalid_rating' === $bad->get_error_code(), 'Only up and down are accepted.' );

$unknown = $feedback->handle_request( new WP_REST_Request( array( 'entry' => str_repeat( 'b', 24 ), 'rating' => 'up' ) ) );
check_conv( is_wp_error( $unknown ) && 'aicfab_unknown_entry' === $unknown->get_error_code(), 'An unknown entry is refused.' );

$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Conversations::OPTION_ENABLED ] = false;
check_conv( false === AI_Chat_Bedrock_Conversations::rate( $id_one, 1 ), 'Ratings are refused while logging is off.' );
$off = $feedback->check_permission();
check_conv( is_wp_error( $off ) && 'aicfab_feedback_disabled' === $off->get_error_code(), 'The endpoint is closed while logging is off.' );
$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Conversations::OPTION_ENABLED ] = true;

$GLOBALS['aicfab_logged_in'] = false;
$guest = $feedback->check_permission();
check_conv( is_wp_error( $guest ) && 'aicfab_forbidden' === $guest->get_error_code(), 'Guests are refused unless guest chat is on.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['allow_public_chat'] = true;
check_conv( true === $feedback->check_permission(), 'Guests may rate when guest chat is enabled.' );
$GLOBALS['aicfab_logged_in'] = true;

// --- Suggested questions --------------------------------------------------

$clean = AI_Chat_Bedrock_Chat_Request::sanitize_suggestions( "  What are your hours?  \n\n<b>Do you ship abroad?</b>\nThird\nFourth\nFifth" );
$lines = explode( "\n", $clean );
check_conv( 4 === count( $lines ), 'At most four suggestions are kept.' );
check_conv( 'What are your hours?' === $lines[0], 'Suggestions are trimmed.' );
check_conv( 'Do you ship abroad?' === $lines[1], 'Markup is stripped from suggestions.' );
check_conv( AI_Chat_Bedrock_Security::string_substr( str_repeat( 'x', 200 ), 0, AI_Chat_Bedrock_Chat_Request::MAX_SUGGESTION_CHARS ) === AI_Chat_Bedrock_Chat_Request::sanitize_suggestions( str_repeat( 'x', 200 ) ), 'Long suggestions are capped.' );
check_conv( array() === AI_Chat_Bedrock_Chat_Request::suggestions( array() ), 'No suggestions are returned when none are configured.' );
check_conv( 2 === count( AI_Chat_Bedrock_Chat_Request::suggestions( array( 'suggested_questions' => "One\nTwo" ) ) ), 'Configured suggestions are returned as a list.' );

// --- WordPress personal data tools ----------------------------------------

$GLOBALS['aicfab_users'] = array( 'visitor@example.com' => 7, 'other@example.com' => 8 );
$GLOBALS['aicfab_options'][ AI_Chat_Bedrock_Conversations::OPTION_ENABLED ] = true;
delete_option( AI_Chat_Bedrock_Conversations::OPTION_LOG );

$GLOBALS['aicfab_user'] = 7;
AI_Chat_Bedrock_Conversations::record( 'My order status?', 'It shipped yesterday.', array( 'source' => 'chat', 'model' => 'claude' ) );
$GLOBALS['aicfab_user'] = 8;
AI_Chat_Bedrock_Conversations::record( 'Someone else question', 'Someone else answer', array( 'source' => 'chat' ) );
$GLOBALS['aicfab_user'] = 7;

$exporters = AI_Chat_Bedrock_Conversations::register_exporter( array() );
check_conv( isset( $exporters['ai-chat-for-amazon-bedrock']['callback'] ), 'An exporter is registered.' );

$erasers = AI_Chat_Bedrock_Conversations::register_eraser( array() );
check_conv( isset( $erasers['ai-chat-for-amazon-bedrock']['callback'] ), 'An eraser is registered.' );

$export = AI_Chat_Bedrock_Conversations::export_personal_data( 'visitor@example.com' );
check_conv( true === $export['done'], 'The export finishes in one pass.' );
check_conv( 1 === count( $export['data'] ), 'Only the requesting user is exported.' );
$values = wp_list_pluck_compat( $export['data'][0]['data'], 'value' );
check_conv( in_array( 'My order status?', $values, true ), 'The exported item contains the question.' );
check_conv( in_array( 'It shipped yesterday.', $values, true ), 'The exported item contains the answer.' );
check_conv( ! in_array( 'Someone else question', $values, true ), 'Another user content is never exported.' );

$unknown = AI_Chat_Bedrock_Conversations::export_personal_data( 'nobody@example.com' );
check_conv( array() === $unknown['data'] && true === $unknown['done'], 'An unknown address exports nothing.' );

$erase = AI_Chat_Bedrock_Conversations::erase_personal_data( 'visitor@example.com' );
check_conv( true === $erase['items_removed'], 'The eraser removes stored entries.' );
check_conv( false === $erase['items_retained'], 'Nothing is retained after erasure.' );
check_conv( 0 === count( AI_Chat_Bedrock_Conversations::export_personal_data( 'visitor@example.com' )['data'] ), 'Erased entries are gone.' );
check_conv( 1 === AI_Chat_Bedrock_Conversations::query( array() )['total'], 'Other users entries survive the erasure.' );

$again = AI_Chat_Bedrock_Conversations::erase_personal_data( 'visitor@example.com' );
check_conv( false === $again['items_removed'], 'A repeated erasure reports nothing removed.' );

function wp_list_pluck_compat( $rows, $field ) {
	return array_map( static function ( $row ) use ( $field ) {
		return isset( $row[ $field ] ) ? $row[ $field ] : null;
	}, (array) $rows );
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: conversation log and editor assistant checks passed\n";
