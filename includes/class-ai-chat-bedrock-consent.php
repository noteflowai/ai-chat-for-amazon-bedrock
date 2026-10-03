<?php
/**
 * The WP Consent API, which consent plugins such as Complianz, CookieYes, Cookiebot and Real
 * Cookie Banner use to learn what other plugins store and to tell them what a visitor allowed.
 *
 * The chat stores two things in the browser, both in session storage and both needed for
 * what the visitor asked for: the conversation, while they move between pages, and whether
 * the floating chat is open. They are described to the consent plugin as functional, so its
 * cookie policy lists them without asking for consent first. The analytics events, which are
 * statistics, wait for consent in the browser; see AI_Chat_Bedrock_Analytics.
 *
 * The plugin declares that it follows the API in the main plugin file, where the hook name
 * can be built from the plugin's own file.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Consent {

	/**
	 * Describe what the chat keeps in the browser, where the WP Consent API is active.
	 */
	public static function describe_storage() {
		if ( ! function_exists( 'wp_add_cookie_info' ) ) {
			return;
		}
		foreach ( self::storage() as $name => $item ) {
			wp_add_cookie_info( $name, $item['service'], 'functional', $item['expires'], $item['purpose'], $item['data'], false, false, 'LOCALSTORAGE' );
		}
	}

	/**
	 * What the chat keeps in the browser, by name.
	 *
	 * @return array Map of name to service, expires, purpose and data.
	 */
	public static function storage() {
		$service = __( 'AI Chatbot & Agents for Amazon Bedrock', 'ai-chat-for-amazon-bedrock' );
		$session = __( 'Until the browser tab is closed', 'ai-chat-for-amazon-bedrock' );
		$items   = array(
			'aicfabPopupOpen' => array(
				'service' => $service,
				'expires' => $session,
				'purpose' => __( 'Keeps the floating chat open or closed as the visitor left it, while they move between pages.', 'ai-chat-for-amazon-bedrock' ),
				'data'    => '',
			),
		);
		if ( class_exists( 'AI_Chat_Bedrock_Chat_History' ) && '' !== AI_Chat_Bedrock_Chat_History::mode() ) {
			$items['aicfabChat:*'] = array(
				'service' => $service,
				'expires' => $session,
				'purpose' => __( 'Keeps the chat conversation while the visitor moves between pages.', 'ai-chat-for-amazon-bedrock' ),
				'data'    => __( 'Chat messages', 'ai-chat-for-amazon-bedrock' ),
			);
		}
		return $items;
	}
}
