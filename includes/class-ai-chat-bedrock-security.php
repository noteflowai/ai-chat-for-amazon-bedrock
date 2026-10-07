<?php
/**
 * Security helpers for AI Chatbot & Agents for Amazon Bedrock.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Security {

	// Object cache group of the counters kept there, when the site has a persistent cache.
	const CACHE_GROUP = 'aicfab_counters';

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
		foreach ( array( 'aws_access_key', 'aws_secret_key', 'aws_session_token', 'bedrock_api_key' ) as $key ) {
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
		// The counter belongs to the current window and dies with it. Keyed by client alone, every
		// request re-armed the expiry, so a steady client never saw its count reset.
		$slot = (int) floor( time() / $window );
		$key  = 'aicfab_rl_' . md5( sanitize_key( $bucket ) . '|' . $window . '|' . $slot . '|' . self::client_identifier() );
		// With a persistent object cache the count is one atomic increment, so requests made at
		// the same moment cannot all slip under the limit.
		$atomic = self::increment( $key, $window );
		if ( null !== $atomic ) {
			return $atomic <= $limit;
		}
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * How much of a daily allowance this visitor has used today, counted per client as the
	 * rate limits are: by account when signed in, by a salted hash of the address otherwise.
	 *
	 * @param string $bucket Allowance name.
	 * @return int
	 */
	public static function daily_spent( $bucket ) {
		$stored = max( 0, (int) get_transient( self::daily_key( $bucket ) ) );
		// spend_daily() falls back to the transient when the cache cannot increment, so with a
		// persistent cache both are read; reading the cache alone lost what the fallback counted.
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return max( $stored, (int) wp_cache_get( self::daily_key( $bucket ), self::CACHE_GROUP ) );
		}
		return $stored;
	}

	/**
	 * Add to what this visitor has used of a daily allowance.
	 *
	 * @param string $bucket Allowance name.
	 * @param int    $amount Amount used.
	 */
	public static function spend_daily( $bucket, $amount ) {
		$amount = (int) $amount;
		if ( $amount <= 0 ) {
			return;
		}
		if ( null !== self::increment( self::daily_key( $bucket ), DAY_IN_SECONDS, $amount ) ) {
			return;
		}
		set_transient( self::daily_key( $bucket ), self::daily_spent( $bucket ) + $amount, DAY_IN_SECONDS );
	}

	/**
	 * Add to a counter atomically, when the site has a persistent object cache such as Redis.
	 *
	 * @param string $key    Counter.
	 * @param int    $ttl    Seconds it lives.
	 * @param int    $amount Amount to add.
	 * @return int|null The new count, or null without such a cache.
	 */
	private static function increment( $key, $ttl, $amount = 1 ) {
		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return null;
		}
		wp_cache_add( $key, 0, self::CACHE_GROUP, $ttl );
		$count = wp_cache_incr( $key, $amount, self::CACHE_GROUP );
		return false === $count ? null : (int) $count;
	}

	/**
	 * Transient for a visitor's daily allowance. The day is part of the key, so the count
	 * starts again at midnight UTC rather than a day after the first use.
	 *
	 * @param string $bucket Allowance name.
	 * @return string
	 */
	private static function daily_key( $bucket ) {
		return 'aicfab_day_' . md5( sanitize_key( $bucket ) . '|' . gmdate( 'Ymd' ) . '|' . self::client_identifier() );
	}

	/**
	 * Take a lock that only one request at a time can hold, for background work.
	 *
	 * The add_option() function is not one: it reads, then writes, and two requests between both get
	 * in. A row inserted with INSERT IGNORE is, as only one insert of a name can succeed. A lock
	 * whose holder died is taken over once it expires, again by one request only, and the value
	 * carries a token so that a run releases only its own lock.
	 *
	 * @param string $name Option name of the lock.
	 * @param int    $ttl  Seconds until it expires.
	 * @return string The holder's value, to release it with; empty when it is held.
	 */
	public static function acquire_lock( $name, $ttl ) {
		global $wpdb;
		$value = ( time() + max( 1, (int) $ttl ) ) . '|' . ( function_exists( 'wp_generate_password' ) ? wp_generate_password( 16, false, false ) : bin2hex( random_bytes( 8 ) ) );
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			if ( add_option( $name, $value, '', false ) ) {
				return $value;
			}
			if ( (int) get_option( $name, 0 ) > time() ) {
				return '';
			}
			update_option( $name, $value, false );
			return $value;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- an atomic insert is the point; options are never cached for these.
		$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
		if ( 1 !== (int) $taken ) {
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			// Held, or taken over just now by another request.
			$taken = (int) $held > time() ? 0 : $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $held ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return 1 === (int) $taken ? $value : '';
	}

	/**
	 * Release a lock taken with acquire_lock(), if it is still this holder's.
	 *
	 * @param string $name  Option name of the lock.
	 * @param string $value What acquire_lock() returned.
	 */
	public static function release_lock( $name, $value ) {
		global $wpdb;
		if ( '' === (string) $value ) {
			return;
		}
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			if ( (string) get_option( $name, '' ) === (string) $value ) {
				delete_option( $name );
			}
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
		}
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

	/**
	 * Cut a UTF-8 string to at most $bytes bytes without splitting a character.
	 *
	 * @param string $value UTF-8 text.
	 * @param int    $bytes Maximum length in bytes.
	 * @return string
	 */
	public static function truncate_bytes( $value, $bytes ) {
		$value = (string) $value;
		$bytes = max( 0, (int) $bytes );
		if ( strlen( $value ) <= $bytes ) {
			return $value;
		}
		$cut = substr( $value, 0, $bytes );
		// Drop a trailing partial sequence: continuation bytes, then the lead byte they belong to.
		$end = strlen( $cut );
		$i   = $end;
		while ( $i > 0 && ( ord( $cut[ $i - 1 ] ) & 0xC0 ) === 0x80 ) {
			--$i;
		}
		if ( $i > 0 ) {
			$lead   = ord( $cut[ $i - 1 ] );
			$needed = $lead >= 0xF0 ? 4 : ( $lead >= 0xE0 ? 3 : ( $lead >= 0xC0 ? 2 : 1 ) );
			if ( $end - ( $i - 1 ) < $needed ) {
				$cut = substr( $cut, 0, $i - 1 );
			}
		}
		return $cut;
	}

	private static function encryption_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|ai-chat-for-amazon-bedrock|credentials', true );
	}

	private static function client_identifier() {
		if ( is_user_logged_in() ) {
			return 'user:' . get_current_user_id();
		}
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		/**
		 * The visitor's IP address, used only to rate limit guests. It is hashed and never stored.
		 *
		 * Behind a load balancer or CDN every guest arrives from the proxy's address and they
		 * all share one limit. A site behind a proxy it controls can return the address that
		 * proxy reports, such as CloudFront-Viewer-Address. Never return a header a visitor
		 * can set directly, or anyone can escape the limit by sending a new value each time.
		 *
		 * @param string $address REMOTE_ADDR.
		 */
		$address = (string) apply_filters( 'ai_chat_bedrock_client_ip', $address );
		return 'guest:' . hash_hmac( 'sha256', $address, wp_salt( 'nonce' ) );
	}
}
