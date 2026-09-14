<?php
/**
 * Answer feedback endpoint.
 *
 * Visitors can mark an answer as helpful or not. Only the rating is stored, and
 * only while the conversation log is enabled, because there is nothing to attach
 * a rating to otherwise.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Feedback {

	const REST_ROUTE = '/feedback';
	const RATE_LIMIT = 30;

	/**
	 * Register the feedback route.
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
					'entry'  => array(
						'required' => true,
						'type'     => 'string',
					),
					'rating' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Feedback follows the same audience as the chat itself.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( ! AI_Chat_Bedrock_Conversations::enabled() ) {
			return new WP_Error( 'aicfab_feedback_disabled', __( 'Feedback is not collected on this site.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}

		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		if ( ! is_user_logged_in() && empty( $options['allow_public_chat'] ) ) {
			return new WP_Error( 'aicfab_forbidden', __( 'Please sign in to send feedback.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 401 ) );
		}

		if ( ! AI_Chat_Bedrock_Security::check_rate_limit( 'feedback', self::RATE_LIMIT ) ) {
			return new WP_Error( 'aicfab_rate_limited', __( 'Too many requests. Please wait a moment.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * Store one rating.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_request( $request ) {
		$entry  = (string) $request->get_param( 'entry' );
		$rating = strtolower( trim( (string) $request->get_param( 'rating' ) ) );

		if ( ! in_array( $rating, array( 'up', 'down' ), true ) ) {
			return new WP_Error( 'aicfab_invalid_rating', __( 'The rating must be up or down.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}

		$stored = AI_Chat_Bedrock_Conversations::rate( $entry, 'up' === $rating ? 1 : -1 );
		if ( ! $stored ) {
			return new WP_Error( 'aicfab_unknown_entry', __( 'That answer is no longer available for feedback.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'recorded' => true ) );
	}
}
