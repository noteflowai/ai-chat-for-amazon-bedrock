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

/*
 * 1.30.0 to 1.62.0 declared `none`, and the WordPress 7.1 Connectors screen renders only
 * connectors with a credential to manage, so Bedrock was in the registry and never on the
 * screen. It now declares an API key, which Bedrock accepts, without making one necessary:
 * an IAM role still answers availability. Exercised against a stand-in registry below.
 */
check_core_ai(
	false !== strpos( $source, 'wp_is_connector_registered' ),
	'Registration must not collide with an existing connector of the same id.'
);

if ( true ) {
	// Defined here, after the inert checks above, so that those still see WordPress 6.x.
	function wp_get_connectors() {
		return array();
	}
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
	class WP_Connector_Registry {
		public $registered = array();
		public function register( $id, $args ) {
			$this->registered[ $id ] = $args;
		}
	}
	/** Stands in for the plugin's secret envelope. */
	class AI_Chat_Bedrock_Security {
		public static function encrypt_secret( $value ) {
			return '' === (string) $value ? '' : 'enc:' . strrev( (string) $value );
		}
		public static function decrypt_secret( $value ) {
			return self::is_encrypted( $value ) ? strrev( substr( (string) $value, 4 ) ) : (string) $value;
		}
		public static function is_encrypted( $value ) {
			return 0 === strpos( (string) $value, 'enc:' );
		}
	}
}
define( 'AI_CHAT_BEDROCK_PLUGIN_FILE', dirname( __DIR__ ) . '/ai-chat-for-amazon-bedrock.php' );

$GLOBALS['aicfab_filters'] = array();
AI_Chat_Bedrock_Core_AI::init();
check_core_ai( in_array( 'pre_update_option_connectors_ai_amazon_bedrock_api_key', $GLOBALS['aicfab_filters'], true ) && in_array( 'option_connectors_ai_amazon_bedrock_api_key', $GLOBALS['aicfab_filters'], true ), 'With Connectors present, the stored key is encrypted on write and decrypted on read.' );

$aicfab_registry = new WP_Connector_Registry();
AI_Chat_Bedrock_Core_AI::register_connector( $aicfab_registry );
$aicfab_connector = isset( $aicfab_registry->registered['amazon-bedrock'] ) ? $aicfab_registry->registered['amazon-bedrock'] : array();
$aicfab_auth      = isset( $aicfab_connector['authentication'] ) ? $aicfab_connector['authentication'] : array();
check_core_ai( 'ai_provider' === ( $aicfab_connector['type'] ?? '' ), 'Bedrock registers as an AI provider.' );
check_core_ai( 'api_key' === ( $aicfab_auth['method'] ?? '' ), 'The connector declares an API key, so the Connectors screen shows it.' );
check_core_ai( 'connectors_ai_amazon_bedrock_api_key' === ( $aicfab_auth['setting_name'] ?? '' ), 'The key is stored under the name core gives the providers it discovers.' );
check_core_ai( 'AI_CHAT_BEDROCK_API_KEY' === ( $aicfab_auth['constant_name'] ?? '' ) && 'AWS_BEARER_TOKEN_BEDROCK' === ( $aicfab_auth['env_var_name'] ?? '' ), 'The screen reports the constant and environment variable the plugin already reads.' );
check_core_ai( 0 === strpos( (string) ( $aicfab_auth['credentials_url'] ?? '' ), 'https://console.aws.amazon.com/bedrock/' ), 'The screen links to where a Bedrock key is created.' );
check_core_ai( '/ai-chat-for-amazon-bedrock.php' === substr( (string) ( $aicfab_connector['plugin']['file'] ?? '' ), -31 ), 'The connector names the plugin that provides it.' );
check_core_ai( false !== stripos( (string) ( $aicfab_connector['description'] ?? '' ), 'IAM role' ), 'The description says an IAM role needs no key.' );

$aicfab_stored = AI_Chat_Bedrock_Core_AI::encrypt_connector_key( 'ABSKexampleKey000000000000' );
check_core_ai( 'ABSKexampleKey000000000000' !== $aicfab_stored && AI_Chat_Bedrock_Security::is_encrypted( $aicfab_stored ), 'The key is not stored as entered.' );
check_core_ai( 'ABSKexampleKey000000000000' === AI_Chat_Bedrock_Core_AI::decrypt_connector_key( $aicfab_stored ), 'Core reads back the key that was entered.' );
check_core_ai( '' === AI_Chat_Bedrock_Core_AI::encrypt_connector_key( '' ), 'Clearing the key stores nothing.' );
$GLOBALS['aicfab_options']['connectors_ai_amazon_bedrock_api_key'] = $aicfab_stored;
check_core_ai( 'ABSKexampleKey000000000000' === AI_Chat_Bedrock_Core_AI::connector_api_key(), 'The plugin reads the connector key, decrypted.' );
unset( $GLOBALS['aicfab_options']['connectors_ai_amazon_bedrock_api_key'] );
check_core_ai( '' === AI_Chat_Bedrock_Core_AI::connector_api_key(), 'No connector key reads as empty.' );

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
	'inputModalities'        => 'flatten',           // Text, and images for vision models; anything else is refused.
	'outputModalities'       => 'MessagePart',       // The result is a text or image part.
	'systemInstruction'      => 'getSystemInstruction',
	'maxTokens'              => 'getMaxTokens',
	'temperature'            => 'getTemperature',
	'candidateCount'         => 'getCandidateCount', // Text models ask once per candidate.
	'stopSequences'          => 'getStopSequences',
	'outputMimeType'         => 'getOutputMimeType',
	'outputSchema'           => 'getOutputSchema',
	'topP'                   => 'getTopP',
	'outputFileType'         => 'new File(',         // Images always come back inline.
	'outputMediaOrientation' => 'getOutputMediaOrientation',
	'outputMediaAspectRatio' => 'getOutputMediaAspectRatio',
	'customOptions'          => 'getCustomOptions',
	'dimensions'             => 'getDimensions',
);
foreach ( array_unique( $declared ) as $option ) {
	check_core_ai(
		isset( $honoured[ $option ] ) && false !== strpos( $provider, $honoured[ $option ] ),
		sprintf( 'Declared option %s must be honoured by the adapter.', $option )
	);
}
// Asking for more candidates than are generated must fail rather than quietly return fewer.
check_core_ai(
	1 === preg_match( '/candidateCount\(\),\s*range\(\s*1,\s*AI_Chat_Bedrock_AI_Model::MAX_CANDIDATES\s*\)/', $provider )
		&& 1 === preg_match( '/candidateCount\(\)\s*,\s*array\(\s*1\s*\)/', $provider ),
	'Candidate counts must be bounded: text models by MAX_CANDIDATES, image models to one.'
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

// --- A partial AI Client must cost a feature, not the site ---------------------

/*
 * The guard checked that the AiClient class existed and concluded the whole bundled library was
 * usable. It is not the same thing. The provider files implement interfaces from that library, and
 * a class implementing a missing interface is a fatal error raised by the include itself, which no
 * caller can handle. Continuous integration on WordPress 7.1.1 hit exactly that state and the
 * request died. Reproduced locally afterwards by hiding one interface file in a cold process: the
 * shipped 1.42.0 version fataled, this one survives.
 *
 * Read from source because the condition cannot be created inside a process that has already
 * loaded the library.
 */
$aicfab_core_ai_src = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-core-ai.php' );

/*
 * Scoped to the guard itself. A first version looked for each interface name anywhere in the file,
 * which passed while the name was only in the comment above the guard, so removing it from the
 * list changed nothing the check could see. Substring searches over a whole file have now been
 * wrong four times in this suite's history; the region matters as much as the string.
 */
$aicfab_guard = '';
if ( preg_match( '/function core_ai_available\(\).*?\n\t\}/s', $aicfab_core_ai_src, $aicfab_gm ) ) {
	$aicfab_guard = $aicfab_gm[0];
}
check_core_ai( '' !== $aicfab_guard, 'the guard body was found in the source' );
foreach (
	array(
		'ModelInterface',
		'TextGenerationModelInterface',
		'ModelMetadataDirectoryInterface',
		'ProviderAvailabilityInterface',
		'WithRequestAuthenticationInterface',
	) as $aicfab_needed
) {
	check_core_ai(
		false !== strpos( $aicfab_guard, "\\" . $aicfab_needed . "'" ),
		'the guard itself names ' . $aicfab_needed . ', which the provider implements'
	);
}
check_core_ai(
	false !== strpos( $aicfab_core_ai_src, 'interface_exists(' ),
	'the guard tests interfaces by existence rather than inferring them from a class'
);

/*
 * Two separate mistakes put the old code outside its own safety net: the includes sat before the
 * try, and Exception does not catch the Error that a missing interface raises.
 */
$aicfab_register = '';
if ( preg_match( '/function register_provider\(\).*?
	\}/s', $aicfab_core_ai_src, $aicfab_m ) ) {
	$aicfab_register = $aicfab_m[0];
}
check_core_ai( '' !== $aicfab_register, 'register_provider was found in the source' );
check_core_ai(
	false !== strpos( $aicfab_register, 'catch ( Throwable' ),
	'registration catches Throwable, since a missing interface raises an Error and not an Exception'
);
check_core_ai(
	strpos( $aicfab_register, 'try {' ) < strpos( $aicfab_register, 'require_once' ),
	'the includes are inside the try, because the include is what raises'
);
check_core_ai(
	false === strpos( $aicfab_register, 'catch ( Exception' ),
	'nothing in registration relies on catching Exception alone'
);

if ( $failures ) {
	echo "FAILED\n";
	foreach ( $failures as $failure ) {
		echo "- $failure\n";
	}
	exit( 1 );
}
echo "OK: core AI client and connector integration checks passed\n";
