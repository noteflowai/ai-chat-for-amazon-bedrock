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

// --- Model access not granted, from the documented wording ------------------

$result = AI_Chat_Bedrock_Bedrock_Errors::explain( 403, '{"message":"You don\'t have access to the model with the specified model ID."}', 'anthropic.claude-3-5-sonnet-20240620-v1:0', 'us-east-1' );
check_error( 'model_access_missing' === $result['kind'], 'a missing model grant is recognised, got ' . $result['kind'] );
check_error( false !== stripos( $result['message'], 'model access' ), 'the fix points at model access' );

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
