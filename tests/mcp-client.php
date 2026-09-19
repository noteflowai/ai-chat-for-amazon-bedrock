<?php
/**
 * MCP client checks, against the real class.
 *
 * No suite loaded AI_Chat_Bedrock_MCP_Client. The transport beneath it is covered, and
 * AI_Chat_Bedrock_Security::is_safe_mcp_url is covered on its own, but the client's use of that
 * check was not: it re-validates the stored URL before every call, which is the layer that
 * matters when a URL reaches the option without going through register_server, and removing it
 * turns this class into an SSRF hole while every suite still passes.
 *
 * The transport is replaced here because these checks must not open a socket. Security is real,
 * so the URL rules under test are the ones that actually ship.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride

define( 'ABSPATH', __DIR__ );
define( 'AI_CHAT_BEDROCK_VERSION', 'test' );

$failures = array();
function check_mcp( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// --- The smallest WordPress this class needs ----------------------------------

$GLOBALS['aicfab_opts']      = array();
$GLOBALS['aicfab_transport'] = array();
$GLOBALS['aicfab_http']      = array();
$GLOBALS['aicfab_next']      = null;

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_opts'] ) ? $GLOBALS['aicfab_opts'][ $name ] : $default_value;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_opts'][ $name ] = $value;
	return true;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/' );
}
function trailingslashit( $value ) {
	return untrailingslashit( $value ) . '/';
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function esc_url_raw( $url, $protocols = null ) {
	$url = trim( (string) $url );
	if ( is_array( $protocols ) ) {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, $protocols, true ) ) {
			return '';
		}
	}
	return $url;
}

/*
 * Stands in for WordPress's own SSRF guard. It refuses loopback, link-local and the private
 * ranges, which is the part these assertions depend on. It is intentionally no more permissive
 * than core: a lenient double here would let the suite claim a protection it has not shown.
 */
function wp_http_validate_url( $url ) {
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return false;
	}
	if ( ! in_array( strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	$host = strtolower( $parts['host'] );
	if ( 'localhost' === $host ) {
		return false;
	}
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
	}
	return $url;
}
function wp_safe_remote_post( $url, $args = array() ) {
	$GLOBALS['aicfab_http'][] = array( 'url' => $url, 'args' => $args );
	return array( 'response' => array( 'code' => 200 ), 'body' => '{"result":"legacy"}' );
}
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}
function __( $text, $domain = null ) {
	return $text;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_generate_uuid4() {
	return '00000000-0000-4000-8000-000000000000';
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

/**
 * Records what the client asked of the transport and answers with whatever the test queued.
 * No socket is opened; the point is which URL, method and auth the client decided to send.
 */
class AI_Chat_Bedrock_MCP_Transport {
	public static function call( $endpoint, $method, $params = array(), $auth = array(), $timeout = 10 ) {
		$GLOBALS['aicfab_transport'][] = array(
			'endpoint' => $endpoint,
			'method'   => $method,
			'params'   => $params,
			'auth'     => $auth,
			'timeout'  => $timeout,
		);
		if ( null !== $GLOBALS['aicfab_next'] ) {
			$queued                = $GLOBALS['aicfab_next'];
			$GLOBALS['aicfab_next'] = null;
			return $queued;
		}
		return array( 'tools' => array( array( 'name' => 'search_docs', 'description' => 'Search the docs' ) ) );
	}

	public static function sanitize_auth( $auth ) {
		$auth = is_array( $auth ) ? $auth : array();
		$type = isset( $auth['type'] ) ? sanitize_key( $auth['type'] ) : 'none';
		return array( 'type' => in_array( $type, array( 'none', 'bearer', 'sigv4' ), true ) ? $type : 'none' );
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-mcp-client.php';

// --- Which servers may be registered at all -----------------------------------

$aicfab_client = new AI_Chat_Bedrock_MCP_Client();

check_mcp( true === $aicfab_client->register_server( 'docs', 'https://mcp.example.com/' ), 'A public HTTPS server registers.' );
check_mcp( false === $aicfab_client->register_server( 'docs', 'https://other.example.com' ), 'An existing name is never silently overwritten.' );
check_mcp( false === $aicfab_client->register_server( 'plain', 'http://mcp.example.com' ), 'A plaintext HTTP server is refused.' );
check_mcp( false === $aicfab_client->register_server( 'local', 'https://localhost/mcp' ), 'localhost is refused.' );
check_mcp( false === $aicfab_client->register_server( 'loop', 'https://127.0.0.1/mcp' ), 'Loopback by address is refused.' );
check_mcp( false === $aicfab_client->register_server( 'meta', 'https://169.254.169.254/latest/meta-data/' ), 'The instance metadata address is refused.' );
check_mcp( false === $aicfab_client->register_server( 'priv', 'https://10.0.0.5/mcp' ), 'A private range address is refused.' );
check_mcp( false === $aicfab_client->register_server( 'priv2', 'https://192.168.1.10/mcp' ), 'Another private range is refused.' );
check_mcp( false === $aicfab_client->register_server( 'creds', 'https://user:pass@mcp.example.com' ), 'A URL carrying credentials is refused.' );
check_mcp( false === $aicfab_client->register_server( 'frag', 'https://mcp.example.com/#x' ), 'A URL with a fragment is refused.' );
check_mcp( false === $aicfab_client->register_server( '', 'https://mcp.example.com' ), 'An empty name is refused.' );
check_mcp( false === $aicfab_client->register_server( str_repeat( 'a', 65 ), 'https://mcp.example.com' ), 'An overlong name is refused.' );
check_mcp( 1 === count( $aicfab_client->get_servers() ), 'Only the one valid server was stored, got ' . count( $aicfab_client->get_servers() ) );

// --- A URL that never went through register_server is still refused ------------

/*
 * The option can be written by an import, a migration, or any code holding the option name.
 * register_server is not the only door, so the check is repeated before each call. Without
 * that, a stored metadata-service URL would be fetched by the site on request.
 */
$GLOBALS['aicfab_opts']['ai_chat_bedrock_mcp_servers'] = array(
	'evil' => array(
		'url'   => 'https://169.254.169.254/latest/meta-data/iam/security-credentials/',
		'tools' => array( array( 'name' => 'steal', 'description' => 'x' ) ),
		'auth'  => array( 'type' => 'none' ),
	),
);
$aicfab_smuggled             = new AI_Chat_Bedrock_MCP_Client();
$GLOBALS['aicfab_transport'] = array();
$GLOBALS['aicfab_http']      = array();

$aicfab_called = $aicfab_smuggled->call_tool( 'evil', 'steal', array() );
check_mcp( $aicfab_called instanceof WP_Error, 'A smuggled unsafe URL is refused at call time.' );
check_mcp(
	$aicfab_called instanceof WP_Error && 'invalid_server' === $aicfab_called->get_error_code(),
	'It is refused as an invalid server.'
);
check_mcp( array() === $GLOBALS['aicfab_transport'], 'Nothing reaches the transport for an unsafe stored URL.' );
check_mcp( array() === $GLOBALS['aicfab_http'], 'No HTTP request is made either.' );
check_mcp( array() === $aicfab_smuggled->discover_server_tools( 'evil' ), 'Discovery against an unsafe stored URL returns nothing.' );
$aicfab_status = $aicfab_smuggled->server_status( 'evil' );
check_mcp( empty( $aicfab_status['available'] ), 'An unsafe stored URL is reported unavailable.' );
check_mcp( ! empty( $aicfab_status['reason'] ), 'The reason is given rather than a bare false.' );
check_mcp( array() === $GLOBALS['aicfab_transport'], 'Still nothing reached the transport.' );

// --- Only discovered tools may be called --------------------------------------

$GLOBALS['aicfab_opts'] = array();
$aicfab_client          = new AI_Chat_Bedrock_MCP_Client();
$aicfab_client->register_server( 'docs', 'https://mcp.example.com' );

$GLOBALS['aicfab_transport'] = array();
$aicfab_unknown              = $aicfab_client->call_tool( 'docs', 'not_discovered', array() );
check_mcp( $aicfab_unknown instanceof WP_Error, 'A tool that was never discovered cannot be called.' );
check_mcp(
	$aicfab_unknown instanceof WP_Error && 'invalid_tool' === $aicfab_unknown->get_error_code(),
	'It is refused as an invalid tool.'
);
check_mcp( array() === $GLOBALS['aicfab_transport'], 'An undiscovered tool never reaches the transport.' );

$GLOBALS['aicfab_transport'] = array();
$aicfab_ok                   = $aicfab_client->call_tool( 'docs', 'search_docs', array( 'q' => 'refunds' ) );
check_mcp( 1 === count( $GLOBALS['aicfab_transport'] ), 'A discovered tool is called once.' );
check_mcp( isset( $GLOBALS['aicfab_transport'][0]['method'] ) && 'tools/call' === $GLOBALS['aicfab_transport'][0]['method'], 'It is called with tools/call.' );
check_mcp( isset( $GLOBALS['aicfab_transport'][0]['params']['name'] ) && 'search_docs' === $GLOBALS['aicfab_transport'][0]['params']['name'], 'The tool name is passed through.' );
$aicfab_endpoint = isset( $GLOBALS['aicfab_transport'][0]['endpoint'] ) ? $GLOBALS['aicfab_transport'][0]['endpoint'] : '(none)';
check_mcp( 'https://mcp.example.com' === $aicfab_endpoint, 'The registered URL is the endpoint, got ' . $aicfab_endpoint );

// An unregistered server cannot be reached at all.
check_mcp( $aicfab_client->call_tool( 'nope', 'search_docs' ) instanceof WP_Error, 'An unregistered server is refused.' );

// --- Input is bounded ---------------------------------------------------------

$GLOBALS['aicfab_transport'] = array();
$aicfab_big                  = $aicfab_client->call_tool( 'docs', 'search_docs', array( 'q' => str_repeat( 'x', 70000 ) ) );
check_mcp( $aicfab_big instanceof WP_Error, 'An oversized tool input is refused.' );
check_mcp(
	$aicfab_big instanceof WP_Error && 'payload_too_large' === $aicfab_big->get_error_code(),
	'It says the payload is too large.'
);
check_mcp( array() === $GLOBALS['aicfab_transport'], 'An oversized payload never reaches the transport.' );

// --- What comes back is normalised, and an error stays an error ----------------

$GLOBALS['aicfab_next'] = array( 'isError' => true, 'content' => array( array( 'type' => 'text', 'text' => 'Tool blew up' ) ) );
$aicfab_err             = $aicfab_client->call_tool( 'docs', 'search_docs' );
check_mcp( $aicfab_err instanceof WP_Error, 'A tool reporting isError becomes an error.' );
check_mcp(
	$aicfab_err instanceof WP_Error && 'Tool blew up' === $aicfab_err->get_error_message(),
	'The tool message is carried through.'
);

$GLOBALS['aicfab_next'] = array( 'structuredContent' => array( 'answer' => 42 ) );
$aicfab_struct          = $aicfab_client->call_tool( 'docs', 'search_docs' );
check_mcp( is_array( $aicfab_struct ) && isset( $aicfab_struct['answer'] ) && 42 === $aicfab_struct['answer'], 'structuredContent is returned as-is.' );

$GLOBALS['aicfab_next'] = array(
	'content' => array(
		array( 'type' => 'text', 'text' => 'first' ),
		array( 'type' => 'image', 'text' => 'ignored' ),
		array( 'type' => 'text', 'text' => 'second' ),
	),
);
$aicfab_text = $aicfab_client->call_tool( 'docs', 'search_docs' );
check_mcp(
	is_array( $aicfab_text ) && isset( $aicfab_text['text'] ) && "first\nsecond" === $aicfab_text['text'],
	'Text blocks are joined and non-text blocks skipped.'
);

$GLOBALS['aicfab_next'] = array( 'content' => array( array( 'type' => 'text', 'text' => str_repeat( 'y', 30000 ) ) ) );
$aicfab_long            = $aicfab_client->call_tool( 'docs', 'search_docs' );
$aicfab_long_text = is_array( $aicfab_long ) && isset( $aicfab_long['text'] ) ? $aicfab_long['text'] : '';
check_mcp( '' !== $aicfab_long_text, 'A text result comes back as text.' );
check_mcp( strlen( $aicfab_long_text ) <= 20000, 'A huge tool result is truncated, got ' . strlen( $aicfab_long_text ) );

// --- Names and defaults -------------------------------------------------------

$aicfab_parsed = $aicfab_client->parse_tool_name( 'docs___search_docs' );
check_mcp( 'docs' === $aicfab_parsed['server_name'] && 'search_docs' === $aicfab_parsed['tool_name'], 'A prefixed tool name splits into server and tool.' );
$aicfab_bare = $aicfab_client->parse_tool_name( 'search_docs' );
check_mcp( '' === $aicfab_bare['server_name'] && '' === $aicfab_bare['tool_name'], 'A name without the separator resolves to nothing.' );

$aicfab_all = $aicfab_client->get_all_tools();
check_mcp( ! empty( $aicfab_all ) && isset( $aicfab_all[0]['name'] ) && 'docs___search_docs' === $aicfab_all[0]['name'], 'Listed tools carry their server prefix.' );

check_mcp( array( 'type' => 'none' ) === $aicfab_client->server_auth( 'docs' ), 'A server registered without auth defaults to none.' );
check_mcp( array( 'type' => 'none' ) === $aicfab_client->server_auth( 'missing' ), 'An unknown server reports no auth rather than failing.' );
check_mcp( true === $aicfab_client->set_server_auth( 'docs', array( 'type' => 'sigv4' ) ), 'Auth can be changed on a registered server.' );
$aicfab_stored_auth = $aicfab_client->server_auth( 'docs' );
check_mcp( isset( $aicfab_stored_auth['type'] ) && 'sigv4' === $aicfab_stored_auth['type'], 'The change is stored.' );
check_mcp( false === $aicfab_client->set_server_auth( 'missing', array( 'type' => 'bearer' ) ), 'Auth cannot be set on a server that is not registered.' );

// The configured auth must actually travel with the call, or bearer and sigv4 do nothing.
$GLOBALS['aicfab_transport'] = array();
$aicfab_client->call_tool( 'docs', 'search_docs' );
check_mcp(
	isset( $GLOBALS['aicfab_transport'][0]['auth']['type'] ) && 'sigv4' === $GLOBALS['aicfab_transport'][0]['auth']['type'],
	'The stored auth is passed to the transport.'
);

check_mcp( true === $aicfab_client->unregister_server( 'docs' ), 'A server can be removed.' );
check_mcp( false === $aicfab_client->unregister_server( 'docs' ), 'Removing it twice reports failure.' );
check_mcp( array() === $aicfab_client->get_servers(), 'Nothing is left behind.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: MCP client checks passed\n";
