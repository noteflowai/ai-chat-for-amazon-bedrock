<?php
/**
 * Security helpers for AI Chat for Amazon Bedrock.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Security {

	const ENCRYPTION_PREFIX = 'aicfab:v1:';

	/**
	 * Encrypt a secret using the site's authentication salt.
	 *
	 * @param string $value Plaintext value.
	 * @return string Encrypted value, or an empty string on failure.
	 */
	public static function encrypt_secret( $value ) {
		$value = (string) $value;
		if ( '' === $value || self::is_encrypted( $value ) ) {
			return $value;
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) || ! function_exists( 'random_bytes' ) ) {
			return '';
		}

		try {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $value, $nonce, self::encryption_key() );
			return self::ENCRYPTION_PREFIX . base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storing an encrypted value as text, not obfuscation.
		} catch ( Exception $exception ) {
			return '';
		}
	}

	/**
	 * Decrypt a stored secret. Plaintext values are returned for legacy migration.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function decrypt_secret( $value ) {
		$value = (string) $value;
		if ( '' === $value || ! self::is_encrypted( $value ) ) {
			return $value;
		}

		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}

		$decoded = base64_decode( substr( $value, strlen( self::ENCRYPTION_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storing an encrypted value as text, not obfuscation.
		if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::encryption_key() );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Whether a value uses the plugin encryption envelope.
	 *
	 * @param string $value Value to inspect.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return 0 === strpos( (string) $value, self::ENCRYPTION_PREFIX );
	}

	/**
	 * Encrypt legacy credentials after an administrator loads the dashboard.
	 */
	public function maybe_migrate_credentials() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = get_option( 'ai_chat_bedrock_settings', array() );
		if ( ! is_array( $options ) ) {
			return;
		}

		$changed = false;
		foreach ( array( 'aws_access_key', 'aws_secret_key', 'aws_session_token' ) as $key ) {
			if ( empty( $options[ $key ] ) || self::is_encrypted( $options[ $key ] ) ) {
				continue;
			}
			$encrypted = self::encrypt_secret( $options[ $key ] );
			if ( '' !== $encrypted ) {
				$options[ $key ] = $encrypted;
				$changed         = true;
			}
		}

		if ( $changed ) {
			update_option( 'ai_chat_bedrock_settings', $options, false );
		}
	}

	/**
	 * Whether the current visitor may use the chat endpoint.
	 *
	 * @return bool
	 */
	public static function can_use_chat( $options = null ) {
		if ( is_user_logged_in() ) {
			return true;
		}

		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
			$options = is_array( $options ) ? $options : array();
		}
		return ! empty( $options['allow_public_chat'] );
	}

	/**
	 * Apply a privacy-preserving per-client fixed-window rate limit.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit  Maximum requests.
	 * @param int    $window Window in seconds.
	 * @return bool
	 */
	public static function check_rate_limit( $bucket, $limit, $window = 60 ) {
		$limit  = max( 1, (int) $limit );
		$window = max( 1, (int) $window );
		$key    = 'aicfab_rl_' . md5( sanitize_key( $bucket ) . '|' . self::client_identifier() );
		$count  = get_transient( $key );

		if ( false === $count ) {
			set_transient( $key, 1, $window );
			return true;
		}

		if ( (int) $count >= $limit ) {
			return false;
		}

		set_transient( $key, (int) $count + 1, $window );
		return true;
	}

	/**
	 * Validate an outbound MCP URL. HTTPS and WordPress safe-URL checks are mandatory.
	 *
	 * @param string $url URL to validate.
	 * @return bool
	 */
	public static function is_safe_mcp_url( $url ) {
		$url   = esc_url_raw( (string) $url, array( 'https' ) );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		return (bool) wp_http_validate_url( $url );
	}

	/**
	 * Normalize untrusted conversation history.
	 *
	 * @param mixed $history      Decoded history.
	 * @param int   $max_messages Maximum entries.
	 * @param int   $max_chars    Maximum characters per entry.
	 * @return array
	 */
	public static function sanitize_history( $history, $max_messages = 12, $max_chars = 4000 ) {
		if ( ! is_array( $history ) ) {
			return array();
		}

		$clean = array();
		foreach ( array_slice( $history, -absint( $max_messages ) ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['role'] ) || ! isset( $entry['content'] ) ) {
				continue;
			}
			$role = sanitize_key( $entry['role'] );
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$content = sanitize_textarea_field( (string) $entry['content'] );
			if ( self::string_length( $content ) > $max_chars ) {
				$content = self::string_substr( $content, 0, $max_chars );
			}
			if ( '' !== $content ) {
				$clean[] = array(
					'role'    => $role,
					'content' => $content,
				);
			}
		}
		return $clean;
	}

	public static function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	public static function string_substr( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, $start, $length, 'UTF-8' ) : substr( $value, $start, $length );
	}

	private static function encryption_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|ai-chat-for-amazon-bedrock|credentials', true );
	}

	private static function client_identifier() {
		if ( is_user_logged_in() ) {
			return 'user:' . get_current_user_id();
		}
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'guest:' . hash_hmac( 'sha256', $address, wp_salt( 'nonce' ) );
	}
}
