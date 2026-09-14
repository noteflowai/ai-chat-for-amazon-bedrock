<?php
/**
 * Optional conversation logging.
 *
 * Disabled by default. When a site owner enables it, question and answer text is
 * stored so conversations can be reviewed for quality and moderation. Retention is
 * bounded by both age and row count, entries can be deleted at any time, and the
 * behaviour is disclosed in the plugin readme so administrators can inform users.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Conversations {

	const OPTION_LOG       = 'ai_chat_bedrock_conversations';
	const OPTION_ENABLED   = 'ai_chat_bedrock_log_conversations';
	const OPTION_RETENTION = 'ai_chat_bedrock_log_retention_days';

	const MAX_ENTRIES  = 200;
	const MAX_TEXT     = 2000;
	const DEFAULT_DAYS = 14;
	const MAX_DAYS     = 90;

	/**
	 * Whether conversation logging is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$enabled = (bool) get_option( self::OPTION_ENABLED, false );
		return (bool) apply_filters( 'ai_chat_bedrock_log_conversations', $enabled );
	}

	/**
	 * Retention window in days.
	 *
	 * @return int
	 */
	public static function retention_days() {
		$days = absint( get_option( self::OPTION_RETENTION, self::DEFAULT_DAYS ) );
		$days = 0 === $days ? self::DEFAULT_DAYS : $days;
		return (int) max( 1, min( self::MAX_DAYS, $days ) );
	}

	/**
	 * Record one exchange.
	 *
	 * @param string $question Visitor message.
	 * @param string $answer   Model answer.
	 * @param array  $context  Optional usage, source and model details.
	 * @return void
	 */
	public static function record( $question, $answer, $context = array() ) {
		if ( ! self::enabled() ) {
			return;
		}

		$question = trim( (string) $question );
		$answer   = trim( (string) $answer );
		if ( '' === $question && '' === $answer ) {
			return;
		}

		$context = is_array( $context ) ? $context : array();
		$usage   = isset( $context['usage'] ) && is_array( $context['usage'] ) ? $context['usage'] : array();
		$source  = isset( $context['source'] ) ? sanitize_key( $context['source'] ) : 'chat';
		$model   = isset( $context['model'] ) ? sanitize_text_field( (string) $context['model'] ) : '';

		$entry = array(
			'id'            => self::new_id(),
			'time'          => time(),
			'user'          => get_current_user_id(),
			'source'        => in_array( $source, array( 'chat', 'stream', 'editor', 'ability' ), true ) ? $source : 'chat',
			'model'         => AI_Chat_Bedrock_Security::string_substr( $model, 0, 120 ),
			'question'      => AI_Chat_Bedrock_Security::string_substr( wp_strip_all_tags( $question ), 0, self::MAX_TEXT ),
			'answer'        => AI_Chat_Bedrock_Security::string_substr( wp_strip_all_tags( $answer ), 0, self::MAX_TEXT ),
			'input_tokens'  => isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0,
			'output_tokens' => isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0,
		);

		$entries   = self::all();
		$entries[] = $entry;
		update_option( self::OPTION_LOG, self::prune( $entries ), false );

		return $entry['id'];
	}

	/**
	 * Random identifier for one stored exchange.
	 *
	 * Random rather than sequential so an identifier cannot be guessed to rate
	 * or probe somebody else's conversation.
	 *
	 * @return string
	 */
	private static function new_id() {
		return substr( bin2hex( random_bytes( 12 ) ), 0, 24 );
	}

	/**
	 * Record a visitor rating for one stored exchange.
	 *
	 * Only the rating is stored. Nothing about the rater is added, and an
	 * unknown identifier is refused without revealing whether it ever existed.
	 *
	 * @param string $id     Entry identifier.
	 * @param int    $rating 1 for helpful, -1 for not helpful.
	 * @return bool Whether a rating was stored.
	 */
	public static function rate( $id, $rating ) {
		if ( ! self::enabled() ) {
			return false;
		}

		$id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $id ) );
		if ( 24 !== strlen( (string) $id ) ) {
			return false;
		}

		$rating = (int) $rating;
		if ( 1 !== $rating && -1 !== $rating ) {
			return false;
		}

		$entries = self::all();
		$found   = false;
		foreach ( $entries as $index => $entry ) {
			if ( ! empty( $entry['id'] ) && hash_equals( (string) $entry['id'], (string) $id ) ) {
				$entries[ $index ]['rating']      = $rating;
				$entries[ $index ]['rating_time'] = time();
				$found                            = true;
				break;
			}
		}

		if ( ! $found ) {
			return false;
		}

		update_option( self::OPTION_LOG, $entries, false );
		return true;
	}

	/**
	 * Search stored entries with pagination.
	 *
	 * @param array $args search, source, rating, page, per_page.
	 * @return array {
	 *     @type array $entries Matching entries, newest first.
	 *     @type int   $total   Total matches.
	 *     @type int   $page    Current page.
	 *     @type int   $pages   Total pages.
	 * }
	 */
	public static function query( $args = array() ) {
		$args = is_array( $args ) ? $args : array();

		$search   = isset( $args['search'] ) ? trim( wp_strip_all_tags( (string) $args['search'] ) ) : '';
		$source   = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : '';
		$rating   = isset( $args['rating'] ) ? (string) $args['rating'] : '';
		$page     = max( 1, isset( $args['page'] ) ? absint( $args['page'] ) : 1 );
		$per_page = max( 5, min( 100, isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20 ) );

		$entries = array_reverse( self::all() );
		$matched = array();

		foreach ( $entries as $entry ) {
			if ( '' !== $source && ( ! isset( $entry['source'] ) || $entry['source'] !== $source ) ) {
				continue;
			}
			if ( 'up' === $rating && 1 !== (int) ( $entry['rating'] ?? 0 ) ) {
				continue;
			}
			if ( 'down' === $rating && -1 !== (int) ( $entry['rating'] ?? 0 ) ) {
				continue;
			}
			if ( 'none' === $rating && 0 !== (int) ( $entry['rating'] ?? 0 ) ) {
				continue;
			}
			if ( '' !== $search ) {
				$haystack = ( $entry['question'] ?? '' ) . ' ' . ( $entry['answer'] ?? '' ) . ' ' . ( $entry['model'] ?? '' );
				if ( false === stripos( $haystack, $search ) ) {
					continue;
				}
			}
			$matched[] = $entry;
		}

		$total = count( $matched );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = min( $page, $pages );

		return array(
			'entries'  => array_slice( $matched, ( $page - 1 ) * $per_page, $per_page ),
			'total'    => $total,
			'page'     => $page,
			'pages'    => $pages,
			'per_page' => $per_page,
		);
	}

	/**
	 * Rating totals for the admin summary.
	 *
	 * @return array
	 */
	public static function ratings() {
		$up   = 0;
		$down = 0;
		foreach ( self::all() as $entry ) {
			$rating = (int) ( $entry['rating'] ?? 0 );
			if ( 1 === $rating ) {
				++$up;
			} elseif ( -1 === $rating ) {
				++$down;
			}
		}
		return array(
			'up'   => $up,
			'down' => $down,
		);
	}

	/**
	 * Stored entries as CSV rows, newest first.
	 *
	 * @return array Rows including a header row.
	 */
	public static function export_rows() {
		$rows = array(
			array( 'time_utc', 'user_id', 'source', 'model', 'input_tokens', 'output_tokens', 'rating', 'question', 'answer' ),
		);
		foreach ( array_reverse( self::all() ) as $entry ) {
			$rows[] = array(
				gmdate( 'Y-m-d H:i:s', (int) ( $entry['time'] ?? 0 ) ),
				(int) ( $entry['user'] ?? 0 ),
				(string) ( $entry['source'] ?? '' ),
				(string) ( $entry['model'] ?? '' ),
				(int) ( $entry['input_tokens'] ?? 0 ),
				(int) ( $entry['output_tokens'] ?? 0 ),
				(int) ( $entry['rating'] ?? 0 ),
				(string) ( $entry['question'] ?? '' ),
				(string) ( $entry['answer'] ?? '' ),
			);
		}
		return $rows;
	}

	/**
	 * Stored entries, newest first.
	 *
	 * @param int $limit Maximum entries.
	 * @return array
	 */
	public static function recent( $limit = 50 ) {
		$entries = array_reverse( self::all() );
		return array_slice( $entries, 0, max( 1, min( self::MAX_ENTRIES, absint( $limit ) ) ) );
	}

	/**
	 * Totals for the admin summary.
	 *
	 * @return array
	 */
	public static function summary() {
		$entries = self::all();
		$input   = 0;
		$output  = 0;
		foreach ( $entries as $entry ) {
			$input  += isset( $entry['input_tokens'] ) ? (int) $entry['input_tokens'] : 0;
			$output += isset( $entry['output_tokens'] ) ? (int) $entry['output_tokens'] : 0;
		}
		return array(
			'count'         => count( $entries ),
			'input_tokens'  => $input,
			'output_tokens' => $output,
			'oldest'        => isset( $entries[0]['time'] ) ? (int) $entries[0]['time'] : 0,
		);
	}

	/**
	 * Delete all stored conversations.
	 */
	public static function clear() {
		delete_option( self::OPTION_LOG );
	}

	/**
	 * Register the plugin with WordPress' personal data tools.
	 *
	 * Site owners are responsible for chat content they choose to store, so the
	 * stored exchanges must be reachable through the standard export and erase
	 * requests rather than only through this plugin's own screens.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['ai-chat-for-amazon-bedrock'] = array(
			'exporter_friendly_name' => __( 'AI chat conversations', 'ai-chat-for-amazon-bedrock' ),
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
		$erasers['ai-chat-for-amazon-bedrock'] = array(
			'eraser_friendly_name' => __( 'AI chat conversations', 'ai-chat-for-amazon-bedrock' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export stored exchanges for one email address.
	 *
	 * @param string $email Email address from the request.
	 * @param int    $page  Page number, unused because the log is capped and small.
	 * @return array
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public static function export_personal_data( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$items = array();
		foreach ( array_reverse( self::all() ) as $index => $entry ) {
			if ( ! isset( $entry['user'] ) || (int) $entry['user'] !== (int) $user->ID ) {
				continue;
			}

			$items[] = array(
				'group_id'    => 'ai-chat-bedrock-conversations',
				'group_label' => __( 'AI chat conversations', 'ai-chat-for-amazon-bedrock' ),
				'item_id'     => 'aicfab-conversation-' . ( isset( $entry['id'] ) && '' !== $entry['id'] ? (string) $entry['id'] : (string) $index ),
				'data'        => array(
					array(
						'name'  => __( 'Date', 'ai-chat-for-amazon-bedrock' ),
						'value' => gmdate( 'Y-m-d H:i:s', (int) ( $entry['time'] ?? 0 ) ) . ' UTC',
					),
					array(
						'name'  => __( 'Source', 'ai-chat-for-amazon-bedrock' ),
						'value' => (string) ( $entry['source'] ?? '' ),
					),
					array(
						'name'  => __( 'Model', 'ai-chat-for-amazon-bedrock' ),
						'value' => (string) ( $entry['model'] ?? '' ),
					),
					array(
						'name'  => __( 'Question', 'ai-chat-for-amazon-bedrock' ),
						'value' => (string) ( $entry['question'] ?? '' ),
					),
					array(
						'name'  => __( 'Answer', 'ai-chat-for-amazon-bedrock' ),
						'value' => (string) ( $entry['answer'] ?? '' ),
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase stored exchanges for one email address.
	 *
	 * @param string $email Email address from the request.
	 * @param int    $page  Page number, unused because the log is capped and small.
	 * @return array
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public static function erase_personal_data( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed = self::forget_user( $user->ID );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Remove entries for one user, for privacy requests.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of removed entries.
	 */
	public static function forget_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return 0;
		}

		$entries = self::all();
		$kept    = array();
		foreach ( $entries as $entry ) {
			if ( isset( $entry['user'] ) && (int) $entry['user'] === $user_id ) {
				continue;
			}
			$kept[] = $entry;
		}

		$removed = count( $entries ) - count( $kept );
		if ( $removed > 0 ) {
			update_option( self::OPTION_LOG, $kept, false );
		}
		return $removed;
	}

	/**
	 * Apply retention rules.
	 */
	public static function enforce_retention() {
		$entries = self::all();
		$pruned  = self::prune( $entries );
		if ( count( $pruned ) !== count( $entries ) ) {
			update_option( self::OPTION_LOG, $pruned, false );
		}
	}

	private static function all() {
		$entries = get_option( self::OPTION_LOG, array() );
		return is_array( $entries ) ? array_values( $entries ) : array();
	}

	private static function prune( $entries ) {
		$cutoff = time() - ( self::retention_days() * DAY_IN_SECONDS );
		$clean  = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['time'] ) || (int) $entry['time'] < $cutoff ) {
				continue;
			}
			$clean[] = array(
				'id'            => isset( $entry['id'] ) && preg_match( '/^[a-f0-9]{24}$/', (string) $entry['id'] ) ? (string) $entry['id'] : '',
				'time'          => (int) $entry['time'],
				'user'          => isset( $entry['user'] ) ? (int) $entry['user'] : 0,
				'source'        => isset( $entry['source'] ) ? sanitize_key( $entry['source'] ) : 'chat',
				'model'         => isset( $entry['model'] ) ? (string) $entry['model'] : '',
				'question'      => isset( $entry['question'] ) ? (string) $entry['question'] : '',
				'answer'        => isset( $entry['answer'] ) ? (string) $entry['answer'] : '',
				'input_tokens'  => isset( $entry['input_tokens'] ) ? (int) $entry['input_tokens'] : 0,
				'output_tokens' => isset( $entry['output_tokens'] ) ? (int) $entry['output_tokens'] : 0,
				'rating'        => in_array( (int) ( $entry['rating'] ?? 0 ), array( 1, -1 ), true ) ? (int) $entry['rating'] : 0,
				'rating_time'   => isset( $entry['rating_time'] ) ? (int) $entry['rating_time'] : 0,
			);
		}

		if ( count( $clean ) > self::MAX_ENTRIES ) {
			$clean = array_slice( $clean, -self::MAX_ENTRIES );
		}
		return $clean;
	}
}
