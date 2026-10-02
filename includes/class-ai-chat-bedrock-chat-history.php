<?php
/**
 * Conversation memory for the chat.
 *
 * Off by default. "Tab" keeps the conversation in the visitor's own browser tab while they move
 * between pages, and nothing is stored on the site. "Account" also keeps a signed-in visitor's
 * conversation on the site, so it is there on their next visit and on another device. It is
 * stored per site in the user's own options, kept for the configured number of days, included
 * in personal data exports and erasures, and deleted when the setting is switched off.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Chat_History {

	/**
	 * User option holding the conversations. Stored per site, with the blog prefix.
	 */
	const OPTION = 'aicfab_chat_history';

	/**
	 * User option holding the time of the oldest stored message, so expired ones can be found.
	 */
	const OPTION_OLDEST = 'aicfab_chat_history_oldest';

	const REST_ROUTE   = '/history';
	const CRON_HOOK    = 'ai_chat_bedrock_prune_chat_history';
	const MODES        = array( '', 'tab', 'account' );
	const MAX_MESSAGES = 30;
	const MAX_THREADS  = 5;
	const MAX_TEXT     = 6000;
	const MAX_SOURCES  = 5;
	const DEFAULT_DAYS = 30;
	const MAX_DAYS     = 365;
	const PRUNE_BATCH  = 100;
	const RATE_LIMIT   = 30;

	/**
	 * The configured memory: '' (off), 'tab' or 'account'.
	 *
	 * @param array|null $options Settings, or null for the saved ones.
	 * @return string
	 */
	public static function mode( $options = null ) {
		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
		}
		$mode = is_array( $options ) && isset( $options['chat_memory'] ) ? (string) $options['chat_memory'] : '';
		return in_array( $mode, self::MODES, true ) ? $mode : '';
	}

	/**
	 * Days a stored conversation is kept.
	 *
	 * @param array|null $options Settings, or null for the saved ones.
	 * @return int
	 */
	public static function retention_days( $options = null ) {
		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
		}
		$days = is_array( $options ) && isset( $options['chat_memory_days'] ) ? absint( $options['chat_memory_days'] ) : self::DEFAULT_DAYS;
		return max( 1, min( self::MAX_DAYS, $days > 0 ? $days : self::DEFAULT_DAYS ) );
	}

	/**
	 * Whether this user's conversations are stored on the site.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function saves_for( $user_id ) {
		return absint( $user_id ) > 0 && 'account' === self::mode();
	}

	/**
	 * The stored conversation for one chat profile, oldest first.
	 *
	 * @param int    $user_id User ID.
	 * @param string $profile Chat profile key, '' for the default chat.
	 * @return array List of messages with role, content, time and, for answers, sources.
	 */
	public static function get( $user_id, $profile = '' ) {
		$threads = self::load( $user_id );
		$key     = self::thread_key( $profile );
		return isset( $threads[ $key ] ) ? $threads[ $key ] : array();
	}

	/**
	 * Store one question and its answer.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $profile  Chat profile key.
	 * @param string $question The question as sent to the model.
	 * @param string $answer   The answer shown.
	 * @param array  $sources  Links listed under the answer.
	 * @return bool Whether anything was stored.
	 */
	public static function append( $user_id, $profile, $question, $answer, $sources = array() ) {
		$user_id = absint( $user_id );
		if ( ! self::saves_for( $user_id ) ) {
			return false;
		}
		$question = self::clip( $question );
		$answer   = self::clip( $answer );
		if ( '' === $question || '' === $answer ) {
			return false;
		}

		$now     = time();
		$threads = self::load( $user_id );
		$key     = self::thread_key( $profile );
		$thread  = isset( $threads[ $key ] ) ? $threads[ $key ] : array();
		$reply   = array(
			'role'    => 'assistant',
			'content' => $answer,
			'time'    => $now,
		);
		$links   = self::sanitize_sources( $sources );
		if ( ! empty( $links ) ) {
			$reply['sources'] = $links;
		}
		$thread[] = array(
			'role'    => 'user',
			'content' => $question,
			'time'    => $now,
		);
		$thread[] = $reply;

		// Drop whole exchanges, so the stored conversation never starts with an answer.
		$thread = array_slice( $thread, -self::MAX_MESSAGES );
		if ( isset( $thread[0]['role'] ) && 'assistant' === $thread[0]['role'] ) {
			array_shift( $thread );
		}

		unset( $threads[ $key ] );
		$threads[ $key ] = $thread;
		// The conversations used least recently go first; the one just written is last.
		$threads = array_slice( $threads, -self::MAX_THREADS, null, true );
		self::save( $user_id, $threads );
		return true;
	}

	/**
	 * Delete a user's stored conversation for one profile, or all of them.
	 *
	 * @param int         $user_id User ID.
	 * @param string|null $profile Chat profile key, or null for every profile.
	 * @return int Number of messages removed.
	 */
	public static function clear( $user_id, $profile = null ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return 0;
		}
		$threads = self::load( $user_id );
		$removed = 0;
		if ( null === $profile ) {
			foreach ( $threads as $thread ) {
				$removed += count( $thread );
			}
			$threads = array();
		} else {
			$key = self::thread_key( $profile );
			if ( isset( $threads[ $key ] ) ) {
				$removed = count( $threads[ $key ] );
				unset( $threads[ $key ] );
			}
		}
		self::save( $user_id, $threads );
		return $removed;
	}

	/**
	 * Delete every stored conversation on this site.
	 *
	 * Runs when account memory is switched off, so nothing is kept that is no longer used.
	 */
	public static function forget_all() {
		delete_metadata( 'user', 0, self::meta_key( self::OPTION ), '', true );
		delete_metadata( 'user', 0, self::meta_key( self::OPTION_OLDEST ), '', true );
	}

	/**
	 * Remove stored messages older than the retention period, a batch of users at a time.
	 *
	 * @return int Number of users whose conversations were pruned.
	 */
	public static function prune_expired() {
		if ( 'account' !== self::mode() ) {
			return 0;
		}
		$query = new WP_User_Query(
			array(
				'fields'      => 'ID',
				'number'      => self::PRUNE_BATCH,
				'count_total' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily batch, bounded by number.
				'meta_query'  => array(
					array(
						'key'     => self::meta_key( self::OPTION_OLDEST ),
						'value'   => self::cutoff(),
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$users = (array) $query->get_results();
		foreach ( $users as $user_id ) {
			self::save( (int) $user_id, self::load( (int) $user_id ) );
		}
		return count( $users );
	}

	/**
	 * Keep the daily pruning scheduled while account memory is on.
	 */
	public static function schedule() {
		$wanted = 'account' === self::mode();
		$next   = wp_next_scheduled( self::CRON_HOOK );
		if ( $wanted && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
			return;
		}
		if ( ! $wanted && $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	/**
	 * Delete stored conversations when account memory is switched off.
	 *
	 * @param mixed $old_value Settings before the save.
	 * @param mixed $value     Settings after the save.
	 */
	public static function settings_updated( $old_value, $value ) {
		if ( 'account' === self::mode( is_array( $old_value ) ? $old_value : array() ) && 'account' !== self::mode( is_array( $value ) ? $value : array() ) ) {
			self::forget_all();
		}
	}

	/**
	 * Register the history routes.
	 */
	public function register_routes() {
		$args = array(
			'profile' => array(
				'type'    => 'string',
				'default' => '',
			),
		);
		register_rest_route(
			AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $args,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'handle_delete' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $args,
				),
			)
		);
	}

	/**
	 * Only a signed-in user, and only their own conversation.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'aicfab_forbidden', __( 'Please sign in to use the chat.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 401 ) );
		}
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'history', self::RATE_LIMIT ) ) {
			return new WP_Error( 'aicfab_rate_limited', __( 'Too many requests. Please wait a moment.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * The signed-in user's stored conversation.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response
	 */
	public function handle_get( $request ) {
		$user_id  = get_current_user_id();
		$enabled  = self::saves_for( $user_id );
		$profile  = AI_Chat_Bedrock_Profiles::sanitize_key( (string) $request->get_param( 'profile' ) );
		$data     = array(
			'enabled'  => $enabled,
			'messages' => $enabled ? self::get( $user_id, $profile ) : array(),
		);
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * Delete the signed-in user's stored conversation. Allowed even with memory off, so
	 * nobody depends on the setting to remove their own data.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response
	 */
	public function handle_delete( $request ) {
		$profile = AI_Chat_Bedrock_Profiles::sanitize_key( (string) $request->get_param( 'profile' ) );
		$removed = self::clear( get_current_user_id(), $profile );
		return new WP_REST_Response( array( 'removed' => $removed ), 200 );
	}

	/**
	 * Register the exporter for personal data requests.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['ai-chat-for-amazon-bedrock-history'] = array(
			'exporter_friendly_name' => __( 'AI chat history', 'ai-chat-for-amazon-bedrock' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser for personal data requests.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['ai-chat-for-amazon-bedrock-history'] = array(
			'eraser_friendly_name' => __( 'AI chat history', 'ai-chat-for-amazon-bedrock' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export a user's stored conversations.
	 *
	 * @param string $email Email address from the request.
	 * @param int    $page  Page number, unused because a user's history is capped and small.
	 * @return array
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public static function export_personal_data( $email, $page = 1 ) {
		$user  = get_user_by( 'email', $email );
		$items = array();
		if ( $user ) {
			foreach ( self::load( $user->ID ) as $key => $thread ) {
				foreach ( $thread as $index => $message ) {
					$items[] = array(
						'group_id'    => 'ai-chat-bedrock-history',
						'group_label' => __( 'AI chat history', 'ai-chat-for-amazon-bedrock' ),
						'item_id'     => 'aicfab-history-' . $key . '-' . $index,
						'data'        => array(
							array(
								'name'  => __( 'Chat', 'ai-chat-for-amazon-bedrock' ),
								'value' => 'default' === $key ? __( 'Default', 'ai-chat-for-amazon-bedrock' ) : $key,
							),
							array(
								'name'  => __( 'Date', 'ai-chat-for-amazon-bedrock' ),
								'value' => gmdate( 'Y-m-d H:i:s', (int) $message['time'] ) . ' UTC',
							),
							array(
								'name'  => 'user' === $message['role'] ? __( 'Question', 'ai-chat-for-amazon-bedrock' ) : __( 'Answer', 'ai-chat-for-amazon-bedrock' ),
								'value' => $message['content'],
							),
						),
					);
				}
			}
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase a user's stored conversations.
	 *
	 * @param string $email Email address from the request.
	 * @param int    $page  Page number, unused.
	 * @return array
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public static function erase_personal_data( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		return array(
			'items_removed'  => $user ? self::clear( $user->ID ) > 0 : false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * A user's conversations, with expired and malformed messages removed.
	 *
	 * @param int $user_id User ID.
	 * @return array Map of thread key to messages.
	 */
	private static function load( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return array();
		}
		$stored  = get_user_meta( $user_id, self::meta_key( self::OPTION ), true );
		$cutoff  = self::cutoff();
		$threads = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $thread ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $thread ) ) {
				continue;
			}
			$clean = array();
			foreach ( $thread as $message ) {
				if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'], $message['time'] ) || ! in_array( $message['role'], array( 'user', 'assistant' ), true ) || (int) $message['time'] < $cutoff ) {
					continue;
				}
				$item = array(
					'role'    => $message['role'],
					'content' => (string) $message['content'],
					'time'    => (int) $message['time'],
				);
				if ( 'assistant' === $message['role'] && ! empty( $message['sources'] ) ) {
					$item['sources'] = self::sanitize_sources( $message['sources'] );
				}
				$clean[] = $item;
			}
			if ( isset( $clean[0] ) && 'assistant' === $clean[0]['role'] ) {
				array_shift( $clean );
			}
			if ( ! empty( $clean ) ) {
				$threads[ $key ] = $clean;
			}
		}
		return $threads;
	}

	/**
	 * Write a user's conversations, or remove the options when there are none.
	 *
	 * @param int   $user_id User ID.
	 * @param array $threads Map of thread key to messages.
	 */
	private static function save( $user_id, $threads ) {
		if ( empty( $threads ) ) {
			delete_user_meta( $user_id, self::meta_key( self::OPTION ) );
			delete_user_meta( $user_id, self::meta_key( self::OPTION_OLDEST ) );
			return;
		}
		$oldest = PHP_INT_MAX;
		foreach ( $threads as $thread ) {
			foreach ( $thread as $message ) {
				$oldest = min( $oldest, (int) $message['time'] );
			}
		}
		update_user_meta( $user_id, self::meta_key( self::OPTION ), $threads );
		update_user_meta( $user_id, self::meta_key( self::OPTION_OLDEST ), $oldest );
	}

	/**
	 * The per-site meta key, as update_user_option() would name it.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private static function meta_key( $name ) {
		global $wpdb;
		return ( isset( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) ? $wpdb->get_blog_prefix() : 'wp_' ) . $name;
	}

	private static function thread_key( $profile ) {
		$key = sanitize_key( (string) $profile );
		return '' === $key ? 'default' : $key;
	}

	private static function cutoff() {
		return time() - self::retention_days() * DAY_IN_SECONDS;
	}

	private static function clip( $text ) {
		$text = trim( (string) $text );
		if ( AI_Chat_Bedrock_Security::string_length( $text ) > self::MAX_TEXT ) {
			$text = AI_Chat_Bedrock_Security::string_substr( $text, 0, self::MAX_TEXT - 1 ) . '…';
		}
		return $text;
	}

	private static function sanitize_sources( $sources ) {
		$clean = array();
		foreach ( is_array( $sources ) ? $sources : array() as $source ) {
			$url = is_array( $source ) && isset( $source['url'] ) ? esc_url_raw( (string) $source['url'], array( 'http', 'https' ) ) : '';
			if ( '' === $url ) {
				continue;
			}
			$clean[] = array(
				'title' => isset( $source['title'] ) ? sanitize_text_field( (string) $source['title'] ) : '',
				'url'   => $url,
			);
			if ( count( $clean ) >= self::MAX_SOURCES ) {
				break;
			}
		}
		return $clean;
	}
}
