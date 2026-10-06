<?php
/**
 * Customer service and player counts for a WeChat mini game.
 *
 * A mini game sends what players do in its customer service chat to one address: a player
 * opening the chat, from which part of the game, the messages they write, and their answers
 * to subscription message prompts. This site receives them, answers questions it has a set
 * answer for, and keeps daily counts the site owner can read.
 *
 * Answers are the site owner's own, matched by keyword. Nothing is generated: a mini game
 * that answers with generative AI needs an AI category and an algorithm filing in China,
 * which a personal mini game cannot have. A mini game cannot reply to WeChat's request either,
 * so answers go back through WeChat's customer service message API, which needs the
 * AppSecret, the server's address in the game's IP whitelist, and a player who wrote in the
 * last 48 hours.
 *
 * The counts are totals per day. No OpenID is kept: players are counted with a key that
 * changes every day and is discarded after it. A player's question is kept only in the
 * conversation log, when the site keeps one. What players do inside the game itself does not
 * come here; the game reports that with wx.reportEvent, and WeChat's own analysis shows it.
 *
 * Every request is checked against the token, in plaintext and safe mode, in JSON or XML.
 * Off until enabled.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_WeChat_Game {

	const REST_ROUTE = '/wechat-game';

	// Daily counts, and the last time the address was called and an answer was sent.
	const STATS_OPTION   = 'aicfab_wxgame_stats';
	const CONTACT_OPTION = 'aicfab_wxgame_contact';
	const ACCESS_KEY     = 'aicfab_wxgame_access';

	const STATS_DAYS = 90;

	// Distinct scenes, events and templates counted a day; more are counted as "other".
	const MAX_KEYS = 20;

	// Players counted a day; beyond this the count stops rising.
	const MAX_PLAYERS = 10000;

	const MAX_ANSWERS = 50;

	// WeChat shows at most about 2,048 bytes of a customer service text.
	const MAX_REPLY_BYTES = 2000;

	// A player who opens the chat again within this time is not welcomed again.
	const WELCOME_EVERY = 43200;

	// A request WeChat sends again, because the first took too long, is handled once, for as long
	// as its signature is accepted.
	const SEEN_TTL = 960;

	// WeChat waits five seconds; an answer is not tried again after four.
	const SEND_SECONDS = 4.0;

	const MAX_BODY_BYTES = 65536;
	const MAX_AGE        = 900;

	/**
	 * Whether the game is connected.
	 *
	 * @param array|null $options Settings; the saved ones when omitted.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		$options = self::options( $options );
		return ! empty( $options['wxgame_enabled'] ) && '' !== self::token( $options ) && '' !== self::app_id( $options );
	}

	public static function token( $options = null ) {
		return self::secret_setting( $options, 'wxgame_token', 'clean_token' );
	}

	public static function aes_key( $options = null ) {
		return self::secret_setting( $options, 'wxgame_aes_key', 'clean_aes_key' );
	}

	public static function app_secret( $options = null ) {
		return self::secret_setting( $options, 'wxgame_app_secret', 'clean_app_secret' );
	}

	public static function app_id( $options = null ) {
		$options = self::options( $options );
		return isset( $options['wxgame_app_id'] ) ? AI_Chat_Bedrock_WeChat::clean_app_id( $options['wxgame_app_id'] ) : '';
	}

	/**
	 * Whether answers can be sent: they need the AppSecret.
	 *
	 * @param array|null $options Settings.
	 * @return bool
	 */
	public static function can_reply( $options = null ) {
		return '' !== self::app_secret( $options );
	}

	/**
	 * An AppSecret: 32 letters and digits.
	 *
	 * @param string $value Raw value.
	 * @return string The AppSecret, or an empty string.
	 */
	public static function clean_app_secret( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^[A-Za-z0-9]{32}$/', $value ) ? $value : '';
	}

	public static function clean_token( $value ) {
		return AI_Chat_Bedrock_WeChat::clean_token( $value );
	}

	public static function clean_aes_key( $value ) {
		return AI_Chat_Bedrock_WeChat::clean_aes_key( $value );
	}

	/**
	 * The set answers, one per line as "keywords = answer", keywords separated by | or commas.
	 *
	 * @param string $value Raw text.
	 * @return string The lines that have both, at most MAX_ANSWERS.
	 */
	public static function clean_answers( $value ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
			$line  = sanitize_text_field( $line );
			$parts = explode( '=', $line, 2 );
			if ( 2 === count( $parts ) && '' !== trim( $parts[0] ) && '' !== trim( $parts[1] ) ) {
				$lines[] = trim( $parts[0] ) . ' = ' . trim( $parts[1] );
			}
		}
		return implode( "\n", array_slice( $lines, 0, self::MAX_ANSWERS ) );
	}

	/**
	 * The set answers, as keywords and answer.
	 *
	 * @param array|null $options Settings.
	 * @return array[] Each with 'keywords' and 'answer'.
	 */
	public static function answers( $options = null ) {
		$options = self::options( $options );
		$answers = array();
		foreach ( explode( "\n", self::clean_answers( isset( $options['wxgame_answers'] ) ? $options['wxgame_answers'] : '' ) ) as $line ) {
			$parts = explode( ' = ', $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$keywords = array_values( array_filter( array_map( 'trim', preg_split( '/[|,，、]/u', $parts[0] ) ), 'strlen' ) );
			if ( $keywords ) {
				$answers[] = array(
					'keywords' => $keywords,
					'answer'   => $parts[1],
				);
			}
		}
		return $answers;
	}

	/**
	 * The answer for a question: the first whose keyword the question contains.
	 *
	 * @param string $text    Question.
	 * @param array  $answers From answers().
	 * @return string The answer, or an empty string.
	 */
	public static function match( $text, $answers ) {
		$text = (string) $text;
		foreach ( $answers as $entry ) {
			foreach ( $entry['keywords'] as $keyword ) {
				$found = function_exists( 'mb_stripos' ) ? mb_stripos( $text, $keyword, 0, 'UTF-8' ) : stripos( $text, $keyword );
				if ( false !== $found ) {
					return $entry['answer'];
				}
			}
		}
		return '';
	}

	/**
	 * The address to enter as the server URL for the game's message push.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . self::REST_ROUTE );
	}

	/**
	 * Register the address WeChat sends the game's messages and events to.
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
	 * Whether a request is WeChat's: signed with the game's token, and recent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		$options = self::options( null );
		$method  = 'POST' === $request->get_method() ? 'POST' : 'GET';
		if ( ! self::enabled( $options ) ) {
			self::note_contact( 'off' );
			return new WP_Error( 'aicfab_wxgame_off', 'Not found', array( 'status' => 404 ) );
		}
		$token     = self::token( $options );
		$timestamp = (string) $request->get_param( 'timestamp' );
		$nonce     = (string) $request->get_param( 'nonce' );
		$denied    = new WP_Error( 'aicfab_wxgame_signature', 'Forbidden', array( 'status' => 403 ) );
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_AGE || '' === $nonce || strlen( $nonce ) > 64 ) {
			self::note_contact( 'stale' );
			return $denied;
		}
		$safe = 'aes' === strtolower( (string) $request->get_param( 'encrypt_type' ) );
		// With an EncodingAESKey set the game is in safe mode, and a plaintext message, whose
		// signature does not cover it, is not WeChat's; with the Official Account's token, only an
		// encrypted message, which names its AppID, shows which of the two it was meant for.
		if ( 'POST' === $method && ! $safe && ( '' !== self::aes_key( $options ) || AI_Chat_Bedrock_WeChat::token( $options ) === $token ) ) {
			self::note_contact( 'plaintext' );
			return $denied;
		}
		if ( 'POST' === $method && $safe ) {
			$body  = (string) $request->get_body();
			$outer = strlen( $body ) <= self::MAX_BODY_BYTES ? self::parse( $body ) : null;
			$valid = null !== $outer && ! empty( $outer['Encrypt'] ) && is_string( $outer['Encrypt'] ) && AI_Chat_Bedrock_WeChat::signature_matches( (string) $request->get_param( 'msg_signature' ), array( $token, $timestamp, $nonce, $outer['Encrypt'] ) );
		} else {
			$valid = AI_Chat_Bedrock_WeChat::signature_matches( (string) $request->get_param( 'signature' ), array( $token, $timestamp, $nonce ) );
		}
		if ( $valid && 'POST' === $method && ! AI_Chat_Bedrock_WeChat_API::fresh( self::REST_ROUTE, $timestamp, $nonce, (string) $request->get_body(), self::MAX_AGE ) ) {
			self::note_contact( 'replay' );
			return $denied;
		}
		self::note_contact( $valid ? ( 'GET' === $method ? 'checked' : 'message' ) : 'signature' );
		return $valid ? true : $denied;
	}

	/**
	 * Take a request from WeChat, once check_permission() has found it signed.
	 *
	 * WeChat only needs to hear "success"; any answer is sent separately.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( $request ) {
		if ( 'GET' === $request->get_method() ) {
			return self::raw( sanitize_text_field( (string) $request->get_param( 'echostr' ) ) );
		}
		$options = self::options( null );
		$body    = (string) $request->get_body();
		if ( '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return self::raw( 'Bad request', 400 );
		}
		if ( 'aes' === strtolower( (string) $request->get_param( 'encrypt_type' ) ) ) {
			$outer = self::parse( $body );
			$body  = null !== $outer && ! empty( $outer['Encrypt'] ) && is_string( $outer['Encrypt'] ) ? AI_Chat_Bedrock_WeChat::decrypt( $outer['Encrypt'], self::aes_key( $options ), self::app_id( $options ) ) : null;
			if ( null === $body ) {
				return self::raw( 'Forbidden', 403 );
			}
		}
		$message = self::parse( $body );
		if ( null === $message || empty( $message['FromUserName'] ) || ! is_string( $message['FromUserName'] ) ) {
			return self::raw( 'Bad request', 400 );
		}
		if ( self::first_time( $message ) ) {
			self::take( $message, $options );
		}
		return self::raw( 'success' );
	}

	/**
	 * Whether this is the first time WeChat sent this message or event.
	 *
	 * @param array $message Message.
	 * @return bool
	 */
	private static function first_time( $message ) {
		$id  = ! empty( $message['MsgId'] ) ? (string) $message['MsgId'] : self::text( $message, 'FromUserName' ) . '|' . self::text( $message, 'CreateTime' ) . '|' . self::text( $message, 'Event' );
		$key = 'aicfab_wxg_seen_' . md5( $id );
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, self::SEEN_TTL );
		return true;
	}

	/**
	 * Count a message or event, and answer it when there is something to say.
	 *
	 * @param array $message Message.
	 * @param array $options Settings.
	 */
	public static function take( $message, $options ) {
		$player = self::text( $message, 'FromUserName' );
		$type   = strtolower( self::text( $message, 'MsgType' ) );
		$stats  = self::stats();
		$day    = wp_date( 'Y-m-d' );
		$today  = isset( $stats[ $day ] ) ? $stats[ $day ] : array();
		$reply  = '';

		if ( 'event' === $type ) {
			$event = strtolower( self::text( $message, 'Event' ) );
			if ( 'user_enter_tempsession' === $event ) {
				self::bump( $today, 'sessions' );
				self::bump( $today, 'scenes', self::key( self::text( $message, 'SessionFrom' ), 'none' ) );
				self::count_player( $today, $player );
				$reply = self::welcome( $player, $options );
			} elseif ( in_array( $event, array( 'subscribe_msg_popup_event', 'subscribe_msg_change_event', 'subscribe_msg_sent_event' ), true ) ) {
				self::count_subscriptions( $today, $event, $message );
			} else {
				self::bump( $today, 'events', self::key( $event, 'other' ) );
			}
		} else {
			self::bump( $today, 'messages', self::key( $type, 'other' ) );
			self::count_player( $today, $player );
			if ( 'text' === $type ) {
				$question = self::text( $message, 'Content' );
				$reply    = self::match( $question, self::answers( $options ) );
				self::bump( $today, '' !== $reply ? 'answered' : 'unanswered' );
				if ( class_exists( 'AI_Chat_Bedrock_Conversations' ) ) {
					AI_Chat_Bedrock_Conversations::record(
						$question,
						$reply,
						array(
							'source'   => 'wxgame',
							'grounded' => '' !== $reply,
						)
					);
				}
				if ( '' === $reply && isset( $options['wxgame_fallback'] ) ) {
					$reply = trim( (string) $options['wxgame_fallback'] );
				}
			}
		}

		// Counted before the answer is sent, which can take seconds, so a message that arrives
		// meanwhile is not counted over.
		$stats[ $day ] = $today;
		self::save_stats( $stats );
		if ( '' !== $reply && self::can_reply( $options ) ) {
			$sent  = self::send( $player, $reply, $options );
			$stats = self::stats();
			$today = isset( $stats[ $day ] ) ? $stats[ $day ] : array();
			self::bump( $today, true === $sent ? 'replies' : 'failed' );
			$stats[ $day ] = $today;
			self::save_stats( $stats );
		}
	}

	/**
	 * The welcome for a player who opened the chat, unless they were welcomed lately.
	 *
	 * @param string $player  OpenID.
	 * @param array  $options Settings.
	 * @return string
	 */
	private static function welcome( $player, $options ) {
		$text = isset( $options['wxgame_welcome'] ) ? trim( (string) $options['wxgame_welcome'] ) : '';
		if ( '' === $text ) {
			return '';
		}
		$key = 'aicfab_wxg_hi_' . md5( wp_salt() . $player );
		if ( false !== get_transient( $key ) ) {
			return '';
		}
		set_transient( $key, 1, self::WELCOME_EVERY );
		return $text;
	}

	/**
	 * Count the answers to subscription prompts, and what became of the messages sent.
	 *
	 * @param array  $today Today's counts.
	 * @param string $event Event.
	 * @param array  $message Message.
	 */
	private static function count_subscriptions( &$today, $event, $message ) {
		$field = array(
			'subscribe_msg_popup_event'  => 'SubscribeMsgPopupEvent',
			'subscribe_msg_change_event' => 'SubscribeMsgChangeEvent',
			'subscribe_msg_sent_event'   => 'SubscribeMsgSentEvent',
		);
		foreach ( self::entries( isset( $message[ $field[ $event ] ] ) ? $message[ $field[ $event ] ] : null ) as $entry ) {
			$template = self::key( isset( $entry['TemplateId'] ) ? (string) $entry['TemplateId'] : '', 'other', 64 );
			if ( 'subscribe_msg_sent_event' === $event ) {
				$outcome = isset( $entry['ErrorCode'] ) && '0' === (string) $entry['ErrorCode'] ? 'delivered' : 'undelivered';
			} else {
				$status  = isset( $entry['SubscribeStatusString'] ) ? strtolower( (string) $entry['SubscribeStatusString'] ) : '';
				$outcome = 'subscribe_msg_popup_event' === $event ? ( 'accept' === $status ? 'accepted' : 'declined' ) : ( 'accept' === $status ? 'turned_on' : 'turned_off' );
			}
			if ( ! isset( $today['templates'][ $template ] ) && isset( $today['templates'] ) && count( $today['templates'] ) >= self::MAX_KEYS - 1 ) {
				$template = 'other';
			}
			$today['templates'][ $template ][ $outcome ] = isset( $today['templates'][ $template ][ $outcome ] ) ? $today['templates'][ $template ][ $outcome ] + 1 : 1;
		}
	}

	/**
	 * The entries of a subscription event, which WeChat sends as one or as a list, in XML
	 * under List and in JSON as they are.
	 *
	 * @param mixed $value Event field.
	 * @return array[]
	 */
	private static function entries( $value ) {
		if ( is_array( $value ) && isset( $value['List'] ) ) {
			$value = $value['List'];
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return isset( $value['TemplateId'] ) ? array( $value ) : array_values( array_filter( $value, 'is_array' ) );
	}

	/**
	 * Count a player once a day, without keeping who they are.
	 *
	 * Each player seen today is a transient named by a hash with a salt made for the day; the
	 * salt and the names expire when the day ends, so no list of players is kept or rewritten.
	 *
	 * @param array  $today  Today's counts.
	 * @param string $player OpenID.
	 */
	private static function count_player( &$today, $player ) {
		if ( isset( $today['players'] ) && (int) $today['players'] >= self::MAX_PLAYERS ) {
			return;
		}
		$zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$left = max( 60, ( new DateTime( 'tomorrow', $zone ) )->getTimestamp() - time() );
		$salt = get_transient( 'aicfab_wxg_salt_' . wp_date( 'Ymd' ) );
		if ( ! is_string( $salt ) || '' === $salt ) {
			$salt = bin2hex( random_bytes( 16 ) );
			set_transient( 'aicfab_wxg_salt_' . wp_date( 'Ymd' ), $salt, $left );
		}
		$key = 'aicfab_wxg_p_' . substr( hash_hmac( 'sha256', $player, $salt ), 0, 32 );
		if ( false !== get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, $left );
		self::bump( $today, 'players' );
	}

	/**
	 * Add one to a count, or to a count under a key, keeping at most MAX_KEYS keys.
	 *
	 * @param array       $today Today's counts.
	 * @param string      $name  Count.
	 * @param string|null $key   Key within it.
	 */
	private static function bump( &$today, $name, $key = null ) {
		if ( null === $key ) {
			$today[ $name ] = isset( $today[ $name ] ) ? (int) $today[ $name ] + 1 : 1;
			return;
		}
		$map = isset( $today[ $name ] ) && is_array( $today[ $name ] ) ? $today[ $name ] : array();
		// One place is kept for "other".
		if ( ! isset( $map[ $key ] ) && count( $map ) >= self::MAX_KEYS - 1 ) {
			$key = 'other';
		}
		$map[ $key ]    = isset( $map[ $key ] ) ? $map[ $key ] + 1 : 1;
		$today[ $name ] = $map;
	}

	/**
	 * A scene, event or template as a key: letters, digits, dashes and underscores.
	 *
	 * @param string $value    Value.
	 * @param string $fallback Key when nothing is left.
	 * @param int    $length   Longest key.
	 * @return string
	 */
	private static function key( $value, $fallback, $length = 32 ) {
		$value = substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value ), 0, $length );
		return '' !== $value ? $value : $fallback;
	}

	/**
	 * Daily counts, oldest first.
	 *
	 * @return array Date to counts.
	 */
	public static function stats() {
		$stats = get_option( self::STATS_OPTION, array() );
		return is_array( $stats ) ? $stats : array();
	}

	private static function save_stats( $stats ) {
		ksort( $stats );
		update_option( self::STATS_OPTION, array_slice( $stats, -self::STATS_DAYS, null, true ), false );
	}

	/**
	 * Totals for the last days.
	 *
	 * Players are counted per day, so one who comes back on three days counts three times.
	 *
	 * @param int $days Days, today included.
	 * @return array Totals, the busiest scenes, and acceptance per template.
	 */
	public static function summary( $days = 30 ) {
		$from   = wp_date( 'Y-m-d', time() - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
		$totals = array(
			'players'    => 0,
			'sessions'   => 0,
			'messages'   => 0,
			'answered'   => 0,
			'unanswered' => 0,
			'replies'    => 0,
			'failed'     => 0,
			'scenes'     => array(),
			'templates'  => array(),
		);
		foreach ( self::stats() as $day => $today ) {
			if ( (string) $day < $from || ! is_array( $today ) ) {
				continue;
			}
			foreach ( array( 'players', 'sessions', 'answered', 'unanswered', 'replies', 'failed' ) as $name ) {
				$totals[ $name ] += isset( $today[ $name ] ) ? (int) $today[ $name ] : 0;
			}
			$totals['messages'] += isset( $today['messages'] ) && is_array( $today['messages'] ) ? array_sum( $today['messages'] ) : 0;
			foreach ( array( 'scenes', 'templates' ) as $name ) {
				foreach ( isset( $today[ $name ] ) && is_array( $today[ $name ] ) ? $today[ $name ] : array() as $key => $value ) {
					if ( is_array( $value ) ) {
						foreach ( $value as $outcome => $count ) {
							$totals[ $name ][ $key ][ $outcome ] = ( isset( $totals[ $name ][ $key ][ $outcome ] ) ? $totals[ $name ][ $key ][ $outcome ] : 0 ) + (int) $count;
						}
					} else {
						$totals[ $name ][ $key ] = ( isset( $totals[ $name ][ $key ] ) ? $totals[ $name ][ $key ] : 0 ) + (int) $value;
					}
				}
			}
		}
		arsort( $totals['scenes'] );
		return $totals;
	}

	/**
	 * Send a player a text through the customer service message API.
	 *
	 * @param string $player  OpenID.
	 * @param string $text    Text.
	 * @param array  $options Settings.
	 * @return true|WP_Error
	 */
	public static function send( $player, $text, $options ) {
		$body   = wp_json_encode(
			array(
				'touser'  => $player,
				'msgtype' => 'text',
				'text'    => array( 'content' => self::cut_bytes( $text, self::MAX_REPLY_BYTES ) ),
			),
			JSON_UNESCAPED_UNICODE
		);
		$result = AI_Chat_Bedrock_WeChat_API::call(
			'message/custom/send',
			$body,
			array(
				'app_id' => self::app_id( $options ),
				'secret' => self::app_secret( $options ),
				'cache'  => self::ACCESS_KEY,
			),
			2,
			'application/json',
			// WeChat waits five seconds for the request this answers.
			( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) ) + self::SEND_SECONDS
		);
		if ( is_wp_error( $result ) ) {
			return self::note_error( $result );
		}
		self::note_sent();
		return true;
	}

	/**
	 * Remember the last time the address was called, and how it went.
	 *
	 * @param string $result off, stale, signature, checked or message.
	 */
	public static function note_contact( $result ) {
		$last    = self::contact();
		$refused = array( 'stale', 'signature', 'plaintext', 'replay' );
		if ( isset( $last['result'], $last['time'] ) && time() - (int) $last['time'] < MINUTE_IN_SECONDS && ( $last['result'] === $result || ( in_array( $last['result'], $refused, true ) && in_array( $result, $refused, true ) ) ) ) {
			return;
		}
		$last['result'] = $result;
		$last['time']   = time();
		update_option( self::CONTACT_OPTION, $last, false );
	}

	private static function note_sent() {
		$last = self::contact();
		if ( isset( $last['sent'] ) && time() - (int) $last['sent'] < MINUTE_IN_SECONDS && empty( $last['error'] ) ) {
			return;
		}
		$last['sent'] = time();
		unset( $last['error'] );
		update_option( self::CONTACT_OPTION, $last, false );
	}

	/**
	 * Remember why an answer could not be sent: the error code, and the address WeChat saw
	 * when that address is not in the game's IP whitelist. Not the message or the player.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error The same error.
	 */
	private static function note_error( $error ) {
		$last          = self::contact();
		$ip            = AI_Chat_Bedrock_WeChat_API::refused_ip( $error );
		$last['error'] = array(
			'code' => (string) $error->get_error_code(),
			'ip'   => $ip,
			'time' => time(),
		);
		update_option( self::CONTACT_OPTION, $last, false );
		return $error;
	}

	private static function contact() {
		$last = get_option( self::CONTACT_OPTION, array() );
		return is_array( $last ) ? $last : array();
	}

	/**
	 * The last contact, and the last answer that could not be sent, in words.
	 *
	 * @return string[] Lines; none when the address has never been called.
	 */
	public static function contact_summary() {
		$last  = self::contact();
		$lines = array();
		if ( ! empty( $last['time'] ) && ! empty( $last['result'] ) ) {
			$what   = array(
				'off'       => __( 'the address was called while the game was off, so nothing was taken', 'ai-chat-for-amazon-bedrock' ),
				'stale'     => __( 'a request came without a current timestamp, so it was refused', 'ai-chat-for-amazon-bedrock' ),
				'signature' => __( 'the signature did not match the token, so the request was refused; enter the same token here as in WeChat', 'ai-chat-for-amazon-bedrock' ),
				'plaintext' => __( 'a plaintext message was refused, because the mini game is set for safe mode (it has an EncodingAESKey, or shares the Official Account\'s token); choose safe mode in WeChat', 'ai-chat-for-amazon-bedrock' ),
				'replay'    => __( 'a signed address was used again with a different message, so it was refused', 'ai-chat-for-amazon-bedrock' ),
				'checked'   => __( 'WeChat checked the address, and the signature matched', 'ai-chat-for-amazon-bedrock' ),
				'message'   => __( 'a signed message arrived from WeChat', 'ai-chat-for-amazon-bedrock' ),
			);
			$result = isset( $what[ $last['result'] ] ) ? $what[ $last['result'] ] : (string) $last['result'];
			/* translators: 1: how long ago, such as 5 mins, 2: what happened. */
			$lines[] = sprintf( __( 'Last contact %1$s ago: %2$s.', 'ai-chat-for-amazon-bedrock' ), human_time_diff( (int) $last['time'], time() ), $result );
		}
		if ( ! empty( $last['error']['time'] ) ) {
			$error = $last['error'];
			$code  = isset( $error['code'] ) ? (string) $error['code'] : '';
			$ago   = human_time_diff( (int) $error['time'], time() );
			if ( ! empty( $error['ip'] ) ) {
				/* translators: 1: how long ago, 2: the server's IP address. */
				$lines[] = sprintf( __( 'An answer could not be sent %1$s ago because WeChat does not accept this server\'s address. Add %2$s to the IP whitelist under Development Management > Development Settings in the mini game\'s console.', 'ai-chat-for-amazon-bedrock' ), $ago, $error['ip'] );
			} elseif ( in_array( $code, array( 'wx_40125', 'wx_40013', 'wx_40164', 'wx_secret' ), true ) ) {
				/* translators: %s: how long ago. */
				$lines[] = sprintf( __( 'An answer could not be sent %s ago: WeChat did not accept the AppID and AppSecret. Enter them again from the mini game\'s console.', 'ai-chat-for-amazon-bedrock' ), $ago );
			} elseif ( in_array( $code, array( 'wx_45015', 'wx_45047' ), true ) ) {
				/* translators: %s: how long ago. */
				$lines[] = sprintf( __( 'An answer could not be sent %s ago: WeChat allows answers only for 48 hours after a player writes, and a few at a time.', 'ai-chat-for-amazon-bedrock' ), $ago );
			} else {
				/* translators: 1: how long ago, 2: error code. */
				$lines[] = sprintf( __( 'An answer could not be sent %1$s ago (%2$s).', 'ai-chat-for-amazon-bedrock' ), $ago, $code );
			}
		} elseif ( ! empty( $last['sent'] ) ) {
			/* translators: %s: how long ago. */
			$lines[] = sprintf( __( 'The last answer was sent %s ago.', 'ai-chat-for-amazon-bedrock' ), human_time_diff( (int) $last['sent'], time() ) );
		}
		return $lines;
	}

	/**
	 * A message or event from WeChat, in JSON or XML, as field names to values. Nested
	 * fields are arrays, and repeated XML elements are lists.
	 *
	 * @param string $body Body.
	 * @return array|null
	 */
	public static function parse( $body ) {
		$body = trim( (string) $body );
		if ( '' === $body ) {
			return null;
		}
		if ( '{' === $body[0] ) {
			$data = json_decode( $body, true, 8 );
			return is_array( $data ) ? $data : null;
		}
		// No document types, so no entities, external or otherwise.
		if ( false !== stripos( $body, '<!DOCTYPE' ) || false !== stripos( $body, '<!ENTITY' ) || ! function_exists( 'simplexml_load_string' ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return false !== $doc && 'xml' === $doc->getName() ? self::xml_fields( $doc, 0 ) : null;
	}

	private static function xml_fields( $node, $depth ) {
		$names = array();
		foreach ( $node->children() as $name => $child ) {
			$names[ $name ] = isset( $names[ $name ] ) ? $names[ $name ] + 1 : 1;
		}
		$fields = array();
		foreach ( $node->children() as $name => $child ) {
			$value = $child->count() > 0 && $depth < 4 ? self::xml_fields( $child, $depth + 1 ) : trim( (string) $child );
			if ( $names[ $name ] > 1 ) {
				$fields[ $name ][] = $value;
			} else {
				$fields[ $name ] = $value;
			}
		}
		return $fields;
	}

	private static function text( $message, $field ) {
		return isset( $message[ $field ] ) && is_scalar( $message[ $field ] ) ? trim( (string) $message[ $field ] ) : '';
	}

	private static function cut_bytes( $text, $bytes ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $bytes ) {
			return $text;
		}
		$text = substr( $text, 0, $bytes );
		// Not in the middle of a character.
		while ( '' !== $text && ! preg_match( '//u', $text ) ) {
			$text = substr( $text, 0, -1 );
		}
		return $text;
	}

	private static function secret_setting( $options, $key, $clean ) {
		$options = self::options( $options );
		return isset( $options[ $key ] ) && '' !== $options[ $key ] ? call_user_func( array( __CLASS__, $clean ), AI_Chat_Bedrock_Security::decrypt_secret( $options[ $key ] ) ) : '';
	}

	private static function raw( $body, $status = 200 ) {
		$response = new WP_REST_Response( (string) $body, $status );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-AICFAB-Raw', '1' );
		return $response;
	}

	/**
	 * Send this route's responses as plain text, which is what WeChat reads.
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
		echo esc_html( (string) $result->get_data() );
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
