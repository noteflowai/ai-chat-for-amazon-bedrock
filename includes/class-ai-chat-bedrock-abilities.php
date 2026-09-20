<?php
/**
 * WordPress Abilities API integration.
 *
 * Two directions are supported when the Abilities API is available:
 *   1. This plugin registers its own abilities so agents and other plugins can
 *      generate text with Amazon Bedrock through the standard registry.
 *   2. Abilities registered by other plugins can be offered to the chat model as
 *      tools, executed on the server under the same tool policy as MCP tools.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Abilities {

	const TOOL_PREFIX = 'wpability___';

	/*
	 * Core ships only the site and user categories, and a category is not optional in practice:
	 * wp_register_ability() returns null both for an unknown category and for none at all, so
	 * every ability here needs one that exists. Filing this plugin's abilities under core's
	 * "site" would misdescribe them, so it registers its own.
	 */
	const CATEGORY  = 'ai-chat-bedrock';
	const MAX_TOOLS = 20;
	const MAX_TEXT  = 4000;

	/**
	 * Whether abilities were already registered in this request.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Whether the Abilities API is available.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wp_register_ability' );
	}

	/**
	 * Whether local abilities may be offered to the chat model.
	 *
	 * @return bool
	 */
	public static function tools_enabled() {
		$enabled = (bool) get_option( 'ai_chat_bedrock_abilities_tools', false );
		return (bool) apply_filters( 'ai_chat_bedrock_abilities_tools_enabled', $enabled && self::available() );
	}

	/**
	 * Register the category this plugin's abilities belong to.
	 *
	 * Runs on wp_abilities_api_categories_init, which fires before abilities are registered.
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'AI Chat for Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ),
				'description' => __( 'Read-only lookups over published content, plus one additive action that creates a draft.', 'ai-chat-for-amazon-bedrock' ),
			)
		);
	}

	/**
	 * Register the abilities exposed by this plugin.
	 */
	public function register_abilities() {
		if ( ! self::available() || $this->registered ) {
			return;
		}
		$this->registered = true;

		wp_register_ability(
			'ai-chat-bedrock/generate-text',
			array(
				'label'               => __( 'Generate text with Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Send a prompt to the configured Amazon Bedrock model and return the generated text.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'prompt' => array(
							'type'        => 'string',
							'description' => __( 'The instruction to send to the model.', 'ai-chat-for-amazon-bedrock' ),
						),
						'system' => array(
							'type'        => 'string',
							'description' => __( 'Optional system instruction.', 'ai-chat-for-amazon-bedrock' ),
						),
					),
					'required'   => array( 'prompt' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'text'  => array( 'type' => 'string' ),
						'usage' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( $this, 'ability_generate_text' ),
				'category'            => self::CATEGORY,
				'meta'                => array(

					/*
					 * Nothing on the site changes, but this spends money on a Bedrock request,
					 * so readonly is deliberately not claimed: a client that treats readonly as
					 * free to call unattended would be billing the account to find out.
					 */
					'annotations' => array( 'destructive' => false ),
					'public'      => true,
				),
				'permission_callback' => array( $this, 'can_generate_text' ),
			)
		);

		wp_register_ability(
			'ai-chat-bedrock/get-status',
			array(
				'label'               => __( 'Read Amazon Bedrock chat status', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Return the plugin configuration status, including credential source, region, model and streaming state. No secret values are returned.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_get_status' ),
				'category'            => self::CATEGORY,
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'public'      => true,
				),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback for text generation.
	 *
	 * @return bool
	 */
	public function can_generate_text() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission callback for status reads.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Generate text with the configured Bedrock model.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function ability_generate_text( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$prompt = isset( $input['prompt'] ) ? sanitize_textarea_field( (string) $input['prompt'] ) : '';
		$system = isset( $input['system'] ) ? sanitize_textarea_field( (string) $input['system'] ) : '';

		if ( '' === $prompt ) {
			return new WP_Error( 'aicfab_missing_prompt', __( 'A prompt is required.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$prompt   = AI_Chat_Bedrock_Security::string_substr( $prompt, 0, self::MAX_TEXT );
		$messages = array();
		if ( '' !== $system ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => AI_Chat_Bedrock_Security::string_substr( $system, 0, self::MAX_TEXT ),
			);
		}
		$messages[] = array(
			'role'    => 'user',
			'content' => $prompt,
		);

		$aws      = new AI_Chat_Bedrock_AWS();
		$response = $aws->handle_chat_message( array( 'messages' => $messages ) );

		if ( empty( $response['success'] ) ) {
			$code    = isset( $response['data']['code'] ) ? $response['data']['code'] : 'aicfab_error';
			$message = isset( $response['data']['message'] ) ? $response['data']['message'] : __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( $code, $message );
		}

		return array(
			'text'  => isset( $response['data']['message'] ) ? (string) $response['data']['message'] : '',
			'usage' => isset( $response['usage'] ) ? $response['usage'] : array(),
		);
	}

	/**
	 * Return configuration status without secret values.
	 *
	 * @return array
	 */
	public function ability_get_status() {
		$options     = get_option( 'ai_chat_bedrock_settings', array() );
		$options     = is_array( $options ) ? $options : array();
		$credentials = AI_Chat_Bedrock_AWS_Credentials::describe( $options );

		return array(
			'credential_source' => $credentials['source'],
			'configured'        => (bool) $credentials['configured'],
			'region'            => isset( $options['aws_region'] ) ? (string) $options['aws_region'] : '',
			'model'             => isset( $options['model_id'] ) ? (string) $options['model_id'] : '',
			'streaming'         => AI_Chat_Bedrock_Chat_Request::streaming_enabled( $options ),
			'guest_chat'        => ! empty( $options['allow_public_chat'] ),
			'mcp_enabled'       => (bool) get_option( 'ai_chat_bedrock_enable_mcp', false ),
			'usage_today'       => AI_Chat_Bedrock_Usage::today_totals(),
		);
	}

	/**
	 * Offer allowed local abilities to the chat model as tools.
	 *
	 * @param array $payload Model payload.
	 * @return array
	 */
	public function add_ability_tools( $payload ) {
		if ( ! self::tools_enabled() || ! AI_Chat_Bedrock_Tool_Policy::current_user_may_use_tools() ) {
			return $payload;
		}

		$tools = isset( $payload['tools'] ) && is_array( $payload['tools'] ) ? $payload['tools'] : array();
		foreach ( $this->available_ability_tools() as $tool ) {
			if ( count( $tools ) >= 50 ) {
				break;
			}
			$tools[] = $tool;
		}

		if ( ! empty( $tools ) ) {
			$payload['tools'] = $tools;
		}
		return $payload;
	}

	/**
	 * Execute ability tool calls requested by the model.
	 *
	 * @param array $response Model response.
	 * @return array
	 */
	public function execute_ability_tools( $response ) {
		if ( ! is_array( $response ) || empty( $response['tool_calls'] ) || ! self::tools_enabled() ) {
			return $response;
		}
		if ( ! AI_Chat_Bedrock_Tool_Policy::current_user_may_use_tools() ) {
			unset( $response['tool_calls'] );
			return $response;
		}

		$round = isset( $response['tool_round'] ) ? max( 1, (int) $response['tool_round'] ) : 1;
		$calls = array();

		foreach ( (array) $response['tool_calls'] as $call ) {
			if ( ! is_array( $call ) || empty( $call['name'] ) || 0 !== strpos( (string) $call['name'], self::TOOL_PREFIX ) ) {
				$calls[] = $call;
				continue;
			}
			if ( isset( $call['result'] ) || isset( $call['error'] ) ) {
				$calls[] = $call;
				continue;
			}

			$tool_name  = (string) $call['name'];
			$ability_id = $this->ability_id_from_tool( $tool_name );
			$parameters = isset( $call['parameters'] ) && is_array( $call['parameters'] ) ? $call['parameters'] : array();
			$clean      = array(
				'id'   => isset( $call['id'] ) ? sanitize_key( $call['id'] ) : wp_generate_uuid4(),
				'name' => $tool_name,
			);

			$ability = '' !== $ability_id ? $this->get_ability( $ability_id ) : null;
			if ( null === $ability || ! AI_Chat_Bedrock_Tool_Policy::is_tool_allowed( $tool_name, $this->ability_description( $ability ) ) ) {
				$clean['error'] = array(
					'code'    => 'ability_not_allowed',
					'message' => __( 'This ability is not allowed by the site policy.', 'ai-chat-for-amazon-bedrock' ),
				);
				AI_Chat_Bedrock_Tool_Log::record(
					array(
						'tool'   => $tool_name,
						'status' => 'error',
						'error'  => 'ability_not_allowed',
						'keys'   => array_keys( $parameters ),
						'round'  => $round,
					)
				);
				$calls[] = $clean;
				continue;
			}

			$started = microtime( true );
			$result  = $this->run_ability( $ability, $parameters );
			$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $result ) ) {
				$clean['error'] = array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
				AI_Chat_Bedrock_Tool_Log::record(
					array(
						'tool'     => $tool_name,
						'status'   => 'error',
						'error'    => $result->get_error_code(),
						'duration' => $elapsed,
						'keys'     => array_keys( $parameters ),
						'round'    => $round,
					)
				);
			} else {
				$clean['result'] = $result;
				AI_Chat_Bedrock_Tool_Log::record(
					array(
						'tool'     => $tool_name,
						'status'   => 'ok',
						'duration' => $elapsed,
						'keys'     => array_keys( $parameters ),
						'round'    => $round,
					)
				);
			}
			$calls[] = $clean;
		}

		$response['tool_calls'] = $calls;
		return $response;
	}

	/**
	 * Ability-backed tools the current user may use.
	 *
	 * @return array
	 */
	public function available_ability_tools() {
		$tools = array();
		foreach ( $this->registered_abilities() as $ability ) {
			$id = $this->ability_name( $ability );
			if ( '' === $id || 0 === strpos( $id, 'ai-chat-bedrock/' ) ) {
				continue;
			}

			$tool_name   = self::TOOL_PREFIX . str_replace( array( '/', '-' ), array( '__', '_' ), $id );
			$description = $this->ability_description( $ability );
			if ( ! AI_Chat_Bedrock_Tool_Policy::is_tool_allowed( $tool_name, $description ) ) {
				continue;
			}
			if ( ! $this->ability_permitted( $ability ) ) {
				continue;
			}

			$tools[] = array(
				'name'         => $tool_name,
				'description'  => '' !== $description ? $description : $id,
				'input_schema' => $this->ability_schema( $ability ),
			);
			if ( count( $tools ) >= self::MAX_TOOLS ) {
				break;
			}
		}
		return $tools;
	}

	private function registered_abilities() {
		if ( function_exists( 'wp_get_abilities' ) ) {
			$abilities = wp_get_abilities();
			return is_array( $abilities ) ? $abilities : array();
		}
		return array();
	}

	private function get_ability( $ability_id ) {
		if ( function_exists( 'wp_get_ability' ) ) {
			$ability = wp_get_ability( $ability_id );
			return $ability ? $ability : null;
		}
		foreach ( $this->registered_abilities() as $ability ) {
			if ( $this->ability_name( $ability ) === $ability_id ) {
				return $ability;
			}
		}
		return null;
	}

	private function ability_id_from_tool( $tool_name ) {
		$suffix = substr( (string) $tool_name, strlen( self::TOOL_PREFIX ) );
		if ( '' === $suffix ) {
			return '';
		}

		foreach ( $this->registered_abilities() as $ability ) {
			$id = $this->ability_name( $ability );
			if ( '' === $id ) {
				continue;
			}
			if ( str_replace( array( '/', '-' ), array( '__', '_' ), $id ) === $suffix ) {
				return $id;
			}
		}
		return '';
	}

	private function ability_name( $ability ) {
		if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) ) {
			return (string) $ability->get_name();
		}
		if ( is_array( $ability ) && isset( $ability['name'] ) ) {
			return (string) $ability['name'];
		}
		return '';
	}

	private function ability_description( $ability ) {
		if ( is_object( $ability ) && method_exists( $ability, 'get_description' ) ) {
			return (string) $ability->get_description();
		}
		if ( is_array( $ability ) && isset( $ability['description'] ) ) {
			return (string) $ability['description'];
		}
		return '';
	}

	private function ability_schema( $ability ) {
		$schema = array(
			'type'       => 'object',
			'properties' => new stdClass(),
		);
		if ( is_object( $ability ) && method_exists( $ability, 'get_input_schema' ) ) {
			$candidate = $ability->get_input_schema();
			if ( is_array( $candidate ) && ! empty( $candidate ) ) {
				$schema = $candidate;
			}
		} elseif ( is_array( $ability ) && isset( $ability['input_schema'] ) && is_array( $ability['input_schema'] ) ) {
			$schema = $ability['input_schema'];
		}
		return $schema;
	}

	private function ability_permitted( $ability ) {
		if ( is_object( $ability ) && method_exists( $ability, 'has_permission' ) ) {
			return (bool) $ability->has_permission();
		}
		return current_user_can( AI_Chat_Bedrock_Tool_Policy::required_capability() );
	}

	private function run_ability( $ability, $parameters ) {
		if ( ! $this->ability_permitted( $ability ) ) {
			return new WP_Error( 'ability_forbidden', __( 'You are not allowed to run this ability.', 'ai-chat-for-amazon-bedrock' ) );
		}

		if ( is_object( $ability ) && method_exists( $ability, 'execute' ) ) {
			$result = $ability->execute( $parameters );
		} elseif ( function_exists( 'wp_execute_ability' ) ) {
			$result = wp_execute_ability( $this->ability_name( $ability ), $parameters );
		} else {
			return new WP_Error( 'ability_unavailable', __( 'The Abilities API is not available.', 'ai-chat-for-amazon-bedrock' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->truncate_result( $result );
	}

	private function truncate_result( $result ) {
		$encoded = wp_json_encode( $result );
		if ( is_string( $encoded ) && strlen( $encoded ) > 20000 ) {
			return array(
				'truncated' => true,
				'preview'   => substr( $encoded, 0, 20000 ),
			);
		}
		return $result;
	}
}
