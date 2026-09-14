<?php
/**
 * Server-Sent Events output.
 *
 * Shared by the chat stream and the content generator so both emit identical
 * framing, headers and buffering behaviour. Keeping this in one place avoids the
 * two paths drifting apart, which is how proxy-buffering bugs usually appear.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_SSE {

	/**
	 * Send the response headers and disable every buffering layer we control.
	 */
	public function open() {
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv
		}
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged
		@ini_set( 'output_buffering', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged
		@ini_set( 'implicit_flush', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate, private' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );
		header( 'X-Content-Type-Options: nosniff' );

		echo ': ' . esc_html__( 'stream open', 'ai-chat-for-amazon-bedrock' ) . "\n\n";
		$this->flush_output();
	}

	/**
	 * Emit one named event with a JSON payload.
	 *
	 * @param string $type Event name.
	 * @param array  $data Payload.
	 */
	public function send( $type, $data ) {
		$data         = is_array( $data ) ? $data : array();
		$data['type'] = sanitize_key( $type );
		$encoded      = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			return;
		}

		echo 'event: ' . sanitize_key( $type ) . "\n";
		echo 'data: ' . $encoded . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->flush_output();
	}

	/**
	 * Close the stream and end the request.
	 *
	 * A stream response cannot be followed by anything else, so this exits.
	 */
	public function close() {
		echo "event: close\ndata: {}\n\n";
		$this->flush_output();
		exit;
	}

	/**
	 * Push whatever is buffered to the client.
	 */
	public function flush_output() {
		if ( ob_get_level() > 0 ) {
			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		flush();
	}
}
