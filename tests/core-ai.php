<?php
/**
 * Standalone tests for the WordPress core AI Client and Connectors integration.
 *
 * Core's AI Client is not available here, so the behavioural half covers the part that
 * runs without it: the guard that keeps the whole integration inert on older WordPress,
 * and the governance decision that core's filter and the model both consult. The rest is
 * asserted against the source, because the properties that matter are structural: what
 * the connector declares about credentials, and that declared options match honoured ones.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_filters'] = array();
$GLOBALS['aicfab_actions'] = array();

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) {
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

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['aicfab_filters'][] = $hook;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['aicfab_actions'][] = $hook;
}
function __( $text, $domain = '' ) {
	return $text;
}
function esc_html__( $text, $domain = '' ) {
	return $text;
}
function esc_html( $text ) {
	return $text;
}

/** Stands in for the plugin's usage accounting. */
class AI_Chat_Bedrock_Usage {
	public static $limit_reached = false;
	public static $calls = 0;
	public static function daily_limit_reached( $options = null ) {
		self::$calls++;
		return self::$limit_reached;
	}
}

define( 'AI_CHAT_BEDROCK_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-core-ai.php';

$failures = array();
function check_core_ai( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// --- Inert without core support -------------------------------------------------

// Neither wp_ai_client_prompt() nor wp_get_connectors() exists in this process, which is
// what WordPress 6.x looks like. Nothing may be hooked, or every site below 7.0 pays for
// an integration it cannot use.
AI_Chat_Bedrock_Core_AI::init();
check_core_ai( false === AI_Chat_Bedrock_Core_AI::core_ai_available(), 'Core AI must be reported unavailable when the API is absent.' );
check_core_ai( false === AI_Chat_Bedrock_Core_AI::connectors_available(), 'Connectors must be reported unavailable when the API is absent.' );
check_core_ai( array() === $GLOBALS['aicfab_filters'], 'No filter may be added when core has no AI API.' );
check_core_ai( array() === $GLOBALS['aicfab_actions'], 'No action may be added when core has no AI API.' );

// --- The governance decision ----------------------------------------------------

AI_Chat_Bedrock_Usage::$limit_reached = false;
check_core_ai( true === AI_Chat_Bedrock_Core_AI::can_generate(), 'A site within its limit must be allowed to generate.' );
check_core_ai( false === AI_Chat_Bedrock_Core_AI::prevent_prompt( false ), 'A permitted call must not be prevented.' );

AI_Chat_Bedrock_Usage::$limit_reached = true;
$blocked = AI_Chat_Bedrock_Core_AI::can_generate();
check_core_ai( is_wp_error( $blocked ), 'A site over its daily limit must be refused.' );
check_core_ai( 'aicfab_daily_limit' === $blocked->get_error_code(), 'The refusal must name the daily limit.' );
check_core_ai( true === AI_Chat_Bedrock_Core_AI::prevent_prompt( false ), 'Core must be told to prevent a call over the limit.' );

// Another plugin's decision to block stands; this one only ever raises the bar.
AI_Chat_Bedrock_Usage::$limit_reached = false;
check_core_ai( true === AI_Chat_Bedrock_Core_AI::prevent_prompt( true ), 'A prior filter decision to prevent must be preserved.' );

// The check must not spend anything: core runs this filter for support probes too, and a
// plugin asking whether a feature exists must not consume a user's budget.
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-core-ai.php' );
check_core_ai(
	false === strpos( $source, 'AI_Chat_Bedrock_Usage::record' ),
	'The pre-flight check must not record usage; core also runs it for support probes.'
);

// --- What the connector declares ------------------------------------------------

// Bedrock signs with IAM, so core must be told it holds no credential. Declaring an API
// key would put a secret field in front of site owners for a secret that should not exist.
check_core_ai(
	1 === preg_match( "/'authentication'\s*=>\s*array\(\s*'method'\s*=>\s*'none'/", $source ),
	'The connector must declare that WordPress stores no credential for Bedrock.'
);
check_core_ai(
	false === strpos( $source, "'method' => 'api_key'" ),
	'The connector must not claim API key authentication.'
);
check_core_ai(
	false !== strpos( $source, 'wp_is_connector_registered' ),
	'Registration must not collide with an existing connector of the same id.'
);

/*
 * A credential-free connector is registered but not rendered on the WordPress 7.1
 * Settings -> Connectors screen. Measured, not assumed: two registrations of the same
 * shape, one declaring api_key and one none, produced a card for the first only. 1.30.0
 * shipped a readme claiming the card appears, so the claim is pinned here.
 */
$readme = file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
check_core_ai(
	1 !== preg_match( '/(appears|appear|shown|listed) in Settings > Connectors/i', $readme ),
	'The readme must not claim Bedrock appears on the Connectors screen; a credential-free connector is not rendered.'
);
check_core_ai(
	false !== strpos( $source, 'It does not appear on the Settings' ),
	'The code must record that the connector is registered for the registry, not the screen.'
);
// Adding a plugin entry does not change it either, and was tried.
check_core_ai(
	false === strpos( $source, "'plugin'" ),
	'No plugin entry: it was added to force the card to render, and it did not.'
);

// --- Declared options must match honoured ones ----------------------------------

$provider = '';
foreach ( glob( dirname( __DIR__ ) . '/includes/core-ai/*.php' ) as $file ) {
	$provider .= file_get_contents( $file );
}

// Every option the model advertises has to be read somewhere, or core hands over a request
// that is then silently ignored, which is worse than core reporting no matching model.
$declared = array();
if ( preg_match_all( '/new SupportedOption\(\s*OptionEnum::([a-zA-Z]+)\(\)/', $provider, $matches ) ) {
	$declared = $matches[1];
}
check_core_ai( ! empty( $declared ), 'The model must declare the options it supports.' );
$honoured = array(
	'inputModalities'   => 'flatten',           // Text parts only; anything else is refused.
	'outputModalities'  => 'MessagePart',       // The result is a text part.
	'systemInstruction' => 'getSystemInstruction',
	'maxTokens'         => 'getMaxTokens',
	'temperature'       => 'getTemperature',
	'candidateCount'    => 'array( 1 )',        // Constrained to one, which is what Bedrock returns.
);
foreach ( $declared as $option ) {
	check_core_ai(
		isset( $honoured[ $option ] ) && false !== strpos( $provider, $honoured[ $option ] ),
		sprintf( 'Declared option %s must be honoured by the adapter.', $option )
	);
}
// Asking for more than one candidate must fail rather than quietly return one.
check_core_ai(
	1 === preg_match( '/candidateCount\(\)\s*,\s*array\(\s*1\s*\)/', $provider ),
	'Candidate count must be constrained to the single candidate Bedrock returns.'
);

// A prompt part that cannot be represented must be refused, not reduced to its text.
check_core_ai(
	false !== strpos( $provider, 'cannot represent' ) && false !== strpos( $provider, 'throw new RuntimeException' ),
	'A prompt containing an unrepresentable part must be refused.'
);

// The adapter must reuse the plugin's request path, which is where the guardrail, region,
// token ceiling and usage accounting already live. Talking to Bedrock directly here would
// mean core AI calls escaped all of it.
check_core_ai(
	false !== strpos( $provider, 'handle_chat_message' ),
	'The adapter must route through the plugin request path so site governance applies.' );
check_core_ai(
	false === strpos( $provider, 'wp_remote_post' ) && false === strpos( $provider, 'sign_request' ),
	'The adapter must not call Bedrock directly and bypass the governed path.'
);

// A caller can obtain a model object without the prompt builder, so the model checks too.
check_core_ai(
	false !== strpos( $provider, 'AI_Chat_Bedrock_Core_AI::can_generate()' ),
	'The model must apply the site gate itself, not rely on core running the filter.'
);

// The model that actually answered is reported, so a fallback is not attributed elsewhere.
check_core_ai(
	false !== strpos( $provider, "fallback_model" ),
	'A fallback model must be reported as the model that ran.'
);

if ( $failures ) {
	echo "FAILED\n";
	foreach ( $failures as $failure ) {
		echo "- $failure\n";
	}
	exit( 1 );
}
echo "OK: core AI client and connector integration checks passed\n";
