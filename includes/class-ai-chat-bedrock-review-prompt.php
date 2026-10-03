<?php
/**
 * One request for a review, made once the chat has proved itself.
 *
 * Shown only on the plugin's own screens, only to administrators, and only after the site has
 * answered real questions for a week. Dismissing it, or following the link, ends it for that
 * person for good. Nothing is offered in return for a review, and nothing is sent anywhere: the
 * decision is made from the usage counters the dashboard already keeps.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Review_Prompt {

	const META        = 'aicfab_dismissed_review_prompt';
	const NONCE       = 'ai_chat_bedrock_dismiss_review_prompt';
	const MIN_ANSWERS = 20;
	const MIN_DAYS    = 7;
	const SUPPORT_URL = 'https://wordpress.org/support/plugin/ai-chat-for-amazon-bedrock/';
	const REVIEW_URL  = 'https://wordpress.org/support/plugin/ai-chat-for-amazon-bedrock/reviews/#new-post';

	/**
	 * Whether the usage so far is a success worth asking about.
	 *
	 * Enough answers that the chat is in real use, and the first of them long enough ago that
	 * the person has seen it work for more than an afternoon.
	 *
	 * @param array  $days  Usage counters by UTC day (Y-m-d), as AI_Chat_Bedrock_Usage::days() returns.
	 * @param string $today Today as Y-m-d, UTC.
	 * @return bool
	 */
	public static function earned( $days, $today ) {
		$answers = 0;
		$first   = '';
		foreach ( (array) $days as $day => $entry ) {
			$requests = is_array( $entry ) && isset( $entry['requests'] ) ? (int) $entry['requests'] : 0;
			if ( $requests <= 0 || ! is_string( $day ) ) {
				continue;
			}
			$answers += $requests;
			if ( '' === $first || $day < $first ) {
				$first = $day;
			}
		}
		if ( $answers < self::MIN_ANSWERS || '' === $first ) {
			return false;
		}
		$age = ( strtotime( $today . ' 00:00:00 UTC' ) - strtotime( $first . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS;
		return $age >= self::MIN_DAYS;
	}

	/**
	 * Whether to show the prompt to the current user on this screen.
	 *
	 * @param string $plugin_name Plugin slug, which every plugin screen ID contains.
	 * @return bool
	 */
	public static function should_show( $plugin_name ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, (string) $plugin_name ) ) {
			return false;
		}
		if ( get_user_meta( get_current_user_id(), self::META, true ) ) {
			return false;
		}
		$earned = class_exists( 'AI_Chat_Bedrock_Usage' ) && self::earned( AI_Chat_Bedrock_Usage::days(), gmdate( 'Y-m-d' ) );

		/**
		 * Filters whether the review request is shown.
		 *
		 * Return false to never show it, for example on a site managed for a client.
		 *
		 * @param bool $earned Whether the site has answered enough questions for long enough.
		 */
		return (bool) apply_filters( 'ai_chat_bedrock_review_prompt', $earned );
	}

	/**
	 * Print the prompt where it applies.
	 *
	 * @param string $plugin_name Plugin slug.
	 */
	public static function render( $plugin_name ) {
		if ( ! self::should_show( $plugin_name ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible aicfab-review-prompt"><p>%1$s</p><p><a class="button button-primary" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a> <a href="%4$s" target="_blank" rel="noopener noreferrer">%5$s</a></p></div>',
			esc_html__( 'Your chat has been answering visitors for over a week. If it has helped, a review on WordPress.org helps other site owners find it. If something is not right, the support forum is the place to say so.', 'ai-chat-for-amazon-bedrock' ),
			esc_url( self::REVIEW_URL ),
			esc_html__( 'Write a review', 'ai-chat-for-amazon-bedrock' ),
			esc_url( self::SUPPORT_URL ),
			esc_html__( 'Ask for help', 'ai-chat-for-amazon-bedrock' )
		);

		// Following either link, or closing the notice, ends it for this person.
		wp_add_inline_script(
			'common',
			sprintf(
				'jQuery( document ).on( "click", ".aicfab-review-prompt .notice-dismiss, .aicfab-review-prompt a", function () { var n = jQuery( this ).closest( ".aicfab-review-prompt" ); if ( n.data( "sent" ) ) { return; } n.data( "sent", 1 ); jQuery.post( ajaxurl, { action: "ai_chat_bedrock_dismiss_review_prompt", _ajax_nonce: %s } ); if ( ! jQuery( this ).hasClass( "notice-dismiss" ) ) { n.fadeOut(); } } );',
				wp_json_encode( wp_create_nonce( self::NONCE ) )
			)
		);
	}

	/**
	 * Stop showing the prompt to the current user.
	 */
	public static function ajax_dismiss() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( self::NONCE, false, false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ai-chat-for-amazon-bedrock' ) ), 403 );
			return;
		}
		update_user_meta( get_current_user_id(), self::META, 1 );
		wp_send_json_success();
	}
}
