<?php
/**
 * Standalone tests for the generated IAM policy.
 *
 * The action names and ARN shapes here were taken from the Amazon Bedrock documentation.
 * They are asserted literally so a well-meaning edit cannot quietly produce a policy that
 * looks plausible and does not work.
 *
 * Run: php tests/iam-policy.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function __( $text, $domain = null ) {
	return $text;
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-iam-policy.php';

$failures = array();
function check_policy( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

/**
 * Find one statement by its Sid.
 */
function statement( $policy, $sid ) {
	foreach ( $policy['Statement'] as $item ) {
		if ( $item['Sid'] === $sid ) {
			return $item;
		}
	}
	return null;
}

// --- A plain foundation model ------------------------------------------------

$policy = AI_Chat_Bedrock_Iam_Policy::build(
	array(
		'region'    => 'us-east-1',
		'account'   => '111122223333',
		'models'    => array( 'anthropic.claude-3-haiku-20240307-v1:0' ),
		'streaming' => true,
	)
);

check_policy( '2012-10-17' === $policy['Version'], 'the document declares the policy language version' );

$invoke = statement( $policy, 'AICFABInvokeConfiguredModels' );
check_policy( null !== $invoke, 'a statement grants model invocation' );
check_policy( 'Allow' === $invoke['Effect'], 'the invocation statement allows rather than denies' );
check_policy(
	in_array( 'bedrock:InvokeModel', $invoke['Action'], true ),
	'InvokeModel is granted'
);
check_policy(
	in_array( 'bedrock:InvokeModelWithResponseStream', $invoke['Action'], true ),
	'streaming is a separate action and is granted too'
);
check_policy(
	array( 'arn:aws:bedrock:us-east-1::foundation-model/anthropic.claude-3-haiku-20240307-v1:0' ) === $invoke['Resource'],
	'a plain model resolves to one foundation-model ARN with no account ID, got ' . json_encode( $invoke['Resource'] )
);
// A foundation model ARN has an empty account field. Putting the account in breaks it.
check_policy(
	false === strpos( $invoke['Resource'][0], '111122223333' ),
	'a foundation-model ARN does not carry the account ID'
);

// --- Streaming disabled ------------------------------------------------------

$buffered = AI_Chat_Bedrock_Iam_Policy::build(
	array(
		'region'    => 'us-east-1',
		'account'   => '111122223333',
		'models'    => array( 'anthropic.claude-3-haiku-20240307-v1:0' ),
		'streaming' => false,
	)
);
$invoke_buffered = statement( $buffered, 'AICFABInvokeConfiguredModels' );
check_policy(
	! in_array( 'bedrock:InvokeModelWithResponseStream', $invoke_buffered['Action'], true ),
	'with streaming off the streaming action is not requested'
);
check_policy(
	in_array( 'bedrock:InvokeModel', $invoke_buffered['Action'], true ),
	'buffered invocation is still granted'
);

// --- A cross-region inference profile ---------------------------------------

$profile = AI_Chat_Bedrock_Iam_Policy::build(
	array(
		'region'    => 'us-west-2',
		'account'   => '111122223333',
		'models'    => array( 'us.anthropic.claude-haiku-4-5-20251001-v1:0' ),
		'streaming' => true,
	)
);
$resources = statement( $profile, 'AICFABInvokeConfiguredModels' )['Resource'];
check_policy(
	in_array( 'arn:aws:bedrock:us-west-2:111122223333:inference-profile/us.anthropic.claude-haiku-4-5-20251001-v1:0', $resources, true ),
	'the inference profile ARN carries the region and account, got ' . json_encode( $resources )
);
// Naming only the profile is the classic mistake: the underlying model must be allowed too.
check_policy(
	in_array( 'arn:aws:bedrock:*::foundation-model/anthropic.claude-haiku-4-5-20251001-v1:0', $resources, true ),
	'the underlying foundation model is allowed across the regions the profile routes to'
);
check_policy( 2 === count( $resources ), 'a profile produces exactly the two required ARNs' );

check_policy(
	AI_Chat_Bedrock_Iam_Policy::is_inference_profile( 'us.anthropic.claude-haiku-4-5-20251001-v1:0' ),
	'a us. prefix is recognised as a profile'
);
check_policy(
	AI_Chat_Bedrock_Iam_Policy::is_inference_profile( 'global.anthropic.claude-sonnet-4-20250514-v1:0' ),
	'a global. prefix is recognised as a profile'
);
check_policy(
	! AI_Chat_Bedrock_Iam_Policy::is_inference_profile( 'amazon.titan-embed-text-v2:0' ),
	'a plain model is not mistaken for a profile'
);
check_policy(
	'anthropic.claude-haiku-4-5-20251001-v1:0' === AI_Chat_Bedrock_Iam_Policy::base_model_id( 'us.anthropic.claude-haiku-4-5-20251001-v1:0' ),
	'the base model is derived by removing the profile prefix'
);
check_policy(
	'amazon.titan-embed-text-v2:0' === AI_Chat_Bedrock_Iam_Policy::base_model_id( 'amazon.titan-embed-text-v2:0' ),
	'a plain model id is returned unchanged'
);

// --- Model discovery ---------------------------------------------------------

$list = statement( $policy, 'AICFABListModelsForTheModelPicker' );
check_policy( null !== $list, 'listing models is granted so the model picker works' );
check_policy( array( 'bedrock:ListFoundationModels' ) === $list['Action'], 'the discovery action is ListFoundationModels' );
// This operation has no resource, so scoping it would deny it.
check_policy( '*' === $list['Resource'], 'ListFoundationModels is granted on * because it takes no resource' );

// --- Optional features appear only when configured --------------------------

$minimal = AI_Chat_Bedrock_Iam_Policy::build(
	array(
		'region'  => 'us-east-1',
		'account' => '111122223333',
		'models'  => array( 'anthropic.claude-3-haiku-20240307-v1:0' ),
	)
);
foreach ( array( 'AICFABApplyConfiguredGuardrail', 'AICFABReadManagedPrompt', 'AICFABRetrieveFromKnowledgeBase', 'AICFABInvokeAgentCoreGateway' ) as $sid ) {
	check_policy( null === statement( $minimal, $sid ), "nothing grants $sid when the feature is unconfigured" );
}

$full = AI_Chat_Bedrock_Iam_Policy::build(
	array(
		'region'         => 'us-east-1',
		'account'        => '111122223333',
		'models'         => array( 'anthropic.claude-3-haiku-20240307-v1:0' ),
		'streaming'      => true,
		'guardrail_id'   => 'abcd1234efgh',
		'prompt_id'      => 'PROMPT123',
		'knowledge_base' => 'KB456',
		'agentcore'      => array( 'us-west-2' ),
	)
);

$guardrail = statement( $full, 'AICFABApplyConfiguredGuardrail' );
check_policy( array( 'bedrock:ApplyGuardrail' ) === $guardrail['Action'], 'a guardrail needs ApplyGuardrail, not only InvokeModel' );
check_policy(
	array( 'arn:aws:bedrock:us-east-1:111122223333:guardrail/abcd1234efgh' ) === $guardrail['Resource'],
	'the guardrail ARN carries the account ID, got ' . json_encode( $guardrail['Resource'] )
);

$prompt = statement( $full, 'AICFABReadManagedPrompt' );
check_policy( array( 'bedrock:GetPrompt' ) === $prompt['Action'], 'a managed prompt is read with GetPrompt' );
check_policy(
	array( 'arn:aws:bedrock:us-east-1:111122223333:prompt/PROMPT123' ) === $prompt['Resource'],
	'the prompt ARN carries the account ID'
);

$kb = statement( $full, 'AICFABRetrieveFromKnowledgeBase' );
check_policy( array( 'bedrock:Retrieve' ) === $kb['Action'], 'a knowledge base is queried with Retrieve' );
check_policy(
	array( 'arn:aws:bedrock:us-east-1:111122223333:knowledge-base/KB456' ) === $kb['Resource'],
	'the knowledge base ARN carries the account ID'
);

$gateway = statement( $full, 'AICFABInvokeAgentCoreGateway' );
check_policy( array( 'bedrock-agentcore:InvokeGateway' ) === $gateway['Action'], 'an AgentCore gateway uses its own service prefix' );
check_policy(
	false !== strpos( $gateway['Resource'][0], 'arn:aws:bedrock-agentcore:us-west-2:111122223333:gateway/' ),
	'the gateway ARN uses the bedrock-agentcore service and the gateway region, got ' . json_encode( $gateway['Resource'] )
);
// The gateway lives in its own service, so it must not appear under bedrock:.
check_policy(
	false === strpos( json_encode( $gateway ), '"bedrock:InvokeGateway"' ),
	'the gateway action is not written with the bedrock prefix'
);

// --- Unknown account --------------------------------------------------------

$wildcard = AI_Chat_Bedrock_Iam_Policy::build(
	array(
		'region'       => 'us-east-1',
		'account'      => '',
		'models'       => array( 'us.anthropic.claude-haiku-4-5-20251001-v1:0' ),
		'guardrail_id' => 'abcd1234efgh',
	)
);
$json = AI_Chat_Bedrock_Iam_Policy::to_json( $wildcard );
check_policy( false !== strpos( $json, 'inference-profile/' ), 'an unknown account still produces a usable document' );
check_policy(
	false !== strpos( $json, 'arn:aws:bedrock:us-east-1:*:guardrail/abcd1234efgh' ),
	'an unknown account becomes a wildcard rather than an empty field'
);
check_policy( AI_Chat_Bedrock_Iam_Policy::needs_account_id( '' ), 'an empty account is reported as needing attention' );
check_policy( ! AI_Chat_Bedrock_Iam_Policy::needs_account_id( '111122223333' ), 'a real account is not reported as needing attention' );
// A region wildcard in a foundation-model ARN must not be mistaken for a missing account.
check_policy(
	! AI_Chat_Bedrock_Iam_Policy::needs_account_id( '210987654321' ),
	'a document containing a region wildcard is not flagged as missing the account'
);

// --- Input handling ---------------------------------------------------------

$dirty = AI_Chat_Bedrock_Iam_Policy::build(
	array(
		'region'       => 'not a region',
		'account'      => 'abc',
		'models'       => array( '', '  ', 'anthropic.claude-3-haiku-20240307-v1:0', 'anthropic.claude-3-haiku-20240307-v1:0' ),
		'guardrail_id' => 'bad id with spaces',
		'prompt_id'    => "quotes\"and\\backslashes",
	)
);
$dirty_invoke = statement( $dirty, 'AICFABInvokeConfiguredModels' );
check_policy( 1 === count( $dirty_invoke['Resource'] ), 'blank and duplicate models collapse to one resource' );
check_policy(
	false !== strpos( $dirty_invoke['Resource'][0], 'us-east-1' ),
	'an unusable region falls back to a default rather than producing a broken ARN'
);
check_policy( null === statement( $dirty, 'AICFABApplyConfiguredGuardrail' ), 'an identifier with spaces is refused, not embedded' );
check_policy( null === statement( $dirty, 'AICFABReadManagedPrompt' ), 'an identifier with quotes is refused, not embedded' );

$empty = AI_Chat_Bedrock_Iam_Policy::build( array( 'models' => array() ) );
check_policy( null === statement( $empty, 'AICFABInvokeConfiguredModels' ), 'no models means no invocation statement' );
check_policy( null !== statement( $empty, 'AICFABListModelsForTheModelPicker' ), 'the model picker still needs listing before anything is chosen' );

// --- The rendered document ---------------------------------------------------

$rendered = AI_Chat_Bedrock_Iam_Policy::to_json( $full );
check_policy( is_array( json_decode( $rendered, true ) ), 'the rendered policy is valid JSON' );
check_policy( false !== strpos( $rendered, "\n" ), 'the policy is formatted for reading rather than one line' );
// Escaped slashes would still parse but make the ARNs unpleasant to read.
check_policy( false === strpos( $rendered, '\\/' ), 'slashes in ARNs are not escaped' );
check_policy( false === strpos( $rendered, 'bedrock:*' ), 'no statement grants every Bedrock action' );
check_policy( false === strpos( $rendered, '"Resource": "arn:aws:bedrock:*::foundation-model/*"' ), 'no statement grants every model' );

// --- Identity shown on screen ------------------------------------------------

// Values below are AWS documentation examples on purpose. A real account, role and instance
// id would make this file a disclosure the moment the repository is readable, and the
// assertions are about the shape of the masking rather than about any one identity.
// The Diagnostics screen names the identity the plugin signs with, and admins share that
// screen in support threads. The full ARN carries the whole account number and, for an
// assumed role on EC2, the instance id as the session name. Neither is needed to answer
// "which role is this", so neither is printed.
$aicfab_full = 'arn:aws:sts::111122223333:assumed-role/WordPressBedrockRole/i-0123456789abcdef0';
$aicfab_shown = AI_Chat_Bedrock_Iam_Policy::display_identity( $aicfab_full );
check_policy( false === strpos( $aicfab_shown, '111122223333' ), 'The full account number must not be shown.' );
check_policy( false === strpos( $aicfab_shown, 'i-0123456789abcdef0' ), 'The session name, which is the instance id on EC2, must not be shown.' );
check_policy( false !== strpos( $aicfab_shown, 'WordPressBedrockRole' ), 'The role name must survive: it is what the line is for.' );
check_policy( false !== strpos( $aicfab_shown, '3333' ), 'Enough of the account must survive to recognise it.' );
check_policy(
	'arn:aws:iam::********3333:role/WordPressBedrockRole' === AI_Chat_Bedrock_Iam_Policy::display_identity( 'arn:aws:iam::111122223333:role/WordPressBedrockRole' ),
	'An IAM role ARN must be shortened in the same shape.'
);
check_policy(
	false !== strpos( AI_Chat_Bedrock_Iam_Policy::display_identity( 'arn:aws-us-gov:iam::111122223333:role/GovRole' ), 'aws-us-gov' ),
	'A GovCloud partition must be preserved.'
);
// Anything unrecognised is passed through rather than mangled into something misleading.
check_policy( 'not-an-arn' === AI_Chat_Bedrock_Iam_Policy::display_identity( 'not-an-arn' ), 'An unrecognised value must pass through unchanged.' );
check_policy( '' === AI_Chat_Bedrock_Iam_Policy::display_identity( '' ), 'An empty value must stay empty.' );

// The generated policy is the opposite case: it has to stay pasteable, so it keeps the real
// account wherever an ARN needs one. A configuration with no account-scoped resource has
// none to keep, which is why a plain foundation model produces a policy without one.
$aicfab_profile = AI_Chat_Bedrock_Iam_Policy::to_json( AI_Chat_Bedrock_Iam_Policy::build( array(
	'region' => 'us-east-1', 'account' => '111122223333', 'streaming' => true,
	'models' => array( 'us.anthropic.claude-haiku-4-5-20251001-v1:0' ),
) ) );
$aicfab_plain = AI_Chat_Bedrock_Iam_Policy::to_json( AI_Chat_Bedrock_Iam_Policy::build( array(
	'region' => 'us-east-1', 'account' => '111122223333', 'streaming' => true,
	'models' => array( 'anthropic.claude-3-haiku-20240307-v1:0' ),
) ) );
check_policy( false !== strpos( $aicfab_profile, '111122223333' ), 'An inference profile ARN needs the account and must keep it.' );
check_policy( false === strpos( $aicfab_plain, '111122223333' ), 'A foundation model ARN carries no account, so none should appear.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: IAM policy checks passed\n";
