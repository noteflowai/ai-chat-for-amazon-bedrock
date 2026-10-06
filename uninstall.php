<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package AI_Chat_Bedrock
 */

/*
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 *
 * Uninstalling deletes plugin rows by option-name pattern. There is no API for that,
 * caching is pointless because the data is being removed, and the table name comes
 * from $wpdb rather than from input.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove this plugin's data from the current site.
 *
 * Vectors stored in an Amazon S3 Vectors index are in the AWS account, not in WordPress, and
 * are left there: delete the index in AWS, or use Delete the index before uninstalling.
 */
function ai_chat_bedrock_uninstall_site() {
	global $wpdb;

	foreach ( array( 'ai_chat_bedrock_settings', 'ai_chat_bedrock_role_limits', 'ai_chat_bedrock_enable_mcp', 'ai_chat_bedrock_mcp_public_access', 'ai_chat_bedrock_mcp_servers', 'ai_chat_bedrock_db_version', 'ai_chat_bedrock_usage', 'ai_chat_bedrock_mcp_tool_policy', 'ai_chat_bedrock_mcp_capability', 'ai_chat_bedrock_mcp_max_rounds', 'ai_chat_bedrock_mcp_log_enabled', 'ai_chat_bedrock_tool_log', 'ai_chat_bedrock_conversations', 'ai_chat_bedrock_log_conversations', 'ai_chat_bedrock_log_retention_days', 'ai_chat_bedrock_oauth_clients', 'ai_chat_bedrock_oauth_grants', 'ai_chat_bedrock_oauth_revoked', 'ai_chat_bedrock_oauth_enabled', 'ai_chat_bedrock_site_abilities', 'ai_chat_bedrock_profiles', 'ai_chat_bedrock_eval_set', 'ai_chat_bedrock_eval_runs', 'ai_chat_bedrock_abilities_tools', 'ai_chat_bedrock_ability_sources_off', 'ai_chat_bedrock_s3v_delete_queue', 'ai_chat_bedrock_youtube', 'aicfab_youtube_uploads', 'aicfab_wechat_contact', 'aicfab_wxgame_contact', 'aicfab_wxgame_stats', 'aicfab_wechat_drafts' ) as $ai_chat_bedrock_option ) {
		delete_option( $ai_chat_bedrock_option );
	}
	// The key entered on Settings > Connectors is stored by core but encrypted by this plugin,
	// so nothing else could read it once the plugin is gone.
	delete_option( 'connectors_ai_amazon_bedrock_api_key' );

	delete_transient( 'ai_chat_bedrock_cache' );
	delete_transient( 'aicfab_role_credentials' );
	delete_transient( 'aicfab_converse_quirks' );
	delete_transient( 'aicfab_speech_fallback' );
	delete_transient( 'aicfab_youtube_access' );
	delete_transient( 'aicfab_wxgame_access' );
	delete_transient( 'aicfab_wechat_access' );

	$ai_chat_bedrock_legacy_table = esc_sql( $wpdb->prefix . 'ai_chat_bedrock_history' );
	$wpdb->query( "DROP TABLE IF EXISTS `{$ai_chat_bedrock_legacy_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

	$ai_chat_bedrock_pattern = $wpdb->esc_like( '_transient_aicfab_rl_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ai_chat_bedrock_timeout_pattern = $wpdb->esc_like( '_transient_timeout_aicfab_rl_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_timeout_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

	foreach ( array( '_transient_aicfab_key_ok_', '_transient_timeout_aicfab_key_ok_' ) as $ai_chat_bedrock_key_prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $ai_chat_bedrock_key_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	$ai_chat_bedrock_model_pattern = $wpdb->esc_like( '_transient_aicfab_models_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_model_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ai_chat_bedrock_model_timeout_pattern = $wpdb->esc_like( '_transient_timeout_aicfab_models_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_model_timeout_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

	// Post meta this plugin wrote, removed by key rather than by option name. Every key the
	// plugin writes has to appear here; tests/security-regression.php checks that it does.
	foreach ( array( '_aicfab_embedding', '_aicfab_embedding_model', '_aicfab_embedding_hash', '_aicfab_index_state', '_aicfab_index_retry', '_aicfab_index_failures', '_aicfab_s3v_ref', '_aicfab_s3v_hash', '_aicfab_s3v_chunks', '_aicfab_scaffolded', '_aicfab_chat_page', '_aicfab_distribution', '_aicfab_youtube_upload', '_aicfab_wechat_images', '_aicfab_wechat_cover' ) as $ai_chat_bedrock_meta_key ) {
		delete_post_meta_by_key( $ai_chat_bedrock_meta_key );
	}

	// Contact requests left in the chat are private posts of their own type, and their meta
	// goes with them. Export them from the Contact requests page before uninstalling.
	do {
		$ai_chat_bedrock_leads = get_posts(
			array(
				'post_type'      => 'aicfab_lead',
				'post_status'    => 'any',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- deleted in batches.
				'fields'         => 'ids',
			)
		);
		foreach ( $ai_chat_bedrock_leads as $ai_chat_bedrock_lead ) {
			wp_delete_post( (int) $ai_chat_bedrock_lead, true );
		}
		$ai_chat_bedrock_more = count( $ai_chat_bedrock_leads ) >= 200;
	} while ( $ai_chat_bedrock_more );
	delete_post_meta_by_key( '_aicfab_status' );

	// Cached managed prompt text is stored in transients keyed by prompt and version.
	$ai_chat_bedrock_prompt_pattern = $wpdb->esc_like( '_transient_aicfab_prompt_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_prompt_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ai_chat_bedrock_prompt_timeout = $wpdb->esc_like( '_transient_timeout_aicfab_prompt_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_prompt_timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

	// Saved chat conversations are user options, so their keys carry this site's prefix.
	foreach ( array( 'aicfab_chat_history', 'aicfab_chat_history_oldest' ) as $ai_chat_bedrock_user_option ) {
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . $ai_chat_bedrock_user_option, '', true );
	}

	// Audio of posts read aloud is kept in the uploads folder.
	$ai_chat_bedrock_uploads = wp_upload_dir( null, false );
	if ( empty( $ai_chat_bedrock_uploads['error'] ) && ! empty( $ai_chat_bedrock_uploads['basedir'] ) ) {
		$ai_chat_bedrock_speech = untrailingslashit( $ai_chat_bedrock_uploads['basedir'] ) . '/ai-chat-bedrock-speech';
		foreach ( (array) glob( $ai_chat_bedrock_speech . '/*' ) as $ai_chat_bedrock_file ) {
			if ( is_string( $ai_chat_bedrock_file ) && is_file( $ai_chat_bedrock_file ) ) {
				wp_delete_file( $ai_chat_bedrock_file );
			}
		}
		if ( is_dir( $ai_chat_bedrock_speech ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( 'direct' === get_filesystem_method() && WP_Filesystem() ) {
				global $wp_filesystem;
				$wp_filesystem->rmdir( $ai_chat_bedrock_speech );
			}
		}
	}

	// Any scheduled index run or chat history pruning is removed with the plugin data.
	foreach ( array( 'ai_chat_bedrock_index_embeddings', 'ai_chat_bedrock_prune_chat_history', 'ai_chat_bedrock_prune_leads', 'ai_chat_bedrock_wechat_drafts', 'ai_chat_bedrock_youtube_upload', 'ai_chat_bedrock_youtube_status' ) as $ai_chat_bedrock_cron_hook ) {
		wp_clear_scheduled_hook( $ai_chat_bedrock_cron_hook );
	}
}

// Every site of a network has its own options and posts.
if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $ai_chat_bedrock_site ) {
		switch_to_blog( (int) $ai_chat_bedrock_site );
		ai_chat_bedrock_uninstall_site();
		restore_current_blog();
	}
} else {
	ai_chat_bedrock_uninstall_site();
}

// The one user meta key: an administrator dismissed the setup notice.
delete_metadata( 'user', 0, 'aicfab_dismissed_setup_notice', '', true );
delete_metadata( 'user', 0, 'aicfab_dismissed_review_prompt', '', true );
