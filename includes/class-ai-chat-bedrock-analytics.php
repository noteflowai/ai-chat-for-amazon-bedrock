<?php
/**
 * Chat events for the analytics a site already runs.
 *
 * A site owner wants to know whether the chat is worth having: how often it is opened, how
 * many questions it answers from the site's pages, and whether those answers lead anywhere.
 * The analytics tag is already on the page, put there by Site Kit, MonsterInsights, GTM4WP,
 * Matomo or Plausible, so the chat reports to it instead of keeping figures of its own.
 *
 * The events say what happened, never what was written: no message text, no contact details,
 * no answer. They are sent from the browser to whichever tag the site loads, which decides
 * what to do with them under its own consent settings. With a consent plugin on the WP
 * Consent API, the chat also waits for the visitor to allow statistics.
 *
 * Off until an administrator turns it on. Developers can listen for the
 * ai-chat-bedrock:event DOM event on document, which carries the same data either way.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Analytics {

	// What the chat reports, with the parameters each event carries.
	const EVENTS = array(
		'ai_chat_open'          => array( 'chat_profile' ),
		'ai_chat_question'      => array( 'chat_profile', 'question_source' ),
		'ai_chat_answer'        => array( 'chat_profile', 'sources', 'products' ),
		'ai_chat_source_click'  => array( 'chat_profile', 'link_url' ),
		'ai_chat_product_click' => array( 'chat_profile', 'link_url', 'product_action' ),
		'ai_chat_feedback'      => array( 'chat_profile', 'rating' ),
		'ai_chat_contact'       => array( 'chat_profile' ),
	);

	/**
	 * Whether the chat reports events to the site's analytics.
	 *
	 * @param array|null $options Settings; the saved ones when omitted.
	 * @return bool
	 */
	public static function enabled( $options = null ) {
		if ( ! is_array( $options ) ) {
			$options = get_option( 'ai_chat_bedrock_settings', array() );
			$options = is_array( $options ) ? $options : array();
		}
		return ! empty( $options['analytics_events'] );
	}

	/**
	 * Analytics plugins on this site whose tag receives the events, by name.
	 *
	 * @return string[]
	 */
	public static function tools() {
		$tools = array(
			'Site Kit by Google'  => defined( 'GOOGLESITEKIT_VERSION' ),
			'MonsterInsights'     => defined( 'MONSTERINSIGHTS_VERSION' ),
			'GTM4WP'              => defined( 'GTM4WP_VERSION' ),
			'Matomo'              => defined( 'MATOMO_ANALYTICS_FILE' ),
			'Plausible Analytics' => defined( 'PLAUSIBLE_ANALYTICS_PLUGIN_FILE' ),
		);
		return array_keys( array_filter( $tools ) );
	}
}
