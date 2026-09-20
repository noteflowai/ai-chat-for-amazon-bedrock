<?php
/**
 * Abilities API checks, against the real class.
 *
 * AI_Chat_Bedrock_Abilities was replaced by a stand-in in tool-ownership.php and loaded for real
 * nowhere, so its permission callbacks and its tool gating were unasserted: deleting the
 * current_user_can call from can_generate_text left every suite passing. This class decides which
 * site abilities a model may invoke, so those are the checks that matter most.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride

define( 'ABSPATH', __DIR__ );

$failures = array();
function check_ab( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// --- The smallest WordPress this class needs ----------------------------------

$GLOBALS['aicfab_caps']      = array();
$GLOBALS['aicfab_abilities'] = array();
$GLOBALS['aicfab_logged']    = array();
$GLOBALS['aicfab_logged_in'] = true;
$GLOBALS['aicfab_model_calls'] = array();
// Real option names, read from the source rather than assumed.
$GLOBALS['aicfab_opts'] = array(
	'ai_chat_bedrock_abilities_tools' => true,
	'ai_chat_bedrock_enable_mcp'      => true,
	'ai_chat_bedrock_mcp_capability'  => 'edit_posts',
	'ai_chat_bedrock_settings'        => array(),
	'ai_chat_bedrock_mcp_tool_policy' => array(),
);

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['aicfab_caps'][ $capability ] );
}
/*
 * Options are served by name. Returning one array for every name is how an earlier draft of
 * this file made tools_enabled() report true while reading a key that does not exist.
 */
function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_opts'] ) ? $GLOBALS['aicfab_opts'][ $name ] : $default_value;
}
function is_user_logged_in() {
	return ! empty( $GLOBALS['aicfab_logged_in'] );
}
// available() gates on the Abilities API being present.
function wp_register_ability( $id, $args = array() ) {
	return true;
}
function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function wp_generate_uuid4() {
	return '00000000-0000-4000-8000-000000000000';
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function __( $text, $domain = null ) {
	return $text;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function wp_get_abilities() {
	return $GLOBALS['aicfab_abilities'];
}

/**
 * Bedrock is not called here. This exists so the generate-text path can be followed past its
 * input checks without a missing class turning every fault into a fatal error.
 */
class AI_Chat_Bedrock_AWS {
	public function handle_chat_message( $request ) {
		$GLOBALS['aicfab_model_calls'][] = $request;
		return array( 'success' => true, 'data' => array( 'message' => 'generated' ), 'usage' => array() );
	}
}

class WP_Error {
	public $code;
	public $message;
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
 * An ability object shaped like the Abilities API provides, with its own permission gate.
 */
class Aicfab_Test_Ability {
	public $name;
	public $description;
	public $permitted;
	public $result;
	public $ran = false;

	public function __construct( $name, $description, $permitted = true, $result = 'done' ) {
		$this->name        = $name;
		$this->description = $description;
		$this->permitted   = $permitted;
		$this->result      = $result;
	}
	public function get_name() {
		return $this->name;
	}
	public function get_description() {
		return $this->description;
	}
	public function has_permission() {
		return $this->permitted;
	}
	public function execute( $parameters ) {
		$this->ran = true;
		return $this->result;
	}
}

// The policy and the log are real: tool gating is the behaviour under test here.
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-tool-policy.php';

class AI_Chat_Bedrock_Tool_Log {
	public static function record( $entry ) {
		$GLOBALS['aicfab_logged'][] = $entry;
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-abilities.php';

$aicfab_abilities_obj = new AI_Chat_Bedrock_Abilities();
check_ab( AI_Chat_Bedrock_Abilities::tools_enabled(), 'The fixture has ability tools switched on, or nothing below is exercised.' );

// --- Permission callbacks actually check a capability -------------------------

$GLOBALS['aicfab_caps'] = array();
check_ab( false === $aicfab_abilities_obj->can_generate_text(), 'Text generation is refused without edit_posts.' );
check_ab( false === $aicfab_abilities_obj->can_manage(), 'Status reads are refused without manage_options.' );

$GLOBALS['aicfab_caps'] = array( 'edit_posts' => true );
check_ab( true === $aicfab_abilities_obj->can_generate_text(), 'Text generation is allowed with edit_posts.' );
check_ab( false === $aicfab_abilities_obj->can_manage(), 'edit_posts alone does not grant status reads.' );

$GLOBALS['aicfab_caps'] = array( 'manage_options' => true );
check_ab( true === $aicfab_abilities_obj->can_manage(), 'Status reads are allowed with manage_options.' );
check_ab( false === $aicfab_abilities_obj->can_generate_text(), 'manage_options is not treated as edit_posts.' );

// --- A prompt is required, and is capped --------------------------------------

$aicfab_missing = $aicfab_abilities_obj->ability_generate_text( array() );
check_ab( $aicfab_missing instanceof WP_Error, 'Generating text without a prompt is an error.' );
check_ab(
	$aicfab_missing instanceof WP_Error && 'aicfab_missing_prompt' === $aicfab_missing->get_error_code(),
	'The missing prompt has its own error code.'
);
check_ab(
	$aicfab_abilities_obj->ability_generate_text( array( 'prompt' => '   ' ) ) instanceof WP_Error,
	'A prompt of only whitespace counts as missing.'
);
check_ab( array() === $GLOBALS['aicfab_model_calls'], 'A rejected prompt never reaches the model.' );

// A real prompt does reach it, and is capped on the way.
$GLOBALS['aicfab_model_calls'] = array();
$aicfab_generated              = $aicfab_abilities_obj->ability_generate_text(
	array( 'prompt' => str_repeat( 'p', AI_Chat_Bedrock_Abilities::MAX_TEXT + 500 ), 'system' => 'Be brief.' )
);
check_ab( is_array( $aicfab_generated ) && 'generated' === $aicfab_generated['text'], 'A valid prompt produces text.' );
check_ab( 1 === count( $GLOBALS['aicfab_model_calls'] ), 'One call per generation, got ' . count( $GLOBALS['aicfab_model_calls'] ) );
$aicfab_sent = $GLOBALS['aicfab_model_calls'][0]['messages'];
$aicfab_user = $aicfab_sent[ count( $aicfab_sent ) - 1 ]['content'];
check_ab(
	strlen( $aicfab_user ) <= AI_Chat_Bedrock_Abilities::MAX_TEXT,
	'An oversized prompt is cut before it is sent, got ' . strlen( $aicfab_user )
);

// --- Which abilities become tools --------------------------------------------

$GLOBALS['aicfab_caps'] = array( 'edit_posts' => true );
$aicfab_allowed         = new Aicfab_Test_Ability( 'acme/lookup-order', 'Look up an order' );
$aicfab_denied          = new Aicfab_Test_Ability( 'acme/secret', 'Secret thing', false );
$GLOBALS['aicfab_abilities'] = array(
	$aicfab_allowed,
	$aicfab_denied,
	// The plugin's own abilities are exposed through their own path, not this one.
	new Aicfab_Test_Ability( 'ai-chat-bedrock/search-content', 'Search content' ),
);

$aicfab_tools = $aicfab_abilities_obj->available_ability_tools();
$aicfab_names = array_map(
	function ( $tool ) {
		return $tool['name'];
	},
	$aicfab_tools
);

check_ab( in_array( 'wpability___acme__lookup_order', $aicfab_names, true ), 'A permitted third-party ability becomes a tool.' );
check_ab(
	! in_array( 'wpability___acme__secret', $aicfab_names, true ),
	'An ability whose own permission check fails is never offered as a tool.'
);
check_ab(
	! in_array( 'wpability___ai_chat_bedrock__search_content', $aicfab_names, true ),
	'The plugin does not re-expose its own abilities through this path.'
);
foreach ( $aicfab_names as $aicfab_name ) {
	check_ab( 0 === strpos( $aicfab_name, AI_Chat_Bedrock_Abilities::TOOL_PREFIX ), 'Every tool carries the ability prefix.' );
}

/*
 * A site owner can deny a specific tool on the MCP policy screen. Nothing asserted that the
 * ability path honours that, and it could be removed with every suite still green.
 */
$GLOBALS['aicfab_abilities'] = array( $aicfab_allowed );
$GLOBALS['aicfab_opts']['ai_chat_bedrock_mcp_tool_policy'] = array( 'wpability___acme__lookup_order' => 'deny' );
$aicfab_denied_by_policy = $aicfab_abilities_obj->available_ability_tools();
check_ab( array() === $aicfab_denied_by_policy, 'A tool the site policy denies is not offered, got ' . count( $aicfab_denied_by_policy ) );

// And it cannot be run either, even when the model names it directly.
$aicfab_policy_run = $aicfab_abilities_obj->execute_ability_tools(
	array( 'tool_calls' => array( array( 'id' => 'p1', 'name' => 'wpability___acme__lookup_order' ) ) )
);
check_ab(
	isset( $aicfab_policy_run['tool_calls'][0]['error'] ),
	'A policy-denied ability is refused at execution, not only hidden from the list.'
);
$GLOBALS['aicfab_opts']['ai_chat_bedrock_mcp_tool_policy'] = array();

// However many abilities a site registers, the model is offered a bounded list.
$GLOBALS['aicfab_abilities'] = array();
for ( $aicfab_i = 0; $aicfab_i < AI_Chat_Bedrock_Abilities::MAX_TOOLS + 10; $aicfab_i++ ) {
	$GLOBALS['aicfab_abilities'][] = new Aicfab_Test_Ability( 'acme/thing-' . $aicfab_i, 'Thing ' . $aicfab_i );
}
check_ab(
	count( $aicfab_abilities_obj->available_ability_tools() ) <= AI_Chat_Bedrock_Abilities::MAX_TOOLS,
	'The offered tool list is capped at MAX_TOOLS, got ' . count( $aicfab_abilities_obj->available_ability_tools() )
);

// --- Execution refuses what listing refused ----------------------------------

/*
 * Listing and running are separate entry points. A model that names a tool it was never
 * offered, or a call that arrives after permissions changed, must still be refused, so the
 * permission check is repeated at execution rather than assumed from the listing.
 */
$GLOBALS['aicfab_abilities'] = array( $aicfab_denied );
$GLOBALS['aicfab_logged']    = array();
$aicfab_response             = $aicfab_abilities_obj->execute_ability_tools(
	array(
		'tool_calls' => array(
			array( 'id' => 'call1', 'name' => 'wpability___acme__secret', 'parameters' => array( 'x' => 1 ) ),
		),
	)
);

check_ab( ! $aicfab_denied->ran, 'An ability that refuses permission is never executed.' );
check_ab( isset( $aicfab_response['tool_calls'][0]['error'] ), 'The refused call comes back as an error.' );
check_ab( ! isset( $aicfab_response['tool_calls'][0]['result'] ), 'A refused call carries no result.' );

// An unknown tool name cannot reach anything.
$GLOBALS['aicfab_abilities'] = array( $aicfab_allowed );
$aicfab_unknown             = $aicfab_abilities_obj->execute_ability_tools(
	array(
		'tool_calls' => array(
			array( 'id' => 'call2', 'name' => 'wpability___acme__does_not_exist', 'parameters' => array() ),
		),
	)
);
check_ab( isset( $aicfab_unknown['tool_calls'][0]['error'] ), 'An unknown ability is an error, not a silent pass.' );
check_ab(
	'ability_not_allowed' === $aicfab_unknown['tool_calls'][0]['error']['code'],
	'The unknown ability is refused by policy rather than executed.'
);

// Only the log's metadata, never the parameter values.
$GLOBALS['aicfab_logged'] = array();
$aicfab_abilities_obj->execute_ability_tools(
	array(
		'tool_calls' => array(
			array( 'id' => 'c', 'name' => 'wpability___acme__nope', 'parameters' => array( 'card' => '4111111111111111' ) ),
		),
	)
);
$aicfab_log_text = wp_json_encode( $GLOBALS['aicfab_logged'] );
check_ab( false === strpos( $aicfab_log_text, '4111111111111111' ), 'A parameter value never reaches the tool log.' );
check_ab( false !== strpos( $aicfab_log_text, 'card' ), 'The parameter key is recorded, which is the point of the log.' );

// --- Tools off means nothing is offered or run -------------------------------

$GLOBALS['aicfab_opts']['ai_chat_bedrock_abilities_tools'] = false;
$GLOBALS['aicfab_abilities'] = array( $aicfab_allowed );
$aicfab_off                  = $aicfab_abilities_obj->execute_ability_tools(
	array(
		'tool_calls' => array( array( 'id' => 'c', 'name' => 'wpability___acme__lookup_order' ) ),
	)
);
check_ab(
	! isset( $aicfab_off['tool_calls'][0]['result'] ),
	'With ability tools disabled, no ability runs.'
);
$GLOBALS['aicfab_opts']['ai_chat_bedrock_abilities_tools'] = true;

// --- Registration has to reach the core registry --------------------------------

/*
 * Every ability this plugin defines was absent from the WordPress registry, on every version
 * that has the Abilities API, because the wiring hooked abilities_api_init: a name WordPress
 * has never fired. The prefixed wp_abilities_api_init is the real one, and wp_register_ability()
 * refuses anything registered outside it. The plugin also passed no category, and core returns
 * null for an ability without one, so the abilities would still not have registered even after
 * the hook name was corrected. Both are checked here, by reading the source, because a unit
 * test that calls register() directly cannot see a wrong hook name.
 */
$aicfab_wiring   = file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock.php' );
$aicfab_sources  = array(
	'includes/class-ai-chat-bedrock-abilities.php',
	'includes/class-ai-chat-bedrock-site-abilities.php',
);

check_ab(
	(bool) preg_match( "/add_action\(\s*'wp_abilities_api_init'/", $aicfab_wiring ),
	'Abilities are registered on wp_abilities_api_init.'
);
check_ab(
	! preg_match( "/'abilities_api_init'/", $aicfab_wiring ),
	'The unprefixed hook name, which WordPress never fires, does not appear.'
);
check_ab(
	(bool) preg_match( "/add_action\(\s*'wp_abilities_api_categories_init'/", $aicfab_wiring ),
	'The category is registered on its own hook, which fires before abilities.'
);
/*
 * Written with a single-quoted pattern on purpose. In a double-quoted PHP string \$ collapses to
 * a bare $, which a regex reads as the end-of-string anchor, and the first version of this check
 * could therefore never match the line it was meant to catch.
 */
check_ab(
	! preg_match( '/add_action\(\s*.init.,\s*\$[a-z_]*abilities/', $aicfab_wiring ),
	'There is no init fallback, because core refuses registration there and warns.'
);

// Every registration must carry a category, or core silently returns null for it.
$aicfab_registrations = 0;
$aicfab_missing_cat   = array();
$aicfab_missing_meta  = array();
foreach ( $aicfab_sources as $aicfab_file ) {
	$aicfab_body = file_get_contents( __DIR__ . '/../' . $aicfab_file );
	if ( ! preg_match_all( "/wp_register_ability\(\s*\n\s*'([a-z0-9\-\/]+)',\s*\n\s*array\((.*?)\n\s*\)\s*\n\s*\);/s", $aicfab_body, $aicfab_hits, PREG_SET_ORDER ) ) {
		continue;
	}
	foreach ( $aicfab_hits as $aicfab_hit ) {
		++$aicfab_registrations;
		if ( false === strpos( $aicfab_hit[2], "'category'" ) ) {
			$aicfab_missing_cat[] = $aicfab_hit[1];
		}
		if ( false === strpos( $aicfab_hit[2], "'annotations'" ) ) {
			$aicfab_missing_meta[] = $aicfab_hit[1];
		}
	}
}

check_ab( $aicfab_registrations >= 7, 'The ability registrations were found, got ' . $aicfab_registrations );
check_ab(
	array() === $aicfab_missing_cat,
	'Every ability declares a category, missing: ' . implode( ', ', $aicfab_missing_cat )
);

/*
 * The annotations are not decoration. Core reads them at the transport layer: an ability marked
 * readonly may only be invoked with GET, and one that updates may only be invoked with POST,
 * which was confirmed against a live site. They are how this plugin's claim that only one
 * ability writes becomes something a client can check rather than a sentence in a readme.
 */
check_ab(
	array() === $aicfab_missing_meta,
	'Every ability declares its behaviour, missing: ' . implode( ', ', $aicfab_missing_meta )
);

/*
 * Matched against source with its whitespace collapsed. The first version of these checks were
 * written against single-line arrays and broke the moment the formatter expanded them, which
 * made the assertions about formatting rather than about behaviour.
 */
$aicfab_flat = preg_replace( '/\s+/', ' ', file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-site-abilities.php' ) );

function aicfab_annotation( $flat, $ability, $key, $value ) {
	$pattern = '/\x27ai-chat-bedrock\/' . preg_quote( $ability, '/' ) . '\x27.*?\x27' . preg_quote( $key, '/' ) . '\x27\s*=>\s*' . preg_quote( $value, '/' ) . '/';
	return (bool) preg_match( $pattern, $flat );
}

check_ab(
	1 === preg_match_all( '/\x27readonly\x27\s*=>\s*false/', $aicfab_flat ),
	'Exactly one site ability declares that it writes, found ' . preg_match_all( '/\x27readonly\x27\s*=>\s*false/', $aicfab_flat )
);
check_ab(
	aicfab_annotation( $aicfab_flat, 'create-draft', 'readonly', 'false' ),
	'The draft ability is the one that declares it writes.'
);
check_ab(
	aicfab_annotation( $aicfab_flat, 'create-draft', 'destructive', 'false' ),
	'And declares itself additive rather than destructive, so a client knows it removes nothing.'
);
foreach ( array( 'search-content', 'get-post', 'suggest-seo-meta', 'get-products' ) as $aicfab_read ) {
	check_ab(
		aicfab_annotation( $aicfab_flat, $aicfab_read, 'readonly', 'true' ),
		$aicfab_read . ' declares itself read only.'
	);
}

check_ab(
	(bool) preg_match( '/function register_category\(/', file_get_contents( __DIR__ . '/../includes/class-ai-chat-bedrock-abilities.php' ) ),
	'The plugin registers the category it files its abilities under, under exactly that name.'
);

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: abilities checks passed\n";
