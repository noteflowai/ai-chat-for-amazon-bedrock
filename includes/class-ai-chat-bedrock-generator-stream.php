<?php
/**
 * Streaming content generation.
 *
 * The generator used to be a synchronous form post, which meant staring at a blank
 * admin screen for the length of a long completion. This route streams the draft as
 * it is written and only creates the post once the model has finished, so nothing is
 * saved for an interrupted generation.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Generator_Stream {

	const REST_ROUTE = '/generate';

	/**
	 * Register the streaming generation route.
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
					'topic'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'notes'    => array(
						'required' => false,
						'type'     => 'string',
					),
					'tone'     => array(
						'required' => false,
						'type'     => 'string',
					),
					'length'   => array(
						'required' => false,
						'type'     => 'string',
					),
					'language' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Only users who may create posts can generate drafts.
	 *
	 * The generator's own rate limit is applied later, inside prepare(), so a
	 * rejected request is reported through the stream like any other error.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'aicfab_forbidden', __( 'You are not allowed to create drafts.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Stream a generated draft.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return void|WP_Error
	 */
	public function handle_request( $request ) {
		$generator = new AI_Chat_Bedrock_Content_Generator();
		$prepared  = $generator->prepare(
			array(
				'topic'    => (string) $request->get_param( 'topic' ),
				'notes'    => (string) $request->get_param( 'notes' ),
				'tone'     => (string) $request->get_param( 'tone' ),
				'length'   => (string) $request->get_param( 'length' ),
				'language' => (string) $request->get_param( 'language' ),
			)
		);

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$sse = new AI_Chat_Bedrock_SSE();
		$sse->open();

		$aws = new AI_Chat_Bedrock_AWS( array( 'max_tokens' => $prepared['max_tokens'] ) );

		if ( ! AI_Chat_Bedrock_AWS::streaming_supported() ) {
			// Without cURL the answer still arrives, just in one piece.
			$response = $aws->handle_chat_message( array( 'messages' => $prepared['messages'] ) );
		} else {
			$response = $aws->stream_chat_message(
				array( 'messages' => $prepared['messages'] ),
				function ( $delta ) use ( $sse ) {
					$sse->send( 'delta', array( 'text' => (string) $delta ) );
				}
			);
		}

		if ( empty( $response['success'] ) ) {
			$sse->send(
				'error',
				array(
					'message' => isset( $response['data']['message'] ) ? (string) $response['data']['message'] : __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' ),
					'code'    => isset( $response['data']['code'] ) ? sanitize_key( (string) $response['data']['code'] ) : 'aicfab_error',
				)
			);
			$sse->close();
		}

		$raw   = isset( $response['data']['message'] ) ? (string) $response['data']['message'] : '';
		$draft = $generator->create_draft( $raw, $prepared['topic'], isset( $response['usage'] ) ? $response['usage'] : array() );

		if ( is_wp_error( $draft ) ) {
			$sse->send(
				'error',
				array(
					'message' => $draft->get_error_message(),
					'code'    => sanitize_key( $draft->get_error_code() ),
				)
			);
			$sse->close();
		}

		$done = array(
			'id'       => (int) $draft['id'],
			'title'    => (string) $draft['title'],
			'words'    => (int) $draft['words'],
			'edit_url' => (string) $draft['edit_url'],
			'usage'    => isset( $draft['usage'] ) ? $draft['usage'] : array(),
		);
		if ( ! empty( $response['fallback_model'] ) ) {
			$done['fallback_model'] = sanitize_text_field( (string) $response['fallback_model'] );
		}

		$sse->send( 'done', $done );
		$sse->close();
	}
}
