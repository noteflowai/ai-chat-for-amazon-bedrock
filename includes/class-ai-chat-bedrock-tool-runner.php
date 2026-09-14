<?php
/**
 * Multi-round tool conversation runner.
 *
 * A model answer may request tools, and the tool results may lead to more tool
 * requests. Rounds are capped by the site policy, tool output is always framed as
 * untrusted data, and every round is subject to the same permission checks.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Tool_Runner {

	/**
	 * Run a conversation to completion, executing tool rounds as required.
	 *
	 * @param AI_Chat_Bedrock_AWS $aws      Bedrock client.
	 * @param array               $messages Conversation messages.
	 * @param string              $message  Original visitor message.
	 * @param callable|null       $on_delta Streaming delta callback, or null for buffered mode.
	 * @param callable|null       $on_round Called with the round number, tool count and tool names when a round runs.
	 * @return array Final response array, including a metadata-only step trace.
	 */
	public static function run( $aws, $messages, $message, $on_delta = null, $on_round = null ) {
		$max_rounds = class_exists( 'AI_Chat_Bedrock_Tool_Policy' ) ? AI_Chat_Bedrock_Tool_Policy::max_rounds() : 1;
		$payload    = apply_filters( 'ai_chat_bedrock_message_payload', array( 'messages' => $messages ), $message );
		$response   = self::invoke( $aws, $payload, $on_delta );
		$usage      = self::usage( array(), $response );
		$steps      = array();
		$truncated  = false;

		for ( $round = 1; $round <= $max_rounds; $round++ ) {
			if ( empty( $response['success'] ) ) {
				return self::finish( $response, $usage, $steps, $truncated );
			}

			$response['tool_round'] = $round;
			$response               = apply_filters( 'ai_chat_bedrock_process_response', $response, $message );

			if ( empty( $response['tool_calls'] ) ) {
				return self::finish( $response, $usage, $steps, $truncated );
			}

			$round_steps = self::describe_calls( $round, $response['tool_calls'] );
			$steps       = array_merge( $steps, $round_steps );

			if ( is_callable( $on_round ) ) {
				call_user_func(
					$on_round,
					$round,
					count( (array) $response['tool_calls'] ),
					wp_list_pluck( $round_steps, 'tool' ),
					wp_list_pluck( $round_steps, 'label' )
				);
			}

			$messages = AI_Chat_Bedrock_Chat_Request::tool_followup_messages( $messages, $response['tool_calls'] );
			$payload  = apply_filters( 'ai_chat_bedrock_message_payload', array( 'messages' => $messages ), $message );

			if ( $round === $max_rounds ) {
				// The final round answers with what it already has, so no further tools are offered.
				unset( $payload['tools'] );
				$truncated = true;
			}

			$response = self::invoke( $aws, $payload, $on_delta );
			$usage    = self::usage( $usage, $response );
		}

		if ( ! empty( $response['success'] ) ) {
			unset( $response['tool_calls'] );
		}
		return self::finish( $response, $usage, $steps, $truncated );
	}

	/**
	 * Summarize executed tool calls as metadata only.
	 *
	 * Parameter values and tool output are deliberately excluded, matching the
	 * audit log, so a trace can be shown without leaking data into the browser.
	 *
	 * @param int   $round Round number.
	 * @param array $calls Executed tool calls.
	 * @return array
	 */
	private static function describe_calls( $round, $calls ) {
		$steps = array();
		foreach ( (array) $calls as $call ) {
			if ( ! is_array( $call ) || empty( $call['name'] ) ) {
				continue;
			}
			$name  = (string) $call['name'];
			$error = isset( $call['error'] ) && is_array( $call['error'] ) ? $call['error'] : array();

			$clean_name = AI_Chat_Bedrock_Security::string_substr( sanitize_text_field( $name ), 0, 120 );

			$steps[] = array(
				'round'  => max( 1, (int) $round ),
				'tool'   => $clean_name,
				'label'  => self::readable_tool( $clean_name ),
				'status' => empty( $error ) ? 'ok' : 'error',
				'code'   => isset( $error['code'] ) ? sanitize_key( (string) $error['code'] ) : '',
			);
		}
		return $steps;
	}

	/**
	 * Turn an internal tool identifier into something a reader can follow.
	 *
	 * Ability tools arrive as wpability___core__get_site_info, and MCP tools as
	 * server___tool. Neither reads well in a chat, so they are normalized while the
	 * raw identifier stays available for support and the audit log.
	 *
	 * @param string $name Internal tool name.
	 * @return string
	 */
	private static function readable_tool( $name ) {
		$name = (string) $name;
		if ( false === strpos( $name, '___' ) ) {
			return $name;
		}

		list( $owner, $tool ) = array_pad( explode( '___', $name, 2 ), 2, '' );

		// 'wpability' is this plugin's own ability prefix, see AI_Chat_Bedrock_Abilities::TOOL_PREFIX.
		if ( 'wpability' === $owner ) {
			$tool = str_replace( '__', '/', $tool );
			return str_replace( '_', '-', $tool );
		}

		$tool = str_replace( '_', ' ', $tool );
		return '' === $owner ? $tool : $owner . ': ' . $tool;
	}

	private static function finish( $response, $usage, $steps, $truncated ) {
		$response = self::with_usage( $response, $usage );
		if ( ! is_array( $response ) ) {
			return $response;
		}
		if ( ! empty( $steps ) ) {
			$response['steps'] = $steps;
			if ( $truncated ) {
				$response['steps_truncated'] = true;
			}
		}
		return $response;
	}

	private static function invoke( $aws, $payload, $on_delta ) {
		if ( is_callable( $on_delta ) ) {
			return $aws->stream_chat_message( $payload, $on_delta );
		}
		return $aws->handle_chat_message( $payload );
	}

	private static function usage( $usage, $response ) {
		$usage = is_array( $usage ) ? $usage : array();
		if ( ! is_array( $response ) || empty( $response['usage'] ) || ! is_array( $response['usage'] ) ) {
			return $usage;
		}
		foreach ( $response['usage'] as $key => $value ) {
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$key           = sanitize_key( (string) $key );
			$usage[ $key ] = isset( $usage[ $key ] ) ? (int) $usage[ $key ] + (int) $value : (int) $value;
		}
		return $usage;
	}

	private static function with_usage( $response, $usage ) {
		if ( is_array( $response ) && ! empty( $usage ) ) {
			$response['usage'] = $usage;
		}
		if ( is_array( $response ) ) {
			unset( $response['tool_round'] );
		}
		return $response;
	}
}
