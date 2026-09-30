<?php
/**
 * The chat's own words in the visitor's language, through Polylang or WPML.
 *
 * The title, welcome message and suggested questions are typed once in the settings, so a
 * multilingual site showed them in that one language on every edition. They are registered
 * as strings with the multilingual plugin, translated in its string translation screen, and
 * shown translated. Without a translation, or without either plugin, the text is unchanged.
 *
 * The plugin's own strings come from language packs, or from the few locales bundled here.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Translation {

	/**
	 * The group the strings are listed under in the multilingual plugin.
	 */
	const CONTEXT = 'AI Chat for Amazon Bedrock';

	/**
	 * Point WordPress at the translations bundled in languages/ when there is no language pack.
	 *
	 * The plugin has no translations on translate.wordpress.org yet, so the chat's buttons and notices
	 * read in English on a Chinese or Japanese page. WordPress 6.8 and later look in the plugin's
	 * Domain Path on their own; this does the same on 6.6 and 6.7. A language pack, once there is
	 * one, still wins: WordPress only reaches this with no path when it found nothing in wp-content.
	 *
	 * @param string|false $path   Languages directory WordPress found, or false.
	 * @param string       $domain Text domain.
	 * @param string       $locale Locale.
	 * @return string|false
	 */
	public static function bundled_languages( $path, $domain, $locale ) {
		if ( false !== $path || 'ai-chat-for-amazon-bedrock' !== $domain || ! preg_match( '/^[A-Za-z]{2,3}(_[A-Za-z0-9]+)*$/', (string) $locale ) ) {
			return $path;
		}
		$dir = AI_CHAT_BEDROCK_PLUGIN_DIR . 'languages/';
		foreach ( array( '.l10n.php', '.mo' ) as $suffix ) {
			if ( is_readable( $dir . $domain . '-' . $locale . $suffix ) ) {
				return $dir;
			}
		}
		return $path;
	}

	/**
	 * Register the settings' and every profile's chat text for translation.
	 */
	public static function register() {
		if ( ! function_exists( 'pll_register_string' ) && ! self::has_wpml() ) {
			return;
		}

		$settings = get_option( 'ai_chat_bedrock_settings', array() );
		self::register_set( is_array( $settings ) ? $settings : array(), '' );
		foreach ( AI_Chat_Bedrock_Profiles::all() as $key => $profile ) {
			self::register_set( $profile, $key );
		}
	}

	/**
	 * Chat settings with the text a visitor reads translated into the current language.
	 *
	 * Only for display. Never save what this returns, or a translation replaces the original.
	 *
	 * @param array $options Chat settings of the profile shown.
	 * @return array
	 */
	public static function presentation( $options ) {
		$options = is_array( $options ) ? $options : array();
		if ( ! function_exists( 'pll__' ) && ! self::has_wpml() ) {
			return $options;
		}

		$profile = isset( $options['profile'] ) ? (string) $options['profile'] : '';
		foreach ( array( 'chat_title', 'welcome_message' ) as $field ) {
			if ( isset( $options[ $field ] ) && '' !== trim( (string) $options[ $field ] ) ) {
				$options[ $field ] = self::translate( (string) $options[ $field ], self::name( $field, $profile ) );
			}
		}
		if ( isset( $options['suggested_questions'] ) && '' !== trim( (string) $options['suggested_questions'] ) ) {
			$lines = array();
			foreach ( AI_Chat_Bedrock_Chat_Request::suggestions( $options ) as $question ) {
				$lines[] = self::translate( $question, self::name( 'suggested_question', $profile, $question ) );
			}
			$options['suggested_questions'] = implode( "\n", $lines );
		}
		return $options;
	}

	/**
	 * Register one set of chat text.
	 *
	 * @param array  $options Settings or a profile.
	 * @param string $profile Profile key, or an empty string for the main settings.
	 */
	private static function register_set( $options, $profile ) {
		foreach ( array( 'chat_title', 'welcome_message' ) as $field ) {
			if ( isset( $options[ $field ] ) && '' !== trim( (string) $options[ $field ] ) ) {
				self::add( self::name( $field, $profile ), (string) $options[ $field ], 'welcome_message' === $field );
			}
		}
		foreach ( AI_Chat_Bedrock_Chat_Request::suggestions( $options ) as $question ) {
			self::add( self::name( 'suggested_question', $profile, $question ), $question, false );
		}
	}

	/**
	 * Register one string.
	 *
	 * @param string $name      Name shown in the translation screen.
	 * @param string $text      Text.
	 * @param bool   $multiline Whether it is edited in a text area.
	 */
	private static function add( $name, $text, $multiline ) {
		if ( function_exists( 'pll_register_string' ) ) {
			pll_register_string( $name, $text, self::CONTEXT, $multiline );

			/*
			 * Polylang answers WPML's API too, and keeps what is registered through it in an
			 * option of its own. 1.51.0 registered through both, so every string was listed
			 * twice. Its copy is removed; this does nothing once it is gone.
			 */
			if ( function_exists( 'icl_unregister_string' ) ) {
				icl_unregister_string( self::CONTEXT, $name );
			}
		} elseif ( self::has_wpml() ) {
			do_action( 'wpml_register_single_string', self::CONTEXT, $name, $text ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API.
		}
	}

	/**
	 * Translate one string.
	 *
	 * @param string $text Text as saved in the settings.
	 * @param string $name Name it was registered under.
	 * @return string
	 */
	private static function translate( $text, $name ) {
		if ( function_exists( 'pll__' ) ) {
			return (string) pll__( $text );
		}
		return (string) apply_filters( 'wpml_translate_single_string', $text, self::CONTEXT, $name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API.
	}

	/**
	 * The name a string is registered under. WPML finds a translation by name, so each
	 * suggested question has its own.
	 *
	 * @param string $field   Setting.
	 * @param string $profile Profile key.
	 * @param string $text    Text, for a setting that holds a list.
	 * @return string
	 */
	private static function name( $field, $profile, $text = '' ) {
		$name = '' !== $profile ? $profile . ': ' . $field : $field;
		return '' !== $text ? $name . ' ' . substr( md5( $text ), 0, 8 ) : $name;
	}

	/**
	 * Whether WPML's string translation is available. Polylang also answers WPML's API, so
	 * it is used directly where it is active.
	 *
	 * @return bool
	 */
	private static function has_wpml() {
		return ! function_exists( 'pll_register_string' ) && function_exists( 'has_action' ) && has_action( 'wpml_register_single_string' );
	}
}
