<?php
/**
 * Standalone tests for demo mode, which the Live Preview runs in.
 *
 * Run: php tests/demo.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_filter_demo']  = null;
$GLOBALS['aicfab_credentials']  = false;
$GLOBALS['aicfab_passages']     = array();
$GLOBALS['aicfab_search']       = array();
$GLOBALS['aicfab_model_called'] = 0;

// --- WordPress stubs -------------------------------------------------------

function apply_filters( $hook, $value ) {
	if ( 'ai_chat_bedrock_demo_mode' === $hook && null !== $GLOBALS['aicfab_filter_demo'] ) {
		return $GLOBALS['aicfab_filter_demo'];
	}
	return $value;
}
function __( $text, $domain = null ) {
	return $text;
}
function get_option( $name, $fallback = false ) {
	return 'ai_chat_bedrock_settings' === $name
		? array(
			'embedding_model_id'  => 'amazon.titan-embed-text-v2:0',
			'context_results'     => 3,
			'enable_site_context' => true,
		)
		: $fallback;
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
}
function wp_list_pluck( $list, $field ) {
	return array();
}

class AI_Chat_Bedrock_Security {
	public static function string_length( $value ) {
		return mb_strlen( (string) $value );
	}
	public static function string_substr( $value, $start, $length ) {
		return mb_substr( (string) $value, $start, $length );
	}
}

class AI_Chat_Bedrock_Retrieval {
	public static function site_passages( $query, $options ) {
		$GLOBALS['aicfab_search'][] = array(
			'query'   => $query,
			'options' => $options,
		);
		return $GLOBALS['aicfab_passages'];
	}
}

class AI_Chat_Bedrock_AWS {
	public function has_credentials() {
		return (bool) $GLOBALS['aicfab_credentials'];
	}
	public function handle_chat_message( $payload ) {
		++$GLOBALS['aicfab_model_called'];
		return array(
			'success' => true,
			'data'    => array( 'message' => 'from the model' ),
		);
	}
	public function stream_chat_message( $payload, $on_delta ) {
		return $this->handle_chat_message( $payload );
	}
}

class AI_Chat_Bedrock_Tool_Policy {
	public static function max_rounds() {
		return 1;
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-demo.php';
require_once __DIR__ . '/../includes/class-ai-chat-bedrock-tool-runner.php';

$failures = array();
function check_demo( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function reset_demo() {
	$GLOBALS['aicfab_filter_demo']  = null;
	$GLOBALS['aicfab_credentials']  = false;
	$GLOBALS['aicfab_passages']     = array();
	$GLOBALS['aicfab_search']       = array();
	$GLOBALS['aicfab_model_called'] = 0;
}

$hours = array(
	'source'  => 'wordpress',
	'post_id' => 7,
	'title'   => 'Opening hours &amp; location',
	'url'     => 'https://example.test/opening-hours/',
	'excerpt' => "We are open Tuesday to Saturday\n from 7am to 6pm.",
);

// --- Off unless asked for ---------------------------------------------------

reset_demo();
check_demo( ! AI_Chat_Bedrock_Demo::enabled(), 'Demo mode is off on a site that has not asked for it.' );
check_demo( ! AI_Chat_Bedrock_Demo::active( new AI_Chat_Bedrock_AWS() ), 'A site without credentials is not put in demo mode by itself.' );
$response = AI_Chat_Bedrock_Tool_Runner::run( new AI_Chat_Bedrock_AWS(), array(), 'When are you open?' );
check_demo( 1 === $GLOBALS['aicfab_model_called'], 'Outside demo mode the chat still goes to the model, and fails there honestly.' );

$GLOBALS['aicfab_filter_demo'] = true;
check_demo( AI_Chat_Bedrock_Demo::enabled(), 'The ai_chat_bedrock_demo_mode filter turns demo mode on.' );

define( 'AI_CHAT_BEDROCK_DEMO', true );
reset_demo();
check_demo( AI_Chat_Bedrock_Demo::enabled(), 'AI_CHAT_BEDROCK_DEMO turns demo mode on.' );
$GLOBALS['aicfab_filter_demo'] = false;
check_demo( ! AI_Chat_Bedrock_Demo::enabled(), 'The filter can turn demo mode off again.' );

// --- Credentials end demo mode ----------------------------------------------

reset_demo();
check_demo( AI_Chat_Bedrock_Demo::active( new AI_Chat_Bedrock_AWS() ), 'Without credentials, demo mode answers.' );
$GLOBALS['aicfab_credentials'] = true;
check_demo( ! AI_Chat_Bedrock_Demo::active( new AI_Chat_Bedrock_AWS() ), 'Once credentials are found, demo mode stops answering.' );
check_demo( ! AI_Chat_Bedrock_Demo::active(), 'The client it builds for itself is asked about credentials too.' );
$response = AI_Chat_Bedrock_Tool_Runner::run( new AI_Chat_Bedrock_AWS(), array(), 'When are you open?' );
check_demo( 1 === $GLOBALS['aicfab_model_called'] && 'from the model' === $response['data']['message'], 'With credentials, the model answers even when the constant is still defined.' );

// --- A matching page ---------------------------------------------------------

reset_demo();
$GLOBALS['aicfab_passages'] = array( $hours );
$response                   = AI_Chat_Bedrock_Tool_Runner::run( new AI_Chat_Bedrock_AWS(), array( array( 'role' => 'user', 'content' => 'ignored' ) ), 'When are you open?' );
$text                       = isset( $response['data']['message'] ) ? $response['data']['message'] : '';
check_demo( 0 === $GLOBALS['aicfab_model_called'], 'In demo mode no model is called.' );
check_demo( ! empty( $response['success'] ) && ! empty( $response['demo'] ), 'A demo reply is a successful response marked as a demo.' );
check_demo( ! isset( $response['usage'] ), 'A demo reply records no token usage.' );
check_demo( 0 === strpos( $text, '**Demo mode, no AI model was called.**' ), 'The reply opens by saying no model was called.' );
check_demo( false !== strpos( $text, '"Opening hours & location"' ), 'The reply names the page, with entities decoded.' );
check_demo( false !== strpos( $text, 'We are open Tuesday to Saturday from 7am to 6pm.' ), 'The reply quotes the passage, with its whitespace collapsed.' );
check_demo( 'When are you open?' === $GLOBALS['aicfab_search'][0]['query'], 'The visitor message is what is searched for.' );
check_demo( '' === $GLOBALS['aicfab_search'][0]['options']['embedding_model_id'], 'Semantic search, which needs Bedrock, is not attempted.' );
check_demo( 1 === $GLOBALS['aicfab_search'][0]['options']['context_results'], 'Only the best passage is asked for.' );

$GLOBALS['aicfab_passages'] = array( array_merge( $hours, array( 'excerpt' => 'Opening hours & location We are open daily.' ) ) );
$text                       = AI_Chat_Bedrock_Demo::reply( 'hours' )['data']['message'];
check_demo( false !== strpos( $text, "\n\nWe are open daily.\n\n" ), 'A passage that starts with the page title is quoted without it.' );

$GLOBALS['aicfab_passages'] = array( array_merge( $hours, array( 'excerpt' => 'Opening hours & location' ) ) );
$text                       = AI_Chat_Bedrock_Demo::reply( 'hours' )['data']['message'];
check_demo( false !== strpos( $text, "\n\nOpening hours & location\n\n" ), 'A passage that is only the title keeps it.' );

$GLOBALS['aicfab_passages'] = array( array( 'title' => 'Empty', 'excerpt' => '' ), $hours );
$text                       = AI_Chat_Bedrock_Demo::reply( 'hours' )['data']['message'];
check_demo( false !== strpos( $text, 'Opening hours' ), 'A passage without text is skipped for the next one.' );

$GLOBALS['aicfab_passages'] = array( array_merge( $hours, array( 'title' => '' ) ) );
$text                       = AI_Chat_Bedrock_Demo::reply( 'hours' )['data']['message'];
check_demo( false !== strpos( $text, 'This passage from the site matches' ), 'A passage without a title is still introduced.' );

// --- Long passages ------------------------------------------------------------

$GLOBALS['aicfab_passages'] = array( array_merge( $hours, array( 'excerpt' => str_repeat( 'bread rolls, ', 80 ) ) ) );
$text                       = AI_Chat_Bedrock_Demo::reply( 'bread' )['data']['message'];
$parts                      = explode( "\n\n", $text );
check_demo( 3 === count( $parts ), 'The reply has an introduction, the quote and what a connected model adds.' );
check_demo( mb_strlen( $parts[1] ) <= AI_Chat_Bedrock_Demo::MAX_QUOTE_CHARS + 1, 'A long passage is shortened.' );
check_demo( '…' === mb_substr( $parts[1], -1 ) && false === strpos( $parts[1], ',…' ), 'A shortened passage ends in an ellipsis, not in punctuation.' );
check_demo( 1 === preg_match( '/ (bread|rolls)…$/u', $parts[1] ), 'A passage with spaces is cut at a word.' );

$GLOBALS['aicfab_passages'] = array( array_merge( $hours, array( 'excerpt' => str_repeat( '面包每天早上烤制', 80 ) ) ) );
$parts                      = explode( "\n\n", AI_Chat_Bedrock_Demo::reply( '面包' )['data']['message'] );
check_demo( AI_Chat_Bedrock_Demo::MAX_QUOTE_CHARS + 1 === mb_strlen( $parts[1] ), 'Text without spaces is cut at the limit.' );

// --- Nothing matches ----------------------------------------------------------

reset_demo();
$text = AI_Chat_Bedrock_Demo::reply( 'Can I bring my dog?' )['data']['message'];
check_demo( false !== strpos( $text, 'No page on this site matches' ), 'Without a match the reply says so rather than inventing an answer.' );
check_demo( false !== strpos( $text, 'Demo mode' ), 'A reply without a match is labelled too.' );
$text = AI_Chat_Bedrock_Demo::reply( '   ' )['data']['message'];
check_demo( 1 === count( $GLOBALS['aicfab_search'] ), 'An empty question is not searched for.' );

// --- Streaming ----------------------------------------------------------------

reset_demo();
$GLOBALS['aicfab_passages'] = array( $hours );
$deltas                     = array();
$response                   = AI_Chat_Bedrock_Tool_Runner::run(
	new AI_Chat_Bedrock_AWS(),
	array(),
	'When are you open?',
	function ( $delta ) use ( &$deltas ) {
		$deltas[] = $delta;
		return true;
	}
);
check_demo( 3 === count( $deltas ), 'A streamed demo reply arrives one paragraph at a time.' );
check_demo( implode( '', $deltas ) === $response['data']['message'], 'The streamed text adds up to the reply.' );

$deltas = array();
AI_Chat_Bedrock_Demo::reply(
	'When are you open?',
	function ( $delta ) use ( &$deltas ) {
		$deltas[] = $delta;
		return false;
	}
);
check_demo( 1 === count( $deltas ), 'A reader that stops the stream gets no more text.' );

// --- Wiring -------------------------------------------------------------------

$root    = dirname( __DIR__ );
$public  = (string) file_get_contents( $root . '/public/class-ai-chat-bedrock-public.php' );
$main    = (string) file_get_contents( $root . '/includes/class-ai-chat-bedrock.php' );
$admin   = (string) file_get_contents( $root . '/admin/class-ai-chat-bedrock-admin.php' );
$ignore  = (string) file_get_contents( $root . '/.distignore' );
$profile = json_decode( (string) file_get_contents( $root . '/.wordpress-org/blueprints/blueprint.json' ), true );

check_demo( false !== strpos( $public, '! $aws->has_credentials() && ! AI_Chat_Bedrock_Demo::enabled()' ), 'The chat renders in demo mode, so the preview has something to try.' );
check_demo( false !== strpos( $main, "includes/class-ai-chat-bedrock-demo.php'" ), 'The demo class is loaded.' );
check_demo( false !== strpos( $main, "'admin_notices', \$admin, 'render_demo_notice'" ), 'The demo notice is hooked.' );
check_demo( false !== strpos( $admin, "AI_Chat_Bedrock_Demo::enabled() ) {\n\t\t\treturn;" ), 'The setup notice gives way to the demo notice.' );
check_demo( false !== strpos( $ignore, '/.wordpress-org/' ), 'The blueprint is kept out of the plugin package.' );

check_demo( is_array( $profile ), 'The blueprint is valid JSON.' );
$steps = array();
foreach ( is_array( $profile ) && isset( $profile['steps'] ) ? $profile['steps'] : array() as $step ) {
	$steps[ $step['step'] ] = $step;
}
check_demo( isset( $steps['defineWpConfigConsts'] ) && true === $steps['defineWpConfigConsts']['consts']['AI_CHAT_BEDROCK_DEMO'], 'The blueprint turns demo mode on.' );
check_demo( isset( $steps['installPlugin'] ) && 'ai-chat-for-amazon-bedrock' === $steps['installPlugin']['pluginData']['slug'] && ! empty( $steps['installPlugin']['options']['activate'] ), 'The blueprint installs and activates the plugin from the directory.' );
check_demo( isset( $steps['runPHP'] ) && false !== strpos( $steps['runPHP']['code'], '<!-- wp:ai-chat-bedrock/chat /-->' ) && false !== strpos( $steps['runPHP']['code'], "'post_name' => 'ask-us'" ), 'The blueprint creates the page it lands on, with the chat block.' );
check_demo( '/ask-us/' === $profile['landingPage'] && '/%postname%/' === $steps['setSiteOptions']['options']['permalink_structure'], 'The landing page resolves with the permalinks the blueprint sets.' );
check_demo( ! empty( $profile['login'] ), 'The preview is signed in, since the chat is not open to guests by default.' );
check_demo( false !== strpos( $steps['runPHP']['code'], "'enable_site_context' => true" ), 'The blueprint lets the chat use the site\'s pages.' );
check_demo( false !== strpos( $steps['runPHP']['code'], "'aws_use_role_credentials' => false" ), 'The blueprint skips the role credential lookup, which Playground cannot answer.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: demo mode checks passed\n";
