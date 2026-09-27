<?php
/**
 * Content-gap CSV: real report, registered action, access checks and response body.
 * Run: php tests/test_feature_9ecd4de5ba50.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['gap_options'] = array( 'ai_chat_bedrock_log_retention_days' => 90 );
$GLOBALS['gap_allowed'] = true;
$GLOBALS['gap_nonce'] = true;
$GLOBALS['gap_forbid_reads'] = false;
$GLOBALS['gap_actions'] = array();
$GLOBALS['gap_now'] = isset( $argv[2] ) ? (int) $argv[2] : time();

class GapAccessDenied extends RuntimeException {}
function get_option( $name, $default = false ) {
	if ( 'ai_chat_bedrock_conversations' === $name && $GLOBALS['gap_forbid_reads'] ) {
		throw new RuntimeException( 'Conversation data read before access checks' );
	}
	return array_key_exists( $name, $GLOBALS['gap_options'] ) ? $GLOBALS['gap_options'][ $name ] : $default;
}
function apply_filters( $hook, $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $text, $domain = null ) { return $text; }
function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $text ) { return esc_html( $text ); }
function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function current_user_can( $capability ) { return 'manage_options' === $capability && $GLOBALS['gap_allowed']; }
function wp_die( $message, $title = '', $args = array() ) {
	throw new GapAccessDenied( $message, $args['response'] ?? 0 );
}
function check_admin_referer( $action ) {
	if ( 'ai_chat_bedrock_export_gaps' !== $action || ! $GLOBALS['gap_nonce'] ) {
		throw new GapAccessDenied( 'Invalid nonce', 403 );
	}
}
function nocache_headers() {}
function add_action( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['gap_actions'][ $name ] = $callback; }
function add_filter( $name, $callback, $priority = 10, $args = 1 ) {}
function add_shortcode( $name, $callback ) {}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function get_admin_page_title() { return 'Conversations'; }
function number_format_i18n( $number ) { return number_format( $number ); }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
// Enough of the list view's helpers to render stored entries.
function wp_trim_words( $text, $words = 55 ) { return implode( ' ', array_slice( preg_split( '/\s+/', (string) $text ), 0, $words ) ); }
function wp_kses_post( $html ) { return (string) $html; }
function wp_unslash( $value ) { return $value; }
function selected( $a, $b, $echo = true ) { $out = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $out; } return $out; }
function paginate_links( $args = array() ) { return ''; }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( 'nonce-' . $action ) . '">';
}

// Only unrelated WordPress/plugin services are replaced. Report, storage reads,
// hook registration, admin handler, view and CSV serialization are real code.
class AI_Chat_Bedrock_Security {}
class AI_Chat_Bedrock_Embeddings { const CRON_HOOK = 'test_embeddings_cron'; }
class AI_Chat_Bedrock_Diagnostics {}
require __DIR__ . '/../includes/class-ai-chat-bedrock-conversations.php';
require __DIR__ . '/../includes/class-ai-chat-bedrock-insights.php';
require __DIR__ . '/../admin/class-ai-chat-bedrock-admin.php';
require __DIR__ . '/../includes/class-ai-chat-bedrock-loader.php';
require __DIR__ . '/../includes/class-ai-chat-bedrock.php';

function gap_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function gap_seed( $question, $grounded = false, $rating = 0, $age = 100, $source = 'chat' ) {
	$GLOBALS['gap_options']['ai_chat_bedrock_conversations'][] = array(
		'time' => $GLOBALS['gap_now'] - $age,
		'question' => $question,
		'grounded' => $grounded,
		'rating' => $rating,
		'source' => $source,
	);
}
function gap_fixture() {
	$GLOBALS['gap_options']['ai_chat_bedrock_conversations'] = array();
	gap_seed( 'How are robot policies evaluated?', false, 0, 300 );
	gap_seed( 'How are robot policies evaluated?', false, 0, 200 );
	gap_seed( 'How are robot policies evaluated?', true, -1, 100 );
	gap_seed( 'How are robot policies evaluated?', true, 1, 50 ); // Not a gap contributor.
	gap_seed( "Explain \"force, torque\" at C:\\logs\\\"trial\"\nNext line", false, 0, 80 );
	gap_seed( '=SUM(1,2)', false, 0, 70 );
	gap_seed( '+SUM(2,3)', false, 0, 60 );
	gap_seed( '-SUM(3,4)', false, 0, 50 );
	gap_seed( '@SUM(4,5)', false, 0, 40 );
	gap_seed( 'Obsolete robot experiment', false, 0, 40 * DAY_IN_SECONDS );
	gap_seed( 'Generated editorial draft', false, 0, 20, 'editor' );
}

// Register through the real core hook method without booting unrelated services.
$core_type = new ReflectionClass( 'AI_Chat_Bedrock' );
$core = $core_type->newInstanceWithoutConstructor();
$loader = new AI_Chat_Bedrock_Loader();
$property = $core_type->getProperty( 'loader' );
$property->setAccessible( true );
$property->setValue( $core, $loader );
$method = $core_type->getMethod( 'define_admin_hooks' );
$method->setAccessible( true );
$method->invoke( $core );
$loader->run();

$action = 'admin_post_ai_chat_bedrock_export_gaps';
gap_check( isset( $GLOBALS['gap_actions'][ $action ] ), 'The CSV download must be registered as an admin-post action' );
$callback = $GLOBALS['gap_actions'][ $action ];
gap_check( is_callable( $callback ), 'The registered export callback must exist' );
gap_check( ! isset( $GLOBALS['gap_actions']['admin_post_nopriv_ai_chat_bedrock_export_gaps'] ), 'No anonymous download route' );

gap_fixture();
$mode = $argv[1] ?? '';
if ( 'download' === $mode || 'download-empty' === $mode ) {
	if ( 'download-empty' === $mode ) {
		$GLOBALS['gap_options']['ai_chat_bedrock_conversations'] = array();
	}
	call_user_func( $callback ); // Real handler streams its response and exits.
	throw new RuntimeException( 'The download handler did not terminate the response' );
}

$header = array( 'question', 'occurrences', 'last_asked_utc', 'grounded_count', 'ungrounded_count' );
$before = $GLOBALS['gap_options'];
$rows = AI_Chat_Bedrock_Insights::export_rows();
$gaps = AI_Chat_Bedrock_Insights::content_gaps();
gap_check( $rows[0] === $header, 'Export has the documented header' );
gap_check( count( $rows ) === 7, 'Only retained visitor gap groups in the 30-day window are exported' );
gap_check( $rows[1] === array( 'How are robot policies evaluated?', 3, gmdate( 'Y-m-d H:i:s', $GLOBALS['gap_now'] - 100 ), 1, 2 ), 'Grouping, UTC time and grounding counts describe gap contributors' );
gap_check( array_column( array_slice( $rows, 1 ), 0 ) === array_column( $gaps, 'question' ), 'CSV follows the visible report ranking' );
gap_check( count( AI_Chat_Bedrock_Insights::export_rows( array( 'limit' => 1 ) ) ) === 2, 'Report limits are retained' );
gap_check( $GLOBALS['gap_options'] === $before, 'Export does not mutate settings or conversation data' );

// Denied users and invalid/missing nonces must fail before conversation reads.
$GLOBALS['gap_forbid_reads'] = true;
foreach ( array( array( false, false ), array( true, false ) ) as $access ) {
	$GLOBALS['gap_allowed'] = $access[0];
	$GLOBALS['gap_nonce'] = $access[1];
	try {
		call_user_func( $callback );
		throw new RuntimeException( 'Unauthorized download succeeded' );
	} catch ( GapAccessDenied $error ) {
		gap_check( 403 === $error->getCode(), 'Denied exports return the forbidden status' );
	}
}
$GLOBALS['gap_forbid_reads'] = false;
$GLOBALS['gap_allowed'] = true;
$GLOBALS['gap_nonce'] = true;

function gap_download( $mode ) {
	$process = proc_open(
		array( PHP_BINARY, __FILE__, $mode, (string) $GLOBALS['gap_now'] ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$pipes
	);
	gap_check( is_resource( $process ), 'Start the isolated response request' );
	fclose( $pipes[0] );
	$body = stream_get_contents( $pipes[1] );
	$error = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	gap_check( 0 === proc_close( $process ), 'Download request succeeded: ' . $error );
	$stream = fopen( 'php://temp', 'w+' );
	fwrite( $stream, $body );
	rewind( $stream );
	$result = array();
	while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
		$result[] = $row;
	}
	fclose( $stream );
	return $result;
}

$download = gap_download( 'download' );
gap_check( $download[0] === $header && count( $download ) === count( $rows ), 'Real download streams every row with one header' );
foreach ( array_slice( $rows, 1 ) as $index => $row ) {
	$expected = array_map( 'strval', $row );
	if ( in_array( $expected[0][0], array( '=', '+', '-', '@' ), true ) ) {
		$expected[0] = "'" . $expected[0];
	}
	gap_check( $download[ $index + 1 ] === $expected, 'CSV round-trips commas, quotes, backslashes, newlines and neutralizes formulas' );
}
gap_check( gap_download( 'download-empty' ) === array( $header ), 'An empty download still contains the header' );

// Render the actual panel and inspect its form rather than matching PHP source.
function gap_render() {
	$_GET = array();
	ob_start();
	require __DIR__ . '/../admin/partials/ai-chat-bedrock-admin-conversations.php';
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$document->loadHTML( ob_get_clean() );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	return new DOMXPath( $document );
}
$GLOBALS['gap_options']['ai_chat_bedrock_conversations'] = array();
$xpath = gap_render();
gap_check( 0 === $xpath->query( '//form[input[@name="action" and @value="ai_chat_bedrock_export_gaps"]]' )->length, 'With no gaps there is nothing to download, so no button' );
gap_check( false !== strpos( $xpath->document->textContent, 'Nothing to report yet' ), 'The empty report says why it is empty' );

gap_fixture();
$xpath = gap_render();
$forms = $xpath->query( '//form[input[@name="action" and @value="ai_chat_bedrock_export_gaps"]]' );
gap_check( 1 === $forms->length, 'The content-gap panel renders one export form' );
$form = $forms->item( 0 );
gap_check( 'post' === $form->getAttribute( 'method' ), 'The form submits a protected POST' );
gap_check( admin_url( 'admin-post.php' ) === $form->getAttribute( 'action' ), 'The form targets the registered admin-post route' );
gap_check( 1 === $xpath->query( './/input[@name="_wpnonce" and @value="nonce-ai_chat_bedrock_export_gaps"]', $form )->length, 'The form includes the matching download nonce' );
gap_check( false !== strpos( $form->textContent, 'Download content gaps CSV' ), 'The download button has a descriptive accessible name' );
$GLOBALS['gap_allowed'] = false;
ob_start();
require __DIR__ . '/../admin/partials/ai-chat-bedrock-admin-conversations.php';
gap_check( '' === ob_get_clean(), 'The report and export form are hidden from unauthorized users' );
echo "Content-gap CSV acceptance passed.\n";
