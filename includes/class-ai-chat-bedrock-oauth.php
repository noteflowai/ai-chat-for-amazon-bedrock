<?php
/**
 * OAuth 2.1 authorization server for the WordPress MCP endpoint.
 *
 * Lets MCP clients such as Claude Desktop connect by pasting the MCP URL: the
 * client registers itself, the site owner signs in to WordPress and approves, and
 * the client receives a short-lived access token. No shared secret is exchanged
 * out of band and no WordPress password ever reaches the client.
 *
 * Security properties:
 *   - PKCE with S256 is mandatory; plain challenges are rejected.
 *   - Redirect URIs must be HTTPS or loopback, as required by OAuth 2.1.
 *   - Authorization codes are single use with a short lifetime.
 *   - Access and refresh tokens are stored only as SHA-256 hashes.
 *   - Refresh tokens rotate, and reuse of a rotated token revokes the grant.
 *   - Grants are bound to one WordPress user; capability checks still apply.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_OAuth {

	const OPTION_CLIENTS = 'ai_chat_bedrock_oauth_clients';
	const OPTION_GRANTS  = 'ai_chat_bedrock_oauth_grants';
	const CODE_PREFIX    = 'aicfab_oauth_code_';
	const CODE_TTL       = 600;
	const ACCESS_TTL     = 3600;
	const REFRESH_TTL    = 2592000;
	const MAX_CLIENTS    = 25;
	const MAX_GRANTS     = 50;
	const SCOPE          = 'mcp';

	/**
	 * Whether OAuth connections are enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$enabled = (bool) get_option( 'ai_chat_bedrock_oauth_enabled', false );
		return (bool) apply_filters( 'ai_chat_bedrock_oauth_enabled', $enabled );
	}

	/**
	 * Metadata document paths handled at the site root.
	 *
	 * @return array
	 */
	public static function well_known_paths() {
		return array(
			'.well-known/oauth-authorization-server' => 'authorization_server_metadata',
			'.well-known/oauth-protected-resource'   => 'protected_resource_metadata',
		);
	}

	/**
	 * Register REST routes for registration, authorization and tokens.
	 */
	public function register_routes() {
		$namespace = AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1;

		register_rest_route(
			$namespace,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_register' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$namespace,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$namespace,
			'/oauth/metadata',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Serve the well-known metadata documents and the authorization screen.
	 *
	 * Intercepting the request avoids depending on flushed rewrite rules.
	 *
	 * @param bool $should_continue Whether WordPress should keep parsing the request.
	 * @return bool
	 */
	public function maybe_handle_root_request( $should_continue ) {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = trim( (string) $path, '/' );

		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
		}

		foreach ( self::well_known_paths() as $candidate => $method ) {
			if ( $path === $candidate ) {
				$this->send_json( $this->{$method}() );
			}
		}

		if ( 'ai-chat-bedrock-authorize' === $path ) {
			$this->render_authorization_screen();
		}

		return $should_continue;
	}

	/**
	 * Authorization server metadata (RFC 8414).
	 *
	 * @return array
	 */
	public function authorization_server_metadata() {
		return array(
			'issuer'                                => home_url( '/' ),
			'authorization_endpoint'                => home_url( '/ai-chat-bedrock-authorize' ),
			'token_endpoint'                        => rest_url( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . '/oauth/token' ),
			'registration_endpoint'                 => rest_url( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . '/oauth/register' ),
			'scopes_supported'                      => array( self::SCOPE ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'service_documentation'                 => 'https://wordpress.org/plugins/ai-chat-for-amazon-bedrock/',
		);
	}

	/**
	 * Protected resource metadata (RFC 9728).
	 *
	 * @return array
	 */
	public function protected_resource_metadata() {
		return array(
			'resource'                 => rest_url( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . '/mcp' ),
			'authorization_servers'    => array( home_url( '/' ) ),
			'scopes_supported'         => array( self::SCOPE ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * Metadata over REST for clients that cannot reach the site root.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_metadata() {
		return rest_ensure_response(
			array(
				'authorization_server' => $this->authorization_server_metadata(),
				'protected_resource'   => $this->protected_resource_metadata(),
				'enabled'              => self::enabled(),
			)
		);
	}

	/**
	 * Dynamic client registration (RFC 7591).
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_register( $request ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'aicfab_oauth_disabled', __( 'OAuth connections are disabled on this site.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'oauth-register', 5, 300 ) ) {
			return new WP_Error( 'aicfab_oauth_rate_limited', __( 'Too many registration attempts.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}

		$body      = $request->get_json_params();
		$body      = is_array( $body ) ? $body : array();
		$name      = isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : '';
		$redirects = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();

		$clean_redirects = array();
		foreach ( array_slice( $redirects, 0, 5 ) as $uri ) {
			$uri = esc_url_raw( (string) $uri, array( 'https', 'http' ) );
			if ( '' !== $uri && self::is_allowed_redirect( $uri ) ) {
				$clean_redirects[] = $uri;
			}
		}
		if ( empty( $clean_redirects ) ) {
			return new WP_Error( 'invalid_redirect_uri', __( 'At least one HTTPS or loopback redirect URI is required.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}

		$clients = $this->clients();
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			$clients = array_slice( $clients, -self::MAX_CLIENTS + 1, null, true );
		}

		$client_id             = 'aicfab_' . wp_generate_password( 24, false, false );
		$clients[ $client_id ] = array(
			'client_id'     => $client_id,
			'client_name'   => '' !== $name ? AI_Chat_Bedrock_Security::string_substr( $name, 0, 120 ) : __( 'MCP client', 'ai-chat-for-amazon-bedrock' ),
			'redirect_uris' => $clean_redirects,
			'created'       => time(),
		);
		update_option( self::OPTION_CLIENTS, $clients, false );

		return new WP_REST_Response(
			array(
				'client_id'                  => $client_id,
				'client_name'                => $clients[ $client_id ]['client_name'],
				'redirect_uris'              => $clean_redirects,
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
				'scope'                      => self::SCOPE,
			),
			201
		);
	}

	/**
	 * Render the consent screen and issue authorization codes.
	 */
	public function render_authorization_screen() {
		if ( ! self::enabled() ) {
			$this->send_html( __( 'OAuth connections are disabled on this site.', 'ai-chat-for-amazon-bedrock' ), 404 );
		}

		$client_id     = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_uri  = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ), array( 'https', 'http' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state         = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$challenge     = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$method        = isset( $_GET['code_challenge_method'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$response_type = isset( $_GET['response_type'] ) ? sanitize_key( wp_unslash( $_GET['response_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$client = $this->client( $client_id );
		if ( ! $client ) {
			$this->send_html( __( 'Unknown OAuth client.', 'ai-chat-for-amazon-bedrock' ), 400 );
		}
		if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			$this->send_html( __( 'The redirect URI does not match this client registration.', 'ai-chat-for-amazon-bedrock' ), 400 );
		}
		if ( 'code' !== $response_type ) {
			$this->redirect_error( $redirect_uri, 'unsupported_response_type', $state );
		}
		if ( 'S256' !== $method || ! preg_match( '#^[A-Za-z0-9_-]{43,128}$#', $challenge ) ) {
			$this->redirect_error( $redirect_uri, 'invalid_request', $state );
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::current_url() ) );
			exit;
		}
		if ( ! current_user_can( AI_Chat_Bedrock_Tool_Policy::required_capability() ) ) {
			$this->send_html( __( 'This account is not allowed to connect AI clients to this site.', 'ai-chat-for-amazon-bedrock' ), 403 );
		}

		$approved = isset( $_POST['aicfab_oauth_approve'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $approved ) {
			if ( ! isset( $_POST['aicfab_oauth_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aicfab_oauth_nonce'] ) ), 'aicfab_oauth_consent' ) ) {
				$this->send_html( __( 'Security check failed. Reload the authorization page and try again.', 'ai-chat-for-amazon-bedrock' ), 403 );
			}

			$code = wp_generate_password( 48, false, false );
			set_transient(
				self::CODE_PREFIX . hash( 'sha256', $code ),
				array(
					'client_id'    => $client['client_id'],
					'user'         => get_current_user_id(),
					'redirect_uri' => $redirect_uri,
					'challenge'    => $challenge,
					'created'      => time(),
				),
				self::CODE_TTL
			);

			$location = add_query_arg(
				array_filter(
					array(
						'code'  => $code,
						'state' => '' !== $state ? $state : null,
					)
				),
				$redirect_uri
			);
			wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		if ( isset( $_POST['aicfab_oauth_deny'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->redirect_error( $redirect_uri, 'access_denied', $state );
		}

		$this->render_consent_form( $client, $state );
	}

	private function render_consent_form( $client, $state ) {
		$user = wp_get_current_user();
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Authorize AI client', 'ai-chat-for-amazon-bedrock' ); ?></title>
	<style>
		body { margin: 0; padding: 40px 20px; background: #f0f2f6; color: #14213d; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
		.box { max-width: 460px; margin: 0 auto; padding: 28px 30px 26px; background: #fff; border: 1px solid #dfe4ec; border-radius: 14px; box-shadow: 0 10px 30px rgba(20,33,61,.08); }
		h1 { margin: 0 0 6px; font-size: 20px; }
		p { margin: 10px 0; font-size: 14px; line-height: 1.55; color: #47536b; }
		.client { padding: 12px 14px; margin: 16px 0; background: #f6f8fb; border: 1px solid #e3e8f0; border-radius: 10px; font-weight: 600; }
		ul { margin: 12px 0 18px; padding-left: 20px; font-size: 14px; color: #47536b; }
		li { margin-bottom: 6px; }
		.actions { display: flex; gap: 10px; margin-top: 20px; }
		button { flex: 1; padding: 11px 14px; font: inherit; font-weight: 600; border-radius: 9px; cursor: pointer; border: 1px solid transparent; }
		.approve { color: #fff; background: #1d4ed8; }
		.deny { color: #47536b; background: #fff; border-color: #dfe4ec; }
	</style>
</head>
<body>
	<div class="box">
		<h1><?php esc_html_e( 'Authorize AI client', 'ai-chat-for-amazon-bedrock' ); ?></h1>
		<p><?php esc_html_e( 'An AI client is requesting access to this site through the Model Context Protocol.', 'ai-chat-for-amazon-bedrock' ); ?></p>
		<div class="client"><?php echo esc_html( $client['client_name'] ); ?></div>
		<p>
			<?php
			printf(
				/* translators: %s: WordPress display name. */
				esc_html__( 'Access will act as %s and is limited by that account’s permissions.', 'ai-chat-for-amazon-bedrock' ),
				'<strong>' . esc_html( $user->display_name ) . '</strong>'
			);
			?>
		</p>
		<ul>
			<li><?php esc_html_e( 'Read published posts, pages, categories and site information', 'ai-chat-for-amazon-bedrock' ); ?></li>
			<li><?php esc_html_e( 'Use only the tools this site has enabled', 'ai-chat-for-amazon-bedrock' ); ?></li>
			<li><?php esc_html_e( 'Never publish, update or delete existing content', 'ai-chat-for-amazon-bedrock' ); ?></li>
			<li><?php esc_html_e( 'Access can be revoked at any time in the MCP settings', 'ai-chat-for-amazon-bedrock' ); ?></li>
		</ul>
		<form method="post">
			<?php wp_nonce_field( 'aicfab_oauth_consent', 'aicfab_oauth_nonce' ); ?>
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
			<div class="actions">
				<button type="submit" class="deny" name="aicfab_oauth_deny" value="1"><?php esc_html_e( 'Deny', 'ai-chat-for-amazon-bedrock' ); ?></button>
				<button type="submit" class="approve" name="aicfab_oauth_approve" value="1"><?php esc_html_e( 'Approve', 'ai-chat-for-amazon-bedrock' ); ?></button>
			</div>
		</form>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Token endpoint: authorization_code and refresh_token grants.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_token( $request ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'aicfab_oauth_disabled', __( 'OAuth connections are disabled on this site.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'oauth-token', 20, 300 ) ) {
			return $this->token_error( 'invalid_request', __( 'Too many token requests.', 'ai-chat-for-amazon-bedrock' ), 429 );
		}

		$grant_type = sanitize_key( (string) $request->get_param( 'grant_type' ) );
		if ( 'authorization_code' === $grant_type ) {
			return $this->grant_authorization_code( $request );
		}
		if ( 'refresh_token' === $grant_type ) {
			return $this->grant_refresh_token( $request );
		}
		return $this->token_error( 'unsupported_grant_type', __( 'Unsupported grant type.', 'ai-chat-for-amazon-bedrock' ) );
	}

	private function grant_authorization_code( $request ) {
		$code         = (string) $request->get_param( 'code' );
		$verifier     = (string) $request->get_param( 'code_verifier' );
		$client_id    = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
		$redirect_uri = esc_url_raw( (string) $request->get_param( 'redirect_uri' ), array( 'https', 'http' ) );

		if ( '' === $code || '' === $verifier ) {
			return $this->token_error( 'invalid_request', __( 'A code and code_verifier are required.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( ! preg_match( '#^[A-Za-z0-9._~-]{43,128}$#', $verifier ) ) {
			return $this->token_error( 'invalid_grant', __( 'The code verifier is malformed.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$key    = self::CODE_PREFIX . hash( 'sha256', $code );
		$stored = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $stored ) ) {
			return $this->token_error( 'invalid_grant', __( 'The authorization code is invalid or expired.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( $stored['client_id'] !== $client_id || $stored['redirect_uri'] !== $redirect_uri ) {
			return $this->token_error( 'invalid_grant', __( 'The authorization code does not match this client.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE challenge encoding required by the spec, not obfuscation.
		if ( ! hash_equals( $stored['challenge'], $expected ) ) {
			return $this->token_error( 'invalid_grant', __( 'The code verifier does not match the challenge.', 'ai-chat-for-amazon-bedrock' ) );
		}

		return $this->issue_tokens( $stored['client_id'], (int) $stored['user'] );
	}

	private function grant_refresh_token( $request ) {
		$refresh   = (string) $request->get_param( 'refresh_token' );
		$client_id = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
		if ( '' === $refresh ) {
			return $this->token_error( 'invalid_request', __( 'A refresh token is required.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$hash   = hash( 'sha256', $refresh );
		$grants = $this->grants();

		foreach ( $grants as $id => $grant ) {
			if ( ! isset( $grant['refresh_hash'] ) ) {
				continue;
			}
			if ( hash_equals( (string) $grant['refresh_hash'], $hash ) ) {
				if ( $grant['client_id'] !== $client_id ) {
					return $this->token_error( 'invalid_grant', __( 'The refresh token does not match this client.', 'ai-chat-for-amazon-bedrock' ) );
				}
				if ( isset( $grant['refresh_expires'] ) && (int) $grant['refresh_expires'] < time() ) {
					$this->revoke_grant( $id );
					return $this->token_error( 'invalid_grant', __( 'The refresh token has expired.', 'ai-chat-for-amazon-bedrock' ) );
				}
				$this->revoke_grant( $id );
				return $this->issue_tokens( $grant['client_id'], (int) $grant['user'] );
			}

			// Reuse of an already rotated refresh token revokes the grant.
			foreach ( isset( $grant['used_refresh'] ) ? (array) $grant['used_refresh'] : array() as $used ) {
				if ( hash_equals( (string) $used, $hash ) ) {
					$this->revoke_grant( $id );
					return $this->token_error( 'invalid_grant', __( 'This refresh token was already used; the connection has been revoked.', 'ai-chat-for-amazon-bedrock' ) );
				}
			}
		}

		return $this->token_error( 'invalid_grant', __( 'The refresh token is invalid.', 'ai-chat-for-amazon-bedrock' ) );
	}

	private function issue_tokens( $client_id, $user_id ) {
		$access  = wp_generate_password( 64, false, false );
		$refresh = wp_generate_password( 64, false, false );
		$grants  = $this->grants();

		if ( count( $grants ) >= self::MAX_GRANTS ) {
			$grants = array_slice( $grants, -self::MAX_GRANTS + 1, null, true );
		}

		$id            = 'g_' . wp_generate_password( 16, false, false );
		$grants[ $id ] = array(
			'id'              => $id,
			'client_id'       => $client_id,
			'user'            => (int) $user_id,
			'access_hash'     => hash( 'sha256', $access ),
			'refresh_hash'    => hash( 'sha256', $refresh ),
			'access_expires'  => time() + self::ACCESS_TTL,
			'refresh_expires' => time() + self::REFRESH_TTL,
			'created'         => time(),
			'used_refresh'    => array(),
		);
		update_option( self::OPTION_GRANTS, $this->prune( $grants ), false );

		return rest_ensure_response(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => self::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => self::SCOPE,
			)
		);
	}

	/**
	 * Authenticate MCP requests that present a bearer token.
	 *
	 * @param int|false $user_id Current user determined so far.
	 * @return int|false
	 */
	public function authenticate_bearer( $user_id ) {
		if ( ! empty( $user_id ) || ! self::enabled() ) {
			return $user_id;
		}

		$header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$header = trim( (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$header = trim( (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		if ( '' === $header || 0 !== stripos( $header, 'bearer ' ) ) {
			return $user_id;
		}

		$token = trim( substr( $header, 7 ) );
		if ( '' === $token || strlen( $token ) > 256 ) {
			return $user_id;
		}

		$hash = hash( 'sha256', $token );
		foreach ( $this->grants() as $id => $grant ) {
			if ( ! isset( $grant['access_hash'] ) || ! hash_equals( (string) $grant['access_hash'], $hash ) ) {
				continue;
			}
			if ( isset( $grant['access_expires'] ) && (int) $grant['access_expires'] < time() ) {
				return $user_id;
			}
			return (int) $grant['user'];
		}
		return $user_id;
	}

	/**
	 * Grants for the settings screen, without secret material.
	 *
	 * @return array
	 */
	public function grant_summaries() {
		$clients = $this->clients();
		$rows    = array();
		foreach ( $this->grants() as $id => $grant ) {
			$user   = get_userdata( (int) $grant['user'] );
			$rows[] = array(
				'id'      => $id,
				'client'  => isset( $clients[ $grant['client_id'] ]['client_name'] ) ? $clients[ $grant['client_id'] ]['client_name'] : $grant['client_id'],
				'user'    => $user ? $user->display_name : __( 'Unknown user', 'ai-chat-for-amazon-bedrock' ),
				'created' => isset( $grant['created'] ) ? (int) $grant['created'] : 0,
				'expires' => isset( $grant['access_expires'] ) ? (int) $grant['access_expires'] : 0,
				'active'  => isset( $grant['access_expires'] ) && (int) $grant['access_expires'] > time(),
			);
		}
		return $rows;
	}

	/**
	 * Revoke one grant.
	 *
	 * @param string $id Grant identifier.
	 * @return bool
	 */
	public function revoke_grant( $id ) {
		$grants = $this->grants();
		$id     = (string) $id;
		if ( ! isset( $grants[ $id ] ) ) {
			return false;
		}

		$used = isset( $grants[ $id ]['used_refresh'] ) ? (array) $grants[ $id ]['used_refresh'] : array();
		if ( isset( $grants[ $id ]['refresh_hash'] ) ) {
			$used[] = $grants[ $id ]['refresh_hash'];
		}
		unset( $grants[ $id ] );

		// Remember rotated tokens briefly so reuse can be detected.
		$revoked = get_option( 'ai_chat_bedrock_oauth_revoked', array() );
		$revoked = is_array( $revoked ) ? $revoked : array();
		foreach ( $used as $hash ) {
			$revoked[ (string) $hash ] = time();
		}
		if ( count( $revoked ) > 200 ) {
			$revoked = array_slice( $revoked, -200, null, true );
		}
		update_option( 'ai_chat_bedrock_oauth_revoked', $revoked, false );
		update_option( self::OPTION_GRANTS, $grants, false );
		return true;
	}

	/**
	 * Revoke every grant.
	 */
	public function revoke_all() {
		delete_option( self::OPTION_GRANTS );
		delete_option( 'ai_chat_bedrock_oauth_revoked' );
	}

	/**
	 * Whether a redirect URI is acceptable under OAuth 2.1.
	 *
	 * @param string $uri Redirect URI.
	 * @return bool
	 */
	public static function is_allowed_redirect( $uri ) {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( isset( $parts['fragment'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );

		if ( 'https' === $scheme ) {
			return true;
		}
		return 'http' === $scheme && in_array( $host, array( '127.0.0.1', '[::1]', 'localhost' ), true );
	}

	private function clients() {
		$clients = get_option( self::OPTION_CLIENTS, array() );
		return is_array( $clients ) ? $clients : array();
	}

	private function client( $client_id ) {
		$clients = $this->clients();
		return isset( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
	}

	private function grants() {
		$grants = get_option( self::OPTION_GRANTS, array() );
		return is_array( $grants ) ? $this->prune( $grants ) : array();
	}

	private function prune( $grants ) {
		$now   = time();
		$clean = array();
		foreach ( $grants as $id => $grant ) {
			if ( ! is_array( $grant ) || empty( $grant['refresh_hash'] ) ) {
				continue;
			}
			if ( isset( $grant['refresh_expires'] ) && (int) $grant['refresh_expires'] < $now ) {
				continue;
			}
			$clean[ $id ] = $grant;
		}
		return $clean;
	}

	private function token_error( $code, $description, $status = 400 ) {
		return new WP_REST_Response(
			array(
				'error'             => sanitize_key( $code ),
				'error_description' => $description,
			),
			$status
		);
	}

	private function redirect_error( $redirect_uri, $error, $state ) {
		$location = add_query_arg(
			array_filter(
				array(
					'error' => sanitize_key( $error ),
					'state' => '' !== $state ? $state : null,
				)
			),
			$redirect_uri
		);
		wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	private function send_json( $data ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function send_html( $message, $status ) {
		status_header( (int) $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . esc_html__( 'Authorization', 'ai-chat-for-amazon-bedrock' ) . '</title></head><body style="font-family:sans-serif;padding:40px"><p>' . esc_html( $message ) . '</p></body></html>';
		exit;
	}

	private static function current_url() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return home_url( $path );
	}
}
