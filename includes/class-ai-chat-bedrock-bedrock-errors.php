<?php
/**
 * Turn an Amazon Bedrock failure into the next thing to do.
 *
 * One message for several unrelated causes is not useful: "check model access and IAM
 * permissions" covers at least four situations with different fixes. The patterns below
 * were captured from real Bedrock responses rather than written from memory, because the
 * actual behaviour is not what one would guess. Notably a model the account cannot use
 * on demand answers with ValidationException, not AccessDeniedException.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Bedrock_Errors {

	/**
	 * Classify a failure and describe the fix.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw response body.
	 * @param string $model  Model identifier that was called.
	 * @param string $region Region that was called.
	 * @return array Array with kind, message and optional model_hint keys.
	 */
	public static function explain( $status, $body, $model = '', $region = '' ) {
		$status = (int) $status;
		$body   = (string) $body;
		$model  = trim( (string) $model );
		$region = trim( (string) $region );

		$decoded = json_decode( $body, true );
		$detail  = '';
		if ( is_array( $decoded ) ) {
			foreach ( array( 'message', 'Message', 'errorMessage' ) as $key ) {
				if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
					$detail = $decoded[ $key ];
					break;
				}
			}
		}
		if ( '' === $detail ) {
			$detail = $body;
		}
		$haystack = strtolower( $detail );

		// Observed: ValidationException, "Invocation of model ID <id> with on-demand
		// throughput isn't supported. Retry your request with the ID or ARN of an
		// inference profile that contains this model." The apostrophe is a typographic
		// one in the real payload, so it is not part of the match.
		if ( false !== strpos( $haystack, 'on-demand throughput' ) ) {
			return array(
				'kind'       => 'needs_inference_profile',
				'message'    => __( 'This model cannot be called directly. Use the cross-region inference profile ID for it, which starts with a prefix such as us. or eu. followed by the model ID.', 'ai-chat-for-amazon-bedrock' ),
				'model_hint' => self::suggest_profile_id( $model, $region ),
			);
		}

		// Observed: ResourceNotFoundException, "Access denied. This Model is marked by
		// provider as Legacy and you have not been actively using the model in the last
		// 30 days." The wording says access denied, but the fix is to pick another model.
		if ( false !== strpos( $haystack, 'legacy' ) ) {
			return array(
				'kind'    => 'legacy_model',
				'message' => __( 'The model provider has retired this model for accounts that were not already using it. Choose a current model on the settings screen.', 'ai-chat-for-amazon-bedrock' ),
			);
		}

		// Observed: ValidationException, "The provided model identifier is invalid." The
		// same message covers a typo and a model that region does not offer, so both are
		// named rather than guessing.
		if ( false !== strpos( $haystack, 'model identifier is invalid' ) ) {
			return array(
				'kind'    => 'unknown_model',
				'message' => '' !== $region
					/* translators: %s: AWS region code. */
					? sprintf( __( 'Amazon Bedrock does not recognise this model ID in %s. Either the ID is wrong, or that region does not offer the model. Refresh the model list to see what this region offers.', 'ai-chat-for-amazon-bedrock' ), $region )
					: __( 'Amazon Bedrock does not recognise this model ID in the configured region. Either the ID is wrong, or the region does not offer the model.', 'ai-chat-for-amazon-bedrock' ),
			);
		}

		// Documented rather than reproduced here: an account that has not been granted
		// access to the model answers with AccessDeniedException naming the model.
		if ( false !== strpos( $haystack, "don't have access to the model" ) || false !== strpos( $haystack, 'do not have access to the model' ) ) {
			return array(
				'kind'    => 'model_access_missing',
				'message' => __( 'This account has not been granted access to the model. Request access for it in the Amazon Bedrock console, under Model access, in this region.', 'ai-chat-for-amazon-bedrock' ),
			);
		}

		// An IAM denial names the action it refused, which is the actionable part.
		if ( false !== strpos( $haystack, 'is not authorized to perform' ) ) {
			return array(
				'kind'    => 'iam_denied',
				'message' => self::denied_action_message( $detail ),
			);
		}

		if ( 403 === $status || 401 === $status ) {
			return array(
				'kind'    => 'forbidden',
				'message' => __( 'Amazon Bedrock refused the request. Attach the generated IAM policy from the Diagnostics screen, and confirm model access is granted in this region.', 'ai-chat-for-amazon-bedrock' ),
			);
		}

		if ( 429 === $status ) {
			return array(
				'kind'    => 'throttled',
				'message' => __( 'Amazon Bedrock is throttling requests. Retry shortly, lower the per-minute limit, or request a quota increase.', 'ai-chat-for-amazon-bedrock' ),
			);
		}

		if ( $status >= 500 ) {
			return array(
				'kind'    => 'service_error',
				'message' => __( 'Amazon Bedrock reported a service error. Retrying usually succeeds; a configured fallback model is used automatically.', 'ai-chat-for-amazon-bedrock' ),
			);
		}

		return array(
			'kind'    => 'unknown',
			'message' => sprintf(
				/* translators: %d: HTTP status code returned by Amazon Bedrock. */
				__( 'Amazon Bedrock returned HTTP %d. The Diagnostics screen lists the permissions this site needs.', 'ai-chat-for-amazon-bedrock' ),
				$status
			),
		);
	}

	/**
	 * Name the action IAM refused so the policy can be corrected.
	 *
	 * @param string $detail Message from Bedrock.
	 * @return string
	 */
	private static function denied_action_message( $detail ) {
		if ( preg_match( '/not authorized to perform:?\s*([a-z0-9-]+:[A-Za-z0-9]+)/i', $detail, $match ) ) {
			return sprintf(
				/* translators: %s: IAM action name, such as bedrock:InvokeModelWithResponseStream. */
				__( 'The AWS identity is not allowed to perform %s. Attach the generated IAM policy from the Diagnostics screen, which includes it.', 'ai-chat-for-amazon-bedrock' ),
				$match[1]
			);
		}
		return __( 'The AWS identity is missing a required permission. The Diagnostics screen generates the policy this site needs.', 'ai-chat-for-amazon-bedrock' );
	}

	/**
	 * The inference profile ID that most likely fits a region.
	 *
	 * @param string $model  Model identifier.
	 * @param string $region Region code.
	 * @return string Suggested identifier, or an empty string.
	 */
	public static function suggest_profile_id( $model, $region ) {
		$model = trim( (string) $model );
		if ( '' === $model || ( class_exists( 'AI_Chat_Bedrock_Iam_Policy' ) && AI_Chat_Bedrock_Iam_Policy::is_inference_profile( $model ) ) ) {
			return '';
		}

		$prefixes = array(
			'us-' => 'us.',
			'eu-' => 'eu.',
			'ap-' => 'apac.',
			'ca-' => 'us.',
			'sa-' => 'us.',
		);
		foreach ( $prefixes as $region_prefix => $model_prefix ) {
			if ( 0 === strpos( (string) $region, $region_prefix ) ) {
				return $model_prefix . $model;
			}
		}
		return '';
	}
}
