<?php
/**
 * Answers for a WeChat Official Account.
 *
 * Followers write to the account in WeChat; WeChat forwards each message to this site, and the
 * chat answers it from the site's pages, as it does on the site. Any account can do this,
 * including a personal subscription account that is not verified, because the answer goes back
 * as the reply to WeChat's own request. Such an account cannot send a message later, so the
 * answer has to be ready while WeChat waits.
 *
 * WeChat waits five seconds for a reply, then asks again with the same message, twice more.
 * The first request writes the answer and the repeats wait for it, so a follower gets an answer
 * that took up to about fifteen seconds. One that takes longer is kept, and the follower is told
 * to send 1 to see it.
 *
 * Every request is checked against the token set for the account, in plaintext mode and in the
 * safe mode that encrypts messages with the EncodingAESKey. Followers are limited per hour,
 * and every answer counts towards the site's daily request limit. Off until enabled.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_WeChat {

	const REST_ROUTE = '/wechat';

	/*
	 * WeChat gives up on a reply five seconds after it sent the request, and asks again. That
	 * includes the time WordPress takes to start, which on a busy site behind a CDN is a second
	 * or more, so the wait is counted from the start of the request, not from here.
	 */
	const WAIT_SECONDS = 4.0;

	// WeChat asks three times in all.
	const ATTEMPTS = 3;

	// An answer, and the record that one is still being written, last this long.
	const ANSWER_TTL = 600;

	// The conversation kept for follow-up questions, per follower.
	const HISTORY_TTL   = 1800;
	const HISTORY_ITEMS = 6;

	const DEFAULT_HOURLY = 20;
	const MAX_HOURLY     = 500;

	// WeChat shows at most about 2,048 bytes of a text reply.
	const MAX_REPLY_BYTES = 2000;

	// Larger requests are not from WeChat.
	const MAX_BODY_BYTES = 65536;

	// A request signed longer ago than this is refused, so a captured one cannot be replayed.
	const MAX_AGE = 900;

	// What a follower sends to see an answer that took too long.
	const SHOW_KEYWORD = '1';

	// The last time the address was called, and how it went; see note_contact().
	const CONTACT_OPTION = 'aicfab_wechat_contact';

	/**
	 * Whether the account is connected and answering.
	 *
	 * @param array|null $options Settings; the saved ones when omitted.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		$options = self::options( $options );
		return ! empty( $options['wechat_enabled'] ) && '' !== self::token( $options );
	}

	/**
	 * The token set for the account, decrypted.
	 *
	 * @param array|null $options Settings.
	 * @return string
	 */
	public static function token( $options = null ) {
		$options = self::options( $options );
		return isset( $options['wechat_token'] ) && '' !== $options['wechat_token'] ? self::clean_token( AI_Chat_Bedrock_Security::decrypt_secret( $options['wechat_token'] ) ) : '';
	}

	/**
	 * The EncodingAESKey, decrypted, for safe mode.
	 *
	 * @param array|null $options Settings.
	 * @return string
	 */
	public static function aes_key( $options = null ) {
		$options = self::options( $options );
		return isset( $options['wechat_aes_key'] ) && '' !== $options['wechat_aes_key'] ? self::clean_aes_key( AI_Chat_Bedrock_Security::decrypt_secret( $options['wechat_aes_key'] ) ) : '';
	}

	/**
	 * The account's AppID, which safe mode messages carry.
	 *
	 * @param array|null $options Settings.
	 * @return string
	 */
	public static function app_id( $options = null ) {
		$options = self::options( $options );
		return isset( $options['wechat_app_id'] ) ? self::clean_app_id( $options['wechat_app_id'] ) : '';
	}

	/**
	 * The model that answers in WeChat, or an empty string for the chat's own.
	 *
	 * WeChat waits about fifteen seconds in all, so a site whose main model takes longer can
	 * answer WeChat with a faster one and keep its main model for the site.
	 *
	 * @param array|null $options Settings.
	 * @return string
	 */
	public static function model( $options = null ) {
		$options = self::options( $options );
		$model   = isset( $options['wechat_model_id'] ) ? trim( (string) $options['wechat_model_id'] ) : '';
		return '' !== $model && class_exists( 'AI_Chat_Bedrock_Models' ) && AI_Chat_Bedrock_Models::is_valid_id( $model ) ? $model : '';
	}

	/**
	 * Messages a follower may send in an hour.
	 *
	 * @param array|null $options Settings.
	 * @return int
	 */
	public static function hourly_limit( $options = null ) {
		$options = self::options( $options );
		$limit   = isset( $options['wechat_hourly'] ) && '' !== trim( (string) $options['wechat_hourly'] ) ? absint( $options['wechat_hourly'] ) : self::DEFAULT_HOURLY;
		return max( 1, min( self::MAX_HOURLY, $limit ) );
	}

	/**
	 * A token as WeChat allows it: 3 to 32 letters and digits.
	 *
	 * @param string $value Raw value.
	 * @return string The token, or an empty string.
	 */
	public static function clean_token( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^[A-Za-z0-9]{3,32}$/', $value ) ? $value : '';
	}

	/**
	 * An EncodingAESKey: 43 letters and digits.
	 *
	 * @param string $value Raw value.
	 * @return string The key, or an empty string.
	 */
	public static function clean_aes_key( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^[A-Za-z0-9]{43}$/', $value ) ? $value : '';
	}

	/**
	 * An AppID: wx and 16 letters or digits.
	 *
	 * @param string $value Raw value.
	 * @return string The AppID, or an empty string.
	 */
	public static function clean_app_id( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^wx[A-Za-z0-9]{16}$/', $value ) ? $value : '';
	}

	/**
	 * The address to enter as the server URL in the WeChat Official Accounts Platform.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . self::REST_ROUTE );
	}

	/**
	 * Register the address WeChat sends messages to.
	 */
	public function register_routes() {
		$args = array(
			'callback'            => array( $this, 'handle' ),
			// WeChat signs every request instead of authenticating, so the signature is the permission.
			'permission_callback' => array( $this, 'check_permission' ),
		);
		register_rest_route( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1, self::REST_ROUTE, array( array( 'methods' => 'GET' ) + $args, array( 'methods' => 'POST' ) + $args ) );
	}

	/**
	 * Whether a request is WeChat's: signed with the account's token, and recent.
	 *
	 * In safe mode the signature covers the encrypted message, so the body is read here too.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		$options = self::options( null );
		$method  = 'POST' === $request->get_method() ? 'POST' : 'GET';
		if ( ! self::enabled( $options ) ) {
			self::note_contact( 'off', $method );
			return new WP_Error( 'aicfab_wechat_off', 'Not found', array( 'status' => 404 ) );
		}
		$token     = self::token( $options );
		$timestamp = (string) $request->get_param( 'timestamp' );
		$nonce     = (string) $request->get_param( 'nonce' );
		$denied    = new WP_Error( 'aicfab_wechat_signature', 'Forbidden', array( 'status' => 403 ) );
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_AGE || '' === $nonce || strlen( $nonce ) > 64 ) {
			self::note_contact( 'stale', $method );
			return $denied;
		}
		if ( 'POST' === $method && 'aes' === strtolower( (string) $request->get_param( 'encrypt_type' ) ) ) {
			$body  = (string) $request->get_body();
			$outer = strlen( $body ) <= self::MAX_BODY_BYTES ? self::parse( $body ) : null;
			$valid = null !== $outer && ! empty( $outer['Encrypt'] ) && self::signature_matches( (string) $request->get_param( 'msg_signature' ), array( $token, $timestamp, $nonce, $outer['Encrypt'] ) );
		} else {
			$valid = self::signature_matches( (string) $request->get_param( 'signature' ), array( $token, $timestamp, $nonce ) );
		}
		self::note_contact( $valid ? ( 'GET' === $method ? 'checked' : 'message' ) : 'signature', $method );
		return $valid ? true : $denied;
	}

	/**
	 * Remember the last time WeChat, or anyone, called the address, and how it went.
	 *
	 * Setting up the account happens in two places, and when nothing arrives there is no log to
	 * read on most hosts. This tells the settings screen whether WeChat reached the site at all,
	 * whether its signature matched the token, and when. Only that is kept: no message, no
	 * follower. It is written at most once a minute unless the outcome changes.
	 *
	 * @param string $result off, stale, signature, checked or message.
	 * @param string $method GET or POST.
	 */
	public static function note_contact( $result, $method ) {
		$last = get_option( self::CONTACT_OPTION, array() );
		$last = is_array( $last ) ? $last : array();
		if ( isset( $last['result'], $last['time'] ) && $last['result'] === $result && time() - (int) $last['time'] < MINUTE_IN_SECONDS ) {
			return;
		}
		update_option(
			self::CONTACT_OPTION,
			array(
				'result' => $result,
				'method' => $method,
				'time'   => time(),
			),
			false
		);
	}

	/**
	 * The last contact, in words for the settings screen.
	 *
	 * @return string Empty when the address has never been called.
	 */
	public static function contact_summary() {
		$last = get_option( self::CONTACT_OPTION, array() );
		if ( ! is_array( $last ) || empty( $last['time'] ) || empty( $last['result'] ) ) {
			return '';
		}
		$what   = array(
			'off'       => __( 'the address was called while answering was off, so nothing was answered', 'ai-chat-for-amazon-bedrock' ),
			'stale'     => __( 'a request came without a current timestamp, so it was refused', 'ai-chat-for-amazon-bedrock' ),
			'signature' => __( 'the signature did not match the token, so the request was refused; enter the same token here as in WeChat', 'ai-chat-for-amazon-bedrock' ),
			'checked'   => __( 'WeChat checked the address, and the signature matched', 'ai-chat-for-amazon-bedrock' ),
			'message'   => __( 'a signed message arrived from WeChat', 'ai-chat-for-amazon-bedrock' ),
		);
		$result = isset( $what[ $last['result'] ] ) ? $what[ $last['result'] ] : (string) $last['result'];
		/* translators: 1: how long ago, such as 5 mins, 2: what happened. */
		return sprintf( __( 'Last contact %1$s ago: %2$s.', 'ai-chat-for-amazon-bedrock' ), human_time_diff( (int) $last['time'], time() ), $result );
	}

	/**
	 * Answer a request from WeChat, once check_permission() has found it signed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( $request ) {
		$options = self::options( null );
		$token   = self::token( $options );
		$nonce   = (string) $request->get_param( 'nonce' );

		// WeChat checks the address with a GET, and expects echostr back.
		if ( 'GET' === $request->get_method() ) {
			return self::raw( sanitize_text_field( (string) $request->get_param( 'echostr' ) ) );
		}

		$body = (string) $request->get_body();
		if ( '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return self::raw( 'Bad request', 400 );
		}

		$safe = 'aes' === strtolower( (string) $request->get_param( 'encrypt_type' ) );
		if ( $safe ) {
			$outer = self::parse( $body );
			$body  = null !== $outer && ! empty( $outer['Encrypt'] ) ? self::decrypt( $outer['Encrypt'], self::aes_key( $options ), self::app_id( $options ) ) : null;
			if ( null === $body ) {
				return self::raw( 'Forbidden', 403 );
			}
		}

		$message = self::parse( $body );
		if ( null === $message || empty( $message['FromUserName'] ) || empty( $message['ToUserName'] ) ) {
			return self::raw( 'Bad request', 400 );
		}

		// The plugin's own words, such as "send 1", are in the follower's language, not the site's.
		$locale   = self::follower_locale( isset( $message['Content'] ) ? (string) $message['Content'] : '' );
		$switched = '' !== $locale && function_exists( 'switch_to_locale' ) && switch_to_locale( $locale );
		$text     = self::reply_for( $message, $options );
		if ( $switched ) {
			restore_previous_locale();
		}
		// "success" tells WeChat there is nothing to say, so it does not retry or warn the follower.
		if ( '' === $text ) {
			return self::raw( 'success' );
		}
		$xml = self::text_xml( $message['FromUserName'], $message['ToUserName'], $text );
		if ( $safe ) {
			$xml = self::encrypted_xml( $xml, $token, self::aes_key( $options ), self::app_id( $options ), $nonce );
		}
		return self::raw( $xml, 200, 'application/xml' );
	}

	/**
	 * The language to write the plugin's own replies in.
	 *
	 * WeChat's users mostly write Chinese, so that is assumed when there is nothing to go by,
	 * as when someone follows the account. Japanese is recognised by its kana, and text in
	 * any other script is answered in the site's language.
	 *
	 * @param string $text Follower's message.
	 * @return string Locale, or an empty string for the site's.
	 */
	public static function follower_locale( $text ) {
		$text = trim( (string) $text );
		if ( preg_match( '/[\x{3040}-\x{30FF}]/u', $text ) ) {
			$locale = 'ja';
		} elseif ( '' === $text || '1' === $text || preg_match( '/\p{Han}/u', $text ) ) {
			$locale = 'zh_CN';
		} else {
			$locale = '';
		}

		/**
		 * The locale of the plugin's own replies in WeChat, such as "send 1 to see the answer".
		 *
		 * @param string $locale Locale, or an empty string for the site's.
		 * @param string $text   Follower's message.
		 */
		return (string) apply_filters( 'ai_chat_bedrock_wechat_locale', $locale, $text );
	}

	/**
	 * What to reply to one message.
	 *
	 * @param array $message Parsed message.
	 * @param array $options Settings.
	 * @return string Text, or an empty string for no reply.
	 */
	public static function reply_for( $message, $options ) {
		$type     = isset( $message['MsgType'] ) ? strtolower( (string) $message['MsgType'] ) : '';
		$follower = (string) $message['FromUserName'];

		if ( 'event' === $type ) {
			$event = isset( $message['Event'] ) ? strtolower( (string) $message['Event'] ) : '';
			return 'subscribe' === $event ? self::welcome( $options ) : '';
		}
		if ( 'text' !== $type ) {
			return __( 'I can only read text messages for now. Please type your question.', 'ai-chat-for-amazon-bedrock' );
		}

		$text = trim( isset( $message['Content'] ) ? (string) $message['Content'] : '' );
		if ( '' === $text ) {
			return '';
		}

		$key = 'aicfab_wx_' . md5( $follower . '|' . ( ! empty( $message['MsgId'] ) ? (string) $message['MsgId'] : ( isset( $message['CreateTime'] ) ? (string) $message['CreateTime'] : '' ) . '|' . $text ) );

		// A repeat of a message being answered waits for that answer instead of writing another.
		$attempt = (int) get_transient( $key . '_n' ) + 1;
		set_transient( $key . '_n', $attempt, self::ANSWER_TTL );
		if ( $attempt > 1 ) {
			return self::await( $key, $follower, $attempt );
		}

		if ( self::SHOW_KEYWORD === $text ) {
			$pending = get_transient( self::pending_key( $follower ) );
			if ( is_string( $pending ) && '' !== $pending ) {
				$answer = get_transient( $pending );
				if ( is_string( $answer ) ) {
					delete_transient( self::pending_key( $follower ) );
					set_transient( $key, $answer, self::ANSWER_TTL );
					return $answer;
				}
				return __( 'The answer is still being written. Please send 1 again in a moment.', 'ai-chat-for-amazon-bedrock' );
			}
		}

		// The menu and the featured posts are the site's own words, not the model's.
		$fixed = self::menu_reply( $text, $options );
		if ( '' !== $fixed ) {
			set_transient( $key, $fixed, self::ANSWER_TTL );
			return $fixed;
		}

		if ( ! self::within_hourly_limit( $follower, $options ) ) {
			$answer = __( 'You have sent a lot of questions this hour. Please try again later.', 'ai-chat-for-amazon-bedrock' );
			set_transient( $key, $answer, self::ANSWER_TTL );
			return $answer;
		}

		// WeChat closes the connection after five seconds, and the answer is still wanted.
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		$answer = self::answer( $text, $follower, $options );
		set_transient( $key, $answer, self::ANSWER_TTL );
		return $answer;
	}

	/**
	 * The reply to a menu word: the site's menu text, or its newest featured posts.
	 *
	 * With message push on, WeChat turns off the menu set in its console, and an account that
	 * is not verified cannot set one through the API, so a follower sends 菜单 instead of
	 * tapping one. Neither reply calls the model or counts towards the hourly limit.
	 *
	 * @param string $text    Follower's message.
	 * @param array  $options Settings.
	 * @return string The reply, or an empty string for a message that is not a menu word.
	 */
	public static function menu_reply( $text, $options ) {
		$word = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text ) );
		/**
		 * Words that ask for the menu, and for the newest featured posts.
		 *
		 * @param array $keywords menu and featured, each a list of words.
		 */
		$keywords = (array) apply_filters(
			'ai_chat_bedrock_wechat_keywords',
			array(
				'menu'     => array( '菜单', '目录', 'menu', 'メニュー' ),
				'featured' => array( '精选', '最新', 'new', 'latest', '新着' ),
			)
		);
		$menu     = self::menu( $options );
		if ( '' !== $menu && isset( $keywords['menu'] ) && in_array( $word, (array) $keywords['menu'], true ) ) {
			return self::cut_bytes( $menu, self::MAX_REPLY_BYTES );
		}
		if ( isset( $keywords['featured'] ) && in_array( $word, (array) $keywords['featured'], true ) ) {
			return self::featured( $options );
		}
		return '';
	}

	/**
	 * The menu text the site set, if any.
	 *
	 * @param array|null $options Settings.
	 * @return string
	 */
	public static function menu( $options = null ) {
		$options = self::options( $options );
		return isset( $options['wechat_menu'] ) ? trim( (string) $options['wechat_menu'] ) : '';
	}

	/**
	 * The newest featured posts, with their addresses: the category chosen for drafts, in the
	 * language drafts are sent in, or else the newest posts.
	 *
	 * @param array $options Settings.
	 * @return string
	 */
	public static function featured( $options ) {
		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 8,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'has_password'        => false,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		if ( class_exists( 'AI_Chat_Bedrock_WeChat_Drafts' ) ) {
			if ( AI_Chat_Bedrock_WeChat_Drafts::category( $options ) ) {
				$args['cat'] = AI_Chat_Bedrock_WeChat_Drafts::category( $options );
			}
			if ( '' !== AI_Chat_Bedrock_WeChat_Drafts::language() ) {
				$args['lang'] = AI_Chat_Bedrock_WeChat_Drafts::language();
			}
		}
		$lines = array();
		foreach ( get_posts( $args ) as $post ) {
			if ( count( $lines ) < 5 && ( ! class_exists( 'AI_Chat_Bedrock_Content' ) || AI_Chat_Bedrock_Content::is_public( $post ) ) ) {
				$lines[] = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) . "\n" . get_permalink( $post );
			}
		}
		if ( ! $lines ) {
			return __( 'There are no featured articles yet.', 'ai-chat-for-amazon-bedrock' );
		}
		return self::cut_bytes( __( 'Featured articles:', 'ai-chat-for-amazon-bedrock' ) . "\n\n" . implode( "\n\n", $lines ), self::MAX_REPLY_BYTES );
	}

	/**
	 * Wait for the answer to a message WeChat sent again.
	 *
	 * @param string $key      Answer key.
	 * @param string $follower Follower's OpenID.
	 * @param int    $attempt  Which time WeChat sent it.
	 * @return string
	 */
	private static function await( $key, $follower, $attempt ) {
		/**
		 * Seconds a repeated request waits for the answer. WeChat gives up after five.
		 *
		 * @param float $seconds Seconds.
		 */
		$wait  = max( 0.0, (float) apply_filters( 'ai_chat_bedrock_wechat_wait', self::WAIT_SECONDS ) );
		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );

		/*
		 * Before the last time, an answer that is not ready must not be replied to at all: a
		 * reply, even an empty one, tells WeChat to stop asking. Waiting past its five seconds
		 * makes it ask once more, and that request may find the answer.
		 */
		$until = $start + ( $attempt < self::ATTEMPTS ? $wait + 2.0 : $wait );
		do {
			// Read past any cache, since another request writes it.
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $key, 'transient' );
			}
			$answer = get_transient( $key );
			if ( is_string( $answer ) ) {
				return $answer;
			}
			if ( microtime( true ) >= $until ) {
				break;
			}
			usleep( 250000 );
		} while ( true );

		if ( $attempt >= self::ATTEMPTS ) {
			// The last time WeChat asks: keep the answer for the follower to collect.
			set_transient( self::pending_key( $follower ), $key, self::ANSWER_TTL );
			return __( 'This one needs a little longer. Send 1 in a moment to see the answer.', 'ai-chat-for-amazon-bedrock' );
		}
		return '';
	}

	/**
	 * Answer a question from the site's content, as plain text for WeChat.
	 *
	 * @param string $text     Question.
	 * @param string $follower Follower's OpenID.
	 * @param array  $options  Settings.
	 * @return string
	 */
	private static function answer( $text, $follower, $options ) {
		if ( ! class_exists( 'AI_Chat_Bedrock_Chat_Request' ) || ! class_exists( 'AI_Chat_Bedrock_Tool_Runner' ) ) {
			return self::sorry();
		}
		// There is no button in WeChat to ask for a person, so the model is not told about one.
		$options['leads_enabled'] = false;
		// Pages in the follower's language come first, as they do for a visitor reading the site in it.
		$languages = array(
			'zh_CN' => 'zh',
			'ja'    => 'ja',
		);
		$locale    = self::follower_locale( $text );
		if ( isset( $languages[ $locale ] ) && class_exists( 'AI_Chat_Bedrock_Content' ) ) {
			$options['_retrieval_language'] = AI_Chat_Bedrock_Content::request_language( $languages[ $locale ] );
		}
		$options['system_prompt'] = trim( ( isset( $options['system_prompt'] ) ? (string) $options['system_prompt'] : '' ) . "\n\n" . __( 'You are replying in a WeChat Official Account chat. Reply in the language of the follower\'s latest message. Write plain text without Markdown, such as asterisks, hashes or tables, and keep the answer under 300 words. Put any web address on a line of its own.', 'ai-chat-for-amazon-bedrock' ) );

		$history = get_transient( self::history_key( $follower ) );
		$history = is_array( $history ) ? $history : array();
		$built   = AI_Chat_Bedrock_Chat_Request::build( AI_Chat_Bedrock_Security::string_substr( $text, 0, 2000 ), $history, $options );
		if ( is_wp_error( $built ) ) {
			return 'aicfab_daily_limit' === $built->get_error_code() ? __( 'The assistant has answered as many questions as it can today. Please try again tomorrow.', 'ai-chat-for-amazon-bedrock' ) : self::sorry();
		}

		$main      = isset( $options['model_id'] ) ? (string) $options['model_id'] : '';
		$model     = self::model( $options );
		$fallback  = isset( $options['fallback_model_id'] ) ? (string) $options['fallback_model_id'] : '';
		$options   = '' !== $model ? array( 'model_id' => $model ) + $options : $options;
		$overrides = AI_Chat_Bedrock_Profiles::overrides_for_client( $options );
		if ( '' !== $model && $model === $fallback ) {
			// The faster model is also the fallback: the main model then stands in for it.
			$overrides['fallback_model_id'] = $main;
		}
		$aws      = new AI_Chat_Bedrock_AWS( $overrides );
		$response = AI_Chat_Bedrock_Tool_Runner::run( $aws, $built['messages'], $built['message'] );
		if ( empty( $response['success'] ) || empty( $response['data']['message'] ) ) {
			return self::sorry();
		}
		$raw = (string) $response['data']['message'];

		if ( class_exists( 'AI_Chat_Bedrock_Conversations' ) ) {
			AI_Chat_Bedrock_Conversations::record(
				$built['message'],
				$raw,
				array(
					'usage'    => isset( $response['usage'] ) ? $response['usage'] : array(),
					'source'   => 'wechat',
					'grounded' => ! empty( $built['grounded'] ),
					'model'    => isset( $options['model_id'] ) ? $options['model_id'] : '',
				)
			);
		}

		$history[] = array(
			'role'    => 'user',
			'content' => $built['message'],
		);
		$history[] = array(
			'role'    => 'assistant',
			'content' => AI_Chat_Bedrock_Security::string_substr( $raw, 0, 4000 ),
		);
		set_transient( self::history_key( $follower ), array_slice( $history, -self::HISTORY_ITEMS ), self::HISTORY_TTL );

		return self::format( $raw, $built['sources'], self::ai_label() );
	}

	/**
	 * The note under every answer saying a model wrote it.
	 *
	 * In WeChat an answer arrives as a message from the account itself, with nothing to show
	 * that no person wrote it, as the chat's AI avatar does on the site. Content that AI
	 * generates has to be labelled as such where it is shown, under WeChat's rules for AI
	 * question answering and China's rules for labelling AI-generated content.
	 *
	 * @return string
	 */
	public static function ai_label() {
		/**
		 * The note added to every answer in WeChat to say that AI wrote it.
		 *
		 * @param string $label Note.
		 */
		return trim( (string) apply_filters( 'ai_chat_bedrock_wechat_ai_label', __( 'Generated by AI. Check the linked pages for anything important.', 'ai-chat-for-amazon-bedrock' ) ) );
	}

	/**
	 * An answer as WeChat shows it: plain text, short enough, with its sources.
	 *
	 * @param string $text    Model answer.
	 * @param array  $sources Pages the answer drew on.
	 * @param string $label   Note added at the end, such as that AI wrote it.
	 * @return string
	 */
	public static function format( $text, $sources = array(), $label = '' ) {
		$text = str_replace( "\r\n", "\n", (string) $text );
		// Links become their text and address, since WeChat shows no Markdown.
		$text = preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '$1 $2', $text );
		$text = preg_replace( '/^[ \t]{0,3}#{1,6}[ \t]*/m', '', (string) $text );
		$text = preg_replace( '/^[ \t]*[-*+][ \t]+/m', '· ', (string) $text );
		$text = preg_replace( '/(\*\*|__|`)/', '', (string) $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", (string) $text );
		$text = trim( (string) $text );

		// An answer that already links to pages, as the model is told to, gets no second list:
		// the pages it quoted are not always the ones listed as sources.
		$sources = array_slice( is_array( $sources ) ? array_values( array_filter( $sources, 'is_array' ) ) : array(), 0, 2 );
		$cited   = 1 === preg_match( '#https?://#i', $text );
		$links   = '';
		foreach ( $cited ? array() : $sources as $source ) {
			if ( ! empty( $source['url'] ) ) {
				$links .= "\n" . ( ! empty( $source['title'] ) ? wp_strip_all_tags( (string) $source['title'] ) . "\n" : '' ) . esc_url_raw( (string) $source['url'] );
			}
		}
		if ( '' !== $links ) {
			$links = "\n\n" . __( 'Sources:', 'ai-chat-for-amazon-bedrock' ) . $links;
		}

		$label = '' !== trim( (string) $label ) ? "\n\n" . trim( (string) $label ) : '';
		$room  = self::MAX_REPLY_BYTES - strlen( $links ) - strlen( $label );
		if ( strlen( $text ) > $room ) {
			$text = self::cut_bytes( $text, max( 200, $room - 3 ) ) . '…';
		}
		return $text . $links . $label;
	}

	/**
	 * Cut UTF-8 text to at most a number of bytes without splitting a character.
	 *
	 * @param string $text  Text.
	 * @param int    $bytes Bytes.
	 * @return string
	 */
	private static function cut_bytes( $text, $bytes ) {
		$cut = substr( $text, 0, $bytes );
		// Drop a trailing partial character.
		while ( '' !== $cut && ! preg_match( '//u', $cut ) ) {
			$cut = substr( $cut, 0, -1 );
		}
		return rtrim( $cut );
	}

	/**
	 * The reply when someone follows the account.
	 *
	 * @param array $options Settings.
	 * @return string
	 */
	private static function welcome( $options ) {
		$text  = class_exists( 'AI_Chat_Bedrock_Translation' ) ? AI_Chat_Bedrock_Translation::presentation( $options ) : $options;
		$hello = isset( $text['welcome_message'] ) && '' !== trim( (string) $text['welcome_message'] ) ? (string) $text['welcome_message'] : __( 'Hello! How can I help you today?', 'ai-chat-for-amazon-bedrock' );
		$asks  = class_exists( 'AI_Chat_Bedrock_Chat_Request' ) ? AI_Chat_Bedrock_Chat_Request::suggestions( $text ) : array();
		if ( $asks ) {
			$hello .= "\n\n" . __( 'You could ask:', 'ai-chat-for-amazon-bedrock' );
			foreach ( array_slice( $asks, 0, 4 ) as $ask ) {
				$hello .= "\n· " . $ask;
			}
		}
		$menu = self::menu( $options );
		return self::cut_bytes( self::format( $hello ) . ( '' !== $menu ? "\n\n" . $menu : '' ), self::MAX_REPLY_BYTES );
	}

	private static function sorry() {
		return __( 'Sorry, the answer could not be written right now. Please try again in a moment.', 'ai-chat-for-amazon-bedrock' );
	}

	/**
	 * Count a message against the follower's hourly limit.
	 *
	 * @param string $follower OpenID.
	 * @param array  $options  Settings.
	 * @return bool Whether it is within the limit.
	 */
	private static function within_hourly_limit( $follower, $options ) {
		$key   = 'aicfab_wx_rate_' . md5( $follower . '|' . gmdate( 'YmdH' ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::hourly_limit( $options ) ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	private static function pending_key( $follower ) {
		return 'aicfab_wx_wait_' . md5( (string) $follower );
	}

	private static function history_key( $follower ) {
		return 'aicfab_wx_hist_' . md5( (string) $follower );
	}

	/**
	 * Whether a signature is the SHA-1 of the sorted parts, as WeChat makes it.
	 *
	 * @param string   $signature Signature sent.
	 * @param string[] $parts     Token, timestamp, nonce and, in safe mode, the encrypted message.
	 * @return bool
	 */
	public static function signature_matches( $signature, $parts ) {
		$parts = array_map( 'strval', $parts );
		sort( $parts, SORT_STRING );
		return '' !== $signature && hash_equals( sha1( implode( '', $parts ) ), strtolower( (string) $signature ) );
	}

	/**
	 * The fields of a WeChat XML message.
	 *
	 * @param string $xml XML.
	 * @return array|null Field names to text, or null for anything else.
	 */
	public static function parse( $xml ) {
		$xml = trim( (string) $xml );
		// No document types, so no entities, external or otherwise.
		if ( '' === $xml || false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) || ! function_exists( 'simplexml_load_string' ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $doc || 'xml' !== $doc->getName() ) {
			return null;
		}
		$fields = array();
		foreach ( $doc->children() as $name => $value ) {
			$fields[ (string) $name ] = trim( (string) $value );
		}
		return $fields;
	}

	/**
	 * A text reply in WeChat's XML.
	 *
	 * @param string $to   Follower's OpenID.
	 * @param string $from The account's original ID.
	 * @param string $text Text.
	 * @return string
	 */
	public static function text_xml( $to, $from, $text ) {
		return '<xml><ToUserName>' . self::cdata( $to ) . '</ToUserName><FromUserName>' . self::cdata( $from ) . '</FromUserName><CreateTime>' . time() . '</CreateTime><MsgType><![CDATA[text]]></MsgType><Content>' . self::cdata( $text ) . '</Content></xml>';
	}

	private static function cdata( $value ) {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', (string) $value ) . ']]>';
	}

	/**
	 * Decrypt a safe mode message.
	 *
	 * WeChat encrypts with AES-256-CBC, the key being the EncodingAESKey decoded from base64 and
	 * the IV its first 16 bytes, over 16 random bytes, the message length in four bytes, the
	 * message and the AppID, padded to a multiple of 32 bytes as in PKCS#7.
	 *
	 * @param string $encrypted Base64 ciphertext.
	 * @param string $aes_key   EncodingAESKey.
	 * @param string $app_id    AppID the message must be for.
	 * @return string|null XML, or null when it does not decrypt or is for another account.
	 */
	public static function decrypt( $encrypted, $aes_key, $app_id ) {
		$key = self::raw_key( $aes_key );
		if ( null === $key || '' === $app_id || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$cipher = base64_decode( (string) $encrypted, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- WeChat sends the ciphertext in base64.
		if ( false === $cipher || '' === $cipher || 0 !== strlen( $cipher ) % 32 ) {
			return null;
		}
		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr( $key, 0, 16 ) );
		if ( false === $plain || '' === $plain ) {
			return null;
		}
		$pad = ord( substr( $plain, -1 ) );
		if ( $pad < 1 || $pad > 32 ) {
			return null;
		}
		$plain = substr( $plain, 0, -$pad );
		if ( strlen( $plain ) < 20 ) {
			return null;
		}
		$length = unpack( 'N', substr( $plain, 16, 4 ) );
		$length = is_array( $length ) ? (int) $length[1] : -1;
		if ( $length < 0 || 20 + $length > strlen( $plain ) ) {
			return null;
		}
		$xml = substr( $plain, 20, $length );
		return hash_equals( $app_id, substr( $plain, 20 + $length ) ) ? $xml : null;
	}

	/**
	 * Encrypt a reply for safe mode.
	 *
	 * @param string $xml     Reply XML.
	 * @param string $aes_key EncodingAESKey.
	 * @param string $app_id  AppID.
	 * @return string|null Base64 ciphertext.
	 */
	public static function encrypt( $xml, $aes_key, $app_id ) {
		$key = self::raw_key( $aes_key );
		if ( null === $key || ! function_exists( 'openssl_encrypt' ) ) {
			return null;
		}
		$plain  = random_bytes( 16 ) . pack( 'N', strlen( $xml ) ) . $xml . $app_id;
		$pad    = 32 - ( strlen( $plain ) % 32 );
		$plain .= str_repeat( chr( $pad ), $pad );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr( $key, 0, 16 ) );
		return false === $cipher ? null : base64_encode( $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WeChat expects the ciphertext in base64.
	}

	/**
	 * A reply wrapped for safe mode, signed as WeChat expects.
	 *
	 * @param string $xml     Reply XML.
	 * @param string $token   Token.
	 * @param string $aes_key EncodingAESKey.
	 * @param string $app_id  AppID.
	 * @param string $nonce   Nonce of the request.
	 * @return string
	 */
	public static function encrypted_xml( $xml, $token, $aes_key, $app_id, $nonce ) {
		$encrypted = self::encrypt( $xml, $aes_key, $app_id );
		if ( null === $encrypted ) {
			return 'success';
		}
		$timestamp = (string) time();
		$parts     = array( $token, $timestamp, $nonce, $encrypted );
		sort( $parts, SORT_STRING );
		return '<xml><Encrypt>' . self::cdata( $encrypted ) . '</Encrypt><MsgSignature>' . self::cdata( sha1( implode( '', $parts ) ) ) . '</MsgSignature><TimeStamp>' . $timestamp . '</TimeStamp><Nonce>' . self::cdata( $nonce ) . '</Nonce></xml>';
	}

	private static function raw_key( $aes_key ) {
		$aes_key = self::clean_aes_key( $aes_key );
		if ( '' === $aes_key ) {
			return null;
		}
		$key = base64_decode( $aes_key . '=', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the EncodingAESKey is the AES key in base64.
		return false !== $key && 32 === strlen( $key ) ? $key : null;
	}

	/**
	 * A response WordPress sends as it is, not as JSON. See serve().
	 *
	 * @param string $body   Body.
	 * @param int    $status HTTP status.
	 * @param string $type   Content type.
	 * @return WP_REST_Response
	 */
	private static function raw( $body, $status = 200, $type = 'text/plain' ) {
		$response = new WP_REST_Response( (string) $body, $status );
		$response->header( 'Content-Type', $type . '; charset=utf-8' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-AICFAB-Raw', '1' );
		return $response;
	}

	/**
	 * Send this route's responses as plain text or XML, which is what WeChat reads.
	 *
	 * @param bool             $served  Whether the response was already sent.
	 * @param WP_REST_Response $result  Response.
	 * @param WP_REST_Request  $request Request.
	 * @return bool
	 */
	public static function serve( $served, $result, $request ) {
		if ( $served || ! $request instanceof WP_REST_Request || AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . self::REST_ROUTE !== ltrim( $request->get_route(), '/' ) ) {
			return $served;
		}
		$headers = $result instanceof WP_REST_Response ? $result->get_headers() : array();
		if ( empty( $headers['X-AICFAB-Raw'] ) ) {
			return $served;
		}
		echo (string) $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML built from CDATA sections, or a fixed word, for WeChat.
		return true;
	}

	private static function options( $options ) {
		if ( is_array( $options ) ) {
			return $options;
		}
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		return is_array( $options ) ? $options : array();
	}
}
