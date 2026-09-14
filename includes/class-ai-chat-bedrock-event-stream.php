<?php
/**
 * Incremental parser for the Amazon Bedrock response stream.
 *
 * Bedrock streams `application/vnd.amazon.eventstream` frames:
 *   total length (4) | headers length (4) | prelude CRC (4) | headers | payload | message CRC (4)
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Event_Stream {

	const MAX_FRAME_BYTES  = 2097152;
	const MAX_BUFFER_BYTES = 8388608;

	/**
	 * Extract complete event payloads from a streaming buffer.
	 *
	 * Consumed bytes are removed from the buffer. Incomplete trailing frames stay
	 * in place until more data arrives.
	 *
	 * @param string $buffer Raw stream buffer, passed by reference.
	 * @return array List of decoded event arrays with `type` and `payload` keys.
	 */
	public static function extract_events( &$buffer ) {
		$events = array();
		if ( ! is_string( $buffer ) ) {
			$buffer = '';
			return $events;
		}
		if ( strlen( $buffer ) > self::MAX_BUFFER_BYTES ) {
			$buffer = '';
			return $events;
		}

		$length = strlen( $buffer );
		while ( $length >= 12 ) {
			$prelude = unpack( 'Ntotal/Nheaders', substr( $buffer, 0, 8 ) );
			if ( ! is_array( $prelude ) ) {
				$buffer = '';
				break;
			}

			$total   = (int) $prelude['total'];
			$headers = (int) $prelude['headers'];
			if ( $total < 16 || $total > self::MAX_FRAME_BYTES || $headers < 0 || $headers > $total - 16 ) {
				$buffer = '';
				break;
			}
			if ( $length < $total ) {
				break;
			}

			$frame        = substr( $buffer, 0, $total );
			$buffer       = substr( $buffer, $total );
			$length       = $length - $total;
			$header_bytes = substr( $frame, 12, $headers );
			$payload      = substr( $frame, 12 + $headers, $total - $headers - 16 );
			$parsed       = self::decode_payload( $payload );

			if ( null !== $parsed ) {
				$events[] = array(
					'type'    => self::header_value( $header_bytes, ':event-type' ),
					'message' => self::header_value( $header_bytes, ':message-type' ),
					'error'   => self::header_value( $header_bytes, ':exception-type' ),
					'payload' => $parsed,
				);
			}
		}

		return $events;
	}

	/**
	 * Extract the incremental text produced by a streaming event.
	 *
	 * @param array  $payload  Decoded event payload.
	 * @param string $model_id Bedrock model identifier.
	 * @return string
	 */
	public static function text_delta( $payload, $model_id ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		if ( false !== strpos( $model_id, 'anthropic.claude' ) ) {
			if ( isset( $payload['type'] ) && 'content_block_delta' === $payload['type'] && isset( $payload['delta']['text'] ) ) {
				return (string) $payload['delta']['text'];
			}
			return '';
		}

		if ( isset( $payload['contentBlockDelta']['delta']['text'] ) ) {
			return (string) $payload['contentBlockDelta']['delta']['text'];
		}
		if ( isset( $payload['outputText'] ) ) {
			return (string) $payload['outputText'];
		}
		if ( isset( $payload['generation'] ) ) {
			return (string) $payload['generation'];
		}
		if ( isset( $payload['outputs'][0]['text'] ) ) {
			return (string) $payload['outputs'][0]['text'];
		}
		if ( isset( $payload['choices'][0]['delta']['content'] ) && is_string( $payload['choices'][0]['delta']['content'] ) ) {
			return $payload['choices'][0]['delta']['content'];
		}
		if ( isset( $payload['choices'][0]['text'] ) && is_string( $payload['choices'][0]['text'] ) ) {
			return $payload['choices'][0]['text'];
		}
		if ( isset( $payload['delta']['text'] ) && is_string( $payload['delta']['text'] ) ) {
			return (string) $payload['delta']['text'];
		}
		return '';
	}

	/**
	 * Extract token usage reported by a streaming event.
	 *
	 * @param array $payload Decoded event payload.
	 * @return array Associative array with input_tokens and output_tokens when present.
	 */
	public static function usage( $payload ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$candidates = array();
		if ( isset( $payload['usage'] ) && is_array( $payload['usage'] ) ) {
			$candidates[] = $payload['usage'];
		}
		if ( isset( $payload['metadata']['usage'] ) && is_array( $payload['metadata']['usage'] ) ) {
			$candidates[] = $payload['metadata']['usage'];
		}
		if ( isset( $payload['amazon-bedrock-invocationMetrics'] ) && is_array( $payload['amazon-bedrock-invocationMetrics'] ) ) {
			$candidates[] = $payload['amazon-bedrock-invocationMetrics'];
		}

		$usage = array();
		foreach ( $candidates as $candidate ) {
			$input  = self::first_numeric( $candidate, array( 'input_tokens', 'inputTokens', 'inputTokenCount', 'prompt_tokens' ) );
			$output = self::first_numeric( $candidate, array( 'output_tokens', 'outputTokens', 'outputTokenCount', 'completion_tokens' ) );
			if ( null !== $input ) {
				$usage['input_tokens'] = $input;
			}
			if ( null !== $output ) {
				$usage['output_tokens'] = $output;
			}
		}
		return $usage;
	}

	/**
	 * Extract streamed tool-call fragments for supported models.
	 *
	 * @param array  $payload  Decoded event payload.
	 * @param string $model_id Bedrock model identifier.
	 * @return array|null Fragment describing a tool call start or argument delta.
	 */
	public static function tool_fragment( $payload, $model_id ) {
		if ( ! is_array( $payload ) || false === strpos( $model_id, 'anthropic.claude' ) ) {
			return null;
		}

		$type = isset( $payload['type'] ) ? $payload['type'] : '';
		if ( 'content_block_start' === $type && isset( $payload['content_block']['type'] ) && 'tool_use' === $payload['content_block']['type'] ) {
			return array(
				'stage' => 'start',
				'index' => isset( $payload['index'] ) ? (int) $payload['index'] : 0,
				'id'    => isset( $payload['content_block']['id'] ) ? (string) $payload['content_block']['id'] : '',
				'name'  => isset( $payload['content_block']['name'] ) ? (string) $payload['content_block']['name'] : '',
			);
		}
		if ( 'content_block_delta' === $type && isset( $payload['delta']['type'] ) && 'input_json_delta' === $payload['delta']['type'] ) {
			return array(
				'stage'   => 'delta',
				'index'   => isset( $payload['index'] ) ? (int) $payload['index'] : 0,
				'partial' => isset( $payload['delta']['partial_json'] ) ? (string) $payload['delta']['partial_json'] : '',
			);
		}
		return null;
	}

	private static function decode_payload( $payload ) {
		$decoded = json_decode( (string) $payload, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		if ( isset( $decoded['bytes'] ) && is_string( $decoded['bytes'] ) ) {
			$inner = base64_decode( $decoded['bytes'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the Bedrock event stream payload, not obfuscation.
			if ( false === $inner ) {
				return null;
			}
			$chunk = json_decode( $inner, true );
			return is_array( $chunk ) ? $chunk : null;
		}
		return $decoded;
	}

	private static function header_value( $header_bytes, $name ) {
		$offset = 0;
		$length = strlen( $header_bytes );

		while ( $offset + 1 <= $length ) {
			$name_length = ord( $header_bytes[ $offset ] );
			++$offset;
			if ( $offset + $name_length + 1 > $length ) {
				return '';
			}
			$header_name = substr( $header_bytes, $offset, $name_length );
			$offset     += $name_length;
			$value_type  = ord( $header_bytes[ $offset ] );
			++$offset;

			if ( 7 === $value_type ) {
				if ( $offset + 2 > $length ) {
					return '';
				}
				$size    = unpack( 'n', substr( $header_bytes, $offset, 2 ) );
				$offset += 2;
				$value   = substr( $header_bytes, $offset, (int) $size[1] );
				$offset += (int) $size[1];
				if ( $header_name === $name ) {
					return (string) $value;
				}
				continue;
			}

			$skip = self::value_size( $value_type );
			if ( null === $skip ) {
				return '';
			}
			$offset += $skip;
		}
		return '';
	}

	private static function value_size( $value_type ) {
		switch ( $value_type ) {
			case 0:
			case 1:
				return 0;
			case 2:
				return 1;
			case 3:
				return 2;
			case 4:
				return 4;
			case 5:
			case 8:
				return 8;
			case 9:
				return 16;
			default:
				return null;
		}
	}

	private static function first_numeric( $data, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
				return (int) $data[ $key ];
			}
		}
		return null;
	}
}
