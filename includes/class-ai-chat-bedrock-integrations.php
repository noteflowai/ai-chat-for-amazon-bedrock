<?php
/**
 * Optional fixes for plugins this one is often installed next to.
 *
 * Every switch here is off until an administrator turns it on, and each only acts when the
 * plugin it adjusts is active:
 *
 *   - GitHub sign-in (FluentAuth) asks GitHub for the "user" scope, which also grants write
 *     access to the profile. Sign-in only reads it, so ask for read:user and user:email.
 *   - Social sign-up needs "Anyone can register", which also opens the core registration
 *     form. That form emails a password link, which fails on a site that sends no mail, and
 *     is where most spam accounts come from. It can send visitors to the login page, where
 *     the social buttons are, instead.
 *   - Polylang prints no x-default alternate, so search engines guess which edition to show
 *     a reader whose language the site does not have. One language can be named for that.
 *   - Yoast SEO credits an article to the account that published it. A site that publishes
 *     as an organization can credit the organization instead, under the name set in Yoast,
 *     which Polylang translates per language.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Integrations {

	/**
	 * Register the hooks for the switches that are on.
	 */
	public static function init() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();

		if ( ! empty( $options['github_read_scope'] ) ) {
			add_filter( 'wp_redirect', array( __CLASS__, 'github_scope' ) );
		}
		if ( ! empty( $options['social_only_registration'] ) ) {
			add_action( 'login_form_register', array( __CLASS__, 'redirect_registration' ) );
			add_filter( 'register', array( __CLASS__, 'hide_register_link' ) );
		}
		if ( ! empty( $options['hreflang_x_default'] ) ) {
			add_filter( 'pll_rel_hreflang_attributes', array( __CLASS__, 'hreflang_x_default' ) );
		}
		if ( ! empty( $options['organization_author'] ) ) {
			add_filter( 'wpseo_schema_article', array( __CLASS__, 'article_author' ), 10, 2 );
			add_filter( 'wpseo_schema_author', array( __CLASS__, 'drop_person' ), 10, 2 );
			add_filter( 'wpseo_meta_author', array( __CLASS__, 'meta_author' ), 10, 2 );
			add_filter( 'wpseo_enhanced_slack_data', array( __CLASS__, 'slack_author' ), 10, 2 );
		}
	}

	/**
	 * Which of the adjusted plugins are active.
	 *
	 * @return array Map of fluentauth, polylang and yoast to a boolean.
	 */
	public static function detected() {
		return array(
			'fluentauth' => defined( 'FLUENT_AUTH_VERSION' ) || defined( 'FLUENT_AUTH_PLUGIN_PATH' ),
			'polylang'   => function_exists( 'pll_languages_list' ),
			'yoast'      => defined( 'WPSEO_VERSION' ),
		);
	}

	/**
	 * Languages Polylang serves, as slug => name.
	 *
	 * @return array
	 */
	public static function languages() {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}
		$slugs = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		$names = (array) pll_languages_list( array( 'fields' => 'name' ) );
		$list  = array();
		foreach ( $slugs as $index => $slug ) {
			$list[ sanitize_key( (string) $slug ) ] = isset( $names[ $index ] ) ? (string) $names[ $index ] : (string) $slug;
		}
		return $list;
	}

	/**
	 * Narrow the GitHub authorization request to read-only scopes.
	 *
	 * @param string $location Redirect target.
	 * @return string
	 */
	public static function github_scope( $location ) {
		if ( ! is_string( $location ) || 0 !== strpos( $location, 'https://github.com/login/oauth/authorize?' ) ) {
			return $location;
		}
		return (string) preg_replace( '/([?&])scope=user(&|$)/', '$1scope=read:user%20user:email$2', $location );
	}

	/**
	 * Send the core registration form to the login page, while a social login plugin is active.
	 */
	public static function redirect_registration() {
		if ( ! self::social_login_active() ) {
			return;
		}
		wp_safe_redirect( wp_login_url() );
		exit;
	}

	/**
	 * Remove the "Register" link, while a social login plugin is active.
	 *
	 * @param string $link Link HTML.
	 * @return string
	 */
	public static function hide_register_link( $link ) {
		return self::social_login_active() ? '' : $link;
	}

	/**
	 * Add an x-default alternate pointing at the chosen language's edition.
	 *
	 * @param array $hreflangs Map of hreflang to URL.
	 * @return array
	 */
	public static function hreflang_x_default( $hreflangs ) {
		$hreflangs = is_array( $hreflangs ) ? $hreflangs : array();
		$options   = get_option( 'ai_chat_bedrock_settings', array() );
		$language  = is_array( $options ) && isset( $options['hreflang_x_default'] ) ? (string) $options['hreflang_x_default'] : '';
		if ( '' === $language || isset( $hreflangs['x-default'] ) ) {
			return $hreflangs;
		}
		// Polylang keys alternates by locale code, such as en or en-US, not by language slug.
		foreach ( $hreflangs as $code => $url ) {
			if ( $code === $language || 0 === strpos( strtolower( (string) $code ), $language . '-' ) ) {
				$hreflangs['x-default'] = $url;
				break;
			}
		}
		return $hreflangs;
	}

	/**
	 * Credit an article to the organization of the same schema graph.
	 *
	 * @param array  $data    Article piece.
	 * @param object $context Yoast meta tags context.
	 * @return array
	 */
	public static function article_author( $data, $context ) {
		if ( ! self::represents_organization( $context ) || ! is_array( $data ) ) {
			return $data;
		}
		$data['author'] = array(
			'@id'  => $context->site_url . '#organization',
			'name' => $context->company_name,
		);
		return $data;
	}

	/**
	 * Leave out the Person piece of the account that published the post.
	 *
	 * @param array|false $data    Person piece.
	 * @param object      $context Yoast meta tags context.
	 * @return array|false
	 */
	public static function drop_person( $data, $context = null ) {
		return self::represents_organization( $context ) ? false : $data;
	}

	/**
	 * The organization's name in the author meta tag.
	 *
	 * @param string $name         Author name.
	 * @param object $presentation Yoast indexable presentation.
	 * @return string
	 */
	public static function meta_author( $name, $presentation ) {
		$context = is_object( $presentation ) && isset( $presentation->context ) ? $presentation->context : null;
		return self::represents_organization( $context ) ? (string) $context->company_name : $name;
	}

	/**
	 * The organization's name in the "Written by" line of Slack link previews.
	 *
	 * @param array  $data         Label => value.
	 * @param object $presentation Yoast indexable presentation.
	 * @return array
	 */
	public static function slack_author( $data, $presentation ) {
		$context = is_object( $presentation ) && isset( $presentation->context ) ? $presentation->context : null;
		if ( ! is_array( $data ) || ! self::represents_organization( $context ) ) {
			return $data;
		}
		$written = __( 'Written by', 'wordpress-seo' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- the label is Yoast's own, translated by Yoast.
		if ( isset( $data[ $written ] ) ) {
			$data[ $written ] = $context->company_name;
		}
		return $data;
	}

	private static function represents_organization( $context ) {
		return is_object( $context )
			&& isset( $context->site_represents, $context->company_name, $context->site_url )
			&& 'company' === $context->site_represents
			&& '' !== trim( (string) $context->company_name );
	}

	private static function social_login_active() {
		$detected = self::detected();

		/**
		 * Whether a plugin offering sign-up through a social account is active, so the core
		 * registration form can be retired without leaving visitors unable to sign up.
		 *
		 * @param bool $active Whether FluentAuth is active.
		 */
		return (bool) apply_filters( 'ai_chat_bedrock_social_login_active', $detected['fluentauth'] );
	}
}
