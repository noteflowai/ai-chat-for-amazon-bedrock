<?php
/**
 * Model Context Protocol transport.
 *
 * Speaks JSON-RPC 2.0 over Streamable HTTP against a single MCP endpoint, which
 * is what Amazon Bedrock AgentCore Gateway and other current MCP servers expose.
 * Authentication is optional and can be a bearer token or AWS SigV4.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_MCP_Transport {

	/**
	 * Revision this client speaks by default.
	 *
	 * 2026-07-28 carries the version, capabilities and identity in _meta on every request
	 * instead of negotiating once, which is why nothing here needs a handshake.
	 */
	const PROTOCOL_VERSION = '2026-07-28';

	/**
	 * Revision to retry with when a server refuses the modern one.
	 */
	const FALLBACK_VERSION = '2025-06-18';

	const MAX_RESPONSE = 1048576;

	/**
	 * _meta keys defined by the 2026-07-28 revision.
	 */
	const META_PROTOCOL_VERSION    = 'io.modelcontextprotocol/protocolVersion';
	const META_CLIENT_INFO         = 'io.modelcontextprotocol/clientInfo';
	const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

	/**
	 * UnsupportedProtocolVersion, which is what an older server answers a modern request with.
	 */
	const ERROR_UNSUPPORTED_VERSION = -32022;

	/**
	 * Call an MCP method.
	 *
	 * @param string $endpoint Absolute HTTPS endpoint.
	 * @param string $method   JSON-RPC method, for example tools/list.
	 * @param array  $params   Method parameters.
	 * @param array  $auth     Authentication configuration.
	 * @param int    $timeout  Request timeout in seconds.
	 * @return array|WP_Error Decoded result payload.
	 */
	public static function call( $endpoint, $method, $params = array(), $auth = array(), $timeout = 10 ) {
		$result = self::call_with_version( $endpoint, $method, $params, $auth, $timeout, self::PROTOCOL_VERSION );

		// A server on an older revision refuses the modern one by code. Retry once rather
		// than making every caller know about protocol revisions.
		if ( is_wp_error( $result ) && 'aicfab_mcp_error_' . abs( self::ERROR_UNSUPPORTED_VERSION ) === $result->get_error_code() ) {
			return self::call_with_version( $endpoint, $method, $params, $auth, $timeout, self::FALLBACK_VERSION );
		}

		return $result;
	}

	/**
	 * Call an MCP method using a specific protocol revision.
	 *
	 * @param string $endpoint Absolute HTTPS endpoint.
	 * @param string $method   JSON-RPC method, for example tools/list.
	 * @param array  $params   Method parameters.
	 * @param array  $auth     Authentication configuration.
	 * @param int    $timeout  Request timeout in seconds.
	 * @param string $version  Protocol revision to declare.
	 * @return array|WP_Error Decoded result payload.
	 */
	private static function call_with_version( $endpoint, $method, $params = array(), $auth = array(), $timeout = 10, $version = self::PROTOCOL_VERSION ) {
		if ( ! AI_Chat_Bedrock_Security::is_safe_mcp_url( $endpoint ) ) {
			return new WP_Error( 'aicfab_mcp_unsafe_url', __( 'The MCP endpoint must be a public HTTPS URL.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$method = (string) $method;
		if ( ! preg_match( '#^[a-z][a-z0-9/_-]{1,60}$#', $method ) ) {
			return new WP_Error( 'aicfab_mcp_invalid_method', __( 'The MCP method name is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$params = is_array( $params ) ? $params : array();

		// From 2026-07-28 there is no handshake, so every request states what it speaks and
		// who is asking. Servers on older revisions ignore an unknown _meta.
		$meta                                   = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();
		$meta[ self::META_PROTOCOL_VERSION ]    = $version;
		$meta[ self::META_CLIENT_INFO ]         = array(
			'name'    => 'ai-chat-for-amazon-bedrock',
			'version' => defined( 'AI_CHAT_BEDROCK_VERSION' ) ? AI_CHAT_BEDROCK_VERSION : '',
		);
		$meta[ self::META_CLIENT_CAPABILITIES ] = new stdClass();
		$params['_meta']                        = $meta;

		$request = array(
			'jsonrpc' => '2.0',
			'id'      => wp_generate_uuid4(),
			'method'  => $method,
			'params'  => $params,
		);
		$body    = wp_json_encode( $request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $body ) || strlen( $body ) > 65536 ) {
			return new WP_Error( 'aicfab_mcp_payload', __( 'The MCP request payload is invalid or too large.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$headers = array(
			'Content-Type'         => 'application/json',
			'Accept'               => 'application/json, text/event-stream',
			// Still sent for servers on revisions that read the header rather than _meta.
			'MCP-Protocol-Version' => $version,
			// Required on a Streamable HTTP POST from 2026-07-28, and useful to a proxy that
			// routes on the method without parsing the body.
			'Mcp-Method'           => $method,
		);
		$headers = self::apply_auth( $headers, $endpoint, $body, $auth );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'             => max( 2, min( 30, absint( $timeout ) ) ),
				'redirection'         => 0,
				'httpversion'         => '1.1',
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::MAX_RESPONSE,
				'headers'             => $headers,
				'body'                => $body,
				'user-agent'          => 'AI-Chat-Bedrock/' . AI_CHAT_BEDROCK_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'aicfab_mcp_transport', __( 'The MCP endpoint could not be reached.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'aicfab_mcp_unauthorized', __( 'The MCP endpoint rejected the credentials.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			/* translators: %d: HTTP status code returned by the MCP endpoint. */
			return new WP_Error( 'aicfab_mcp_http_error', sprintf( __( 'The MCP endpoint returned HTTP %d.', 'ai-chat-for-amazon-bedrock' ), $status ) );
		}

		$decoded = self::decode_body( $raw, wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		if ( isset( $decoded['error'] ) ) {
			$message = isset( $decoded['error']['message'] ) ? sanitize_text_field( (string) $decoded['error']['message'] ) : __( 'The MCP endpoint returned an error.', 'ai-chat-for-amazon-bedrock' );
			$code    = isset( $decoded['error']['code'] ) ? (int) $decoded['error']['code'] : 0;
			return new WP_Error( 'aicfab_mcp_error_' . abs( $code ), $message );
		}

		return isset( $decoded['result'] ) && is_array( $decoded['result'] ) ? $decoded['result'] : array();
	}

	/**
	 * Negotiated MCP protocol version.
	 *
	 * @return string
	 */
	public static function protocol_version() {
		$version = (string) apply_filters( 'ai_chat_bedrock_mcp_protocol_version', self::PROTOCOL_VERSION );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $version ) ? $version : self::PROTOCOL_VERSION;
	}

	/**
	 * Normalize an authentication configuration for storage.
	 *
	 * @param array $auth Raw configuration.
	 * @return array
	 */
	public static function sanitize_auth( $auth ) {
		$auth = is_array( $auth ) ? $auth : array();
		$type = isset( $auth['type'] ) ? sanitize_key( $auth['type'] ) : 'none';
		$type = in_array( $type, array( 'none', 'bearer', 'sigv4' ), true ) ? $type : 'none';

		$clean = array( 'type' => $type );

		if ( 'bearer' === $type ) {
			$token = isset( $auth['token'] ) ? trim( (string) $auth['token'] ) : '';
			if ( '' === $token ) {
				return array( 'type' => 'none' );
			}
			$encrypted = AI_Chat_Bedrock_Security::is_encrypted( $token ) ? $token : AI_Chat_Bedrock_Security::encrypt_secret( $token );
			if ( '' === $encrypted ) {
				return array( 'type' => 'none' );
			}
			$clean['token'] = $encrypted;
		}

		if ( 'sigv4' === $type ) {
			$service          = isset( $auth['service'] ) ? preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $auth['service'] ) ) : '';
			$clean['service'] = '' !== $service ? $service : 'bedrock-agentcore';
			$region           = isset( $auth['region'] ) ? sanitize_key( (string) $auth['region'] ) : '';
			if ( '' !== $region && preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $region ) ) {
				$clean['region'] = $region;
			}
		}

		return $clean;
	}

	private static function apply_auth( $headers, $endpoint, $body, $auth ) {
		$auth = is_array( $auth ) ? $auth : array();
		$type = isset( $auth['type'] ) ? sanitize_key( $auth['type'] ) : 'none';

		if ( 'bearer' === $type ) {
			$token = isset( $auth['token'] ) ? AI_Chat_Bedrock_Security::decrypt_secret( $auth['token'] ) : '';
			if ( '' === $token ) {
				return new WP_Error( 'aicfab_mcp_missing_token', __( 'The MCP bearer token is missing or could not be decrypted.', 'ai-chat-for-amazon-bedrock' ) );
			}
			$headers['Authorization'] = 'Bearer ' . $token;
			return $headers;
		}

		if ( 'sigv4' === $type ) {
			$service = isset( $auth['service'] ) && '' !== $auth['service'] ? (string) $auth['service'] : 'bedrock-agentcore';
			$region  = isset( $auth['region'] ) ? (string) $auth['region'] : '';
			$signed  = AI_Chat_Bedrock_AWS::sign_request( $endpoint, $body, 'POST', $service, $region );
			if ( is_wp_error( $signed ) ) {
				return $signed;
			}
			return array_merge( $headers, $signed );
		}

		return $headers;
	}

	private static function decode_body( $raw, $content_type ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return new WP_Error( 'aicfab_mcp_empty', __( 'The MCP endpoint returned an empty response.', 'ai-chat-for-amazon-bedrock' ) );
		}

		if ( false !== strpos( strtolower( (string) $content_type ), 'text/event-stream' ) || 0 === strpos( $raw, 'event:' ) || 0 === strpos( $raw, 'data:' ) ) {
			$payload = '';
			foreach ( preg_split( '/\r\n|\n|\r/', $raw ) as $line ) {
				if ( 0 === strpos( $line, 'data:' ) ) {
					$payload .= trim( substr( $line, 5 ) );
				}
			}
			$raw = '' !== $payload ? $payload : $raw;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'aicfab_mcp_invalid_json', __( 'The MCP endpoint returned invalid JSON.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return $decoded;
	}
}
