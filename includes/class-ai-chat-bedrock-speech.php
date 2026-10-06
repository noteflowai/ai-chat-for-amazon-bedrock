<?php
/**
 * Reading aloud with Amazon Polly.
 *
 * Off by default, in two parts. Chat answers get a Listen button: the site signs every answer
 * it gives, and only signed text is read, so the endpoint is not a free text-to-speech service
 * for anyone who finds it. Posts get a Listen to this post button: only the text a signed-out
 * visitor can read is sent to Polly, so members-only content never is, and the audio is kept
 * in the uploads folder until the post changes.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Speech {

	const REST_ROUTE = '/speech';
	const DIRECTORY  = 'ai-chat-bedrock-speech';
	const ENGINES    = array( 'neural', 'generative' );

	/**
	 * Characters sent to Polly in one request. Polly takes 3,000; shorter parts start sooner.
	 */
	const MAX_PART = 1500;

	/**
	 * Characters of an answer that are read, and of a post unless filtered.
	 */
	const MAX_REPLY = 6000;
	const MAX_POST  = 30000;

	/**
	 * Longest answer text the endpoint accepts before it checks the signature.
	 */
	const MAX_TEXT = 20000;

	/**
	 * Parts a post may have: the most a filtered length can make, with room for short parts.
	 */
	const MAX_PARTS = 400;

	/**
	 * Seconds an answer's signature lasts: it works in the window it was given and the next.
	 */
	const TOKEN_WINDOW = 86400;

	const DEFAULT_DAILY_CHARACTERS = 100000;
	const MAX_DAILY_CHARACTERS     = 10000000;
	const RATE_LIMIT               = 30;

	/**
	 * One visitor may use at most this share of the site's daily characters, so no single
	 * visitor or script can use up the day's reading for everyone else.
	 */
	const VISITOR_SHARE = 4;

	/**
	 * Transient listing the voices that refused the generative engine in a Region.
	 */
	const FALLBACK = 'aicfab_speech_fallback';

	/**
	 * Polly language code and voice for each site language, most specific first.
	 */
	const VOICES = array(
		'en'    => array( 'en-US', 'Joanna' ),
		'en_gb' => array( 'en-GB', 'Amy' ),
		'en_au' => array( 'en-AU', 'Olivia' ),
		'en_in' => array( 'en-IN', 'Kajal' ),
		'zh'    => array( 'cmn-CN', 'Zhiyu' ),
		'zh_hk' => array( 'yue-CN', 'Hiujin' ),
		'ja'    => array( 'ja-JP', 'Kazuha' ),
		'ko'    => array( 'ko-KR', 'Seoyeon' ),
		'fr'    => array( 'fr-FR', 'Lea' ),
		'fr_ca' => array( 'fr-CA', 'Gabrielle' ),
		'de'    => array( 'de-DE', 'Vicki' ),
		'es'    => array( 'es-ES', 'Lucia' ),
		'es_mx' => array( 'es-MX', 'Mia' ),
		'es_us' => array( 'es-US', 'Lupe' ),
		'it'    => array( 'it-IT', 'Bianca' ),
		'pt'    => array( 'pt-PT', 'Ines' ),
		'pt_br' => array( 'pt-BR', 'Camila' ),
		'nl'    => array( 'nl-NL', 'Laura' ),
		'sv'    => array( 'sv-SE', 'Elin' ),
		'nb'    => array( 'nb-NO', 'Ida' ),
		'da'    => array( 'da-DK', 'Sofie' ),
		'fi'    => array( 'fi-FI', 'Suvi' ),
		'pl'    => array( 'pl-PL', 'Ola' ),
		'tr'    => array( 'tr-TR', 'Burcu' ),
		'hi'    => array( 'hi-IN', 'Kajal' ),
		'ar'    => array( 'ar-AE', 'Hala' ),
	);

	/**
	 * Whether chat answers have a Listen button.
	 *
	 * @param array|null $options Settings, or null for the saved ones.
	 * @return bool
	 */
	public static function replies_enabled( $options = null ) {
		$options = self::options( $options );
		return ! empty( $options['speech_replies'] );
	}

	/**
	 * Whether posts have a Listen to this post button.
	 *
	 * @param array|null $options Settings, or null for the saved ones.
	 * @return bool
	 */
	public static function posts_enabled( $options = null ) {
		$options = self::options( $options );
		return ! empty( $options['speech_posts'] );
	}

	/**
	 * The Polly engine: neural, or generative where the voice and Region offer it.
	 *
	 * @param array|null $options Settings, or null for the saved ones.
	 * @return string
	 */
	public static function engine( $options = null ) {
		$options = self::options( $options );
		$engine  = isset( $options['speech_engine'] ) ? (string) $options['speech_engine'] : '';
		return in_array( $engine, self::ENGINES, true ) ? $engine : 'neural';
	}

	/**
	 * Characters Polly may read in a day, across the site. Zero means no limit.
	 *
	 * @param array|null $options Settings, or null for the saved ones.
	 * @return int
	 */
	public static function daily_characters( $options = null ) {
		$options = self::options( $options );
		// Only an explicit 0 removes the limit; an emptied field keeps the default.
		$limit = isset( $options['speech_daily_chars'] ) && '' !== trim( (string) $options['speech_daily_chars'] ) ? absint( $options['speech_daily_chars'] ) : self::DEFAULT_DAILY_CHARACTERS;
		return min( self::MAX_DAILY_CHARACTERS, $limit );
	}

	/**
	 * Characters of new audio one visitor may have made in a day. Audio already saved costs
	 * nothing and does not count. Zero means no limit, as when the site sets none.
	 *
	 * @param array|null $options Settings, or null for the saved ones.
	 * @return int
	 */
	public static function visitor_characters( $options = null ) {
		$site    = self::daily_characters( $options );
		$visitor = min( $site, max( self::MAX_POST, intdiv( $site, self::VISITOR_SHARE ) ) );

		/**
		 * Characters of new audio one visitor may have made in a day. Administrators are not
		 * limited by it.
		 *
		 * @param int $visitor Characters; by default a quarter of the site's daily limit, and
		 *                     at least one post of the longest length read.
		 * @param int $site    The site's daily limit, 0 for none.
		 */
		return max( 0, (int) apply_filters( 'ai_chat_bedrock_speech_visitor_chars', $visitor, $site ) );
	}

	/**
	 * Whether only signed-in visitors may listen to posts.
	 *
	 * @param array|null $options Settings, or null for the saved ones.
	 * @return bool
	 */
	public static function posts_need_sign_in( $options = null ) {
		$options = self::options( $options );
		return ! empty( $options['speech_posts_signed_in'] );
	}

	/**
	 * Whether a request comes from a script or crawler rather than a browser.
	 *
	 * Only such a request is turned away, and only when it would make new audio: a crawler
	 * that followed the button would otherwise have every post read on the site's account.
	 * Something that pretends to be a browser is held by the daily allowance instead.
	 *
	 * @return bool
	 */
	public static function is_automated() {
		$agent     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$automated = '' === trim( $agent ) || 1 === preg_match( '/\bbot\b|bot\/|bot-|crawl|spider|slurp|scrap|headless|lighthouse|pagespeed|facebookexternalhit|curl\/|wget\/|python|java\/|go-http|okhttp|axios|node-fetch|libwww|httpclient/i', $agent );

		/**
		 * Whether a request to read a post aloud comes from a script or crawler.
		 *
		 * @param bool   $automated Whether it looks automated.
		 * @param string $agent     User agent.
		 */
		return (bool) apply_filters( 'ai_chat_bedrock_speech_is_automated', $automated, $agent );
	}

	/**
	 * Post types that offer the Listen to this post button.
	 *
	 * @return array
	 */
	public static function post_types() {
		/**
		 * Post types whose single view offers Listen to this post.
		 *
		 * @param array $types Post type names. Default: post.
		 */
		$types = (array) apply_filters( 'ai_chat_bedrock_speech_post_types', array( 'post' ) );
		return array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
	}

	/**
	 * The AWS Region Polly is called in. Defaults to the chat Region.
	 *
	 * @return string
	 */
	public static function region() {
		$options = self::options( null );
		$default = isset( $options['aws_region'] ) ? sanitize_key( $options['aws_region'] ) : 'us-east-1';

		/**
		 * The AWS Region for Amazon Polly, for a chat Region where Polly is not offered.
		 *
		 * @param string $region Region code. Default: the chat Region.
		 */
		$region = sanitize_key( (string) apply_filters( 'ai_chat_bedrock_speech_region', $default ) );
		return preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $region ) ? $region : $default;
	}

	/**
	 * The Polly language code and voice for a language.
	 *
	 * @param string $language Locale or language slug, such as en_GB, ja or zh-hk.
	 * @return array Language code and voice ID.
	 */
	public static function voice( $language ) {
		$key = strtolower( str_replace( '-', '_', (string) $language ) );
		if ( isset( self::VOICES[ $key ] ) ) {
			$voice = self::VOICES[ $key ];
		} else {
			$short = substr( $key, 0, 2 );
			$voice = isset( self::VOICES[ $short ] ) ? self::VOICES[ $short ] : self::VOICES['en'];
		}

		/**
		 * The Amazon Polly voice for a language.
		 *
		 * @param array  $voice    Polly language code and voice ID, such as array( 'en-US', 'Joanna' ).
		 * @param string $language Locale or language slug it was chosen for.
		 */
		$filtered = apply_filters( 'ai_chat_bedrock_speech_voice', $voice, $language );
		if ( is_array( $filtered ) && isset( $filtered[0], $filtered[1] ) && preg_match( '/^[a-z]{2,3}-[A-Z]{2}$/', (string) $filtered[0] ) && preg_match( '/^[A-Z][A-Za-z]{1,30}$/', (string) $filtered[1] ) ) {
			$voice = array( (string) $filtered[0], (string) $filtered[1] );
		}
		return $voice;
	}

	/**
	 * The language to read an answer in: Chinese, Japanese or Korean when it is written in
	 * them, otherwise the page's language, and English when that is one of those three.
	 *
	 * @param string $text Text to read.
	 * @param string $hint Language of the page, or empty for the site's.
	 * @return string
	 */
	public static function reply_language( $text, $hint = '' ) {
		$text = (string) $text;
		$hint = strtolower( str_replace( '-', '_', '' !== (string) $hint ? (string) $hint : self::site_locale() ) );
		$cjk  = (int) preg_match_all( '/[\p{Han}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text );
		// One Chinese or Japanese character carries about as much as a short word.
		if ( $cjk > 0 && $cjk * 3 >= (int) preg_match_all( '/[A-Za-z]/', $text ) ) {
			if ( preg_match( '/[\x{3040}-\x{30FF}]/u', $text ) ) {
				return 'ja';
			}
			if ( preg_match( '/[\x{AC00}-\x{D7AF}]/u', $text ) ) {
				return 'ko';
			}
			return in_array( $hint, array( 'zh_hk', 'zh_tw' ), true ) ? $hint : 'zh';
		}
		return in_array( substr( $hint, 0, 2 ), array( 'zh', 'ja', 'ko' ), true ) ? 'en' : $hint;
	}

	/**
	 * An answer as it should be heard: no code, link targets, or markdown marks.
	 *
	 * @param string $text Answer text, which may hold markdown.
	 * @return string
	 */
	public static function speakable( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/```.*?(?:```|$)/s', ' ', $text );
		$text = preg_replace( '/!?\[([^\]]*)\]\([^)]*\)/u', '$1', (string) $text );
		$text = preg_replace( '/`([^`]*)`/u', '$1', (string) $text );
		$text = preg_replace( '/^[ \t]{0,3}(?:#{1,6}|>)[ \t]*/mu', '', (string) $text );
		$text = preg_replace( '/^[ \t]*[-*+•][ \t]+/mu', '', (string) $text );
		$text = preg_replace( '/^[ \t]*\|?[ \t:|-]*-[ \t:|-]*$/mu', '', (string) $text );
		$text = preg_replace( '/(\*\*|__|~~)(.+?)\1/u', '$2', (string) $text );
		$text = str_replace( array( '|', '*', '`' ), ' ', (string) $text );
		return self::tidy( wp_strip_all_tags( (string) $text ) );
	}

	/**
	 * Text split into parts Polly accepts, at sentence ends where possible.
	 *
	 * @param string $text  Plain text.
	 * @param int    $limit Characters read in all; the rest is left out.
	 * @return array
	 */
	public static function parts( $text, $limit ) {
		$text  = self::tidy( $text );
		$limit = max( self::MAX_PART, (int) $limit );
		if ( '' === $text ) {
			return array();
		}
		if ( AI_Chat_Bedrock_Security::string_length( $text ) > $limit ) {
			$text = AI_Chat_Bedrock_Security::string_substr( $text, 0, $limit );
			// End on a sentence when one ends in the last part.
			if ( preg_match( '/^(.+[。！？.!?])/su', $text, $match ) && AI_Chat_Bedrock_Security::string_length( $match[1] ) > $limit - self::MAX_PART ) {
				$text = $match[1];
			}
		}

		$sentences = preg_split( '/(?<=[。！？；])|(?<=[.!?;])\s+|\n+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$parts     = array();
		$current   = '';
		$half      = (int) ( self::MAX_PART / 2 );
		foreach ( (array) $sentences as $sentence ) {
			$sentence = trim( (string) $sentence );
			// A run with no sentence end is cut at a space or comma, or else anywhere.
			while ( AI_Chat_Bedrock_Security::string_length( $sentence ) > self::MAX_PART ) {
				$window = AI_Chat_Bedrock_Security::string_substr( $sentence, 0, self::MAX_PART );
				$head   = preg_match( '/^(.{' . $half . ',}[\s,，、])/su', $window, $match ) ? $match[1] : $window;
				if ( '' !== $current ) {
					$parts[] = $current;
					$current = '';
				}
				$parts[]  = trim( $head );
				$sentence = trim( AI_Chat_Bedrock_Security::string_substr( $sentence, AI_Chat_Bedrock_Security::string_length( $head ), AI_Chat_Bedrock_Security::string_length( $sentence ) ) );
			}
			if ( '' === $sentence ) {
				continue;
			}
			if ( '' !== $current && AI_Chat_Bedrock_Security::string_length( $current ) + 1 + AI_Chat_Bedrock_Security::string_length( $sentence ) > self::MAX_PART ) {
				$parts[] = $current;
				$current = '';
			}
			$current = '' === $current ? $sentence : $current . ' ' . $sentence;
		}
		if ( '' !== $current ) {
			$parts[] = $current;
		}
		return $parts;
	}

	/**
	 * The signature of an answer given to the current visitor.
	 *
	 * @param string   $text    Answer text exactly as the visitor received it.
	 * @param int|null $user_id User, or null for the current one.
	 * @return string 32 hexadecimal characters.
	 */
	public static function token( $text, $user_id = null, $window = null ) {
		$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
		$window  = null === $window ? (int) floor( time() / self::TOKEN_WINDOW ) : (int) $window;
		return wp_hash( 'aicfab_speech|' . $user_id . '|' . $window . '|' . (string) $text );
	}

	/**
	 * Whether a signature was given for this text to the current visitor in the last day or so.
	 *
	 * @param string $text  Answer text.
	 * @param string $token Signature sent back.
	 * @return bool
	 */
	public static function valid_token( $text, $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return false;
		}
		$window = (int) floor( time() / self::TOKEN_WINDOW );
		return hash_equals( self::token( $text, null, $window ), $token ) || hash_equals( self::token( $text, null, $window - 1 ), $token );
	}

	/**
	 * Register the speech route.
	 */
	public function register_routes() {
		register_rest_route(
			AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'text'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'token' => array(
						'type'    => 'string',
						'default' => '',
					),
					'post'  => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'part'  => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
						'maximum' => self::MAX_PARTS,
					),
					'lang'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * A post may be heard by anyone who can read it; an answer by whoever may use the chat.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		$post = absint( $request->get_param( 'post' ) );
		if ( $post > 0 ? ! self::posts_enabled() : ! self::replies_enabled() ) {
			return new WP_Error( 'aicfab_speech_off', __( 'Reading aloud is off on this site.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		if ( 0 === $post && ! AI_Chat_Bedrock_Security::can_use_chat() ) {
			return new WP_Error( 'aicfab_forbidden', __( 'Please sign in to use the chat.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 401 ) );
		}
		if ( $post > 0 && self::posts_need_sign_in() && ! is_user_logged_in() ) {
			return new WP_Error( 'aicfab_speech_sign_in', __( 'Please sign in to listen to this post.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 401 ) );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'speech', self::RATE_LIMIT ) ) {
			return new WP_Error( 'aicfab_rate_limited', __( 'Too many requests. Please wait a moment.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * One part of the audio: a file address for a post, the audio itself for an answer.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_request( $request ) {
		$part   = absint( $request->get_param( 'part' ) );
		$post   = absint( $request->get_param( 'post' ) );
		$result = $post > 0
			? self::post_part( $post, $part )
			: self::reply_part( (string) $request->get_param( 'text' ), (string) $request->get_param( 'token' ), $part, (string) $request->get_param( 'lang' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = new WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * One part of a signed answer, as audio.
	 *
	 * @param string $text  Answer text.
	 * @param string $token Its signature.
	 * @param int    $part  Part number, from zero.
	 * @param string $lang  Language of the page.
	 * @return array|WP_Error
	 */
	public static function reply_part( $text, $token, $part, $lang = '' ) {
		if ( '' === trim( $text ) || AI_Chat_Bedrock_Security::string_length( $text ) > self::MAX_TEXT || ! self::valid_token( $text, $token ) ) {
			return new WP_Error( 'aicfab_speech_token', __( 'Only answers from this chat can be read aloud.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 403 ) );
		}
		$speakable = self::speakable( $text );
		$parts     = self::parts( $speakable, self::MAX_REPLY );
		if ( ! isset( $parts[ $part ] ) ) {
			return new WP_Error( 'aicfab_speech_part', __( 'There is nothing more to read.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}
		$audio = self::synthesize( $parts[ $part ], self::reply_language( $speakable, $lang ) );
		if ( is_wp_error( $audio ) ) {
			return $audio;
		}
		return array(
			'audio' => base64_encode( $audio ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- audio for the browser to play, not obfuscation.
			'type'  => 'audio/mpeg',
			'parts' => count( $parts ),
		);
	}

	/**
	 * One part of a post, as the address of a saved audio file.
	 *
	 * The audio is made the first time a part is asked for and kept until the post changes.
	 * Where files cannot be written, the audio is returned instead and not kept.
	 *
	 * @param int $post_id Post ID.
	 * @param int $part    Part number, from zero.
	 * @return array|WP_Error
	 */
	public static function post_part( $post_id, $part ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::post_types(), true ) || ! AI_Chat_Bedrock_Content::is_public( $post ) ) {
			// A post can stop being public without being saved, when a membership rule changes.
			if ( $post instanceof WP_Post ) {
				self::forget_post( $post->ID );
			}
			return new WP_Error( 'aicfab_speech_post', __( 'This post cannot be read aloud.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}

		/**
		 * Characters of a post that are read aloud. Polly is billed per character.
		 *
		 * @param int     $limit Characters. Default 30,000.
		 * @param WP_Post $post  Post.
		 */
		$limit    = (int) apply_filters( 'ai_chat_bedrock_speech_max_chars', self::MAX_POST, $post );
		$parts    = self::parts( AI_Chat_Bedrock_Content::public_text( $post ), max( self::MAX_PART, min( 200000, $limit ) ) );
		$language = AI_Chat_Bedrock_Content::language( $post );
		$language = '' !== $language ? $language : self::site_locale();
		// A post in Chinese on an English site without a language plugin is still read in Chinese.
		$language = isset( $parts[0] ) ? self::reply_language( $parts[0], $language ) : $language;
		if ( ! isset( $parts[ $part ] ) ) {
			return new WP_Error( 'aicfab_speech_part', __( 'There is nothing more to read.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}

		$voice = self::voice( $language );
		$hash  = substr( wp_hash( 'aicfab_speech_post|' . $post->ID . '|' . md5( implode( "\n", $parts ) ) . '|' . implode( '|', $voice ) . '|' . self::engine() ), 0, 16 );
		$name  = $post->ID . '-' . $hash . '-' . $part . '.mp3';
		$found = self::stored_url( $name );
		if ( '' !== $found ) {
			return array(
				'url'   => $found,
				'parts' => count( $parts ),
			);
		}

		if ( self::is_automated() ) {
			return new WP_Error( 'aicfab_speech_automated', __( 'Posts are read aloud for visitors listening in a browser.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 403 ) );
		}
		$audio = self::synthesize( $parts[ $part ], $language );
		if ( is_wp_error( $audio ) ) {
			return $audio;
		}
		// Audio of an earlier version is not needed again once a listener starts the new one.
		// Later parts leave it, so a listener already part way through can finish.
		if ( 0 === $part ) {
			self::forget_post( $post->ID, $post->ID . '-' . $hash . '-' );
		}
		$url = self::store( $name, $audio );
		if ( '' === $url ) {
			return array(
				'audio' => base64_encode( $audio ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- audio for the browser to play, not obfuscation.
				'type'  => 'audio/mpeg',
				'parts' => count( $parts ),
			);
		}
		return array(
			'url'   => $url,
			'parts' => count( $parts ),
		);
	}

	/**
	 * Audio for one part, within the daily limit, falling back from the generative engine to
	 * neural for a voice or Region that does not offer it.
	 *
	 * @param string $text     Part text.
	 * @param string $language Locale or language slug.
	 * @return string|WP_Error
	 */
	private static function synthesize( $text, $language ) {
		$cap = self::daily_characters();
		if ( $cap > 0 && class_exists( 'AI_Chat_Bedrock_Usage' ) ) {
			$today = AI_Chat_Bedrock_Usage::today_totals();
			if ( (int) $today['speech_characters'] + AI_Chat_Bedrock_Security::string_length( $text ) > $cap ) {
				return new WP_Error( 'aicfab_speech_limit', __( 'Reading aloud has reached today\'s limit. Please try again tomorrow.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
			}
		}
		$length    = AI_Chat_Bedrock_Security::string_length( $text );
		$allowance = current_user_can( 'manage_options' ) ? 0 : self::visitor_characters();
		if ( $allowance > 0 && AI_Chat_Bedrock_Security::daily_spent( 'speech' ) + $length > $allowance ) {
			return new WP_Error( 'aicfab_speech_visitor_limit', __( 'You have listened to a lot today. Please try again tomorrow.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}

		$voice    = self::voice( $language );
		$region   = self::region();
		$engine   = self::engine();
		$key      = $region . '|' . $voice[1];
		$fallback = get_transient( self::FALLBACK );
		$fallback = is_array( $fallback ) ? $fallback : array();
		if ( 'neural' !== $engine && isset( $fallback[ $key ] ) ) {
			$engine = 'neural';
		}

		$aws   = new AI_Chat_Bedrock_AWS( array( 'aws_region' => $region ) );
		$audio = $aws->synthesize_speech( $text, $voice[1], $voice[0], $engine );
		// Polly refuses an engine a voice lacks with a 400 that names the engine.
		if ( is_wp_error( $audio ) && 'neural' !== $engine && 400 === self::status( $audio ) && false !== stripos( $audio->get_error_message(), 'engine' ) ) {
			$fallback[ $key ] = true;
			set_transient( self::FALLBACK, array_slice( $fallback, -50, null, true ), WEEK_IN_SECONDS );
			$audio = $aws->synthesize_speech( $text, $voice[1], $voice[0], 'neural' );
		}
		if ( is_wp_error( $audio ) ) {
			// A refusal from AWS can name the account and role, so only an administrator sees it.
			// To the visitor any failure upstream is the site's, except being told to slow down.
			$status  = 429 === self::status( $audio ) ? 429 : 502;
			$message = current_user_can( 'manage_options' ) ? $audio->get_error_message() : __( 'The audio could not be made right now. Please try again later.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( $audio->get_error_code(), $message, array( 'status' => $status ) );
		}
		if ( $allowance > 0 ) {
			AI_Chat_Bedrock_Security::spend_daily( 'speech', $length );
		}
		return $audio;
	}

	/**
	 * Add the Listen to this post button above the post on its own page.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function add_player( $content ) {
		if ( ! self::posts_enabled() || ( self::posts_need_sign_in() && ! is_user_logged_in() ) || AI_Chat_Bedrock_Content::is_rendering() || is_feed() || doing_filter( 'get_the_excerpt' ) || ! is_singular( self::post_types() ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post || (int) get_queried_object_id() !== (int) $post->ID || ! AI_Chat_Bedrock_Content::is_public( $post ) ) {
			return $content;
		}
		$player = '<div class="aicfab-listen" data-post="' . esc_attr( $post->ID ) . '">'
			. '<button type="button" class="aicfab-listen-button" aria-pressed="false">' . esc_html__( 'Listen to this post', 'ai-chat-for-amazon-bedrock' ) . '</button>'
			. ' <span class="aicfab-listen-status" role="status"></span>'
			. '</div>';
		return $player . $content;
	}

	/**
	 * Register the player script, and load it on a post that offers Listen to this post.
	 */
	public static function enqueue_assets() {
		self::register_assets();
		if ( self::posts_enabled() && ( ! self::posts_need_sign_in() || is_user_logged_in() ) && is_singular( self::post_types() ) && AI_Chat_Bedrock_Content::is_public( get_queried_object() ) ) {
			wp_enqueue_script( 'ai-chat-bedrock-speech' );
			wp_enqueue_style( 'ai-chat-bedrock-speech' );
		}
	}

	/**
	 * Register the player, which the chat needs wherever it is shown, the admin Test Chat too.
	 */
	public static function register_assets() {
		if ( wp_script_is( 'ai-chat-bedrock-speech', 'registered' ) ) {
			return;
		}
		$base    = plugin_dir_url( __DIR__ ) . 'public/';
		$version = defined( 'AI_CHAT_BEDROCK_ASSET_VERSION' ) ? AI_CHAT_BEDROCK_ASSET_VERSION : ( defined( 'AI_CHAT_BEDROCK_VERSION' ) ? AI_CHAT_BEDROCK_VERSION : false );
		wp_register_script( 'ai-chat-bedrock-speech', $base . 'js/ai-chat-bedrock-speech.js', array(), $version, true );
		wp_register_style( 'ai-chat-bedrock-speech', $base . 'css/ai-chat-bedrock-speech.css', array(), $version );
		wp_localize_script(
			'ai-chat-bedrock-speech',
			'aicfabSpeech',
			array(
				// No nonce: posts are read as a guest, so a cached page keeps working, and the
				// chat sends its own.
				'url'  => rest_url( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . self::REST_ROUTE ),
				'i18n' => array(
					'listen'      => __( 'Listen', 'ai-chat-for-amazon-bedrock' ),
					'listen_post' => __( 'Listen to this post', 'ai-chat-for-amazon-bedrock' ),
					'stop'        => __( 'Stop', 'ai-chat-for-amazon-bedrock' ),
					'loading'     => __( 'Preparing audio…', 'ai-chat-for-amazon-bedrock' ),
					'playing'     => __( 'Playing', 'ai-chat-for-amazon-bedrock' ),
					'blocked'     => __( 'Press Listen again to start the audio.', 'ai-chat-for-amazon-bedrock' ),
					'error'       => __( 'The audio could not be played.', 'ai-chat-for-amazon-bedrock' ),
				),
			)
		);
	}

	/**
	 * Delete a post's audio when it is saved or deleted: it may no longer be public, and its
	 * text may have changed.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $keep    File name prefix to keep, or empty to delete all of the post's audio.
	 */
	public static function forget_post( $post_id, $keep = '' ) {
		$post_id = absint( $post_id );
		$folder  = self::folder();
		if ( $post_id < 1 || null === $folder ) {
			return;
		}
		foreach ( (array) glob( $folder['path'] . '/' . $post_id . '-*' ) as $file ) {
			if ( is_string( $file ) && ( '' === $keep || 0 !== strpos( basename( $file ), $keep ) ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Delete every saved post audio file and the folder.
	 */
	public static function forget_all() {
		$folder = self::folder();
		if ( null === $folder || ! is_dir( $folder['path'] ) ) {
			return;
		}
		foreach ( (array) glob( $folder['path'] . '/*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$filesystem = self::filesystem();
		if ( $filesystem ) {
			$filesystem->rmdir( $folder['path'] );
		}
	}

	/**
	 * Delete the saved post audio when Listen to this post is switched off or its engine
	 * changes, so nothing is kept that is no longer used.
	 *
	 * @param mixed $old_value Settings before the save.
	 * @param mixed $value     Settings after the save.
	 */
	public static function settings_updated( $old_value, $value ) {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$value     = is_array( $value ) ? $value : array();
		if ( self::posts_enabled( $old_value ) && ( ! self::posts_enabled( $value ) || self::engine( $old_value ) !== self::engine( $value ) ) ) {
			self::forget_all();
		}
		$old_region = isset( $old_value['aws_region'] ) ? (string) $old_value['aws_region'] : '';
		$new_region = isset( $value['aws_region'] ) ? (string) $value['aws_region'] : '';
		if ( $old_region !== $new_region || self::engine( $old_value ) !== self::engine( $value ) ) {
			delete_transient( self::FALLBACK );
		}
	}

	/**
	 * The address of a saved audio file, or empty when there is none.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function stored_url( $name ) {
		$folder = self::folder();
		return null !== $folder && is_file( $folder['path'] . '/' . $name ) ? $folder['url'] . '/' . $name : '';
	}

	/**
	 * Save audio under the uploads folder, writing to a temporary name first so a listener
	 * never gets half a file.
	 *
	 * @param string $name  File name.
	 * @param string $audio MP3 audio.
	 * @return string Address of the file, or empty when it could not be saved.
	 */
	private static function store( $name, $audio ) {
		$folder     = self::folder();
		$filesystem = self::filesystem();
		if ( null === $folder || ! $filesystem || ! wp_mkdir_p( $folder['path'] ) ) {
			return '';
		}
		if ( ! is_file( $folder['path'] . '/index.php' ) ) {
			$filesystem->put_contents( $folder['path'] . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}
		$final = $folder['path'] . '/' . $name;
		$temp  = $final . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( ! $filesystem->put_contents( $temp, $audio, FS_CHMOD_FILE ) ) {
			return '';
		}
		// Without overwriting: a listener may be playing the copy another request saved first.
		if ( ! $filesystem->move( $temp, $final, false ) ) {
			wp_delete_file( $temp );
			return is_file( $final ) ? $folder['url'] . '/' . $name : '';
		}
		return $folder['url'] . '/' . $name;
	}

	/**
	 * The folder for saved audio, and its address.
	 *
	 * @return array|null Path and url, or null when uploads are unavailable.
	 */
	private static function folder() {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return null;
		}
		return array(
			'path' => untrailingslashit( $uploads['basedir'] ) . '/' . self::DIRECTORY,
			'url'  => untrailingslashit( set_url_scheme( $uploads['baseurl'] ) ) . '/' . self::DIRECTORY,
		);
	}

	/**
	 * WordPress's filesystem, when it can write without asking for credentials.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			return null;
		}
		return $wp_filesystem;
	}

	private static function status( $error ) {
		$data = $error instanceof WP_Error ? $error->get_error_data() : null;
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	private static function tidy( $text ) {
		$text = preg_replace( '#\bhttps?://\S+#iu', ' ', (string) $text );
		$text = preg_replace( '/[ \t\x{00A0}\x{3000}]+/u', ' ', (string) $text );
		$text = preg_replace( '/ *\n */', "\n", (string) $text );
		$text = preg_replace( '/\n{2,}/', "\n", (string) $text );
		return trim( (string) $text );
	}

	private static function site_locale() {
		return function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	}

	private static function options( $options ) {
		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
		}
		return is_array( $options ) ? $options : array();
	}
}
