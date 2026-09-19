<?php
/**
 * Diagnostics checks, against the real class.
 *
 * No suite loaded AI_Chat_Bedrock_Diagnostics. Two properties of this screen are worth holding
 * still: it must not print a credential while reporting on credentials, and loading it must not
 * invoke a model unless the administrator asked for a live test, because an admin screen that
 * silently bills tokens on every page view is a defect a maintainer would not notice.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride

define( 'ABSPATH', __DIR__ );

$failures = array();
function check_diag( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// --- The smallest WordPress this class needs ----------------------------------

// Values that must never appear in what the screen renders.
define( 'AICFAB_TEST_KEY', 'AKIAIOSFODNN7EXAMPLE' );
define( 'AICFAB_TEST_SECRET', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );

$GLOBALS['aicfab_opts']  = array();
$GLOBALS['aicfab_model']    = 0;
$GLOBALS['aicfab_listings'] = 0;

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_opts'] ) ? $GLOBALS['aicfab_opts'][ $name ] : $default_value;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}
function __( $text, $domain = null ) {
	return $text;
}
function _x( $text, $context, $domain = null ) {
	return $text;
}
function sprintf_placeholder() {}
function apply_filters( $hook, $value ) {
	return $value;
}
function is_wp_error( $thing ) {
	return false;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

/**
 * Counts model invocations so a screen that quietly calls Bedrock cannot pass.
 */
class AI_Chat_Bedrock_AWS {
	public static function streaming_supported( $model_id = '' ) {
		return true;
	}
	/*
	 * check_model asks Models::options(), which discovers the region's models through this
	 * call when the transient cache is cold. It is a control-plane listing rather than an
	 * inference request, so it costs no tokens, but it is counted separately here so the
	 * assertion about not invoking a model says only what it means.
	 */
	// The shape the real methods return, already normalised from the AWS response.
	public function list_foundation_models( $region = '' ) {
		++$GLOBALS['aicfab_listings'];
		return array(
			array(
				'id'        => 'amazon.nova-lite-v1:0',
				'name'      => 'Nova Lite',
				'provider'  => 'Amazon',
				'lifecycle' => 'ACTIVE',
				'on_demand' => true,
				'profile'   => false,
			),
		);
	}
	public function list_inference_profiles( $region = '' ) {
		++$GLOBALS['aicfab_listings'];
		return array();
	}
	public function test_model_access( $model_id ) {
		++$GLOBALS['aicfab_model'];
		return array(
			'success'  => true,
			'message'  => 'The model answered.',
			'duration' => 42,
			'usage'    => array( 'input_tokens' => 7, 'output_tokens' => 3 ),
		);
	}
	public function retrieve_from_knowledge_base( $id, $query, $limit ) {
		++$GLOBALS['aicfab_model'];
		return array( 'retrievalResults' => array() );
	}
}

class AI_Chat_Bedrock_AWS_Credentials {
	public static function describe( $options ) {
		// Reports the source the way the real one does, including the key it found.
		if ( ! empty( $options['aws_access_key'] ) ) {
			return array(
				'configured' => true,
				'source'     => 'options',
				'message'    => 'Keys stored in the database are in use.',
			);
		}
		return array( 'configured' => true, 'source' => 'instance_role', 'message' => 'An IAM role supplies credentials.' );
	}
}

// A real cache, so the assertion that a second page load does not call AWS means something.
$GLOBALS['aicfab_transients'] = array();
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['aicfab_transients'] ) ? $GLOBALS['aicfab_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['aicfab_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['aicfab_transients'][ $key ] );
	return true;
}

/*
 * Models and Rate_Limits are loaded for real. They hold data and rules rather than doing any
 * I/O, and an earlier draft of this file replaced Models with a double that was missing
 * is_valid_id, which is the kind of guess that makes a suite assert something the shipped code
 * does not do.
 */
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-models.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-rate-limits.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-diagnostics.php';

$GLOBALS['aicfab_opts'] = array(
	'ai_chat_bedrock_settings' => array(
		'aws_region'        => 'us-east-1',
		'model_id'          => 'amazon.nova-lite-v1:0',
		'aws_access_key'    => AICFAB_TEST_KEY,
		'aws_secret_key'    => AICFAB_TEST_SECRET,
		'enable_streaming'  => true,
	),
);

$aicfab_diag = new AI_Chat_Bedrock_Diagnostics();

// --- Reporting on credentials must not reveal them -----------------------------

$GLOBALS['aicfab_model'] = 0;
$aicfab_checks           = $aicfab_diag->run( false );
$aicfab_rendered         = wp_json_encode( $aicfab_checks );

check_diag( ! empty( $aicfab_checks ), 'The screen produces checks.' );
check_diag(
	false === strpos( $aicfab_rendered, AICFAB_TEST_KEY ),
	'An access key id never appears in the diagnostics output.'
);
check_diag(
	false === strpos( $aicfab_rendered, AICFAB_TEST_SECRET ),
	'A secret access key never appears in the diagnostics output.'
);
check_diag(
	false === strpos( $aicfab_rendered, 'wJalrXUtnFEMI' ),
	'Not even a fragment of the secret appears.'
);

// Stored keys are still reported as a weaker posture than a role, which is the point of the check.
$aicfab_cred = null;
foreach ( $aicfab_checks as $aicfab_check ) {
	if ( 'credentials' === $aicfab_check['id'] ) {
		$aicfab_cred = $aicfab_check;
	}
}
check_diag( null !== $aicfab_cred, 'There is a credentials check.' );
check_diag( 'warn' === $aicfab_cred['status'], 'Keys in the database are a warning, not a pass.' );

// --- Loading the screen does not call a model ---------------------------------

check_diag( 0 === $GLOBALS['aicfab_model'], 'Running diagnostics without a live test invokes no model, got ' . $GLOBALS['aicfab_model'] );

/*
 * It does discover the region's models, which is two control-plane listings rather than any
 * inference: foundation models and inference profiles. What matters for an admin screen is
 * that this is cached, so opening the page twice does not talk to AWS twice.
 */
check_diag( 2 === $GLOBALS['aicfab_listings'], 'One discovery per cold run, two listings, got ' . $GLOBALS['aicfab_listings'] );
$GLOBALS['aicfab_listings'] = 0;
$aicfab_diag->run( false );
check_diag( 0 === $GLOBALS['aicfab_listings'], 'A second run is served from cache and calls AWS not at all, got ' . $GLOBALS['aicfab_listings'] );

$GLOBALS['aicfab_model'] = 0;
$aicfab_live             = $aicfab_diag->run( true );
check_diag( 1 === $GLOBALS['aicfab_model'], 'Asking for a live test invokes the model exactly once, got ' . $GLOBALS['aicfab_model'] );
check_diag( count( $aicfab_live ) === count( $aicfab_checks ) + 1, 'The live test adds one check.' );

$aicfab_live_ids = array();
foreach ( $aicfab_live as $aicfab_check ) {
	$aicfab_live_ids[] = $aicfab_check['id'];
}
check_diag( in_array( 'invocation', $aicfab_live_ids, true ), 'The live test reports under its own id.' );

// --- Every check has the shape the screen renders ------------------------------

$aicfab_allowed_status = array( 'pass', 'warn', 'fail' );
foreach ( $aicfab_live as $aicfab_check ) {
	check_diag( isset( $aicfab_check['id'], $aicfab_check['label'], $aicfab_check['status'], $aicfab_check['message'] ), 'Each check carries id, label, status and message.' );
	check_diag( in_array( $aicfab_check['status'], $aicfab_allowed_status, true ), 'Status is one of pass, warn or fail, got ' . $aicfab_check['status'] );
	check_diag( '' !== trim( (string) $aicfab_check['message'] ), 'No check is reported without saying anything, at ' . $aicfab_check['id'] );
	// Every id in this class is a literal that is already a key, so asserting that they
	// survive sanitize_key would pass with the call removed. Shape is what is checked here.
	check_diag( '' !== $aicfab_check['id'], 'Every check has an id, at ' . $aicfab_check['label'] );
}
check_diag( count( $aicfab_live_ids ) === count( array_unique( $aicfab_live_ids ) ), 'No check id is reported twice.' );

// --- A public MCP endpoint is a warning, not a pass ---------------------------

$GLOBALS['aicfab_opts']['ai_chat_bedrock_enable_mcp']        = true;
$GLOBALS['aicfab_opts']['ai_chat_bedrock_mcp_public_access'] = true;
$aicfab_public                                              = $aicfab_diag->run( false );
$aicfab_mcp                                                 = null;
foreach ( $aicfab_public as $aicfab_check ) {
	if ( 'mcp' === $aicfab_check['id'] ) {
		$aicfab_mcp = $aicfab_check;
	}
}
check_diag( null !== $aicfab_mcp && 'warn' === $aicfab_mcp['status'], 'A publicly readable MCP endpoint is a warning.' );

$GLOBALS['aicfab_opts']['ai_chat_bedrock_mcp_public_access'] = false;
$aicfab_private                                             = $aicfab_diag->run( false );
foreach ( $aicfab_private as $aicfab_check ) {
	if ( 'mcp' === $aicfab_check['id'] ) {
		check_diag( 'pass' === $aicfab_check['status'], 'An authenticated MCP endpoint passes.' );
	}
}

// --- An IAM role is the better posture, and says so ---------------------------

$GLOBALS['aicfab_opts']['ai_chat_bedrock_settings'] = array(
	'aws_region' => 'us-east-1',
	'model_id'   => 'amazon.nova-lite-v1:0',
);
$aicfab_role = $aicfab_diag->run( false );
foreach ( $aicfab_role as $aicfab_check ) {
	if ( 'credentials' === $aicfab_check['id'] ) {
		check_diag( 'pass' === $aicfab_check['status'], 'A role-based setup passes rather than warns.' );
	}
}

// --- A misconfigured region or model fails rather than passing quietly ---------

$GLOBALS['aicfab_opts']['ai_chat_bedrock_settings'] = array( 'aws_region' => 'moon-base-1', 'model_id' => 'not a model' );
$aicfab_bad                                        = $aicfab_diag->run( false );
$aicfab_bad_status                                 = array();
foreach ( $aicfab_bad as $aicfab_check ) {
	$aicfab_bad_status[ $aicfab_check['id'] ] = $aicfab_check['status'];
}
check_diag( 'fail' === $aicfab_bad_status['region'], 'An unsupported region fails.' );
check_diag( 'fail' === $aicfab_bad_status['model'], 'A structurally invalid model id fails.' );

/*
 * A model id that is unknown but well formed is accepted on purpose: Models::is_valid_id
 * validates by format because Bedrock adds models and inference profiles continuously, so a
 * fixed allowlist would reject new models the day they ship. Asserted so nobody tightens this
 * into an allowlist thinking it was an oversight.
 */
$GLOBALS['aicfab_opts']['ai_chat_bedrock_settings'] = array( 'aws_region' => 'us-east-1', 'model_id' => 'eu.anthropic.some-future-model-v9:0' );
$aicfab_future                                     = $aicfab_diag->run( false );
foreach ( $aicfab_future as $aicfab_check ) {
	if ( 'model' === $aicfab_check['id'] ) {
		check_diag( 'fail' !== $aicfab_check['status'], 'A well-formed model id the plugin has never heard of is not a failure.' );
	}
}
$GLOBALS['aicfab_opts']['ai_chat_bedrock_settings'] = array( 'aws_region' => 'us-east-1', 'model_id' => str_repeat( 'm', 250 ) );
$aicfab_toolong                                    = $aicfab_diag->run( false );
foreach ( $aicfab_toolong as $aicfab_check ) {
	if ( 'model' === $aicfab_check['id'] ) {
		check_diag( 'fail' === $aicfab_check['status'], 'An absurdly long model id fails.' );
	}
}

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: diagnostics checks passed\n";
