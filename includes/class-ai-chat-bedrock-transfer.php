<?php
/**
 * Move a configuration between sites.
 *
 * Setting this plugin up means filling in around thirty five fields, several profiles and a
 * tool policy. Doing that twice, once on staging and once in production, is tedious and easy
 * to get subtly wrong.
 *
 * What is deliberately never exported:
 *
 * - AWS credentials. They are encrypted with a key derived from this site's salts, so the
 *   ciphertext is useless elsewhere, and a configuration file that carries AWS credentials
 *   is a file nobody should be emailing around.
 * - MCP bearer tokens, for the same reasons.
 * - OAuth clients, grants and revocations. Those hold client secrets and token hashes and
 *   belong to one site's connections.
 * - Conversations, usage counters and the tool log. Those are records, not configuration,
 *   and the first contains what visitors typed.
 *
 * The list of what does travel is an allowlist rather than a set of exclusions, so a new
 * option is left behind until somebody decides it should travel.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Transfer {

	/**
	 * Format version, so an import can refuse a file it does not understand.
	 */
	const FORMAT = 1;

	/**
	 * Settings keys that hold credentials and never travel.
	 *
	 * @return array
	 */
	public static function credential_keys() {
		return array( 'aws_access_key', 'aws_secret_key', 'aws_session_token' );
	}

	/**
	 * Options that may travel, and how each is written on import.
	 *
	 * @return array Map of option name to handler key.
	 */
	public static function options() {
		return array(
			'ai_chat_bedrock_settings'           => 'settings',
			'ai_chat_bedrock_profiles'           => 'profiles',
			'ai_chat_bedrock_role_limits'        => 'role_limits',
			'ai_chat_bedrock_mcp_servers'        => 'mcp_servers',
			'ai_chat_bedrock_enable_mcp'         => 'flag',
			'ai_chat_bedrock_mcp_public_access'  => 'flag',
			'ai_chat_bedrock_mcp_log_enabled'    => 'flag',
			'ai_chat_bedrock_mcp_capability'     => 'text',
			'ai_chat_bedrock_mcp_max_rounds'     => 'number',
			'ai_chat_bedrock_mcp_tool_policy'    => 'policy',
			'ai_chat_bedrock_site_abilities'     => 'flag',
			'ai_chat_bedrock_log_conversations'  => 'flag',
			'ai_chat_bedrock_log_retention_days' => 'number',
			'ai_chat_bedrock_index_embeddings'   => 'flag',
			'ai_chat_bedrock_oauth_enabled'      => 'flag',
		);
	}

	/**
	 * Build the configuration to export.
	 *
	 * @return array
	 */
	public static function export() {
		$payload = array(
			'format'   => self::FORMAT,
			'plugin'   => 'ai-chat-for-amazon-bedrock',
			'version'  => defined( 'AI_CHAT_BEDROCK_VERSION' ) ? AI_CHAT_BEDROCK_VERSION : '',
			'exported' => gmdate( 'c' ),
			'options'  => array(),
			'excluded' => array(),
		);

		foreach ( self::options() as $option => $handler ) {
			$value = get_option( $option, null );
			if ( null === $value ) {
				continue;
			}

			if ( 'settings' === $handler && is_array( $value ) ) {
				foreach ( self::credential_keys() as $secret ) {
					if ( isset( $value[ $secret ] ) && '' !== $value[ $secret ] ) {
						$payload['excluded'][] = $secret;
					}
					unset( $value[ $secret ] );
				}
			}

			if ( 'mcp_servers' === $handler && is_array( $value ) ) {
				foreach ( $value as $name => $server ) {
					if ( is_array( $server ) && isset( $server['auth']['token'] ) && '' !== $server['auth']['token'] ) {
						$payload['excluded'][]           = 'mcp token for ' . $name;
						$value[ $name ]['auth']['token'] = '';
					}
				}
			}

			$payload['options'][ $option ] = $value;
		}

		$payload['excluded'] = array_values( array_unique( $payload['excluded'] ) );
		return $payload;
	}

	/**
	 * Apply an exported configuration.
	 *
	 * Values go through the same validators the screens use, so an imported file cannot
	 * write anything the interface would refuse.
	 *
	 * @param string $json Exported JSON.
	 * @return array|WP_Error Summary of what was applied.
	 */
	public static function import( $json ) {
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'aicfab_import_invalid', __( 'That is not a configuration file this plugin wrote.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( 'ai-chat-for-amazon-bedrock' !== ( isset( $decoded['plugin'] ) ? $decoded['plugin'] : '' ) ) {
			return new WP_Error( 'aicfab_import_foreign', __( 'That file was written by something else.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( self::FORMAT !== (int) ( isset( $decoded['format'] ) ? $decoded['format'] : 0 ) ) {
			return new WP_Error( 'aicfab_import_format', __( 'That file uses a format this version does not understand.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( empty( $decoded['options'] ) || ! is_array( $decoded['options'] ) ) {
			return new WP_Error( 'aicfab_import_empty', __( 'The file contains no settings to apply.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$allowed = self::options();
		$applied = array();
		$skipped = array();

		foreach ( $decoded['options'] as $option => $value ) {
			$option = (string) $option;
			if ( ! isset( $allowed[ $option ] ) ) {
				// Anything not on the allowlist is ignored rather than written.
				$skipped[] = $option;
				continue;
			}
			if ( self::apply( $allowed[ $option ], $option, $value ) ) {
				$applied[] = $option;
			} else {
				$skipped[] = $option;
			}
		}

		if ( class_exists( 'AI_Chat_Bedrock_Setup_Steps' ) ) {
			AI_Chat_Bedrock_Setup_Steps::flush();
		}

		return array(
			'applied' => $applied,
			'skipped' => $skipped,
		);
	}

	/**
	 * Write one option through the right validator.
	 *
	 * @param string $handler Handler key.
	 * @param string $option  Option name.
	 * @param mixed  $value   Value from the file.
	 * @return bool Whether it was written.
	 */
	private static function apply( $handler, $option, $value ) {
		switch ( $handler ) {
			case 'settings':
				if ( ! is_array( $value ) ) {
					return false;
				}
				// Credentials already configured here are kept: the file never carries any.
				$current = get_option( $option, array() );
				$current = is_array( $current ) ? $current : array();
				foreach ( self::credential_keys() as $secret ) {
					unset( $value[ $secret ] );
					if ( isset( $current[ $secret ] ) ) {
						$value[ $secret ] = $current[ $secret ];
					}
				}
				// validate_settings() only reads _aicfab_fields, so the field registry is not
				// needed. Calling register_settings() here worked in an admin request and
				// crashed anywhere else, which is a dependency not worth having.
				$admin = new AI_Chat_Bedrock_Admin( 'ai-chat-for-amazon-bedrock', defined( 'AI_CHAT_BEDROCK_VERSION' ) ? AI_CHAT_BEDROCK_VERSION : '' );
				// Present every field so the validator treats this as a full submission.
				$value['_aicfab_fields'] = array_keys( $value );
				update_option( $option, $admin->validate_settings( $value ) );
				return true;

			case 'profiles':
				if ( ! is_array( $value ) ) {
					return false;
				}
				foreach ( $value as $key => $profile ) {
					if ( is_array( $profile ) ) {
						AI_Chat_Bedrock_Profiles::save( (string) $key, $profile );
					}
				}
				return true;

			case 'role_limits':
				if ( ! is_array( $value ) ) {
					return false;
				}
				AI_Chat_Bedrock_Rate_Limits::save( $value );
				return true;

			case 'mcp_servers':
				if ( ! is_array( $value ) ) {
					return false;
				}
				$clean = array();
				foreach ( $value as $name => $server ) {
					$name = sanitize_key( (string) $name );
					if ( '' === $name || ! is_array( $server ) || empty( $server['url'] ) ) {
						continue;
					}
					if ( ! AI_Chat_Bedrock_Security::is_safe_mcp_url( (string) $server['url'] ) ) {
						continue;
					}
					// A file may omit the auth block entirely, so resolve it once rather than
					// reaching into it repeatedly.
					$auth = ( isset( $server['auth'] ) && is_array( $server['auth'] ) ) ? $server['auth'] : array();
					$type = isset( $auth['type'] ) ? (string) $auth['type'] : 'none';

					$clean[ $name ] = array(
						'url'  => esc_url_raw( (string) $server['url'] ),
						'auth' => array(
							'type'    => in_array( $type, array( 'none', 'bearer', 'sigv4' ), true ) ? $type : 'none',
							// A token in the file is never written: it would be ciphertext
							// from another site's salts and useless here.
							'token'   => '',
							'service' => isset( $auth['service'] ) ? sanitize_text_field( (string) $auth['service'] ) : '',
							'region'  => isset( $auth['region'] ) ? sanitize_text_field( (string) $auth['region'] ) : '',
						),
					);
				}
				update_option( $option, $clean );
				return true;

			case 'policy':
				if ( ! is_array( $value ) ) {
					return false;
				}
				$policy = array();
				foreach ( $value as $tool => $state ) {
					$tool = sanitize_text_field( (string) $tool );
					if ( '' !== $tool ) {
						$policy[ $tool ] = 'allow' === $state ? 'allow' : 'deny';
					}
				}
				update_option( $option, $policy );
				return true;

			case 'flag':
				update_option( $option, empty( $value ) ? 0 : 1 );
				return true;

			case 'number':
				update_option( $option, absint( $value ) );
				return true;

			case 'text':
				update_option( $option, sanitize_text_field( (string) $value ) );
				return true;
		}

		return false;
	}
}
