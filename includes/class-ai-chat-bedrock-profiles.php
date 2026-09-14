<?php
/**
 * Named chat profiles.
 *
 * A site can serve several chats from one plugin: a public support assistant, an
 * internal research assistant, a documentation helper. Each profile overrides the
 * model, prompt, presentation, guest access and grounding, and everything else
 * falls back to the main settings.
 *
 * Profile keys arrive from shortcodes, blocks and chat requests, so they are always
 * validated against the stored list before any override is applied.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Profiles {

	const OPTION       = 'ai_chat_bedrock_profiles';
	const MAX_PROFILES = 10;

	/**
	 * Stored profiles keyed by slug.
	 *
	 * @return array
	 */
	public static function all() {
		$profiles = get_option( self::OPTION, array() );
		if ( ! is_array( $profiles ) ) {
			return array();
		}

		$clean = array();
		foreach ( $profiles as $key => $profile ) {
			$key = self::sanitize_key( $key );
			if ( '' === $key || ! is_array( $profile ) ) {
				continue;
			}
			$clean[ $key ] = self::sanitize( $profile, $key );
			if ( count( $clean ) >= self::MAX_PROFILES ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Whether a profile key exists.
	 *
	 * @param string $key Profile key.
	 * @return bool
	 */
	public static function exists( $key ) {
		$key = self::sanitize_key( $key );
		return '' !== $key && array_key_exists( $key, self::all() );
	}

	/**
	 * Save or replace a profile.
	 *
	 * @param string $key     Profile key.
	 * @param array  $profile Profile values.
	 * @return string|WP_Error Stored key.
	 */
	public static function save( $key, $profile ) {
		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return new WP_Error( 'aicfab_invalid_profile_key', __( 'A profile needs a name using letters, numbers or dashes.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$profiles = self::all();
		if ( ! isset( $profiles[ $key ] ) && count( $profiles ) >= self::MAX_PROFILES ) {
			return new WP_Error( 'aicfab_too_many_profiles', __( 'The maximum number of chat profiles has been reached.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$profiles[ $key ] = self::sanitize( $profile, $key );
		update_option( self::OPTION, $profiles, false );
		return $key;
	}

	/**
	 * Delete a profile.
	 *
	 * @param string $key Profile key.
	 * @return bool
	 */
	public static function delete( $key ) {
		$key      = self::sanitize_key( $key );
		$profiles = self::all();
		if ( '' === $key || ! isset( $profiles[ $key ] ) ) {
			return false;
		}
		unset( $profiles[ $key ] );
		update_option( self::OPTION, $profiles, false );
		return true;
	}

	/**
	 * Resolve the effective options for a profile.
	 *
	 * @param string $key      Profile key, or an empty string for the site default.
	 * @param array  $settings Base plugin settings.
	 * @return array
	 */
	public static function resolve( $key, $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'ai_chat_bedrock_settings', array() );
			$settings = is_array( $settings ) ? $settings : array();
		}

		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return $settings;
		}

		$profiles = self::all();
		if ( ! isset( $profiles[ $key ] ) ) {
			return $settings;
		}
		$profile = $profiles[ $key ];

		$overrides = array();
		foreach ( array( 'model_id', 'system_prompt', 'chat_title', 'welcome_message', 'suggested_questions' ) as $field ) {
			if ( isset( $profile[ $field ] ) && '' !== $profile[ $field ] ) {
				$overrides[ $field ] = $profile[ $field ];
			}
		}
		foreach ( array( 'max_tokens', 'rate_limit_per_minute', 'context_results' ) as $field ) {
			if ( isset( $profile[ $field ] ) && $profile[ $field ] > 0 ) {
				$overrides[ $field ] = (int) $profile[ $field ];
			}
		}
		if ( isset( $profile['temperature'] ) && '' !== $profile['temperature'] ) {
			$overrides['temperature'] = (float) $profile['temperature'];
		}
		foreach ( array( 'allow_public_chat', 'enable_site_context' ) as $flag ) {
			if ( isset( $profile[ $flag ] ) && 'inherit' !== $profile[ $flag ] ) {
				$overrides[ $flag ] = ( 'on' === $profile[ $flag ] );
			}
		}

		$resolved            = array_merge( $settings, $overrides );
		$resolved['profile'] = $key;
		return $resolved;
	}

	/**
	 * Reduce resolved options to the fields the Bedrock client may override.
	 *
	 * @param array $options Resolved options.
	 * @return array
	 */
	public static function overrides_for_client( $options ) {
		$options   = is_array( $options ) ? $options : array();
		$overrides = array();

		if ( isset( $options['model_id'] ) && '' !== $options['model_id'] ) {
			$overrides['model_id'] = (string) $options['model_id'];
		}
		if ( isset( $options['max_tokens'] ) && (int) $options['max_tokens'] > 0 ) {
			$overrides['max_tokens'] = (int) $options['max_tokens'];
		}
		if ( isset( $options['temperature'] ) && '' !== $options['temperature'] ) {
			$overrides['temperature'] = (float) $options['temperature'];
		}
		return $overrides;
	}

	/**
	 * Normalize a profile key coming from untrusted input.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function sanitize_key( $key ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key || strlen( $key ) > 32 ) {
			return '';
		}
		return preg_match( '/^[a-z0-9_-]+$/', $key ) ? $key : '';
	}

	/**
	 * Selectable choices for admin dropdowns.
	 *
	 * @return array
	 */
	public static function choices() {
		$choices = array( '' => __( 'Site default settings', 'ai-chat-for-amazon-bedrock' ) );
		foreach ( self::all() as $key => $profile ) {
			$choices[ $key ] = $profile['label'] . ' (' . $key . ')';
		}
		return $choices;
	}

	private static function sanitize( $profile, $key ) {
		$flags = array();
		foreach ( array( 'allow_public_chat', 'enable_site_context' ) as $flag ) {
			$value          = isset( $profile[ $flag ] ) ? (string) $profile[ $flag ] : 'inherit';
			$flags[ $flag ] = in_array( $value, array( 'inherit', 'on', 'off' ), true ) ? $value : 'inherit';
		}

		$model = isset( $profile['model_id'] ) ? sanitize_text_field( (string) $profile['model_id'] ) : '';
		if ( '' !== $model && class_exists( 'AI_Chat_Bedrock_Models' ) && ! AI_Chat_Bedrock_Models::is_valid_id( $model ) ) {
			$model = '';
		}

		$label = isset( $profile['label'] ) ? sanitize_text_field( (string) $profile['label'] ) : '';
		$label = '' !== $label ? AI_Chat_Bedrock_Security::string_substr( $label, 0, 80 ) : $key;

		return array(
			'key'                   => $key,
			'label'                 => $label,
			'model_id'              => $model,
			'system_prompt'         => isset( $profile['system_prompt'] ) ? AI_Chat_Bedrock_Security::string_substr( sanitize_textarea_field( (string) $profile['system_prompt'] ), 0, 8000 ) : '',
			'chat_title'            => isset( $profile['chat_title'] ) ? AI_Chat_Bedrock_Security::string_substr( sanitize_text_field( (string) $profile['chat_title'] ), 0, 120 ) : '',
			'welcome_message'       => isset( $profile['welcome_message'] ) ? AI_Chat_Bedrock_Security::string_substr( sanitize_text_field( (string) $profile['welcome_message'] ), 0, 500 ) : '',
			'suggested_questions'   => isset( $profile['suggested_questions'] ) ? AI_Chat_Bedrock_Chat_Request::sanitize_suggestions( (string) $profile['suggested_questions'] ) : '',
			'max_tokens'            => isset( $profile['max_tokens'] ) ? min( 4000, absint( $profile['max_tokens'] ) ) : 0,
			'temperature'           => isset( $profile['temperature'] ) && '' !== $profile['temperature'] ? max( 0, min( 1, (float) $profile['temperature'] ) ) : '',
			'rate_limit_per_minute' => isset( $profile['rate_limit_per_minute'] ) ? min( 60, absint( $profile['rate_limit_per_minute'] ) ) : 0,
			'context_results'       => isset( $profile['context_results'] ) ? min( 8, absint( $profile['context_results'] ) ) : 0,
			'allow_public_chat'     => $flags['allow_public_chat'],
			'enable_site_context'   => $flags['enable_site_context'],
		);
	}
}
