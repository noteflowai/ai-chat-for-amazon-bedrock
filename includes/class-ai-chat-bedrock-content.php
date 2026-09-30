<?php
/**
 * The text of a post as a visitor who is not signed in would read it.
 *
 * Everything this plugin hands to a model or to a vector index comes from here. Stripping the
 * tags from post_content is not enough: membership and visibility plugins (Block Visibility,
 * Paid Memberships Pro, MemberPress, Restrict Content and others) hide sections while the
 * page renders, so the stored markup still holds text a guest never sees. Indexing that
 * markup put members-only paragraphs into the embeddings and into the reference material a
 * guest's question was answered from. The post is rendered through the_content with nobody
 * signed in, and only what that produces is used.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Content {

	/**
	 * Rendered text is cached per post and modification time for the rest of the request, or
	 * longer where the site has a persistent object cache.
	 */
	const CACHE_GROUP = 'ai_chat_bedrock_text';

	/**
	 * Bump when the extraction changes, so cached text and stored hashes are rebuilt.
	 */
	const VERSION = 1;

	/**
	 * Whether a post is being rendered, so a filter that asks for text again does not recurse.
	 *
	 * @var bool
	 */
	private static $rendering = false;

	/**
	 * Whether a post is published and readable by anyone.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	public static function is_public( $post ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}
		$public = true;
		if ( function_exists( 'is_post_type_viewable' ) ) {
			$public = is_post_type_viewable( $post->post_type );
		}

		/**
		 * Whether a published post may be used to answer questions.
		 *
		 * Return false for posts that are published but meant for a restricted audience as a
		 * whole, for example a members-only post type a membership plugin guards per request.
		 *
		 * @param bool    $public Whether the post is public.
		 * @param WP_Post $post   Post.
		 */
		return (bool) apply_filters( 'ai_chat_bedrock_is_public_post', $public, $post );
	}

	/**
	 * Plain text of a post as a guest sees it, title first. Paragraph breaks are kept so the
	 * text can be split into passages.
	 *
	 * @param WP_Post $post Post.
	 * @return string Empty for a post that is not public.
	 */
	public static function public_text( $post ) {
		if ( ! self::is_public( $post ) ) {
			return '';
		}

		$key    = $post->ID . ':' . md5( (string) $post->post_modified_gmt . '|' . self::VERSION );
		$cached = function_exists( 'wp_cache_get' ) ? wp_cache_get( $key, self::CACHE_GROUP ) : false;
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$body = self::to_text( self::render_as_guest( $post ) );
		$text = trim( get_the_title( $post ) . "\n\n" . $body );

		/**
		 * The text used for a post in semantic search, keyword passages and site abilities.
		 *
		 * It is already what a guest sees. Filter it to remove more, never to add text a guest
		 * cannot read.
		 *
		 * @param string  $text Plain text.
		 * @param WP_Post $post Post.
		 */
		$text = trim( (string) apply_filters( 'ai_chat_bedrock_indexable_text', $text, $post ) );

		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $key, $text, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}
		return $text;
	}

	/**
	 * The rendered HTML of a post with nobody signed in.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function render_as_guest( $post ) {
		if ( self::$rendering || ! function_exists( 'apply_filters' ) ) {
			return '';
		}

		$user_id  = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		self::$rendering = true;
		ob_start();
		try {
			if ( $user_id && function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( 0 );
			}
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- blocks and shortcodes read the global post while rendering; it is restored below.
			if ( function_exists( 'setup_postdata' ) ) {
				setup_postdata( $post );
			}
			$html = (string) apply_filters( 'the_content', (string) $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying a core filter, not declaring a hook.
		} finally {
			// A filter that echoes instead of returning must not reach the response.
			ob_end_clean();
			if ( $user_id && function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $user_id );
			}
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the value saved above.
			if ( $previous instanceof WP_Post && function_exists( 'setup_postdata' ) ) {
				setup_postdata( $previous );
			}
			self::$rendering = false;
		}
		return $html;
	}

	/**
	 * Plain text from HTML, one paragraph per block-level element.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function to_text( $html ) {
		$html = (string) $html;
		// Scripts, styles and forms are not reading text, and wp_strip_all_tags keeps a form's labels.
		$html = preg_replace( '#<(script|style|noscript|template|form|svg|iframe)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );
		$html = preg_replace( '#</?(p|div|section|article|aside|header|footer|main|h[1-6]|li|ul|ol|dl|dt|dd|tr|table|thead|tbody|blockquote|pre|figure|figcaption|details|summary|hr)\b[^>]*>#i', "\n\n", (string) $html );
		$text = wp_strip_all_tags( (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[ \t\x{00A0}\x{3000}]+/u', ' ', (string) $text );
		$text = preg_replace( '/ *\n */', "\n", (string) $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", (string) $text );
		return trim( (string) $text );
	}

	/**
	 * The text on one line, for an excerpt.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function flatten( $text ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Split text into passages of about $size characters on paragraph boundaries, each
	 * overlapping the previous one by up to $overlap characters, so a sentence that answers
	 * a question is not cut in two.
	 *
	 * @param string $text    Text with paragraphs separated by blank lines.
	 * @param int    $size    Target passage length in characters.
	 * @param int    $overlap Characters carried over from the previous passage.
	 * @return array List of passages.
	 */
	public static function chunks( $text, $size = 1200, $overlap = 150 ) {
		$size    = max( 200, (int) $size );
		$overlap = max( 0, min( (int) ( $size / 2 ), (int) $overlap ) );
		$parts   = preg_split( '/\n{2,}/', trim( (string) $text ) );
		$parts   = is_array( $parts ) ? array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) ) : array();

		// Paragraphs longer than a passage are cut at sentence ends, or hard when there are none.
		$pieces = array();
		foreach ( $parts as $part ) {
			if ( AI_Chat_Bedrock_Security::string_length( $part ) <= $size ) {
				$pieces[] = $part;
				continue;
			}
			$sentences = preg_split( '/(?<=[.!?。！？])\s*/u', $part, -1, PREG_SPLIT_NO_EMPTY );
			$buffer    = '';
			foreach ( is_array( $sentences ) ? $sentences : array( $part ) as $sentence ) {
				while ( AI_Chat_Bedrock_Security::string_length( $sentence ) > $size ) {
					if ( '' !== $buffer ) {
						$pieces[] = $buffer;
						$buffer   = '';
					}
					$pieces[] = AI_Chat_Bedrock_Security::string_substr( $sentence, 0, $size );
					$sentence = AI_Chat_Bedrock_Security::string_substr( $sentence, $size, AI_Chat_Bedrock_Security::string_length( $sentence ) );
				}
				$joined = '' === $buffer ? $sentence : $buffer . ' ' . $sentence;
				if ( AI_Chat_Bedrock_Security::string_length( $joined ) > $size ) {
					$pieces[] = $buffer;
					$buffer   = $sentence;
				} else {
					$buffer = $joined;
				}
			}
			if ( '' !== trim( $buffer ) ) {
				$pieces[] = $buffer;
			}
		}

		$chunks  = array();
		$current = '';
		foreach ( $pieces as $piece ) {
			$joined = '' === $current ? $piece : $current . "\n\n" . $piece;
			if ( '' !== $current && AI_Chat_Bedrock_Security::string_length( $joined ) > $size ) {
				$chunks[] = $current;
				$tail     = $overlap > 0 ? self::tail( $current, $overlap ) : '';
				$current  = '' !== $tail ? $tail . "\n\n" . $piece : $piece;
				continue;
			}
			$current = $joined;
		}
		if ( '' !== trim( $current ) ) {
			$chunks[] = $current;
		}
		return $chunks;
	}

	/**
	 * The passage of a text that shares the most words with a question.
	 *
	 * Keyword search finds the right post but used to quote its first characters, which on a
	 * long page is rarely where the answer is.
	 *
	 * @param string $text  Text.
	 * @param string $query Question.
	 * @param int    $size  Passage length.
	 * @return string
	 */
	public static function best_passage( $text, $query, $size = 1200 ) {
		$chunks = self::chunks( $text, $size, 0 );
		if ( count( $chunks ) < 2 ) {
			return isset( $chunks[0] ) ? self::flatten( $chunks[0] ) : '';
		}

		$lower = function ( $value ) {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
		};
		$terms = preg_split( '/[^\p{L}\p{N}]+/u', $lower( $query ), -1, PREG_SPLIT_NO_EMPTY );
		$terms = is_array( $terms ) ? $terms : array();
		// Chinese and Japanese are not written with spaces, so their words are matched as pairs of characters.
		$grams = array();
		foreach ( $terms as $term ) {
			if ( preg_match( '/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $term ) ) {
				$length = AI_Chat_Bedrock_Security::string_length( $term );
				for ( $i = 0; $i < $length - 1; $i++ ) {
					$grams[] = AI_Chat_Bedrock_Security::string_substr( $term, $i, 2 );
				}
			} elseif ( AI_Chat_Bedrock_Security::string_length( $term ) > 2 ) {
				$grams[] = $term;
			}
		}
		$grams = array_values( array_unique( $grams ) );
		if ( empty( $grams ) ) {
			return self::flatten( $chunks[0] );
		}

		$best       = 0;
		$best_score = -1;
		foreach ( $chunks as $index => $chunk ) {
			$haystack = $lower( $chunk );
			$score    = 0;
			foreach ( $grams as $gram ) {
				$score += min( 3, substr_count( $haystack, $gram ) );
			}
			if ( $score > $best_score ) {
				$best       = $index;
				$best_score = $score;
			}
		}
		// The title leads the first passage; quote it with the passage found elsewhere.
		$passage = $chunks[ $best ];
		if ( $best > 0 ) {
			$title   = strtok( (string) $text, "\n" );
			$passage = trim( (string) $title ) . "\n" . $passage;
		}
		return self::flatten( $passage );
	}

	/**
	 * The language of a post, from Polylang or WPML, or an empty string.
	 *
	 * @param WP_Post|int $post Post.
	 * @return string Language slug such as en or zh.
	 */
	public static function language( $post ) {
		$post_id = $post instanceof WP_Post ? $post->ID : absint( $post );
		if ( function_exists( 'pll_get_post_language' ) ) {
			return sanitize_key( (string) pll_get_post_language( $post_id, 'slug' ) );
		}
		if ( function_exists( 'has_filter' ) && has_filter( 'wpml_post_language_details' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API.
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				return sanitize_key( (string) $details['language_code'] );
			}
		}
		return '';
	}

	/**
	 * The language the visitor is reading in, from Polylang or WPML, or an empty string.
	 *
	 * @return string
	 */
	public static function current_language() {
		if ( function_exists( 'pll_current_language' ) ) {
			return sanitize_key( (string) pll_current_language( 'slug' ) );
		}
		if ( function_exists( 'has_filter' ) && has_filter( 'wpml_current_language' ) ) {
			return sanitize_key( (string) apply_filters( 'wpml_current_language', null ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API.
		}
		return '';
	}

	/**
	 * A language slug a visitor's browser sent, if the site serves that language.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Language slug, or an empty string for any language.
	 */
	public static function request_language( $value ) {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		$languages = array();
		if ( function_exists( 'pll_languages_list' ) ) {
			$languages = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		} elseif ( function_exists( 'has_filter' ) && has_filter( 'wpml_active_languages' ) ) {
			$active    = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API.
			$languages = is_array( $active ) ? array_keys( $active ) : array();
		}
		return in_array( $value, array_map( 'sanitize_key', array_map( 'strval', $languages ) ), true ) ? $value : '';
	}

	/**
	 * Drop the cached text of a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function flush( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( $post instanceof WP_Post && function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $post->ID . ':' . md5( (string) $post->post_modified_gmt . '|' . self::VERSION ), self::CACHE_GROUP );
		}
	}

	private static function tail( $text, $length ) {
		$tail = AI_Chat_Bedrock_Security::string_substr( (string) $text, -$length, $length );
		// Start the carried-over text at a word or sentence, not in the middle of one.
		if ( preg_match( '/^.*?(?:[.!?。！？]\s*|\s)(.+)$/su', $tail, $match ) && AI_Chat_Bedrock_Security::string_length( $match[1] ) > $length / 3 ) {
			$tail = $match[1];
		}
		return trim( $tail );
	}
}
