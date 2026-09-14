<?php
/**
 * Streaming chat endpoint.
 *
 * Streaming uses an authenticated POST request and Server-Sent Events so
 * conversation content never appears in a URL, and each visitor message
 * triggers exactly one Bedrock invocation.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Stream {

	private $sse;

	const REST_NAMESPACE = 'ai-chat-bedrock/v1';
	const REST_ROUTE     = '/stream';

	/**
	 * Register the streaming route.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'stream' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'message' => array(
						'required' => true,
						'type'     => 'string',
					),
					'history' => array(
						'required' => false,
						'type'     => 'string',
					),
					'nonce'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'profile' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Authorize a streaming request.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		$nonce = (string) $request->get_param( 'nonce' );
		if ( ! wp_verify_nonce( $nonce, 'ai_chat_bedrock_nonce' ) ) {
			return new WP_Error( 'aicfab_bad_nonce', __( 'Security check failed.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 403 ) );
		}
		$profile = AI_Chat_Bedrock_Profiles::sanitize_key( (string) $request->get_param( 'profile' ) );
		$options = AI_Chat_Bedrock_Profiles::resolve( $profile );

		if ( ! AI_Chat_Bedrock_Security::can_use_chat( $options ) ) {
			return new WP_Error( 'aicfab_forbidden', __( 'Please sign in to use the chat.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 401 ) );
		}
		if ( ! AI_Chat_Bedrock_Chat_Request::streaming_enabled() ) {
			return new WP_Error( 'aicfab_streaming_disabled', __( 'Streaming is disabled on this site.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 409 ) );
		}

		$limit = isset( $options['rate_limit_per_minute'] ) ? absint( $options['rate_limit_per_minute'] ) : 5;
		$limit = class_exists( 'AI_Chat_Bedrock_Rate_Limits' )
			? AI_Chat_Bedrock_Rate_Limits::for_current_user( $limit )
			: $limit;
		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'chat-' . ( '' !== $profile ? $profile : 'default' ), max( 1, min( AI_Chat_Bedrock_Rate_Limits::MAX_PER_ROLE, $limit ) ) ) ) {
			return new WP_Error( 'aicfab_rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Stream a Bedrock response to the browser.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return void
	 */
	public function stream( $request ) {
		$profile = AI_Chat_Bedrock_Profiles::sanitize_key( (string) $request->get_param( 'profile' ) );
		$options = AI_Chat_Bedrock_Profiles::resolve( $profile );
		$built   = AI_Chat_Bedrock_Chat_Request::build( $request->get_param( 'message' ), (string) $request->get_param( 'history' ), $options );

		$this->send_headers();

		if ( is_wp_error( $built ) ) {
			$this->send_event( 'error', array( 'message' => $built->get_error_message() ) );
			$this->finish();
		}

		$aws   = new AI_Chat_Bedrock_AWS( AI_Chat_Bedrock_Profiles::overrides_for_client( $options ) );
		$emit  = function ( $delta ) {
			$this->send_event( 'delta', array( 'text' => (string) $delta ) );
		};
		$round = function ( $number, $tools, $names = array(), $labels = array() ) {
			$this->send_event(
				'tools',
				array(
					'round'  => (int) $number,
					'count'  => (int) $tools,
					'tools'  => array_values( array_map( 'sanitize_text_field', (array) $names ) ),
					'labels' => array_values( array_map( 'sanitize_text_field', (array) $labels ) ),
				)
			);
		};

		$response = AI_Chat_Bedrock_Tool_Runner::run( $aws, $built['messages'], $built['message'], $emit, $round );

		if ( empty( $response['success'] ) ) {
			$code = isset( $response['data']['code'] ) ? $response['data']['code'] : '';
			if ( in_array( $code, array( 'aicfab_streaming_unavailable', 'aicfab_stream_interrupted' ), true ) ) {
				$this->send_event( 'fallback', array( 'message' => __( 'Streaming is unavailable; retrying without streaming.', 'ai-chat-for-amazon-bedrock' ) ) );
			} else {
				$this->send_event( 'error', array( 'message' => isset( $response['data']['message'] ) ? $response['data']['message'] : __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' ) ) );
			}
			$this->finish();
		}

		$answer = isset( $response['data']['message'] ) ? (string) $response['data']['message'] : '';
		$entry  = AI_Chat_Bedrock_Conversations::record(
			$built['message'],
			$answer,
			array(
				'usage'  => isset( $response['usage'] ) ? $response['usage'] : array(),
				'source' => 'stream',
				'model'  => isset( $options['model_id'] ) ? $options['model_id'] : '',
			)
		);

		$done = array( 'message' => $answer );
		if ( ! empty( $response['usage'] ) && is_array( $response['usage'] ) ) {
			$done['usage'] = $response['usage'];
		}
		if ( is_string( $entry ) && '' !== $entry ) {
			$done['entry'] = $entry;
		}
		if ( ! empty( $response['fallback_model'] ) ) {
			$done['fallback_model'] = sanitize_text_field( (string) $response['fallback_model'] );
		}
		if ( ! empty( $response['steps'] ) && is_array( $response['steps'] ) ) {
			$done['steps'] = $response['steps'];
			if ( ! empty( $response['steps_truncated'] ) ) {
				$done['steps_truncated'] = true;
			}
		}
		$this->send_event( 'done', $done );
		$this->finish();
	}

	private function sse() {
		if ( ! $this->sse instanceof AI_Chat_Bedrock_SSE ) {
			$this->sse = new AI_Chat_Bedrock_SSE();
		}
		return $this->sse;
	}

	private function send_headers() {
		$this->sse()->open();
	}

	private function send_event( $type, $data ) {
		$this->sse()->send( $type, $data );
	}

	private function finish() {
		$this->sse()->close();
	}
}
