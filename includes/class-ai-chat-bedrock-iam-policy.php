<?php
/**
 * Build the least-privilege IAM policy this site actually needs.
 *
 * "Check your IAM permissions" is not an instruction anyone can follow. This produces a
 * policy document scoped to the models and resources this site is configured to use, so
 * an administrator can paste it into IAM instead of reaching for AmazonBedrockFullAccess.
 *
 * Action names and ARN shapes follow the Amazon Bedrock documentation:
 * - InvokeModel and InvokeModelWithResponseStream are separate actions, so granting only
 *   the first is why chat works while streaming fails.
 * - ListFoundationModels takes no resource, so it has to be "*".
 * - Passing a guardrail to InvokeModel additionally requires ApplyGuardrail.
 * - Foundation model ARNs carry no account ID; guardrails, prompts and knowledge bases do.
 * - Naming an inference profile also requires the underlying foundation model in every
 *   Region the profile spans.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Iam_Policy {

	/**
	 * Placeholder used when the account ID could not be determined.
	 *
	 * A wildcard keeps the document valid and pasteable. It only ever matches resources in
	 * the caller's own account, and the screen says to replace it.
	 */
	const ACCOUNT_WILDCARD = '*';

	/**
	 * Shorten a caller ARN for display.
	 *
	 * The Diagnostics screen shows which identity the plugin is signing with, and the useful
	 * part of that is the role or user name. The rest identifies infrastructure: the full
	 * account number, and for an assumed role the session name, which on EC2 is the instance
	 * id. Admins share this screen in support threads and in screenshots, so printing the
	 * whole ARN hands out more than the question needs.
	 *
	 * Enough is kept to recognise the identity: the last four digits of the account, and the
	 * role or user name. The generated policy below is unaffected, because that has to stay
	 * pasteable and therefore has to carry the real account.
	 *
	 * @param string $arn Caller ARN from GetCallerIdentity.
	 * @return string Display form, or an empty string when there is nothing to show.
	 */
	public static function display_identity( $arn ) {
		$arn = trim( (string) $arn );
		if ( '' === $arn ) {
			return '';
		}
		// arn:aws:sts::123456789012:assumed-role/Role/session or arn:aws:iam::123456789012:user/Name
		if ( ! preg_match( '#^(arn:[a-z0-9-]+:(?:sts|iam)::)(\d{4,})(:[a-z-]+/)(.+)$#i', $arn, $parts ) ) {
			return $arn;
		}
		$account = str_repeat( '*', max( 0, strlen( $parts[2] ) - 4 ) ) . substr( $parts[2], -4 );
		$path    = explode( '/', $parts[4] );
		// Drop the session name; the role or user name is what identifies the permission set.
		return $parts[1] . $account . $parts[3] . $path[0];
	}

	/**
	 * Prefixes that mark a cross-Region inference profile rather than a plain model.
	 *
	 * @return array
	 */
	public static function profile_prefixes() {
		return array( 'us.', 'eu.', 'apac.', 'apne.', 'jp.', 'au.', 'ca.', 'us-gov.', 'global.' );
	}

	/**
	 * Whether a model identifier names an inference profile.
	 *
	 * @param string $model_id Model identifier.
	 * @return bool
	 */
	public static function is_inference_profile( $model_id ) {
		$model_id = (string) $model_id;
		foreach ( self::profile_prefixes() as $prefix ) {
			if ( 0 === strpos( $model_id, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The underlying foundation model for an identifier.
	 *
	 * @param string $model_id Model identifier.
	 * @return string
	 */
	public static function base_model_id( $model_id ) {
		$model_id = (string) $model_id;
		foreach ( self::profile_prefixes() as $prefix ) {
			if ( 0 === strpos( $model_id, $prefix ) ) {
				return substr( $model_id, strlen( $prefix ) );
			}
		}
		return $model_id;
	}

	/**
	 * Resource ARNs that allow invoking one model.
	 *
	 * @param string $model_id Model identifier.
	 * @param string $region   Region the site calls.
	 * @param string $account  Account ID, or the wildcard when unknown.
	 * @return array
	 */
	public static function model_resources( $model_id, $region, $account ) {
		$model_id = trim( (string) $model_id );
		if ( '' === $model_id ) {
			return array();
		}

		$base = self::base_model_id( $model_id );

		if ( ! self::is_inference_profile( $model_id ) ) {
			return array( sprintf( 'arn:aws:bedrock:%s::foundation-model/%s', $region, $base ) );
		}

		// The profile itself, plus the foundation model. The Region is left wildcarded for
		// the model because a profile routes to several Regions and each one has to be
		// allowed; listing them would mean hard-coding a mapping that AWS changes.
		return array(
			sprintf( 'arn:aws:bedrock:%s:%s:inference-profile/%s', $region, $account, $model_id ),
			sprintf( 'arn:aws:bedrock:*::foundation-model/%s', $base ),
		);
	}

	/**
	 * Build the policy document for a configuration.
	 *
	 * Recognised keys: region, account, models, streaming, guardrail_id, prompt_id,
	 * knowledge_base and agentcore. Everything except region and models is optional, and
	 * an unset feature produces no statement for it.
	 *
	 * @param array $config Configuration to build from.
	 * @return array Policy document.
	 */
	public static function build( $config ) {
		$config = is_array( $config ) ? $config : array();

		$region  = self::clean_region( isset( $config['region'] ) ? $config['region'] : '' );
		$account = self::clean_account( isset( $config['account'] ) ? $config['account'] : '' );

		$models = array();
		foreach ( (array) ( isset( $config['models'] ) ? $config['models'] : array() ) as $model ) {
			$model = trim( (string) $model );
			if ( '' !== $model && ! in_array( $model, $models, true ) ) {
				$models[] = $model;
			}
		}

		$resources = array();
		foreach ( $models as $model ) {
			foreach ( self::model_resources( $model, $region, $account ) as $arn ) {
				if ( ! in_array( $arn, $resources, true ) ) {
					$resources[] = $arn;
				}
			}
		}

		$statements = array();

		if ( $resources ) {
			$actions = array( 'bedrock:InvokeModel' );
			if ( ! empty( $config['streaming'] ) ) {
				// A distinct action. Streaming is the default, so this is normally present.
				$actions[] = 'bedrock:InvokeModelWithResponseStream';
			}
			$statements[] = array(
				'Sid'      => 'AICFABInvokeConfiguredModels',
				'Effect'   => 'Allow',
				'Action'   => $actions,
				'Resource' => $resources,
			);
		}

		// The model picker lists what the account can use. No resource-level scoping exists.
		$statements[] = array(
			'Sid'      => 'AICFABListModelsForTheModelPicker',
			'Effect'   => 'Allow',
			'Action'   => array( 'bedrock:ListFoundationModels' ),
			'Resource' => '*',
		);

		$guardrail = self::clean_id( isset( $config['guardrail_id'] ) ? $config['guardrail_id'] : '' );
		if ( '' !== $guardrail ) {
			$statements[] = array(
				'Sid'      => 'AICFABApplyConfiguredGuardrail',
				'Effect'   => 'Allow',
				'Action'   => array( 'bedrock:ApplyGuardrail' ),
				'Resource' => array( sprintf( 'arn:aws:bedrock:%s:%s:guardrail/%s', $region, $account, $guardrail ) ),
			);
		}

		$prompt = self::clean_id( isset( $config['prompt_id'] ) ? $config['prompt_id'] : '' );
		if ( '' !== $prompt ) {
			$statements[] = array(
				'Sid'      => 'AICFABReadManagedPrompt',
				'Effect'   => 'Allow',
				'Action'   => array( 'bedrock:GetPrompt' ),
				'Resource' => array( sprintf( 'arn:aws:bedrock:%s:%s:prompt/%s', $region, $account, $prompt ) ),
			);
		}

		$knowledge_base = self::clean_id( isset( $config['knowledge_base'] ) ? $config['knowledge_base'] : '' );
		if ( '' !== $knowledge_base ) {
			$statements[] = array(
				'Sid'      => 'AICFABRetrieveFromKnowledgeBase',
				'Effect'   => 'Allow',
				'Action'   => array( 'bedrock:Retrieve' ),
				'Resource' => array( sprintf( 'arn:aws:bedrock:%s:%s:knowledge-base/%s', $region, $account, $knowledge_base ) ),
			);
		}

		$gateways = array();
		foreach ( (array) ( isset( $config['agentcore'] ) ? $config['agentcore'] : array() ) as $gateway_region ) {
			$gateway_region = self::clean_region( $gateway_region );
			$arn            = sprintf( 'arn:aws:bedrock-agentcore:%s:%s:gateway/*', $gateway_region, $account );
			if ( ! in_array( $arn, $gateways, true ) ) {
				$gateways[] = $arn;
			}
		}
		if ( $gateways ) {
			// A different service prefix, which is easy to miss when writing this by hand.
			$statements[] = array(
				'Sid'      => 'AICFABInvokeAgentCoreGateway',
				'Effect'   => 'Allow',
				'Action'   => array( 'bedrock-agentcore:InvokeGateway' ),
				'Resource' => $gateways,
			);
		}

		return array(
			'Version'   => '2012-10-17',
			'Statement' => $statements,
		);
	}

	/**
	 * The policy for the current site configuration.
	 *
	 * @param array  $options Plugin settings.
	 * @param string $account Account ID, empty when unknown.
	 * @return array
	 */
	public static function for_site( $options, $account = '' ) {
		$options = is_array( $options ) ? $options : array();

		$models = array(
			isset( $options['model_id'] ) ? $options['model_id'] : '',
			isset( $options['fallback_model_id'] ) ? $options['fallback_model_id'] : '',
			isset( $options['embedding_model_id'] ) ? $options['embedding_model_id'] : '',
		);

		// Profiles may pin their own model, and each one costs money too.
		if ( class_exists( 'AI_Chat_Bedrock_Profiles' ) ) {
			foreach ( AI_Chat_Bedrock_Profiles::all() as $profile ) {
				if ( ! empty( $profile['model_id'] ) ) {
					$models[] = $profile['model_id'];
				}
			}
		}

		$agentcore = array();
		if ( class_exists( 'AI_Chat_Bedrock_MCP_Client' ) ) {
			foreach ( self::agentcore_regions() as $gateway_region ) {
				$agentcore[] = $gateway_region;
			}
		}

		return self::build(
			array(
				'region'         => isset( $options['aws_region'] ) ? $options['aws_region'] : 'us-east-1',
				'account'        => $account,
				'models'         => $models,
				'streaming'      => ! isset( $options['enable_streaming'] ) || 'off' !== $options['enable_streaming'],
				'guardrail_id'   => isset( $options['guardrail_id'] ) ? $options['guardrail_id'] : '',
				'prompt_id'      => isset( $options['prompt_id'] ) ? $options['prompt_id'] : '',
				'knowledge_base' => isset( $options['knowledge_base_id'] ) ? $options['knowledge_base_id'] : '',
				'agentcore'      => $agentcore,
			)
		);
	}

	/**
	 * Regions of registered MCP servers that are signed for AgentCore.
	 *
	 * @return array
	 */
	private static function agentcore_regions() {
		$regions = array();
		$servers = get_option( 'ai_chat_bedrock_mcp_servers', array() );
		if ( ! is_array( $servers ) ) {
			return $regions;
		}

		foreach ( $servers as $server ) {
			if ( ! is_array( $server ) || 'sigv4' !== ( isset( $server['auth'] ) ? $server['auth'] : '' ) ) {
				continue;
			}
			$service = isset( $server['auth_service'] ) ? (string) $server['auth_service'] : 'bedrock-agentcore';
			if ( 'bedrock-agentcore' !== $service ) {
				continue;
			}
			$region = self::clean_region( isset( $server['auth_region'] ) ? $server['auth_region'] : '' );
			if ( ! in_array( $region, $regions, true ) ) {
				$regions[] = $region;
			}
		}
		return $regions;
	}

	/**
	 * The policy as formatted JSON, ready to paste into IAM.
	 *
	 * @param array $policy Policy document.
	 * @return string
	 */
	public static function to_json( $policy ) {
		$flags = 0;
		if ( defined( 'JSON_PRETTY_PRINT' ) ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}
		$json = wp_json_encode( $policy, $flags );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * Whether the account ID could not be resolved, so the document carries a wildcard.
	 *
	 * Decided from the account value rather than by searching the rendered JSON: a
	 * legitimate Region wildcard in a foundation-model ARN also produces ":*:".
	 *
	 * @param string $account Account ID as resolved, empty when unknown.
	 * @return bool
	 */
	public static function needs_account_id( $account ) {
		return self::ACCOUNT_WILDCARD === self::clean_account( $account );
	}

	private static function clean_region( $region ) {
		$region = strtolower( trim( (string) $region ) );
		return preg_match( '/^[a-z]{2}(-[a-z]+)+-\d$/', $region ) ? $region : 'us-east-1';
	}

	private static function clean_account( $account ) {
		$account = preg_replace( '/[^0-9]/', '', (string) $account );
		return ( is_string( $account ) && 12 === strlen( $account ) ) ? $account : self::ACCOUNT_WILDCARD;
	}

	private static function clean_id( $value ) {
		$value = trim( (string) $value );
		// Identifiers reach IAM as written, so refuse anything that is not an identifier.
		return preg_match( '/^[A-Za-z0-9._:\/-]{1,256}$/', $value ) ? $value : '';
	}
}
