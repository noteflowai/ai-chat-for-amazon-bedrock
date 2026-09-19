<?php
/**
 * Server-Sent Events framing, against the real class.
 *
 * Nothing loaded AI_Chat_Bedrock_SSE. Everything a visitor sees during a streamed answer passes
 * through send(), and the text inside it is model output, retrieved page content and tool results:
 * none of which the site controls. In the SSE wire format a blank line ends an event and a line
 * beginning "event:" starts one, so text carrying those sequences could forge frames, terminate
 * the stream early, or inject an event the client would act on. JSON encoding is what prevents
 * that, and nothing asserted it.
 *
 * flush_output is overridden below because the real one calls ob_flush, which pushes the bytes
 * past any buffer the test installs and leaves nothing to inspect.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride

define( 'ABSPATH', __DIR__ );

$failures = array();
function check_sse( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function nocache_headers() {}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function __( $text, $domain = null ) {
	return $text;
}
function current_user_can( $capability ) {
	return ! empty( $GLOBALS['aicfab_caps'][ $capability ] );
}
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['aicfab_routes'][] = array( 'namespace' => $namespace, 'route' => $route, 'args' => $args );
	return true;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function get_option( $name, $default_value = false ) {
	return $default_value;
}

class WP_Error {
	private $code;
	private $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_data() {
		return $this->data;
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-sse.php';

/**
 * The real framing, with flushing disabled so the bytes can be read.
 */
class Aicfab_Readable_SSE extends AI_Chat_Bedrock_SSE {
	public function flush_output() {}
}

function aicfab_emit( $type, $data ) {
	$sse = new Aicfab_Readable_SSE();
	ob_start();
	$sse->send( $type, $data );
	return ob_get_clean();
}

/**
 * How many events a client would parse out of these bytes.
 */
function aicfab_frames( $bytes ) {
	return preg_match_all( '/^event: /m', $bytes );
}

// --- Text that looks like the wire format cannot forge a frame ------------------

$aicfab_hostile = "Here is your answer.\n\nevent: close\ndata: {\"forged\":true}\n\n";
$aicfab_out     = aicfab_emit( 'delta', array( 'text' => $aicfab_hostile ) );

check_sse( '' !== $aicfab_out, 'A delta produces output.' );
check_sse( 1 === aicfab_frames( $aicfab_out ), 'Hostile text yields exactly one event, got ' . aicfab_frames( $aicfab_out ) );
check_sse(
	false === strpos( $aicfab_out, "\nevent: close" ),
	'A close event cannot be forged from inside the payload.'
);
check_sse(
	1 === substr_count( $aicfab_out, "\n\n" ),
	'There is one frame boundary, got ' . substr_count( $aicfab_out, "\n\n" )
);
check_sse(
	false !== strpos( $aicfab_out, '\n\nevent: close' ),
	'The hostile text survives inside the payload, escaped rather than removed.'
);
check_sse(
	false === strpos( $aicfab_out, "forged\":true}\n" ),
	'No raw payload braces reach the wire as their own line.'
);

// A payload that is nothing but frame separators is still one frame.
$aicfab_separators = aicfab_emit( 'delta', array( 'text' => "\n\n\n\n\n\n" ) );
check_sse( 1 === aicfab_frames( $aicfab_separators ), 'A payload of only newlines is one event.' );
check_sse( 1 === substr_count( $aicfab_separators, "\n\n" ), 'And has one boundary.' );

// Carriage returns are a frame separator too in some parsers.
$aicfab_crlf = aicfab_emit( 'delta', array( 'text' => "a\r\n\r\nevent: close\r\n\r\n" ) );
check_sse( 1 === aicfab_frames( $aicfab_crlf ), 'CRLF sequences do not split the frame either.' );
check_sse( false === strpos( $aicfab_crlf, "\r" ), 'A raw carriage return never reaches the wire.' );

// --- The event name is a key, whatever the caller passes -----------------------

$aicfab_bad_name = aicfab_emit( "close\ndata: {}", array( 'x' => 1 ) );
check_sse( 1 === aicfab_frames( $aicfab_bad_name ), 'A hostile event name yields one event.' );
check_sse( false === strpos( $aicfab_bad_name, "event: close\n" ), 'It is not emitted as a close event.' );
check_sse(
	(bool) preg_match( '/^event: [a-z0-9_\-]*$/m', $aicfab_bad_name ),
	'The event name on the wire contains only key characters.'
);

// The type travels in the payload as well, and matches the event line.
$aicfab_typed = aicfab_emit( 'Delta-One!', array( 'text' => 'x' ) );
preg_match( '/^event: (.*)$/m', $aicfab_typed, $aicfab_line );
preg_match( '/"type":"([^"]*)"/', $aicfab_typed, $aicfab_payload_type );
check_sse( ! empty( $aicfab_line[1] ), 'The event line names the event.' );
check_sse(
	isset( $aicfab_payload_type[1] ) && $aicfab_line[1] === $aicfab_payload_type[1],
	'The event name and the payload type agree, got ' . ( $aicfab_line[1] ?? '?' ) . ' and ' . ( $aicfab_payload_type[1] ?? '?' )
);

// A caller cannot override the type by putting one in the data.
$aicfab_override = aicfab_emit( 'delta', array( 'type' => 'close', 'text' => 'x' ) );
check_sse(
	false !== strpos( $aicfab_override, '"type":"delta"' ),
	'The event name wins over a type supplied in the payload.'
);
check_sse( false === strpos( $aicfab_override, '"type":"close"' ), 'The supplied type does not survive.' );

// --- Unsendable data sends nothing rather than a broken frame ------------------

$aicfab_invalid = aicfab_emit( 'delta', array( 'text' => "valid\xC3\x28invalid" ) );
check_sse( '' === $aicfab_invalid, 'A payload that cannot be encoded emits nothing at all.' );

// Non-array data is treated as empty rather than concatenated in.
$aicfab_scalar = aicfab_emit( 'delta', 'just a string' );
check_sse( 1 === aicfab_frames( $aicfab_scalar ), 'Non-array data still yields one well-formed event.' );
check_sse( false === strpos( $aicfab_scalar, 'just a string' ), 'A scalar payload is dropped, not pasted into the frame.' );

// --- Text in other languages is not mangled -----------------------------------

$aicfab_unicode = aicfab_emit( 'delta', array( 'text' => '退款政策は21日です' ) );
check_sse(
	false !== strpos( $aicfab_unicode, '退款政策は21日です' ),
	'Unicode is sent as itself rather than as escape sequences.'
);
check_sse( false === strpos( $aicfab_unicode, '\u9000' ), 'No escaped code points appear.' );

// Slashes are not escaped either, so a URL in an answer stays readable.
$aicfab_url = aicfab_emit( 'delta', array( 'text' => 'See https://example.com/refunds' ) );
check_sse( false !== strpos( $aicfab_url, 'https://example.com/refunds' ), 'A URL is not slash-escaped.' );

/*
 * open() is deliberately not asserted here. It closes every output buffer it can find, including
 * one this file would install to read it, so it cannot be observed from inside the same process.
 * That teardown is the point of the method: it is what stops a proxy holding the stream. The part
 * that carries untrusted text is send(), which is what is covered above.
 */

// --- The generator stream is for people who may create drafts -----------------

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-wp-mcp-server.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-generator-stream.php';

$GLOBALS['aicfab_caps'] = array();
$aicfab_gen             = new AI_Chat_Bedrock_Generator_Stream();
$aicfab_denied          = $aicfab_gen->check_permission();
check_sse( $aicfab_denied instanceof WP_Error, 'Generating a draft is refused without edit_posts.' );
check_sse(
	$aicfab_denied instanceof WP_Error && 'aicfab_forbidden' === $aicfab_denied->get_error_code(),
	'The refusal has its own code.'
);
$aicfab_data = $aicfab_denied instanceof WP_Error ? $aicfab_denied->get_error_data() : array();
check_sse( isset( $aicfab_data['status'] ) && 403 === $aicfab_data['status'], 'It answers 403.' );

$GLOBALS['aicfab_caps'] = array( 'edit_posts' => true );
check_sse( true === $aicfab_gen->check_permission(), 'An editor may generate a draft.' );

$GLOBALS['aicfab_caps'] = array( 'read' => true );
check_sse( $aicfab_gen->check_permission() instanceof WP_Error, 'A subscriber may not.' );

/*
 * This route has no nonce of its own, unlike the chat stream. That is deliberate: it is reachable
 * only by an authenticated user, and WordPress already requires X-WP-Nonce for cookie
 * authentication on REST requests. The chat stream carries its own nonce because it can serve
 * visitors who have no cookie at all. What must hold is that the route is never simply open.
 */
$GLOBALS['aicfab_routes'] = array();
$aicfab_gen->register_routes();
check_sse( ! empty( $GLOBALS['aicfab_routes'] ), 'The generator route is registered.' );
foreach ( $GLOBALS['aicfab_routes'] as $aicfab_route ) {
	$aicfab_args = $aicfab_route['args'];
	check_sse( isset( $aicfab_args['permission_callback'] ), 'The generator route has a permission callback.' );
	check_sse( '__return_true' !== $aicfab_args['permission_callback'], 'The generator route is never anonymously open.' );
	check_sse(
		is_array( $aicfab_args['permission_callback'] ) && 'check_permission' === $aicfab_args['permission_callback'][1],
		'It uses the capability check asserted above.'
	);
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: SSE framing checks passed\n";
