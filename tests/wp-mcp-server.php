<?php
/** Standalone tests for the built-in WordPress MCP server dispatch. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AI_CHAT_BEDROCK_VERSION', '1.3.0' );

$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_actions'] = array();

class WP_Error {
	private $code; private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class WP_Post {
	public $ID = 1; public $post_title = ''; public $post_status = 'publish'; public $post_password = ''; public $post_type = 'post';
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['aicfab_actions'][ $hook ][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $name, $default = false ) { return isset( $GLOBALS['aicfab_options'][ $name ] ) ? $GLOBALS['aicfab_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['aicfab_options'][ $name ] = $value; return true; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_title( $value ) { return strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '-', (string) $value ) ); }
function sanitize_user( $value ) { return preg_replace( '/[^A-Za-z0-9_.\-@ ]/', '', (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $message, $domain = null ) { return $message; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function current_user_can( $capability ) { return true; }
function is_user_logged_in() { return true; }
function get_current_user_id() { return 1; }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ); }
function register_rest_route( $namespace, $route, $args = array() ) { $GLOBALS['aicfab_routes'][] = $namespace . $route; return true; }
function rest_ensure_response( $value ) { return $value; }
function wp_salt() { return 'server-salt'; }

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-tool-policy.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-tool-log.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wp-mcp-server.php';

$failures = array();
function check_server( $condition, $message ) { global $failures; if ( ! $condition ) { $failures[] = $message; } }

$server   = new AI_Chat_Bedrock_WP_MCP_Server();
$dispatch = new ReflectionMethod( $server, 'dispatch' );
$dispatch->setAccessible( true );
$call = function ( array $payload ) use ( $dispatch, $server ) {
	return $dispatch->invoke( $server, $payload );
};

// Initialize negotiates the protocol version and advertises capabilities.
$init = $call( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-11-25' ) ) );
check_server( '2.0' === $init['jsonrpc'] && 1 === $init['id'], 'initialize must answer the same request id.' );
check_server( '2025-11-25' === $init['result']['protocolVersion'], 'A valid requested protocol version must be echoed.' );
check_server( isset( $init['result']['capabilities']['tools'] ), 'Tool capability must be advertised.' );
check_server( false !== strpos( $init['result']['serverInfo']['version'], '1.3.0' ), 'Server info must report the plugin version.' );

$bad_version = $call( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => array( 'protocolVersion' => 'not-a-date' ) ) );
check_server( AI_Chat_Bedrock_WP_MCP_Server::PROTOCOL_VERSION === $bad_version['result']['protocolVersion'], 'Invalid protocol versions must fall back to the supported version.' );

// Notifications produce no response body.
check_server( null === $call( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ), 'Notifications must not produce a response.' );

// Tool listing exposes read-only tools by default.
$list  = $call( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list' ) );
$names = array_map( function ( $tool ) { return $tool['name']; }, $list['result']['tools'] );
check_server( in_array( 'search_posts', $names, true ) && in_array( 'get_site_info', $names, true ), 'Read-only tools must be listed.' );
check_server( ! in_array( 'create_draft', $names, true ), 'Write tools must stay hidden while site abilities are disabled.' );
foreach ( $list['result']['tools'] as $tool ) {
	check_server( isset( $tool['inputSchema']['type'] ) && 'object' === $tool['inputSchema']['type'], 'Every tool must publish an object input schema.' );
}

// Enabling the controlled abilities adds the draft-only write tool.
$GLOBALS['aicfab_options']['ai_chat_bedrock_site_abilities'] = true;
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-site-abilities.php';
$server_with_abilities = new AI_Chat_Bedrock_WP_MCP_Server();
$dispatch2             = new ReflectionMethod( $server_with_abilities, 'dispatch' );
$dispatch2->setAccessible( true );
$list2  = $dispatch2->invoke( $server_with_abilities, array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list' ) );
$names2 = array_map( function ( $tool ) { return $tool['name']; }, $list2['result']['tools'] );
check_server( in_array( 'create_draft', $names2, true ), 'Draft creation must appear once site abilities are enabled.' );
check_server( in_array( 'suggest_seo_meta', $names2, true ), 'SEO suggestions must appear once site abilities are enabled.' );
check_server( ! in_array( 'delete_post', $names2, true ) && ! in_array( 'update_post', $names2, true ), 'No destructive tools may ever be exposed.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_site_abilities'] = false;

// The site description is a tool, and the server points to it, only once the site turns it on.
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-ontology.php';
$list3  = $call( array( 'jsonrpc' => '2.0', 'id' => 40, 'method' => 'tools/list' ) );
$names3 = array_map( function ( $tool ) { return $tool['name']; }, $list3['result']['tools'] );
check_server( ! in_array( 'describe_site', $names3, true ), 'describe_site stays hidden while the site description is off.' );
check_server( false === strpos( $init['result']['instructions'], 'describe_site' ), 'The instructions do not mention a hidden tool.' );
$off = $call( array( 'jsonrpc' => '2.0', 'id' => 41, 'method' => 'tools/call', 'params' => array( 'name' => 'describe_site' ) ) );
check_server( -32602 === $off['error']['code'], 'describe_site cannot be called while it is off.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'site_ontology' => true );
$list4  = $call( array( 'jsonrpc' => '2.0', 'id' => 42, 'method' => 'tools/list' ) );
$names4 = array_map( function ( $tool ) { return $tool['name']; }, $list4['result']['tools'] );
check_server( in_array( 'describe_site', $names4, true ), 'describe_site is listed once the site description is on.' );
$discover = $call( array( 'jsonrpc' => '2.0', 'id' => 43, 'method' => 'server/discover' ) );
$init2    = $call( array( 'jsonrpc' => '2.0', 'id' => 44, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-11-25' ) ) );
check_server( false !== strpos( $discover['result']['instructions'], 'Call describe_site first' ) && false !== strpos( $init2['result']['instructions'], 'Call describe_site first' ), 'The instructions point to describe_site.' );
unset( $GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] );

// Business figures are a tool only while the site turns them on and the account may read one.
class AI_Chat_Bedrock_Metrics {
	public static function enabled() { return ! empty( $GLOBALS['aicfab_metrics_on'] ); }
	public static function available( $usage ) { return 'agent' === $usage ? $GLOBALS['aicfab_metrics_available'] : array(); }
	public static function input_schema( $usage, $current_user = false ) {
		$GLOBALS['aicfab_metrics_schema_for'] = array( $usage, $current_user );
		return array( 'type' => 'object', 'properties' => array( 'metric' => array( 'type' => 'string', 'enum' => array_keys( $GLOBALS['aicfab_metrics_available'] ) ) ), 'required' => array( 'metric' ) );
	}
	public static function query( $args, $usage ) {
		$GLOBALS['aicfab_metrics_queried'] = array( $args, $usage );
		return array( 'metric' => $args['metric'], 'value' => 14, 'hidden' => false );
	}
}
$metric_names = function () use ( $call ) {
	$list = $call( array( 'jsonrpc' => '2.0', 'id' => 45, 'method' => 'tools/list' ) );
	return array_map( function ( $tool ) { return $tool['name']; }, $list['result']['tools'] );
};
$GLOBALS['aicfab_metrics_on']        = false;
$GLOBALS['aicfab_metrics_available'] = array( 'orders' => array() );
check_server( ! in_array( 'query_metrics', $metric_names(), true ), 'query_metrics stays hidden while business insights are off.' );
$off = $call( array( 'jsonrpc' => '2.0', 'id' => 46, 'method' => 'tools/call', 'params' => array( 'name' => 'query_metrics', 'arguments' => array( 'metric' => 'orders' ) ) ) );
check_server( isset( $off['error'] ) && ! isset( $GLOBALS['aicfab_metrics_queried'] ), 'query_metrics cannot be called while business insights are off.' );
$GLOBALS['aicfab_metrics_on']        = true;
$GLOBALS['aicfab_metrics_available'] = array();
check_server( ! in_array( 'query_metrics', $metric_names(), true ), 'query_metrics stays hidden from an account that may read no figure.' );
$GLOBALS['aicfab_metrics_available'] = array( 'orders' => array() );
check_server( in_array( 'query_metrics', $metric_names(), true ), 'query_metrics is listed once business insights are on.' );
check_server( array( 'agent', true ) === $GLOBALS['aicfab_metrics_schema_for'], 'The tool lists only the figures the agent may read for this account.' );
$init3 = $call( array( 'jsonrpc' => '2.0', 'id' => 47, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-11-25' ) ) );
check_server( false !== strpos( $init3['result']['instructions'], 'Call query_metrics' ), 'The instructions point to query_metrics while it is listed.' );
$on = $call( array( 'jsonrpc' => '2.0', 'id' => 48, 'method' => 'tools/call', 'params' => array( 'name' => 'query_metrics', 'arguments' => array( 'metric' => 'orders', 'period' => 'last_month' ) ) ) );
check_server( isset( $on['result'] ) && empty( $on['result']['isError'] ), 'query_metrics answers once business insights are on.' );
check_server( array( array( 'metric' => 'orders', 'period' => 'last_month' ), 'agent' ) === $GLOBALS['aicfab_metrics_queried'], 'The tool queries as an agent, so the data rules for agents apply.' );
$GLOBALS['aicfab_metrics_on'] = false;

// Unknown methods and unknown tools are rejected with JSON-RPC errors.
$unknown = $call( array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/destroy' ) );
check_server( -32601 === $unknown['error']['code'], 'Unknown methods must return -32601.' );
$missing = $call( array( 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => array( 'name' => 'nope' ) ) );
check_server( -32602 === $missing['error']['code'], 'Unknown tools must return -32602.' );
$empty = $call( array( 'jsonrpc' => '2.0', 'id' => 7 ) );
check_server( -32600 === $empty['error']['code'], 'Requests without a method must return -32600.' );

// Empty method names and prompts/resources listings behave predictably.
$prompts = $call( array( 'jsonrpc' => '2.0', 'id' => 8, 'method' => 'prompts/list' ) );
check_server( array() === $prompts['result']['prompts'], 'Prompt listing must return an empty list.' );
$resources = $call( array( 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'resources/list' ) );
check_server( array() === $resources['result']['resources'], 'Resource listing must return an empty list.' );
$ping = $call( array( 'jsonrpc' => '2.0', 'id' => 10, 'method' => 'ping' ) );
check_server( isset( $ping['result'] ), 'Ping must return a result.' );

// The standard route and the legacy routes are both registered.
$GLOBALS['aicfab_routes'] = array();
$server->register_routes();
check_server( in_array( 'ai-chat-bedrock/v1/mcp', $GLOBALS['aicfab_routes'], true ), 'The standard MCP route must be registered.' );
check_server( in_array( 'ai-chat-bedrock/v1/mcp/discover', $GLOBALS['aicfab_routes'], true ), 'Legacy discovery must remain available.' );

// --- Protocol revisions --------------------------------------------------------

// 2026-07-28 removed the initialize handshake and protocol-level sessions: a request states
// its own version in _meta. A stateless server therefore cannot remember that a client
// negotiated an older revision, which is why the header remains the signal for those.
$aicfab_server_src = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-wp-mcp-server.php' );

check_server( false !== strpos( $aicfab_server_src, "const PROTOCOL_VERSION = '2026-07-28';" ), 'the server prefers the current revision' );
check_server( false !== strpos( $aicfab_server_src, "const SUPPORTED_VERSIONS = array( '2026-07-28', '2025-11-25', '2025-06-18' );" ), 'older revisions are still answered' );
check_server( false !== strpos( $aicfab_server_src, "case 'server/discover':" ), 'server/discover is implemented, which the revision requires' );
check_server( false !== strpos( $aicfab_server_src, 'const ERROR_UNSUPPORTED_VERSION = -32022;' ), 'an unsupported revision is refused with the reserved code' );
check_server( false !== strpos( $aicfab_server_src, 'trim( (string) $header_version )' ), 'the header is read for revisions that state their version there' );

// _meta has to win: a client that upgraded may still send a stale header.
$aicfab_meta_at   = strpos( $aicfab_server_src, 'isset( $meta[ self::META_PROTOCOL_VERSION ] )' );
$aicfab_header_at = strpos( $aicfab_server_src, 'trim( (string) $header_version )' );
check_server( false !== $aicfab_meta_at && false !== $aicfab_header_at && $aicfab_meta_at < $aicfab_header_at, '_meta is consulted before the header' );

// The modern envelope must be conditional, or an older client receives fields it never saw.
check_server( false !== strpos( $aicfab_server_src, 'is_modern( $version ) && is_array( $result )' ), 'the modern envelope is only added for modern callers' );
// Matched by pattern: the formatter realigns assignments, which broke a literal match.
check_server( 1 === preg_match( "/'resultType'\s*\]\s*=\s*'complete'/", $aicfab_server_src ), 'modern results carry resultType' );
check_server( 1 === preg_match( "/'cacheScope'\s*\]\s*=\s*'private'/", $aicfab_server_src ), 'a list result is not cacheable by shared intermediaries, since it depends on the caller' );
check_server( false !== strpos( $aicfab_server_src, 'usort(' ) && false !== strpos( $aicfab_server_src, "strcmp( \$left['name'], \$right['name'] )" ), 'tools are listed in a deterministic order' );

$aicfab_client_src = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-mcp-transport.php' );
check_server( false !== strpos( $aicfab_client_src, "const PROTOCOL_VERSION = '2026-07-28';" ), 'the client speaks the current revision' );
check_server( 1 === preg_match( '/META_PROTOCOL_VERSION\s*\]\s*=\s*\$version;/', $aicfab_client_src ), 'the client states its revision in _meta on every request' );
check_server( false !== strpos( $aicfab_client_src, "'Mcp-Method'" ), 'the client sends the request header the revision requires' );
check_server( false !== strpos( $aicfab_client_src, 'self::FALLBACK_VERSION' ), 'the client downgrades once when the modern revision is refused' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: WordPress MCP server dispatch checks passed\n";
