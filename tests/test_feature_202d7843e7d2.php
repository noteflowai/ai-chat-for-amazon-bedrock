<?php
/**
 * Site-source coverage in the usage store, with the conversation log off.
 *
 * The grounded flag used to live only in the conversation log, which is off by default, so an
 * operator who keeps it off had no way to see how many website chat answers had site content
 * behind them. This drives the real streaming endpoint, Conversations, Usage and the CLI with
 * local stubs for everything that would reach Bedrock, and checks what an operator reads.
 *
 * All cases are synthetic. Nothing here calls a model or a network service.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Squiz.PHP.Eval

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'AI_CHAT_BEDROCK_VERSION', 'test' );

$GLOBALS['aicfab_options']  = array();
$GLOBALS['aicfab_stdout']   = array();
$GLOBALS['aicfab_stderr']   = array();
$GLOBALS['t_warnings']      = array();
$GLOBALS['t_events']        = array();
$GLOBALS['t_built']         = null;
$GLOBALS['t_response']      = null;

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		$GLOBALS['t_warnings'][] = $message . ' at ' . basename( $file ) . ':' . $line;
		return true;
	}
);

$failures = array();
function check_grounding( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// --- The smallest WordPress these classes need --------------------------------

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['aicfab_options'][ $name ] );
	return true;
}
function get_transient( $key ) {
	return false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	return true;
}
function delete_transient( $key ) {
	return true;
}
function apply_filters( $hook, $value, ...$args ) {
	return $value;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	return true;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function sanitize_textarea_field( $value ) {
	return trim( (string) $value );
}
function absint( $value ) {
	return abs( (int) $value );
}
function __( $text, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function get_current_user_id() {
	return 0;
}
function current_user_can( $capability ) {
	return false;
}
function is_user_logged_in() {
	return false;
}
function wp_salt( $scheme = 'auth' ) {
	return 'test-salt';
}
function current_time( $type = 'mysql', $gmt = 0 ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}

// WP-CLI's table printer lives in a namespace; defined here without making this file namespaced.
eval( 'namespace WP_CLI\Utils; function format_items( $format, $items, $fields ) { $GLOBALS["aicfab_stdout"][] = "[table]"; }' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}

class Aicfab_Halt extends Exception {
	public $exit_code;
	public function __construct( $code ) {
		parent::__construct( 'halt' );
		$this->exit_code = (int) $code;
	}
}

class WP_CLI {
	public static function add_command( $name, $class ) {
	}
	public static function line( $message = '' ) {
		$GLOBALS['aicfab_stdout'][] = (string) $message;
	}
	public static function log( $message = '' ) {
		$GLOBALS['aicfab_stdout'][] = (string) $message;
	}
	public static function success( $message = '' ) {
		$GLOBALS['aicfab_stdout'][] = 'Success: ' . $message;
	}
	public static function warning( $message = '' ) {
		$GLOBALS['aicfab_stderr'][] = 'Warning: ' . $message;
	}
	public static function error( $message = '' ) {
		$GLOBALS['aicfab_stderr'][] = 'Error: ' . $message;
		throw new Aicfab_Halt( 1 );
	}
	public static function halt( $code ) {
		throw new Aicfab_Halt( $code );
	}
}

// --- Stand-ins for everything the stream endpoint touches besides the log and usage ----

class AI_Chat_Bedrock_Security {
	public static function string_substr( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length ) : substr( (string) $value, $start, $length );
	}
}
class AI_Chat_Bedrock_Profiles {
	public static function sanitize_key( $key ) {
		return sanitize_key( $key );
	}
	public static function resolve( $profile ) {
		return array( 'model_id' => 'amazon.nova-lite-v1:0' );
	}
	public static function overrides_for_client( $options ) {
		return array();
	}
}
class AI_Chat_Bedrock_Content {
	public static function request_language( $lang ) {
		return '';
	}
}
class AI_Chat_Bedrock_Chat_Request {
	public static function build( $message, $history, $options ) {
		return $GLOBALS['t_built'];
	}
}
class AI_Chat_Bedrock_AWS {
	public function __construct( $overrides = array() ) {
	}
}
class AI_Chat_Bedrock_Tool_Runner {
	public static function run( $aws, $messages, $message, $emit, $round ) {
		return $GLOBALS['t_response'];
	}
}
class AI_Chat_Bedrock_Chat_History {
	public static function append( $user, $profile, $question, $answer, $sources ) {
		return true;
	}
}
class AI_Chat_Bedrock_Speech {
	public static function replies_enabled() {
		return false;
	}
	public static function token( $text ) {
		return '';
	}
}

/**
 * The real close() exits, which ends the request. Throwing stands in for that here.
 */
class T_Stream_Closed extends Exception {
}
class AI_Chat_Bedrock_SSE {
	public function open() {
	}
	public function send( $type, $data ) {
		$GLOBALS['t_events'][] = $type;
	}
	public function close() {
		throw new T_Stream_Closed( 'closed' );
	}
}
class T_Request {
	private $params;
	public function __construct( $params ) {
		$this->params = $params;
	}
	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-usage.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-conversations.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-stream.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-cli.php';

if ( ! method_exists( 'AI_Chat_Bedrock_Usage', 'grounding_summary' ) || ! method_exists( 'AI_Chat_Bedrock_Usage', 'record_grounding' ) ) {
	echo 'FAIL: the usage store has no site-source coverage counter.' . PHP_EOL;
	exit( 1 );
}

// --- Helpers ----------------------------------------------------------------------

function t_built( $grounded ) {
	return array(
		'messages' => array( array( 'role' => 'user', 'content' => array( array( 'text' => 'How long do refunds take?' ) ) ) ),
		'message'  => 'How long do refunds take?',
		'grounded' => $grounded,
		'sources'  => array(),
		'products' => array(),
	);
}
function t_success( $text ) {
	return array(
		'success' => true,
		'data'    => array( 'message' => $text ),
		'usage'   => array( 'input_tokens' => 10, 'output_tokens' => 4 ),
	);
}
function t_stream( $built, $response ) {
	$GLOBALS['t_built']    = $built;
	$GLOBALS['t_response'] = $response;
	$GLOBALS['t_events']   = array();
	$endpoint              = new AI_Chat_Bedrock_Stream();
	try {
		$endpoint->stream(
			new T_Request(
				array(
					'message' => 'How long do refunds take?',
					'history' => '',
					'profile' => '',
					'lang'    => '',
					'product' => 0,
				)
			)
		);
		return 'returned';
	} catch ( T_Stream_Closed $closed ) {
		return 'closed';
	}
}
function t_cli( $assoc ) {
	$GLOBALS['aicfab_stdout'] = array();
	$cli                      = new AI_Chat_Bedrock_CLI();
	try {
		$cli->usage( array(), $assoc );
		return 0;
	} catch ( Aicfab_Halt $halt ) {
		return $halt->exit_code;
	}
}
function t_all_ints( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			if ( ! t_all_ints( $item ) ) {
				return false;
			}
		}
		return true;
	}
	return is_int( $value );
}
function t_index_of( $lines, $prefix ) {
	foreach ( $lines as $index => $line ) {
		if ( 0 === strpos( $line, $prefix ) ) {
			return $index;
		}
	}
	return -1;
}

$log_option   = AI_Chat_Bedrock_Conversations::OPTION_LOG;
$usage_option = AI_Chat_Bedrock_Usage::OPTION;

// --- With the log off, successful website chat answers are counted ---------------------

check_grounding( ! AI_Chat_Bedrock_Conversations::enabled(), 'The conversation log must be off for this test.' );
$empty = AI_Chat_Bedrock_Usage::grounding_summary( 7 );
check_grounding( 0 === $empty['answers'] && null === $empty['percent'], 'With nothing counted, answers must be 0 and percent null.' );

check_grounding( 'closed' === t_stream( t_built( true ), t_success( 'Refunds take 21 days.' ) ), 'A successful stream must end the request.' );
t_stream( t_built( true ), t_success( 'Refunds take 21 days.' ) );
t_stream( t_built( false ), t_success( 'I could not find that on this site.' ) );
check_grounding( in_array( 'done', $GLOBALS['t_events'], true ), 'A successful stream must send its done event.' );

$summary = AI_Chat_Bedrock_Usage::grounding_summary( 7 );
check_grounding( 3 === $summary['answers'], 'Three successful answers must be counted, got ' . var_export( $summary['answers'], true ) . '.' );
check_grounding( 2 === $summary['site'] && 1 === $summary['none'], 'Two answers from site content and one without must be counted.' );
check_grounding( 67 === $summary['percent'], 'Two of three must read 67 percent.' );
check_grounding( empty( get_option( $log_option, array() ) ), 'Nothing may be written to the conversation log while it is off.' );

$stored = get_option( $usage_option, array() );
check_grounding( is_array( $stored ) && t_all_ints( $stored ), 'The usage store must hold only integers.' );
check_grounding( false === stripos( serialize( $stored ), 'refund' ) && false === stripos( serialize( $stored ), 'could not find' ), 'No question or answer text may reach the usage store.' );

// --- What the operator reads ------------------------------------------------------

$code  = t_cli( array( 'days' => '7' ) );
$lines = $GLOBALS['aicfab_stdout'];
check_grounding( 0 === $code, 'The usage command must not halt nonzero.' );
$line_at = array_search( 'Website chat answers from site content (7 days): 2 of 3 (67%), 1 with no site content.', $lines, true );
check_grounding( false !== $line_at, 'The daily view must print the coverage line, got: ' . implode( ' | ', $lines ) );
$failed_at = t_index_of( $lines, 'Failed requests' );
check_grounding( -1 === $failed_at || ( false !== $line_at && $line_at < $failed_at ), 'The coverage line must come before the Failed requests line.' );
check_grounding( 0 === strpos( (string) end( $lines ), 'Time to first text' ), 'Time to first text must stay the last line.' );

t_cli( array( 'days' => '7', 'by-model' => true ) );
check_grounding( -1 === t_index_of( $GLOBALS['aicfab_stdout'], 'Website chat answers' ), 'The per-model view must not print the coverage line.' );

// --- Failed, empty and other-channel answers add nothing ------------------------------

t_stream( new WP_Error( 'aicfab_empty', 'Please enter a message.' ), t_success( 'unused' ) );
check_grounding( in_array( 'error', $GLOBALS['t_events'], true ), 'A build error must reach the visitor as an error event.' );
t_stream( t_built( true ), array( 'success' => false, 'data' => array( 'message' => 'Throttled.', 'code' => 'aicfab_throttled' ) ) );
t_stream( t_built( true ), t_success( "  \n\t " ) );
check_grounding( 3 === AI_Chat_Bedrock_Usage::grounding_summary( 7 )['answers'], 'A build error, a failed run or a whitespace answer must add nothing.' );

foreach ( array( 'editor', 'ability', 'wechat', 'wxgame' ) as $source ) {
	AI_Chat_Bedrock_Conversations::record( 'q', 'An answer.', array( 'source' => $source, 'grounded' => true ) );
}
check_grounding( 3 === AI_Chat_Bedrock_Usage::grounding_summary( 7 )['answers'], 'Answers outside website chat must not be counted.' );
AI_Chat_Bedrock_Conversations::record( 'q', 'An answer.', array( 'source' => 'chat', 'grounded' => true ) );
$summary = AI_Chat_Bedrock_Usage::grounding_summary( 7 );
check_grounding( 3 === $summary['site'] && 1 === $summary['none'], 'A non-streamed chat answer must be counted too.' );
check_grounding( empty( get_option( $log_option, array() ) ), 'The log must still be empty while it is off.' );

// --- With the log on, entries are unchanged and the counter keeps counting -------------

update_option( AI_Chat_Bedrock_Conversations::OPTION_ENABLED, true );
t_stream( t_built( true ), t_success( 'Refunds take 21 days.' ) );
$entry = AI_Chat_Bedrock_Conversations::recent( 1 );
check_grounding( isset( $entry[0] ) && 'stream' === $entry[0]['source'] && 1 === $entry[0]['grounded'], 'With the log on, a grounded stream must still be logged as grounded.' );
t_stream( t_built( false ), t_success( 'No page covers that.' ) );
$entry = AI_Chat_Bedrock_Conversations::recent( 1 );
check_grounding( isset( $entry[0] ) && 0 === $entry[0]['grounded'], 'With the log on, an ungrounded stream must still be logged as ungrounded.' );
$summary = AI_Chat_Bedrock_Usage::grounding_summary( 7 );
check_grounding( 4 === $summary['site'] && 2 === $summary['none'], 'The counter must keep counting with the log on.' );
delete_option( AI_Chat_Bedrock_Conversations::OPTION_ENABLED );

// --- Retention and reset ----------------------------------------------------------

$recent_day = gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS );
$old_day    = gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS );
$stored     = get_option( $usage_option, array() );
$stored[ $recent_day ] = array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'models' => array(), 'grounding' => array( 'site' => 2, 'none' => 0 ) );
$stored[ $old_day ]    = array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'models' => array(), 'grounding' => array( 'site' => 9, 'none' => 9 ) );
update_option( $usage_option, $stored );
AI_Chat_Bedrock_Usage::record_grounding( false );
$stored = get_option( $usage_option, array() );
check_grounding( isset( $stored[ $recent_day ]['grounding'] ) && array( 'site' => 2, 'none' => 0 ) === $stored[ $recent_day ]['grounding'], 'Counters for a retained day must survive pruning.' );
check_grounding( ! isset( $stored[ $old_day ] ), 'Days beyond the retention period must be pruned.' );
$summary = AI_Chat_Bedrock_Usage::grounding_summary( 7 );
check_grounding( 6 === $summary['site'] && 3 === $summary['none'], 'The window must add the retained day and today.' );

AI_Chat_Bedrock_Usage::reset();
check_grounding( 0 === AI_Chat_Bedrock_Usage::grounding_summary( 30 )['answers'], 'reset() must clear the coverage counters.' );

$code = t_cli( array( 'days' => '7' ) );
check_grounding( 0 === $code, 'An empty window must not halt nonzero.' );
check_grounding( -1 === t_index_of( $GLOBALS['aicfab_stdout'], 'Website chat answers' ), 'An empty window must print no coverage line, never 0%.' );
check_grounding( 0 === strpos( (string) end( $GLOBALS['aicfab_stdout'] ), 'Time to first text' ), 'Time to first text must stay the last line with an empty window.' );

// --- Malformed stored values count as zero --------------------------------------------

$today = gmdate( 'Y-m-d' );
$day1  = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
$day2  = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
update_option(
	$usage_option,
	array(
		$today => array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'models' => array(), 'grounding' => 'site' ),
		$day1  => array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'models' => array(), 'grounding' => array( 'site' => -3, 'none' => '2' ) ),
		$day2  => array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'models' => array(), 'grounding' => 5 ),
	)
);
$GLOBALS['t_warnings'] = array();
$summary               = AI_Chat_Bedrock_Usage::grounding_summary( 7 );
check_grounding( 0 === $summary['answers'] && 0 === $summary['site'] && 0 === $summary['none'], 'Malformed stored values must count as zero.' );
check_grounding( null === $summary['percent'], 'percent must be null when no answers are counted.' );
check_grounding( empty( $GLOBALS['t_warnings'] ), 'Malformed values must not raise PHP warnings: ' . implode( ' | ', $GLOBALS['t_warnings'] ) );

restore_error_handler();

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}
echo 'Site-source coverage tests passed.' . PHP_EOL;
