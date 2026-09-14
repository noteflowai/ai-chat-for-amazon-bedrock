<?php
/**
 * Audit log for MCP tool calls.
 *
 * Entries record metadata only: timestamp, user ID, tool name, outcome, duration
 * and the parameter keys that were sent. Parameter values, tool output and chat
 * content are never stored.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Tool_Log {

	const OPTION      = 'ai_chat_bedrock_tool_log';
	const MAX_ENTRIES = 200;

	/**
	 * Record one tool invocation.
	 *
	 * @param array $entry Entry data.
	 * @return void
	 */
	public static function record( $entry ) {
		if ( ! self::enabled() ) {
			return;
		}

		$entry = is_array( $entry ) ? $entry : array();
		$clean = array(
			'time'     => time(),
			'user'     => get_current_user_id(),
			'tool'     => self::clean_tool( isset( $entry['tool'] ) ? $entry['tool'] : '' ),
			'status'   => isset( $entry['status'] ) && 'error' === $entry['status'] ? 'error' : 'ok',
			'duration' => isset( $entry['duration'] ) ? max( 0, (int) $entry['duration'] ) : 0,
			'keys'     => self::clean_keys( isset( $entry['keys'] ) ? $entry['keys'] : array() ),
			'error'    => isset( $entry['error'] ) ? sanitize_key( (string) $entry['error'] ) : '',
			'round'    => isset( $entry['round'] ) ? max( 1, (int) $entry['round'] ) : 1,
		);

		$entries   = self::entries();
		$entries[] = $clean;
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Stored entries, oldest first.
	 *
	 * @return array
	 */
	public static function entries() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Stored entries, newest first.
	 *
	 * @param int $limit Maximum entries to return.
	 * @return array
	 */
	public static function recent( $limit = 50 ) {
		$entries = array_reverse( self::entries() );
		return array_slice( $entries, 0, max( 1, min( self::MAX_ENTRIES, absint( $limit ) ) ) );
	}

	/**
	 * Remove all entries.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Whether logging is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$enabled = (bool) get_option( 'ai_chat_bedrock_mcp_log_enabled', true );
		return (bool) apply_filters( 'ai_chat_bedrock_mcp_log_enabled', $enabled );
	}

	private static function clean_tool( $tool ) {
		$tool = trim( (string) $tool );
		if ( '' === $tool ) {
			return '';
		}
		$tool = preg_replace( '/[^A-Za-z0-9_.-]/', '', $tool );
		return substr( (string) $tool, 0, 120 );
	}

	private static function clean_keys( $keys ) {
		$clean = array();
		foreach ( (array) $keys as $key ) {
			if ( ! is_string( $key ) && ! is_int( $key ) ) {
				continue;
			}
			$key = sanitize_key( (string) $key );
			if ( '' !== $key ) {
				$clean[] = $key;
			}
			if ( count( $clean ) >= 12 ) {
				break;
			}
		}
		return $clean;
	}
}
