<?php
/**
 * Amazon Bedrock Prompt Management integration.
 *
 * A team can keep the system prompt in AWS, versioned and reviewable, and point
 * every site at it instead of pasting the text into each installation. The prompt
 * text is fetched, cached briefly, and used in place of the local system prompt.
 *
 * The chat itself is unchanged: history, tools, grounding and streaming all still
 * apply, because only the system prompt comes from AWS.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Prompts {

	const CACHE_PREFIX = 'aicfab_prompt_';
	const CACHE_TTL    = 300;
	const MAX_CHARS    = 8000;

	/**
	 * Configured prompt identifier, or an empty string when unused.
	 *
	 * @param array $options Plugin options.
	 * @return string
	 */
	public static function identifier( $options = null ) {
		$options = self::options( $options );
		$id      = isset( $options['prompt_id'] ) ? trim( (string) $options['prompt_id'] ) : '';

		if ( '' === $id || ! preg_match( '#^[A-Za-z0-9:._/-]{1,2048}$#', $id ) ) {
			return '';
		}
		return $id;
	}

	/**
	 * Configured prompt version. An empty string means the draft.
	 *
	 * @param array $options Plugin options.
	 * @return string
	 */
	public static function version( $options = null ) {
		$options = self::options( $options );
		$version = isset( $options['prompt_version'] ) ? trim( (string) $options['prompt_version'] ) : '';

		if ( '' === $version || ! preg_match( '/^(?:DRAFT|[0-9]{1,10})$/', $version ) ) {
			return '';
		}
		return $version;
	}

	/**
	 * Whether a managed prompt should be used.
	 *
	 * @param array $options Plugin options.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		return '' !== self::identifier( $options );
	}

	/**
	 * Resolve the system prompt text from AWS.
	 *
	 * @param array $options Plugin options.
	 * @return string|WP_Error
	 */
	public static function text( $options = null ) {
		$options    = self::options( $options );
		$identifier = self::identifier( $options );
		if ( '' === $identifier ) {
			return new WP_Error( 'aicfab_no_prompt', __( 'No managed prompt is configured.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$version = self::version( $options );
		$key     = self::CACHE_PREFIX . md5( $identifier . '|' . $version );
		$cached  = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return self::render( $cached );
		}

		$aws    = new AI_Chat_Bedrock_AWS();
		$result = $aws->get_prompt( $identifier, $version );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$template = self::template_from( $result );
		if ( '' === $template ) {
			return new WP_Error( 'aicfab_prompt_empty', __( 'That prompt has no text template this plugin can use.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$ttl = (int) apply_filters( 'ai_chat_bedrock_prompt_cache_ttl', self::CACHE_TTL );
		set_transient( $key, $template, max( 30, $ttl ) );

		return self::render( $template );
	}

	/**
	 * The system prompt to use, falling back to the local setting on any problem.
	 *
	 * A prompt that cannot be fetched must not silently empty the system prompt, so
	 * the locally configured text stays in place and the failure is visible in the
	 * settings screen rather than in the answers.
	 *
	 * @param string $local   Locally configured system prompt.
	 * @param array  $options Plugin options.
	 * @return string
	 */
	public static function system_prompt( $local, $options = null ) {
		if ( ! self::enabled( $options ) ) {
			return (string) $local;
		}

		$text = self::text( $options );
		if ( is_wp_error( $text ) || '' === trim( (string) $text ) ) {
			return (string) $local;
		}
		return (string) $text;
	}

	/**
	 * Pull the text template out of a GetPrompt response.
	 *
	 * @param array $result Decoded API response.
	 * @return string
	 */
	public static function template_from( $result ) {
		if ( ! is_array( $result ) || empty( $result['variants'] ) || ! is_array( $result['variants'] ) ) {
			return '';
		}

		$default = isset( $result['defaultVariant'] ) ? (string) $result['defaultVariant'] : '';
		$chosen  = null;

		foreach ( $result['variants'] as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}
			if ( '' !== $default && isset( $variant['name'] ) && (string) $variant['name'] === $default ) {
				$chosen = $variant;
				break;
			}
			if ( null === $chosen ) {
				$chosen = $variant;
			}
		}

		$text = isset( $chosen['templateConfiguration']['text']['text'] ) ? (string) $chosen['templateConfiguration']['text']['text'] : '';
		return AI_Chat_Bedrock_Security::string_substr( trim( $text ), 0, self::MAX_CHARS );
	}

	/**
	 * Substitute the site variables this plugin can resolve.
	 *
	 * Anything else is left exactly as written, because guessing at a variable would
	 * change the meaning of a prompt the site owner reviewed in AWS.
	 *
	 * @param string $template Prompt template.
	 * @return string
	 */
	public static function render( $template ) {
		$replacements = apply_filters(
			'ai_chat_bedrock_prompt_variables',
			array(
				'site_name'        => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'site_description' => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
				'site_url'         => home_url( '/' ),
				'current_date'     => gmdate( 'Y-m-d' ),
			)
		);

		$text = (string) $template;
		foreach ( (array) $replacements as $name => $value ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $name ) ) {
				continue;
			}
			$text = str_replace( array( '{{' . $name . '}}', '{{ ' . $name . ' }}' ), (string) $value, $text );
		}

		return AI_Chat_Bedrock_Security::string_substr( trim( $text ), 0, self::MAX_CHARS );
	}

	/**
	 * Variables still unresolved in a template, for display in the settings screen.
	 *
	 * @param string $rendered Rendered prompt text.
	 * @return array
	 */
	public static function unresolved( $rendered ) {
		if ( ! preg_match_all( '/\{\{\s*([A-Za-z0-9_]{1,64})\s*\}\}/', (string) $rendered, $matches ) ) {
			return array();
		}
		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Drop the cached prompt text.
	 *
	 * @param array $options Plugin options.
	 */
	public static function flush( $options = null ) {
		$options    = self::options( $options );
		$identifier = self::identifier( $options );
		if ( '' === $identifier ) {
			return;
		}
		delete_transient( self::CACHE_PREFIX . md5( $identifier . '|' . self::version( $options ) ) );
	}

	private static function options( $options ) {
		if ( is_array( $options ) ) {
			return $options;
		}
		$stored = get_option( 'ai_chat_bedrock_settings', array() );
		return is_array( $stored ) ? $stored : array();
	}
}
