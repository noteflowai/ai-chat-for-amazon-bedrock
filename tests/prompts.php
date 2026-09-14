<?php
/**
 * Standalone tests for Amazon Bedrock Prompt Management integration.
 *
 * Run: php tests/prompts.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options']    = array();
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_prompt_calls'] = 0;
$GLOBALS['aicfab_prompt_result'] = array();

// --- WordPress stubs -------------------------------------------------------

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['aicfab_transients'] ) ? $GLOBALS['aicfab_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['aicfab_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['aicfab_transients'][ $key ] );
	return true;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function __( $text, $domain = null ) {
	return $text;
}
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}
function get_bloginfo( $field ) {
	return 'name' === $field ? 'Bedrock Demo' : 'Just another site';
}
function home_url( $path = '/' ) {
	return 'https://example.test' . $path;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
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

class AI_Chat_Bedrock_Security {
	public static function string_substr( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length ) : substr( (string) $value, $start, $length );
	}
}

class AI_Chat_Bedrock_AWS {
	public function get_prompt( $identifier, $version = '' ) {
		$GLOBALS['aicfab_prompt_calls']++;
		$result = $GLOBALS['aicfab_prompt_result'];
		return $result instanceof WP_Error ? $result : $result;
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-prompts.php';

$failures = array();
function check_prompt( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function configure( $id, $version = '' ) {
	$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array( 'prompt_id' => $id, 'prompt_version' => $version );
}

// --- Identifier and version validation ------------------------------------

configure( '' );
check_prompt( false === AI_Chat_Bedrock_Prompts::enabled(), 'No identifier means the integration is off.' );

configure( 'bad id with spaces' );
check_prompt( '' === AI_Chat_Bedrock_Prompts::identifier(), 'A malformed identifier is refused.' );

configure( 'arn:aws:bedrock:us-east-1:123456789012:prompt/ABC123', '2' );
check_prompt( 'arn:aws:bedrock:us-east-1:123456789012:prompt/ABC123' === AI_Chat_Bedrock_Prompts::identifier(), 'An ARN is accepted.' );
check_prompt( '2' === AI_Chat_Bedrock_Prompts::version(), 'A numeric version is accepted.' );

configure( 'ABC123', 'v2' );
check_prompt( '' === AI_Chat_Bedrock_Prompts::version(), 'A malformed version falls back to the draft.' );

configure( 'ABC123', 'DRAFT' );
check_prompt( 'DRAFT' === AI_Chat_Bedrock_Prompts::version(), 'DRAFT is accepted as a version.' );

// --- Variant selection ----------------------------------------------------

$response = array(
	'defaultVariant' => 'production',
	'variants'       => array(
		array( 'name' => 'experiment', 'templateConfiguration' => array( 'text' => array( 'text' => 'Experimental wording.' ) ) ),
		array( 'name' => 'production', 'templateConfiguration' => array( 'text' => array( 'text' => 'Production wording.' ) ) ),
	),
);
check_prompt( 'Production wording.' === AI_Chat_Bedrock_Prompts::template_from( $response ), 'The default variant is used, not the first one.' );

$single = array( 'variants' => array( array( 'templateConfiguration' => array( 'text' => array( 'text' => 'Only one.' ) ) ) ) );
check_prompt( 'Only one.' === AI_Chat_Bedrock_Prompts::template_from( $single ), 'A single unnamed variant is used.' );
check_prompt( '' === AI_Chat_Bedrock_Prompts::template_from( array() ), 'An empty response yields no template.' );
check_prompt( '' === AI_Chat_Bedrock_Prompts::template_from( array( 'variants' => array( array( 'templateType' => 'CHAT' ) ) ) ), 'A variant without text yields no template.' );

// --- Variable rendering ---------------------------------------------------

$rendered = AI_Chat_Bedrock_Prompts::render( 'You are the assistant for {{site_name}} at {{ site_url }}. Today is {{current_date}}. Keep {{tone}} intact.' );
check_prompt( false !== strpos( $rendered, 'Bedrock Demo' ), 'The site name is substituted.' );
check_prompt( false !== strpos( $rendered, 'https://example.test/' ), 'Spaced variables are substituted.' );
check_prompt( false !== strpos( $rendered, gmdate( 'Y-m-d' ) ), 'The current date is substituted.' );
check_prompt( false !== strpos( $rendered, '{{tone}}' ), 'An unknown variable is left untouched rather than guessed.' );
check_prompt( array( 'tone' ) === AI_Chat_Bedrock_Prompts::unresolved( $rendered ), 'Unresolved variables are reported for the settings screen.' );
check_prompt( array() === AI_Chat_Bedrock_Prompts::unresolved( 'No variables here.' ), 'A fully resolved prompt reports nothing unresolved.' );

// --- Fetching and caching -------------------------------------------------

configure( 'ABC123', '1' );
$GLOBALS['aicfab_transients']     = array();
$GLOBALS['aicfab_prompt_calls']   = 0;
$GLOBALS['aicfab_prompt_result']  = array(
	'variants' => array( array( 'templateConfiguration' => array( 'text' => array( 'text' => 'Answer for {{site_name}} only.' ) ) ) ),
);

$text = AI_Chat_Bedrock_Prompts::text();
check_prompt( 'Answer for Bedrock Demo only.' === $text, 'The fetched template is rendered.' );
check_prompt( 1 === $GLOBALS['aicfab_prompt_calls'], 'Fetching calls the API once.' );

$again = AI_Chat_Bedrock_Prompts::text();
check_prompt( 'Answer for Bedrock Demo only.' === $again, 'A cached prompt returns the same text.' );
check_prompt( 1 === $GLOBALS['aicfab_prompt_calls'], 'A cached prompt costs no second API call.' );

AI_Chat_Bedrock_Prompts::flush();
AI_Chat_Bedrock_Prompts::text();
check_prompt( 2 === $GLOBALS['aicfab_prompt_calls'], 'Flushing forces a refetch.' );

// A different version must not reuse another version's cache.
configure( 'ABC123', '2' );
AI_Chat_Bedrock_Prompts::text();
check_prompt( 3 === $GLOBALS['aicfab_prompt_calls'], 'Each version is cached separately.' );

// --- Failure must not empty the system prompt -----------------------------

configure( 'ABC123', '1' );
$GLOBALS['aicfab_transients']    = array();
$GLOBALS['aicfab_prompt_result'] = new WP_Error( 'aicfab_forbidden', 'not authorized' );

$result = AI_Chat_Bedrock_Prompts::text();
check_prompt( is_wp_error( $result ) && 'aicfab_forbidden' === $result->get_error_code(), 'A failed fetch reports the error.' );
check_prompt( 'Local fallback prompt.' === AI_Chat_Bedrock_Prompts::system_prompt( 'Local fallback prompt.' ), 'A failed fetch keeps the local system prompt.' );

$GLOBALS['aicfab_prompt_result'] = array( 'variants' => array( array( 'templateConfiguration' => array( 'text' => array( 'text' => '   ' ) ) ) ) );
$GLOBALS['aicfab_transients']    = array();
check_prompt( 'Local fallback prompt.' === AI_Chat_Bedrock_Prompts::system_prompt( 'Local fallback prompt.' ), 'An empty template keeps the local system prompt.' );

configure( '' );
check_prompt( 'Local fallback prompt.' === AI_Chat_Bedrock_Prompts::system_prompt( 'Local fallback prompt.' ), 'With no managed prompt the local one is used unchanged.' );

// --- Length cap -----------------------------------------------------------

$GLOBALS['aicfab_transients']    = array();
configure( 'ABC123', '1' );
$GLOBALS['aicfab_prompt_result'] = array( 'variants' => array( array( 'templateConfiguration' => array( 'text' => array( 'text' => str_repeat( 'x', AI_Chat_Bedrock_Prompts::MAX_CHARS + 500 ) ) ) ) ) );
$long = AI_Chat_Bedrock_Prompts::text();
check_prompt( AI_Chat_Bedrock_Prompts::MAX_CHARS === strlen( $long ), 'A long prompt is capped.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: managed prompt checks passed\n";
