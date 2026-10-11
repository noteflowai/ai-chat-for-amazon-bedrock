<?php
/**
 * An llms.txt file for the site, written from the site description.
 *
 * The llms.txt format (https://llmstxt.org/) is a Markdown file at the site root that tells
 * AI tools what the site is and where its main content lives. This one lists what the site
 * policy lets agents see, grouped by the schema.org types of the site description, so it
 * never names a members-only post, a draft, a hidden product or a page the site keeps out
 * of search engines.
 *
 * It is served only when nothing else answers the address. A real llms.txt file at the site
 * root is served by the web server before WordPress runs. A page, post or rewrite rule at the
 * address resolves the request, so WordPress does not report it as not found. Every
 * template_redirect handler runs before this one, which answers from the template_include
 * filter, and any other handler can claim the address through ai_chat_bedrock_llms_txt_serve.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Llms_Txt {

	const CACHE_KEY    = 'ai_chat_bedrock_llms_txt';
	const CACHE_TTL    = 43200;
	const MAX_PER_TYPE = 50;

	/**
	 * Whether the site publishes the file.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		return is_array( $options ) && ! empty( $options['llms_txt'] );
	}

	/**
	 * The file's address.
	 *
	 * @return string
	 */
	public static function url() {
		return home_url( '/llms.txt' );
	}

	/**
	 * Whether a real llms.txt file is at the site root, which the web server serves instead.
	 *
	 * @return bool
	 */
	public static function file_exists() {
		return file_exists( ABSPATH . 'llms.txt' );
	}

	/**
	 * Whether this request is for the file.
	 *
	 * @return bool
	 */
	public static function is_request() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return '' !== $path && rtrim( '' !== $home ? $home : '/', '/' ) . '/llms.txt' === $path;
	}

	/**
	 * Answer a request for the file, from the template_include filter, so that every
	 * template_redirect handler has had its turn.
	 *
	 * @param string $template Template WordPress would load.
	 * @return string The template, unchanged, when the file is not served.
	 */
	public function serve( $template = '' ) {
		if ( ! self::enabled() || ! self::is_request() || self::file_exists() || ! is_404() ) {
			return $template;
		}

		/**
		 * Whether the plugin answers the llms.txt address. Return false from a plugin that
		 * serves its own llms.txt from template_include or later.
		 *
		 * @since 1.71.0
		 *
		 * @param bool $serve Whether to serve the file. True when nothing else answered.
		 */
		if ( ! apply_filters( 'ai_chat_bedrock_llms_txt_serve', true ) ) {
			return $template;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo self::text(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text; every value is stripped of tags and Markdown in line().
		exit;
	}

	/**
	 * The file, from the cache when it is current.
	 *
	 * @return string
	 */
	public static function text() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$text = self::build();
		set_transient( self::CACHE_KEY, $text, self::CACHE_TTL );
		return $text;
	}

	/**
	 * Forget the file, so the next request writes it again. Hooked to post and settings changes.
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Write the file.
	 *
	 * @return string
	 */
	public static function build() {
		$name        = self::line( get_bloginfo( 'name' ) );
		$description = self::line( get_bloginfo( 'description' ) );
		$languages   = AI_Chat_Bedrock_Ontology::languages();
		$out         = '# ' . ( '' !== $name ? $name : self::line( home_url( '/' ) ) ) . "\n";
		if ( '' !== $description ) {
			$out .= "\n> " . $description . "\n";
		}
		if ( count( $languages ) > 1 ) {
			$out .= "\n" . sprintf(
				/* translators: %s: language names, separated by commas. */
				__( 'Content is published in: %s.', 'ai-chat-for-amazon-bedrock' ),
				implode( ', ', array_map( array( __CLASS__, 'line' ), wp_list_pluck( $languages, 'name' ) ) )
			) . "\n";
		}

		$sections = array();
		foreach ( AI_Chat_Bedrock_Ontology::public_post_types() as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => self::MAX_PER_TYPE,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => true,
					'lang'           => '',
				)
			);
			foreach ( $posts as $post ) {
				$type = AI_Chat_Bedrock_Ontology::type_of( $post );
				if ( '' === $type || ! AI_Chat_Bedrock_Ontology::allows( $post, 'agent' ) ) {
					continue;
				}
				if ( isset( $sections[ $type ] ) && count( $sections[ $type ] ) >= self::MAX_PER_TYPE ) {
					continue;
				}
				$title = self::line( AI_Chat_Bedrock_Content::title( $post ) );
				$url   = str_replace( array( '(', ')', ' ' ), array( '%28', '%29', '%20' ), esc_url_raw( (string) get_permalink( $post ) ) );
				$item  = '- [' . ( '' !== $title ? $title : $url ) . '](' . $url . ')';
				if ( count( $languages ) > 1 ) {
					$language = AI_Chat_Bedrock_Content::language( $post );
					if ( '' !== $language ) {
						$item .= ': ' . self::line( AI_Chat_Bedrock_Content::language_name( $language ) );
					}
				}
				$sections[ $type ][] = $item;
			}
		}

		$types = AI_Chat_Bedrock_Ontology::types();
		foreach ( $sections as $type => $items ) {
			$label = isset( $types[ $type ]['label'] ) ? $types[ $type ]['label'] : $type;
			$out  .= "\n## " . self::line( $label ) . "\n\n" . implode( "\n", $items ) . "\n";
		}

		/**
		 * Filters the llms.txt text before it is cached and served.
		 *
		 * @since 1.71.0
		 *
		 * @param string $out The Markdown text.
		 */
		return (string) apply_filters( 'ai_chat_bedrock_llms_txt', $out );
	}

	/**
	 * One value on one line, without tags or the brackets that would end a Markdown link text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function line( $value ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' );
		$text = str_replace( array( '[', ']' ), array( '(', ')' ), $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
