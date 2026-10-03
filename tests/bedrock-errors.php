<?php
/**
 * Standalone tests for Bedrock failure classification.
 *
 * The payloads below were captured from real Amazon Bedrock responses on 2026-09-14, not
 * written from memory. Two of them are counterintuitive and are the reason this file
 * exists: a model the account cannot invoke on demand answers with ValidationException
 * rather than AccessDeniedException, and a retired model answers with a message that
 * begins "Access denied" while the actual fix is to choose a different model.
 *
 * Run: php tests/bedrock-errors.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = null ) {
	return $text;
}

function _x( $text, $context, $domain = null ) {
	return $text;
}

class AI_Chat_Bedrock_Iam_Policy {
	public static function is_inference_profile( $model_id ) {
		foreach ( array( 'us.', 'eu.', 'apac.', 'apne.', 'global.' ) as $prefix ) {
			if ( 0 === strpos( (string) $model_id, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}

require_once __DIR__ . '/../includes/class-ai-chat-bedrock-bedrock-errors.php';
require_once __DIR__ . '/../includes/class-ai-chat-bedrock-translation.php';

$failures = array();
function check_error( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

/**
 * Real payloads. The apostrophe in the first is the typographic one Bedrock sends.
 */
$captured = array(
	'needs_profile' => array(
		'status' => 400,
		'body'   => '{"message":"Invocation of model ID anthropic.claude-opus-4-1-20250805-v1:0 with on-demand throughput isn’t supported. Retry your request with the ID or ARN of an inference profile that contains this model."}',
		'model'  => 'anthropic.claude-opus-4-1-20250805-v1:0',
	),
	'unknown_model' => array(
		'status' => 400,
		'body'   => '{"message":"The provided model identifier is invalid."}',
		'model'  => 'anthropic.claude-does-not-exist-v1:0',
	),
	'legacy'        => array(
		'status' => 404,
		'body'   => '{"message":"Access denied. This Model is marked by provider as Legacy and you have not been actively using the model in the last 30 days. Please upgrade to an active model on Amazon Bedrock"}',
		'model'  => 'anthropic.claude-3-haiku-20240307-v1:0',
	),
	// Captured 2026-09-24 from us-east-1; Titan Text Express returns the same body.
	'end_of_life'   => array(
		'status' => 404,
		'body'   => '{"message":"This model version has reached the end of its life. Please refer to the AWS documentation for more details."}',
		'model'  => 'us.anthropic.claude-3-7-sonnet-20250219-v1:0',
	),
);

// --- A model that requires an inference profile ------------------------------

$result = AI_Chat_Bedrock_Bedrock_Errors::explain(
	$captured['needs_profile']['status'],
	$captured['needs_profile']['body'],
	$captured['needs_profile']['model'],
	'us-east-1'
);
check_error( 'needs_inference_profile' === $result['kind'], 'the on-demand refusal is recognised, got ' . $result['kind'] );
check_error( false !== stripos( $result['message'], 'inference profile' ), 'the fix names the inference profile' );
// This case must not be reported as a permissions problem, which is what the status suggests.
check_error( false === stripos( $result['message'], 'IAM' ), 'it is not blamed on IAM' );
check_error(
	'us.anthropic.claude-opus-4-1-20250805-v1:0' === $result['model_hint'],
	'the profile ID to try is suggested, got ' . $result['model_hint']
);

// The typographic apostrophe must not be part of the match.
$straight = str_replace( '’', "'", $captured['needs_profile']['body'] );
check_error(
	'needs_inference_profile' === AI_Chat_Bedrock_Bedrock_Errors::explain( 400, $straight, '', 'us-east-1' )['kind'],
	'the match survives a straight apostrophe'
);

// --- An unrecognised model ---------------------------------------------------

$result = AI_Chat_Bedrock_Bedrock_Errors::explain( 400, $captured['unknown_model']['body'], $captured['unknown_model']['model'], 'ap-south-2' );
check_error( 'unknown_model' === $result['kind'], 'an invalid identifier is recognised, got ' . $result['kind'] );
check_error( false !== strpos( $result['message'], 'ap-south-2' ), 'the region is named, because the same message covers a region that does not offer the model' );
// Observed: a real model in a region that does not offer it returns this identical
// message, so the advice has to cover both causes rather than assert one.
check_error( false !== stripos( $result['message'], 'region does not offer' ), 'both causes are offered' );

// --- A retired model ---------------------------------------------------------

$result = AI_Chat_Bedrock_Bedrock_Errors::explain( $captured['legacy']['status'], $captured['legacy']['body'], $captured['legacy']['model'], 'us-east-1' );
check_error( 'legacy_model' === $result['kind'], 'a retired model is recognised, got ' . $result['kind'] );
check_error( false !== stripos( $result['message'], 'choose a current model' ), 'the fix is to pick another model' );
// The payload starts with "Access denied", which would mislead a status-only classifier.
check_error( false === stripos( $result['message'], 'not authorized' ), 'the wording of the payload does not turn it into a permissions error' );

// --- A model version Bedrock has shut down -----------------------------------

$result = AI_Chat_Bedrock_Bedrock_Errors::explain( $captured['end_of_life']['status'], $captured['end_of_life']['body'], $captured['end_of_life']['model'], 'us-east-1' );
check_error( 'retired_model' === $result['kind'], 'an end-of-life model is recognised, got ' . $result['kind'] );
check_error( false !== stripos( $result['message'], 'choose a current model' ), 'the fix for an end-of-life model is to pick another model' );
check_error( false === stripos( $result['message'], 'IAM' ), 'an end-of-life 404 is not blamed on IAM' );

// --- Model access not granted, from the documented wording ------------------

$result = AI_Chat_Bedrock_Bedrock_Errors::explain( 403, '{"message":"You don\'t have access to the model with the specified model ID."}', 'anthropic.claude-3-5-sonnet-20240620-v1:0', 'us-east-1' );
check_error( 'model_access_missing' === $result['kind'], 'a missing model grant is recognised, got ' . $result['kind'] );
check_error( false !== stripos( $result['message'], 'playground' ), 'the fix is to open the model once, now that there is no model access page' );

// --- An IAM denial ----------------------------------------------------------

$denial = '{"message":"User: arn:aws:sts::111122223333:assumed-role/site/i-0abc is not authorized to perform: bedrock:InvokeModelWithResponseStream on resource: arn:aws:bedrock:us-east-1::foundation-model/anthropic.claude-3-haiku-20240307-v1:0"}';
$result = AI_Chat_Bedrock_Bedrock_Errors::explain( 403, $denial, 'anthropic.claude-3-haiku-20240307-v1:0', 'us-east-1' );
check_error( 'iam_denied' === $result['kind'], 'an IAM denial is recognised, got ' . $result['kind'] );
check_error(
	false !== strpos( $result['message'], 'bedrock:InvokeModelWithResponseStream' ),
	'the refused action is named so the policy can be fixed, got ' . $result['message']
);
// The role ARN is operational detail that should not be echoed at a site visitor.
check_error( false === strpos( $result['message'], 'assumed-role' ), 'the identity ARN is not echoed back' );

// --- Which credentials were used ---------------------------------------------

// This came from a real incident: a container held expired temporary credentials in its
// environment, which take precedence over the instance role by AWS convention. Bedrock
// answered 403 and the advice sent the reader to the IAM policy, which was fine. Naming
// the source is what would have shortened that.
$expired_env = AI_Chat_Bedrock_Bedrock_Errors::explain(
	403,
	'{}',
	'a-model',
	'us-east-1',
	array(
		'source'    => 'environment',
		'temporary' => true,
	)
);
check_error( 'forbidden' === $expired_env['kind'], 'a bare 403 is still a permissions hint' );
check_error( false !== strpos( $expired_env['message'], 'environment variables' ), 'the message names the credential source, got: ' . $expired_env['message'] );
check_error( false !== stripos( $expired_env['message'], 'nothing renews once they expire' ), 'temporary credentials outside a role are called out' );

// A role renews itself, so that warning would be wrong.
$role = AI_Chat_Bedrock_Bedrock_Errors::explain(
	403,
	'{}',
	'a-model',
	'us-east-1',
	array(
		'source'    => 'instance_role',
		'temporary' => true,
	)
);
check_error( false !== strpos( $role['message'], 'instance IAM role' ), 'a role is named too' );
check_error( false === stripos( $role['message'], 'nothing renews once they expire' ), 'a role is not accused of expiring unrenewed' );

// Long-lived keys in wp-config are not temporary.
$constants = AI_Chat_Bedrock_Bedrock_Errors::explain(
	403,
	'{}',
	'a-model',
	'us-east-1',
	array(
		'source'    => 'constants',
		'temporary' => false,
	)
);
check_error( false !== strpos( $constants['message'], 'wp-config.php constants' ), 'constants are named' );
check_error( false === stripos( $constants['message'], 'nothing renews once they expire' ), 'permanent keys get no expiry warning' );

// An IAM denial names both the action and the source.
$denied_with_source = AI_Chat_Bedrock_Bedrock_Errors::explain(
	403,
	'{"message":"User: arn:aws:iam::111122223333:user/site is not authorized to perform: bedrock:InvokeModel on resource: x"}',
	'a-model',
	'us-east-1',
	array(
		'source'    => 'settings',
		'temporary' => false,
	)
);
check_error( false !== strpos( $denied_with_source['message'], 'bedrock:InvokeModel' ), 'the refused action is still named' );
check_error( false !== strpos( $denied_with_source['message'], 'stored in the settings' ), 'the source is named alongside it' );

// Nothing known about the credentials must not produce a dangling sentence.
$unknown = AI_Chat_Bedrock_Bedrock_Errors::explain( 403, '{}', 'a-model', 'us-east-1' );
check_error( false === strpos( $unknown['message'], 'credentials from' ), 'no source means no claim about one' );
$bogus = AI_Chat_Bedrock_Bedrock_Errors::explain( 403, '{}', '', '', array( 'source' => 'made-up' ) );
check_error( false === strpos( $bogus['message'], 'credentials from' ), 'an unrecognised source is not echoed' );

// --- A guardrail that Bedrock will not accept --------------------------------

// Both messages captured from real calls. Bedrock fails closed on a wrong guardrail, so
// every request stops; the generic advice used to send the reader to the IAM policy.
foreach ( array(
	'{"message":"The provided guardrail identifier is invalid."}',
	'{"message":"The guardrail identifier or version provided in the request does not exist."}',
) as $aicfab_guardrail_body ) {
	$aicfab_g = AI_Chat_Bedrock_Bedrock_Errors::explain( 400, $aicfab_guardrail_body, 'a-model', 'us-east-1' );
	check_error( 'guardrail_invalid' === $aicfab_g['kind'], 'a rejected guardrail is recognised, got ' . $aicfab_g['kind'] );
	check_error( false !== stripos( $aicfab_g['message'], 'guardrail' ), 'the message names the guardrail' );
	check_error( false === stripos( $aicfab_g['message'], 'IAM policy' ), 'it is not blamed on the IAM policy' );
}

// A guardrail message must not be mistaken for an unknown model.
check_error(
	'unknown_model' === AI_Chat_Bedrock_Bedrock_Errors::explain( 400, '{"message":"The provided model identifier is invalid."}', '', '' )['kind'],
	'an unknown model is still recognised as such'
);

// --- Status-only cases ------------------------------------------------------

check_error( 'throttled' === AI_Chat_Bedrock_Bedrock_Errors::explain( 429, '{}', '', '' )['kind'], 'throttling is recognised' );
check_error( 'service_error' === AI_Chat_Bedrock_Bedrock_Errors::explain( 503, '{}', '', '' )['kind'], 'a service error is recognised' );
check_error( 'forbidden' === AI_Chat_Bedrock_Bedrock_Errors::explain( 403, '{}', '', '' )['kind'], 'a bare 403 falls back to a permissions hint' );
check_error( 'unknown' === AI_Chat_Bedrock_Bedrock_Errors::explain( 418, '{}', '', '' )['kind'], 'an unrecognised status is reported plainly' );
check_error(
	false !== strpos( AI_Chat_Bedrock_Bedrock_Errors::explain( 418, '{}', '', '' )['message'], '418' ),
	'the unrecognised status is stated'
);

// --- Robustness -------------------------------------------------------------

foreach ( array( '', 'not json at all', '{', '[]', 'null' ) as $body ) {
	$result = AI_Chat_Bedrock_Bedrock_Errors::explain( 400, $body, '', '' );
	check_error( isset( $result['kind'], $result['message'] ), 'a body of ' . var_export( $body, true ) . ' still yields a message' );
	check_error( '' !== $result['message'], 'the message is never empty for ' . var_export( $body, true ) );
}

// --- A model turned on at first use ------------------------------------------
// As users have reported them; Bedrock no longer has a model access page to send them to.

$result = AI_Chat_Bedrock_Bedrock_Errors::explain( 404, '{"message":"Model use case details have not been submitted for this account. Fill out the Anthropic use case details form before using the model. If you have already filled out the form, try again in 15 minutes."}', 'us.anthropic.claude-sonnet-4-5-20250929-v1:0', 'us-east-1' );
check_error( 'use_case_required' === $result['kind'], 'the Anthropic use case form is recognised, got ' . $result['kind'] );
check_error( false !== stripos( $result['message'], 'model catalog' ), 'the fix says where the form is' );
check_error( false === stripos( $result['message'], 'HTTP 404' ), 'a 404 for the form is not reported as unknown' );

$marketplace = '{"message":"Model access is denied due to IAM user or service role is not authorized to perform the required AWS Marketplace actions (aws-marketplace:ViewSubscriptions, aws-marketplace:Subscribe) to enable access to this model. Refer to the Amazon Bedrock documentation for further details. Your AWS Marketplace subscription for this model cannot be completed at this time. If you recently fixed this issue, try again after 15 minutes."}';
$result      = AI_Chat_Bedrock_Bedrock_Errors::explain( 403, $marketplace, 'us.anthropic.claude-sonnet-4-5-20250929-v1:0', 'us-east-1', array( 'source' => 'instance_role' ) );
check_error( 'marketplace_first_use' === $result['kind'], 'a first use without Marketplace permissions is recognised, got ' . $result['kind'] );
check_error( false !== strpos( $result['message'], 'aws-marketplace:Subscribe' ), 'the missing Marketplace action is named' );
check_error( false !== stripos( $result['message'], 'playground' ), 'the one-time way round it is offered' );
check_error( false !== strpos( $result['message'], 'instance IAM role' ), 'the identity that was refused is named' );
check_error( false === stripos( $result['message'], 'Model access page' ) && false === stripos( $result['message'], 'under Model access' ), 'the retired console page is not mentioned' );

$result = AI_Chat_Bedrock_Bedrock_Errors::explain( 403, '{"message":"Model access is denied due to INVALID_PAYMENT_INSTRUMENT:A valid payment instrument must be provided.. Your AWS Marketplace subscription for this model cannot be completed at this time. If you recently fixed this issue, try again after 15 minutes."}', 'us.anthropic.claude-sonnet-4-5-20250929-v1:0', 'us-east-1' );
check_error( 'payment_required' === $result['kind'], 'a missing payment method is recognised before the Marketplace permissions, got ' . $result['kind'] );
check_error( false !== stripos( $result['message'], 'Nova' ), 'a model that needs no Marketplace subscription is offered' );

foreach ( array( 'use_case_required', 'payment_required', 'marketplace_first_use', 'model_access_missing', 'forbidden' ) as $kind ) {
	// Nothing tells the operator to visit a console page that no longer exists.
	$bodies = array(
		'use_case_required'     => '{"message":"Model use case details have not been submitted for this account."}',
		'payment_required'      => '{"message":"Model access is denied due to INVALID_PAYMENT_INSTRUMENT"}',
		'marketplace_first_use' => $marketplace,
		'model_access_missing'  => '{"message":"You don\'t have access to the model with the specified model ID."}',
		'forbidden'             => '{"message":"Forbidden"}',
	);
	$message = AI_Chat_Bedrock_Bedrock_Errors::explain( 403, $bodies[ $kind ], '', 'us-east-1' )['message'];
	check_error( false === stripos( $message, 'under Model access' ) && false === stripos( $message, 'model access is granted' ), $kind . ' does not send the operator to the retired Model access page' );
}

// A plain text body, which some AWS front ends return.
check_error(
	'needs_inference_profile' === AI_Chat_Bedrock_Bedrock_Errors::explain( 400, 'ValidationException: on-demand throughput is not supported', '', '' )['kind'],
	'a non-JSON body is still classified'
);

// --- Profile suggestion -----------------------------------------------------

check_error( 'us.foo' === AI_Chat_Bedrock_Bedrock_Errors::suggest_profile_id( 'foo', 'us-west-2' ), 'a US region suggests the us prefix' );
check_error( 'eu.foo' === AI_Chat_Bedrock_Bedrock_Errors::suggest_profile_id( 'foo', 'eu-central-1' ), 'an EU region suggests the eu prefix' );
check_error( 'apac.foo' === AI_Chat_Bedrock_Bedrock_Errors::suggest_profile_id( 'foo', 'ap-northeast-1' ), 'an Asia Pacific region suggests the apac prefix' );
check_error( '' === AI_Chat_Bedrock_Bedrock_Errors::suggest_profile_id( 'us.foo', 'us-west-2' ), 'a model that is already a profile suggests nothing' );
check_error( '' === AI_Chat_Bedrock_Bedrock_Errors::suggest_profile_id( '', 'us-west-2' ), 'no model suggests nothing' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: Bedrock error classification checks passed\n";
