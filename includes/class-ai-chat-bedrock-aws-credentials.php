<?php
/**
 * Amazon Bedrock credential resolution.
 *
 * Resolution order:
 *   1. wp-config.php constants
 *   2. Encrypted plugin options
 *   3. Process environment variables
 *   4. ECS/EKS container credential endpoint
 *   5. EC2 instance metadata service (IMDSv2)
 *
 * Sources 3-5 remove the need to store long-lived AWS keys in WordPress.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_AWS_Credentials {

	const IMDS_HOST          = '169.254.169.254';
	const CONTAINER_HOST     = '169.254.170.2';
	const ROLE_CACHE_KEY     = 'aicfab_role_credentials';
	const ROLE_CACHE_MIN_TTL = 60;
	const ROLE_CACHE_MAX_TTL = 900;
	const ROLE_EXPIRY_BUFFER = 300;

	/**
	 * Resolve credentials for signing Bedrock requests.
	 *
	 * @param array $options Plugin options.
	 * @return array|WP_Error Credential array with access_key, secret_key, session_token and source.
	 */
	public static function resolve( $options = null ) {
		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
			$options = is_array( $options ) ? $options : array();
		}

		$static = self::from_constants();
		if ( null !== $static ) {
			return $static;
		}

		$static = self::from_options( $options );
		if ( null !== $static ) {
			return $static;
		}

		if ( ! self::role_credentials_enabled( $options ) ) {
			return new WP_Error( 'aicfab_no_credentials', __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$cached = self::cached_role_credentials();
		if ( null !== $cached ) {
			return $cached;
		}

		foreach ( array( 'from_environment', 'from_container_role', 'from_instance_role' ) as $provider ) {
			$credentials = self::{$provider}();
			if ( null === $credentials ) {
				continue;
			}
			if ( 'from_environment' !== $provider ) {
				self::cache_role_credentials( $credentials );
			}
			return $credentials;
		}

		return new WP_Error( 'aicfab_no_credentials', __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' ) );
	}

	/**
	 * Describe the active credential source without exposing secret values.
	 *
	 * @param array $options Plugin options.
	 * @return array
	 */
	public static function describe( $options = null ) {
		$credentials = self::resolve( $options );
		if ( is_wp_error( $credentials ) ) {
			return array(
				'configured' => false,
				'source'     => 'none',
				'temporary'  => false,
				'message'    => $credentials->get_error_message(),
			);
		}

		return array(
			'configured' => true,
			'source'     => $credentials['source'],
			'temporary'  => '' !== $credentials['session_token'],
			'message'    => self::source_label( $credentials['source'] ),
		);
	}

	/**
	 * Whether role-based credential providers may be used.
	 *
	 * @param array $options Plugin options.
	 * @return bool
	 */
	public static function role_credentials_enabled( $options ) {
		$enabled = ! isset( $options['aws_use_role_credentials'] ) || ! empty( $options['aws_use_role_credentials'] );
		return (bool) apply_filters( 'ai_chat_bedrock_use_role_credentials', $enabled );
	}

	/**
	 * Human readable label for a credential source.
	 *
	 * @param string $source Source identifier.
	 * @return string
	 */
	public static function source_label( $source ) {
		$labels = array(
			'constants'      => __( 'wp-config.php constants', 'ai-chat-for-amazon-bedrock' ),
			'options'        => __( 'Encrypted WordPress settings', 'ai-chat-for-amazon-bedrock' ),
			'environment'    => __( 'Server environment variables', 'ai-chat-for-amazon-bedrock' ),
			'container_role' => __( 'ECS or EKS task role', 'ai-chat-for-amazon-bedrock' ),
			'instance_role'  => __( 'EC2 instance role (IMDSv2)', 'ai-chat-for-amazon-bedrock' ),
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : __( 'Unknown source', 'ai-chat-for-amazon-bedrock' );
	}

	/**
	 * Discard cached role credentials.
	 */
	public static function flush_cache() {
		delete_transient( self::ROLE_CACHE_KEY );
	}

	private static function from_constants() {
		if ( ! defined( 'AI_CHAT_BEDROCK_AWS_ACCESS_KEY' ) || ! defined( 'AI_CHAT_BEDROCK_AWS_SECRET_KEY' ) ) {
			return null;
		}
		$access = trim( (string) constant( 'AI_CHAT_BEDROCK_AWS_ACCESS_KEY' ) );
		$secret = trim( (string) constant( 'AI_CHAT_BEDROCK_AWS_SECRET_KEY' ) );
		$token  = defined( 'AI_CHAT_BEDROCK_AWS_SESSION_TOKEN' ) ? trim( (string) constant( 'AI_CHAT_BEDROCK_AWS_SESSION_TOKEN' ) ) : '';
		return self::build( $access, $secret, $token, 'constants' );
	}

	private static function from_options( $options ) {
		$access = isset( $options['aws_access_key'] ) ? AI_Chat_Bedrock_Security::decrypt_secret( $options['aws_access_key'] ) : '';
		$secret = isset( $options['aws_secret_key'] ) ? AI_Chat_Bedrock_Security::decrypt_secret( $options['aws_secret_key'] ) : '';
		$token  = isset( $options['aws_session_token'] ) ? AI_Chat_Bedrock_Security::decrypt_secret( $options['aws_session_token'] ) : '';
		return self::build( $access, $secret, $token, 'options' );
	}

	private static function from_environment() {
		$access = self::env( 'AWS_ACCESS_KEY_ID' );
		$secret = self::env( 'AWS_SECRET_ACCESS_KEY' );
		$token  = self::env( 'AWS_SESSION_TOKEN' );
		return self::build( $access, $secret, $token, 'environment' );
	}

	private static function from_container_role() {
		$relative = self::env( 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI' );
		$full     = self::env( 'AWS_CONTAINER_CREDENTIALS_FULL_URI' );

		if ( '' !== $relative ) {
			$url = 'http://' . self::CONTAINER_HOST . '/' . ltrim( $relative, '/' );
		} elseif ( '' !== $full && self::is_allowed_container_uri( $full ) ) {
			$url = $full;
		} else {
			return null;
		}

		$headers = array( 'Accept' => 'application/json' );
		$token   = self::container_authorization_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = $token;
		}

		$body = self::request( 'GET', $url, $headers );
		return null === $body ? null : self::from_metadata_document( $body, 'container_role' );
	}

	private static function from_instance_role() {
		$base  = 'http://' . self::IMDS_HOST . '/latest';
		$token = self::request(
			'PUT',
			$base . '/api/token',
			array( 'X-aws-ec2-metadata-token-ttl-seconds' => '60' )
		);
		if ( null === $token || '' === trim( $token ) ) {
			return null;
		}
		$headers = array( 'X-aws-ec2-metadata-token' => trim( $token ) );

		$role = self::request( 'GET', $base . '/meta-data/iam/security-credentials/', $headers );
		if ( null === $role ) {
			return null;
		}
		$role = trim( strtok( trim( $role ), "\n" ) );
		if ( '' === $role || ! preg_match( '#^[A-Za-z0-9+=,.@_/-]{1,128}$#', $role ) ) {
			return null;
		}

		$document = self::request( 'GET', $base . '/meta-data/iam/security-credentials/' . rawurlencode( $role ), $headers );
		return null === $document ? null : self::from_metadata_document( $document, 'instance_role' );
	}

	private static function from_metadata_document( $body, $source ) {
		$data = json_decode( (string) $body, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( isset( $data['Code'] ) && 'Success' !== $data['Code'] ) {
			return null;
		}

		$credentials = self::build(
			isset( $data['AccessKeyId'] ) ? (string) $data['AccessKeyId'] : '',
			isset( $data['SecretAccessKey'] ) ? (string) $data['SecretAccessKey'] : '',
			isset( $data['Token'] ) ? (string) $data['Token'] : '',
			$source
		);
		if ( null === $credentials || '' === $credentials['session_token'] ) {
			return null;
		}

		if ( ! empty( $data['Expiration'] ) ) {
			$expires = strtotime( (string) $data['Expiration'] );
			if ( false !== $expires ) {
				$credentials['expires'] = $expires;
			}
		}
		return $credentials;
	}

	private static function cached_role_credentials() {
		$cached = get_transient( self::ROLE_CACHE_KEY );
		if ( ! is_array( $cached ) || empty( $cached['access_key'] ) || empty( $cached['secret_key'] ) ) {
			return null;
		}

		$access = AI_Chat_Bedrock_Security::decrypt_secret( $cached['access_key'] );
		$secret = AI_Chat_Bedrock_Security::decrypt_secret( $cached['secret_key'] );
		$token  = isset( $cached['session_token'] ) ? AI_Chat_Bedrock_Security::decrypt_secret( $cached['session_token'] ) : '';
		$source = isset( $cached['source'] ) ? sanitize_key( $cached['source'] ) : 'instance_role';

		if ( isset( $cached['expires'] ) && (int) $cached['expires'] - self::ROLE_EXPIRY_BUFFER <= time() ) {
			self::flush_cache();
			return null;
		}
		return self::build( $access, $secret, $token, $source );
	}

	private static function cache_role_credentials( $credentials ) {
		$access = AI_Chat_Bedrock_Security::encrypt_secret( $credentials['access_key'] );
		$secret = AI_Chat_Bedrock_Security::encrypt_secret( $credentials['secret_key'] );
		$token  = AI_Chat_Bedrock_Security::encrypt_secret( $credentials['session_token'] );
		if ( '' === $access || '' === $secret || '' === $token ) {
			return;
		}

		$ttl = self::ROLE_CACHE_MAX_TTL;
		if ( isset( $credentials['expires'] ) ) {
			$ttl = (int) $credentials['expires'] - self::ROLE_EXPIRY_BUFFER - time();
		}
		$ttl = max( self::ROLE_CACHE_MIN_TTL, min( self::ROLE_CACHE_MAX_TTL, $ttl ) );

		$payload = array(
			'access_key'    => $access,
			'secret_key'    => $secret,
			'session_token' => $token,
			'source'        => $credentials['source'],
		);
		if ( isset( $credentials['expires'] ) ) {
			$payload['expires'] = (int) $credentials['expires'];
		}
		set_transient( self::ROLE_CACHE_KEY, $payload, $ttl );
	}

	/**
	 * Perform a metadata request.
	 *
	 * The IMDS and container credential endpoints are link-local addresses that
	 * WordPress safe-HTTP helpers deliberately reject, so these fixed, non
	 * user-controlled URLs are requested directly with redirects disabled.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     Metadata URL.
	 * @param array  $headers Request headers.
	 * @return string|null
	 */
	private static function request( $method, $url, $headers = array() ) {
		$args = array(
			'method'             => $method,
			'timeout'            => 2,
			'redirection'        => 0,
			'httpversion'        => '1.1',
			'reject_unsafe_urls' => false,
			'sslverify'          => false,
			'headers'            => $headers,
		);

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $response );
		return strlen( $body ) > 8192 ? null : $body;
	}

	private static function container_authorization_token() {
		$file = self::env( 'AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE' );
		if ( '' !== $file && 0 === strpos( $file, '/' ) && is_readable( $file ) && filesize( $file ) <= 8192 ) {
			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $contents ) {
				return trim( $contents );
			}
		}
		return self::env( 'AWS_CONTAINER_AUTHORIZATION_TOKEN' );
	}

	private static function is_allowed_container_uri( $uri ) {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		if ( 'https' === $scheme ) {
			return true;
		}
		if ( 'http' !== $scheme ) {
			return false;
		}
		return in_array( $host, array( self::CONTAINER_HOST, 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
	}

	private static function env( $name ) {
		$value = getenv( $name );
		if ( false === $value && isset( $_SERVER[ $name ] ) ) {
			// A server variable, not request input, but it is still unslashed and cleaned.
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) );
		}
		return false === $value ? '' : trim( (string) $value );
	}

	private static function build( $access_key, $secret_key, $session_token, $source ) {
		$access_key    = self::clean( $access_key );
		$secret_key    = self::clean( $secret_key );
		$session_token = self::clean( $session_token );

		if ( '' === $access_key || '' === $secret_key ) {
			return null;
		}
		return array(
			'access_key'    => $access_key,
			'secret_key'    => $secret_key,
			'session_token' => $session_token,
			'source'        => $source,
		);
	}

	private static function clean( $value ) {
		$value = trim( (string) $value );
		return preg_replace( '/[\r\n\t\0\x0B]/', '', $value );
	}
}
