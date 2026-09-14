<?php
/**
 * Secure MCP client.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_MCP_Client {
	private $timeout;
	private $servers = array();

	public function __construct( $base_url = '', $timeout = 10 ) {
		$this->timeout = max( 2, min( 20, absint( $timeout ) ) );
		$saved         = get_option( 'ai_chat_bedrock_mcp_servers', array() );
		$this->servers = is_array( $saved ) ? $saved : array();
	}

	private function save_servers() {
		update_option( 'ai_chat_bedrock_mcp_servers', $this->servers, false );
	}

	public function register_server( $server_name, $server_url, $auth = array() ) {
		$server_name = sanitize_key( $server_name );
		$server_url  = untrailingslashit( esc_url_raw( $server_url, array( 'https' ) ) );
		if ( '' === $server_name || strlen( $server_name ) > 64 || isset( $this->servers[ $server_name ] ) || ! AI_Chat_Bedrock_Security::is_safe_mcp_url( $server_url ) ) {
			return false;
		}

		$this->servers[ $server_name ] = array(
			'url'   => $server_url,
			'tools' => array(),
			'auth'  => AI_Chat_Bedrock_MCP_Transport::sanitize_auth( $auth ),
		);
		$this->save_servers();
		$this->discover_server_tools( $server_name );
		return true;
	}

	/**
	 * Update the authentication configuration of a registered server.
	 *
	 * @param string $server_name Server name.
	 * @param array  $auth        Authentication configuration.
	 * @return bool
	 */
	public function set_server_auth( $server_name, $auth ) {
		$server_name = sanitize_key( $server_name );
		if ( ! isset( $this->servers[ $server_name ] ) ) {
			return false;
		}
		$this->servers[ $server_name ]['auth'] = AI_Chat_Bedrock_MCP_Transport::sanitize_auth( $auth );
		$this->save_servers();
		return true;
	}

	/**
	 * Authentication configuration for a server.
	 *
	 * @param string $server_name Server name.
	 * @return array
	 */
	public function server_auth( $server_name ) {
		return isset( $this->servers[ $server_name ]['auth'] ) && is_array( $this->servers[ $server_name ]['auth'] )
			? $this->servers[ $server_name ]['auth']
			: array( 'type' => 'none' );
	}

	public function unregister_server( $server_name ) {
		$server_name = sanitize_key( $server_name );
		if ( ! isset( $this->servers[ $server_name ] ) ) {
			return false;
		}
		unset( $this->servers[ $server_name ] );
		$this->save_servers();
		return true;
	}

	public function discover_server_tools( $server_name ) {
		if ( ! isset( $this->servers[ $server_name ] ) || ! $this->server_is_safe( $server_name ) ) {
			return array();
		}

		$tools = $this->discover_via_jsonrpc( $server_name );
		if ( empty( $tools ) ) {
			$tools = $this->discover_via_legacy( $server_name );
		}

		$this->servers[ $server_name ]['tools'] = $tools;
		$this->save_servers();
		return $tools;
	}

	private function discover_via_jsonrpc( $server_name ) {
		$result = AI_Chat_Bedrock_MCP_Transport::call(
			$this->servers[ $server_name ]['url'],
			'tools/list',
			array(),
			$this->server_auth( $server_name ),
			$this->timeout
		);
		if ( is_wp_error( $result ) || empty( $result['tools'] ) || ! is_array( $result['tools'] ) ) {
			return array();
		}

		$tools = array();
		foreach ( array_slice( $result['tools'], 0, 50 ) as $tool ) {
			$clean = $this->sanitize_tool( $tool );
			if ( ! empty( $clean ) ) {
				$tools[] = $clean;
			}
		}
		return $tools;
	}

	private function discover_via_legacy( $server_name ) {
		$response = wp_safe_remote_get(
			trailingslashit( $this->servers[ $server_name ]['url'] ) . 'mcp/discover',
			$this->request_args()
		);
		$data     = $this->decode_response( $response );
		if ( is_wp_error( $data ) || empty( $data['tools'] ) || ! is_array( $data['tools'] ) ) {
			return array();
		}

		$tools = array();
		foreach ( array_slice( $data['tools'], 0, 50 ) as $tool ) {
			$clean = $this->sanitize_tool( $tool );
			if ( ! empty( $clean ) ) {
				$tools[] = $clean;
			}
		}
		return $tools;
	}

	public function get_servers() {
		return $this->servers;
	}

	public function get_server( $server_name ) {
		return isset( $this->servers[ $server_name ] ) ? $this->servers[ $server_name ] : null;
	}

	public function call_tool( $server_name, $tool_name, $parameters = array() ) {
		if ( ! isset( $this->servers[ $server_name ] ) || ! $this->server_is_safe( $server_name ) ) {
			return new WP_Error( 'invalid_server', __( 'Invalid MCP server.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$tool_name = sanitize_key( $tool_name );
		$allowed   = false;
		foreach ( (array) $this->servers[ $server_name ]['tools'] as $tool ) {
			if ( isset( $tool['name'] ) && $tool_name === $tool['name'] ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			return new WP_Error( 'invalid_tool', __( 'Invalid MCP tool.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$body = wp_json_encode( is_array( $parameters ) ? $parameters : array() );
		if ( strlen( $body ) > 65536 ) {
			return new WP_Error( 'payload_too_large', __( 'MCP tool input is too large.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$result = AI_Chat_Bedrock_MCP_Transport::call(
			$this->servers[ $server_name ]['url'],
			'tools/call',
			array(
				'name'      => $tool_name,
				'arguments' => is_array( $parameters ) ? $parameters : array(),
			),
			$this->server_auth( $server_name ),
			$this->timeout
		);

		if ( ! is_wp_error( $result ) ) {
			return $this->normalize_tool_result( $result );
		}
		if ( in_array( $result->get_error_code(), array( 'aicfab_mcp_unauthorized', 'aicfab_mcp_missing_token', 'aicfab_no_credentials', 'aicfab_invalid_region', 'aicfab_invalid_service' ), true ) ) {
			return $result;
		}

		$args            = $this->request_args();
		$args['headers'] = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);
		$args['body']    = $body;
		$response        = wp_safe_remote_post(
			trailingslashit( $this->servers[ $server_name ]['url'] ) . 'mcp/tools/' . rawurlencode( $tool_name ),
			$args
		);
		return $this->decode_response( $response );
	}

	/**
	 * Convert an MCP tool result into a compact array for the model.
	 *
	 * @param array $result Raw JSON-RPC result.
	 * @return array|WP_Error
	 */
	private function normalize_tool_result( $result ) {
		if ( ! empty( $result['isError'] ) ) {
			$message = __( 'The MCP tool reported an error.', 'ai-chat-for-amazon-bedrock' );
			if ( isset( $result['content'][0]['text'] ) ) {
				$message = sanitize_text_field( (string) $result['content'][0]['text'] );
			}
			return new WP_Error( 'mcp_tool_error', $message );
		}

		if ( isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] ) ) {
			return $result['structuredContent'];
		}

		$text = '';
		foreach ( isset( $result['content'] ) && is_array( $result['content'] ) ? $result['content'] : array() as $block ) {
			if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= ( '' === $text ? '' : "\n" ) . (string) $block['text'];
			}
		}
		if ( '' !== $text ) {
			return array( 'text' => AI_Chat_Bedrock_Security::string_substr( $text, 0, 20000 ) );
		}
		return is_array( $result ) ? $result : array();
	}

	public function get_all_tools() {
		$all = array();
		foreach ( $this->servers as $server_name => $server ) {
			foreach ( isset( $server['tools'] ) ? (array) $server['tools'] : array() as $tool ) {
				$tool['name'] = $server_name . '___' . $tool['name'];
				$all[]        = $tool;
			}
		}
		return $all;
	}

	public function parse_tool_name( $name ) {
		$parts = explode( '___', $name, 2 );
		return array(
			'server_name' => 2 === count( $parts ) ? sanitize_key( $parts[0] ) : '',
			'tool_name'   => 2 === count( $parts ) ? sanitize_key( $parts[1] ) : '',
		);
	}

	public function is_server_available( $server_name ) {
		if ( ! isset( $this->servers[ $server_name ] ) || ! $this->server_is_safe( $server_name ) ) {
			return false;
		}

		$result = AI_Chat_Bedrock_MCP_Transport::call(
			$this->servers[ $server_name ]['url'],
			'tools/list',
			array(),
			$this->server_auth( $server_name ),
			5
		);
		if ( ! is_wp_error( $result ) ) {
			return true;
		}

		$args            = $this->request_args();
		$args['timeout'] = 5;
		$response        = wp_safe_remote_get( trailingslashit( $this->servers[ $server_name ]['url'] ) . 'mcp/health', $args );
		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	private function request_args() {
		return array(
			'timeout'             => $this->timeout,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => 1048576,
			'headers'             => array( 'Accept' => 'application/json' ),
			'user-agent'          => 'AI-Chat-Bedrock/' . AI_CHAT_BEDROCK_VERSION,
		);
	}

	private function decode_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			/* translators: %d: HTTP status code returned by the MCP server. */
			return new WP_Error( 'mcp_http_error', sprintf( __( 'MCP server returned HTTP %d.', 'ai-chat-for-amazon-bedrock' ), $status ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new WP_Error( 'invalid_response', __( 'MCP server returned invalid JSON.', 'ai-chat-for-amazon-bedrock' ) );
	}

	private function server_is_safe( $server_name ) {
		return AI_Chat_Bedrock_Security::is_safe_mcp_url( $this->servers[ $server_name ]['url'] );
	}

	private function sanitize_tool( $tool ) {
		if ( ! is_array( $tool ) || empty( $tool['name'] ) ) {
			return array();
		}
		$name = sanitize_key( $tool['name'] );
		if ( '' === $name || strlen( $name ) > 64 ) {
			return array();
		}
		$schema = isset( $tool['parameters'] ) && is_array( $tool['parameters'] ) ? $tool['parameters'] : array();
		if ( empty( $schema ) && isset( $tool['inputSchema'] ) && is_array( $tool['inputSchema'] ) ) {
			$schema = $tool['inputSchema'];
		}
		if ( empty( $schema ) && isset( $tool['input_schema'] ) && is_array( $tool['input_schema'] ) ) {
			$schema = $tool['input_schema'];
		}
		$properties = array();
		foreach ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? array_slice( $schema['properties'], 0, 50, true ) : array() as $key => $property ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! is_array( $property ) ) {
				continue;
			}
			$type               = isset( $property['type'] ) && in_array( $property['type'], array( 'string', 'number', 'integer', 'boolean', 'array', 'object' ), true ) ? $property['type'] : 'string';
			$properties[ $key ] = array(
				'type'        => $type,
				'description' => isset( $property['description'] ) ? sanitize_text_field( $property['description'] ) : '',
			);
		}
		$required = array();
		foreach ( isset( $schema['required'] ) ? (array) $schema['required'] : array() as $key ) {
			$key = sanitize_key( $key );
			if ( isset( $properties[ $key ] ) ) {
				$required[] = $key;
			}
		}
		return array(
			'name'        => $name,
			'description' => isset( $tool['description'] ) ? sanitize_text_field( $tool['description'] ) : '',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => empty( $properties ) ? new stdClass() : $properties,
				'required'   => $required,
			),
		);
	}
}
