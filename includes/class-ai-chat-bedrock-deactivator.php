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
		// Deactivation can run without the rest of the plugin loaded, so the names are spelled out.
		// The YouTube events carry the post as an argument, which wp_clear_scheduled_hook() needs
		// to match, so they are removed whatever their arguments.
		wp_clear_scheduled_hook( 'ai_chat_bedrock_index_embeddings' );
		wp_clear_scheduled_hook( 'ai_chat_bedrock_prune_chat_history' );
		wp_clear_scheduled_hook( 'ai_chat_bedrock_prune_leads' );
		wp_clear_scheduled_hook( 'ai_chat_bedrock_wechat_drafts' );
		wp_unschedule_hook( 'ai_chat_bedrock_youtube_upload' );
		wp_unschedule_hook( 'ai_chat_bedrock_youtube_status' );
	}
}
