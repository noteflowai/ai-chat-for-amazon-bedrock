<?php
/**
 * Standalone tests for tool call ownership between the MCP handler and the Abilities bridge.
 *
 * Both handlers listen on the same filter and both see names shaped like
 * owner___tool. The MCP handler runs first, so it must leave ability calls
 * untouched; otherwise every ability call is marked as an invalid server and
 * can never execute.
 *
 * Run: php tests/tool-ownership.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_called']   = array();
$GLOBALS['aicfab_logged']   = array();
$GLOBALS['aicfab_may_use']  = true;
$GLOBALS['aicfab_allowed']  = true;

// --- WordPress stubs -------------------------------------------------------

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function wp_generate_uuid4() {
	return 'uuid-' . count( $GLOBALS['aicfab_called'] );
}
function __( $text, $domain = null ) {
	return $text;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function get_option( $name, $default = false ) {
	return $default;
}
function update_option( $name, $value, $autoload = null ) {
	return true;
}
function get_current_user_id() {
	return 1;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
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

class AI_Chat_Bedrock_Abilities {
	const TOOL_PREFIX = 'wpability___';
}

class AI_Chat_Bedrock_Tool_Policy {
	public static function current_user_may_use_tools() {
		return (bool) $GLOBALS['aicfab_may_use'];
	}
	public static function is_tool_allowed( $tool, $description = '' ) {
		return (bool) $GLOBALS['aicfab_allowed'];
	}
}

class AI_Chat_Bedrock_Tool_Log {
	public static function record( $entry ) {
		$GLOBALS['aicfab_logged'][] = $entry;
	}
}

class Stub_MCP_Client {
	public function parse_tool_name( $name ) {
		$parts = explode( '___', (string) $name, 2 );
		return array( 'server_name' => $parts[0], 'tool_name' => isset( $parts[1] ) ? $parts[1] : '' );
	}
	public function call_tool( $server, $tool, $parameters = array() ) {
		$GLOBALS['aicfab_called'][] = $server . '/' . $tool;
		if ( 'wpsite' !== $server ) {
			return new WP_Error( 'invalid_server', 'Invalid MCP server.' );
		}
		return array( 'content' => 'server result' );
	}
	public function get_all_tools() {
		return array( array( 'name' => 'wpsite___search_posts', 'description' => 'Search posts' ) );
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-mcp-integration.php';

$failures = array();
function check_owner( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function integration() {
	$reflection = new ReflectionClass( 'AI_Chat_Bedrock_MCP_Integration' );
	$instance   = $reflection->newInstanceWithoutConstructor();
	$property   = $reflection->getProperty( 'mcp_client' );
	$property->setAccessible( true );
	$property->setValue( $instance, new Stub_MCP_Client() );
	return $instance;
}

// --- Ability calls must survive the MCP handler untouched ------------------

$GLOBALS['aicfab_called'] = array();
$GLOBALS['aicfab_logged'] = array();

$mcp      = integration();
$response = $mcp->process_mcp_tool_calls(
	array(
		'success'    => true,
		'tool_round' => 1,
		'tool_calls' => array(
			array( 'id' => 'a1', 'name' => 'wpability___core__get_site_info', 'parameters' => array( 'fields' => array( 'name' ) ) ),
		),
	),
	'site name?'
);

check_owner( 1 === count( $response['tool_calls'] ), 'The ability call is kept.' );
check_owner( 'wpability___core__get_site_info' === $response['tool_calls'][0]['name'], 'The ability call keeps its name.' );
check_owner( ! isset( $response['tool_calls'][0]['error'] ), 'The MCP handler does not fail an ability call.' );
check_owner( ! isset( $response['tool_calls'][0]['result'] ), 'The MCP handler does not resolve an ability call.' );
check_owner( array() === $GLOBALS['aicfab_called'], 'No MCP request is made for an ability call.' );
check_owner( array() === $GLOBALS['aicfab_logged'], 'An ability call is not audited twice.' );

// --- Genuine MCP calls still execute --------------------------------------

$GLOBALS['aicfab_called'] = array();
$response                 = integration()->process_mcp_tool_calls(
	array(
		'success'    => true,
		'tool_round' => 1,
		'tool_calls' => array( array( 'id' => 'b1', 'name' => 'wpsite___search_posts', 'parameters' => array( 'query' => 'refund' ) ) ),
	),
	'refund?'
);
check_owner( array( 'wpsite/search_posts' ) === $GLOBALS['aicfab_called'], 'A registered MCP tool is executed.' );
check_owner( isset( $response['tool_calls'][0]['result'] ), 'The MCP result is attached.' );

// --- Unknown MCP servers still fail closed --------------------------------

$GLOBALS['aicfab_called'] = array();
$response                 = integration()->process_mcp_tool_calls(
	array(
		'success'    => true,
		'tool_round' => 1,
		'tool_calls' => array( array( 'id' => 'c1', 'name' => 'ghost___do_thing', 'parameters' => array() ) ),
	),
	'anything'
);
check_owner( isset( $response['tool_calls'][0]['error'] ), 'An unknown server is refused.' );
check_owner( 'invalid_server' === $response['tool_calls'][0]['error']['code'], 'The refusal keeps the invalid_server code.' );

// --- Already resolved calls are not executed again ------------------------

$GLOBALS['aicfab_called'] = array();
$response                 = integration()->process_mcp_tool_calls(
	array(
		'success'    => true,
		'tool_round' => 1,
		'tool_calls' => array( array( 'id' => 'd1', 'name' => 'wpsite___search_posts', 'result' => 'already done' ) ),
	),
	'refund?'
);
check_owner( array() === $GLOBALS['aicfab_called'], 'A resolved call is not executed twice.' );
check_owner( 'already done' === $response['tool_calls'][0]['result'], 'A resolved call keeps its result.' );

// --- Users without tool rights get no execution ---------------------------

$GLOBALS['aicfab_may_use'] = false;
$GLOBALS['aicfab_called']  = array();
$response                  = integration()->process_mcp_tool_calls(
	array(
		'success'    => true,
		'tool_calls' => array( array( 'id' => 'e1', 'name' => 'wpsite___search_posts' ) ),
	),
	'refund?'
);
check_owner( ! isset( $response['tool_calls'] ), 'Tool calls are dropped for users without tool rights.' );
check_owner( array() === $GLOBALS['aicfab_called'], 'Nothing runs for users without tool rights.' );
$GLOBALS['aicfab_may_use'] = true;

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: tool call ownership checks passed\n";
