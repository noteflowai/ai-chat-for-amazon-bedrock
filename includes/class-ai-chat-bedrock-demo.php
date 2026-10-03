<?php
/**
 * Demo mode: a chat that works before any AWS account is connected.
 *
 * The plugin directory's Live Preview opens the plugin in WordPress Playground, which has no
 * AWS credentials and could not reach Amazon Bedrock if it had them. Without this the preview
 * showed a chat that is not rendered at all, which says nothing about what the plugin does.
 *
 * Demo mode is on only when the site defines AI_CHAT_BEDROCK_DEMO, as the preview blueprint
 * does, and only while no credentials are found: connecting AWS turns it off by itself. A
 * reply quotes the passage of the site's own pages that best matches the question, found by
 * the same search that grounds real answers, and says plainly that no AI model wrote it.
 * Nothing pretends to be connected: Diagnostics, the setup steps and the WordPress AI client
 * still report that Amazon Bedrock is not set up.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Demo {

	// The quoted passage is cut at a word near this length, so a reply stays readable.
	const MAX_QUOTE_CHARS = 400;

	/**
	 * Whether the site has asked for demo mode.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$enabled = defined( 'AI_CHAT_BEDROCK_DEMO' ) && AI_CHAT_BEDROCK_DEMO;

		/**
		 * Filter whether demo mode is on. It still only answers while no AWS credentials are found.
		 *
		 * @param bool $enabled Whether AI_CHAT_BEDROCK_DEMO is defined and true.
		 */
		return (bool) apply_filters( 'ai_chat_bedrock_demo_mode', $enabled );
	}

	/**
	 * Whether the chat answers with demo replies, which is when it is asked to and has no
	 * credentials to do anything else.
	 *
	 * @param AI_Chat_Bedrock_AWS|null $aws Client the chat would use; a new one when omitted.
	 * @return bool
	 */
	public static function active( $aws = null ) {
		if ( ! self::enabled() ) {
			return false;
		}
		$aws = $aws instanceof AI_Chat_Bedrock_AWS ? $aws : new AI_Chat_Bedrock_AWS();
		return ! $aws->has_credentials();
	}

	/**
	 * Answer a question from the site's own pages without calling a model.
	 *
	 * @param string        $message  Visitor message.
	 * @param callable|null $on_delta Receives the text when the answer is streamed.
	 * @return array Response in the shape the AWS client returns.
	 */
	public static function reply( $message, $on_delta = null ) {
		$text = self::text( self::best_passage( (string) $message ) );

		if ( is_callable( $on_delta ) ) {
			// One delta per paragraph, so the streamed path is exercised as it would be.
			foreach ( preg_split( '/(?<=\n\n)/', $text ) as $part ) {
				if ( '' !== $part && false === call_user_func( $on_delta, $part ) ) {
					break;
				}
			}
		}

		// No usage: no tokens were spent, and the usage screen should not count a demo reply.
		return array(
			'success' => true,
			'data'    => array(
				'message' => $text,
			),
			'demo'    => true,
		);
	}

	/**
	 * The passage of a published page that matches a question best.
	 *
	 * @param string $message Visitor message.
	 * @return array|null Passage with title and excerpt, or null when nothing matches.
	 */
	private static function best_passage( $message ) {
		$message = trim( $message );
		if ( '' === $message || ! class_exists( 'AI_Chat_Bedrock_Retrieval' ) ) {
			return null;
		}

		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		// Semantic search needs Bedrock for the question's embedding, which is what is missing.
		$options['embedding_model_id'] = '';
		$options['context_results']    = 1;

		foreach ( AI_Chat_Bedrock_Retrieval::site_passages( $message, $options ) as $passage ) {
			if ( is_array( $passage ) && ! empty( $passage['excerpt'] ) ) {
				return $passage;
			}
		}
		return null;
	}

	/**
	 * Write the demo reply.
	 *
	 * @param array|null $passage Best passage, or null.
	 * @return string
	 */
	private static function text( $passage ) {
		$label = __( 'Demo mode, no AI model was called.', 'ai-chat-for-amazon-bedrock' );

		if ( null === $passage ) {
			return '**' . $label . '** ' . __( 'No page on this site matches that question. Once Amazon Bedrock is connected, a model answers from the site\'s pages and says when they do not cover a question.', 'ai-chat-for-amazon-bedrock' );
		}

		$title = isset( $passage['title'] ) ? trim( html_entity_decode( wp_strip_all_tags( (string) $passage['title'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
		$quote = trim( preg_replace( '/\s+/', ' ', (string) $passage['excerpt'] ) );
		// A passage from the top of a page starts with its title, which the reply already names.
		if ( '' !== $title && 0 === strpos( $quote, $title ) && '' !== trim( substr( $quote, strlen( $title ) ) ) ) {
			$quote = ltrim( substr( $quote, strlen( $title ) ), ' :' );
		}
		$quote = self::shorten( $quote );

		return '**' . $label . '** ' . (
			'' !== $title
				/* translators: %s: title of the page the passage comes from. */
				? sprintf( __( 'This passage from "%s" matches the question best:', 'ai-chat-for-amazon-bedrock' ), $title )
				: __( 'This passage from the site matches the question best:', 'ai-chat-for-amazon-bedrock' )
		) . "\n\n" . $quote . "\n\n" . __( 'Once Amazon Bedrock is connected, a model writes the answer from passages like this one and links to them.', 'ai-chat-for-amazon-bedrock' );
	}

	/**
	 * Cut a passage at a word boundary.
	 *
	 * @param string $text Passage.
	 * @return string
	 */
	private static function shorten( $text ) {
		if ( AI_Chat_Bedrock_Security::string_length( $text ) <= self::MAX_QUOTE_CHARS ) {
			return $text;
		}
		$cut   = AI_Chat_Bedrock_Security::string_substr( $text, 0, self::MAX_QUOTE_CHARS );
		$space = strrpos( $cut, ' ' );
		// Text without spaces, such as Chinese or Japanese, is cut where it reached the limit.
		if ( false !== $space && $space > strlen( $cut ) / 2 ) {
			$cut = substr( $cut, 0, $space );
		}
		return rtrim( $cut, " \t\n\r\0\x0B,.;:" ) . '…';
	}
}
