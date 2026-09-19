<?php
/**
 * Plugin deactivation.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Deactivator {
	/**
	 * Stop scheduled work, and leave every setting alone.
	 *
	 * Settings survive deactivation on purpose: switching a plugin off to test something should
	 * not discard its configuration, and permanent cleanup belongs to uninstall.php. A scheduled
	 * event is different. The embeddings index schedules an hourly event, and nothing removed it
	 * here, so a deactivated plugin left a recurring event in the site's cron array that fired
	 * every hour with no code to answer it.
	 */
	public static function deactivate() {
		if ( class_exists( 'AI_Chat_Bedrock_Embeddings' ) ) {
			wp_clear_scheduled_hook( AI_Chat_Bedrock_Embeddings::CRON_HOOK );
			return;
		}
		// Deactivation can run without the rest of the plugin loaded, so the name is spelled out.
		wp_clear_scheduled_hook( 'ai_chat_bedrock_index_embeddings' );
	}
}
