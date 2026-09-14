<?php
/**
 * WordPress MCP server.
 *
 * Exposes a standards-compliant Model Context Protocol endpoint so MCP clients
 * such as Claude Code, Cursor or an agent framework can read this site. Requests
 * use JSON-RPC 2.0 over Streamable HTTP at a single route.
 *
 * Security model:
 *   - Authentication is required by default; WordPress Application Passwords work
 *     out of the box. Anonymous access is opt-in and rate limited.
 *   - Reads only ever return published, publicly visible content.
 *   - Write access is limited to the controlled site abilities, which create
 *     drafts only and require the matching WordPress capability.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_WP_MCP_Server {

	const NAMESPACE_V1 = 'ai-chat-bedrock/v1';
	/**
	 * Preferred protocol revision.
	 *
	 * 2026-07-28 removed the initialize handshake and protocol-level sessions: every request
	 * carries its version, capabilities and identity in _meta, and a server advertises itself
	 * through server/discover. That suits WordPress, which is stateless anyway.
	 */
	const PROTOCOL_VERSION = '2026-07-28';

	/**
	 * Revisions this server answers, newest first.
	 *
	 * The older two are kept because clients on them are still in use and still expect the
	 * handshake. Results are shaped for whichever revision the caller asked for, so an older
	 * client sees exactly what it saw before.
	 */
	const SUPPORTED_VERSIONS = array( '2026-07-28', '2025-11-25', '2025-06-18' );

	/**
	 * _meta keys defined by the 2026-07-28 revision.
	 */
	const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
	const META_SERVER_INFO      = 'io.modelcontextprotocol/serverInfo';

	/**
	 * UnsupportedProtocolVersion, from the range the specification reserves for itself.
	 */
	const ERROR_UNSUPPORTED_VERSION = -32022;

	private $tools = array();

	public function __construct() {
		$this->init_tools();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	private function init_tools() {
		$this->tools = array(
			'search_posts'   => $this->tool(
				'search_posts',
				'Search published WordPress posts.',
				array(
					'query'    => 'string',
					'category' => 'string',
					'tag'      => 'string',
					'author'   => 'string',
					'limit'    => 'integer',
				)
			),
			'get_post'       => $this->tool(
				'get_post',
				'Get a published WordPress post by ID or slug.',
				array(
					'id'   => 'integer',
					'slug' => 'string',
				)
			),
			'get_categories' => $this->tool(
				'get_categories',
				'List WordPress categories.',
				array(
					'hide_empty' => 'boolean',
					'limit'      => 'integer',
				)
			),
			'get_tags'       => $this->tool(
				'get_tags',
				'List WordPress tags.',
				array(
					'hide_empty' => 'boolean',
					'limit'      => 'integer',
				)
			),
			'get_site_info'  => $this->tool( 'get_site_info', 'Get public WordPress site information.', array() ),
		);
	}

	private function tool( $name, $description, $properties, $required = array() ) {
		$schema = array();
		foreach ( $properties as $property => $type ) {
			$schema[ $property ] = array( 'type' => $type );
		}
		return array(
			'name'        => $name,
			'description' => $description,
			'parameters'  => array(
				'type'       => 'object',
				'properties' => empty( $schema ) ? new stdClass() : $schema,
				'required'   => $required,
			),
		);
	}

	/**
	 * Tools that write, exposed only when the controlled site abilities are enabled.
	 *
	 * @return array
	 */
	private function ability_tools() {
		if ( ! class_exists( 'AI_Chat_Bedrock_Site_Abilities' ) || ! AI_Chat_Bedrock_Site_Abilities::enabled() ) {
			return array();
		}

		$tools = array(
			'suggest_seo_meta' => $this->tool( 'suggest_seo_meta', 'Suggest an SEO title and meta description for a published post. Nothing is saved.', array( 'id' => 'integer' ), array( 'id' ) ),
			'create_draft'     => $this->tool(
				'create_draft',
				'Create a new draft post. Drafts are never published and existing posts are never modified.',
				array(
					'title'   => 'string',
					'content' => 'string',
				),
				array( 'title', 'content' )
			),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$tools['get_products'] = $this->tool(
				'get_products',
				'Search published WooCommerce products. Read only; orders and customers are never exposed.',
				array(
					'query'    => 'string',
					'per_page' => 'integer',
				)
			);
		}
		return $tools;
	}

	/**
	 * All callable tools for the current request.
	 *
	 * @return array
	 */
	private function available_tools() {
		$tools = array_merge( $this->tools, $this->ability_tools() );
		return (array) apply_filters( 'ai_chat_bedrock_wp_mcp_tools', $tools );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_jsonrpc' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_server_info' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Legacy routes kept for existing integrations.
		register_rest_route(
			'ai-chat-bedrock/v1/mcp',
			'/discover',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_discover' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
		register_rest_route(
			'ai-chat-bedrock/v1/mcp',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_health' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
		foreach ( $this->tools as $name => $tool ) {
			register_rest_route(
				'ai-chat-bedrock/v1/mcp/tools',
				'/' . $name,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_tool_' . $name ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);
		}
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public function check_permission( $request ) {
		if ( current_user_can( 'read' ) ) {
			return true;
		}
		if ( ! get_option( 'ai_chat_bedrock_mcp_public_access', false ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Authentication is required for the MCP endpoint.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 401 ) );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'mcp-rest', 30 ) ) {
			return new WP_Error( 'rest_rate_limited', __( 'MCP request limit exceeded.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Describe the server for clients that probe with GET.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_server_info() {
		return rest_ensure_response(
			array(
				'protocolVersion' => self::PROTOCOL_VERSION,
				'serverInfo'      => $this->server_info(),
				'capabilities'    => $this->capabilities(),
				'transport'       => 'streamable-http',
				'endpoint'        => rest_url( self::NAMESPACE_V1 . '/mcp' ),
			)
		);
	}

	/**
	 * Handle a JSON-RPC 2.0 request.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public function handle_jsonrpc( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			return $this->rpc_error( null, -32700, __( 'Parse error.', 'ai-chat-for-amazon-bedrock' ) );
		}

		// Revisions before 2026-07-28 state their version in a header on every request, which
		// is the only way a stateless server can know a client is on an older one.
		$header = (string) $request->get_header( 'mcp-protocol-version' );

		// Batched requests are answered in order.
		if ( isset( $payload[0] ) && is_array( $payload[0] ) ) {
			$responses = array();
			foreach ( array_slice( $payload, 0, 20 ) as $single ) {
				$response = $this->dispatch( is_array( $single ) ? $single : array(), $header );
				if ( null !== $response ) {
					$responses[] = $response;
				}
			}
			return rest_ensure_response( $responses );
		}

		$response = $this->dispatch( $payload, $header );
		if ( null === $response ) {
			return new WP_REST_Response( null, 202 );
		}
		return rest_ensure_response( $response );
	}

	private function dispatch( $payload, $header_version = '' ) {
		$id     = isset( $payload['id'] ) && ( is_string( $payload['id'] ) || is_int( $payload['id'] ) ) ? $payload['id'] : null;
		$method = isset( $payload['method'] ) ? (string) $payload['method'] : '';
		$params = isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array();

		if ( '' === $method ) {
			return $this->rpc_error_body( $id, -32600, __( 'Invalid request.', 'ai-chat-for-amazon-bedrock' ) );
		}

		// Notifications carry no id and expect no response.
		if ( null === $id && 0 === strpos( $method, 'notifications/' ) ) {
			return null;
		}

		$version = $this->requested_version( $payload, $header_version );
		if ( '' === $version ) {
			return $this->rpc_error_body(
				$id,
				self::ERROR_UNSUPPORTED_VERSION,
				sprintf(
					/* translators: %s: comma separated protocol revisions. */
					__( 'Unsupported protocol version. This server speaks %s.', 'ai-chat-for-amazon-bedrock' ),
					implode( ', ', self::SUPPORTED_VERSIONS )
				)
			);
		}

		switch ( $method ) {
			case 'server/discover':
				// Required from 2026-07-28. A client may also use it as a probe to find out
				// what an unknown server speaks before committing to a revision.
				return $this->rpc_result(
					$id,
					array(
						'protocolVersions' => array_values( self::SUPPORTED_VERSIONS ),
						'capabilities'     => $this->capabilities(),
						'serverInfo'       => $this->server_info(),
						'instructions'     => __( 'Read-only WordPress content tools. Draft creation is available only when the site enables it and the account has permission.', 'ai-chat-for-amazon-bedrock' ),
					),
					$version
				);

			case 'initialize':
				// Removed in 2026-07-28, kept for the revisions that still require it.
				return $this->rpc_result(
					$id,
					array(
						'protocolVersion' => $this->negotiate_version( $params ),
						'capabilities'    => $this->capabilities(),
						'serverInfo'      => $this->server_info(),
						'instructions'    => __( 'Read-only WordPress content tools. Draft creation is available only when the site enables it and the account has permission.', 'ai-chat-for-amazon-bedrock' ),
					),
					$version
				);

			case 'ping':
				// Removed in 2026-07-28. Answered anyway so older clients keep working.
				return $this->rpc_result( $id, new stdClass(), $version );

			case 'tools/list':
				$tools = array();
				foreach ( $this->available_tools() as $tool ) {
					$tools[] = array(
						'name'        => $tool['name'],
						'description' => $tool['description'],
						'inputSchema' => $tool['parameters'],
					);
				}
				// A deterministic order lets a client cache the list and helps prompt caches.
				usort(
					$tools,
					static function ( $left, $right ) {
						return strcmp( $left['name'], $right['name'] );
					}
				);
				return $this->rpc_result( $id, $this->cacheable( array( 'tools' => $tools ), $version ), $version );

			case 'tools/call':
				return $this->call_tool( $id, $params, $version );

			case 'resources/list':
				return $this->rpc_result( $id, $this->cacheable( array( 'resources' => array() ), $version ), $version );

			case 'prompts/list':
				return $this->rpc_result( $id, $this->cacheable( array( 'prompts' => array() ), $version ), $version );

			default:
				return $this->rpc_error_body( $id, -32601, __( 'Method not found.', 'ai-chat-for-amazon-bedrock' ) );
		}
	}

	private function call_tool( $id, $params, $version = '' ) {
		$name      = isset( $params['name'] ) ? sanitize_key( $params['name'] ) : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$tools     = $this->available_tools();

		if ( '' === $name || ! isset( $tools[ $name ] ) ) {
			return $this->rpc_error_body( $id, -32602, __( 'Unknown tool.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$started = microtime( true );
		$result  = $this->execute_tool( $name, $arguments );
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( class_exists( 'AI_Chat_Bedrock_Tool_Log' ) ) {
			AI_Chat_Bedrock_Tool_Log::record(
				array(
					'tool'     => 'wpserver___' . $name,
					'status'   => is_wp_error( $result ) ? 'error' : 'ok',
					'error'    => is_wp_error( $result ) ? $result->get_error_code() : '',
					'duration' => $elapsed,
					'keys'     => array_keys( $arguments ),
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $this->rpc_result(
				$id,
				array(
					'isError' => true,
					'content' => array(
						array(
							'type' => 'text',
							'text' => $result->get_error_message(),
						),
					),
				),
				$version
			);
		}

		$encoded = wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		if ( ! is_string( $encoded ) ) {
			$encoded = '{}';
		}
		if ( strlen( $encoded ) > 200000 ) {
			$encoded = substr( $encoded, 0, 200000 );
		}

		return $this->rpc_result(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => $encoded,
					),
				),
				'structuredContent' => $result,
				'isError'           => false,
			),
			$version
		);
	}

	private function execute_tool( $name, $arguments ) {
		switch ( $name ) {
			case 'search_posts':
				return $this->search_posts( $arguments );
			case 'get_post':
				return $this->get_single_post( $arguments );
			case 'get_categories':
				return array( 'categories' => $this->format_terms( get_categories( $this->term_args_from( $arguments ) ) ) );
			case 'get_tags':
				return array( 'tags' => $this->format_terms( get_tags( $this->term_args_from( $arguments ) ) ) );
			case 'get_site_info':
				return array( 'site_info' => $this->site_info() );
		}

		if ( ! class_exists( 'AI_Chat_Bedrock_Site_Abilities' ) || ! AI_Chat_Bedrock_Site_Abilities::enabled() ) {
			return new WP_Error( 'tool_unavailable', __( 'This tool is not enabled on this site.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$abilities = new AI_Chat_Bedrock_Site_Abilities();
		switch ( $name ) {
			case 'suggest_seo_meta':
				if ( ! $abilities->can_draft() ) {
					return new WP_Error( 'forbidden', __( 'This account cannot analyse content.', 'ai-chat-for-amazon-bedrock' ) );
				}
				return $abilities->suggest_seo_meta( $arguments );

			case 'create_draft':
				if ( ! $abilities->can_draft() ) {
					return new WP_Error( 'forbidden', __( 'This account cannot create drafts.', 'ai-chat-for-amazon-bedrock' ) );
				}
				return $abilities->create_draft( $arguments );

			case 'get_products':
				if ( ! $abilities->can_read() ) {
					return new WP_Error( 'forbidden', __( 'This account cannot read products.', 'ai-chat-for-amazon-bedrock' ) );
				}
				return $abilities->get_products( $arguments );
		}

		return new WP_Error( 'unknown_tool', __( 'Unknown tool.', 'ai-chat-for-amazon-bedrock' ) );
	}

	private function negotiate_version( $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		return in_array( $requested, self::SUPPORTED_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSION;
	}

	/**
	 * The revision a request is speaking.
	 *
	 * A modern client states it in _meta on every request. An older one states it once, in
	 * initialize, and this server has no session to remember it in, so an absent _meta means
	 * the caller is on the revision it was built against.
	 *
	 * @param array  $payload        Decoded JSON-RPC payload.
	 * @param string $header_version Value of the MCP-Protocol-Version header.
	 * @return string Revision, or an empty string when one was stated that is not supported.
	 */
	private function requested_version( $payload, $header_version = '' ) {
		$meta = array();
		if ( isset( $payload['params']['_meta'] ) && is_array( $payload['params']['_meta'] ) ) {
			$meta = $payload['params']['_meta'];
		}

		// Stated per request from 2026-07-28.
		$stated = isset( $meta[ self::META_PROTOCOL_VERSION ] ) ? (string) $meta[ self::META_PROTOCOL_VERSION ] : '';

		// Older revisions state it in the header instead, on every request.
		if ( '' === $stated ) {
			$stated = trim( (string) $header_version );
		}

		// A client that opens with the handshake is on an older revision by definition.
		if ( '' === $stated ) {
			$method = isset( $payload['method'] ) ? (string) $payload['method'] : '';
			if ( in_array( $method, array( 'initialize', 'notifications/initialized' ), true ) ) {
				return $this->negotiate_version( isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array() );
			}
			return self::PROTOCOL_VERSION;
		}

		return in_array( $stated, self::SUPPORTED_VERSIONS, true ) ? $stated : '';
	}

	/**
	 * Whether a revision expects the stateless shape.
	 *
	 * @param string $version Revision.
	 * @return bool
	 */
	private function is_modern( $version ) {
		return '' !== $version && $version >= '2026-07-28';
	}

	/**
	 * Add the freshness hints the 2026-07-28 revision requires on list results.
	 *
	 * @param array  $result  Result payload.
	 * @param string $version Negotiated revision.
	 * @return array
	 */
	private function cacheable( $result, $version ) {
		if ( ! $this->is_modern( $version ) ) {
			return $result;
		}

		$result['ttlMs'] = 60000;
		// The list depends on the caller's capabilities, so a shared cache must not keep it.
		$result['cacheScope'] = 'private';
		return $result;
	}

	private function capabilities() {
		return array(
			'tools'     => array( 'listChanged' => false ),
			'resources' => new stdClass(),
			'prompts'   => new stdClass(),
		);
	}

	private function server_info() {
		return array(
			'name'    => 'AI Chat for Amazon Bedrock — WordPress MCP',
			'version' => AI_CHAT_BEDROCK_VERSION,
		);
	}

	/**
	 * Wrap a result, shaped for the revision the caller asked for.
	 *
	 * From 2026-07-28 every result carries resultType and the server identifies itself in
	 * _meta. Older clients are sent exactly what they were sent before, so nothing they
	 * parse changes.
	 *
	 * @param mixed  $id      Request id.
	 * @param mixed  $result  Result payload.
	 * @param string $version Negotiated revision.
	 * @return array
	 */
	private function rpc_result( $id, $result, $version = '' ) {
		if ( $this->is_modern( $version ) && is_array( $result ) ) {
			$result['resultType']           = 'complete';
			$meta                           = isset( $result['_meta'] ) && is_array( $result['_meta'] ) ? $result['_meta'] : array();
			$meta[ self::META_SERVER_INFO ] = $this->server_info();
			$result['_meta']                = $meta;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private function rpc_error_body( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => (int) $code,
				'message' => $message,
			),
		);
	}

	private function rpc_error( $id, $code, $message ) {
		return rest_ensure_response( $this->rpc_error_body( $id, $code, $message ) );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public function handle_discover( $request ) {
		return rest_ensure_response(
			array(
				'name'        => 'WordPress MCP Server',
				'description' => 'Read-only tools for public WordPress content.',
				'version'     => AI_CHAT_BEDROCK_VERSION,
				'tools'       => array_values( $this->available_tools() ),
			)
		);
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public function handle_health( $request ) {
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'version' => AI_CHAT_BEDROCK_VERSION,
			)
		);
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public function handle_tool_search_posts( $request ) {
		return rest_ensure_response( $this->search_posts( $this->params( $request ) ) );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public function handle_tool_get_post( $request ) {
		$result = $this->get_single_post( $this->params( $request ) );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'post_not_found', $result->get_error_message(), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $result );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public function handle_tool_get_categories( $request ) {
		return rest_ensure_response( array( 'categories' => $this->format_terms( get_categories( $this->term_args_from( $this->params( $request ) ) ) ) ) );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public function handle_tool_get_tags( $request ) {
		return rest_ensure_response( array( 'tags' => $this->format_terms( get_tags( $this->term_args_from( $this->params( $request ) ) ) ) ) );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public function handle_tool_get_site_info( $request ) {
		return rest_ensure_response( array( 'site_info' => $this->site_info() ) );
	}

	private function search_posts( $params ) {
		$limit = isset( $params['limit'] ) ? max( 1, min( 20, absint( $params['limit'] ) ) ) : 5;
		$args  = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'ignore_sticky_posts' => true,
			'has_password'        => false,
		);

		if ( ! empty( $params['query'] ) ) {
			$args['s'] = sanitize_text_field( $params['query'] );
		}
		if ( ! empty( $params['category'] ) ) {
			$category = sanitize_text_field( $params['category'] );
			$args[ is_numeric( $category ) ? 'cat' : 'category_name' ] = is_numeric( $category ) ? absint( $category ) : sanitize_title( $category );
		}
		if ( ! empty( $params['tag'] ) ) {
			$tag = sanitize_text_field( $params['tag'] );
			$args[ is_numeric( $tag ) ? 'tag_id' : 'tag' ] = is_numeric( $tag ) ? absint( $tag ) : sanitize_title( $tag );
		}
		if ( ! empty( $params['author'] ) ) {
			$author = sanitize_text_field( $params['author'] );
			$args[ is_numeric( $author ) ? 'author' : 'author_name' ] = is_numeric( $author ) ? absint( $author ) : sanitize_user( $author );
		}

		$query = new WP_Query( $args );
		$posts = array();
		foreach ( $query->posts as $post ) {
			if ( $this->is_public_post( $post ) ) {
				$posts[] = $this->format_post( $post );
			}
		}
		return array(
			'posts' => $posts,
			'total' => (int) $query->found_posts,
		);
	}

	private function get_single_post( $params ) {
		$post = null;
		if ( ! empty( $params['id'] ) ) {
			$post = get_post( absint( $params['id'] ) );
		} elseif ( ! empty( $params['slug'] ) ) {
			$posts = get_posts(
				array(
					'name'        => sanitize_title( $params['slug'] ),
					'post_type'   => 'post',
					'post_status' => 'publish',
					'numberposts' => 1,
				)
			);
			$post  = empty( $posts ) ? null : $posts[0];
		}
		if ( ! $this->is_public_post( $post ) || 'post' !== $post->post_type ) {
			return new WP_Error( 'post_not_found', __( 'Published post not found.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return array( 'post' => $this->format_post( $post, true ) );
	}

	private function is_public_post( $post ) {
		return $post instanceof WP_Post && 'publish' === $post->post_status && '' === $post->post_password;
	}

	private function site_info() {
		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url( '/' ),
			'language'    => get_bloginfo( 'language' ),
			'post_count'  => (int) wp_count_posts()->publish,
		);
	}

	public function format_post( $post, $include_content = false ) {
		$author = get_userdata( $post->post_author );
		$data   = array(
			'id'      => (int) $post->ID,
			'title'   => get_the_title( $post ),
			'slug'    => $post->post_name,
			'date'    => get_the_date( 'c', $post ),
			'excerpt' => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'author'  => $author ? $author->display_name : '',
			'url'     => get_permalink( $post ),
		);
		if ( $include_content ) {
			$data['content'] = wp_strip_all_tags( strip_shortcodes( apply_filters( 'the_content', $post->post_content ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying a core filter, not declaring a hook.
		}
		return $data;
	}

	private function params( $request ) {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : array();
	}

	private function term_args_from( $params ) {
		return array(
			'hide_empty' => ! empty( $params['hide_empty'] ),
			'number'     => isset( $params['limit'] ) ? max( 1, min( 100, absint( $params['limit'] ) ) ) : 20,
		);
	}

	private function format_terms( $terms ) {
		$output = array();
		if ( is_wp_error( $terms ) ) {
			return $output;
		}
		foreach ( $terms as $term ) {
			$output[] = array(
				'id'          => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => wp_strip_all_tags( $term->description ),
				'count'       => (int) $term->count,
			);
		}
		return $output;
	}
}
