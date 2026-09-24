<?php
/**
 * Configuration diagnostics and Site Health integration.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Diagnostics {

	/**
	 * Register Site Health tests.
	 *
	 * @param array $tests Existing tests.
	 * @return array
	 */
	public function register_site_health_tests( $tests ) {
		$tests['direct']['ai_chat_bedrock_configuration'] = array(
			'label' => __( 'AI Chat for Amazon Bedrock configuration', 'ai-chat-for-amazon-bedrock' ),
			'test'  => array( $this, 'site_health_configuration' ),
		);
		return $tests;
	}

	/**
	 * Site Health result for plugin configuration.
	 *
	 * @return array
	 */
	public function site_health_configuration() {
		$checks   = $this->run( false );
		$failed   = array();
		$warnings = array();

		foreach ( $checks as $check ) {
			if ( 'fail' === $check['status'] ) {
				$failed[] = $check;
			} elseif ( 'warn' === $check['status'] ) {
				$warnings[] = $check;
			}
		}

		$result = array(
			'label'       => __( 'Amazon Bedrock chat is configured correctly', 'ai-chat-for-amazon-bedrock' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'AI Chat Bedrock', 'ai-chat-for-amazon-bedrock' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Credentials, region, model access, and chat security defaults were verified.', 'ai-chat-for-amazon-bedrock' ) . '</p>',
			'actions'     => '',
			'test'        => 'ai_chat_bedrock_configuration',
		);

		$problems = array_merge( $failed, $warnings );
		if ( ! empty( $problems ) ) {
			$result['status'] = empty( $failed ) ? 'recommended' : 'critical';
			$result['label']  = empty( $failed )
				? __( 'Amazon Bedrock chat has configuration recommendations', 'ai-chat-for-amazon-bedrock' )
				: __( 'Amazon Bedrock chat is not fully configured', 'ai-chat-for-amazon-bedrock' );

			$items = '';
			foreach ( $problems as $problem ) {
				$items .= '<li><strong>' . esc_html( $problem['label'] ) . ':</strong> ' . esc_html( $problem['message'] ) . '</li>';
			}
			$result['description'] = '<ul>' . $items . '</ul>';
			$result['actions']     = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-diagnostics' ) ),
				esc_html__( 'Open Bedrock diagnostics', 'ai-chat-for-amazon-bedrock' )
			);
		}

		return $result;
	}

	/**
	 * Run configuration checks.
	 *
	 * @param bool $include_live Whether to perform a live Bedrock invocation.
	 * @return array
	 */
	public function run( $include_live = false ) {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$checks  = array();

		$checks[] = $this->check_php();
		$checks[] = $this->check_credentials( $options );
		$checks[] = $this->check_region( $options );
		$checks[] = $this->check_model( $options );
		$checks[] = $this->check_streaming( $options );
		$checks[] = $this->check_encryption();
		$checks[] = $this->check_public_access( $options );
		$checks[] = $this->check_mcp();
		$checks[] = $this->check_guardrail( $options );
		$checks[] = $this->check_knowledge_base( $options );

		if ( $include_live ) {
			$checks[] = $this->check_live_invocation( $options );
		}

		return $checks;
	}

	private function check_php() {
		$ok = version_compare( PHP_VERSION, '7.4', '>=' );
		return $this->result(
			'php',
			__( 'PHP version', 'ai-chat-for-amazon-bedrock' ),
			$ok ? 'pass' : 'fail',
			$ok
				/* translators: %s: PHP version. */
				? sprintf( __( 'PHP %s meets the plugin requirement.', 'ai-chat-for-amazon-bedrock' ), PHP_VERSION )
				: __( 'PHP 7.4 or newer is required.', 'ai-chat-for-amazon-bedrock' )
		);
	}

	private function check_credentials( $options ) {
		$status = AI_Chat_Bedrock_AWS_Credentials::describe( $options );
		if ( ! $status['configured'] ) {
			return $this->result( 'credentials', __( 'AWS credentials', 'ai-chat-for-amazon-bedrock' ), 'fail', __( 'No usable AWS credentials were found. Paste an Amazon Bedrock API key, add access keys, or enable IAM role credentials.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$state   = in_array( $status['source'], array( 'options' ), true ) ? 'warn' : 'pass';
		$message = $status['message'];
		if ( 'options' === $status['source'] ) {
			$message .= ' ' . __( 'Consider wp-config.php constants or an IAM role so keys are not stored in the database.', 'ai-chat-for-amazon-bedrock' );
		}
		if ( 0 === strpos( $status['source'], 'api_key_' ) ) {
			if ( ! empty( $status['temporary'] ) ) {
				$state    = 'warn';
				$message .= ' ' . __( 'This is a short-term key, which stops working within 12 hours. Use a long-term key or an IAM role.', 'ai-chat-for-amazon-bedrock' );
			}
			// Agents endpoints refuse API keys, so a feature that uses them needs signing credentials too.
			$needs_signing = ! empty( $options['knowledge_base_id'] ) || ! empty( $options['prompt_id'] );
			if ( $needs_signing && ! ( new AI_Chat_Bedrock_AWS() )->has_signing_credentials() ) {
				$state    = 'warn';
				$message .= ' ' . __( 'A Knowledge Base or managed prompt is configured, and those only accept signed requests. Add access keys or an IAM role for them; chat keeps using the API key.', 'ai-chat-for-amazon-bedrock' );
			}
		}
		return $this->result( 'credentials', __( 'AWS credentials', 'ai-chat-for-amazon-bedrock' ), $state, $message );
	}

	private function check_region( $options ) {
		$region  = isset( $options['aws_region'] ) ? sanitize_key( $options['aws_region'] ) : '';
		$regions = AI_Chat_Bedrock_Models::regions();
		if ( '' === $region || ! isset( $regions[ $region ] ) ) {
			return $this->result( 'region', __( 'AWS region', 'ai-chat-for-amazon-bedrock' ), 'fail', __( 'Select a supported AWS region for Amazon Bedrock.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return $this->result( 'region', __( 'AWS region', 'ai-chat-for-amazon-bedrock' ), 'pass', $regions[ $region ] . ' (' . $region . ')' );
	}

	private function check_model( $options ) {
		$model = isset( $options['model_id'] ) ? (string) $options['model_id'] : '';
		if ( ! AI_Chat_Bedrock_Models::is_valid_id( $model ) ) {
			return $this->result( 'model', __( 'Model selection', 'ai-chat-for-amazon-bedrock' ), 'fail', __( 'Select a Bedrock model in the settings.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$catalog = AI_Chat_Bedrock_Models::options();
		if ( ! isset( $catalog[ $model ] ) ) {
			return $this->result( 'model', __( 'Model selection', 'ai-chat-for-amazon-bedrock' ), 'warn', __( 'The selected model was not returned by the region catalog. Confirm model access and whether an inference profile ID is required.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return $this->result( 'model', __( 'Model selection', 'ai-chat-for-amazon-bedrock' ), 'pass', $model );
	}

	private function check_streaming( $options ) {
		$requested = ! isset( $options['enable_streaming'] ) || 'off' !== $options['enable_streaming'];
		$supported = AI_Chat_Bedrock_AWS::streaming_supported();

		if ( ! $requested ) {
			return $this->result( 'streaming', __( 'Streaming', 'ai-chat-for-amazon-bedrock' ), 'warn', __( 'Streaming is disabled, so answers appear only when generation finishes.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( ! $supported ) {
			return $this->result( 'streaming', __( 'Streaming', 'ai-chat-for-amazon-bedrock' ), 'warn', __( 'The PHP cURL extension is unavailable, so buffered responses are used instead of streaming.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return $this->result( 'streaming', __( 'Streaming', 'ai-chat-for-amazon-bedrock' ), 'pass', __( 'Streaming responses are enabled with automatic fallback.', 'ai-chat-for-amazon-bedrock' ) );
	}

	private function check_encryption() {
		$ok = function_exists( 'sodium_crypto_secretbox' );
		return $this->result(
			'encryption',
			__( 'Credential encryption', 'ai-chat-for-amazon-bedrock' ),
			$ok ? 'pass' : 'warn',
			$ok
				? __( 'Stored credentials are encrypted with authenticated encryption.', 'ai-chat-for-amazon-bedrock' )
				: __( 'Sodium is unavailable, so credentials cannot be encrypted in the database. Use wp-config.php constants or an IAM role.', 'ai-chat-for-amazon-bedrock' )
		);
	}

	private function check_public_access( $options ) {
		if ( empty( $options['allow_public_chat'] ) ) {
			return $this->result( 'guest_access', __( 'Guest access', 'ai-chat-for-amazon-bedrock' ), 'pass', __( 'Only signed-in users can start paid Bedrock requests.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$limit = isset( $options['rate_limit_per_minute'] ) ? absint( $options['rate_limit_per_minute'] ) : 5;

		// Report the limit guests actually get, which a per-role override can change.
		$guest_limit = $limit;
		if ( class_exists( 'AI_Chat_Bedrock_Rate_Limits' ) ) {
			$overrides = AI_Chat_Bedrock_Rate_Limits::all();
			if ( isset( $overrides[ AI_Chat_Bedrock_Rate_Limits::GUEST_KEY ] ) ) {
				$guest_limit = (int) $overrides[ AI_Chat_Bedrock_Rate_Limits::GUEST_KEY ];
			}
		}

		return $this->result(
			'guest_access',
			__( 'Guest access', 'ai-chat-for-amazon-bedrock' ),
			'warn',
			sprintf(
				/* translators: %d: requests allowed per visitor per minute. */
				__( 'Guest chat is enabled with %d requests per visitor each minute. Confirm AWS Budgets and model pricing.', 'ai-chat-for-amazon-bedrock' ),
				max( 1, $guest_limit )
			)
		);
	}

	/**
	 * Whether a configured guardrail actually exists and is ready.
	 *
	 * Bedrock fails closed here: a wrong identifier makes every request fail with a
	 * ValidationException, so the chat stops working entirely. Nothing checked it before,
	 * which meant finding out from a visitor.
	 *
	 * @param array $options Plugin settings.
	 * @return array
	 */
	private function check_guardrail( $options ) {
		$label      = __( 'Guardrail', 'ai-chat-for-amazon-bedrock' );
		$identifier = isset( $options['guardrail_id'] ) ? trim( (string) $options['guardrail_id'] ) : '';
		if ( '' === $identifier ) {
			return $this->result( 'guardrail', $label, 'pass', __( 'No guardrail is configured, so nothing is filtered by Amazon Bedrock.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$version = isset( $options['guardrail_version'] ) ? trim( (string) $options['guardrail_version'] ) : '';
		$aws     = new AI_Chat_Bedrock_AWS();
		if ( ! $aws->has_credentials() ) {
			return $this->result( 'guardrail', $label, 'warn', __( 'A guardrail is configured but credentials are missing, so it cannot be verified.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$guardrail = $aws->get_guardrail( $identifier, $version );
		if ( is_wp_error( $guardrail ) ) {
			return $this->result(
				'guardrail',
				$label,
				'fail',
				sprintf(
					/* translators: %s: reason the guardrail could not be read. */
					__( 'The configured guardrail could not be read, so every chat request will be refused: %s', 'ai-chat-for-amazon-bedrock' ),
					$guardrail->get_error_message()
				)
			);
		}

		if ( '' !== $guardrail['status'] && 'READY' !== strtoupper( $guardrail['status'] ) ) {
			return $this->result(
				'guardrail',
				$label,
				'warn',
				sprintf(
					/* translators: 1: guardrail name, 2: guardrail status reported by AWS. */
					__( 'Guardrail "%1$s" is %2$s rather than READY.', 'ai-chat-for-amazon-bedrock' ),
					$guardrail['name'],
					$guardrail['status']
				)
			);
		}

		return $this->result(
			'guardrail',
			$label,
			'pass',
			sprintf(
				/* translators: 1: guardrail name, 2: guardrail version. */
				__( 'Guardrail "%1$s" is ready, version %2$s.', 'ai-chat-for-amazon-bedrock' ),
				$guardrail['name'],
				'' !== $guardrail['version'] ? $guardrail['version'] : 'DRAFT'
			)
		);
	}

	/**
	 * Whether a configured knowledge base ID can work.
	 *
	 * The identifier has a fixed shape, so an obvious typo is caught without calling AWS at
	 * all. Anything that could be real is then tried for real, since a plausible but wrong
	 * ID otherwise fails silently the first time a visitor asks something.
	 *
	 * @param array $options Plugin settings.
	 * @return array
	 */
	private function check_knowledge_base( $options ) {
		$label = __( 'Knowledge base', 'ai-chat-for-amazon-bedrock' );
		$id    = isset( $options['knowledge_base_id'] ) ? trim( (string) $options['knowledge_base_id'] ) : '';
		if ( '' === $id ) {
			return $this->result( 'knowledge_base', $label, 'pass', __( 'No Amazon Bedrock knowledge base is configured.', 'ai-chat-for-amazon-bedrock' ) );
		}

		// AWS requires ten alphanumeric characters, or a knowledge base ARN.
		if ( ! preg_match( '#^[0-9a-zA-Z]{10}$#', $id ) && ! preg_match( '#^arn:aws(-[^:]+)?:bedrock:[a-z0-9-]{1,20}:[0-9]{12}:knowledge-base/[0-9a-zA-Z]{10}$#', $id ) ) {
			return $this->result(
				'knowledge_base',
				$label,
				'fail',
				__( 'The knowledge base ID is not the right shape. AWS expects ten letters or digits, or a knowledge base ARN.', 'ai-chat-for-amazon-bedrock' )
			);
		}

		$aws = new AI_Chat_Bedrock_AWS();
		if ( ! $aws->has_credentials() ) {
			return $this->result( 'knowledge_base', $label, 'warn', __( 'A knowledge base is configured but credentials are missing, so it cannot be verified.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$result = $aws->retrieve_from_knowledge_base( $id, 'test', 1 );
		if ( is_wp_error( $result ) ) {
			return $this->result(
				'knowledge_base',
				$label,
				'fail',
				sprintf(
					/* translators: %s: reason the knowledge base could not be queried. */
					__( 'The knowledge base could not be queried: %s', 'ai-chat-for-amazon-bedrock' ),
					$result->get_error_message()
				)
			);
		}

		return $this->result( 'knowledge_base', $label, 'pass', __( 'The knowledge base answered a test query.', 'ai-chat-for-amazon-bedrock' ) );
	}

	private function check_mcp() {
		if ( ! get_option( 'ai_chat_bedrock_enable_mcp', false ) ) {
			return $this->result( 'mcp', __( 'MCP tools', 'ai-chat-for-amazon-bedrock' ), 'pass', __( 'MCP tools are disabled.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( get_option( 'ai_chat_bedrock_mcp_public_access', false ) ) {
			return $this->result( 'mcp', __( 'MCP tools', 'ai-chat-for-amazon-bedrock' ), 'warn', __( 'The built-in WordPress MCP endpoint is publicly readable. Disable public access unless it is required.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return $this->result( 'mcp', __( 'MCP tools', 'ai-chat-for-amazon-bedrock' ), 'pass', __( 'MCP tools are enabled and require authentication.', 'ai-chat-for-amazon-bedrock' ) );
	}

	private function check_live_invocation( $options ) {
		$aws    = new AI_Chat_Bedrock_AWS();
		$result = $aws->test_model_access( isset( $options['model_id'] ) ? $options['model_id'] : '' );

		if ( empty( $result['success'] ) ) {
			return $this->result( 'invocation', __( 'Model invocation', 'ai-chat-for-amazon-bedrock' ), 'fail', $result['message'] );
		}

		$message = $result['message'];
		if ( isset( $result['duration'] ) ) {
			/* translators: %d: round trip duration in milliseconds. */
			$message .= ' ' . sprintf( __( 'Round trip took %d ms.', 'ai-chat-for-amazon-bedrock' ), (int) $result['duration'] );
		}
		if ( isset( $result['usage']['input_tokens'], $result['usage']['output_tokens'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: 1: input token count, 2: output token count. */
				__( 'Tokens used: %1$d input, %2$d output.', 'ai-chat-for-amazon-bedrock' ),
				(int) $result['usage']['input_tokens'],
				(int) $result['usage']['output_tokens']
			);
		}
		return $this->result( 'invocation', __( 'Model invocation', 'ai-chat-for-amazon-bedrock' ), 'pass', $message );
	}

	private function result( $id, $label, $status, $message ) {
		return array(
			'id'      => sanitize_key( $id ),
			'label'   => $label,
			'status'  => in_array( $status, array( 'pass', 'warn', 'fail' ), true ) ? $status : 'warn',
			'message' => $message,
		);
	}
}
