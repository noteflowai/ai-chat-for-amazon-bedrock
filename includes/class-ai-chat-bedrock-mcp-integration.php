<?php
/**
 * MCP integration.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_MCP_Integration {
	private $mcp_client;

	public function __construct() {
		$this->mcp_client = new AI_Chat_Bedrock_MCP_Client();
	}

	public function register_hooks( $loader ) {
		$loader->add_action( 'wp_ajax_ai_chat_bedrock_save_option', $this, 'ajax_save_boolean_option' );
		$loader->add_action( 'wp_ajax_ai_chat_bedrock_register_mcp_server', $this, 'ajax_register_mcp_server' );
		$loader->add_action( 'wp_ajax_ai_chat_bedrock_unregister_mcp_server', $this, 'ajax_unregister_mcp_server' );
		$loader->add_action( 'wp_ajax_ai_chat_bedrock_get_mcp_servers', $this, 'ajax_get_mcp_servers' );
		$loader->add_action( 'wp_ajax_ai_chat_bedrock_discover_mcp_tools', $this, 'ajax_discover_mcp_tools' );
		$loader->add_action( 'admin_post_ai_chat_bedrock_save_tool_policy', $this, 'handle_save_tool_policy' );
		$loader->add_action( 'admin_post_ai_chat_bedrock_save_oauth', $this, 'handle_save_oauth' );
		$loader->add_action( 'admin_post_ai_chat_bedrock_revoke_oauth', $this, 'handle_revoke_oauth' );
		$loader->add_filter( 'ai_chat_bedrock_message_payload', $this, 'add_mcp_tools_to_payload', 10, 2 );
		$loader->add_filter( 'ai_chat_bedrock_process_response', $this, 'process_mcp_tool_calls', 10, 2 );
	}

	public function ajax_save_boolean_option() {
		$this->authorize_admin_request( 'ai_chat_bedrock_nonce' );
		$name    = isset( $_POST['option_name'] ) ? sanitize_key( wp_unslash( $_POST['option_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
		$allowed = array( 'ai_chat_bedrock_enable_mcp', 'ai_chat_bedrock_mcp_public_access' );
		if ( ! in_array( $name, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'This setting cannot be changed here.', 'ai-chat-for-amazon-bedrock' ) ), 400 );
		}
		$raw_value = isset( $_POST['option_value'] ) ? sanitize_text_field( wp_unslash( $_POST['option_value'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
		$value     = '' !== $raw_value && '0' !== $raw_value;
		update_option( $name, $value, false );
		wp_send_json_success( array( 'message' => __( 'Setting saved.', 'ai-chat-for-amazon-bedrock' ) ) );
	}

	public function ajax_register_mcp_server() {
		$this->authorize_admin_request();
		$name = isset( $_POST['server_name'] ) ? sanitize_key( wp_unslash( $_POST['server_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
		$url  = isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ), array( 'https' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
		if ( '' === $name || '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'A server name and HTTPS URL are required.', 'ai-chat-for-amazon-bedrock' ) ), 400 );
		}

		$auth = array( 'type' => isset( $_POST['auth_type'] ) ? sanitize_key( wp_unslash( $_POST['auth_type'] ) ) : 'none' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
		if ( 'bearer' === $auth['type'] ) {
			$auth['token'] = isset( $_POST['auth_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['auth_token'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
			if ( '' === $auth['token'] ) {
				wp_send_json_error( array( 'message' => __( 'A bearer token is required for token authentication.', 'ai-chat-for-amazon-bedrock' ) ), 400 );
			}
		}
		if ( 'sigv4' === $auth['type'] ) {
			$auth['service'] = isset( $_POST['auth_service'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_service'] ) ) : 'bedrock-agentcore'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
			$auth['region']  = isset( $_POST['auth_region'] ) ? sanitize_key( wp_unslash( $_POST['auth_region'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
		}

		if ( ! $this->mcp_client->register_server( $name, $url, $auth ) ) {
			wp_send_json_error( array( 'message' => __( 'The MCP server could not be registered. Verify that it uses a public HTTPS URL.', 'ai-chat-for-amazon-bedrock' ) ), 400 );
		}

		$server = $this->mcp_client->get_server( $name );
		if ( is_array( $server ) && isset( $server['auth']['token'] ) ) {
			$server['auth']['token'] = '';
		}
		wp_send_json_success(
			array(
				'message' => __( 'MCP server registered.', 'ai-chat-for-amazon-bedrock' ),
				'server'  => $server,
			)
		);
	}

	public function ajax_unregister_mcp_server() {
		$this->authorize_admin_request();
		$name = isset( $_POST['server_name'] ) ? sanitize_key( wp_unslash( $_POST['server_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
		if ( ! $this->mcp_client->unregister_server( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'MCP server not found.', 'ai-chat-for-amazon-bedrock' ) ), 404 );
		}
		wp_send_json_success( array( 'message' => __( 'MCP server removed.', 'ai-chat-for-amazon-bedrock' ) ) );
	}

	public function ajax_get_mcp_servers() {
		$this->authorize_admin_request();
		$servers = $this->mcp_client->get_servers();
		foreach ( $servers as $name => &$server ) {
			$status              = $this->mcp_client->server_status( $name );
			$server['available'] = ! empty( $status['available'] );
			$server['reason']    = isset( $status['reason'] ) ? (string) $status['reason'] : '';
			if ( isset( $server['auth']['token'] ) ) {
				$server['auth']['token'] = '';
			}
			if ( isset( $server['auth']['type'] ) ) {
				$server['auth_type'] = $server['auth']['type'];
			}
		}
		unset( $server );
		wp_send_json_success( array( 'servers' => $servers ) );
	}

	public function ajax_discover_mcp_tools() {
		$this->authorize_admin_request();
		$name  = isset( $_POST['server_name'] ) ? sanitize_key( wp_unslash( $_POST['server_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
		$tools = $this->mcp_client->discover_server_tools( $name );
		if ( empty( $tools ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid tools were discovered.', 'ai-chat-for-amazon-bedrock' ) ), 400 );
		}
		wp_send_json_success(
			array(
				'message' => __( 'Tools refreshed.', 'ai-chat-for-amazon-bedrock' ),
				'tools'   => $tools,
			)
		);
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public function add_mcp_tools_to_payload( $payload, $message ) {
		if ( ! AI_Chat_Bedrock_Tool_Policy::current_user_may_use_tools() ) {
			return $payload;
		}

		$tools = array();
		foreach ( AI_Chat_Bedrock_Tool_Policy::filter_tools( $this->mcp_client->get_all_tools() ) as $tool ) {
			$tools[] = array(
				'name'         => $tool['name'],
				'description'  => $tool['description'],
				'input_schema' => $tool['parameters'],
			);
		}
		if ( ! empty( $tools ) ) {
			$payload['tools'] = array_slice( $tools, 0, 50 );
		}
		return $payload;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public function process_mcp_tool_calls( $response, $message ) {
		if ( ! is_array( $response ) ) {
			return $response;
		}
		if ( ! AI_Chat_Bedrock_Tool_Policy::current_user_may_use_tools() ) {
			unset( $response['tool_calls'] );
			return $response;
		}

		$calls = isset( $response['tool_calls'] ) && is_array( $response['tool_calls'] ) ? $response['tool_calls'] : array();
		if ( isset( $response['content'] ) && is_array( $response['content'] ) ) {
			foreach ( $response['content'] as $item ) {
				if ( is_array( $item ) && isset( $item['type'], $item['name'] ) && 'tool_use' === $item['type'] ) {
					$calls[] = array(
						'id'         => isset( $item['id'] ) ? sanitize_key( $item['id'] ) : wp_generate_uuid4(),
						'name'       => $item['name'],
						'parameters' => isset( $item['input'] ) ? $item['input'] : array(),
					);
				}
			}
		}

		$round                  = isset( $response['tool_round'] ) ? max( 1, (int) $response['tool_round'] ) : 1;
		$response['tool_calls'] = array();

		foreach ( array_slice( $calls, 0, 5 ) as $call ) {
			if ( ! is_array( $call ) || empty( $call['name'] ) || false === strpos( $call['name'], '___' ) ) {
				continue;
			}

			/*
			 * Ability tools share the server___tool shape but belong to the Abilities
			 * bridge, which runs later on this filter. Claiming them here would mark
			 * every ability call as an invalid server and stop it from ever running.
			 */
			if ( class_exists( 'AI_Chat_Bedrock_Abilities' ) && 0 === strpos( (string) $call['name'], AI_Chat_Bedrock_Abilities::TOOL_PREFIX ) ) {
				$response['tool_calls'][] = $call;
				continue;
			}

			// A call another handler already resolved must not be executed twice.
			if ( isset( $call['result'] ) || isset( $call['error'] ) ) {
				$response['tool_calls'][] = $call;
				continue;
			}

			$parsed     = $this->mcp_client->parse_tool_name( $call['name'] );
			$tool_name  = $parsed['server_name'] . '___' . $parsed['tool_name'];
			$parameters = isset( $call['parameters'] ) && is_array( $call['parameters'] ) ? $call['parameters'] : array();
			$clean      = array(
				'id'   => isset( $call['id'] ) ? sanitize_key( $call['id'] ) : wp_generate_uuid4(),
				'name' => $tool_name,
			);

			if ( ! AI_Chat_Bedrock_Tool_Policy::is_tool_allowed( $tool_name, $this->tool_description( $tool_name ) ) ) {
				$clean['error'] = array(
					'code'    => 'tool_not_allowed',
					'message' => __( 'This tool is not allowed by the site policy.', 'ai-chat-for-amazon-bedrock' ),
				);
				AI_Chat_Bedrock_Tool_Log::record(
					array(
						'tool'   => $tool_name,
						'status' => 'error',
						'error'  => 'tool_not_allowed',
						'keys'   => array_keys( $parameters ),
						'round'  => $round,
					)
				);
				$response['tool_calls'][] = $clean;
				continue;
			}

			$started = microtime( true );
			$result  = $this->mcp_client->call_tool( $parsed['server_name'], $parsed['tool_name'], $parameters );
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
			$response['tool_calls'][] = $clean;
		}
		return $response;
	}

	private function tool_description( $tool_name ) {
		foreach ( $this->mcp_client->get_all_tools() as $tool ) {
			if ( isset( $tool['name'] ) && $tool['name'] === $tool_name ) {
				return isset( $tool['description'] ) ? (string) $tool['description'] : '';
			}
		}
		return '';
	}

	/**
	 * Save the MCP tool policy submitted from the MCP settings screen.
	 */
	public function handle_save_tool_policy() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_tool_policy' );

		$allowed = array();
		if ( isset( $_POST['tool_allow'] ) && is_array( $_POST['tool_allow'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
			foreach ( wp_unslash( $_POST['tool_allow'] ) as $tool ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$allowed[] = sanitize_text_field( (string) $tool );
			}
		}

		$policy = array();
		foreach ( $this->mcp_client->get_all_tools() as $tool ) {
			if ( empty( $tool['name'] ) ) {
				continue;
			}
			$name            = (string) $tool['name'];
			$policy[ $name ] = in_array( $name, $allowed, true ) ? 'allow' : 'deny';
		}
		AI_Chat_Bedrock_Tool_Policy::save_policy( $policy );

		$capability   = isset( $_POST['mcp_capability'] ) ? sanitize_key( wp_unslash( $_POST['mcp_capability'] ) ) : AI_Chat_Bedrock_Tool_Policy::DEFAULT_CAPABILITY; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
		$capabilities = array( 'read', 'edit_posts', 'edit_others_posts', 'manage_options' );
		update_option( AI_Chat_Bedrock_Tool_Policy::OPTION_CAPABILITY, in_array( $capability, $capabilities, true ) ? $capability : AI_Chat_Bedrock_Tool_Policy::DEFAULT_CAPABILITY, false );

		$rounds = isset( $_POST['mcp_max_rounds'] ) ? absint( wp_unslash( $_POST['mcp_max_rounds'] ) ) : AI_Chat_Bedrock_Tool_Policy::DEFAULT_MAX_ROUNDS; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
		update_option( AI_Chat_Bedrock_Tool_Policy::OPTION_MAX_ROUNDS, max( 1, min( AI_Chat_Bedrock_Tool_Policy::MAX_ROUNDS_LIMIT, $rounds ) ), false );

		update_option( 'ai_chat_bedrock_mcp_log_enabled', ! empty( $_POST['mcp_log_enabled'] ), false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().

		wp_safe_redirect( add_query_arg( 'aicfab-policy', 'saved', admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-mcp' ) ) );
		exit;
	}

	/**
	 * Save the OAuth connection settings.
	 */
	public function handle_save_oauth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_oauth_settings' );

		update_option( 'ai_chat_bedrock_oauth_enabled', ! empty( $_POST['oauth_enabled'] ), false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
		wp_safe_redirect( add_query_arg( 'aicfab-oauth', 'saved', admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-mcp' ) ) );
		exit;
	}

	/**
	 * Revoke one or more OAuth connections.
	 */
	public function handle_revoke_oauth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_chat_bedrock_oauth_revoke' );

		$oauth = new AI_Chat_Bedrock_OAuth();
		if ( ! empty( $_POST['revoke_all'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
			$oauth->revoke_all();
		} elseif ( isset( $_POST['revoke'] ) && is_array( $_POST['revoke'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize_admin_request().
			foreach ( wp_unslash( $_POST['revoke'] ) as $id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$oauth->revoke_grant( sanitize_text_field( (string) $id ) );
			}
		}

		wp_safe_redirect( add_query_arg( 'aicfab-oauth', 'revoked', admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-mcp' ) ) );
		exit;
	}

	public function get_mcp_client() {
		return $this->mcp_client;
	}

	/**
	 * Reject the request unless the caller is an administrator with a valid nonce.
	 *
	 * Every AJAX handler in this class calls this first, which is why the individual
	 * $_POST reads below carry a phpcs:ignore for the nonce sniff: the verification is
	 * here rather than inline.
	 *
	 * @param string $nonce_action Expected nonce action.
	 */
	private function authorize_admin_request( $nonce_action = 'ai_chat_bedrock_mcp_nonce' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
		}
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
		}
	}
}
